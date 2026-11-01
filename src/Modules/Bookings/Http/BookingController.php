<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http;

use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Bookings\Application\BookingException;
use App\Modules\Bookings\Application\BookingRequest;
use App\Modules\Bookings\Application\BookingService;
use App\Modules\Bookings\Domain\Booking;
use App\Modules\Bookings\Infrastructure\PreviewTokenSession;
use App\Modules\Identity\Domain\CurrentUser;
use App\Modules\Identity\Domain\UserAccount;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Shared\Http\ConflictHttpException;
use App\Shared\Http\FlashBag;
use App\Shared\Http\ForbiddenHttpException;
use App\Shared\Http\NotFoundHttpException;
use App\Shared\Http\ValidationHttpException;
use App\Shared\Http\ViewRenderer;
use InvalidArgumentException;

final readonly class BookingController
{
    public function __construct(
        private BookingService $bookings,
        private PreviewTokenSession $tokens,
        private IdentitySession $identity,
        private SessionBootstrap $session,
        private ViewRenderer $views,
        private FlashBag $flash,
        private AppConfig $config,
    ) {
    }

    public function store(Request $request): Response
    {
        $user = $this->customer();
        $token = trim((string) ($request->header('x-preview-token') ?? $this->tokens->current() ?? ''));
        try {
            $bookingRequest = BookingRequest::fromInput(
                $this->string($request->body['clientRequestId'] ?? null, 'ClientRequestID'),
                $this->string($request->body['bookerName'] ?? null, 'Họ tên người đặt'),
                $this->string($request->body['bookerEmail'] ?? null, 'Email người đặt'),
                $this->string($request->body['bookerPhone'] ?? null, 'Số điện thoại người đặt'),
                is_string($request->body['specialRequest'] ?? null)
                    ? (string) $request->body['specialRequest']
                    : null,
                $this->accepted($request->body['termsAccepted'] ?? null),
            );
            $created = $this->bookings->createPayAtProperty(
                $token,
                $user->id,
                $bookingRequest,
                $request->requestId,
                $request->clientIp,
            );
        } catch (BookingException $exception) {
            throw new ConflictHttpException($exception->errorCode, $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            throw new ValidationHttpException(['booking' => $exception->getMessage()], $exception->getMessage());
        }

        $this->tokens->clear();
        if ($request->expectsJson()) {
            return Response::json(
                ['booking' => $this->payload($created->booking), 'replayed' => $created->replayed],
                $created->replayed ? 200 : 201,
                ['Cache-Control' => 'no-store'],
            );
        }

        return Response::redirect(
            $this->url('/bookings/' . rawurlencode($created->booking->code) . '/confirmation'),
            303,
        );
    }

    public function confirmation(Request $request): Response
    {
        $user = $this->customer();
        try {
            $booking = $this->bookings->findConfirmation((string) $request->route('code'), $user->id);
        } catch (BookingException) {
            throw new NotFoundHttpException();
        }
        $payload = $this->payload($booking);
        $headers = $this->securityHeaders();
        if ($request->expectsJson()) {
            return Response::json(['booking' => $payload], 200, $headers);
        }

        return Response::html($this->views->render('bookings/confirmation', [
            'title' => 'Đặt phòng thành công · StayBooking',
            'baseUrl' => rtrim($this->config->url, '/'),
            'csrfToken' => $this->session->csrfToken(),
            'flashes' => $this->flash->consumeAll(),
            'currentUserName' => $user->fullName,
            'currentUserRole' => $user->role,
            'booking' => $payload,
        ], 'layouts/app'), 200, $headers);
    }

    /** @return array<string, mixed> */
    private function payload(Booking $booking): array
    {
        return [
            'code' => $booking->code,
            'property' => ['name' => $booking->propertyName, 'address' => $booking->propertyAddress],
            'checkIn' => $booking->checkIn,
            'checkOut' => $booking->checkOut,
            'booker' => [
                'name' => $booking->bookerName,
                'email' => $booking->bookerEmail,
                'phone' => $booking->bookerPhone,
                'specialRequest' => $booking->specialRequest,
            ],
            'paymentTiming' => $booking->paymentTiming,
            'currency' => 'VND',
            'grandTotal' => $booking->grandTotal->decimal(),
            'status' => $booking->status,
            'confirmedAt' => $booking->confirmedAt->format(DATE_ATOM),
            'items' => $booking->items,
        ];
    }

    private function customer(): CurrentUser
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
            throw new InvalidArgumentException($label . ' không được để trống.');
        }
        return trim($value);
    }

    private function accepted(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'on', 'true'], true);
    }

    /** @return array<string, string> */
    private function securityHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; base-uri 'self'; form-action 'self'",
        ];
    }

    private function url(string $path): string
    {
        return rtrim($this->config->url, '/') . $path;
    }
}
