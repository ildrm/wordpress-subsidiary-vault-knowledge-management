# Release readiness

Version: 0.1.0-dev. Decision: **INCOMPLETE DEVELOPMENT BUILD — DO NOT USE FOR COMPANY DATA**.

The whole product is not implemented. All 346 requirements remain in the registry; 0 have full canonical acceptance. Passing local component tests establish the stated local behaviors only. The application rejects production mode in code. The independent high-assurance zero-knowledge repository mode is unavailable.

| Source gate | State | Evidence or missing action |
|---|---|---|
| G0 Charter and authority | OPEN | Source review and internal ADRs exist; actual sponsors, owners, legal jurisdictions and signatories unassigned |
| G1 Threat model and design | PARTIAL INTERNAL | Threat/data flow and 22 ADRs exist; enterprise design, protocol and independent review incomplete |
| G2 Tenant and access proof | PARTIAL INTERNAL | Executed ten-tenant local negative tests and policy checks; full enterprise paths/personas/identity/data plane incomplete |
| G3 Cryptographic assurance | BLOCKED | Local Sodium/envelope/stream tests pass; recipient protocol, signed native client and external assessment missing |
| G4 Core document lifecycle | PARTIAL INTERNAL | Text versions, metadata, review, records/holds/disposition and connector work; binary ingestion, previews, folder hierarchy and broader lifecycle incomplete |
| G5 Governance and resilience | PARTIAL INTERNAL | Local snapshot/isolated restoration and minimal runbooks; production custody, WORM, monitoring and measured drills missing |
| G6 Subsidiary pilot | NOT RUN | Synthetic fixtures are not human UAT or an actual customer pilot |
| G7 Independent production go/no-go | DENIED | Engineering gaps plus security/crypto/legal/privacy/records/accessibility/DR evidence and accountable signatures outstanding |
| G8 Enterprise expansion | NOT READY | Enterprise/expansion requirements remain tracked, mostly not started |

`python3 tools/release_gate.py` exits nonzero and reports missing gates. `--development` validates only the local distributable package; it does not approve production. No full security-scan pipeline, signed artifact provenance, penetration test, professional translation certification or legal compliance claim is made.

Docker configuration and CI definitions are supplied but have not run in this environment. The host Docker daemon was inaccessible. WordPress connector logic and packaging are tested in a small API harness; live WordPress activation, multisite, WP REST integration, Plugin Check and WordPress coding-standard checks remain unperformed. Local test logs and precise scope appear in [TEST-RESULTS](../implementation/TEST-RESULTS.md).
