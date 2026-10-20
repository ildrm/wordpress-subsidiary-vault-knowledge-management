#!/usr/bin/env python3
"""Reference contract for the implemented development API, not the entire future feature catalog."""
import json
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
paths={}
def route(path,methods,summary,fields=None,required=None):
    entry=paths.setdefault(path,{})
    for method in methods.split():
        op={'summary':summary,'operationId':method+'_'+path.strip('/').replace('/','_').replace('{','').replace('}',''),'responses':{'200':{'description':'Operation succeeded; see API guide for response semantics','content':{'application/json':{'schema':{'type':'object'}}}},**{str(code):{'description':description,'content':{'application/json':{'schema':{'$ref':'#/components/schemas/Error'}}}} for code,description in [(400,'Malformed JSON'),(401,'Independent authentication required'),(403,'Policy denied'),(404,'Resource or route unavailable'),(409,'Conflict or protection gate'),(413,'Quota or request size exceeded'),(415,'Unsupported content type'),(422,'Invalid input'),(429,'Tenant rate limit'),(500,'Unexpected failure, no sensitive details'),(503,'Dependency or integrity failure')]}},'parameters':[]}
        for part in path.split('/'):
            if part.startswith('{'):op['parameters'].append({'name':part[1:-1],'in':'path','required':True,'schema':{'type':'string','maxLength':64}})
        if fields and method in ['post','put','patch','delete']:
            op['requestBody']={'required':bool(required),'content':{'application/json':{'schema':{'type':'object','additionalProperties':False,'properties':fields,'required':required or []}}}}
        entry[method]=op
S=lambda maximum=1000:{'type':'string','maxLength':maximum}
I={'type':'integer','minimum':0}
META={'type':'object','maxProperties':30,'additionalProperties':S(1000)}
route('/healthz','get','Local service liveness')
paths['/healthz']['get']['security']=[]
route('/v1/me','get','Identity and independently scoped memberships')
route('/v1/session','delete','Revoke current development token; OIDC must revoke at provider')
route('/v1/posture','get','Holding aggregate counts only; no document titles or content')
route('/v1/tenants','post','Provision a local tenant for an existing independent identity',{'name':S(120),'region':{'const':'local'},'owner_id':S(64),'idempotency_key':S(64)},['name','region','owner_id','idempotency_key'])
route('/v1/governance/tenants/{tenant}','patch','Suspend/reactivate access while preserving all content',{'status':{'enum':['active','suspended']}},['status'])
B='/v1/tenants/{tenant}'
route(B+'/settings','get patch','View/update eligible settings; locked floors are not mutable',{'name':S(120),'locale':S(10),'quota_bytes':I})
route(B+'/roles','get post','List roles or create a role with no privileges by default',{'name':S(120),'capabilities':{'type':'array','items':S(32)}},['name'])
route(B+'/members','get','List tenant memberships')
route(B+'/members/{user}','patch','Change another membership without privilege amplification',{'status':{'enum':['active','suspended']},'role_id':S(64),'clearance':{'type':'integer','minimum':0,'maximum':3},'expires_at':I})
for group in ['groups','departments']:
    route(B+'/'+group,'get post','List/create scoped '+group,{'name':S(120),**({'parent_id':S(64)} if group=='departments' else {})},['name'])
route(B+'/groups/{group}/members','post delete','Add/remove an existing member; self-grants are denied',{'user_id':S(64)},['user_id'])
route(B+'/audit','get','First 100 events after a sequence; verifies local hash chain')
paths[B+'/audit']['get']['parameters'].append({'name':'after','in':'query','schema':I})
route(B+'/notifications','get','Reauthorize every notification at delivery')
route(B+'/notifications/{notification}','patch','Mark own notification as read')
for kind in ['documents','knowledge']:
    path=B+'/'+kind
    route(path,'get post','List authorized '+kind+' or create encrypted UTF-8 text content',{'title':S(240),'classification':{'type':'integer','minimum':0,'maximum':3},'metadata':META,'content_base64':S(11184812),'mode':{'const':'managed-local'}},['title','content_base64'])
    paths[path]['get']['parameters'] += [{'name':'q','in':'query','schema':S(200)},{'name':'after','in':'query','schema':I}]
    path+='/{document}'
    route(path,'get patch delete','Read/edit metadata or soft-delete after dual disposition approval',{'title':S(240),'metadata':META,'expected_version':I,'expected_metadata_revision':I})
    route(path+'/versions','get post','List immutable versions or append a new encrypted version',{'content_base64':S(11184812),'expected_version':I},['content_base64','expected_version'])
    route(path+'/versions/{number}','get','Authorize current rights and return decrypted text as base64')
    route(path+'/permissions','get put delete','Manage explicit user/group/role access; denies win',{'subject_type':{'enum':['user','group','role']},'subject_id':S(64),'action':S(32),'effect':{'enum':['allow','deny']},'expires_at':I},['subject_type','subject_id','action','effect'])
    route(path+'/workflow','get post','Submit immutable review or decide with a separate approver',{'decision':{'enum':['submit','approve','reject']},'expected_version':I,'comment':S(2000)},['expected_version'])
    route(path+'/comments','get post','Read/post version-bound discussion',{'body':S(4000),'expected_version':I},['body','expected_version'])
    route(path+'/holds','get post','Read/place a document hold',{'reason':S(1000)},['reason'])
    route(path+'/holds/{hold}','delete','Release a hold with a different authorized legal officer')
    route(path+'/record','post','Declare approved bytes an immutable record',{'retain_until':I,'declare':{'const':True}})
    route(path+'/retention','post','Extend but never shorten retention',{'retain_until':I},['retain_until'])
    route(path+'/disposition','post','Request disposition or approve a different custodian request',{'approve_id':S(64)})
    route(path+'/bookmark','post delete','Save/remove own bookmark after view authorization')
doc={'openapi':'3.1.0','info':{'title':'SubsidiaryVault local development API','version':'0.1.0-dev','description':'Independent bearer identity, active membership, RBAC/ABAC/ACL, deny precedence. Local text-only profile; production and zero-knowledge are unavailable. Response schemas are intentionally generic; this is a reference, not a generated SDK contract.'},'servers':[{'url':'http://127.0.0.1:8088'}],'security':[{'VaultBearer':[]}],'paths':paths,'components':{'securitySchemes':{'VaultBearer':{'type':'http','scheme':'bearer','description':'Development opaque token or pinned-key RS256 adapter selected by server configuration. WordPress cookies, nonces and roles are not accepted.'}},'schemas':{'Error':{'type':'object','required':['error'],'properties':{'error':S(100),'request_id':S(32)}}}}}
(ROOT/'packages/contracts/openapi.json').write_text(json.dumps(doc,indent=2)+'\n')
print(f'Generated reference: {len(paths)} paths, {sum(len(x) for x in paths.values())} operations')
