<?php
declare(strict_types=1);
namespace SubsidiaryVault;

final class Database
{
    public readonly \PDO $pdo;
    public readonly string $driver;
    private bool $transaction = false;

    public function __construct(string $file)
    {
        $postgres = str_starts_with($file, 'pgsql:');
        $this->pdo = new \PDO($postgres ? $file : 'sqlite:' . $file, $postgres ? (getenv('SV_DATABASE_USER') ?: null) : null, $postgres ? Secrets::optional('SV_DATABASE_PASSWORD') : null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_EMULATE_PREPARES => false]);
        $this->driver = $postgres ? 'pgsql' : 'sqlite';
        if ($postgres) { $this->pdo->exec("SET statement_timeout='15s'; SET lock_timeout='5s'; SET idle_in_transaction_session_timeout='30s'"); }
        else { $this->pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL'); }
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function one(string $sql, array $params = []): ?array
    {
        return $this->query($sql, $params)->fetch() ?: null;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function begin(): void
    {
        $this->pdo->exec($this->driver === 'pgsql' ? 'BEGIN' : 'BEGIN IMMEDIATE'); $this->transaction = true;
        // Preserve the existing serialized policy/mutation contract across API and worker processes.
        // This conservative lock is a measured scalability limitation, not a per-tenant throughput claim.
        if ($this->driver === 'pgsql') { $this->pdo->exec('SELECT pg_advisory_xact_lock(1937139787)'); }
    }
    public function context(string $principal, string $tenant = ''): void
    {
        if ($this->driver === 'pgsql') {
            if (!$this->transaction) { throw new \LogicException('tenant_context_requires_transaction'); }
            $this->query("SELECT set_config('svkms.principal',?,true),set_config('svkms.tenant',?,true)", [$principal,$tenant]);
        }
    }
    public function schemaVersion(): int
    {
        $exists=$this->driver==='pgsql' ? $this->one("SELECT to_regclass('public.schema_migrations') AS name") : $this->one("SELECT name FROM sqlite_master WHERE type='table' AND name='schema_migrations'");
        return !empty($exists['name']) ? (int)$this->one('SELECT MAX(version) AS version FROM schema_migrations')['version'] : 0;
    }
    public function commit(): void { $this->pdo->exec('COMMIT'); $this->transaction = false; }
    public function rollback(): void { if ($this->transaction) { $this->pdo->exec('ROLLBACK'); $this->transaction = false; } }
    public static function id(): string { return bin2hex(random_bytes(16)); }
}
