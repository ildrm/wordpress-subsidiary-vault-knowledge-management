<?php
declare(strict_types=1);
namespace SubsidiaryVault;

final class Audit
{
    public function __construct(private Database $db) {}

    public function append(string $tenant, string $actor, string $action, string $resource, string $outcome, string $reason, string $request): void
    {
        $previous = $this->db->one('SELECT hash FROM audit WHERE tenant_id=? ORDER BY sequence DESC LIMIT 1', [$tenant])['hash'] ?? str_repeat('0',64);
        $event = [$tenant,$actor,$action,$resource,$outcome,$reason,time(),$request,$previous];
        $hash = hash('sha256', json_encode($event, JSON_THROW_ON_ERROR));
        $this->db->query('INSERT INTO audit(tenant_id,actor_id,action,resource_id,outcome,reason,at,request_id,previous_hash,hash) VALUES (?,?,?,?,?,?,?,?,?,?)', [...$event,$hash]);
    }

    public function verify(string $tenant): bool
    {
        $previous = str_repeat('0',64);
        foreach ($this->db->all('SELECT * FROM audit WHERE tenant_id=? ORDER BY sequence', [$tenant]) as $row) {
            $event = [$row['tenant_id'],$row['actor_id'],$row['action'],$row['resource_id'],$row['outcome'],$row['reason'],(int)$row['at'],$row['request_id'],$row['previous_hash']];
            if ($row['previous_hash'] !== $previous || !hash_equals($row['hash'],hash('sha256',json_encode($event,JSON_THROW_ON_ERROR)))) { return false; }
            $previous = $row['hash'];
        }
        return true;
    }
}
