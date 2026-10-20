<?php
declare(strict_types=1);
namespace SubsidiaryVault;

/** Local envelope encryption adapter. NOT an HSM/KMS, NOT a zero-knowledge protocol. */
final class Crypto
{
    public function __construct(private string $keyDir) {}

    public function provision(string $tenant): void
    {
        if (getenv('SV_KEY_PROVIDER')==='openbao-transit') { (new KeyService())->verify($tenant);return; }
        $path = $this->path($tenant);
        if (is_file($path)) { return; }
        $handle = fopen($path, 'x');
        if (!$handle) { throw new \RuntimeException('key_creation_failed'); }
        chmod($path, 0600);
        try { if (fwrite($handle, random_bytes(32)) !== 32) { throw new \RuntimeException('key_creation_failed'); } } finally { fclose($handle); }
    }

    private function path(string $tenant): string { return $this->keyDir . '/' . hash('sha256', $tenant) . '.key'; }
    private function key(string $tenant): string
    {
        $key = @file_get_contents($this->path($tenant));
        if ($key === false || strlen($key) !== 32) { throw new ApiError(503, 'key_unavailable'); }
        return $key;
    }

    public static function context(string $tenant, string $document, int $version, int $classification): string
    {
        return json_encode(['suite'=>'svkms-local-xchacha20poly1305-v1','tenant'=>$tenant,'document'=>$document,'version'=>$version,'classification'=>$classification], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function encrypt(string $tenant, string $context, #[\SensitiveParameter] string $plaintext): array
    {
        $dek = random_bytes(32);
        $nonce = random_bytes(24);
        $wrapNonce = random_bytes(24);
        try {
            $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $dek);
            if (getenv('SV_KEY_PROVIDER')==='openbao-transit') {
                return ['ciphertext'=>$nonce.$cipher,'envelope'=>json_encode(['suite'=>'svkms-transit-xchacha20poly1305-v1','provider'=>'openbao-transit','key_name'=>KeyService::name($tenant),'wrapped'=>(new KeyService())->wrap($tenant,$context,$dek)],JSON_THROW_ON_ERROR)];
            }
            $wrapped = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($dek, 'wrap:' . $context, $wrapNonce, $this->key($tenant));
            return ['ciphertext'=>$nonce . $cipher,'envelope'=>json_encode(['suite'=>'svkms-local-xchacha20poly1305-v1','nonce'=>base64_encode($wrapNonce),'wrapped'=>base64_encode($wrapped)], JSON_THROW_ON_ERROR)];
        } finally { sodium_memzero($dek); }
    }

    public function decrypt(string $tenant, string $context, string $cipher, string $envelope): string
    {
        try {
            $e = json_decode($envelope, true, 8, JSON_THROW_ON_ERROR);
            if (getenv('SV_KEY_PROVIDER')==='openbao-transit') {
                if (($e['suite']??'')!=='svkms-transit-xchacha20poly1305-v1'||($e['provider']??'')!=='openbao-transit'||($e['key_name']??'')!==KeyService::name($tenant)) { throw new ApiError(503,'key_provider_mismatch'); }
                $dek=(new KeyService())->unwrap($tenant,$context,$e['wrapped']);
                try { $plaintext=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($cipher,24),$context,substr($cipher,0,24),$dek); } finally { sodium_memzero($dek); }
                if ($plaintext===false) { throw new ApiError(503,'integrity_check_failed'); }return $plaintext;
            }
            if (($e['suite'] ?? '') !== 'svkms-local-xchacha20poly1305-v1') { throw new \RuntimeException('suite'); }
            $dek = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(base64_decode($e['wrapped'], true), 'wrap:' . $context, base64_decode($e['nonce'], true), $this->key($tenant));
            if ($dek === false) { throw new \RuntimeException('envelope'); }
            try { $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($cipher,24), $context, substr($cipher,0,24), $dek); } finally { sodium_memzero($dek); }
            if ($plaintext === false) { throw new \RuntimeException('ciphertext'); }
            return $plaintext;
        } catch (ApiError $e) { throw $e; } catch (\Throwable) { throw new ApiError(503, 'integrity_check_failed'); }
    }
}
