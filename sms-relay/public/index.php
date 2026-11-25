<?php
/** Front controller. Serve ONLY this directory; keep config and database private. */
ini_set('display_errors','0'); ini_set('log_errors','0');
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
try {
    require_once dirname(__DIR__).'/vendor/autoload.php';
    require_once dirname(__DIR__).'/auth.php'; require_once dirname(__DIR__).'/queue.php'; require_once dirname(__DIR__).'/verification.php';
    require_once dirname(__DIR__).'/api.php'; require_once dirname(__DIR__).'/webhook.php';
    $configPath=getenv('IEB_RELAY_CONFIG');
    if (!is_string($configPath) || !is_file($configPath) || str_starts_with((string)realpath($configPath),realpath(__DIR__).DIRECTORY_SEPARATOR)) throw new RuntimeException('Configuration unavailable');
    $c=json_decode(file_get_contents($configPath),true,16,JSON_THROW_ON_ERROR);
    if (!is_array($c) || !is_file($c['database']??'') || !preg_match('/\A[0-9a-f]{64}\z/D',$c['encryption_key']??'') || !is_array($c['installations']??null)) throw new RuntimeException('Configuration unavailable');
    $db=new PDO('sqlite:'.$c['database']); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $queue=new IebSmsQueue($db,hex2bin($c['encryption_key']));
    $uri=$_SERVER['REQUEST_URI']??''; $method=$_SERVER['REQUEST_METHOD']??'';
    if ($method!=='POST' || strpos($uri,'?')!==false || strlen($uri)>128) { http_response_code(404); exit; }
    $body=file_get_contents('php://input',false,null,0,65537); if ($body===false || strlen($body)>65536) { http_response_code(413); exit; }
    $type=strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]));
    if ($uri==='/v1/twilio') {
        if ($type!=='application/x-www-form-urlencoded' || !empty($_SERVER['HTTP_X_TWILIO_WEBHOOKS_SIGNATURE_SETTINGS'])) { http_response_code(400); exit; }
        // Parse every raw form field exactly once: PHP's $_POST silently collapses
        // duplicate names and rewrites some keys before signature validation.
        $params=[]; $pairs=explode('&',$body);
        if (count($pairs)>256) { http_response_code(400); exit; }
        foreach ($pairs as $pair) {
            if ($pair==='' || preg_match('/%(?![0-9a-fA-F]{2})/',$pair)) { http_response_code(400); exit; }
            $parts=explode('=',$pair,2); $name=urldecode($parts[0]); $value=urldecode($parts[1]??'');
            if ($name==='' || array_key_exists($name,$params)) { http_response_code(400); exit; }
            $params[$name]=$value;
        }
        $tw=$c['twilio']; $hook=new IebTwilioWebhook($queue,$tw['webhook_url'],$tw['auth_token'],$tw['account_sid'],$tw['sender']);
        [$status,$output]=$hook->handle($params,$_SERVER['HTTP_X_TWILIO_SIGNATURE']??''); header('Content-Type: application/xml; charset=utf-8');
    } else {
        if ($type!=='application/json') { http_response_code(415); exit; }
        $api=new IebRelayApi($db,$queue,$c['installations'],new IebSmsVerification($db,hex2bin($c['encryption_key'])));
        [$status,$response]=$api->handle($method,$uri,['installation'=>$_SERVER['HTTP_X_INSTALLATION']??'','timestamp'=>$_SERVER['HTTP_X_TIMESTAMP']??'','nonce'=>$_SERVER['HTTP_X_NONCE']??'','signature'=>$_SERVER['HTTP_X_SIGNATURE']??''],$body,time());
        header('Content-Type: application/json; charset=utf-8'); $output=json_encode($response,JSON_THROW_ON_ERROR);
    }
    http_response_code($status); echo $output;
} catch (Throwable $e) { http_response_code(503); header('Content-Type: application/json'); echo '{"error":"unavailable"}'; }
