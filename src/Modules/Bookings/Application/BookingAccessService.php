<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Domain\Booking;
use App\Modules\Bookings\Infrastructure\BookingRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Database\TransactionManager;
use InvalidArgumentException;

final readonly class BookingAccessService
{
    private const STATUSES = [
        'PENDING_PAYMENT', 'CONFIRMED', 'PAYMENT_FAILED', 'CANCELLED', 'NO_SHOW', 'COMPLETED',
    ];

    public function __construct(
        private BookingRepository $bookings,
        private AuditTrail $audit,
        private TransactionManager $transactions,
    ) {
    }

    public function customerBookings(int $customerId, int $page, ?string $status = null): BookingListPage
    {
        $this->positiveId($customerId);
        [$page, $status] = $this->filters($page, $status);
        $result = $this->bookings->paginateForCustomer($customerId, $page, 10, $status);
        return new BookingListPage($result['items'], $page, 10, $result['total']);
    }

    public function customerBooking(string $code, int $customerId): ?Booking
    {
        $this->positiveId($customerId);
        return $this->bookings->findForCustomerByCode($this->code($code), $customerId);
    }

    public function partnerBookings(int $organizationId, int $page, ?string $status = null): BookingListPage
    {
        $this->positiveId($organizationId);
        [$page, $status] = $this->filters($page, $status);
        $result = $this->bookings->paginateForOrganization($organizationId, $page, 20, $status);
        return new BookingListPage($result['items'], $page, 20, $result['total']);
    }

    public function partnerBooking(string $code, int $organizationId): ?Booking
    {
        $this->positiveId($organizationId);
        return $this->bookings->findForOrganizationByCode($this->code($code), $organizationId);
    }

    public function adminBookings(int $page, ?string $status = null, ?string $query = null): BookingListPage
    {
        [$page, $status] = $this->filters($page, $status);
        $query = trim((string) $query);
        if (strlen($query) > 190) {
            throw new InvalidArgumentException('Từ khóa booking không được vượt quá 190 ký tự.');
        }
        $result = $this->bookings->paginateForAdmin($page, 20, $status, $query === '' ? null : $query);
        return new BookingListPage($result['items'], $page, 20, $result['total']);
    }

    public function adminBooking(
        string $code,
        int $adminId,
        string $requestId,
        string $clientIp,
    ): ?Booking {
        $this->positiveId($adminId);
        $requestId = $this->requestId($requestId);
        $booking = $this->bookings->findByCode($this->code($code));
        if (!$booking instanceof Booking) {
            return null;
        }
        $this->transactions->run(function () use ($booking, $adminId, $requestId, $clientIp): void {
            $existing = $this->audit->findByRequestId($requestId);
            if ($existing !== null) {
                if ((int) ($existing['actorId'] ?? 0) !== $adminId
                    || ($existing['action'] ?? null) !== 'ADMIN_BOOKING_VIEW'
                    || ($existing['entityId'] ?? null) !== (string) $booking->id) {
                    throw new BookingException('IDEMPOTENCY_CONFLICT', 'RequestID đã được dùng cho thao tác khác.');
                }
                return;
            }
            $this->audit->record(
                $adminId,
                'ADMIN_BOOKING_VIEW',
                'DatCho',
                (string) $booking->id,
                $requestId,
                null,
                ['code' => $booking->code, 'status' => $booking->status],
                $clientIp,
            );
        });
        return $booking;
    }

    /** @return array{int, ?string} */
    private function filters(int $page, ?string $status): array
    {
        if ($page < 1 || $page > 100_000) {
            throw new InvalidArgumentException('Số trang booking không hợp lệ.');
        }
        $status = strtoupper(trim((string) $status));
        if ($status === '') {
            return [$page, null];
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Trạng thái booking cần lọc không hợp lệ.');
        }
        return [$page, $status];
    }

    private function positiveId(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Phạm vi đọc booking không hợp lệ.');
        }
    }

    private function code(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '' || strlen($code) > 24 || preg_match('/^[A-Z0-9-]+$/', $code) !== 1) {
            throw new InvalidArgumentException('Mã booking không hợp lệ.');
        }
        return $code;
    }

    private function requestId(string $requestId): string
    {
        $requestId = trim($requestId);
        if (preg_match('/^[A-Za-z0-9._-]{8,80}$/', $requestId) !== 1) {
            throw new BookingException('REQUEST_ID_INVALID', 'RequestID audit booking không hợp lệ.');
        }
        return $requestId;
    }
}
