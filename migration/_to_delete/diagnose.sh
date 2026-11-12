#!/usr/bin/env bash
# Read-only pre-migration diagnostics for the old (filesystem) Nextcloud and the new (object store) target.
# Nothing is modified on either cluster. Raw output goes to diag-<timestamp>/, a summary is printed at the end.
#
#   OLD_NS=<namespace> ./diagnose.sh
#
# Optional env:
#   OLD_CTX=old  NEW_CTX=new  NEW_NS=omni365
#   NC_SELECTOR=app=nextcloud   PG_POD=postgres-0      (old cluster)
#   DU=1          also run `du -sh` on the data dir (slow on large CephFS volumes)
#   SKIP_NEW=1    don't touch the new cluster
set -u

: "${OLD_NS:?set OLD_NS=<old namespace>}"
OLD_CTX=${OLD_CTX:-old}; NEW_CTX=${NEW_CTX:-new}; NEW_NS=${NEW_NS:-omni365}
NC_SELECTOR=${NC_SELECTOR:-app=nextcloud}; PG_POD=${PG_POD:-postgres-0}

OUT="diag-$(date +%Y%m%d-%H%M%S)"; mkdir -p "$OUT"
OLD="oc --context $OLD_CTX -n $OLD_NS"
NEW="oc --context $NEW_CTX -n $NEW_NS"
WARN=()
warn() { WARN+=("$*"); }
section() { printf '\n==== %s\n' "$*"; }
save() { tee "$OUT/$1"; }          # usage: cmd | save name.txt

NCPOD=$($OLD get pod -l "$NC_SELECTOR" -o jsonpath='{.items[0].metadata.name}' 2>/dev/null)
[ -n "$NCPOD" ] || { echo "no pod matches -l $NC_SELECTOR in $OLD_NS"; exit 1; }
occ() { $OLD exec "$NCPOD" -- su www-data -s /bin/sh -c "php occ $*" 2>&1; }
inpod() { $OLD exec "$NCPOD" -- sh -c "$*" 2>&1; }

# ---------------------------------------------------------------------------
section "Old instance: version / status"
occ status | save status.txt
VERSION=$(grep -E '^\s*- versionstring' "$OUT/status.txt" | awk '{print $3}')
grep -q 'maintenance: true' "$OUT/status.txt" && warn "old instance is in maintenance mode right now"

section "Old instance: pod, image, resources, PVC"
$OLD get deploy -l "$NC_SELECTOR" -o jsonpath='{range .items[*]}image={.spec.template.spec.containers[0].image}{"\n"}resources={.spec.template.spec.containers[0].resources}{"\n"}{end}' | save pod.txt
$OLD get pvc | save pvc.txt
inpod 'df -h /var/www/html' | save df.txt
if [ "${DU:-0}" = 1 ]; then
  echo "du -sh data (this can take a long time)..."
  inpod 'du -sh /var/www/html/data' | save du.txt
fi

section "Old instance: database connection (from config.php)"
DBHOST=$(occ config:system:get dbhost); DBNAME=$(occ config:system:get dbname)
DBUSER=$(occ config:system:get dbuser); PREFIX=$(occ config:system:get dbtableprefix); PREFIX=${PREFIX:-oc_}
DATADIR=$(occ config:system:get datadirectory)
echo "dbhost=$DBHOST dbname=$DBNAME dbuser=$DBUSER prefix=$PREFIX datadirectory=$DATADIR" | save db-conn.txt
psql() { $OLD exec "$PG_POD" -- psql -U "$DBUSER" -d "$DBNAME" "$@" 2>&1; }
q()    { psql -At -c "$1"; }       # single value / machine readable

section "Database: version and size"
{ q "select version()"; q "select pg_size_pretty(pg_database_size(current_database()))"; } | save db-size.txt
psql -c "select relname, pg_size_pretty(pg_total_relation_size(oid)) size from pg_class where relkind='r' and relnamespace='public'::regnamespace order by pg_total_relation_size(oid) desc limit 10" | save db-tables.txt
DBBYTES=$(q "select pg_database_size(current_database())")

section "Storages (oc_storages)"
psql -c "select numeric_id, id from ${PREFIX}storages order by numeric_id" | save storages.txt
OTHER_STORAGES=$(q "select count(*) from ${PREFIX}storages where id not like 'home::%' and id not like 'local::%'")
[ "$OTHER_STORAGES" != 0 ] && warn "$OTHER_STORAGES storage(s) that are neither home:: nor local:: (external/shared mounts?) - the script ignores them; see storages.txt"
LOCAL_COUNT=$(q "select count(*) from ${PREFIX}storages where id like 'local::%'")
[ "$LOCAL_COUNT" != 1 ] && warn "$LOCAL_COUNT local:: storages (expected exactly 1 matching datadirectory)"

section "Filecache: totals (home + local storages, files vs directories)"
psql -c "
select count(*) filter (where m.mimetype <> 'httpd/unix-directory') as files,
       count(*) filter (where m.mimetype  = 'httpd/unix-directory') as dirs,
       pg_size_pretty(coalesce(sum(f.size) filter (where m.mimetype <> 'httpd/unix-directory'),0)) as total_size
from ${PREFIX}filecache f join ${PREFIX}mimetypes m on m.id=f.mimetype join ${PREFIX}storages s on s.numeric_id=f.storage
where s.id like 'home::%' or s.id like 'local::%'" | save filecache-totals.txt
FILES=$(q "select count(*) from ${PREFIX}filecache f join ${PREFIX}mimetypes m on m.id=f.mimetype join ${PREFIX}storages s on s.numeric_id=f.storage where m.mimetype<>'httpd/unix-directory' and (s.id like 'home::%' or s.id like 'local::%')")
BYTES=$(q "select coalesce(sum(f.size),0) from ${PREFIX}filecache f join ${PREFIX}mimetypes m on m.id=f.mimetype join ${PREFIX}storages s on s.numeric_id=f.storage where m.mimetype<>'httpd/unix-directory' and (s.id like 'home::%' or s.id like 'local::%')")

section "Filecache: breakdown by area"
psql -c "
select case when f.path like 'files/%' then 'files'
            when f.path like 'files_trashbin/%' then 'trashbin'
            when f.path like 'files_versions/%' then 'versions'
            when f.path like 'appdata_%/preview/%' then 'appdata/preview'
            when f.path like 'appdata_%' then 'appdata/other'
            when f.path like 'cache/%' or f.path like 'uploads/%' then 'cache+uploads'
            else 'other' end as area,
       count(*) as files, pg_size_pretty(sum(f.size)) as size
from ${PREFIX}filecache f join ${PREFIX}mimetypes m on m.id=f.mimetype join ${PREFIX}storages s on s.numeric_id=f.storage
where m.mimetype<>'httpd/unix-directory' and (s.id like 'home::%' or s.id like 'local::%')
group by 1 order by sum(f.size) desc" | save filecache-areas.txt

section "Filecache: top 15 users by size (files/ only)"
psql -c "
select s.id as storage, count(*) as files, pg_size_pretty(sum(f.size)) as size
from ${PREFIX}filecache f join ${PREFIX}mimetypes m on m.id=f.mimetype join ${PREFIX}storages s on s.numeric_id=f.storage
where m.mimetype<>'httpd/unix-directory' and s.id like 'home::%' and f.path like 'files/%'
group by s.id order by sum(f.size) desc limit 15" | save filecache-users.txt

section "Filecache: large files"
psql -c "
select count(*) filter (where size > 5*1024^3)  as over_5gb,
       count(*) filter (where size > 50*1024^3) as over_50gb,
       count(*) filter (where size = 0)         as empty_files,
       pg_size_pretty(max(size)) as largest
from ${PREFIX}filecache f join ${PREFIX}mimetypes m on m.id=f.mimetype where m.mimetype<>'httpd/unix-directory'" | save filecache-large.txt
psql -c "select f.fileid, s.id as storage, f.path, pg_size_pretty(f.size) from ${PREFIX}filecache f join ${PREFIX}storages s on s.numeric_id=f.storage order by f.size desc limit 10" | save filecache-largest.txt

section "Users, shares"
occ user:report | save users.txt
psql -c "select share_type, count(*) from ${PREFIX}share group by 1 order by 1" | save shares.txt

section "Apps"
occ app:list | save apps.txt
occ encryption:status | save encryption.txt
grep -q 'enabled: true' "$OUT/encryption.txt" && warn "server-side ENCRYPTION is enabled - the migration script copies encrypted blobs; decrypt first (occ encryption:decrypt-all) or stop"
for a in files_external groupfolders user_ldap; do
  grep -qE "^\s*- $a:" "$OUT/apps.txt" && sed -n '/^Enabled/,/^Disabled/p' "$OUT/apps.txt" | grep -q "  - $a:" && warn "app '$a' is ENABLED - needs separate handling (not covered by the filesystem->S3 script)"
done
inpod 'ls -la /var/www/html/custom_apps && du -sh /var/www/html/custom_apps' | save custom_apps.txt

section "Apps with external services / hostname-bound config"
{
  echo "onlyoffice DocumentServerUrl: $(occ config:app:get onlyoffice DocumentServerUrl)"
  echo "spreed signaling_servers:     $(occ config:app:get spreed signaling_servers | head -c 300)"
  echo "notify_push:                  $(occ notify_push:self-test 2>&1 | tail -n 1)"
  echo "user_oidc providers:"; occ user_oidc:provider 2>&1 | sed 's/^/  /'
  echo "app_api daemons:"; occ app_api:daemon:list 2>&1 | sed 's/^/  /'
  echo "backgroundjobs_mode: $(occ config:app:get core backgroundjobs_mode)"
  LASTCRON=$(occ config:app:get core lastcron); echo "lastcron: $LASTCRON ($(date -r "${LASTCRON:-0}" 2>/dev/null || date -d @"${LASTCRON:-0}" 2>/dev/null))"
  echo "trusted_domains: $(occ config:system:get trusted_domains | tr '\n' ' ')"
  echo "overwritehost: $(occ config:system:get overwritehost)   overwrite.cli.url: $(occ config:system:get overwrite.cli.url)"
} | save external-services.txt
occ config:list > "$OUT/config-list.json" 2>&1 && echo "full app/system config (secrets redacted by occ) -> $OUT/config-list.json"

section "nextcloud.log"
inpod "ls -lh $DATADIR/nextcloud.log; tail -n 500 $DATADIR/nextcloud.log | grep -c '\"level\":[34]' | sed 's/^/errors+fatals in last 500 lines: /'" | save log.txt

# ---------------------------------------------------------------------------
if [ "${SKIP_NEW:-0}" != 1 ]; then
  section "New cluster: target resources"
  { $NEW get deploy omni365-server -o jsonpath='image={.spec.template.spec.containers[0].image} replicas={.spec.replicas}{"\n"}'
    $NEW get cluster omni365-server-postgres -o jsonpath='cnpg phase={.status.phase} instances={.status.readyInstances}/{.spec.instances} storage={.spec.storage.size} primary={.status.currentPrimary}{"\n"}'
    $NEW get obc omni365-server-s3 -o jsonpath='obc phase={.status.phase} bucket={.spec.bucketName}{"\n"}'
    $NEW get pods -l app=omni365-server-redis --no-headers | awk '{r[$3]++} END{for(k in r) printf "redis pods %s=%d\n", k, r[k]}'
    $NEW get route omni365-server -o jsonpath='route host={.spec.host}{"\n"}'
    oc --context "$NEW_CTX" -n openshift-storage get route -o name | sed 's/^/openshift-storage route: /'
  } 2>&1 | save new-cluster.txt
  CNPG_SIZE=$($NEW get cluster omni365-server-postgres -o jsonpath='{.spec.storage.size}' 2>/dev/null)

  section "New cluster: Ceph capacity"
  oc --context "$NEW_CTX" -n openshift-storage get cephcluster -o jsonpath='{range .items[*]}{.metadata.name}: health={.status.ceph.health} total={.status.ceph.capacity.bytesTotal} used={.status.ceph.capacity.bytesUsed} available={.status.ceph.capacity.bytesAvailable}{"\n"}{end}' 2>&1 | save ceph.txt
  CEPH_AVAIL=$(oc --context "$NEW_CTX" -n openshift-storage get cephcluster -o jsonpath='{.items[0].status.ceph.capacity.bytesAvailable}' 2>/dev/null)
fi

# ---------------------------------------------------------------------------
section "SUMMARY"
gb() { awk -v b="${1:-0}" 'BEGIN{printf "%.1f", b/1e9}'; }
echo "old Nextcloud version:      ${VERSION:-?}"
echo "files to copy:              ${FILES:-?}   ($(gb "$BYTES") GB in filecache)"
echo "database size:              $(gb "$DBBYTES") GB"
[ -n "${CNPG_SIZE:-}" ] && echo "CNPG storage per instance:  $CNPG_SIZE"
[ -n "${CEPH_AVAIL:-}" ] && echo "Ceph available (raw):       $(gb "$CEPH_AVAIL") GB   (need ~3x data = $(awk -v b="$BYTES" 'BEGIN{printf "%.0f", 3*b/1e9}') GB)"
echo "estimated Phase 1 at 20 / 50 / 100 MB/s: $(awk -v b="$BYTES" 'BEGIN{printf "%.1fh / %.1fh / %.1fh", b/20e6/3600, b/50e6/3600, b/100e6/3600}')"
echo "memory for the script:      ~$(awk -v f="${FILES:-0}" 'BEGIN{printf "%d", 512 + 150*f/1e6}')Mi memory_limit (bucket listing)"

# cross-checks
if [ -n "${CEPH_AVAIL:-}" ] && [ -n "$BYTES" ]; then
  awk -v a="$CEPH_AVAIL" -v b="$BYTES" 'BEGIN{exit !(a < 3.3*b)}' && warn "Ceph available ($(gb "$CEPH_AVAIL") GB) is below ~3.3x the data size - not enough room for 3 replicas"
fi
if [ -n "${CNPG_SIZE:-}" ] && [ -n "$DBBYTES" ]; then
  cnpg_bytes=$(echo "$CNPG_SIZE" | awk '/Gi/{printf "%d", $1*1073741824} /Ti/{printf "%d", $1*1099511627776} /Mi/{printf "%d", $1*1048576}')
  [ -n "$cnpg_bytes" ] && awk -v a="$cnpg_bytes" -v b="$DBBYTES" 'BEGIN{exit !(a < 3*b)}' && warn "CNPG storage ($CNPG_SIZE) is less than 3x the current DB size - raise spec.storage.size in postgres.yaml"
fi
OVER50=$(q "select count(*) from ${PREFIX}filecache where size > 50*1024^3")
[ "${OVER50:-0}" != 0 ] && echo "note: $OVER50 file(s) over 50 GB - handled by the script's adaptive part size"

echo
if [ ${#WARN[@]} -gt 0 ]; then
  echo "WARNINGS:"; printf '  - %s\n' "${WARN[@]}"
else
  echo "no warnings"
fi
echo
echo "raw output in $OUT/"
