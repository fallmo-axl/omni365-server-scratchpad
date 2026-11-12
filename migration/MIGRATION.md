# Nextcloud migration: filesystem (old cluster) → object store (new cluster)

Both sides run Nextcloud 33. Old: `nextcloud-kubernetes/filesystem/*` (CephFS data dir, PG 17 StatefulSet,
single Redis). New: `omni365-server` (Ceph RGW bucket, CNPG PG 18, Redis cluster).

Conventions: `oc` contexts `old` and `new`; old namespace `accel-workspace`; new namespace `omni365`.
Run everything from the `migration/` folder, in one shell session (later steps reuse variables set in earlier ones).
zsh: run `setopt SH_WORD_SPLIT` first so `$OLD` / `$NEW` expand into separate words.
Don't paste trailing `# comments` with a command — zsh passes them to `oc` as arguments.

```bash
setopt SH_WORD_SPLIT
OLD='oc --context <old-context> -n accel-workspace'
NEW='oc --context <new-context> -n omni365'
NEWSTORAGE='oc --context <new-context> -n openshift-storage'
# both must answer:
$OLD get pods && $NEW get pods
NCPOD=$($OLD get pod -l app=nextcloud -o jsonpath='{.items[0].metadata.name}')
```

---

## Phase 0 — prepare (no downtime)

### 0.0 Match the Nextcloud version

The old image tag (`s390x/nextcloud:production`) floats. The new image must be built on the **same major**
as what the old instance actually runs — the entrypoint refuses downgrades and skipping a major.

```bash
$OLD exec $NCPOD -- su www-data -s /bin/sh -c 'php occ status'
```

Prod reports `33.0.10`. The existing `v1` image is built from `33-apache` (Dockerfile default) and is 33.0.10.1 —
the same major, so it is the right image; nothing to build. Do **not** use `v2` from the test run: that one is 35.

The same tag must be in `../server.yaml`, `../cron.yaml` and `helper-pod.yaml` — every pod mounts the same PVC and
must run identical code. All three currently say `v1`.

### 0.0b Background jobs (cron) in the new deployment

`server.yaml` has no cron. The old instance runs background jobs every 5 minutes (`lastcron` in the diagnostics);
without them the Mail app stops syncing, trash/versions never expire, notifications and activity mails stop.
`../cron.yaml` is a dedicated single-replica Deployment (same image, PVC and object-store env as the web pods) that
runs `cron.php` every 5 minutes. It is applied together with the rest in 0.1. Keep its image tag in sync with `server.yaml`.

### 0.0c DNS

Lower the TTL of `omniserver.heritage.africa` to 300s at least a day before the cutover so the switch propagates quickly.

### 0.1 Deploy the new stack, init the PVC, stop the app

```bash
$NEW apply -f ../postgres.yaml -f ../redis.yaml -f ../server.yaml -f ../cron.yaml
# wait for the 6 redis pods, then create the cluster:
$NEW exec omni365-server-redis-0 -- redis-cli --cluster create \
  $($NEW get pods -l app=omni365-server-redis -o jsonpath='{range .items[*]}{.status.podIP}:6379 {end}') \
  --cluster-replicas 1 --cluster-yes
# wait for omni365-server pod Running (it copies the Nextcloud code into the PVC; do NOT open the web installer), then:
$NEW scale deploy/omni365-server --replicas=0
```

The Route already carries `omniserver.heritage.africa`; public DNS still points at the old cluster, so nobody reaches
the new instance (or its installer page) until you switch DNS. To reach it yourself before that, put
`<new router IP>  omniserver.heritage.africa` in your `/etc/hosts`.

### 0.2 Make RGW reachable from the old cluster

```bash
$NEWSTORAGE get route
```

If no route points at `rook-ceph-rgw-ocs-storagecluster-cephobjectstore`, create one:

```bash
$NEWSTORAGE create route edge rgw-migration \
  --service=rook-ceph-rgw-ocs-storagecluster-cephobjectstore --port=http
$NEWSTORAGE annotate route rgw-migration haproxy.router.openshift.io/timeout=10m
RGW_HOST=$($NEWSTORAGE get route rgw-migration -o jsonpath='{.spec.host}')
```

Capacity: Ceph keeps 3 replicas, so the new cluster needs ~3× the old data size free.

```bash
$NEWSTORAGE get cephcluster -o jsonpath='{.items[0].status.ceph.capacity}'
```

Prod data is 124 GB → ~375 GB raw must be available.

Collect bucket credentials:

```bash
S3_BUCKET=$($NEW get cm omni365-server-s3 -o jsonpath='{.data.BUCKET_NAME}')
S3_KEY=$($NEW get secret omni365-server-s3 -o jsonpath='{.data.AWS_ACCESS_KEY_ID}' | base64 -d)
S3_SECRET=$($NEW get secret omni365-server-s3 -o jsonpath='{.data.AWS_SECRET_ACCESS_KEY}' | base64 -d)
```

Quick check from the old pod (ingress cert must be trusted there; otherwise use `S3_VERIFY=false` below):

```bash
# expect 403 (= reachable, unauthenticated):
$OLD exec $NCPOD -- curl -sS -o /dev/null -w '%{http_code}\n' https://$RGW_HOST/
```

### 0.3 Safety backup + copy of config and custom apps

```bash
mkdir -p old
$OLD exec postgres-0 -- pg_dump -U nextcloud -Fc nexcloud > old/backup-$(date +%F).dump
$OLD rsync $NCPOD:/var/www/html/config/ old/config/
$OLD rsync $NCPOD:/var/www/html/custom_apps/ old/custom_apps/
```

This dump is a general safety net. The rollback dump is taken at cutover (2.1), so it includes everything users do
during Phase 1.

### 0.4 Raise the old pod's limits and dry-run the script

The old pod runs at 200m / 256Mi. Raise it for the migration (restarts the pod once, ~30s interruption).
Size for the parallel uploads in Phase 1: roughly 1 CPU per shard, and 512Mi + ~150Mi per million files.

```bash
$OLD set resources deploy/nextcloud --limits=cpu=4,memory=2Gi
$OLD rollout status deploy/nextcloud
NCPOD=$($OLD get pod -l app=nextcloud -o jsonpath='{.items[0].metadata.name}')
```

The script, its state and its logs live under the data directory (`/var/www/html/data/.migrate-s3/`): on the PVC, so
they survive pod restarts, and Apache denies web access to the data directory.

```bash
MIG=/var/www/html/data/.migrate-s3
$OLD exec $NCPOD -- mkdir -p $MIG
$OLD cp migrate-to-s3.php $NCPOD:$MIG/migrate-to-s3.php
$OLD exec $NCPOD -- php -d memory_limit=1G $MIG/migrate-to-s3.php dry
```

Expected: `39138 files in filecache, ... 0 missing on disk` (prod numbers). If files are missing, run
`php occ files:scan --all` on the old instance and re-run `dry` until it is 0 (or accept them and use `FORCE=1` later).

Prod is 124 GB / 39k files: no need for the trash/versions cleanup, and `memory_limit=1G` is ample.

---

## Phase 1 — pre-sync files while the old instance is live

This is the long step at production size and it runs with the old instance fully in service. Run several shards in
parallel (here 4 — match the CPU limit set in 0.4); each shard takes `fileid % 4 == i`:

```bash
S3ENV="S3_ENDPOINT=https://$RGW_HOST S3_BUCKET=$S3_BUCKET S3_KEY=$S3_KEY S3_SECRET=$S3_SECRET"
for i in 0 1 2 3; do
  $OLD exec $NCPOD -- sh -c "$S3ENV SHARD=$i/4 nohup php -d memory_limit=1G $MIG/migrate-to-s3.php sync > $MIG/sync-$i.log 2>&1 &"
done
$OLD exec $NCPOD -- sh -c "tail -n 2 $MIG/sync-*.log"
```

Repeat the `tail` to watch progress; each progress line shows MB/s. Each log ends with a
`sync: ... uploaded ... already present ... missing` summary when its shard is done.
If the pod restarts mid-way (OOM, node drain) just rerun the loop — it skips what is already in the bucket.
Add `S3_VERIFY=false` to `S3ENV` if the RGW route uses a cert the pod doesn't trust.

Estimate: 124 GB ÷ observed aggregate MB/s — under an hour at 50 MB/s. If throughput is poor, the bottleneck is usually
the OpenShift router doing TLS; a passthrough route to the RGW `https` port, or a NodePort/LoadBalancer Service, bypasses it.

**Right before the maintenance window**, run one more pass with `VERIFY=1`: it HEADs every object and compares the
Nextcloud etag, catching content changes that kept the same size and mtime (version restores). ~39k requests,
a few minutes across 4 shards:

```bash
for i in 0 1 2 3; do
  $OLD exec $NCPOD -- sh -c "$S3ENV SHARD=$i/4 VERIFY=1 nohup php -d memory_limit=1G $MIG/migrate-to-s3.php sync > $MIG/verify-$i.log 2>&1 &"
done
$OLD exec $NCPOD -- sh -c "tail -n 1 $MIG/verify-*.log"
```

---

## Phase 2 — cutover (downtime starts)

### 2.1 Freeze the old instance and finalize the file migration

```bash
$OLD exec $NCPOD -- su www-data -s /bin/sh -c 'php occ maintenance:mode --on'
$OLD exec postgres-0 -- pg_dump -U nextcloud -Fc nexcloud > old/pre-finalize.dump
$OLD exec $NCPOD -- sh -c "$S3ENV PRUNE_MISSING=1 php -d memory_limit=1G $MIG/migrate-to-s3.php finalize"
```

`pre-finalize.dump` is the rollback point: the DB exactly as users left it.

`finalize` runs unsharded: it lists the bucket, stats every filecache entry on disk, uploads what changed since the
last `sync`, then rewrites `oc_storages` in the **old** DB:
`home::<uid>` → `object::user:<uid>`, `local::/var/www/html/data/` → `object::store:amazon::<bucket>`.
`PRUNE_MISSING=1` deletes the filecache rows that have no file on disk — the Phase 1 logs listed them: stale
`files_versions/` entries and a leftover `appdata_snappymail` file. Without it, `finalize` refuses to proceed
(`FORCE=1` would keep the rows; they would 404 on the new instance). The old data dir is untouched.

### 2.2 Move the database

```bash
$OLD exec postgres-0 -- pg_dump -U nextcloud -Fc nexcloud > migrated.dump

DBNAME=$($NEW get secret omni365-server-postgres-app -o jsonpath='{.data.dbname}'   | base64 -d)
DBUSER=$($NEW get secret omni365-server-postgres-app -o jsonpath='{.data.username}' | base64 -d)
DBPASS=$($NEW get secret omni365-server-postgres-app -o jsonpath='{.data.password}' | base64 -d)
PRIMARY=$($NEW get cluster omni365-server-postgres -o jsonpath='{.status.currentPrimary}')

$NEW exec -i $PRIMARY -c postgres -- pg_restore -U postgres -d $DBNAME --no-owner --no-privileges --role=$DBUSER --clean --if-exists < migrated.dump
```

(`--no-privileges` skips the old DB's `GRANT ... TO oc_admin`, a role that doesn't exist on CNPG.)

Verify — expect `object::user:<uid>` per user plus one `object::store:amazon::<bucket>`, a filecache count close to what
`finalize` printed (it also counts directories), and the app user able to read:

```bash
$NEW exec $PRIMARY -c postgres -- psql -U postgres -d $DBNAME -c "select id from oc_storages"
$NEW exec $PRIMARY -c postgres -- psql -U postgres -d $DBNAME -c "select count(*) from oc_filecache"
$NEW exec $PRIMARY -c postgres -- env PGPASSWORD=$DBPASS psql -h localhost -U $DBUSER -d $DBNAME -c "select count(*) from oc_users"
```

### 2.3 Put config + custom apps on the new PVC

```bash
$NEW apply -f helper-pod.yaml && $NEW wait --for=condition=Ready pod/migration-helper
NEW_HOST=$($NEW get route omni365-server -o jsonpath='{.spec.host}')

$NEW rsync old/custom_apps/ migration-helper:/var/www/html/custom_apps/
$NEW cp old/config/config.php migration-helper:/var/www/html/config/config.php
$NEW cp fix-config.php migration-helper:/tmp/fix-config.php
$NEW exec migration-helper -- env NEW_DBHOST=omni365-server-postgres-rw NEW_DBNAME=$DBNAME NEW_DBUSER=$DBUSER \
  NEW_DBPASS=$DBPASS NEW_HOST=$NEW_HOST php /tmp/fix-config.php /var/www/html/config/config.php

# data dir marker (Nextcloud checks for it even with an object store)
$NEW exec migration-helper -- sh -c 'mkdir -p /var/www/html/data && printf "# Nextcloud data directory\n" > /var/www/html/data/.ncdata'

$NEW delete pod migration-helper
```

Omit `NEW_HOST=...` if the public hostname stays the same.

### 2.4 Start and verify

```bash
$NEW scale deploy/omni365-server --replicas=1
$NEW logs -f deploy/omni365-server
```

Wait for Apache to start. The log line about the "web-based installer" is expected — `config.php` says installed, so nothing gets installed.
If `occ status` reports a "Downgrading ... is not supported" error, the image major doesn't match the old instance: go back to step 0.0,
fix the tag, `set image` on the deployment, then remove `/var/www/html/version.php` from the PVC (via `helper-pod.yaml`) and scale up again.

```bash
$NEW exec deploy/omni365-server -- php occ status
# only if status says an upgrade is needed (image minor > old minor):
$NEW exec deploy/omni365-server -- php occ upgrade
# pretty URLs (/apps/..., /ocs/... without index.php): the installer normally writes this rewrite block; we skipped the installer
$NEW exec deploy/omni365-server -- php occ maintenance:update:htaccess
$NEW exec deploy/omni365-server -- php occ maintenance:repair
$NEW exec deploy/omni365-server -- php occ app:list | head -80
$NEW exec deploy/omni365-server -- php occ config:system:get objectstore arguments bucket
```

`app:list` should match the old instance; the last command should print the bucket name.

API check — must return 401 (routed to Nextcloud), not an Apache 404:
```bash
curl -s -o /dev/null -w '%{http_code}\n' https://omniserver.heritage.africa/apps/mail/api/accounts
```

Browser checks (with the `/etc/hosts` override from 0.1): log in via SSO → open files, previews, trash, versions →
open a shared link → Talk → calendar/contacts → `$NEW logs deploy/omni365-server-cron` shows `cron.php` runs without errors.
OnlyOffice editing will **not** work yet: the Document Server reaches Nextcloud via public DNS, which still points at
the old cluster. It works once DNS is switched.

Then switch DNS to the new router. Keep the old namespace in maintenance mode (and the old PVC) for at least a week.

---

## Phase 3 — after cutover

Hostname is unchanged, so IdP redirect URIs, OnlyOffice and Talk signaling need no reconfiguration — verify each once:
SSO login, open a document in OnlyOffice, start a Talk call.

### Previews (thumbnails) — expected 500s, fix once

Nextcloud 33 tracks previews in `oc_previews`; rows created on local storage have no object-store location, so on the
new instance every old thumbnail request fails with `AssertionError: assert(!empty($objectStoreName))` and
`occ preview:cleanup` fails the same way. Previews are derived data — drop the metadata and they regenerate on demand:

```bash
$NEW exec $PRIMARY -c postgres -- psql -U postgres -d $DBNAME -c "select count(*) total, count(location_id) with_location from oc_previews"
$NEW exec $PRIMARY -c postgres -- psql -U postgres -d $DBNAME -c "begin; delete from oc_previews; delete from oc_preview_versions; delete from oc_preview_locations; commit;"
```

(`with_location` should be 0 before you delete; if it isn't, stop and look.) The old preview files copied into the
bucket (~1 GB under `appdata_*/preview`) become orphans; `occ preview:cleanup` works again afterwards.

- `TRUSTED_PROXIES` in `server.yaml` must match the new cluster's pod network
  (`oc get network.config cluster -o jsonpath='{.status.clusterNetwork[*].cidr}'`); wrong value → wrong client IPs,
  brute-force protection hits the router IP.
- `notify_push`: was never running on the old instance; optional later (daemon sidecar + `/push` route).
- Delete the `rgw-migration` route and put the old pod's resource limits back (or leave it; it's in maintenance).
- After a week with no issues: scale the old deployment to 0, keep the PVC a while longer, then clean up.

## Rollback (any time before DNS switch)

Only `finalize` changed the old DB, and only `oc_storages`. Undo it in place:

```bash
$OLD exec $NCPOD -- sh -c "S3_BUCKET=$S3_BUCKET php $MIG/migrate-to-s3.php rollback"
$OLD exec $NCPOD -- su www-data -s /bin/sh -c 'php occ maintenance:mode --off'
```

Fallback if that fails for any reason — restore the dump taken at 2.1:

```bash
$OLD exec -i postgres-0 -- pg_restore -U nextcloud -d nexcloud --clean --if-exists < old/pre-finalize.dump
$OLD exec $NCPOD -- su www-data -s /bin/sh -c 'php occ maintenance:mode --off'
```

Files on the old PVC were never modified, so nothing else is needed. After DNS has moved and users have written to the
new instance, rolling back means losing those writes — decide within the first hours.
