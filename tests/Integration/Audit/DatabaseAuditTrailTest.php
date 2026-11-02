<?php

declare(strict_types=1);

namespace Tests\Integration\Audit;

use App\Bootstrap\App;
use App\Shared\Audit\DatabaseAuditTrail;
use PDO;
use Tests\Support\TestCase;

final class DatabaseAuditTrailTest extends TestCase
{
    public function test_records_canonical_before_and_after_state(): void
    {
        $pdo = App::boot(dirname(__DIR__, 3))->container()->get(PDO::class);
        $pdo->beginTransaction();
        try {
            $requestId = 'phase4-' . bin2hex(random_bytes(8));
            (new DatabaseAuditTrail($pdo))->record(
                1,
                'PROPERTY_UPDATED',
                'PROPERTY',
                '28',
                $requestId,
                ['status' => 'DRAFT'],
                ['status' => 'PENDING_REVIEW'],
                '127.0.0.1',
            );

            $statement = $pdo->prepare(
                'SELECT HanhDong, DuLieuTruocJSON, DuLieuSauJSON FROM NhatKyHeThong WHERE RequestID = :requestId'
            );
            $statement->execute(['requestId' => $requestId]);
            $audit = $statement->fetch(PDO::FETCH_ASSOC);

            self::assertIsArray($audit);
            self::assertSame('PROPERTY_UPDATED', $audit['HanhDong']);
            self::assertJsonStringEqualsJsonString('{"status":"DRAFT"}', $audit['DuLieuTruocJSON']);
            self::assertJsonStringEqualsJsonString('{"status":"PENDING_REVIEW"}', $audit['DuLieuSauJSON']);
            $loaded = (new DatabaseAuditTrail($pdo))->findByRequestId($requestId);
            self::assertSame('PROPERTY_UPDATED', $loaded['action'] ?? null);
            self::assertSame(['status' => 'DRAFT'], $loaded['before'] ?? null);
            self::assertSame(['status' => 'PENDING_REVIEW'], $loaded['after'] ?? null);
        } finally {
            $pdo->rollBack();
        }
    }
}
