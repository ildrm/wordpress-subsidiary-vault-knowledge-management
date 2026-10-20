<?php
declare(strict_types=1);
require dirname(__DIR__) . '/services/core-api/src/bootstrap.php';
use SubsidiaryVault\{Application,Config,Database};
umask(0077);
$app=new Application(new Config());$db=$app->db;$delivered=0;$suppressed=0;
$db->begin();
try {
    $events=$db->all('SELECT * FROM outbox WHERE delivered_at IS NULL ORDER BY created_at,id LIMIT 100');
    foreach($events as $event){
        $doc=$db->one("SELECT * FROM documents WHERE tenant_id=? AND id=? AND state!='deleted'",[$event['tenant_id'],$event['resource_id']]);
        if($doc){
            foreach($db->all('SELECT m.user_id,u.status FROM memberships m JOIN users u ON u.id=m.user_id WHERE m.tenant_id=?',[$event['tenant_id']]) as $member){
                if($member['status']!=='active' || $app->policy->reason(['id'=>$member['user_id'],'mfa'=>false],$event['tenant_id'],'view',$doc)!==null){$suppressed++;continue;}
                $notification=hash('sha256',$event['id'] . ':' . $member['user_id']);
                $db->query('INSERT OR IGNORE INTO notifications(tenant_id,user_id,id,event,resource_id,created_at) VALUES(?,?,?,?,?,?)',[$event['tenant_id'],$member['user_id'],$notification,$event['event'],$event['resource_id'],time()]);$delivered++;
            }
        }
        $db->query('UPDATE outbox SET delivered_at=? WHERE id=?',[time(),$event['id']]);
    }
    $db->commit();
}catch(Throwable $e){$db->rollback();throw $e;}
echo json_encode(['events'=>count($events),'local_notifications'=>$delivered,'unauthorized_recipients_suppressed'=>$suppressed]),"\n";
