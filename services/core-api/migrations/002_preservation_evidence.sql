ALTER TABLE workflows ADD COLUMN assurance TEXT;
ALTER TABLE workflows ADD COLUMN version_digest TEXT;
ALTER TABLE workflows ADD COLUMN decision_comment TEXT;
ALTER TABLE workflows ADD COLUMN policy_version INTEGER;
CREATE TRIGGER record_content_immutable BEFORE UPDATE OF title,metadata,classification,current_version,state,owner_id,tenant_id ON documents
WHEN OLD.is_record=1 OR EXISTS(SELECT 1 FROM holds WHERE tenant_id=OLD.tenant_id AND document_id=OLD.id AND released_at IS NULL)
BEGIN SELECT RAISE(ABORT,'preservation_required'); END;
CREATE TRIGGER record_lock_immutable BEFORE UPDATE OF is_record ON documents WHEN OLD.is_record=1 AND NEW.is_record!=1
BEGIN SELECT RAISE(ABORT,'record_lock_immutable'); END;
CREATE TRIGGER retention_monotonic BEFORE UPDATE OF retain_until ON documents WHEN NEW.retain_until<OLD.retain_until
BEGIN SELECT RAISE(ABORT,'retention_cannot_be_weakened'); END;
CREATE TRIGGER preserved_document_no_delete BEFORE DELETE ON documents
WHEN OLD.is_record=1 OR OLD.retain_until>unixepoch() OR EXISTS(SELECT 1 FROM holds WHERE tenant_id=OLD.tenant_id AND document_id=OLD.id AND released_at IS NULL)
BEGIN SELECT RAISE(ABORT,'preservation_required'); END;
CREATE TRIGGER workflow_decision_immutable BEFORE UPDATE ON workflows WHEN OLD.state!='pending'
BEGIN SELECT RAISE(ABORT,'workflow_evidence_immutable'); END;
CREATE TRIGGER workflow_no_delete BEFORE DELETE ON workflows
BEGIN SELECT RAISE(ABORT,'workflow_evidence_immutable'); END;
INSERT INTO schema_migrations VALUES(2);
