<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Bookings;

use App\Bootstrap\App;
use App\Modules\Bookings\Domain\BookingPreview;
use App\Modules\Bookings\Domain\PreviewDraft;
use App\Modules\Bookings\Infrastructure\PreviewRepository;
use App\Shared\Money\Money;
use App\Shared\Security\Hasher;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\TestCase;

final class PreviewRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PreviewRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->pdo->beginTransaction();
        $this->repository = new PreviewRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }

    public function test_stores_only_token_hash_and_reads_versioned_snapshot(): void
    {
        $rawToken = bin2hex(random_bytes(32));
        $hash = (new Hasher())->hashToken($rawToken);
        $createdAt = new DateTimeImmutable('2026-11-01 02:00:00', new DateTimeZone('UTC'));
        $preview = $this->repository->create(new PreviewDraft(
            $hash,
            $this->customerId(),
            $this->propertyId(),
            '2026-12-10',
            '2026-12-12',
            Money::vnd(2_000_000),
            Money::vnd(0),
            Money::vnd(2_000_000),
            ['version' => 1, 'paymentTiming' => 'PAY_AT_PROPERTY', 'rooms' => []],
            str_repeat('a', 64),
            $createdAt->modify('+15 minutes'),
            $createdAt,
        ));

        self::assertSame(BookingPreview::STATUS_VALID, $preview->status);
        self::assertSame(1, $preview->allocationSnapshot['version']);
        self::assertFalse($preview->isExpiredAt($createdAt));
        self::assertTrue($preview->isExpiredAt($createdAt->modify('+15 minutes')));

        $stored = $this->pdo->query(
            'SELECT TokenHash FROM XemTruocDatCho WHERE XemTruocDatChoID = ' . $preview->id
        )->fetchColumn();
        self::assertSame($hash, $stored);
        self::assertNotSame($rawToken, $stored);

        $found = $this->repository->findByTokenHash($hash);
        self::assertNotNull($found);
        self::assertSame($preview->id, $found->id);
    }

    public function test_invalidates_only_a_valid_preview(): void
    {
        $preview = $this->createPreview();

        $this->repository->invalidate($preview->id);
        $invalidated = $this->repository->findByTokenHash(str_repeat('b', 64));

        self::assertNotNull($invalidated);
        self::assertSame(BookingPreview::STATUS_INVALIDATED, $invalidated->status);
        self::assertNull($invalidated->usedAt);
    }

    private function createPreview(): BookingPreview
    {
        $createdAt = new DateTimeImmutable('2026-11-01 02:00:00', new DateTimeZone('UTC'));
        return $this->repository->create(new PreviewDraft(
            str_repeat('b', 64),
            $this->customerId(),
            $this->propertyId(),
            '2026-12-10',
            '2026-12-12',
            Money::vnd(1),
            Money::vnd(1),
            Money::vnd(0),
            ['version' => 1, 'paymentTiming' => 'PAY_ONLINE', 'rooms' => []],
            str_repeat('c', 64),
            $createdAt->modify('+15 minutes'),
            $createdAt,
        ));
    }

    private function customerId(): int
    {
        return (int) $this->pdo->query(
            "SELECT NguoiDungID FROM NguoiDung WHERE VaiTro = 'CUSTOMER' AND TrangThai = 'HOAT_DONG' ORDER BY NguoiDungID LIMIT 1"
        )->fetchColumn();
    }

    private function propertyId(): int
    {
        return (int) $this->pdo->query(
            "SELECT CoSoLuuTruID FROM CoSoLuuTru WHERE TrangThai = 'ACTIVE' ORDER BY CoSoLuuTruID LIMIT 1"
        )->fetchColumn();
    }
}
