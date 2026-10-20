CREATE TABLE external_identities (issuer TEXT NOT NULL, subject TEXT NOT NULL, user_id TEXT NOT NULL REFERENCES users(id), status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','suspended')), created_at BIGINT NOT NULL, PRIMARY KEY(issuer,subject));
CREATE TABLE revoked_access_tokens (hash TEXT PRIMARY KEY, expires_at BIGINT NOT NULL);
INSERT INTO schema_migrations VALUES(3);
