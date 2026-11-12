<?php
/**
 * Nextcloud primary storage migration: local filesystem -> S3 (object store).
 *
 * Run INSIDE the old Nextcloud container (needs the AWS SDK shipped in 3rdparty/,
 * the data directory, and DB access via config.php).
 *
 *   php -d memory_limit=1G migrate-to-s3.php dry        # count & verify files on disk, no uploads, no DB writes
 *   php -d memory_limit=1G migrate-to-s3.php sync       # upload to S3 (idempotent; safe to run while Nextcloud is live)
 *   php -d memory_limit=1G migrate-to-s3.php finalize   # upload delta since last sync, then rewrite oc_storages
 *                                                        # (requires maintenance mode ON)
 *   php migrate-to-s3.php rollback                      # undo the oc_storages rewrite (requires maintenance mode ON)
 *
 * Environment:
 *   S3_ENDPOINT  https://<rgw-host>             (required for sync/finalize)
 *   S3_BUCKET    bucket name                    (required for sync/finalize/rollback)
 *   S3_KEY       access key                     (required for sync/finalize)
 *   S3_SECRET    secret key                     (required for sync/finalize)
 *   S3_REGION    default us-east-1
 *   S3_VERIFY    path to CA bundle, or "false" to skip TLS verification (default: system CAs)
 *   NC_ROOT      Nextcloud root, default /var/www/html
 *   FORCE=1          allow finalize even if some filecache entries have no file on disk (rows are kept; they 404 later)
 *   PRUNE_MISSING=1  finalize only: delete the filecache rows that have no file on disk (stale versions, leftovers
 *                    of removed apps). Done in the same transaction as the storage rewrite.
 *   SHARD=i/n    sync only: run n copies in parallel (SHARD=0/4 .. SHARD=3/4), each takes fileid % n == i.
 *                finalize must run unsharded.
 *   VERIFY=1     for files that look unchanged, HEAD the object and compare the stored Nextcloud etag;
 *                re-upload on mismatch. Catches content changes that kept size and mtime (e.g. version restores).
 *                Slower (one request per file) - use it for the last sync pass before the maintenance window.
 *
 * State files (incremental runs) live in <datadirectory>/.migrate-s3/ - not web-accessible, survives pod restarts.
 *
 * Object layout matches Nextcloud's S3 object store: key = "urn:oid:<fileid>".
 * Storage ids are rewritten the way Nextcloud names them itself:
 *   home::<uid>            -> object::user:<uid>
 *   local::<datadir>/      -> object::store:amazon::<bucket>
 */

$mode = $argv[1] ?? '';
if (!in_array($mode, ['dry', 'sync', 'finalize', 'rollback'], true)) {
    fwrite(STDERR, "usage: migrate-to-s3.php dry|sync|finalize|rollback\n");
    exit(2);
}

$ncRoot = rtrim(getenv('NC_ROOT') ?: '/var/www/html', '/');
require_once $ncRoot . '/3rdparty/autoload.php';
$CONFIG = [];
include $ncRoot . '/config/config.php';

// S3 is only needed for sync/finalize; dry only reads the DB and the data directory, rollback only writes the DB.
$s3 = null;
$bucket = getenv('S3_BUCKET') ?: '';
$verifyEtag = (bool)getenv('VERIFY');
if ($mode === 'sync' || $mode === 'finalize') {
    foreach (['S3_ENDPOINT', 'S3_BUCKET', 'S3_KEY', 'S3_SECRET'] as $v) {
        if (!getenv($v)) {
            fwrite(STDERR, "$v is not set\n");
            exit(2);
        }
    }
    $verify = getenv('S3_VERIFY');
    $verify = ($verify === false || $verify === '') ? true : ($verify === 'false' ? false : $verify);
    $s3 = new Aws\S3\S3Client([
        'version' => 'latest',
        'region' => getenv('S3_REGION') ?: 'us-east-1',
        'endpoint' => getenv('S3_ENDPOINT'),
        'use_path_style_endpoint' => true,
        'credentials' => ['key' => getenv('S3_KEY'), 'secret' => getenv('S3_SECRET')],
        'http' => ['verify' => $verify],
    ]);
}

if (($mode === 'finalize' || $mode === 'rollback') && empty($CONFIG['maintenance'])) {
    fwrite(STDERR, "$mode requires maintenance mode: php occ maintenance:mode --on\n");
    exit(2);
}

$prefix = $CONFIG['dbtableprefix'] ?? 'oc_';
[$dbHost, $dbPort] = array_pad(explode(':', $CONFIG['dbhost'], 2), 2, '5432');
$pdo = new PDO(
    "pgsql:host=$dbHost;port=$dbPort;dbname={$CONFIG['dbname']}",
    $CONFIG['dbuser'],
    $CONFIG['dbpassword'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$datadir = rtrim($CONFIG['datadirectory'], '/');

// ---- rollback: reverse the finalize rewrite, nothing else ------------------
if ($mode === 'rollback') {
    if ($bucket === '') {
        fwrite(STDERR, "S3_BUCKET is needed to identify the object::store row\n");
        exit(2);
    }
    $pdo->beginTransaction();
    $n1 = $pdo->exec("UPDATE {$prefix}storages SET id = 'home::' || substr(id, 14) WHERE id LIKE 'object::user:%'");
    $u = $pdo->prepare("UPDATE {$prefix}storages SET id = :old WHERE id = :new");
    $u->execute([':old' => "local::$datadir/", ':new' => 'object::store:amazon::' . $bucket]);
    $n2 = $u->rowCount();
    $pdo->commit();
    echo "oc_storages restored: $n1 object::user -> home, $n2 object::store -> local::$datadir/\n";
    echo "Now: php occ maintenance:mode --off (and check the file list in the browser).\n";
    exit(0);
}

// ---- storages -------------------------------------------------------------
$roots = [];       // numeric_id => filesystem root for filecache paths
$localIds = [];    // oc_storages.id values of local:: storages
$homeCount = 0;
foreach ($pdo->query("SELECT numeric_id, id FROM {$prefix}storages WHERE id LIKE 'home::%' OR id LIKE 'local::%'") as $s) {
    if (str_starts_with($s['id'], 'home::')) {
        $roots[$s['numeric_id']] = $datadir . '/' . substr($s['id'], 6);
        $homeCount++;
    } else {
        $roots[$s['numeric_id']] = rtrim(substr($s['id'], 7), '/');
        $localIds[] = $s['id'];
    }
}
if (!$roots) {
    fwrite(STDERR, "no home::/local:: storages found - already migrated?\n");
    exit(1);
}
$dataLocalId = in_array("local::$datadir/", $localIds, true) ? "local::$datadir/"
             : (in_array("local::$datadir", $localIds, true) ? "local::$datadir" : null);
echo "storages: $homeCount home, " . count($localIds) . " local (" . implode(', ', $localIds) . ")\n";
if ($dataLocalId === null) {
    fwrite(STDERR, "WARNING: no local:: storage matches datadirectory=$datadir\n");
}

$dirMime = (int)$pdo->query("SELECT id FROM {$prefix}mimetypes WHERE mimetype = 'httpd/unix-directory'")->fetchColumn();

// ---- sharding (SHARD=i/n runs n copies in parallel, each handling fileid % n == i) ----
$shardI = 0; $shardN = 1;
if ($shard = getenv('SHARD')) {
    if (!preg_match('#^(\d+)/(\d+)$#', $shard, $m) || (int)$m[2] < 1 || (int)$m[1] >= (int)$m[2]) {
        fwrite(STDERR, "SHARD must look like 0/4 (index/count)\n");
        exit(2);
    }
    [$shardI, $shardN] = [(int)$m[1], (int)$m[2]];
}
if ($mode === 'finalize' && $shardN > 1) {
    fwrite(STDERR, "finalize must run unsharded (it rewrites oc_storages once)\n");
    exit(2);
}

// ---- state (for incremental runs) ----------------------------------------
// Each shard keeps its own state file; finalize uses the oldest start time of any previous run.
// Kept under the data directory: Apache denies web access there and it survives pod restarts (PVC).
$stateDir = $datadir . '/.migrate-s3';
if (!is_dir($stateDir) && !@mkdir($stateDir, 0750, true)) {
    fwrite(STDERR, "cannot create $stateDir\n");
    exit(1);
}
$stateFile = $stateDir . '/state' . ($shardN > 1 ? ".$shardI-$shardN" : '') . '.json';
$sinceMtime = 0;
foreach (glob($stateDir . '/state*.json') ?: [] as $f) {
    $st = json_decode(file_get_contents($f), true);
    $t = (int)($st['last_run_started'] ?? 0);
    if ($t > 0 && ($sinceMtime === 0 || $t < $sinceMtime)) {
        $sinceMtime = $t;   // re-upload anything modified after the earliest previous run started
    }
}
$runStarted = time();

// ---- what is already in the bucket ---------------------------------------
$existing = [];  // key => size (only this shard's keys are kept)
if ($mode !== 'dry') {
    echo "listing bucket $bucket...";
    $n = 0;
    foreach ($s3->getPaginator('ListObjectsV2', ['Bucket' => $bucket]) as $page) {
        foreach ($page['Contents'] ?? [] as $o) {
            $n++;
            if ($shardN > 1 && !str_starts_with($o['Key'], 'urn:oid:')) {
                continue;
            }
            if ($shardN > 1 && ((int)substr($o['Key'], 8)) % $shardN !== $shardI) {
                continue;
            }
            $existing[$o['Key']] = (int)$o['Size'];
        }
    }
    echo " $n objects" . ($shardN > 1 ? ', ' . count($existing) . ' in this shard' : '') . "\n";
}

// ---- walk filecache -------------------------------------------------------
$in = implode(',', array_map('intval', array_keys($roots)));
$shardSql = $shardN > 1 ? "AND fileid % $shardN = $shardI" : '';
$stmt = $pdo->prepare(
    "SELECT fileid, storage, path, size, mtime, etag FROM {$prefix}filecache
     WHERE fileid > :last AND mimetype <> :dir AND storage IN ($in) $shardSql
     ORDER BY fileid LIMIT 5000"
);

$total = 0; $uploaded = 0; $skipped = 0; $missing = 0; $bytes = 0; $verifiedChanged = 0;
$missingList = []; $missingIds = [];
$last = 0;
while (true) {
    $stmt->execute([':last' => $last, ':dir' => $dirMime]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        break;
    }
    foreach ($rows as $r) {
        $last = (int)$r['fileid'];
        $total++;
        $local = $roots[$r['storage']] . ($r['path'] === '' ? '' : '/' . $r['path']);
        if (!is_file($local)) {
            $missing++;
            $missingIds[] = (int)$r['fileid'];
            if (count($missingList) < 50) {
                $missingList[] = "fileid {$r['fileid']}: $local";
            }
            continue;
        }
        if ($mode === 'dry') {
            continue;
        }
        $key = 'urn:oid:' . $r['fileid'];
        $size = filesize($local);
        // skip if already in bucket with the same size and not modified since the previous run started
        if (isset($existing[$key]) && $existing[$key] === $size
            && ($sinceMtime === 0 || (int)$r['mtime'] < $sinceMtime)) {
            if ($verifyEtag) {
                // mtime can lie (version restore keeps the old mtime): compare Nextcloud's etag stored on the object
                $head = $s3->headObject(['Bucket' => $bucket, 'Key' => $key]);
                if (($head['Metadata']['etag'] ?? null) === (string)$r['etag']) {
                    $skipped++;
                    continue;
                }
                $verifiedChanged++;
            } else {
                $skipped++;
                continue;
            }
        }
        $fh = fopen($local, 'rb');
        $s3->upload($bucket, $key, $fh, 'private', [
            'params' => ['Metadata' => ['mtime' => (string)$r['mtime'], 'etag' => (string)$r['etag']]],
            // S3 allows at most 10,000 parts: grow the part size for very large files (min 5 MB, default 16 MB threshold)
            'part_size' => max(5 * 1024 * 1024, (int)ceil($size / 9000)),
        ]);
        if (is_resource($fh)) {
            fclose($fh);
        }
        $uploaded++;
        $bytes += $size;
        if ($uploaded % 500 === 0) {
            $elapsed = max(1, time() - $runStarted);
            printf("  %d uploaded, %d skipped, %d missing, %.1f GB, %.1f MB/s\n",
                $uploaded, $skipped, $missing, $bytes / 1e9, $bytes / 1e6 / $elapsed);
        }
    }
}

printf("\n%s: %d files in filecache, %d uploaded (%.2f GB), %d already present, %d missing on disk%s\n",
    $mode, $total, $uploaded, $bytes / 1e9, $skipped, $missing,
    $verifyEtag ? ", $verifiedChanged re-uploaded after etag check" : '');
if ($missingList) {
    echo "missing on disk (first " . count($missingList) . "):\n  " . implode("\n  ", $missingList) . "\n";
}

if ($mode !== 'dry') {
    file_put_contents($stateFile, json_encode(['last_run_started' => $runStarted, 'mode' => $mode]));
}

// ---- finalize: rewrite storage ids --------------------------------------
if ($mode === 'finalize') {
    $prune = (bool)getenv('PRUNE_MISSING');
    if ($missing > 0 && !$prune && !getenv('FORCE')) {
        fwrite(STDERR, "\n$missing filecache entries have no file on disk; they would 404 after migration.\n"
            . "Re-run with PRUNE_MISSING=1 to delete those rows, or FORCE=1 to keep them.\n");
        exit(1);
    }
    $pdo->beginTransaction();
    if ($prune && $missingIds) {
        $pruned = 0;
        foreach (array_chunk($missingIds, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $pdo->prepare("DELETE FROM {$prefix}filecache_extended WHERE fileid IN ($ph)")->execute($chunk);
            $d = $pdo->prepare("DELETE FROM {$prefix}filecache WHERE fileid IN ($ph)");
            $d->execute($chunk);
            $pruned += $d->rowCount();
        }
        echo "pruned $pruned filecache rows with no file on disk\n";
    }
    $n1 = $pdo->exec("UPDATE {$prefix}storages SET id = 'object::user:' || substr(id, 7) WHERE id LIKE 'home::%'");
    $n2 = 0;
    if ($dataLocalId !== null) {
        $u = $pdo->prepare("UPDATE {$prefix}storages SET id = :new WHERE id = :old");
        $u->execute([':new' => 'object::store:amazon::' . $bucket, ':old' => $dataLocalId]);
        $n2 = $u->rowCount();
    }
    $pdo->commit();
    echo "oc_storages rewritten: $n1 home -> object::user, $n2 local -> object::store:amazon::$bucket\n";
    $others = array_diff($localIds, [$dataLocalId]);
    if ($others) {
        echo "NOTE: other local:: storages left untouched: " . implode(', ', $others) . "\n";
    }
    echo "\nDone. Now: pg_dump this database and restore it into the new cluster.\n";
}
