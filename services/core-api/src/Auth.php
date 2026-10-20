<?php
declare(strict_types=1);
namespace SubsidiaryVault;

final class Auth
{
    public function __construct(private Database $db, private Config $config) {}

    public function authenticate(string $header): array
    {
        if (!preg_match('/^Bearer ([A-Za-z0-9._~-]{20,8192})$/D', $header, $m)) { throw new ApiError(401, 'authentication_required'); }
        $token = $m[1];
        if ($this->config->authMode === 'oidc-rs256') { return $this->jwt($token); }
        $credential = $this->db->one('SELECT c.*,u.status FROM credentials c JOIN users u ON u.id=c.user_id WHERE c.hash=?', [hash('sha256', $token)]);
        if (!$credential || $credential['revoked'] || $credential['expires_at'] <= time() || $credential['status'] !== 'active') { throw new ApiError(401, 'invalid_session'); }
        return ['id' => $credential['user_id'], 'mfa' => (bool)$credential['mfa'], 'credential' => $credential['hash']];
    }

    private function jwt(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) { throw new ApiError(401, 'invalid_token'); }
        try {
            $header = json_decode(self::decode($parts[0]), true, 16, JSON_THROW_ON_ERROR);
            $claims = json_decode(self::decode($parts[1]), true, 16, JSON_THROW_ON_ERROR);
            $sig = self::decode($parts[2]);
        } catch (\Throwable) { throw new ApiError(401, 'invalid_token'); }
        $key = $this->config->publicKey !== '' ? @file_get_contents($this->config->publicKey) : false;
        if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || isset($header['crit']) || !$key || openssl_verify($parts[0] . '.' . $parts[1], $sig, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new ApiError(401, 'invalid_signature');
        }
        $aud = $claims['aud'] ?? [];
        $aud = is_array($aud) ? $aud : [$aud];
        if ($this->config->issuer === '' || $this->config->audience === '' || ($claims['iss'] ?? '') !== $this->config->issuer || !in_array($this->config->audience, $aud, true) || !is_int($claims['exp'] ?? null) || $claims['exp'] <= time() || ($claims['nbf'] ?? 0) > time() || !is_string($claims['sub'] ?? null)) {
            throw new ApiError(401, 'invalid_claims');
        }
        $user = $this->db->one('SELECT id,status FROM users WHERE id=?', [$claims['sub']]);
        if (!$user || $user['status'] !== 'active') { throw new ApiError(401, 'unknown_identity'); }
        // MFA is deliberately not inferred from unconfigured provider claims.
        return ['id' => $user['id'], 'mfa' => false, 'credential' => null];
    }

    private static function decode(string $part): string
    {
        $value = base64_decode(strtr($part, '-_', '+/'), true);
        if ($value === false) { throw new \RuntimeException('base64'); }
        return $value;
    }
}
