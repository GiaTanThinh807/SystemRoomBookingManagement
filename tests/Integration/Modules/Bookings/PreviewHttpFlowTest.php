<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Bookings;

use App\Bootstrap\App;
use App\Bootstrap\SessionBootstrap;
use App\Http\Request;
use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PreviewHttpFlowTest extends TestCase
{
    private App $app;
    private PDO $pdo;
    private SessionBootstrap $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::boot(dirname(__DIR__, 4));
        $container = $this->app->container();
        $this->pdo = $container->get(PDO::class);
        $this->pdo->beginTransaction();
        $this->session = $container->get(SessionBootstrap::class);
        $this->session->start();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->session) && session_status() === PHP_SESSION_ACTIVE) {
            $this->session->destroy();
        }
        parent::tearDown();
    }

    public function test_customer_creates_preview_then_opens_checkout_without_token_in_url_or_html(): void
    {
        $this->loginCustomer();
        [$criteria, $card] = $this->searchSelection();
        $allocation = $card['representative'];

        $created = $this->app->handle(new Request('POST', '/previews', body: [
            '_token' => $this->session->csrfToken(),
            'propertySlug' => $card['property']['slug'],
            'criteria' => json_encode($criteria, JSON_THROW_ON_ERROR),
            'paymentTiming' => $allocation['paymentTiming'],
            'selectionFingerprint' => $allocation['selectionFingerprint'],
            'selected' => $this->selected($allocation['rooms']),
        ], requestId: 'preview-browser-create'));

        self::assertSame(303, $created->status());
        self::assertSame('http://localhost/checkout', $created->headers()['Location']);
        self::assertStringNotContainsString('token', strtolower($created->headers()['Location']));

        $checkout = $this->app->handle(new Request(
            'GET',
            '/checkout',
            headers: ['accept' => 'application/json'],
            requestId: 'preview-browser-checkout',
        ));
        self::assertSame(200, $checkout->status());
        self::assertSame('no-referrer', $checkout->headers()['Referrer-Policy']);
        self::assertSame('no-store, private', $checkout->headers()['Cache-Control']);
        self::assertArrayHasKey('Content-Security-Policy', $checkout->headers());
        $payload = json_decode($checkout->body(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['preview']['allocation']['version']);
        self::assertArrayNotHasKey('token', $payload['preview']);

        $rawToken = $_SESSION['_checkout_preview_token'] ?? null;
        self::assertIsString($rawToken);
        self::assertStringNotContainsString($rawToken, $checkout->body());
    }

    public function test_api_returns_raw_token_once_and_accepts_it_only_from_header_for_checkout(): void
    {
        $this->loginCustomer();
        [$criteria, $card] = $this->searchSelection();
        $allocation = $card['representative'];
        $created = $this->app->handle(new Request(
            'POST',
            '/previews',
            body: [
                '_token' => $this->session->csrfToken(),
                'propertySlug' => $card['property']['slug'],
                'criteria' => $criteria,
                'paymentTiming' => $allocation['paymentTiming'],
                'selectionFingerprint' => $allocation['selectionFingerprint'],
                'selected' => $this->selected($allocation['rooms']),
            ],
            headers: ['accept' => 'application/json'],
            requestId: 'preview-api-create',
        ));

        self::assertSame(201, $created->status());
        $payload = json_decode($created->body(), true, 64, JSON_THROW_ON_ERROR);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['token']);
        self::assertSame('no-store', $created->headers()['Cache-Control']);
        self::assertArrayNotHasKey('_checkout_preview_token', $_SESSION);

        $checkout = $this->app->handle(new Request(
            'GET',
            '/checkout',
            headers: ['accept' => 'application/json', 'x-preview-token' => $payload['token']],
            requestId: 'preview-api-checkout',
        ));
        self::assertSame(200, $checkout->status());
        self::assertStringNotContainsString($payload['token'], $checkout->body());
    }

    public function test_preview_routes_require_customer_role_and_csrf(): void
    {
        $anonymous = $this->app->handle(new Request(
            'GET',
            '/checkout',
            headers: ['accept' => 'application/json'],
            requestId: 'preview-anonymous',
        ));
        self::assertSame(401, $anonymous->status());

        $this->loginCustomer();
        $withoutCsrf = $this->app->handle(new Request(
            'POST',
            '/previews',
            headers: ['accept' => 'application/json'],
            requestId: 'preview-no-csrf',
        ));
        self::assertSame(403, $withoutCsrf->status());
    }

    private function loginCustomer(): void
    {
        $response = $this->app->handle(new Request('POST', '/auth/login', body: [
            '_token' => $this->session->csrfToken(),
            'identifier' => 'customer0001@example.test',
            'password' => 'Ntu@123456',
        ], requestId: 'preview-login'));
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
        ];
        $response = $this->app->handle(new Request(
            'GET',
            '/search',
            $query,
            headers: ['accept' => 'application/json'],
            requestId: 'preview-search',
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
}
