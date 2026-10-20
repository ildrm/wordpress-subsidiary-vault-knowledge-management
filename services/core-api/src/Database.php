<?php
declare(strict_types=1);
namespace SubsidiaryVault;

final class Database
{
    public readonly \PDO $pdo;
    private bool $transaction = false;

    public function __construct(string $file)
    {
        $this->pdo = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL');
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

    public function begin(): void { $this->pdo->exec('BEGIN IMMEDIATE'); $this->transaction = true; }
    public function commit(): void { $this->pdo->exec('COMMIT'); $this->transaction = false; }
    public function rollback(): void { if ($this->transaction) { $this->pdo->exec('ROLLBACK'); $this->transaction = false; } }
    public static function id(): string { return bin2hex(random_bytes(16)); }
}
