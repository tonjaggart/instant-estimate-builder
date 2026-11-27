<?php
// Internal helper: invoked synchronously by unicode-rollout.py under its flock.
if (PHP_SAPI !== 'cli' || $argc !== 7) exit(64);
try {
    [$script, $source, $database, $config, $action, $installation, $cap] = $argv;
    require $source.'/queue.php';
    $c=json_decode(file_get_contents($config), true, 16, JSON_THROW_ON_ERROR);
    if (($c['database']??null)!==$database || !preg_match('/\A[0-9a-f]{64}\z/D',$c['encryption_key']??'')) throw new RuntimeException('Invalid fixture config');
    $q=new IebSmsQueue(new PDO('sqlite:'.$database),hex2bin($c['encryption_key']));
    if ($action==='migrate') $q->migrateSegmentSchema();
    elseif ($action==='cap') $q->configureSegmentCap($installation,(int)$cap);
    else throw new RuntimeException('Invalid action');
} catch (Throwable $e) { fwrite(STDERR,"Migration helper failed: ".$e->getMessage()."\n"); exit(1); }
