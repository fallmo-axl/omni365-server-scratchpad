<?php
/**
 * Rewrite a copied config.php for the new (object-store, CNPG, Redis-cluster) deployment.
 *
 *   php fix-config.php /var/www/html/config/config.php
 *
 * Environment (all required except NEW_HOST):
 *   NEW_DBHOST   e.g. omni365-server-postgres-rw
 *   NEW_DBNAME   from secret omni365-server-postgres-app (key: dbname)
 *   NEW_DBUSER   from secret omni365-server-postgres-app (key: username)
 *   NEW_DBPASS   from secret omni365-server-postgres-app (key: password)
 *   NEW_HOST     new Route hostname; omit if the hostname does not change
 *
 * Keeps instanceid / passwordsalt / secret / datadirectory untouched.
 * Removes keys that the new deployment supplies from env/hook files
 * (redis-cluster.config.php, s3.config.php).
 */

$file = $argv[1] ?? '';
if (!is_file($file)) {
    fwrite(STDERR, "usage: fix-config.php /path/to/config.php\n");
    exit(2);
}
foreach (['NEW_DBHOST', 'NEW_DBNAME', 'NEW_DBUSER', 'NEW_DBPASS'] as $v) {
    if (!getenv($v)) {
        fwrite(STDERR, "$v is not set\n");
        exit(2);
    }
}

$CONFIG = [];
include $file;

$CONFIG['dbtype'] = 'pgsql';
$CONFIG['dbhost'] = getenv('NEW_DBHOST');
$CONFIG['dbname'] = getenv('NEW_DBNAME');
$CONFIG['dbuser'] = getenv('NEW_DBUSER');
$CONFIG['dbpassword'] = getenv('NEW_DBPASS');
$CONFIG['datadirectory'] = '/var/www/html/data';
$CONFIG['maintenance'] = false;

foreach (['memcache.local', 'memcache.distributed', 'memcache.locking', 'redis', 'redis.cluster', 'objectstore'] as $k) {
    unset($CONFIG[$k]);
}

if ($host = getenv('NEW_HOST')) {
    $CONFIG['trusted_domains'] = array_values(array_unique(array_merge($CONFIG['trusted_domains'] ?? [], [$host])));
    $CONFIG['overwrite.cli.url'] = 'https://' . $host;
    $CONFIG['overwritehost'] = $host;
    $CONFIG['overwriteprotocol'] = 'https';
}

copy($file, $file . '.pre-migration');
file_put_contents($file, "<?php\n\$CONFIG = " . var_export($CONFIG, true) . ";\n");
echo "written $file (backup: $file.pre-migration)\n";
