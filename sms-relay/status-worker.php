<?php
/** CLI only; one bounded read-only provider status batch, never sends. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/maintenance-lock.php';
$maintenanceLock=ieb_maintenance_shared();
if (!$maintenanceLock) { fwrite(STDERR,"Maintenance gate unavailable\n"); exit(75); }
ini_set('display_errors','0'); ini_set('log_errors','0');
try {
    $path=getenv('IEB_RELAY_CONFIG');
    if (!is_string($path) || !is_file($path)) throw new RuntimeException();
    $c=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
    if (($c['status_poll_enabled']??false)!==true) { fwrite(STDERR,"Status polling disabled\n"); exit(2); }
    if (!is_file($c['database']??'')) throw new RuntimeException();
    $tw=$c['twilio']??[];
    if (!preg_match('/\AAC[0-9a-fA-F]{32}\z/D',$tw['account_sid']??'') || !is_string($tw['auth_token']??null) || strlen($tw['auth_token'])<8) throw new RuntimeException();
    require_once __DIR__.'/vendor/autoload.php'; require_once __DIR__.'/twilio.php'; require_once __DIR__.'/delivery.php';
    $db=new PDO('sqlite:'.$c['database']);
    $client=new Twilio\Rest\Client($tw['account_sid'],$tw['auth_token']);
    $client->setHttpClient(new Twilio\Http\CurlClient([CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]));
    $d=new IebSmsDelivery($db); echo 'lookups='.$d->poll(new IebTwilioStatus($client),time())."\n";
} catch (Throwable $e) { fwrite(STDERR,"Status poll unavailable\n"); exit(1); }
