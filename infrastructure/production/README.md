# Production deployment is gated

There is intentionally no purported working production deployment manifest. The implemented application rejects any `SV_ENV` other than `local`. Disabling this guard would not create the missing production architecture.

Required adapters and controls: independent OIDC/SAML/SCIM identity with reviewed MFA/session lifecycle; PostgreSQL tenant constraints and RLS under restricted service identities; private object store with scoped credentials and legally appropriate retention/object lock; an approved KMS/HSM and audited key policies; independent immutable audit; queue isolation and worker identities; ingress/TLS/abuse defense; observability; approved regional processing; signed updates/build provenance; encrypted immutable offline recovery and reconciliation procedures. The high-assurance client is a separate additional milestone.

Implement these against the canonical contracts with tests before supplying a deployment manifest. Then obtain the actual external assessments, operational drills and customer approvals listed in `docs/release/READINESS.md`. No cloud account, production domain, provider, jurisdiction, data processor or signatory was invented for this repository.
