<?php

declare(strict_types=1);

use App\Bootstrap\Container;
use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\HealthController;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\RequestIdMiddleware;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Modules\Identity\Http\LoginController;
use App\Modules\Identity\Http\LogoutController;
use App\Modules\Identity\Http\RegisterController;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Modules\Identity\Domain\UserAccount;
use App\Modules\Bookings\Http\PreviewController;
use App\Modules\Bookings\Http\BookingController;
use App\Modules\Bookings\Http\BookingPortalController;
use App\Modules\Commercial\Http\PartnerCommercialController;
use App\Modules\Inventory\Http\PartnerInventoryController;
use App\Modules\Properties\Http\AdminPropertyController;
use App\Modules\Properties\Http\PartnerPropertyController;
use App\Modules\Properties\Http\PropertyImageController;
use App\Modules\Search\Http\PublicSearchController;
use App\Shared\Http\FlashBag;
use App\Shared\Http\ViewRenderer;

return static function (Router $router, Container $container): void {
    $requestId = $container->get(RequestIdMiddleware::class);
    $csrf = $container->get(CsrfMiddleware::class);
    $auth = $container->get(AuthMiddleware::class);
    $partnerRole = new RoleMiddleware($container->get(IdentitySession::class), [UserAccount::ROLE_PARTNER]);
    $adminRole = new RoleMiddleware($container->get(IdentitySession::class), [UserAccount::ROLE_ADMIN]);
    $customerRole = new RoleMiddleware($container->get(IdentitySession::class), [UserAccount::ROLE_CUSTOMER]);
    $partnerContext = $container->get(\App\Http\Middleware\PartnerContextMiddleware::class);

    $router->get('/', static function (Request $request) use ($container): Response {
        $config = $container->get(AppConfig::class);
        $identity = $container->get(IdentitySession::class);
        $user = $identity->currentUser();
        $today = $container->get(\App\Shared\Clock\ClockInterface::class)
            ->now()
            ->setTimezone($config->businessTimezone)
            ->setTime(0, 0);
        return Response::html($container->get(ViewRenderer::class)->render('home', [
            'title' => 'StayBooking - Tìm chỗ nghỉ tại TP.HCM',
            'baseUrl' => rtrim($config->url, '/'),
            'csrfToken' => $container->get(SessionBootstrap::class)->csrfToken(),
            'flashes' => $container->get(FlashBag::class)->consumeAll(),
            'currentUserName' => $user?->fullName,
            'currentUserRole' => $user?->role,
            'searchForm' => [
                'destination' => 'Thành phố Hồ Chí Minh',
                'checkIn' => $today->modify('+1 day')->format('Y-m-d'),
                'checkOut' => $today->modify('+3 days')->format('Y-m-d'),
                'parties' => [['adults' => 2, 'childAges' => []]],
            ],
        ], 'layouts/app'));
    }, [$requestId], 'home');

    $router->get(
        '/auth/register',
        static fn (Request $request): Response => $container->get(RegisterController::class)->show($request),
        [$requestId],
        'auth.register.form',
    );
    $router->post(
        '/auth/register',
        static fn (Request $request): Response => $container->get(RegisterController::class)->store($request),
        [$requestId, $csrf],
        'auth.register',
    );
    $router->get(
        '/auth/login',
        static fn (Request $request): Response => $container->get(LoginController::class)->show($request),
        [$requestId],
        'auth.login.form',
    );
    $router->post(
        '/auth/login',
        static fn (Request $request): Response => $container->get(LoginController::class)->store($request),
        [$requestId, $csrf],
        'auth.login',
    );
    $router->post(
        '/auth/logout',
        static fn (Request $request): Response => ($container->get(LogoutController::class))($request),
        [$requestId, $csrf, $auth],
        'auth.logout',
    );

    $router->get('/health', static fn (Request $request): Response =>
        ($container->get(HealthController::class))($request),
        [$requestId],
        'health',
    );

    $publicSearch = $container->get(PublicSearchController::class);
    $router->get('/search', $publicSearch->search(...), [$requestId], 'search.index');
    $router->get('/properties/{slug}', $publicSearch->property(...), [$requestId], 'properties.public.show');
    $router->get(
        '/properties/{slug}/availability',
        $publicSearch->availability(...),
        [$requestId],
        'properties.public.availability',
    );

    $preview = $container->get(PreviewController::class);
    $customerRead = [$requestId, $auth, $customerRole];
    $customerWrite = [$requestId, $csrf, $auth, $customerRole];
    $router->post('/previews', $preview->create(...), $customerWrite, 'previews.create');
    $router->get('/checkout', $preview->checkout(...), $customerRead, 'checkout.show');
    $booking = $container->get(BookingController::class);
    $router->post('/bookings', $booking->store(...), $customerWrite, 'bookings.store');
    $router->get(
        '/bookings/{code}/confirmation',
        $booking->confirmation(...),
        $customerRead,
        'bookings.confirmation',
    );
    $bookingPortal = $container->get(BookingPortalController::class);
    $router->get('/account/bookings', $bookingPortal->customerIndex(...), $customerRead, 'account.bookings.index');
    $router->get('/account/bookings/{code}', $bookingPortal->customerShow(...), $customerRead, 'account.bookings.show');
    $router->post(
        '/account/bookings/{code}/guest-details',
        $bookingPortal->customerUpdate(...),
        $customerWrite,
        'account.bookings.update',
    );

    $partner = $container->get(PartnerPropertyController::class);
    $partnerRead = [$requestId, $auth, $partnerRole, $partnerContext];
    $partnerWrite = [$requestId, $csrf, $auth, $partnerRole, $partnerContext];
    $partnerBookingRead = [$requestId, $auth, $partnerRole];
    $router->get('/partner/bookings', $bookingPortal->partnerIndex(...), $partnerBookingRead, 'partner.bookings.index');
    $router->get('/partner/bookings/{code}', $bookingPortal->partnerShow(...), $partnerBookingRead, 'partner.bookings.show');
    $router->get('/partner/properties', $partner->index(...), $partnerRead, 'partner.properties.index');
    $router->get('/partner/properties/create', $partner->create(...), $partnerRead, 'partner.properties.create');
    $router->post('/partner/properties', $partner->store(...), $partnerWrite, 'partner.properties.store');
    $router->get('/partner/properties/{propertyId}', $partner->show(...), $partnerRead, 'partner.properties.show');
    $router->get('/partner/properties/{propertyId}/edit', $partner->edit(...), $partnerRead, 'partner.properties.edit');
    $router->post('/partner/properties/{propertyId}/update', $partner->update(...), $partnerWrite, 'partner.properties.update');
    $router->post('/partner/properties/{propertyId}/submit', $partner->submit(...), $partnerWrite, 'partner.properties.submit');
    $router->post('/partner/properties/{propertyId}/rooms', $partner->createRoom(...), $partnerWrite, 'partner.rooms.store');
    $router->post('/partner/rooms/{roomTypeId}/update', $partner->updateRoom(...), $partnerWrite, 'partner.rooms.update');
    $router->post(
        '/partner/rooms/{roomTypeId}/facilities',
        $partner->updateRoomFacilities(...),
        $partnerWrite,
        'partner.rooms.facilities',
    );
    $router->post(
        '/partner/properties/{propertyId}/facilities',
        $partner->updatePropertyFacilities(...),
        $partnerWrite,
        'partner.properties.facilities',
    );
    $router->post('/partner/properties/{propertyId}/images', $partner->uploadImage(...), $partnerWrite, 'partner.properties.images');

    $commercial = $container->get(PartnerCommercialController::class);
    $router->get(
        '/partner/properties/{propertyId}/commercial',
        $commercial->index(...),
        $partnerRead,
        'partner.commercial.index',
    );
    $router->post(
        '/partner/properties/{propertyId}/cancellation-policies',
        $commercial->createPolicy(...),
        $partnerWrite,
        'partner.commercial.policies.store',
    );
    $router->post(
        '/partner/cancellation-policies/{policyId}/update',
        $commercial->updatePolicy(...),
        $partnerWrite,
        'partner.commercial.policies.update',
    );
    $router->post(
        '/partner/properties/{propertyId}/room-products',
        $commercial->createProduct(...),
        $partnerWrite,
        'partner.commercial.products.store',
    );
    $router->post(
        '/partner/room-products/{productId}/update',
        $commercial->updateProduct(...),
        $partnerWrite,
        'partner.commercial.products.update',
    );
    $router->post(
        '/partner/room-products/{productId}/rates',
        $commercial->updateRate(...),
        $partnerWrite,
        'partner.commercial.rates.update',
    );
    $router->post(
        '/partner/properties/{propertyId}/promotions',
        $commercial->createPromotion(...),
        $partnerWrite,
        'partner.commercial.promotions.store',
    );
    $router->post(
        '/partner/promotions/{promotionId}/update',
        $commercial->updatePromotion(...),
        $partnerWrite,
        'partner.commercial.promotions.update',
    );

    $inventory = $container->get(PartnerInventoryController::class);
    $router->get(
        '/partner/properties/{propertyId}/inventory',
        $inventory->index(...),
        $partnerRead,
        'partner.inventory.index',
    );
    $router->post(
        '/partner/room-types/{roomTypeId}/inventory',
        $inventory->update(...),
        $partnerWrite,
        'partner.inventory.update',
    );

    $admin = $container->get(AdminPropertyController::class);
    $adminRead = [$requestId, $auth, $adminRole];
    $adminWrite = [$requestId, $csrf, $auth, $adminRole];
    $router->get('/admin/bookings', $bookingPortal->adminIndex(...), $adminRead, 'admin.bookings.index');
    $router->get('/admin/bookings/{code}', $bookingPortal->adminShow(...), $adminRead, 'admin.bookings.show');
    $router->get('/admin/properties/review', $admin->index(...), $adminRead, 'admin.properties.index');
    $router->get('/admin/properties/{propertyId}', $admin->show(...), $adminRead, 'admin.properties.show');
    $router->post('/admin/properties/{propertyId}/review', $admin->review(...), $adminWrite, 'admin.properties.review');

    $router->get(
        '/uploads/properties/{propertyCode}/{filename}',
        ($container->get(PropertyImageController::class))(...),
        [$requestId],
        'properties.images.show',
    );
};
