<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http;

use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Bookings\Application\BookingAccessService;
use App\Modules\Bookings\Application\BookingException;
use App\Modules\Bookings\Application\BookingGuestDetailsRequest;
use App\Modules\Bookings\Application\BookingModificationService;
use App\Modules\Bookings\Application\BookingListPage;
use App\Modules\Bookings\Domain\Booking;
use App\Modules\Identity\Domain\CurrentUser;
use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Shared\Http\FlashBag;
use App\Shared\Http\NotFoundHttpException;
use App\Shared\Http\ValidationHttpException;
use App\Shared\Http\ViewRenderer;
use DomainException;
use InvalidArgumentException;

final readonly class BookingPortalController
{
    public function __construct(
        private BookingAccessService $access,
        private BookingModificationService $modifications,
        private BookingViewPresenter $presenter,
        private IdentitySession $identity,
        private SessionBootstrap $session,
        private ViewRenderer $views,
        private FlashBag $flash,
        private AppConfig $config,
    ) {
    }

    public function customerIndex(Request $request): Response
    {
        $user = $this->user();
        $status = $this->status($request);
        return $this->indexView(
            $this->loadPage(fn (): BookingListPage => $this->access->customerBookings(
                $user->id,
                $this->page($request),
                $status,
            )),
            'Đặt chỗ của tôi',
            'Theo dõi các chuyến đi và mở lại thông tin đã chốt tại thời điểm đặt.',
            '/account/bookings',
            false,
            $status ?? '',
            '',
        );
    }

    public function customerShow(Request $request): Response
    {
        $booking = $this->lookup(fn (): ?Booking => $this->access->customerBooking(
            $this->code($request),
            $this->user()->id,
        ));
        return $this->detailView($booking, '/account/bookings', true);
    }

    public function customerUpdate(Request $request): Response
    {
        $code = $this->code($request);
        try {
            $this->modifications->updateGuestDetails(
                $code,
                $this->user()->id,
                BookingGuestDetailsRequest::fromInput($request->body),
                $request->requestId,
                $request->clientIp,
            );
            $this->flash->add('success', 'Đã cập nhật thông tin liên hệ và khách lưu trú.');
        } catch (BookingException $exception) {
            if ($exception->errorCode === 'BOOKING_NOT_FOUND') {
                throw new NotFoundHttpException();
            }
            $this->flash->add('info', $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            $this->flash->add('info', $exception->getMessage());
        }
        return Response::redirect($this->url('/account/bookings/' . rawurlencode($code)), 303);
    }

    public function partnerIndex(Request $request): Response
    {
        $organization = $this->organization();
        $status = $this->status($request);
        return $this->indexView(
            $this->loadPage(fn (): BookingListPage => $this->access->partnerBookings(
                $organization->id,
                $this->page($request),
                $status,
            )),
            'Đặt phòng',
            'Các booking thuộc cơ sở trong tổ chức ' . $organization->name . '.',
            '/partner/bookings',
            false,
            $status ?? '',
            '',
        );
    }

    public function partnerShow(Request $request): Response
    {
        $booking = $this->lookup(fn (): ?Booking => $this->access->partnerBooking(
            $this->code($request),
            $this->organization()->id,
        ));
        return $this->detailView($booking, '/partner/bookings', false);
    }

    public function adminIndex(Request $request): Response
    {
        $status = $this->status($request);
        $query = $this->query($request, 'q');
        return $this->indexView(
            $this->loadPage(fn (): BookingListPage => $this->access->adminBookings(
                $this->page($request),
                $status,
                $query,
            )),
            'Hỗ trợ booking',
            'Tra cứu booking theo mã, email người đặt hoặc cơ sở lưu trú.',
            '/admin/bookings',
            true,
            $status ?? '',
            $query,
        );
    }

    public function adminShow(Request $request): Response
    {
        $user = $this->user();
        $booking = $this->lookup(fn (): ?Booking => $this->access->adminBooking(
            $this->code($request),
            $user->id,
            $request->requestId,
            $request->clientIp,
        ));
        return $this->detailView($booking, '/admin/bookings', false);
    }

    private function indexView(
        BookingListPage $page,
        string $title,
        string $description,
        string $basePath,
        bool $searchable,
        string $statusFilter,
        string $searchQuery,
    ): Response {
        return $this->view('bookings/portal/index', [
            'title' => $title,
            'heading' => $title,
            'description' => $description,
            'pageData' => $this->presenter->page($page),
            'listPath' => $basePath,
            'statusFilter' => $statusFilter,
            'searchQuery' => $searchQuery,
            'searchable' => $searchable,
            'activeNavigation' => 'bookings',
        ])->withHeader('Cache-Control', 'no-store, private');
    }

    private function detailView(Booking $booking, string $listPath, bool $editable): Response
    {
        return $this->view('bookings/portal/show', [
            'title' => 'Booking ' . $booking->code,
            'booking' => $this->presenter->booking($booking),
            'listPath' => $listPath,
            'editable' => $editable && $booking->status === 'CONFIRMED',
            'activeNavigation' => 'bookings',
        ])->withHeader('Cache-Control', 'no-store, private');
    }

    /** @param array<string, mixed> $data */
    private function view(string $template, array $data): Response
    {
        $user = $this->user();
        return Response::html($this->views->render($template, [
            ...$data,
            'baseUrl' => rtrim($this->config->url, '/'),
            'csrfToken' => $this->session->csrfToken(),
            'flashes' => $this->flash->consumeAll(),
            'currentUserName' => $user->fullName,
            'currentUserRole' => $user->role,
        ], 'layouts/app'));
    }

    private function user(): CurrentUser
    {
        return $this->identity->currentUser() ?? throw new DomainException('Phiên người dùng không hợp lệ.');
    }

    private function organization(): OrganizationContext
    {
        return $this->identity->organization() ?? throw new DomainException('Phiên tổ chức đối tác không hợp lệ.');
    }

    /** @param callable(): BookingListPage $loader */
    private function loadPage(callable $loader): BookingListPage
    {
        try {
            return $loader();
        } catch (InvalidArgumentException $exception) {
            throw new ValidationHttpException(['filter' => [$exception->getMessage()]]);
        }
    }

    /** @param callable(): ?Booking $loader */
    private function lookup(callable $loader): Booking
    {
        try {
            return $loader() ?? throw new NotFoundHttpException();
        } catch (InvalidArgumentException) {
            throw new NotFoundHttpException();
        }
    }

    private function code(Request $request): string
    {
        return (string) $request->route('code', '');
    }

    private function page(Request $request): int
    {
        $value = $request->query['page'] ?? 1;
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new ValidationHttpException(['page' => ['Số trang booking không hợp lệ.']]);
        }
        return (int) $value;
    }

    private function status(Request $request): ?string
    {
        $status = $this->query($request, 'status');
        return $status === '' ? null : $status;
    }

    private function query(Request $request, string $name): string
    {
        $value = $request->query[$name] ?? '';
        if (!is_scalar($value)) {
            throw new ValidationHttpException([$name => ['Tham số truy vấn không hợp lệ.']]);
        }
        return trim((string) $value);
    }

    private function url(string $path): string
    {
        return rtrim($this->config->url, '/') . $path;
    }
}
