<?php

declare(strict_types=1);

namespace App\Shared\Audit;

interface AuditTrail
{
    /** @return array<string, mixed>|null */
    public function findByRequestId(string $requestId): ?array;

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(
        ?int $actorId,
        string $action,
        string $entityType,
        string $entityId,
        ?string $requestId,
        ?array $before,
        ?array $after,
        ?string $clientIp,
    ): void;
}
