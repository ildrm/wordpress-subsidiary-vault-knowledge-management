# API guide

The reference is [OpenAPI 3.1 JSON](../../packages/contracts/openapi.json): 42 actual paths, 67 operations, generic success response schemas. A complete typed SDK/enterprise API contract is not implemented. Each operation is a real development route; unsupported future features have no pretend endpoint.

Every protected call requires an independent `Authorization: Bearer …` header. WordPress cookies, roles, REST nonces, query parameters and a client tenant ID alone cannot authenticate vault access. All successful domain operations return HTTP 200 in this development version. Errors return `error` and usually a non-secret `request_id`; denial messages do not include protected titles/content.

```sh
export SV_TOKEN="$(php tools/vault.php token tenant-01-custodian)"
curl --fail-with-body -H "Authorization: Bearer $SV_TOKEN" http://127.0.0.1:8088/v1/me
curl --fail-with-body -H "Authorization: Bearer $SV_TOKEN" http://127.0.0.1:8088/v1/tenants/tenant-01/documents
unset SV_TOKEN
```

Do not store bearer tokens in shell history literals or URLs. This command reads a synthetic development token; use a dedicated test environment.

| Surface | Behavior |
|---|---|
| `/healthz` | Public liveness/version/development-profile signal, not a readiness/SLO promise |
| `/v1/me`, `/v1/session` | Current independently authenticated identity/memberships; revoke current development session |
| `/v1/posture` | Holding-only aggregate names/status/counts/bytes, no titles/keys |
| `POST /v1/tenants` | Holding MFA, existing independent owner, `region=local`, required idempotency key; matching retry returns same ID, conflicting payload returns 409 |
| `PATCH /v1/governance/tenants/{tenant}` | Holding MFA suspend/reactivate; closing rejected |
| `/v1/tenants/{tenant}/settings` | Eligible name/locale/quota; locked security floors cannot be weakened |
| `…/roles`, `…/members/{user}` | Tenant custom roles and changes to another membership, with capability/clearance amplification checks |
| `…/groups`, `…/groups/{group}/members`, `…/departments` | Static tenant teams, existing-member assignment and department hierarchy |
| `…/documents`, `…/knowledge` | Current-authorized title/metadata search (`q`), 50-item result pages (`after`), encrypted UTF-8 creation |
| `…/{document}` | Read metadata, edit metadata with `expected_version` and `expected_metadata_revision`, guarded soft deletion |
| `…/{document}/versions`, `…/versions/{number}` | List immutable version evidence, append with expected content version, authorized base64 text download |
| `…/{document}/permissions` | Inspect/grant/revoke user/group/role ACL actions; explicit deny precedence and no self-grants |
| `…/{document}/workflow` | Submit or separately approve/reject pinned bytes; inspect evidence |
| `…/{document}/comments`, `…/bookmark` | Version comments and per-user bookmark mutation; bookmark inventory UI remains unfinished |
| `…/{document}/holds`, `…/holds/{hold}` | Place/inspect holds, dual-officer release |
| `…/{document}/record`, `…/retention`, `…/disposition` | Declare approved immutable record, extend retention, request/approve separate disposition |
| `…/audit`, `…/notifications` | Scoped event pages and current-authorized local inbox; mark own notification read |

File upload is JSON `content_base64`; this proof is not multipart/resumable upload. The server accepts nonempty UTF-8 without prohibited controls, max 8 MiB decoded, and rejects unsupported security modes. Download response includes `content_base64`, digest, MIME and version; it means the service authorized/read/decrypted bytes to construct a response, not proof that a human read them or saved a complete download. No inline HTML/PDF viewer is present.

HTTP envelope limits JSON to 12 MiB and requires an object, applies no-store/CSP/frame/noindex headers, and serves only an explicit asset allowlist. There is no CORS permission or cookie authority. The local rate limit is per principal/tenant, including rejected requests; this is not a full unauthenticated ingress abuse-control system.

Local transactions serialize authorization and state updates. Metadata changes increment `metadata_revision`; stale metadata and stale content versions return 409. Review/approved/record/held documents reject content edits. High-risk deletion rechecks retention/hold/record and a separate disposition approval bound to the current version. Version history always uses current access restrictions.
