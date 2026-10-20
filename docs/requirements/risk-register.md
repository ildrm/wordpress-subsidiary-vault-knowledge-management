# Open risk register

All review entries are INTERNAL/AI REVIEW ONLY. Named accountable humans and formal acceptance have not been supplied.

| ID | Risk | Severity | Current containment | Owner / acceptance |
|---|---|---|---|---|
| RISK-01 | Local process can read all managed tenant keys and metadata | Critical for real data | Synthetic local use only; production mode rejected | Platform security/key custodian, unassigned |
| RISK-02 | Missing trusted recipient/client/update protocol | Critical | Zero-knowledge repository mode unavailable; offline utility labeled unsigned/local | Crypto/client assessor and signing custodian, unassigned |
| RISK-03 | Same-host audit can be rewritten/truncated by root | High | Hash-chain/trigger checks cover application paths; no independent integrity claim | Audit/SRE, unassigned |
| RISK-04 | Missing scanner/quarantine and binary processing | High | Reject binary uploads, cap text size; no conversion/editor/inline render | Processing/security owner, unassigned |
| RISK-05 | Real identity/MFA/session lifecycle not integrated | Critical | Development sessions, local-only pinned verifier, no trusted provider MFA mapping | IAM owner, unassigned |
| RISK-06 | Historical restored access/preservation may differ from current authority | Critical | Fresh isolated destination, old sessions revoked, explicit no-reconnect runbook | Identity/legal/records/recovery owners, unassigned |
| RISK-07 | Incomplete localization/keyboard/screen-reader behavior | High | Draft translations labeled; no WCAG/native certification | Localization/accessibility owners, unassigned |
| RISK-08 | No measured production scale, SLO or DR profile | High | Fixed modest local limits; no availability/capacity promise | SRE/capacity owner, unassigned |
| RISK-09 | Untested real WordPress/Docker/CI compatibility | Medium | Syntax/harness/package testing; honest unexecuted checks list | WordPress/platform QA, unassigned |
| RISK-10 | Majority of canonical product not implemented | Critical to project completion | Full ledger, disabled/absent incomplete routes, fail-closed production release gate | Product/delivery sponsor, unassigned |

Release may not be approved by reclassifying unfinished engineering as an accepted risk or by treating this self-review as an independent security assessment. Track concrete remediation, acceptance tests, actual reviewer decisions and revisitation dates for every item.
