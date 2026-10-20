<?php
declare(strict_types=1);
namespace SubsidiaryVault;

final class Migrations
{
    public static function run(Database $db): int
    {
        $current=$db->schemaVersion();
        $directory=dirname(__DIR__) . '/migrations' . ($db->driver==='pgsql'?'/postgres':'');
        foreach (glob($directory . '/*.sql') as $file) {
            $number=(int)basename($file);if ($number<=$current) { continue; }
            $db->begin();
            try { $db->pdo->exec(file_get_contents($file));$db->commit(); } catch (\Throwable $e) { $db->rollback();throw $e; }
            $current=$number;
        }
        return $current;
    }
}
