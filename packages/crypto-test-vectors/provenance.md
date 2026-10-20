# Primitive test-vector provenance

The known-answer vector in `tests/crypto_backup_test.php` is transcribed from the first case in libsodium’s [upstream test source](https://github.com/jedisct1/libsodium/blob/master/test/default/aead_xchacha20poly1305.c) and [expected output](https://github.com/jedisct1/libsodium/blob/master/test/default/aead_xchacha20poly1305.exp), inspected 2026-10-20. It tests PHP’s Sodium primitive binding, not the application protocol or signed-client assurance. Values are fixed public test data; they are never used as runtime keys.

File utility tests cover empty, boundary-sized and multi-frame files, wrong key/AAD, truncation, no overwrite and failure cleanup. Managed-object tests cover context/version/classification binding, corruption and fresh envelopes. Recipient-directory substitution, quorum recovery, independent client signing and malicious updates remain unimplemented acceptance cases.
