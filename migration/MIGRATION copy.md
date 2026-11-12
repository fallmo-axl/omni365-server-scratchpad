# Nextcloud migration: filesystem (old cluster) → object store (new cluster)

Both sides run Nextcloud 33. Old: `nextcloud-kubernetes/filesystem/*` (CephFS data dir, PG 17 StatefulSet,
single Redis). New: `omni365-server` (Ceph RGW bucket, CNPG PG 18, Redis cluster).

Conventions: `oc` contexts `old` and `new`; old namespace `$OLD_NS`; new namespace `omni365`.
Run everything from the `migration/` folder.

```bash
export OLD_NS=<old-namespace>
OLD='oc --context old -n '"$OLD_NS"
NEW='oc --context new -n omni365'
NCPOD=$($OLD get pod -l app=nextcloud -o jsonpath='{.items[0].metadata.name}')
```

---

## Phase 0 — prepare (no downtime)

### 0.0 Match the Nextcloud version

The old image tag (`s390x/nextcloud:production`) floats. The new image must be built on the **same major**
as what the old instance actually runs — the entrypoint refuses downgrades and skipping a major.

```bash
$OLD exec $NCPOD -- su www-data -s /bin/sh -c 'php occ status'          # e.g. versionstring: 35.0.2
docker build --build-arg NEXTCLOUD_TAG=35-apache -t quay.io/mohamedf0/omni365-server:v2 ..   # same major as above
docker push quay.io/mohamedf0/omni365-server:v2
```

Then set that tag in `../server.yaml` and `helper-pod.yaml` before continuing.

### 0.1 Deploy the new stack, init the PVC, stop the app

```bash
$NEW apply -f ../postgres.yaml -f ../redis.yaml -f ../server.yaml
# wait for the 6 redis pods, then create the cluster:
$NEW exec omni365-server-redis-0 -- redis-cli --cluster create \
  $($NEW get pods -l app=omni365-server-redis -o jsonpath='{range .items[*]}{.status.podIP}:6379 {end}') \
  --cluster-replicas 1 --cluster-yes
# wait for omni365-server pod Running (it copies the Nextcloud code into the PVC; do NOT open the web installer), then:
$NEW scale deploy/omni365-server --replicas=0
```

### 0.2 Make RGW reachable from the old cluster

```bash
$NEW -n openshift-storage get route
```

If no route points at `rook-ceph-rgw-ocs-storagecluster-cephobjectstore`, create one:

```bash
oc --context new -n openshift-storage create route edge rgw-migration \
  --service=rook-ceph-rgw-ocs-storagecluster-cephobjectstore --port=http
RGW_HOST=$(oc --context new -n openshift-storage get route rgw-migration -o jsonpath='{.spec.host}')
```

Collect bucket credentials:

```bash
S3_BUCKET=$($NEW get cm omni365-server-s3 -o jsonpath='{.data.BUCKET_NAME}')
S3_KEY=$($NEW get secret omni365-server-s3 -o jsonpath='{.data.AWS_ACCESS_KEY_ID}' | base64 -d)
S3_SECRET=$($NEW get secret omni365-server-s3 -o jsonpath='{.data.AWS_SECRET_ACCESS_KEY}' | base64 -d)
```

Quick check from the old pod (ingress cert must be trusted there; otherwise use `S3_VERIFY=false` below):

```bash
$OLD exec $NCPOD -- curl -sS -o /dev/null -w '%{http_code}\n' https://$RGW_HOST/   # 403 = reachable
```

### 0.3 Backups + copy of config and custom apps

```bash
mkdir -p old
$OLD exec postgres-0 -- pg_dump -U nextcloud -Fc nexcloud > old/pre-migration.dump
$OLD rsync $NCPOD:/var/www/html/config/ old/config/
$OLD rsync $NCPOD:/var/www/html/custom_apps/ old/custom_apps/
$OLD exec $NCPOD -- du -sh /var/www/html/data
```

### 0.4 Raise the old pod's limits and dry-run the script

The old pod runs at 200m / 256Mi. Raise it for the migration (restarts the pod once, ~30s interruption):

```bash
$OLD set resources deploy/nextcloud --limits=cpu=2,memory=1Gi
$OLD rollout status deploy/nextcloud
NCPOD=$($OLD get pod -l app=nextcloud -o jsonpath='{.items[0].metadata.name}')

$OLD cp migrate-to-s3.php $NCPOD:/tmp/migrate-to-s3.php
$OLD exec $NCPOD -- php -d memory_limit=512M /tmp/migrate-to-s3.php dry
```

Expected: `N files in filecache, ... 0 missing on disk`. If files are missing, run
`php occ files:scan --all` on the old instance and re-run `dry` until it is 0 (or accept them and use `FORCE=1` later).

---

## Phase 1 — pre-sync files while the old instance is live

At ~1 GB this takes minutes; the phase is optional but it validates the S3 path before the downtime window.

```bash
$OLD exec $NCPOD -- sh -c "S3_ENDPOINT=https://$RGW_HOST S3_BUCKET=$S3_BUCKET S3_KEY=$S3_KEY S3_SECRET=$S3_SECRET \
  nohup php -d memory_limit=512M /tmp/migrate-to-s3.php sync > /tmp/migrate.log 2>&1 &"
$OLD exec $NCPOD -- tail -f /tmp/migrate.log
```

Idempotent — re-run as often as you like; later runs only upload new/changed files.
Add `S3_VERIFY=false` to the env if the RGW route uses a cert the pod doesn't trust.

---

## Phase 2 — cutover (downtime starts)

### 2.1 Freeze the old instance and finalize the file migration

```bash
$OLD exec $NCPOD -- su www-data -s /bin/sh -c 'php occ maintenance:mode --on'
$OLD exec $NCPOD -- sh -c "S3_ENDPOINT=https://$RGW_HOST S3_BUCKET=$S3_BUCKET S3_KEY=$S3_KEY S3_SECRET=$S3_SECRET \
  php -d memory_limit=512M /tmp/migrate-to-s3.php finalize"
```

`finalize` uploads the delta, then rewrites `oc_storages` in the **old** DB:
`home::<uid>` → `object::user:<uid>`, `local::/var/www/html/data/` → `object::store:amazon::<bucket>`.
The old data dir is untouched; rollback = restore `old/pre-migration.dump` and turn maintenance off.

### 2.2 Move the database

```bash
$OLD exec postgres-0 -- pg_dump -U nextcloud -Fc nexcloud > migrated.dump

DBNAME=$($NEW get secret omni365-server-postgres-app -o jsonpath='{.data.dbname}'   | base64 -d)
DBUSER=$($NEW get secret omni365-server-postgres-app -o jsonpath='{.data.username}' | base64 -d)
DBPASS=$($NEW get secret omni365-server-postgres-app -o jsonpath='{.data.password}' | base64 -d)
PRIMARY=$($NEW get cluster omni365-server-postgres -o jsonpath='{.status.currentPrimary}')

$NEW exec -i $PRIMARY -c postgres -- pg_restore -U postgres -d $DBNAME --no-owner --role=$DBUSER --clean --if-exists < migrated.dump
$NEW exec $PRIMARY -c postgres -- psql -U postgres -d $DBNAME -c "select id from oc_storages"   # should show object::user:* and object::store:amazon::*
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
$OLD cp $NCPOD:/var/www/html/data/.ncdata old/.ncdata
$NEW exec migration-helper -- mkdir -p /var/www/html/data
$NEW cp old/.ncdata migration-helper:/var/www/html/data/.ncdata

$NEW delete pod migration-helper
```

Omit `NEW_HOST=...` if the public hostname stays the same.

### 2.4 Start and verify

```bash
$NEW scale deploy/omni365-server --replicas=1
$NEW logs -f deploy/omni365-server            # wait for apache to start; no "installing" / "upgrading" errors
$NEW exec deploy/omni365-server -- php occ status
$NEW exec deploy/omni365-server -- php occ maintenance:repair
$NEW exec deploy/omni365-server -- php occ app:list | head -80   # same enabled list as the old instance
$NEW exec deploy/omni365-server -- php occ config:system:get objectstore bucket   # = $S3_BUCKET
```

Browser checks on the new Route: log in → open files, previews, trash, versions → open a shared link → calendar/contacts.
Then switch DNS / move the Route hostname. Keep the old namespace in maintenance mode until you're confident.

---

## Phase 3 — after cutover

- `notify_push`: needs its daemon; under `restricted-v2` add it as a sidecar, then `occ notify_push:setup https://<host>/push`.
- `user_oidc`, `sociallogin`, `omni365_oidc`: update redirect URIs at the IdP if the hostname changed.
- `onlyoffice`, `spreed` (HPB), `app_api` daemon: re-point URLs in admin settings.
- Cron: make sure background jobs run (`occ background:cron` via a CronJob or sidecar).
- Delete the `rgw-migration` route when done.

## Rollback (any time before DNS switch)

```bash
$OLD exec -i postgres-0 -- pg_restore -U nextcloud -d nexcloud --clean --if-exists < old/pre-migration.dump
$OLD exec $NCPOD -- su www-data -s /bin/sh -c 'php occ maintenance:mode --off'
```
