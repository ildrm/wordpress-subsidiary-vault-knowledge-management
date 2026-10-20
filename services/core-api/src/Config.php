<?php
declare(strict_types=1);
namespace SubsidiaryVault;

final class Config
{
    public readonly string $dataDir;
    public readonly string $keyDir;
    public readonly string $authMode;
    public readonly string $issuer;
    public readonly string $audience;
    public readonly string $publicKey;
    public const MAX_FILE_BYTES = 8 * 1024 * 1024;

    public function __construct(?string $dataDir = null, ?string $keyDir = null)
    {
        if ((getenv('SV_ENV') ?: 'local') !== 'local') {
            throw new \RuntimeException('production_disabled: external KMS, identity, data plane and release gates require validation');
        }
        $this->dataDir = $dataDir ?? (getenv('SV_DATA_DIR') ?: dirname(__DIR__, 3) . '/var/data');
        $this->keyDir = $keyDir ?? (getenv('SV_KEY_DIR') ?: dirname(__DIR__, 3) . '/var/keys');
        $this->authMode = getenv('SV_AUTH_MODE') ?: 'development';
        if (!in_array($this->authMode, ['development', 'oidc-rs256'], true)) { throw new \RuntimeException('invalid_auth_mode'); }
        $this->issuer = getenv('SV_OIDC_ISSUER') ?: '';
        $this->audience = getenv('SV_OIDC_AUDIENCE') ?: '';
        $this->publicKey = getenv('SV_OIDC_PUBLIC_KEY') ?: '';
        foreach ([$this->dataDir, $this->keyDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0700, true)) { throw new \RuntimeException('private_directory_unavailable'); }
        }
    }
}
