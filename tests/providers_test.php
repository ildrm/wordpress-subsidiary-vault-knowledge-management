<?php
declare(strict_types=1);
require dirname(__DIR__).'/services/core-api/src/bootstrap.php';
use SubsidiaryVault\{Application,Config,Crypto,Database,FileScanner,HttpClient,KeyService,Secrets};
$app=new Application(new Config());$checks=0;$file=$app->config->dataDir.'/provider-doc.json';
function ok(bool $value,string $reason):void{global $checks;$checks++;if(!$value){throw new RuntimeException($reason);}}
$tokens=json_decode(file_get_contents($app->config->dataDir.'/demo-credentials.json'),true)['tokens'];$auth='Bearer '.$tokens['tenant-01-custodian'];
function api(string $method,string $path,array $body=[],int $expected=200):array{global $app,$auth;[$s,$r]=$app->handle($method,$path,$auth,$body);ok($s===$expected,'provider API '.$path.' '.json_encode($r));return $r;}
if(($argv[1]??'')==='verify-rotation'){
 $doc=json_decode(file_get_contents($file),true);$r=api('GET','/v1/tenants/tenant-01/documents/'.$doc['id'].'/versions/1');ok(hash('sha256',base64_decode($r['content_base64']))===$doc['digest'],'old version decrypts after external root rotation');echo "Live root rotation: $checks assertions passed.\n";exit;
}
$bucket=getenv('SV_S3_BUCKET');$url=getenv('SV_S3_ENDPOINT').'/'.$bucket;$h=curl_init($url);
curl_setopt_array($h,[CURLOPT_CUSTOMREQUEST=>'PUT',CURLOPT_AWS_SIGV4=>'aws:amz:'.getenv('SV_S3_REGION').':s3',CURLOPT_USERPWD=>getenv('SV_S3_ACCESS_KEY').':'.getenv('SV_S3_SECRET_KEY'),CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['x-amz-content-sha256: '.hash('sha256','')],CURLOPT_TIMEOUT=>15,CURLOPT_PROXY=>'']);curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);ok(in_array($status,[200,204],true),'create private S3 test bucket');
$app->crypto->provision('tenant-01');
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jDxoAAAAASUVORK5CYII=');
$doc=api('POST','/v1/tenants/tenant-01/documents',['title'=>'Synthetic scanned PNG','classification'=>1,'content_base64'=>base64_encode($png)]);$p='/v1/tenants/tenant-01/documents/'.$doc['id'];
$r=api('GET',$p.'/versions/1');ok($r['mime']==='image/png'&&base64_decode($r['content_base64'])===$png,'real scanned binary upload roundtrip');
$app->db->begin();$app->db->context('tenant-01-custodian','tenant-01');$v=$app->db->one('SELECT * FROM versions WHERE tenant_id=? AND document_id=?',['tenant-01',$doc['id']]);$app->db->commit();
$envelope=json_decode($v['envelope'],true);ok($envelope['provider']==='openbao-transit'&&str_starts_with($envelope['wrapped'],'vault:v'),'independent key custody envelope');
ok(!file_exists($app->config->dataDir.'/objects/'.$v['object_id'].'.bin'),'object is remote, not silently written locally');
$cipher=$app->objects->get('tenant-01',$v['object_id']);ok(!str_contains($cipher,$png),'S3 stores encrypted bytes');
try{$app->objects->put('tenant-01',$v['object_id'],'overwrite');ok(false,'S3 conditional overwrite accepted');}catch(SubsidiaryVault\ApiError $e){ok($e->reason==='immutable_object_exists','S3 immutable conditional create');}
try{$app->objects->get('tenant-02',$v['object_id']);ok(false,'foreign S3 partition accepted');}catch(SubsidiaryVault\ApiError){ok(true,'S3 partition binding');}
$eicar='X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
api('POST','/v1/tenants/tenant-01/documents',['title'=>'Malware synthetic test','content_base64'=>base64_encode($eicar)],422);
$address=getenv('SV_CLAMD_ADDRESS');putenv('SV_CLAMD_ADDRESS=tcp://127.0.0.1:1');api('POST','/v1/tenants/tenant-01/documents',['title'=>'Scanner outage','content_base64'=>base64_encode('Synthetic text')],503);putenv('SV_CLAMD_ADDRESS='.$address);
$keyUrl=getenv('SV_KEY_SERVICE_URL');putenv('SV_KEY_SERVICE_URL=http://127.0.0.1:1');api('GET',$p.'/versions/1',[],503);putenv('SV_KEY_SERVICE_URL='.$keyUrl);
$s3Url=getenv('SV_S3_ENDPOINT');putenv('SV_S3_ENDPOINT=http://127.0.0.1:1');api('GET',$p.'/versions/1',[],503);putenv('SV_S3_ENDPOINT='.$s3Url);
$key=KeyService::name('tenant-01');[$status]=HttpClient::request('POST',$keyUrl.'/v1/transit/keys/'.$key.'/rotate',['X-Vault-Token: '.Secrets::required('SV_KEY_SERVICE_TOKEN'),'Content-Type: application/json'],'{}');ok($status===403,'application key token cannot rotate root policy');
[$status]=HttpClient::request('GET',$keyUrl.'/v1/transit/export/encryption-key/'.$key,['X-Vault-Token: '.Secrets::required('SV_KEY_SERVICE_TOKEN')]);ok($status===403,'application cannot export root key');
$r=api('GET',$p.'/versions/1');ok(base64_decode($r['content_base64'])===$png,'provider outages do not corrupt committed content');
file_put_contents($file,json_encode(['id'=>$doc['id'],'digest'=>hash('sha256',$png)]));chmod($file,0600);
echo "Live external keys/S3/ClamAV: $checks assertions passed.\n";
