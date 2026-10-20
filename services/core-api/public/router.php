<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SubsidiaryVault\{Application,Config};

umask(0077);
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
$assets=['/'=>['index.html','text/html; charset=utf-8'],'/app.js'=>['app.js','application/javascript; charset=utf-8'],'/style.css'=>['style.css','text/css; charset=utf-8'],'/locales.json'=>['locales.json','application/json; charset=utf-8']];
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET' && isset($assets[$path])) {
    [$file,$type]=$assets[$path];header('Content-Type: ' . $type);readfile(dirname(__DIR__,3) . '/apps/admin-web/' . $file);exit;
}
header('Content-Type: application/json; charset=utf-8');
try {
    $length=(int)($_SERVER['CONTENT_LENGTH']??0);
    if($length>12*1024*1024) { http_response_code(413);echo json_encode(['error'=>'request_too_large']);exit; }
    $body=[];
    if(in_array($_SERVER['REQUEST_METHOD'],['POST','PUT','PATCH','DELETE'],true)) {
        $stream=fopen('php://input','rb');$raw=stream_get_contents($stream,12*1024*1024+1);fclose($stream);
        if(strlen($raw)>12*1024*1024) { http_response_code(413);echo json_encode(['error'=>'request_too_large']);exit; }
        if($raw!=='') {
            if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json')) { http_response_code(415);echo json_encode(['error'=>'json_required']);exit; }
            $decoded=json_decode($raw,false,32,JSON_THROW_ON_ERROR);
            if(!$decoded instanceof stdClass) { throw new JsonException('object_required'); }
            $body=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        }
    }
    if(!is_string($path) || str_contains($path,'%') || str_contains($path,'..') || str_contains($path,"\0")) { http_response_code(400);echo json_encode(['error'=>'invalid_path']);exit; }
    $app=new Application(new Config());
    [$status,$result]=$app->handle($_SERVER['REQUEST_METHOD'],$path,$_SERVER['HTTP_AUTHORIZATION']??'',$body,$_GET);
    http_response_code($status);echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch(JsonException) { http_response_code(400);echo json_encode(['error'=>'invalid_json_object']); }
catch(Throwable) { http_response_code(503);echo json_encode(['error'=>'service_unavailable']); }
