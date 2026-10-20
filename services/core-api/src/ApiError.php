<?php
declare(strict_types=1);
namespace SubsidiaryVault;

final class ApiError extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
