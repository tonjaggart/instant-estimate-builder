<?php
// TEST FIXTURE ONLY. Not an authenticated enrollment flow, webhook or production service.
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(403); exit; }
require_once __DIR__.'/../sms-relay/auth.php';
require_once __DIR__.'/../sms-relay/queue.php';
header('Content-Type: application/json');
function fixture_reply($code,$data) { http_response_code($code); echo json_encode($data); exit; }
try {
    $db=new PDO('sqlite:'.getenv('IEB_TEST_DB'));
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout=5000');
    ieb_auth_install_schema($db);
    $q=new IebSmsQueue($db,hex2bin(getenv('IEB_TEST_CRYPT'))); $q->install();
    $db->exec("INSERT OR IGNORE INTO ieb_sms_installations VALUES ('install_123',2,1)");
    $path=$_SERVER['REQUEST_URI'] ?? ''; $body=file_get_contents('php://input');
    if (($_SERVER['REQUEST_METHOD']??'') !== 'POST' || !in_array($path,['/consent','/events'],true)) fixture_reply(404,['error'=>'not found']);
    $id=$_SERVER['HTTP_X_INSTALLATION'] ?? '';
    if ($id!=='install_123' || !ieb_auth_verify($db,getenv('IEB_TEST_KEY'),$id,'POST',$path,$_SERVER['HTTP_X_TIMESTAMP']??'',$_SERVER['HTTP_X_NONCE']??'',$body,$_SERVER['HTTP_X_SIGNATURE']??'',time())) fixture_reply(401,['error'=>'unauthorized']);
    $data=json_decode($body,true,32,JSON_THROW_ON_ERROR);
    if ($path==='/consent') { $q->consent($id,$data['phone']??'', $data['actor']??'', $data['notice']??'',time()); fixture_reply(200,['result'=>'ok']); }
    $result=$q->enqueue($id,$data['event']??'', $data['phone']??'', $data['lead']??[],time());
    fixture_reply(200,['result'=>$result]);
} catch (DomainException $e) { fixture_reply(409,['error'=>'rejected']); }
catch (Throwable $e) { fixture_reply(400,['error'=>'request failed']); }
