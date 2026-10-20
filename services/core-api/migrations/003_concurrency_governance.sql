ALTER TABLE documents ADD COLUMN metadata_revision INTEGER NOT NULL DEFAULT 1;
CREATE TABLE provisioning_requests (id TEXT PRIMARY KEY, actor_id TEXT NOT NULL REFERENCES users(id), request_hash TEXT NOT NULL, tenant_id TEXT NOT NULL UNIQUE REFERENCES tenants(id), created_at INTEGER NOT NULL);
INSERT INTO schema_migrations VALUES(3);
