# Cryptographic development profiles

## Managed local document storage

For each document version: generate a fresh 32-byte data-encryption key and 24-byte nonce using PHP's CSPRNG. Encrypt through `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt`. Additional authenticated data is the exact UTF-8 JSON emitted by `Crypto::context`, in this fixed insertion order: suite, tenant, document, version, classification. The suite is `svkms-local-xchacha20poly1305-v1`. A different 24-byte nonce wraps the data key under the tenant's 32-byte local key, with `wrap:` plus the exact context as authenticated data. The envelope stores suite, base64 wrap nonce and wrapped key. The object contains nonce followed by ciphertext/tag. Fresh versions never reuse an object ID or envelope.

Tenant keys are separate 0600 files whose names are SHA-256 of immutable tenant IDs; directory permissions are 0700 when created. This file provider is not an HSM/KMS or cryptographic separation from the running service. Keys are not exposed through the API or stored in WordPress. Missing keys fail closed. No automatic regeneration happens when reading missing keys. The optional external profile below adds transit wrapping/rotation; local key rotation/migration, full key inventory and recovery quorum remain unimplemented.

SHA-256 plaintext digests are stored for authorized integrity checking; equality and metadata leakage are disclosed. Document classification is fixed after creation because it participates in authenticated context. Titles and metadata are independently scoped but not encrypted in this managed local DB. Plaintext exists in service/browser memory for authorized requests. `sodium_memzero` reduces some buffer lifetimes but does not establish that every PHP/runtime/browser copy has been securely erased.

## Offline file utility and backup wrapper

`FileCipher` uses libsodium secretstream XChaCha20-Poly1305, 64 KiB plaintext chunks, the eight-byte magic `SVLOCAL1`, a Sodium stream header and length-prefixed authenticated frames. Every frame binds `svkms-offline-file-v1:` followed by the caller's exact context string. The final tag is mandatory; trailing bytes, truncation, wrong keys and wrong context fail. Decryption writes a private temporary file and publishes it only after authenticating the complete stream. Atomic hard-link publication refuses overwrite. File size/access timing leak. Keys are raw random 32-byte files and must be secured separately; no password-derived key or keychain integration is supplied.

Backups use the context `svkms-local-backup-v1`; the encrypted tar contains a consistent SQLite snapshot, referenced immutable ciphertext objects and a SHA-256 manifest. It omits tenant keys and revokes development credentials in the snapshot. The manifest is authenticated by the outer encrypted stream; it is not independently digitally signed. Restore accepts only a new directory and a strict allowlist of paths, verifies hashes and revokes sessions again. This proves local isolated restoration only, not safe reconnection with current permissions or production backup resilience.

## Assurance limits

The primitive binding is checked against an upstream public Sodium known-answer vector; stream tests include boundary sizes, wrong key/AAD and truncation. Application protocol, recipient key exchange, independently authenticated client delivery, signed updates, recovery ceremonies, FIPS requirements and any claimed zero-knowledge profile require additional engineering and external assessment. See [vector provenance](../../packages/crypto-test-vectors/provenance.md).

## External managed candidate

`openbao-transit` uses envelope suite `svkms-transit-xchacha20poly1305-v1`. A fresh random file DEK/nonce still encrypts bytes with Sodium and exact `Crypto::context` AAD; Bao transit wraps the DEK with the `wrap:` context and stores a versioned `vault:v...` wrapped value. The key name is bound to SHA-256 of tenant ID. Per-operation policy checks reject exportable, plaintext-backup-enabled or deletion-enabled roots and require aes256-gcm96. Application credentials may read metadata and encrypt/decrypt only; the reference tests reject root rotation/export and separately verify old envelopes after operator rotation.

Roots do not leave Bao, but the service sees DEKs and authorized file plaintext. This is not zero knowledge, HSM validation or immunity to a compromised decrypting service. The fixture uses dev/unsealed roots without production custody. Provider mismatches, missing keys and outages fail closed; no local fallback or existing-envelope migration is supplied. S3 stores only ciphertext using conditional immutable creation; production deletion denial/object lock and durable backups remain separate unverified controls.

Ed25519 audit checkpoints authenticate a supplied tenant prefix against an independently trusted latest checkpoint/public key. They detect rewrite/truncation of that prefix, not unanchored event loss or rollback of the supposedly latest external checkpoint. Independent immutable custody, scheduling and signer lifecycle are still required.
