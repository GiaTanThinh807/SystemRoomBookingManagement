<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Bookings;

use App\Bootstrap\App;
use App\Bootstrap\SessionBootstrap;
use App\Http\Request;
use App\Modules\Inventory\Application\InventoryReservationService;
use App\Shared\Database\TransactionManager;
use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BookingHttpFlowTest extends TestCase
{
    private App $app;
    private PDO $pdo;
    private SessionBootstrap $session;
    private TransactionManager $transactions;
    private InventoryReservationService $inventory;
    private ?string $clientRequestId = null;
    private ?string $previewTokenHash = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::boot(dirname(__DIR__, 4));
        $container = $this->app->container();
        $this->pdo = $container->get(PDO::class);
        $this->session = $container->get(SessionBootstrap::class);
        $this->transactions = $container->get(TransactionManager::class);
        $this->inventory = $container->get(InventoryReservationService::class);
        $this->session->start();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $bookingId = null;
            $previewId = null;
            if ($this->clientRequestId !== null) {
                $statement = $this->pdo->prepare(
                    'SELECT DatChoID, XemTruocDatChoID FROM DatCho WHERE ClientRequestID = ?'
                );
                $statement->execute([$this->clientRequestId]);
                $row = $statement->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    $bookingId = (int) $row['DatChoID'];
                    $previewId = (int) $row['XemTruocDatChoID'];
                }
            }
            if ($bookingId !== null) {
                $this->transactions->run(function () use ($bookingId): void {
                    $this->inventory->releaseActive($bookingId, 'TEST_CLEANUP');
                    $this->pdo->exec('DELETE FROM NhatKyHeThong WHERE LoaiDoiTu = \'DatCho\' AND DoiTuID = ' . $bookingId);
                    $this->pdo->exec('DELETE FROM ThanhToan WHERE DatChoID = ' . $bookingId);
                    $this->pdo->exec('DELETE gt FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID = gt.HangMucDatChoID WHERE hm.DatChoID = ' . $bookingId);
                    $this->pdo->exec('DELETE gd FROM GiaDemDatCho gd INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID = gd.HangMucDatChoID WHERE hm.DatChoID = ' . $bookingId);
                    $this->pdo->exec('DELETE FROM HangMucDatCho WHERE DatChoID = ' . $bookingId);
                    $this->pdo->exec('DELETE FROM DatCho WHERE DatChoID = ' . $bookingId);
                });
            }
            if ($previewId !== null) {
                $this->pdo->prepare('DELETE FROM XemTruocDatCho WHERE XemTruocDatChoID = ?')->execute([$previewId]);
            } elseif ($this->previewTokenHash !== null) {
                $this->pdo->prepare('DELETE FROM XemTruocDatCho WHERE TokenHash = ?')->execute([$this->previewTokenHash]);
            }
        }
        if (isset($this->session) && session_status() === PHP_SESSION_ACTIVE) {
            $this->session->destroy();
        }
        parent::tearDown();
    }

    public function test_customer_confirms_booking_and_opens_snapshot_confirmation_page(): void
    {
        $this->loginCustomer();
        [$criteria, $card] = $this->searchSelection();
        $allocation = $card['representative'];
        self::assertSame('PAY_AT_PROPERTY', $allocation['paymentTiming']);

        $previewResponse = $this->app->handle(new Request('POST', '/previews', body: [
            '_token' => $this->session->csrfToken(),
            'propertySlug' => $card['property']['slug'],
            'criteria' => json_encode($criteria, JSON_THROW_ON_ERROR),
            'paymentTiming' => $allocation['paymentTiming'],
            'selectionFingerprint' => $allocation['selectionFingerprint'],
            'selected' => $this->selected($allocation['rooms']),
        ], requestId: 'phase9-http-preview'));
        self::assertSame(303, $previewResponse->status());
        $rawToken = $_SESSION['_checkout_preview_token'] ?? null;
        self::assertIsString($rawToken);
        $this->previewTokenHash = hash('sha256', $rawToken);

        $checkout = $this->app->handle(new Request('GET', '/checkout', requestId: 'phase9-http-checkout'));
        self::assertSame(200, $checkout->status());
        self::assertStringContainsString('data-booking-form', $checkout->body());
        self::assertStringContainsString('name="bookerPhone"', $checkout->body());
        self::assertStringContainsString('Xác nhận đặt phòng', $checkout->body());

        $this->clientRequestId = $this->uuid();
        $created = $this->app->handle(new Request('POST', '/bookings', body: [
            '_token' => $this->session->csrfToken(),
            'clientRequestId' => $this->clientRequestId,
            'bookerName' => 'Khách HTTP Giai đoạn 9',
            'bookerEmail' => 'phase9-http@example.test',
            'bookerPhone' => '0901234567',
            'specialRequest' => 'Tầng cao nếu có thể',
            'termsAccepted' => '1',
        ], requestId: 'phase9-http-booking'));

        self::assertSame(303, $created->status());
        self::assertMatchesRegularExpression(
            '#^http://localhost/bookings/SB[A-F0-9]{20}/confirmation$#',
            $created->headers()['Location'],
        );
        self::assertArrayNotHasKey('_checkout_preview_token', $_SESSION);
        $path = (string) parse_url($created->headers()['Location'], PHP_URL_PATH);
        $confirmation = $this->app->handle(new Request('GET', $path, requestId: 'phase9-http-confirmation'));
        self::assertSame(200, $confirmation->status());
        self::assertSame('no-store, private', $confirmation->headers()['Cache-Control']);
        self::assertStringContainsString('Chỗ nghỉ của bạn đã được đặt', $confirmation->body());
        self::assertStringContainsString('Thanh toán tại cơ sở', $confirmation->body());
        self::assertStringNotContainsString($rawToken, $confirmation->body());
    }

    public function test_booking_route_requires_login_and_csrf(): void
    {
        $anonymous = $this->app->handle(new Request(
            'POST',
            '/bookings',
            body: ['_token' => $this->session->csrfToken()],
            headers: ['accept' => 'application/json'],
            requestId: 'phase9-anonymous',
        ));
        self::assertSame(401, $anonymous->status());

        $this->loginCustomer();
        $withoutCsrf = $this->app->handle(new Request(
            'POST',
            '/bookings',
            headers: ['accept' => 'application/json'],
            requestId: 'phase9-no-csrf',
        ));
        self::assertSame(403, $withoutCsrf->status());
    }

    private function loginCustomer(): void
    {
        $response = $this->app->handle(new Request('POST', '/auth/login', body: [
            '_token' => $this->session->csrfToken(),
            'identifier' => 'customer0001@example.test',
            'password' => 'Ntu@123456',
        ], requestId: 'phase9-login'));
        self::assertSame(303, $response->status());
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function searchSelection(): array
    {
        $query = [
            'destination' => 'HCM',
            'checkIn' => '2026-12-10',
            'checkOut' => '2026-12-12',
            'rooms' => '[{"roomOrder":1,"adults":2,"childAges":[]}]',
            'paymentTiming' => 'PAY_AT_PROPERTY',
        ];
        $response = $this->app->handle(new Request(
            'GET',
            '/search',
            $query,
            headers: ['accept' => 'application/json'],
            requestId: 'phase9-search',
        ));
        self::assertSame(200, $response->status());
        $payload = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        return [$payload['criteria'], $payload['results'][0]];
    }

    /** @param list<array<string, mixed>> $rooms */
    private function selected(array $rooms): string
    {
        return implode(',', array_map(
            static fn (array $room): string => $room['roomOrder'] . ':' . $room['roomTypeId'] . ':' . $room['productId'],
            $rooms,
        ));
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        );
    }
}
