<?php
declare(strict_types=1);
namespace SubsidiaryVault;

final class Policy
{
    public const DOCUMENT_ACTIONS = ['view','download','edit','comment','share','delete','approve','records','hold','audit'];
    public const CAPABILITIES = ['create','members','roles','groups','settings','audit','view','download','edit','comment','share','delete','approve','records','hold'];
    private const STEP_UP = ['approve','records','hold','members','roles'];

    public function __construct(private Database $db) {}

    public function membership(array $actor, string $tenant): array
    {
        $m = $this->db->one('SELECT m.*,r.capabilities,t.status AS tenant_status FROM memberships m JOIN roles r ON r.tenant_id=m.tenant_id AND r.id=m.role_id JOIN tenants t ON t.id=m.tenant_id WHERE m.tenant_id=? AND m.user_id=?', [$tenant, $actor['id']]);
        if (!$m || $m['status'] !== 'active' || $m['tenant_status'] !== 'active' || ($m['expires_at'] !== null && $m['expires_at'] <= time())) { throw new ApiError(403, 'tenant_access_denied'); }
        $m['capabilities'] = json_decode($m['capabilities'], true, 16, JSON_THROW_ON_ERROR);
        return $m;
    }

    public function require(array $actor, string $tenant, string $action, ?array $doc = null): void
    {
        $reason = $this->reason($actor, $tenant, $action, $doc);
        if ($reason !== null) { throw new ApiError(403, $reason); }
    }

    public function reason(array $actor, string $tenant, string $action, ?array $doc = null): ?string
    {
        try { $m = $this->membership($actor, $tenant); } catch (ApiError $e) { return $e->reason; }
        if (!in_array($action, $m['capabilities'], true)) { return 'capability_denied'; }
        if (in_array($action, self::STEP_UP, true) && !$actor['mfa']) { return 'step_up_required'; }
        if (!$doc) { return null; }
        if ($doc['tenant_id'] !== $tenant || $doc['state'] === 'deleted') { return 'resource_unavailable'; }
        if ($m['clearance'] < $doc['classification']) { return 'classification_denied'; }
        $rows = $this->db->all('SELECT * FROM acl WHERE tenant_id=? AND document_id=? AND action=? AND (expires_at IS NULL OR expires_at>?)', [$tenant,$doc['id'],$action,time()]);
        $groups = array_column($this->db->all('SELECT group_id FROM group_members WHERE tenant_id=? AND user_id=?', [$tenant,$actor['id']]), 'group_id');
        $allow = false;
        foreach ($rows as $rule) {
            $matches = match ($rule['subject_type']) {
                'user' => $rule['subject_id'] === $actor['id'],
                'role' => $rule['subject_id'] === $m['role_id'],
                'group' => in_array($rule['subject_id'], $groups, true),
                default => false,
            };
            if ($matches && $rule['effect'] === 'deny') { return 'explicit_deny'; }
            if ($matches && $rule['effect'] === 'allow') { $allow = true; }
        }
        if (!$allow) { return 'acl_denied'; }
        if ($action === 'delete') {
            if ($doc['is_record'] || $doc['retain_until'] > time() || $this->db->one('SELECT id FROM holds WHERE tenant_id=? AND document_id=? AND released_at IS NULL', [$tenant,$doc['id']])) { return 'preservation_required'; }
        }
        return null;
    }
}
