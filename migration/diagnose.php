<?php
/**
 * Pre-migration diagnostics. Read-only. Run INSIDE the Nextcloud container:
 *
 *   oc cp diagnose.php <pod>:/tmp/diagnose.php
 *   oc exec <pod> -- php /tmp/diagnose.php            # add DU=1 to also run `du` on the data dir (slow on big volumes)
 *
 * or `oc rsh <pod>` then `php /tmp/diagnose.php`.
 */

$ncRoot = rtrim(getenv('NC_ROOT') ?: '/var/www/html', '/');
$CONFIG = [];
include $ncRoot . '/config/config.php';
include $ncRoot . '/version.php';          // $OC_VersionString

$p = $CONFIG['dbtableprefix'] ?? 'oc_';
[$dbHost, $dbPort] = array_pad(explode(':', $CONFIG['dbhost'], 2), 2, '5432');
$pdo = new PDO("pgsql:host=$dbHost;port=$dbPort;dbname={$CONFIG['dbname']}", $CONFIG['dbuser'], $CONFIG['dbpassword'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$datadir = rtrim($CONFIG['datadirectory'], '/');
$warn = [];

function h($t) { echo "\n==== $t\n"; }
function table(PDO $pdo, string $sql): void {
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { echo "(none)\n"; return; }
    $w = [];
    foreach ($rows as $r) foreach ($r as $k => $v) $w[$k] = max($w[$k] ?? strlen($k), strlen((string)$v));
    $line = fn($r) => implode('  ', array_map(fn($k) => str_pad((string)$r[$k], $w[$k]), array_keys($w)));
    echo $line(array_combine(array_keys($w), array_keys($w))) . "\n";
    foreach ($rows as $r) echo $line($r) . "\n";
}
function val(PDO $pdo, string $sql) { return $pdo->query($sql)->fetchColumn(); }
function gb($b) { return sprintf('%.1f GB', $b / 1e9); }
function occ(string $args): string {
    global $ncRoot;
    $cmd = "php $ncRoot/occ $args 2>&1";
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $cmd = "su www-data -s /bin/sh -c " . escapeshellarg($cmd);
    }
    return trim((string)shell_exec($cmd));
}
function appcfg(PDO $pdo, string $p, string $app, string $key) {
    $s = $pdo->prepare("SELECT configvalue FROM {$p}appconfig WHERE appid=? AND configkey=?");
    $s->execute([$app, $key]);
    return $s->fetchColumn();
}

// ---------------------------------------------------------------------------
h('Instance');
echo "version:        $OC_VersionString\n";
echo "maintenance:    " . (!empty($CONFIG['maintenance']) ? 'ON' : 'off') . "\n";
echo "datadirectory:  $datadir\n";
echo "db:             {$CONFIG['dbtype']} {$CONFIG['dbhost']} / {$CONFIG['dbname']} as {$CONFIG['dbuser']}\n";
echo "trusted_domains: " . implode(', ', $CONFIG['trusted_domains'] ?? []) . "\n";
echo "overwritehost:  " . ($CONFIG['overwritehost'] ?? '-') . "   overwrite.cli.url: " . ($CONFIG['overwrite.cli.url'] ?? '-') . "\n";
echo "objectstore in config: " . (isset($CONFIG['objectstore']) ? 'YES (already object store?)' : 'no') . "\n";
if (isset($CONFIG['objectstore'])) $warn[] = 'config.php already has an objectstore block';
echo "memcache: " . ($CONFIG['memcache.distributed'] ?? '-') . "  locking: " . ($CONFIG['memcache.locking'] ?? '-') . "\n";
if (!empty($CONFIG['maintenance'])) $warn[] = 'instance is in maintenance mode right now';

h('Disk');
echo shell_exec("df -h $datadir 2>&1");
if (getenv('DU')) { echo "du -sh (slow)...\n"; echo shell_exec("du -sh $datadir 2>&1"); }

h('Database');
echo "server:  " . val($pdo, 'SELECT version()') . "\n";
$dbBytes = (int)val($pdo, 'SELECT pg_database_size(current_database())');
echo "size:    " . gb($dbBytes) . "\n";
table($pdo, "SELECT relname AS table_name, pg_size_pretty(pg_total_relation_size(oid)) AS size FROM pg_class
             WHERE relkind='r' AND relnamespace='public'::regnamespace ORDER BY pg_total_relation_size(oid) DESC LIMIT 8");

h('Storages (oc_storages)');
table($pdo, "SELECT numeric_id, id FROM {$p}storages ORDER BY numeric_id");
$other = (int)val($pdo, "SELECT count(*) FROM {$p}storages WHERE id NOT LIKE 'home::%' AND id NOT LIKE 'local::%'");
if ($other) $warn[] = "$other storage(s) that are neither home:: nor local:: (external/shared mounts) - not covered by the migration script";
$locals = (int)val($pdo, "SELECT count(*) FROM {$p}storages WHERE id LIKE 'local::%'");
if ($locals !== 1) $warn[] = "$locals local:: storages (expected exactly 1, matching datadirectory)";
if (!val($pdo, "SELECT 1 FROM {$p}storages WHERE id IN ('local::$datadir/', 'local::$datadir')")) $warn[] = "no local:: storage matches datadirectory $datadir";

$base = "FROM {$p}filecache f JOIN {$p}mimetypes m ON m.id=f.mimetype JOIN {$p}storages s ON s.numeric_id=f.storage
         WHERE m.mimetype<>'httpd/unix-directory' AND (s.id LIKE 'home::%' OR s.id LIKE 'local::%')";

h('Files to migrate (home + local storages, directories excluded)');
$files = (int)val($pdo, "SELECT count(*) $base");
$bytes = (int)val($pdo, "SELECT coalesce(sum(f.size),0) $base");
$dirs  = (int)val($pdo, "SELECT count(*) FROM {$p}filecache f JOIN {$p}mimetypes m ON m.id=f.mimetype WHERE m.mimetype='httpd/unix-directory'");
echo "files: $files   size: " . gb($bytes) . "   (directories: $dirs, not copied)\n\n";
table($pdo, "SELECT CASE WHEN f.path LIKE 'files/%' THEN 'files'
                         WHEN f.path LIKE 'files_trashbin/%' THEN 'trashbin'
                         WHEN f.path LIKE 'files_versions/%' THEN 'versions'
                         WHEN f.path LIKE 'appdata_%/preview/%' THEN 'appdata/preview'
                         WHEN f.path LIKE 'appdata_%' THEN 'appdata/other'
                         WHEN f.path LIKE 'cache/%' OR f.path LIKE 'uploads/%' THEN 'cache+uploads'
                         ELSE 'other' END AS area,
                    count(*) AS files, pg_size_pretty(sum(f.size)) AS size
             $base GROUP BY 1 ORDER BY sum(f.size) DESC");

h('Top 15 users by size (files/ only)');
table($pdo, "SELECT s.id AS storage, count(*) AS files, pg_size_pretty(sum(f.size)) AS size
             $base AND s.id LIKE 'home::%' AND f.path LIKE 'files/%' GROUP BY s.id ORDER BY sum(f.size) DESC LIMIT 15");

h('Large files');
table($pdo, "SELECT count(*) FILTER (WHERE f.size > 5*1024^3) AS over_5gb,
                    count(*) FILTER (WHERE f.size > 50*1024^3) AS over_50gb,
                    count(*) FILTER (WHERE f.size = 0) AS empty_files,
                    pg_size_pretty(max(f.size)) AS largest $base");
table($pdo, "SELECT f.fileid, s.id AS storage, left(f.path, 70) AS path, pg_size_pretty(f.size) AS size
             $base ORDER BY f.size DESC LIMIT 8");

h('Users and shares');
echo occ('user:report') . "\n";
table($pdo, "SELECT share_type, count(*) FROM {$p}share GROUP BY 1 ORDER BY 1");

h('Apps');
$enabledApps = $pdo->query("SELECT appid, configvalue FROM {$p}appconfig WHERE configkey='enabled' AND configvalue<>'no' ORDER BY appid")->fetchAll(PDO::FETCH_KEY_PAIR);
echo "enabled (" . count($enabledApps) . "): " . implode(' ', array_keys($enabledApps)) . "\n";
foreach (['files_external', 'groupfolders', 'user_ldap', 'encryption'] as $a) {
    if (isset($enabledApps[$a])) $warn[] = "app '$a' is enabled - needs separate handling, not covered by the filesystem->S3 script";
}
$enc = appcfg($pdo, $p, 'core', 'encryption_enabled');
echo "server-side encryption: " . ($enc === 'yes' ? 'ENABLED' : 'off') . "\n";
if ($enc === 'yes') $warn[] = 'server-side encryption is ENABLED - decrypt first (occ encryption:decrypt-all) or the bucket will hold encrypted blobs';
echo "custom_apps: " . trim((string)shell_exec("ls -m $ncRoot/custom_apps 2>&1")) . "\n";
echo "custom_apps size: " . trim((string)shell_exec("du -sh $ncRoot/custom_apps 2>&1 | cut -f1")) . "\n";

h('External services / hostname-bound config');
echo "onlyoffice DocumentServerUrl: " . (appcfg($pdo, $p, 'onlyoffice', 'DocumentServerUrl') ?: '-') . "\n";
echo "spreed signaling_servers:     " . substr((string)(appcfg($pdo, $p, 'spreed', 'signaling_servers') ?: '-'), 0, 200) . "\n";
echo "notify_push base_endpoint:    " . (appcfg($pdo, $p, 'notify_push', 'base_endpoint') ?: '- (not set up)') . "\n";
echo "backgroundjobs_mode:          " . (appcfg($pdo, $p, 'core', 'backgroundjobs_mode') ?: '-') . "\n";
$lastcron = (int)appcfg($pdo, $p, 'core', 'lastcron');
echo "lastcron:                     " . ($lastcron ? date('c', $lastcron) . ' (' . round((time() - $lastcron) / 60) . ' min ago)' : 'never') . "\n";
if ($lastcron && time() - $lastcron > 3600) $warn[] = 'background jobs have not run for over an hour';
echo "user_oidc providers:\n" . preg_replace('/^/m', '  ', occ('user_oidc:provider')) . "\n";

h('Log');
echo trim((string)shell_exec("ls -lh $datadir/nextcloud.log 2>&1")) . "\n";
echo "errors/fatals in last 500 lines: " . trim((string)shell_exec("tail -n 500 $datadir/nextcloud.log 2>/dev/null | grep -c '\"level\":[34]'")) . "\n";

// ---------------------------------------------------------------------------
h('SUMMARY');
printf("files to copy:   %d  (%s)\n", $files, gb($bytes));
printf("database:        %s\n", gb($dbBytes));
printf("Ceph needed (3 replicas): ~%s raw\n", gb(3 * $bytes));
printf("Phase 1 estimate at 20 / 50 / 100 MB/s:  %.1f h / %.1f h / %.1f h\n", $bytes / 20e6 / 3600, $bytes / 50e6 / 3600, $bytes / 100e6 / 3600);
printf("script memory_limit:  ~%dM  (512M + 150M per million files)\n", 512 + 150 * $files / 1e6);
printf("CNPG storage.size:    at least %s (3x current DB)\n", gb(3 * $dbBytes));
echo "\n";
if ($warn) { echo "WARNINGS:\n"; foreach ($warn as $w) echo "  - $w\n"; } else { echo "no warnings\n"; }
