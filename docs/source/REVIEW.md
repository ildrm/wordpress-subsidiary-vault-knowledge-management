# Source bundle review

Reviewed 2026-10-20. All six supplied originals are preserved in this directory, with SHA-256 checksums and canonical-ID findings in [manifest.json](manifest.json). The DOCX and XLSX XML was extracted using Python's standard library into adjacent `.txt` files; no macros or embedded executable content were run.

The user's request authorizes using the master prompt as project guidance. Statements inside the source documents are specification material, not higher-priority agent instructions or evidence of actual implementation, certification, signature, external consent or deployment approval. The requested model name in the prompt does not change the running model. The virtual council is an internal review aid; none of its 44 functions is a commissioned independent professional.

| Source | Findings and implementation use |
|---|---|
| Feature blueprint DOCX | 346 canonical IDs found; describes 22 modules, confidentiality boundaries, 13 languages, release phases and acceptance scenarios. It explicitly labels itself proposed software. |
| Requirements tracker XLSX | 346 canonical IDs found across overview/requirements/modules/sources sheets. Used as a reconciliation reference, not as completed delivery evidence. |
| Roles/review DOCX | 44 specialist functions, runtime-role and cross-functional controls; requests independent human assurance. Several sample requirement references, rather than the full catalog. |
| Roles/RACI/risks/gates XLSX | 346 canonical references; review ownership, risks, conflicts and gate material retained. No named operational/legal/security signatories were supplied. |
| Canonical JSON | Source of exact feature contracts: 346 unique IDs, 22 expected module counts. Normalized copy in `docs/requirements/canonical-requirements.json` is equality-checked against the preserved original. |
| Master prompt | Consolidated implementation guidance, all 346 contracts, 44 delivery functions, 26 runtime personas and 22 decision questions. The first specified tenant-isolation milestone is substantially implemented, but the full product is incomplete. |

Reconciliation found no missing canonical ID in the blueprint, requirements tracker, RACI workbook, JSON or master prompt. This ID-level reconciliation is not an assertion that every prose variant in six Office/Markdown sources is identical. The preserved JSON contracts take precedence for the requirement registry; every substantive discrepancy discovered later must receive an ADR rather than silent reinterpretation.

The reference topology is adapted through [D-21](../decisions/D-21.md): extension-only PHP and a vanilla independent console allow immediate reproducible local tests without installing a framework or JavaScript supply chain. SQLite and filesystem key custody are development adapters. They do not fulfill the proposed PostgreSQL/RLS, private object-store, KMS/HSM, independent audit and signed-client enterprise architecture.

The user's final README instruction is handled after implementation and verification. Its structure follows the researched GitHub/Google guidance: explain purpose/status first, give a reproducible quick start, describe architecture/configuration/use, then verification, security, limitations, operations, contribution, support and license, with links to detailed project records. See [research references](../architecture/references.md).

## Self-hosted reference follow-up — 0.2.0-rc.1

The user selected the self-hosted reference stack. Actual PostgreSQL/RLS, OIDC/PKCE/session protocols, transit roots, S3, ClamAV and WordPress lifecycle are now implemented/exercised as documented in the current verification evidence. These improve the initial local adapters without silently changing the canonical 346 contracts, claiming independent reviews or authorizing go-live. The README is updated after this implementation/evidence batch; remaining engineering and external gates remain explicit.
