-- Migration credentials must own the schema; runtime credentials must neither own tables nor BYPASSRLS.
CREATE FUNCTION svkms_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'immutable_evidence' USING ERRCODE='23514'; END $$;
CREATE TRIGGER version_update_guard BEFORE UPDATE OR DELETE ON versions FOR EACH ROW EXECUTE FUNCTION svkms_immutable();
CREATE TRIGGER audit_update_guard BEFORE UPDATE OR DELETE ON audit FOR EACH ROW EXECUTE FUNCTION svkms_immutable();
CREATE FUNCTION svkms_preservation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='DELETE' THEN
    IF OLD.is_record=1 OR OLD.retain_until>extract(epoch FROM now()) OR EXISTS(SELECT 1 FROM holds WHERE tenant_id=OLD.tenant_id AND document_id=OLD.id AND released_at IS NULL) THEN
      RAISE EXCEPTION 'preservation_required' USING ERRCODE='23514';
    END IF;
    RETURN OLD;
  END IF;
  IF NEW.retain_until<OLD.retain_until OR (OLD.is_record=1 AND NEW.is_record<>1) THEN
    RAISE EXCEPTION 'preservation_cannot_be_weakened' USING ERRCODE='23514';
  END IF;
  IF ROW(NEW.title,NEW.metadata,NEW.classification,NEW.current_version,NEW.state,NEW.owner_id,NEW.tenant_id)
     IS DISTINCT FROM ROW(OLD.title,OLD.metadata,OLD.classification,OLD.current_version,OLD.state,OLD.owner_id,OLD.tenant_id)
     AND (OLD.is_record=1 OR EXISTS(SELECT 1 FROM holds WHERE tenant_id=OLD.tenant_id AND document_id=OLD.id AND released_at IS NULL)) THEN
    RAISE EXCEPTION 'preservation_required' USING ERRCODE='23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER preservation_guard BEFORE UPDATE OR DELETE ON documents FOR EACH ROW EXECUTE FUNCTION svkms_preservation();
CREATE FUNCTION svkms_workflow_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR OLD.state<>'pending' THEN RAISE EXCEPTION 'immutable_evidence' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER workflow_guard BEFORE UPDATE OR DELETE ON workflows FOR EACH ROW EXECUTE FUNCTION svkms_workflow_guard();

DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['documents','versions','acl','holds','workflows','comments','dispositions','audit','outbox','notifications','rate_limits','bookmarks','groups','group_members','departments'] LOOP
  EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY',t);
  EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY',t);
  EXECUTE format('CREATE POLICY tenant_scope ON %I USING (tenant_id=nullif(current_setting(''svkms.tenant'',true),'''')) WITH CHECK (tenant_id=nullif(current_setting(''svkms.tenant'',true),''''))',t);
 END LOOP;
END $$;
ALTER TABLE memberships ENABLE ROW LEVEL SECURITY;
ALTER TABLE memberships FORCE ROW LEVEL SECURITY;
CREATE POLICY membership_read ON memberships FOR SELECT USING (tenant_id=nullif(current_setting('svkms.tenant',true),'') OR user_id=nullif(current_setting('svkms.principal',true),''));
CREATE POLICY membership_write ON memberships FOR ALL USING(tenant_id=nullif(current_setting('svkms.tenant',true),'')) WITH CHECK(tenant_id=nullif(current_setting('svkms.tenant',true),''));
ALTER TABLE roles ENABLE ROW LEVEL SECURITY;
ALTER TABLE roles FORCE ROW LEVEL SECURITY;
CREATE POLICY role_read ON roles FOR SELECT USING(tenant_id=nullif(current_setting('svkms.tenant',true),'') OR EXISTS(SELECT 1 FROM memberships m WHERE m.tenant_id=roles.tenant_id AND m.user_id=nullif(current_setting('svkms.principal',true),'')));
CREATE POLICY role_write ON roles FOR ALL USING(tenant_id=nullif(current_setting('svkms.tenant',true),'')) WITH CHECK(tenant_id=nullif(current_setting('svkms.tenant',true),''));
ALTER TABLE tenants ENABLE ROW LEVEL SECURITY;
ALTER TABLE tenants FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_read ON tenants FOR SELECT USING(id=nullif(current_setting('svkms.tenant',true),'') OR EXISTS(SELECT 1 FROM memberships m WHERE m.tenant_id=tenants.id AND m.user_id=nullif(current_setting('svkms.principal',true),'')));
CREATE POLICY tenant_write ON tenants FOR ALL USING(id=nullif(current_setting('svkms.tenant',true),'')) WITH CHECK(id=nullif(current_setting('svkms.tenant',true),''));
ALTER TABLE provisioning_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE provisioning_requests FORCE ROW LEVEL SECURITY;
CREATE POLICY provisioning_actor ON provisioning_requests USING(actor_id=nullif(current_setting('svkms.principal',true),'')) WITH CHECK(actor_id=nullif(current_setting('svkms.principal',true),''));

-- Defined by a separate privileged migration account. No arbitrary SQL or plaintext return surface.
CREATE FUNCTION svkms_posture() RETURNS TABLE(id text,name text,status text,documents bigint,bytes numeric)
LANGUAGE plpgsql SECURITY DEFINER SET search_path=public,pg_temp AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM memberships m JOIN roles r ON r.id=m.role_id AND r.tenant_id=m.tenant_id
   JOIN users u ON u.id=m.user_id WHERE m.tenant_id='holding' AND m.user_id=nullif(current_setting('svkms.principal',true),'')
   AND m.status='active' AND u.status='active' AND (m.expires_at IS NULL OR m.expires_at>extract(epoch FROM now()))
   AND r.capabilities::jsonb @> '["posture"]'::jsonb) THEN RAISE EXCEPTION 'posture_denied' USING ERRCODE='42501'; END IF;
 RETURN QUERY SELECT t.id,t.name,t.status,COUNT(d.id),COALESCE(SUM(v.bytes),0)
 FROM tenants t LEFT JOIN documents d ON d.tenant_id=t.id AND d.state<>'deleted'
 LEFT JOIN versions v ON v.tenant_id=d.tenant_id AND v.document_id=d.id AND v.number=d.current_version
 WHERE t.id<>'holding' GROUP BY t.id ORDER BY t.id;
END $$;
REVOKE ALL ON FUNCTION svkms_posture() FROM PUBLIC;
CREATE FUNCTION svkms_pending_events() RETURNS SETOF outbox LANGUAGE sql SECURITY DEFINER SET search_path=public,pg_temp AS $$
 SELECT * FROM outbox WHERE delivered_at IS NULL ORDER BY created_at,id LIMIT 100
$$;
REVOKE ALL ON FUNCTION svkms_pending_events() FROM PUBLIC;
INSERT INTO schema_migrations VALUES(2);
