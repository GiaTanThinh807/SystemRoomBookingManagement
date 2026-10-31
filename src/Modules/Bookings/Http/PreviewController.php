<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http;

use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Bookings\Application\PreviewException;
use App\Modules\Bookings\Application\PreviewService;
use App\Modules\Bookings\Domain\BookingPreview;
use App\Modules\Bookings\Infrastructure\PreviewTokenSession;
use App\Modules\Identity\Domain\UserAccount;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Modules\Search\Application\SearchCriteriaFactory;
use App\Shared\Http\ConflictHttpException;
use App\Shared\Http\FlashBag;
use App\Shared\Http\ForbiddenHttpException;
use App\Shared\Http\ValidationHttpException;
use App\Shared\Http\ViewRenderer;
use InvalidArgumentException;
use JsonException;

final readonly class PreviewController
{
    public function __construct(
        private PreviewService $previews,
        private SearchCriteriaFactory $criteriaFactory,
        private PreviewTokenSession $tokens,
        private IdentitySession $identity,
        private SessionBootstrap $session,
        private ViewRenderer $views,
        private FlashBag $flash,
        private AppConfig $config,
    ) {
    }

    public function create(Request $request): Response
    {
        $user = $this->customer();
        try {
            $criteria = $this->criteriaFactory->fromArray($this->criteriaInput($request->body['criteria'] ?? null));
            $created = $this->previews->create(
                $user->id,
                $this->string($request->body['propertySlug'] ?? null, 'Cơ sở'),
                $criteria,
                $this->string($request->body['paymentTiming'] ?? null, 'Thời điểm thanh toán'),
                $this->string($request->body['selectionFingerprint'] ?? null, 'Selection fingerprint'),
                $this->selected($request->body['selected'] ?? null),
            );
        } catch (PreviewException $exception) {
            throw new ConflictHttpException($exception->errorCode, $exception->getMessage());
        } catch (InvalidArgumentException|JsonException $exception) {
            throw new ValidationHttpException(['preview' => $exception->getMessage()], $exception->getMessage());
        }

        if ($request->expectsJson()) {
            return Response::json([
                'token' => $created->token,
                'preview' => $this->payload($created->preview),
            ], 201, ['Cache-Control' => 'no-store']);
        }

        $this->tokens->store($created->token);
        return Response::redirect($this->url('/checkout'), 303);
    }

    public function checkout(Request $request): Response
    {
        $user = $this->customer();
        $token = trim((string) ($request->header('x-preview-token') ?? $this->tokens->current() ?? ''));
        try {
            $preview = $this->previews->loadForCheckout($token, $user->id);
        } catch (PreviewException $exception) {
            throw new ConflictHttpException($exception->errorCode, $exception->getMessage());
        }
        $payload = $this->payload($preview);
        $securityHeaders = [
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; base-uri 'self'; form-action 'self'",
        ];

        if ($request->expectsJson()) {
            return Response::json(['preview' => $payload], 200, $securityHeaders);
        }

        return Response::html($this->views->render('checkout/show', [
            'title' => 'Xác nhận lựa chọn · StayBooking',
            'baseUrl' => rtrim($this->config->url, '/'),
            'csrfToken' => $this->session->csrfToken(),
            'flashes' => $this->flash->consumeAll(),
            'currentUserName' => $user->fullName,
            'currentUserRole' => $user->role,
            'currentUserEmail' => $user->email,
            'preview' => $payload,
        ], 'layouts/app'), 200, $securityHeaders);
    }

    /** @return array<string, mixed> */
    private function payload(BookingPreview $preview): array
    {
        return [
            'id' => $preview->id,
            'checkIn' => $preview->checkIn,
            'checkOut' => $preview->checkOut,
            'currency' => 'VND',
            'grandTotal' => $preview->grandTotal->decimal(),
            'payNowTotal' => $preview->payNowTotal->decimal(),
            'payAtPropertyTotal' => $preview->payAtPropertyTotal->decimal(),
            'allocation' => $preview->allocationSnapshot,
            'fingerprint' => $preview->fingerprint,
            'status' => $preview->status,
            'expiresAt' => $preview->expiresAt->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function criteriaInput(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('Criteria preview phải là JSON object.');
        }
        $stay = $value['stayPeriod'] ?? null;
        $parties = $value['parties'] ?? null;
        $filters = $value['filters'] ?? [];
        if (!is_array($stay) || !is_array($parties) || !is_array($filters)) {
            throw new InvalidArgumentException('Criteria preview sai cấu trúc.');
        }
        return [
            'destination' => $value['destination'] ?? '',
            'checkIn' => $stay['checkIn'] ?? '',
            'checkOut' => $stay['checkOut'] ?? '',
            'rooms' => $parties,
            'sort' => $value['sort'] ?? 'RECOMMENDED',
            'page' => $value['page'] ?? 1,
            ...$filters,
        ];
    }

    /** @return array<int, array{roomTypeId: int, productId: int}> */
    private function selected(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Preview cần danh sách phòng/sản phẩm đã chọn.');
        }
        $selected = [];
        foreach (explode(',', $value) as $choice) {
            $parts = array_map('trim', explode(':', $choice));
            if (count($parts) !== 3) {
                throw new InvalidArgumentException('Danh sách phòng/sản phẩm đã chọn không hợp lệ.');
            }
            [$order, $roomTypeId, $productId] = $parts;
            if (filter_var($order, FILTER_VALIDATE_INT) === false
                || filter_var($roomTypeId, FILTER_VALIDATE_INT) === false
                || filter_var($productId, FILTER_VALIDATE_INT) === false) {
                throw new InvalidArgumentException('Danh sách phòng/sản phẩm đã chọn không hợp lệ.');
            }
            $selected[(int) $order] = ['roomTypeId' => (int) $roomTypeId, 'productId' => (int) $productId];
        }
        ksort($selected, SORT_NUMERIC);
        return $selected;
    }

    private function customer(): \App\Modules\Identity\Domain\CurrentUser
    {
        $user = $this->identity->currentUser();
        if ($user === null || !$user->hasRole(UserAccount::ROLE_CUSTOMER)) {
            throw new ForbiddenHttpException();
        }
        return $user;
    }

    private function string(mixed $value, string $label): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("{$label} không được để trống.");
        }
        return trim($value);
    }

    private function url(string $path): string
    {
        return rtrim($this->config->url, '/') . $path;
    }
}
