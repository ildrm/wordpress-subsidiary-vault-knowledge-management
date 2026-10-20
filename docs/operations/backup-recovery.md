# Backup and isolated restoration

Local functional recovery only. No independent backup account, immutable/offline provider, trustee recovery, current-policy reconciliation or promised RTO/RPO is supplied.

Generate a separate offline file/backup key in a private location outside the repository:

```sh
php apps/trusted-vault-client/vault-client.php keygen /private/tmp/svkms-backup.key
php tools/backup.php /private/tmp/svkms-backup.key /private/tmp/svkms-snapshot.svbackup
php tools/restore.php /private/tmp/svkms-backup.key /private/tmp/svkms-snapshot.svbackup /private/tmp/svkms-isolated-restore
```

These sample paths must not already exist. The backup captures a consistent SQLite snapshot and only referenced immutable ciphertext objects; authenticates an internal integrity manifest through the outer secretstream; omits tenant keys and raw demo credentials; revokes sessions in the snapshot. The manifest is not a detached digital signature. Snapshot creation does not freeze future application writes, and those later writes are outside that recovery point.

Tenant encryption keys under `SV_KEY_DIR` must be protected and backed up separately under distinct operator custody. The backup encryption key is also necessary and must not be stored alongside ciphertext. Losing either required key domain can make recovery impossible. Do not claim the resulting local files are independently immutable or ransomware-safe.

Restore refuses existing destinations, authenticates the whole encrypted stream before publishing, validates allowlisted paths and per-file hashes, and revokes all old sessions. It writes `RESTORE-ISOLATED.txt`. Keep the restored system isolated. Compare restored identities, current revocations, ACLs, memberships, holds, retention, keys and policy against authoritative current records before any reconnection. The software does not automate that reconciliation. Historical ACLs are still historical; revoking old sessions alone does not solve a future identity-provider reconnection.

The test suite restores all ten synthetic documents, validates audit chains, confirms sessions are revoked and decrypts a sample with the separately held original tenant key. Missing keys fail closed. That local automated exercise is not a production DR drill or evidence of agreed geographic resilience.

For key loss, stop affected decrypting workflows; preserve ciphertext and evidence; determine the affected tenant and exact key generation; involve the designated human custodians; restore only an authenticated original key from approved custody. Never generate a replacement at an existing key path and claim it recovers old content. No production key recovery ceremony is implemented.
