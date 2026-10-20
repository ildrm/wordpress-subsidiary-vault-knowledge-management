# Contributing

Read `docs/source/REVIEW.md`, `docs/implementation/PROGRESS.md`, `docs/requirements/traceability.csv` and `docs/security/threat-model.md` before changing behavior. Preserve all canonical IDs/contracts and source checksums. Resume an unfinished dependency-ready item rather than replacing working components.

Run `make validate`, then `make package` and `python3 tools/release_gate.py --development`. Use synthetic data. Add meaningful negative authorization/preservation tests for new resource surfaces; do not merely check that a button exists. Record actual evidence and unresolved limits. `VERIFIED` requires the full canonical contract, not a partial implementation or generated artifact.

Keep tenant identity derived from verified membership plus immutable resource ownership; deny overrides grants. WordPress privileges never confer content rights. All mutations need an atomic audit event. New versions use fresh keys; no plaintext/key belongs in WordPress or logs. Every destruction/restore/export/async path must honor current permissions and preservation. Do not add unreviewed providers, new plaintext processing, recovery bypasses or permissive outage fallbacks.

Create forward migrations and a rollback/recovery plan; do not rewrite already deployed schema history. Existing migration files belong to this initial development release; subsequent changes should use the next numbered file. Keep new tests isolated in temporary directories and refuse overwriting operator data. Never commit `var`, private credentials, keys, snapshots or actual company content.

Use an ADR for trust-boundary, legal/retention, provider, crypto-mode or policy semantic changes. Internal AI/self-review does not satisfy independent assurance gates. Include requirement IDs, concrete behavior, tests executed and known risks in PR descriptions. A real maintainer/contact list and review ownership must be assigned by the repository owner; none is fabricated here.
