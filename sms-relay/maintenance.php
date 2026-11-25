<?php
/** One bounded maintenance pass; no provider SDK or sending credentials required. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('log_errors','0');
try {
    $path=getenv('IEB_RELAY_CONFIG');
    if (!is_string($path) || !is_file($path)) throw new RuntimeException('Invalid config');
    $c=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
    if (!is_file($c['database']??'') || !preg_match('/\A[0-9a-f]{64}\z/D',$c['encryption_key']??'')) throw new RuntimeException('Invalid config');
    require_once __DIR__.'/queue.php';
    $db=new PDO('sqlite:'.$c['database']);
    (new IebSmsQueue($db,hex2bin($c['encryption_key'])))->cleanup(time());
    echo "Maintenance complete\n";
} catch (Throwable $e) { fwrite(STDERR,"Maintenance unavailable\n"); exit(1); }
