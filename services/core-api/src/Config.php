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
    public readonly string $environment;
    public readonly string $database;
    public const MAX_FILE_BYTES = 8 * 1024 * 1024;

    public function __construct(?string $dataDir = null, ?string $keyDir = null)
    {
        $this->environment=getenv('SV_ENV') ?: 'local';
        if (!in_array($this->environment,['local','integration','staging'],true)) {
            throw new \RuntimeException('production_disabled: external KMS, identity, data plane and release gates require validation');
        }
        $this->dataDir = $dataDir ?? (getenv('SV_DATA_DIR') ?: dirname(__DIR__, 3) . '/var/data');
        $this->keyDir = $keyDir ?? (getenv('SV_KEY_DIR') ?: dirname(__DIR__, 3) . '/var/keys');
        $this->database=getenv('SV_DATABASE_DSN') ?: $this->dataDir . '/vault.sqlite';
        $this->authMode = getenv('SV_AUTH_MODE') ?: 'development';
        if (!in_array($this->authMode, ['development', 'oidc-rs256','oidc-introspection'], true)) { throw new \RuntimeException('invalid_auth_mode'); }
        $this->issuer = getenv('SV_OIDC_ISSUER') ?: '';
        $this->audience = getenv('SV_OIDC_AUDIENCE') ?: '';
        $this->publicKey = getenv('SV_OIDC_PUBLIC_KEY') ?: '';
        foreach (['SV_KEY_PROVIDER'=>['local-files','openbao-transit'],'SV_OBJECT_PROVIDER'=>['local-files','s3'],'SV_UPLOAD_PROFILE'=>['text','scanned']] as $name=>$values) {
            $value=getenv($name);if($value!==false&&$value!==''&&!in_array($value,$values,true)){throw new \RuntimeException('unsupported_provider_configuration');}
        }
        if ($this->environment==='staging' && (!str_starts_with($this->database,'pgsql:') || $this->authMode!=='oidc-introspection')) { throw new \RuntimeException('staging_requires_postgres_and_independent_identity'); }
        if ($this->environment==='staging' && (getenv('SV_KEY_PROVIDER')!=='openbao-transit' || getenv('SV_OBJECT_PROVIDER')!=='s3' || getenv('SV_UPLOAD_PROFILE')!=='scanned')) { throw new \RuntimeException('staging_requires_external_keys_objects_scanner'); }
        if ($this->environment==='staging') {
            HttpClient::validateUrl($this->issuer);HttpClient::validateUrl(getenv('SV_KEY_SERVICE_URL')?:'');HttpClient::validateUrl(getenv('SV_S3_ENDPOINT')?:'');
            $roleUser=getenv('SV_DATABASE_USER')?:'';if($roleUser===''||$roleUser==='postgres'){throw new \RuntimeException('restricted_database_identity_required');}
            if(!preg_match('/(?:^|[; ])sslmode=verify-full(?:;|$)/D',$this->database)){throw new \RuntimeException('verified_postgres_tls_required');}
            if(function_exists('posix_geteuid')&&posix_geteuid()===0){throw new \RuntimeException('unprivileged_runtime_required');}
        }
        foreach ([$this->dataDir, $this->keyDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0700, true)) { throw new \RuntimeException('private_directory_unavailable'); }
        }
    }
    public function managedMode(): string { return getenv('SV_KEY_PROVIDER')==='openbao-transit'&&getenv('SV_OBJECT_PROVIDER')==='s3'?'managed-external':'managed-local'; }
}
