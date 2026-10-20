<?php
declare(strict_types=1);
namespace SubsidiaryVault;

final class Application
{
    public readonly Database $db;
    public readonly Policy $policy;
    public readonly Crypto $crypto;
    public readonly Audit $audit;
    public readonly ObjectStore $objects;
    private ?array $actor = null;
    private ?string $tenant = null;
    private string $requestId = '';
    private array $newObjects = [];

    public function __construct(public readonly Config $config)
    {
        $this->db = new Database($config->database);
        $this->policy = new Policy($this->db);
        $this->crypto = new Crypto($config->keyDir);
        $this->audit = new Audit($this->db);
        $this->objects = new ObjectStore($config);
    }

    /** Shared HTTP/CLI test boundary; every domain route uses the same policy engine. */
    public function handle(string $method, string $path, string $authorization = '', array $body = [], array $query = []): array
    {
        $this->actor = null; $this->tenant = null; $this->newObjects = []; $this->requestId = Database::id();
        if ($method === 'GET' && $path === '/healthz') { return [200, ['status'=>'ok','profile'=>$this->config->environment,'version'=>Version::CURRENT,'mode'=>$this->config->managedMode(),'release_approved'=>false]]; }
        if ($method === 'GET' && $path === '/v1/auth/config') {
            try { return [200,[...($this->config->authMode==='oidc-introspection'?(new Oidc($this->db,$this->config))->configuration():['enabled'=>false]),'upload_profile'=>getenv('SV_UPLOAD_PROFILE')?:'text','max_file_bytes'=>Config::MAX_FILE_BYTES,'mode'=>$this->config->managedMode()]]; }
            catch (\Throwable) { return [503,['error'=>'identity_configuration_unavailable']]; }
        }
        try {
            $this->db->begin();
            $this->actor = (new Auth($this->db,$this->config))->authenticate($authorization);
            $this->db->context($this->actor['id']);
            $parts = explode('/', trim($path,'/'));
            if (count($parts)>=3 && $parts[0]==='v1' && $parts[1]==='tenants') {
                $this->tenant = self::id($parts[2]);
                $this->db->context($this->actor['id'],$this->tenant);
                $this->policy->membership($this->actor,$this->tenant);
                $this->rateLimit();
            }
            $result = $this->route($method,$parts,$body,$query);
            if ($this->tenant) {
                $this->audit->append($this->tenant,$this->actor['id'],'request.allowed','','allowed','policy_allowed',$this->requestId);
            }
            $this->db->commit();
            return [200,$result];
        } catch (\Throwable $e) {
            $this->db->rollback();
            foreach ($this->newObjects as $object) { $this->objects->rollbackLocal($object); }
            $error = $e instanceof ApiError ? $e : new ApiError(500,'internal_error');
            // No request bodies, tokens, titles, file data or key material in logs.
            if (!$e instanceof ApiError) { error_log('svkms_failure request=' . $this->requestId . ' class=' . get_class($e)); }
            if ($this->actor && $this->tenant && $this->db->one('SELECT id FROM tenants WHERE id=?',[$this->tenant])) {
                try {
                    $this->db->begin();
                    $this->db->context($this->actor['id'],$this->tenant);
                    $this->countRequest();
                    $this->audit->append($this->tenant,$this->actor['id'],'request.denied','', 'denied',$error->reason,$this->requestId);
                    $this->db->commit();
                } catch (\Throwable) { $this->db->rollback(); return [503,['error'=>'audit_unavailable','request_id'=>$this->requestId]]; }
            }
            return [$error->status,['error'=>$error->reason,'request_id'=>$this->requestId]];
        }
    }

    private function route(string $method, array $p, array $b, array $q): array
    {
        if ($p === ['v1','me'] && $method==='GET') {
            $u=$this->db->one('SELECT id,name,locale FROM users WHERE id=?',[$this->actor['id']]);
            $u['mfa']=$this->actor['mfa'];
            $u['tenants']=$this->db->all("SELECT t.id,t.name,t.status,m.role_id FROM tenants t JOIN memberships m ON m.tenant_id=t.id WHERE m.user_id=? AND m.status='active' AND (m.expires_at IS NULL OR m.expires_at>?) ORDER BY t.name",[$this->actor['id'],time()]);
            return $u;
        }
        if ($p === ['v1','session'] && $method==='DELETE') {
            if (isset($this->actor['external_hash'])) {
                $this->db->query('INSERT INTO revoked_access_tokens(hash,expires_at) VALUES(?,?) ON CONFLICT DO NOTHING',[$this->actor['external_hash'],$this->actor['expires_at']]);
                return ['revoked'=>true,'provider_refresh_revocation_required'=>true];
            }
            if (!$this->actor['credential']) { throw new ApiError(409,'revoke_at_identity_provider'); }
            $this->db->query('UPDATE credentials SET revoked=1 WHERE hash=?',[$this->actor['credential']]);
            return ['revoked'=>true];
        }
        if ($p === ['v1','posture'] && $method==='GET') {
            $m=$this->policy->membership($this->actor,'holding');
            if (!in_array('posture',$m['capabilities'],true)) { throw new ApiError(403,'capability_denied'); }
            return ['tenants'=>$this->db->all($this->db->driver==='pgsql' ? 'SELECT * FROM svkms_posture()' : "SELECT t.id,t.name,t.status,COUNT(d.id) AS documents,COALESCE(SUM(v.bytes),0) AS bytes FROM tenants t LEFT JOIN documents d ON d.tenant_id=t.id AND d.state!='deleted' LEFT JOIN versions v ON v.tenant_id=d.tenant_id AND v.document_id=d.id AND v.number=d.current_version WHERE t.id!='holding' GROUP BY t.id ORDER BY t.id")];
        }
        if ($p === ['v1','tenants'] && $method==='POST') { return $this->provision($b); }
        if (count($p)===4 && $p[0]==='v1' && $p[1]==='governance' && $p[2]==='tenants' && $method==='PATCH') {
            $this->governance(); self::keys($b,['status']); $target=self::id($p[3]); $status=$b['status']??'';
            $this->db->context($this->actor['id'],$target);
            if (!in_array($status,['active','suspended'],true)) { throw new ApiError(409,'closure_review_required'); }
            $tenant=$this->db->one('SELECT id,status FROM tenants WHERE id=?',[$target]);
            if (!$tenant || $target==='holding' || $tenant['status']==='closed') { throw new ApiError(404,'tenant_not_available'); }
            $this->db->query('UPDATE tenants SET status=? WHERE id=?',[$status,$target]);
            $this->tenant=$target; $this->event('tenant.' . $status,$target); return ['id'=>$target,'status'=>$status];
        }
        if (count($p)<4 || $p[0]!=='v1' || $p[1]!=='tenants' || !$this->tenant) { throw new ApiError(404,'route_not_found'); }
        $t=$this->tenant; $area=$p[3]; $id=isset($p[4]) ? self::id($p[4]) : null; $sub=$p[5]??null;
        if (count($p)>7) { throw new ApiError(404,'route_not_found'); }
        if ($area==='settings' && count($p)===4) {
            $this->permit('settings');
            if ($method==='GET') { return $this->tenantSettings(); }
            if ($method==='PATCH') {
                self::keys($b,['name','locale','quota_bytes']);
                $current=$this->tenantSettings();
                $name=isset($b['name']) ? self::text($b,'name',120) : $current['name'];
                $locale=$b['locale']??$current['locale']; self::locale($locale);
                $quota=$b['quota_bytes']??$current['quota_bytes'];
                if (!is_int($quota) || $quota<1048576 || $quota>1073741824) { throw new ApiError(422,'invalid_quota'); }
                $this->db->query('UPDATE tenants SET name=?,settings=?,quota_bytes=? WHERE id=?',[$name,json_encode(['locale'=>$locale]),$quota,$t]);
                $this->event('settings.updated',$t);
                return $this->tenantSettings();
            }
        }
        if ($area==='roles' && count($p)<=5) {
            $this->permit('roles');
            if ($method==='GET') { return ['items'=>$this->db->all('SELECT id,name,capabilities,version FROM roles WHERE tenant_id=? ORDER BY name',[$t])]; }
            if ($method==='POST' && !$id) {
                self::keys($b,['name','capabilities']); $caps=$b['capabilities']??[];
                if (!is_array($caps) || !array_is_list($caps) || array_diff($caps,Policy::CAPABILITIES)) { throw new ApiError(422,'invalid_capabilities'); }
                $own=$this->policy->membership($this->actor,$t);
                if (array_diff($caps,$own['capabilities'])) { throw new ApiError(403,'privilege_amplification'); }
                $id=Database::id();
                $this->db->query('INSERT INTO roles(tenant_id,id,name,capabilities) VALUES(?,?,?,?)',[$t,$id,self::text($b,'name',120),json_encode(array_values(array_unique($caps)))]);
                $this->event('role.created',$id); return ['id'=>$id];
            }
        }
        if ($area==='members' && count($p)<=5) {
            $this->permit('members');
            if ($method==='GET') { return ['items'=>$this->db->all('SELECT m.user_id,u.name,m.role_id,m.status,m.clearance,m.expires_at FROM memberships m JOIN users u ON u.id=m.user_id WHERE m.tenant_id=? ORDER BY u.name',[$t])]; }
            if ($method==='PATCH' && $id) {
                self::keys($b,['status','role_id','clearance','expires_at']);
                if ($id===$this->actor['id']) { throw new ApiError(403,'self_membership_change_denied'); }
                $member=$this->db->one('SELECT * FROM memberships WHERE tenant_id=? AND user_id=?',[$t,$id]);
                if (!$member) { throw new ApiError(404,'member_not_found'); }
                $status=$b['status']??$member['status']; $role=$b['role_id']??$member['role_id']; $clearance=$b['clearance']??$member['clearance']; $expires=$b['expires_at']??$member['expires_at'];
                if (!in_array($status,['active','suspended'],true) || !is_int($clearance) || $clearance<0 || $clearance>3 || ($expires!==null && (!is_int($expires) || $expires<=time()))) { throw new ApiError(422,'invalid_membership'); }
                $target=$this->db->one('SELECT capabilities FROM roles WHERE tenant_id=? AND id=?',[$t,self::id($role)]);
                $own=$this->policy->membership($this->actor,$t);
                if (!$target || array_diff(json_decode($target['capabilities'],true),$own['capabilities']) || $clearance>$own['clearance']) { throw new ApiError(403,'privilege_amplification'); }
                $this->db->query('UPDATE memberships SET role_id=?,status=?,clearance=?,expires_at=? WHERE tenant_id=? AND user_id=?',[$role,$status,$clearance,$expires,$t,$id]);
                $this->event('membership.updated',$id); return ['updated'=>true];
            }
        }
        if (in_array($area,['groups','departments'],true) && count($p)<=6) {
            $this->permit('groups');
            if ($method==='GET' && !$id) { return ['items'=>$this->db->all('SELECT * FROM ' . $area . ' WHERE tenant_id=? ORDER BY name',[$t])]; }
            if ($method==='POST' && !$id) {
                self::keys($b,$area==='groups'?['name']:['name','parent_id']); $id=Database::id(); $name=self::text($b,'name',120);
                if ($area==='groups') { $this->db->query('INSERT INTO groups(tenant_id,id,name) VALUES(?,?,?)',[$t,$id,$name]); }
                else {
                    $parent=$b['parent_id']??null;
                    if ($parent && !$this->db->one('SELECT id FROM departments WHERE tenant_id=? AND id=?',[$t,self::id($parent)])) { throw new ApiError(422,'invalid_parent'); }
                    $this->db->query('INSERT INTO departments(tenant_id,id,name,parent_id) VALUES(?,?,?,?)',[$t,$id,$name,$parent]);
                }
                $this->event($area . '.created',$id); return ['id'=>$id];
            }
            if ($area==='groups' && $id && $sub==='members' && in_array($method,['POST','DELETE'],true)) {
                self::keys($b,['user_id']); $user=self::id($b['user_id']??'');
                if (!$this->db->one('SELECT id FROM groups WHERE tenant_id=? AND id=?',[$t,$id]) || !$this->db->one("SELECT user_id FROM memberships WHERE tenant_id=? AND user_id=? AND status='active'",[$t,$user])) { throw new ApiError(422,'invalid_group_membership'); }
                // A manager cannot add themselves to a group that already confers document entitlements.
                if ($method==='POST' && $user===$this->actor['id']) { throw new ApiError(403,'self_grant_denied'); }
                if ($method==='POST') { $this->db->query('INSERT INTO group_members(tenant_id,group_id,user_id) VALUES(?,?,?) ON CONFLICT DO NOTHING',[$t,$id,$user]); }
                else { $this->db->query('DELETE FROM group_members WHERE tenant_id=? AND group_id=? AND user_id=?',[$t,$id,$user]); }
                $this->event('group.membership.updated',$id); return ['updated'=>true];
            }
        }
        if ($area==='audit' && count($p)===4 && $method==='GET') {
            $this->permit('audit');
            return ['chain_valid'=>$this->audit->verify($t),'items'=>$this->db->all('SELECT sequence,actor_id,action,resource_id,outcome,reason,at,request_id,previous_hash,hash FROM audit WHERE tenant_id=? AND sequence>? ORDER BY sequence LIMIT 100',[$t,self::nonnegative($q['after']??0)])];
        }
        if ($area==='notifications' && count($p)<=5) {
            if ($method==='GET' && !$id) {
                $items=$this->db->all('SELECT * FROM notifications WHERE tenant_id=? AND user_id=? ORDER BY created_at DESC LIMIT 100',[$t,$this->actor['id']]);
                return ['items'=>array_values(array_filter($items,fn($n)=>$this->visible($n['resource_id'])))];
            }
            if ($method==='PATCH' && $id) { $this->db->query('UPDATE notifications SET read_at=? WHERE tenant_id=? AND user_id=? AND id=?',[time(),$t,$this->actor['id'],$id]); return ['updated'=>true]; }
        }
        if ($area==='documents' || $area==='knowledge') { return $this->documents($method,$p,$b,$q,$area==='knowledge'?'article':'document'); }
        throw new ApiError(404,'route_not_found');
    }

    private function documents(string $method, array $p, array $b, array $q, string $kind): array
    {
        $t=$this->tenant; $id=isset($p[4])?self::id($p[4]):null; $sub=$p[5]??null;
        if (!$id && count($p)===4) {
            if ($method==='GET') {
                $this->permit('view');
                $search=$q['q']??'';
                if (!is_string($search) || mb_strlen($search)>200) { throw new ApiError(422,'invalid_search'); }
                $after=self::nonnegative($q['after']??0);
                $rows=$this->db->all("SELECT * FROM documents WHERE tenant_id=? AND kind=? AND state!='deleted' ORDER BY created_at,id",[$t,$kind]);
                $rows=array_values(array_filter($rows,fn($doc)=>$this->policy->reason($this->actor,$t,'view',$doc)===null && ($search==='' || mb_stripos($doc['title'] . ' ' . $doc['metadata'],$search)!==false)));
                return ['items'=>array_map([$this,'present'],array_slice($rows,$after,50)),'next_offset'=>count($rows)>$after+50?$after+50:null];
            }
            if ($method==='POST') {
                $this->permit('create'); self::keys($b,['title','classification','metadata','content_base64','mode']);
                if (($b['mode']??$this->config->managedMode())!==$this->config->managedMode()) { throw new ApiError(409,'security_mode_unavailable'); }
                $m=$this->policy->membership($this->actor,$t); $classification=$b['classification']??0;
                if (!is_int($classification) || $classification<0 || $classification>3 || $classification>$m['clearance']) { throw new ApiError(422,'invalid_classification'); }
                $id=Database::id(); $now=time(); $title=self::text($b,'title',240); $metadata=self::metadata($b['metadata']??[]);
                $this->db->query('INSERT INTO documents(tenant_id,id,title,kind,owner_id,classification,metadata,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)',[$t,$id,$title,$kind,$this->actor['id'],$classification,$metadata,$now,$now]);
                foreach (Policy::DOCUMENT_ACTIONS as $action) {
                    if (in_array($action,$m['capabilities'],true)) { $this->db->query('INSERT INTO acl(tenant_id,document_id,subject_type,subject_id,action,effect) VALUES(?,?,?,?,?,?)',[$t,$id,'user',$this->actor['id'],$action,'allow']); }
                }
                $doc=$this->document($id,$kind);
                $this->addVersion($doc,$b);
                $this->event('document.created',$id); return $this->present($this->document($id,$kind));
            }
        }
        if (!$id) { throw new ApiError(404,'route_not_found'); }
        $doc=$this->document($id,$kind);
        if (count($p)===5) {
            if ($method==='GET') { $this->permit('view',$doc); $this->event('document.viewed',$id); return $this->present($doc); }
            if ($method==='PATCH') {
                $this->permit('edit',$doc); self::keys($b,['title','metadata','expected_version','expected_metadata_revision']); $this->editable($doc); $this->expected($doc,$b);
                if (!is_int($b['expected_metadata_revision']??null) || $b['expected_metadata_revision']!==$doc['metadata_revision']) { throw new ApiError(409,'metadata_revision_conflict'); }
                $title=isset($b['title'])?self::text($b,'title',240):$doc['title']; $metadata=isset($b['metadata'])?self::metadata($b['metadata']):$doc['metadata'];
                $this->db->query('UPDATE documents SET title=?,metadata=?,metadata_revision=metadata_revision+1,updated_at=? WHERE tenant_id=? AND id=?',[$title,$metadata,time(),$t,$id]);
                $this->event('metadata.updated',$id); return $this->present($this->document($id,$kind));
            }
            if ($method==='DELETE') {
                $this->permit('delete',$doc);
                $disposition=$this->db->one('SELECT * FROM dispositions WHERE tenant_id=? AND document_id=? AND version=? AND approved_by IS NOT NULL ORDER BY created_at DESC LIMIT 1',[$t,$id,$doc['current_version']]);
                if (!$disposition) { throw new ApiError(409,'disposition_approval_required'); }
                $this->db->query("UPDATE documents SET state='deleted',updated_at=? WHERE tenant_id=? AND id=?",[time(),$t,$id]);
                $this->event('document.deleted',$id); return ['deleted'=>true,'physical_purge'=>false];
            }
        }
        if ($sub==='versions' && count($p)<=7) {
            if ($method==='POST' && count($p)===6) {
                self::keys($b,['content_base64','expected_version']); $this->permit('edit',$doc); $this->editable($doc); $this->expected($doc,$b); $this->addVersion($doc,$b);
                $this->event('version.created',$id); return $this->present($this->document($id,$kind));
            }
            if ($method==='GET' && count($p)===6) {
                $this->permit('view',$doc); return ['items'=>$this->db->all('SELECT number,bytes,digest,mime,author_id,created_at FROM versions WHERE tenant_id=? AND document_id=? ORDER BY number DESC',[$t,$id])];
            }
            if ($method==='GET' && count($p)===7) {
                $this->permit('download',$doc); $version=self::positive($p[6]);
                $v=$this->db->one('SELECT * FROM versions WHERE tenant_id=? AND document_id=? AND number=?',[$t,$id,$version]);
                if (!$v) { throw new ApiError(404,'version_not_found'); }
                $cipher=$this->objects->get($t,$v['object_id']);
                $plain=$this->crypto->decrypt($t,Crypto::context($t,$id,$version,(int)$doc['classification']),$cipher,$v['envelope']);
                if (!hash_equals($v['digest'],hash('sha256',$plain))) { throw new ApiError(503,'integrity_check_failed'); }
                $result=['content_base64'=>base64_encode($plain),'mime'=>$v['mime'],'version'=>$version,'digest'=>$v['digest']]; sodium_memzero($plain);
                $this->event('version.downloaded',$id); return $result;
            }
        }
        if ($sub==='permissions' && count($p)===6) {
            $this->permit('share',$doc);
            if ($method==='GET') { return ['items'=>$this->db->all('SELECT subject_type,subject_id,action,effect,expires_at FROM acl WHERE tenant_id=? AND document_id=?',[$t,$id])]; }
            if ($method==='PUT' || $method==='DELETE') {
                self::keys($b,['subject_type','subject_id','action','effect','expires_at']); $type=$b['subject_type']??''; $subject=self::id($b['subject_id']??''); $action=$b['action']??''; $effect=$b['effect']??'';
                if (!in_array($type,['user','group','role'],true) || !in_array($action,Policy::DOCUMENT_ACTIONS,true) || !in_array($effect,['allow','deny'],true)) { throw new ApiError(422,'invalid_acl'); }
                $this->permit($action,$doc);
                if ($type==='user' && $subject===$this->actor['id']) { throw new ApiError(403,'self_grant_denied'); }
                $own=$this->policy->membership($this->actor,$t);
                if (($type==='role' && $subject===$own['role_id']) || ($type==='group' && $this->db->one('SELECT user_id FROM group_members WHERE tenant_id=? AND group_id=? AND user_id=?',[$t,$subject,$this->actor['id']]))) { throw new ApiError(403,'self_grant_denied'); }
                $table=match($type){'user'=>'memberships','role'=>'roles','group'=>'groups'}; $column=$type==='user'?'user_id':'id';
                if (!$this->db->one("SELECT $column FROM $table WHERE tenant_id=? AND $column=?",[$t,$subject])) { throw new ApiError(422,'unknown_subject'); }
                $expires=$b['expires_at']??null;
                if ($expires!==null && (!is_int($expires) || $expires<=time())) { throw new ApiError(422,'invalid_expiry'); }
                if ($method==='DELETE') { $this->db->query('DELETE FROM acl WHERE tenant_id=? AND document_id=? AND subject_type=? AND subject_id=? AND action=?',[$t,$id,$type,$subject,$action]); }
                else { $this->db->query('INSERT INTO acl(tenant_id,document_id,subject_type,subject_id,action,effect,expires_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(tenant_id,document_id,subject_type,subject_id,action) DO UPDATE SET effect=excluded.effect,expires_at=excluded.expires_at',[$t,$id,$type,$subject,$action,$effect,$expires]); }
                $this->event('acl.updated',$id); return ['updated'=>true];
            }
        }
        if ($sub==='holds' && count($p)<=7) {
            $this->permit('hold',$doc);
            if ($method==='GET' && count($p)===6) { return ['items'=>$this->db->all('SELECT * FROM holds WHERE tenant_id=? AND document_id=?',[$t,$id])]; }
            if ($method==='POST' && count($p)===6) {
                self::keys($b,['reason']); $hold=Database::id(); $this->db->query('INSERT INTO holds(tenant_id,document_id,id,reason,created_by,created_at) VALUES(?,?,?,?,?,?)',[$t,$id,$hold,self::text($b,'reason',1000),$this->actor['id'],time()]);
                $this->event('hold.created',$id); return ['id'=>$hold];
            }
            if ($method==='DELETE' && count($p)===7) {
                $hold=$this->db->one('SELECT * FROM holds WHERE tenant_id=? AND document_id=? AND id=? AND released_at IS NULL',[$t,$id,self::id($p[6])]);
                if (!$hold) { throw new ApiError(404,'hold_not_found'); }
                if ($hold['created_by']===$this->actor['id']) { throw new ApiError(403,'separation_of_duties'); }
                $this->db->query('UPDATE holds SET released_by=?,released_at=? WHERE tenant_id=? AND id=?',[$this->actor['id'],time(),$t,$hold['id']]);
                $this->event('hold.released',$id); return ['released'=>true];
            }
        }
        if ($sub==='record' && count($p)===6 && $method==='POST') {
            $this->permit('records',$doc); self::keys($b,['retain_until','declare']); $until=$b['retain_until']??$doc['retain_until'];
            if (!is_int($until) || $until<$doc['retain_until'] || ($b['declare']??true)!==true) { throw new ApiError(422,'preservation_cannot_be_weakened'); }
            if ($doc['state']!=='approved') { throw new ApiError(409,'approved_version_required'); }
            $this->db->query('UPDATE documents SET is_record=1,retain_until=?,updated_at=? WHERE tenant_id=? AND id=?',[$until,time(),$t,$id]);
            $this->event('record.declared',$id); return $this->present($this->document($id,$kind));
        }
        if ($sub==='retention' && count($p)===6 && $method==='POST') {
            $this->permit('records',$doc); self::keys($b,['retain_until']); $until=$b['retain_until']??null;
            if (!is_int($until) || $until<$doc['retain_until'] || $until<time()) { throw new ApiError(422,'preservation_cannot_be_weakened'); }
            $this->db->query('UPDATE documents SET retain_until=? WHERE tenant_id=? AND id=?',[$until,$t,$id]); $this->event('retention.extended',$id); return ['retain_until'=>$until];
        }
        if ($sub==='disposition' && count($p)===6 && $method==='POST') {
            $this->permit('records',$doc); $this->permit('delete',$doc); self::keys($b,['approve_id']);
            if (isset($b['approve_id'])) {
                $d=$this->db->one('SELECT * FROM dispositions WHERE tenant_id=? AND document_id=? AND id=? AND approved_by IS NULL',[$t,$id,self::id($b['approve_id'])]);
                if (!$d || $d['version']!==$doc['current_version']) { throw new ApiError(409,'stale_disposition'); }
                if ($d['requested_by']===$this->actor['id']) { throw new ApiError(403,'separation_of_duties'); }
                $this->db->query('UPDATE dispositions SET approved_by=?,approved_at=? WHERE tenant_id=? AND id=?',[$this->actor['id'],time(),$t,$d['id']]);
                $this->event('disposition.approved',$id); return ['approved'=>true];
            }
            $disposition=Database::id(); $this->db->query('INSERT INTO dispositions(tenant_id,document_id,id,version,requested_by,created_at) VALUES(?,?,?,?,?,?)',[$t,$id,$disposition,$doc['current_version'],$this->actor['id'],time()]);
            $this->event('disposition.requested',$id); return ['id'=>$disposition];
        }
        if ($sub==='workflow' && count($p)===6) {
            if ($method==='GET') { $this->permit('view',$doc); return ['items'=>$this->db->all('SELECT * FROM workflows WHERE tenant_id=? AND document_id=? ORDER BY created_at DESC',[$t,$id])]; }
            if ($method==='POST') {
                self::keys($b,['decision','expected_version','comment']); $decision=$b['decision']??'submit'; $this->expected($doc,$b);
                if ($this->held($doc)) { throw new ApiError(409,'preservation_required'); }
                if ($decision==='submit') {
                    $this->permit('edit',$doc); $this->editable($doc); $workflow=Database::id();
                    $this->db->query('INSERT INTO workflows(tenant_id,document_id,id,version,requested_by,created_at) VALUES(?,?,?,?,?,?)',[$t,$id,$workflow,$doc['current_version'],$this->actor['id'],time()]);
                    $this->db->query("UPDATE documents SET state='in_review' WHERE tenant_id=? AND id=?",[$t,$id]);
                } elseif (in_array($decision,['approve','reject'],true)) {
                    $this->permit('approve',$doc);
                    $w=$this->db->one("SELECT * FROM workflows WHERE tenant_id=? AND document_id=? AND state='pending'",[$t,$id]);
                    if (!$w || $doc['state']!=='in_review' || $w['version']!==$doc['current_version']) { throw new ApiError(409,'workflow_not_pending'); }
                    $v=$this->db->one('SELECT author_id FROM versions WHERE tenant_id=? AND document_id=? AND number=?',[$t,$id,$doc['current_version']]);
                    if ($w['requested_by']===$this->actor['id'] || $doc['owner_id']===$this->actor['id'] || $v['author_id']===$this->actor['id']) { throw new ApiError(403,'self_approval_denied'); }
                    $state=$decision==='approve'?'approved':'rejected';
                    $digest=$this->db->one('SELECT digest FROM versions WHERE tenant_id=? AND document_id=? AND number=?',[$t,$id,$doc['current_version']])['digest'];
                    $comment=isset($b['comment'])?self::text($b,'comment',2000):'';
                    $this->db->query('UPDATE workflows SET state=?,decided_by=?,decided_at=?,assurance=?,version_digest=?,decision_comment=?,policy_version=1 WHERE tenant_id=? AND id=?',[$state,$this->actor['id'],time(),'verified-session-mfa',$digest,$comment,$t,$w['id']]);
                    $this->db->query('UPDATE documents SET state=? WHERE tenant_id=? AND id=?',[$state,$t,$id]);
                } else { throw new ApiError(422,'invalid_decision'); }
                $this->event('workflow.' . $decision,$id); return $this->present($this->document($id,$kind));
            }
        }
        if ($sub==='comments' && count($p)===6) {
            $this->permit('view',$doc);
            if ($method==='GET') { return ['items'=>$this->db->all('SELECT id,version,author_id,body,created_at FROM comments WHERE tenant_id=? AND document_id=? ORDER BY created_at,id',[$t,$id])]; }
            if ($method==='POST') {
                $this->permit('comment',$doc); self::keys($b,['body','expected_version']); $this->expected($doc,$b); $comment=Database::id();
                $this->db->query('INSERT INTO comments(tenant_id,document_id,id,version,author_id,body,created_at) VALUES(?,?,?,?,?,?,?)',[$t,$id,$comment,$doc['current_version'],$this->actor['id'],self::text($b,'body',4000),time()]);
                $this->event('comment.created',$id); return ['id'=>$comment];
            }
        }
        if ($sub==='bookmark' && count($p)===6 && in_array($method,['POST','DELETE'],true)) {
            $this->permit('view',$doc);
            if ($method==='POST') { $this->db->query('INSERT INTO bookmarks(tenant_id,document_id,user_id) VALUES(?,?,?) ON CONFLICT DO NOTHING',[$t,$id,$this->actor['id']]); }
            else { $this->db->query('DELETE FROM bookmarks WHERE tenant_id=? AND document_id=? AND user_id=?',[$t,$id,$this->actor['id']]); }
            return ['bookmarked'=>$method==='POST'];
        }
        throw new ApiError(404,'route_not_found');
    }

    private function addVersion(array $doc, array $body): void
    {
        $encoded=$body['content_base64']??null;
        if (!is_string($encoded) || strlen($encoded)>4*ceil(Config::MAX_FILE_BYTES/3)) { throw new ApiError(413,'file_limit_exceeded'); }
        $plain=base64_decode($encoded,true);
        if ($plain===false || strlen($plain)>Config::MAX_FILE_BYTES || $plain==='') { throw new ApiError(422,'invalid_content'); }
        $mime=FileScanner::validate($plain);
        $bytes=strlen($plain); $tenant=$doc['tenant_id'];
        $quota=$this->db->one('SELECT quota_bytes FROM tenants WHERE id=?',[$tenant])['quota_bytes'];
        $used=$this->db->one('SELECT COALESCE(SUM(bytes),0) AS used FROM versions WHERE tenant_id=?',[$tenant])['used'];
        if ($used+$bytes>$quota) { throw new ApiError(413,'tenant_quota_exceeded'); }
        $version=(int)$doc['current_version']+1; $object=Database::id(); $digest=hash('sha256',$plain);
        try { $encrypted=$this->crypto->encrypt($tenant,Crypto::context($tenant,$doc['id'],$version,(int)$doc['classification']),$plain); } finally { sodium_memzero($plain); }
        $this->objects->put($tenant,$object,$encrypted['ciphertext']);$this->newObjects[]=$object;
        $this->db->query('INSERT INTO versions(tenant_id,document_id,number,object_id,envelope,bytes,digest,mime,author_id,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$tenant,$doc['id'],$version,$object,$encrypted['envelope'],$bytes,$digest,$mime,$this->actor['id'],time()]);
        $this->db->query("UPDATE documents SET current_version=?,state='draft',updated_at=? WHERE tenant_id=? AND id=?",[$version,time(),$tenant,$doc['id']]);
    }

    private function document(string $id, string $kind='document'): array
    {
        $doc=$this->db->one("SELECT * FROM documents WHERE tenant_id=? AND id=? AND kind=? AND state!='deleted'",[$this->tenant,$id,$kind]);
        if (!$doc) { throw new ApiError(404,'resource_not_found'); } return $doc;
    }
    private function visible(string $id): bool
    {
        $doc=$this->db->one("SELECT * FROM documents WHERE tenant_id=? AND id=? AND state!='deleted'",[$this->tenant,$id]);
        return $doc && $this->policy->reason($this->actor,$this->tenant,'view',$doc)===null;
    }
    private function present(array $doc): array
    {
        $doc['metadata']=json_decode($doc['metadata'],true,16,JSON_THROW_ON_ERROR);
        $doc['permissions']=[];
        foreach (Policy::DOCUMENT_ACTIONS as $action) { $doc['permissions'][$action]=$this->policy->reason($this->actor,$doc['tenant_id'],$action,$doc)===null; }
        $doc['mode']=$this->config->managedMode(); return $doc;
    }
    private function permit(string $action, ?array $doc=null): void { $this->policy->require($this->actor,$this->tenant,$action,$doc); }
    private function editable(array $doc): void
    {
        if ($doc['is_record'] || !in_array($doc['state'],['draft','rejected'],true)) { throw new ApiError(409,'immutable_or_in_review'); }
        if ($this->held($doc)) { throw new ApiError(409,'preservation_required'); }
    }
    private function held(array $doc): bool { return $this->db->one('SELECT id FROM holds WHERE tenant_id=? AND document_id=? AND released_at IS NULL',[$doc['tenant_id'],$doc['id']])!==null; }
    private function expected(array $doc, array $body): void
    {
        if (!isset($body['expected_version']) || !is_int($body['expected_version']) || $body['expected_version']!==$doc['current_version']) { throw new ApiError(409,'version_conflict'); }
    }
    private function event(string $action, string $resource): void
    {
        $this->audit->append($this->tenant,$this->actor['id'],$action,$resource,'allowed','policy_allowed',$this->requestId);
        if (!str_ends_with($action,'.viewed') && !str_ends_with($action,'.downloaded')) {
            $this->db->query('INSERT INTO outbox(id,tenant_id,event,resource_id,created_at) VALUES(?,?,?,?,?)',[Database::id(),$this->tenant,$action,$resource,time()]);
        }
    }
    private function tenantSettings(): array
    {
        $t=$this->db->one('SELECT id,name,status,region,settings,quota_bytes FROM tenants WHERE id=?',[$this->tenant]);
        $t['locale']=json_decode($t['settings'],true)['locale']??'en'; unset($t['settings']);
        $t['locked_controls']=['encryption'=>true,'explicit_deny'=>true,'mfa_for_privileged_actions'=>true,'external_sharing'=>false,'ai'=>false]; return $t;
    }
    private function provision(array $b): array
    {
        $this->governance();
        self::keys($b,['name','region','owner_id','idempotency_key']); $owner=self::id($b['owner_id']??'');
        $name=self::text($b,'name',120); $key=self::id($b['idempotency_key']??'');
        $requestId=hash('sha256',$this->actor['id'] . ':' . $key);
        $hash=hash('sha256',json_encode([$name,$b['region']??'', $owner]));
        $previous=$this->db->one('SELECT * FROM provisioning_requests WHERE id=?',[$requestId]);
        if ($previous) {
            if (!hash_equals($previous['request_hash'],$hash)) { throw new ApiError(409,'idempotency_conflict'); }
            $this->tenant=$previous['tenant_id']; return ['id'=>$previous['tenant_id'],'replayed'=>true];
        }
        if (!$this->db->one("SELECT id FROM users WHERE id=? AND status='active'",[$owner])) { throw new ApiError(422,'unknown_owner'); }
        if (($b['region']??'')!=='local') { throw new ApiError(422,'region_not_available'); }
        $id=Database::id();
        $this->db->context($this->actor['id'],$id);
        $this->db->query('INSERT INTO tenants(id,name,parent_id,region,created_at) VALUES(?,?,?,?,?)',[$id,$name,'holding','local',time()]);
        $this->db->query('INSERT INTO roles(tenant_id,id,name,capabilities) VALUES(?,?,?,?)',[$id,'administrator','Subsidiary administrator',json_encode(Policy::CAPABILITIES)]);
        $this->db->query('INSERT INTO memberships(tenant_id,user_id,role_id,clearance) VALUES(?,?,?,?)',[$id,$owner,'administrator',3]);
        $this->crypto->provision($id); $this->tenant=$id;
        $this->db->query('INSERT INTO provisioning_requests(id,actor_id,request_hash,tenant_id,created_at) VALUES(?,?,?,?,?)',[$requestId,$this->actor['id'],$hash,$id,time()]);
        $this->event('tenant.provisioned',$id); return ['id'=>$id];
    }
    private function governance(): void
    {
        $m=$this->policy->membership($this->actor,'holding');
        if (!in_array('tenants',$m['capabilities'],true) || !$this->actor['mfa']) { throw new ApiError(403,'governance_permission_required'); }
    }
    private function rateLimit(): void
    {
        $row=$this->db->one('SELECT requests FROM rate_limits WHERE tenant_id=? AND user_id=? AND "window"=?',[$this->tenant,$this->actor['id'],intdiv(time(),60)]);
        if (($row['requests']??0)>=120) { throw new ApiError(429,'tenant_rate_limit'); } $this->countRequest();
    }
    private function countRequest(): void
    {
        $this->db->query('INSERT INTO rate_limits(tenant_id,user_id,"window",requests) VALUES(?,?,?,1) ON CONFLICT(tenant_id,user_id,"window") DO UPDATE SET requests=rate_limits.requests+1',[$this->tenant,$this->actor['id'],intdiv(time(),60)]);
        $this->db->query('DELETE FROM rate_limits WHERE "window"<?',[intdiv(time(),60)-2]);
    }
    public static function id(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$id)) { throw new ApiError(422,'invalid_identifier'); } return $id;
    }
    public static function text(array $body, string $key, int $limit): string
    {
        $v=$body[$key]??null;
        if (!is_string($v) || !mb_check_encoding($v,'UTF-8') || mb_strlen(trim($v))<1 || mb_strlen($v)>$limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$v)) { throw new ApiError(422,'invalid_' . $key); }
        return \Normalizer::normalize(trim($v),\Normalizer::FORM_C);
    }
    private static function metadata(mixed $metadata): string
    {
        if (!is_array($metadata) || count($metadata)>30) { throw new ApiError(422,'invalid_metadata'); }
        foreach ($metadata as $key=>$value) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$key) || !is_string($value) || mb_strlen($value)>1000) { throw new ApiError(422,'invalid_metadata'); }
        }
        return json_encode($metadata,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    }
    private static function keys(array $body, array $allowed): void
    {
        if (array_diff(array_keys($body),$allowed)) { throw new ApiError(422,'unknown_fields'); }
    }
    private static function positive(mixed $n): int { $v=self::nonnegative($n); if ($v<1) { throw new ApiError(422,'invalid_number'); } return $v; }
    private static function nonnegative(mixed $n): int
    {
        if ((!is_int($n) && !is_string($n)) || !preg_match('/^\d{1,9}$/D',(string)$n)) { throw new ApiError(422,'invalid_number'); } return (int)$n;
    }
    private static function locale(mixed $locale): void
    {
        if (!in_array($locale,['en','fa','fr','tr','ru','zh-Hans','de','pt','es','hi','ja','ar','hy'],true)) { throw new ApiError(422,'invalid_locale'); }
    }
}
