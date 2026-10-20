# Security policy

This repository is an incomplete development build for synthetic data. No production version, commissioned security assessor or supported production deployment exists. See [the threat model](docs/security/threat-model.md) and [release gates](docs/release/READINESS.md).

Do not include tokens, encryption keys, actual documents, personal information, private URLs or unredacted snapshots in public reports. For a non-sensitive defect, use the repository issue tracker with a synthetic reproduction and relevant non-secret request IDs. If private vulnerability reporting is enabled by the repository owner, use it for sensitive exploit details. A verified private security contact and response-time commitments have not been configured; do not assume an invented address is monitored. The owner must publish that contact before real deployment.

Track each finding's severity, affected version/requirements, owner, reproduction, fix, regression evidence and disclosure decision. The existing CI definition is not a full SAST/SCA/secret/DAST pipeline. No current claim of zero vulnerabilities, complete compliance, audited cryptography or production readiness is made.
