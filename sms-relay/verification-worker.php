<?php
/** Separate CLI worker. Both explicit switches are required; keep both disabled. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('log_errors','0');
try {
    $path=getenv('IEB_RELAY_CONFIG'); $c=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
    if (($c['live_send_enabled']??false)!==true || ($c['verification_send_enabled']??false)!==true) { fwrite(STDERR,"Sending disabled\n"); exit(2); }
    // Fail closed for verification sends too; pending actors may have lost access.
    // Deliberately not configurable. See README before removing this gate.
    fwrite(STDERR,"Sending disabled: authorization recheck unavailable\n"); exit(2);
    require_once __DIR__.'/vendor/autoload.php'; require_once __DIR__.'/verification.php'; require_once __DIR__.'/twilio.php';
    if (!is_file($c['database']??'') || !preg_match('/\A[0-9a-f]{64}\z/D',$c['encryption_key']??'')) throw new RuntimeException('Invalid config');
    $tw=$c['twilio']; if (!preg_match('/\AAC[0-9a-fA-F]{32}\z/D',$tw['account_sid']??'') || !is_string($tw['auth_token']??null) || strlen($tw['auth_token'])<8) throw new RuntimeException('Invalid config');
    $db=new PDO('sqlite:'.$c['database']); $v=new IebSmsVerification($db,hex2bin($c['encryption_key']));
    $client=new Twilio\Rest\Client($tw['account_sid'],$tw['auth_token']);
    $client->setHttpClient(new Twilio\Http\CurlClient([CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]));
    echo $v->dispatch(new IebTwilioAdapter($client,$tw['sender']),time())."\n";
} catch (Throwable $e) { fwrite(STDERR,"Worker unavailable\n"); exit(1); }
