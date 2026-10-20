# Cryptographic development profiles

## Managed local document storage

For each document version: generate a fresh 32-byte data-encryption key and 24-byte nonce using PHP's CSPRNG. Encrypt through `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt`. Additional authenticated data is the exact UTF-8 JSON emitted by `Crypto::context`, in this fixed insertion order: suite, tenant, document, version, classification. The suite is `svkms-local-xchacha20poly1305-v1`. A different 24-byte nonce wraps the data key under the tenant's 32-byte local key, with `wrap:` plus the exact context as authenticated data. The envelope stores suite, base64 wrap nonce and wrapped key. The object contains nonce followed by ciphertext/tag. Fresh versions never reuse an object ID or envelope.

Tenant keys are separate 0600 files whose names are SHA-256 of immutable tenant IDs; directory permissions are 0700 when created. This file provider is not an HSM/KMS or cryptographic separation from the running service. Keys are not exposed through the API or stored in WordPress. Missing keys fail closed. No automatic regeneration happens when reading missing keys. Key rotation, external KMS identity/policy, key inventory and recovery quorum remain unimplemented.

SHA-256 plaintext digests are stored for authorized integrity checking; equality and metadata leakage are disclosed. Document classification is fixed after creation because it participates in authenticated context. Titles and metadata are independently scoped but not encrypted in this managed local DB. Plaintext exists in service/browser memory for authorized requests. `sodium_memzero` reduces some buffer lifetimes but does not establish that every PHP/runtime/browser copy has been securely erased.

## Offline file utility and backup wrapper

`FileCipher` uses libsodium secretstream XChaCha20-Poly1305, 64 KiB plaintext chunks, the eight-byte magic `SVLOCAL1`, a Sodium stream header and length-prefixed authenticated frames. Every frame binds `svkms-offline-file-v1:` followed by the caller's exact context string. The final tag is mandatory; trailing bytes, truncation, wrong keys and wrong context fail. Decryption writes a private temporary file and publishes it only after authenticating the complete stream. Atomic hard-link publication refuses overwrite. File size/access timing leak. Keys are raw random 32-byte files and must be secured separately; no password-derived key or keychain integration is supplied.

Backups use the context `svkms-local-backup-v1`; the encrypted tar contains a consistent SQLite snapshot, referenced immutable ciphertext objects and a SHA-256 manifest. It omits tenant keys and revokes development credentials in the snapshot. The manifest is authenticated by the outer encrypted stream; it is not independently digitally signed. Restore accepts only a new directory and a strict allowlist of paths, verifies hashes and revokes sessions again. This proves local isolated restoration only, not safe reconnection with current permissions or production backup resilience.

## Assurance limits

The primitive binding is checked against an upstream public Sodium known-answer vector; stream tests include boundary sizes, wrong key/AAD and truncation. Application protocol, recipient key exchange, independently authenticated client delivery, signed updates, recovery ceremonies, FIPS requirements and any claimed zero-knowledge profile require additional engineering and external assessment. See [vector provenance](../../packages/crypto-test-vectors/provenance.md).
