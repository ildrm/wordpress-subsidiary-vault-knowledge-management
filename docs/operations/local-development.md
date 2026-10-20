# Local development and user guide

Use synthetic data only. The software is an incomplete local implementation of the larger source specification.

## Requirements

PHP 8.3+ CLI with `pdo_sqlite`, `sodium`, `openssl`, `intl`, `mbstring` and `Phar`; Python 3.10+ for project tools/tests; Node.js for JavaScript syntax checks; Make is optional. No Composer or npm packages are required. Executed environment: macOS ARM64, PHP 8.5.8, Python 3.14.5, Node 26.12.0. CI is configured for PHP 8.3–8.5 but has not run on GitHub here.

From the repository root:

```sh
make setup
make serve
```

Open `http://127.0.0.1:8088`. In another terminal obtain an existing synthetic token:

```sh
php tools/vault.php token tenant-01-custodian
```

Paste it into the console, decline browser password saving, and choose the available subsidiary. Tokens last eight hours, are random for each seed, are hashed in SQLite and are stored raw only in the ignored private `var/data/demo-credentials.json` for local use. The application retains the pasted token in memory only; a reload requires signing in again. `seed` does not replace an existing directory's users, keys or documents.

```sh
php tools/vault.php rotate-demo-tokens
```

This rotates all development credentials without deleting documents or changing memberships. Existing sessions immediately fail. Do not put tokens in URLs, WordPress options, commits, issue reports or command-line arguments to unknown tools.

The server binds loopback. A managed sandbox may require permission to open a loopback socket. The PHP built-in server is for development; do not expose it to the internet.

## Synthetic identities and daily workflows

Initial tenant IDs are `tenant-01` through `tenant-10`. Each has eight users with the following suffixes; prefix each with the tenant ID and a hyphen. All are synthetic and have independently scoped memberships.

| Suffix | Intended test use |
|---|---|
| `administrator` | Manage eligible settings, groups and roles/memberships; does not automatically see seeded documents |
| `custodian` | Own the seeded document, add text versions, share existing document rights, request reviews and manage preservation within policy |
| `reviewer` | Read/download/comment and independently approve a granted document |
| `records`, `records2` | View permitted documents, declare records/place holds, independently release another officer's hold or approve disposition |
| `viewer` | Metadata/view permission only; download denied |
| `contributor` | Create own documents and edit explicitly granted materials |
| `lowclearance` | Demonstrate classification filtering; seeded confidential documents remain unavailable |

`holding-governor` belongs only to the holding control plane. Group oversight shows aggregate names, states, counts and bytes. Suspension/reactivation is available there; it never deletes records. Closing a tenant is deliberately rejected pending a governed closure implementation.

Documents and knowledge articles accept nonempty UTF-8 text, up to 8 MiB/version. Binary formats are rejected. Create a title/classification/category and text, or select a UTF-8 text file. Each committed content version is encrypted independently. Open a permitted document to view versions, comments, effective actions and permitted governance forms. Download is separately authorized from view.

To review: sign in as custodian, open the seeded document, submit the current version. Sign out and use `tenant-01-reviewer` to approve/reject that pinned version. Neither the owner, version author nor requester can approve their own material. Approved content is immutable in this build; rejected content can receive a new draft version. Qualified electronic signatures are not provided.

To test holds: use `records` to place a hold and `records2` to release it. The creator cannot release their own hold. Holds block content edits and disposition/deletion. Retention can only be extended. An approved version can be declared an immutable record, which cannot be unlocked through the API. No physical purge exists.

To soft-delete a non-record without active hold or retention: an authorized custodian requests disposition; a separate records officer approves its request ID; then the entitled deleting actor submits deletion. The system checks current preservation again. Soft deletion hides all retrieval paths but does not erase objects or keys. There is no recycle/restore UI in this build.

## Worker, diagnostics and audit

```sh
php tools/worker.php
php tools/vault.php audit
php tools/diagnostics.php
```

Run the worker repeatedly until it reports zero events; each run handles at most 100. It sends local inbox notifications only, after current view authorization. It does not send emails, chat messages or external webhooks. Diagnostics contain runtime/schema/version and extension flags, not user data, paths, tokens, keys or document bodies. Audit checks verify local hash chains, not independent archival integrity.

## Configuration

`.env.example` documents variables. There is no dotenv loader; export variables in your shell or service environment. Defaults are `SV_ENV=local`, `SV_AUTH_MODE=development`, `SV_DATA_DIR=<repo>/var/data`, `SV_KEY_DIR=<repo>/var/keys`. New private directories use 0700 and new keys/objects use 0600. Choose private absolute directories; do not place data or keys under any web document root.

Any non-`local` `SV_ENV` is rejected before directory creation. The `oidc-rs256` adapter reads a pinned PEM public key plus exact issuer/audience. It accepts only pre-provisioned independent user IDs. It provides no browser login, provider metadata discovery, JWK rotation, refresh, SAML, SCIM, user provisioning or trusted MFA claim mapping. Accordingly, privileged MFA operations remain denied through that adapter. It is not production federation.

Fixed local policy: 8 MiB per text version; 100 MiB default tenant quota; 120 requests/principal/tenant/minute. Settings permit a 1 MiB–1 GiB quota. Storage accounting includes all historical and soft-deleted versions because nothing is physically purged. Tenant settings cannot disable encryption, explicit denials, privileged MFA or audit, and cannot enable external sharing/AI.

## Docker alternative

```sh
docker compose -f infrastructure/dev/compose.yaml up --build
docker compose -f infrastructure/dev/compose.yaml exec vault php tools/vault.php token tenant-01-custodian
```

The composition maps loopback port 8088 and keeps data and keys in separate local volumes, mounted into the same local process. It uses a mutable development base-image tag and has not been built/run in this environment because Docker access was unavailable. It does not implement the enterprise PostgreSQL/S3/KMS/IdP architecture. Never delete volumes as a routine troubleshooting step; retained data and keys could be lost.
