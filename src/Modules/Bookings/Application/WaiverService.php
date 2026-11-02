<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Domain\Payment;
use App\Modules\Bookings\Infrastructure\CancellationRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Modules\Bookings\Infrastructure\WaiverRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Clock\ClockInterface;
use App\Shared\Database\TransactionManager;
use App\Shared\Json\CanonicalJson;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;

final readonly class WaiverService
{
    public function __construct(
        private CancellationRepository $cancellations,
        private PaymentRepository $payments,
        private WaiverRepository $waivers,
        private CancellationFinalizer $finalizer,
        private CancellationRefundService $refunds,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    public function request(
        string $code,
        int $customerId,
        string $reason,
        string $requestId,
        string $clientIp,
    ): int {
        $code = $this->code($code);
        $reason = $this->text($reason, 5, 'Lý do xin miễn phí hủy');
        $this->requestId($requestId);
        return $this->transactions->run(function () use ($code, $customerId, $reason, $requestId, $clientIp): int {
            $context = $this->cancellations->lockForCustomer($code, $customerId);
            if ($context === null) {
                throw new BookingException('BOOKING_NOT_FOUND', 'Không tìm thấy booking thuộc tài khoản hiện tại.');
            }
            $existing = $this->audit->findByRequestId($requestId);
            if ($existing !== null) {
                if (($existing['action'] ?? null) !== 'CANCELLATION_REQUEST_CREATED'
                    || (int) ($existing['actorId'] ?? 0) !== $customerId
                    || ($existing['after']['reason'] ?? null) !== $reason) {
                    throw new BookingException('IDEMPOTENCY_CONFLICT', 'RequestID đã được dùng cho thao tác khác.');
                }
                return (int) $existing['entityId'];
            }
            $this->assertOpen($context);
            if ($this->waivers->hasPending((int) $context['id'])) {
                throw new BookingException('WAIVER_ALREADY_PENDING', 'Booking đã có yêu cầu miễn phí hủy đang chờ.');
            }
            $waiverId = $this->waivers->create((int) $context['id'], $customerId, $reason, $this->clock->now());
            $this->audit->record(
                $customerId,
                'CANCELLATION_REQUEST_CREATED',
                'YeuCauHuyMienPhi',
                (string) $waiverId,
                $requestId,
                null,
                ['status' => 'PENDING', 'bookingId' => (int) $context['id'], 'reason' => $reason],
                $clientIp,
            );
            return $waiverId;
        });
    }

    public function withdraw(
        string $code,
        int $customerId,
        int $waiverId,
        string $requestId,
        string $clientIp,
    ): void {
        $code = $this->code($code);
        $this->requestId($requestId);
        $this->transactions->run(function () use ($code, $customerId, $waiverId, $requestId, $clientIp): void {
            $context = $this->cancellations->lockForCustomer($code, $customerId);
            if ($context === null) {
                throw new BookingException('BOOKING_NOT_FOUND', 'Không tìm thấy booking thuộc tài khoản hiện tại.');
            }
            $waiver = $this->waivers->lockForCustomer($waiverId, $customerId, (int) $context['id']);
            if ($waiver === null) {
                throw new BookingException('WAIVER_NOT_FOUND', 'Không tìm thấy yêu cầu miễn phí hủy.');
            }
            $existing = $this->audit->findByRequestId($requestId);
            if ($existing !== null) {
                if (($existing['action'] ?? null) !== 'CANCELLATION_REQUEST_WITHDRAWN'
                    || ($existing['entityId'] ?? null) !== (string) $waiverId
                    || (int) ($existing['actorId'] ?? 0) !== $customerId) {
                    throw new BookingException('IDEMPOTENCY_CONFLICT', 'RequestID đã được dùng cho thao tác khác.');
                }
                return;
            }
            if ($waiver['status'] !== 'PENDING') {
                throw new BookingException('WAIVER_NOT_PENDING', 'Yêu cầu không còn ở trạng thái chờ.');
            }
            $this->waivers->withdraw($waiverId, $this->clock->now());
            $this->audit->record(
                $customerId,
                'CANCELLATION_REQUEST_WITHDRAWN',
                'YeuCauHuyMienPhi',
                (string) $waiverId,
                $requestId,
                ['status' => 'PENDING'],
                ['status' => 'WITHDRAWN'],
                $clientIp,
            );
        });
    }

    public function decide(
        int $waiverId,
        int $organizationId,
        int $actorId,
        WaiverDecision $decision,
        string $requestId,
        string $clientIp,
    ): void {
        $this->requestId($requestId);
        $scope = $this->waivers->findScope($waiverId);
        if ($scope === null || $scope['organizationId'] !== $organizationId) {
            throw new BookingException('WAIVER_NOT_FOUND', 'Không tìm thấy yêu cầu miễn phí hủy trong tổ chức hiện tại.');
        }
        $state = $this->transactions->run(function () use (
            $waiverId,
            $organizationId,
            $actorId,
            $decision,
            $requestId,
            $clientIp,
            $scope,
        ): array {
            $context = $this->cancellations->lockById($scope['bookingId']);
            $payment = $this->payments->lockByBookingId($scope['bookingId']);
            if ($context === null || !$payment instanceof Payment || $context['organizationId'] !== $organizationId) {
                throw new BookingException('WAIVER_NOT_FOUND', 'Không tìm thấy yêu cầu miễn phí hủy trong tổ chức hiện tại.');
            }
            $waiver = $this->waivers->lockForOrganization($waiverId, $organizationId);
            if ($waiver === null) {
                throw new BookingException('WAIVER_NOT_FOUND', 'Không tìm thấy yêu cầu miễn phí hủy trong tổ chức hiện tại.');
            }
            $existing = $this->audit->findByRequestId($requestId);
            if ($existing !== null) {
                if (($existing['entityId'] ?? null) !== (string) $waiverId
                    || (int) ($existing['actorId'] ?? 0) !== $actorId
                    || ($existing['after']['decision'] ?? null) !== $decision->status
                    || ($existing['after']['response'] ?? null) !== $decision->response) {
                    throw new BookingException('IDEMPOTENCY_CONFLICT', 'RequestID đã được dùng cho thao tác khác.');
                }
                $expected = Money::vnd((string) ($context['calculation']['refundExpected'] ?? '0'));
                return ['bookingId' => (int) $context['id'], 'key' => $this->cancellationKey($waiverId), 'expected' => $expected];
            }
            if ($waiver['status'] !== 'PENDING') {
                throw new BookingException('WAIVER_NOT_PENDING', 'Yêu cầu không còn ở trạng thái chờ.');
            }
            $this->assertOpen($context);
            $expected = Money::vnd(0);
            $cancellationKey = $this->cancellationKey($waiverId);
            if ($decision->status === 'APPROVED') {
                $expected = $this->finalizer->finalize(
                    $context,
                    $payment,
                    $cancellationKey,
                    $actorId,
                    'Đối tác chấp thuận miễn phí hủy: ' . (string) $waiver['reason'],
                    Money::vnd(0),
                    [['type' => 'WAIVER', 'waiverId' => $waiverId, 'fee' => '0']],
                    CanonicalJson::hash(['waiverId' => $waiverId, 'decision' => 'APPROVED']),
                    'BOOKING_CANCELLED_BY_WAIVER',
                    $clientIp,
                    $this->clock->now(),
                );
            }
            $this->waivers->decide($waiverId, $decision->status, $actorId, $decision->response, $this->clock->now());
            $this->audit->record(
                $actorId,
                'CANCELLATION_REQUEST_' . $decision->status,
                'YeuCauHuyMienPhi',
                (string) $waiverId,
                $requestId,
                ['status' => 'PENDING'],
                ['status' => $decision->status, 'decision' => $decision->status, 'response' => $decision->response],
                $clientIp,
            );
            return ['bookingId' => (int) $context['id'], 'key' => $cancellationKey, 'expected' => $expected];
        });
        if ($decision->status === 'APPROVED' && !$state['expected']->isZero()) {
            $this->refunds->process($state['bookingId'], $state['expected'], $state['key'], $actorId, $clientIp);
        }
    }

    /** @return list<array<string, mixed>> */
    public function pendingForOrganization(int $organizationId): array
    {
        return $this->waivers->listPendingForOrganization($organizationId);
    }

    /** @return list<array<string, mixed>> */
    public function forCustomerBooking(int $bookingId, int $customerId): array
    {
        return $this->waivers->listForBooking($bookingId, $customerId);
    }

    /** @param array<string, mixed> $context */
    private function assertOpen(array $context): void
    {
        if ($context['status'] !== 'CONFIRMED') {
            throw new BookingException('BOOKING_NOT_CANCELLABLE', 'Booking hiện tại không thể yêu cầu miễn phí hủy.');
        }
        $cutoff = new DateTimeImmutable(
            (string) $context['checkIn'] . ' ' . (string) $context['checkInTime'],
            new DateTimeZone((string) $context['timezone']),
        );
        if ($this->clock->now() >= $cutoff) {
            throw new BookingException('BOOKING_CUTOFF_PASSED', 'Đã đến hoặc qua thời điểm nhận phòng của cơ sở.');
        }
    }

    private function cancellationKey(int $waiverId): string
    {
        $hex = hash('sha256', 'waiver:' . $waiverId);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-5' . substr($hex, 13, 3)
            . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }

    private function code(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '' || strlen($code) > 24 || preg_match('/^[A-Z0-9-]+$/', $code) !== 1) {
            throw new BookingException('BOOKING_NOT_FOUND', 'Không tìm thấy booking thuộc tài khoản hiện tại.');
        }
        return $code;
    }

    private function text(string $text, int $minimum, string $label): string
    {
        $text = trim($text);
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($length < $minimum || $length > 1000) {
            throw new BookingException('WAIVER_INPUT_INVALID', $label . " phải có từ {$minimum} đến 1000 ký tự.");
        }
        return $text;
    }

    private function requestId(string $requestId): void
    {
        if (preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $requestId) !== 1) {
            throw new BookingException('REQUEST_ID_INVALID', 'RequestID không hợp lệ.');
        }
    }
}
