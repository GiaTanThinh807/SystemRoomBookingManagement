<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Config\AppConfig;
use App\Config\DatabaseConfig;
use App\Config\Env;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\HealthController;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\PartnerContextMiddleware;
use App\Http\Middleware\RequestIdMiddleware;
use App\Modules\Bookings\Application\BookingAccessService;
use App\Modules\Bookings\Application\BookingModificationService;
use App\Modules\Bookings\Application\BookingService;
use App\Modules\Bookings\Application\OnlinePaymentService;
use App\Modules\Bookings\Application\PaymentExpiryService;
use App\Modules\Bookings\Application\PaymentGateway;
use App\Modules\Bookings\Application\PreviewService;
use App\Modules\Bookings\Application\PreviewSnapshotFactory;
use App\Modules\Bookings\Http\BookingController;
use App\Modules\Bookings\Http\BookingPortalController;
use App\Modules\Bookings\Http\BookingViewPresenter;
use App\Modules\Bookings\Http\PreviewController;
use App\Modules\Bookings\Infrastructure\BookingCodeGenerator;
use App\Modules\Bookings\Infrastructure\BookingRepository;
use App\Modules\Bookings\Infrastructure\MockPaymentGateway;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Modules\Bookings\Infrastructure\PreviewRepository;
use App\Modules\Bookings\Infrastructure\PreviewTokenSession;
use App\Modules\Commercial\Application\ManageCancellationPolicyService;
use App\Modules\Commercial\Application\ManageDailyRateService;
use App\Modules\Commercial\Application\ManagePromotionService;
use App\Modules\Commercial\Application\ManageRoomProductService;
use App\Modules\Commercial\Application\PriceCalculator;
use App\Modules\Commercial\Application\StandardPriceCalculator;
use App\Modules\Commercial\Http\PartnerCommercialController;
use App\Modules\Commercial\Infrastructure\CancellationPolicyRepository;
use App\Modules\Commercial\Infrastructure\DailyRateRepository;
use App\Modules\Commercial\Infrastructure\PromotionRepository;
use App\Modules\Commercial\Infrastructure\RoomProductRepository;
use App\Modules\Identity\Application\LoginService;
use App\Modules\Identity\Application\LogoutService;
use App\Modules\Identity\Application\RegisterCustomerService;
use App\Modules\Identity\Http\LoginController;
use App\Modules\Identity\Http\LogoutController;
use App\Modules\Identity\Http\RegisterController;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Modules\Identity\Infrastructure\LoginThrottle;
use App\Modules\Identity\Infrastructure\PartnerMembershipRepository;
use App\Modules\Identity\Infrastructure\UserRepository;
use App\Modules\Inventory\Application\AvailabilityReader;
use App\Modules\Inventory\Application\InventoryCalendarService;
use App\Modules\Inventory\Application\InventoryReservationService;
use App\Modules\Inventory\Application\InventoryReconciliationService;
use App\Modules\Inventory\Application\TransactionalInventoryReservationService;
use App\Modules\Inventory\Infrastructure\DatabaseAvailabilityReader;
use App\Modules\Inventory\Infrastructure\InventoryRepository;
use App\Modules\Inventory\Http\PartnerInventoryController;
use App\Modules\Properties\Application\CreatePropertyService;
use App\Modules\Properties\Application\ManageRoomTypeService;
use App\Modules\Properties\Application\PropertyImageStorage;
use App\Modules\Properties\Application\ReviewPropertyService;
use App\Modules\Properties\Application\SubmitPropertyForReviewService;
use App\Modules\Properties\Application\UpdateFacilitiesService;
use App\Modules\Properties\Application\UpdatePropertyService;
use App\Modules\Properties\Domain\PropertyReadinessChecker;
use App\Modules\Properties\Http\AdminPropertyController;
use App\Modules\Properties\Http\PartnerPropertyController;
use App\Modules\Properties\Http\PropertyImageController;
use App\Modules\Properties\Infrastructure\FacilityRepository;
use App\Modules\Properties\Infrastructure\PropertyReadinessRepository;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Modules\Properties\Infrastructure\RoomTypeRepository;
use App\Modules\Search\Application\DeterministicRoomAllocator;
use App\Modules\Search\Application\PropertyAllocationService;
use App\Modules\Search\Application\PropertyAvailabilityService;
use App\Modules\Search\Application\RoomAllocator;
use App\Modules\Search\Application\SearchCriteriaFactory;
use App\Modules\Search\Application\SearchService;
use App\Modules\Search\Http\PublicSearchController;
use App\Modules\Search\Infrastructure\SearchCatalogRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Clock\ClockInterface;
use App\Shared\Clock\SystemClock;
use App\Shared\Database\Connection;
use App\Shared\Database\TransactionManager;
use App\Shared\Http\ExceptionHandler;
use App\Shared\Http\FlashBag;
use App\Shared\Http\ViewRenderer;
use App\Shared\Logging\FileLogger;
use App\Shared\Logging\Logger;
use App\Shared\Security\Hasher;
use App\Shared\Security\TokenGenerator;
use PDO;
use Throwable;

final class App
{
    private function __construct(
        private readonly Router $router,
        private readonly Container $container,
        private readonly ExceptionHandler $exceptionHandler,
    ) {
    }

    public static function boot(string $projectRoot): self
    {
        date_default_timezone_set('UTC');
        // Nạp env
        Env::load($projectRoot . '/.env');
        //tạo config
        $appConfig = AppConfig::fromEnv();
        $databaseConfig = DatabaseConfig::fromEnv();

        $container = new Container();
        $container->instance(AppConfig::class, $appConfig);
        $container->instance(DatabaseConfig::class, $databaseConfig);

        $container->singleton(ClockInterface::class, static fn (Container $_): ClockInterface => new SystemClock());
        $container->singleton(TokenGenerator::class, static fn (Container $_): TokenGenerator => new TokenGenerator());
        $container->singleton(Hasher::class, 
        static fn (Container $_): Hasher => new Hasher());

        $container->singleton(
            PDO::class,
            static fn (Container $_): PDO => Connection::create($databaseConfig),
        );
        $container->singleton(
            TransactionManager::class,
            static fn (Container $container): TransactionManager => new TransactionManager($container->get(PDO::class)),
        );

        $container->singleton(
            Logger::class,
            static fn (Container $container): Logger => new FileLogger(
                $projectRoot . '/storage/logs/app.log',
                $container->get(ClockInterface::class),
            ),
        );

        $container->singleton(
            ViewRenderer::class,
            static fn (Container $_): ViewRenderer => new ViewRenderer($projectRoot . '/views'),
        );

        $container->singleton(
            FlashBag::class,
            static fn (Container $_): FlashBag => new FlashBag(),
        );
        $container->instance(RequestIdMiddleware::class, new RequestIdMiddleware());

        $container->singleton(
            HealthController::class,
            static fn (Container $container): HealthController => new HealthController(
                $container->get(PDO::class),
                $container->get(ClockInterface::class),
            ),
        );
        $session = new SessionBootstrap(
            $appConfig,
            $projectRoot . '/storage/tmp',
            $container->get(ClockInterface::class),
            $container->get(TokenGenerator::class),
        );
        $container->instance(
            SessionBootstrap::class,
            $session,
        );
        if (PHP_SAPI !== 'cli') {
            $session->start();
        }

        $container->singleton(
            UserRepository::class,
            static fn (Container $container): UserRepository => new UserRepository($container->get(PDO::class)),
        );
        $container->singleton(
            PartnerMembershipRepository::class,
            static fn (Container $container): PartnerMembershipRepository => new PartnerMembershipRepository(
                $container->get(PDO::class),
            ),
        );
        $container->singleton(
            IdentitySession::class,
            static fn (Container $container): IdentitySession => new IdentitySession(
                $container->get(SessionBootstrap::class),
            ),
        );
        $container->singleton(
            LoginThrottle::class,
            static fn (Container $container): LoginThrottle => new LoginThrottle(
                $container->get(ClockInterface::class),
            ),
        );
        $container->singleton(
            RegisterCustomerService::class,
            static fn (Container $container): RegisterCustomerService => new RegisterCustomerService(
                $container->get(UserRepository::class),
                $container->get(TokenGenerator::class),
            ),
        );
        $container->singleton(
            LoginService::class,
            static fn (Container $container): LoginService => new LoginService(
                $container->get(UserRepository::class),
                $container->get(PartnerMembershipRepository::class),
                $container->get(IdentitySession::class),
                $container->get(LoginThrottle::class),
            ),
        );
        $container->singleton(
            LogoutService::class,
            static fn (Container $container): LogoutService => new LogoutService(
                $container->get(IdentitySession::class),
            ),
        );
        $container->singleton(
            RegisterController::class,
            static fn (Container $container): RegisterController => new RegisterController(
                $container->get(RegisterCustomerService::class),
                $container->get(IdentitySession::class),
                $container->get(SessionBootstrap::class),
                $container->get(ViewRenderer::class),
                $container->get(FlashBag::class),
                $container->get(AppConfig::class),
            ),
        );
        $container->singleton(
            LoginController::class,
            static fn (Container $container): LoginController => new LoginController(
                $container->get(LoginService::class),
                $container->get(IdentitySession::class),
                $container->get(SessionBootstrap::class),
                $container->get(ViewRenderer::class),
                $container->get(FlashBag::class),
                $container->get(AppConfig::class),
            ),
        );
        $container->singleton(
            LogoutController::class,
            static fn (Container $container): LogoutController => new LogoutController(
                $container->get(LogoutService::class),
                $container->get(AppConfig::class),
            ),
        );
        $container->singleton(
            CsrfMiddleware::class,
            static fn (Container $container): CsrfMiddleware => new CsrfMiddleware(
                $container->get(SessionBootstrap::class),
            ),
        );
        $container->singleton(
            AuthMiddleware::class,
            static fn (Container $container): AuthMiddleware => new AuthMiddleware(
                $container->get(IdentitySession::class),
            ),
        );
        $container->singleton(
            PartnerContextMiddleware::class,
            static fn (Container $container): PartnerContextMiddleware => new PartnerContextMiddleware(
                $container->get(IdentitySession::class),
            ),
        );

        $container->singleton(
            AuditTrail::class,
            static fn (Container $container): AuditTrail => new DatabaseAuditTrail($container->get(PDO::class)),
        );
        $container->singleton(
            PropertyRepository::class,
            static fn (Container $container): PropertyRepository => new PropertyRepository($container->get(PDO::class)),
        );
        $container->singleton(
            CancellationPolicyRepository::class,
            static fn (Container $container): CancellationPolicyRepository => new CancellationPolicyRepository(
                $container->get(PDO::class),
            ),
        );
        $container->singleton(
            RoomProductRepository::class,
            static fn (Container $container): RoomProductRepository => new RoomProductRepository($container->get(PDO::class)),
        );
        $container->singleton(
            DailyRateRepository::class,
            static fn (Container $container): DailyRateRepository => new DailyRateRepository($container->get(PDO::class)),
        );
        $container->singleton(
            PromotionRepository::class,
            static fn (Container $container): PromotionRepository => new PromotionRepository($container->get(PDO::class)),
        );
        $container->singleton(
            PriceCalculator::class,
            static fn (Container $_): PriceCalculator => new StandardPriceCalculator(),
        );
        $container->singleton(
            AvailabilityReader::class,
            static fn (Container $container): AvailabilityReader => new DatabaseAvailabilityReader(
                $container->get(PDO::class),
            ),
        );
        $container->singleton(
            SearchCriteriaFactory::class,
            static fn (Container $container): SearchCriteriaFactory => new SearchCriteriaFactory(
                $container->get(ClockInterface::class),
                $container->get(AppConfig::class)->businessTimezone,
            ),
        );
        $container->singleton(
            SearchCatalogRepository::class,
            static fn (Container $container): SearchCatalogRepository => new SearchCatalogRepository(
                $container->get(PDO::class),
            ),
        );
        $container->singleton(
            RoomAllocator::class,
            static fn (Container $_): RoomAllocator => new DeterministicRoomAllocator(),
        );
        $container->singleton(
            PropertyAllocationService::class,
            static fn (Container $container): PropertyAllocationService => new PropertyAllocationService(
                $container->get(PriceCalculator::class),
                $container->get(RoomAllocator::class),
            ),
        );
        $container->singleton(
            SearchService::class,
            static fn (Container $container): SearchService => new SearchService(
                $container->get(SearchCatalogRepository::class),
                $container->get(PropertyAllocationService::class),
                $container->get(ClockInterface::class),
            ),
        );
        $container->singleton(
            PropertyAvailabilityService::class,
            static fn (Container $container): PropertyAvailabilityService => new PropertyAvailabilityService(
                $container->get(SearchCatalogRepository::class),
                $container->get(PropertyAllocationService::class),
                $container->get(ClockInterface::class),
            ),
        );
        $container->singleton(
            PublicSearchController::class,
            static fn (Container $container): PublicSearchController => new PublicSearchController(
                $container->get(SearchCriteriaFactory::class),
                $container->get(SearchService::class),
                $container->get(PropertyAvailabilityService::class),
                $container->get(IdentitySession::class),
                $container->get(SessionBootstrap::class),
                $container->get(ViewRenderer::class),
                $container->get(FlashBag::class),
                $container->get(AppConfig::class),
            ),
        );
        $container->singleton(
            PreviewRepository::class,
            static fn (Container $container): PreviewRepository => new PreviewRepository($container->get(PDO::class)),
        );
        $container->singleton(
            PreviewSnapshotFactory::class,
            static fn (Container $_): PreviewSnapshotFactory => new PreviewSnapshotFactory(),
        );
        $container->singleton(
            PreviewService::class,
            static fn (Container $container): PreviewService => new PreviewService(
                $container->get(PropertyAvailabilityService::class),
                $container->get(PreviewSnapshotFactory::class),
                $container->get(PreviewRepository::class),
                $container->get(TokenGenerator::class),
                $container->get(Hasher::class),
                $container->get(ClockInterface::class),
            ),
        );
        $container->singleton(
            PreviewTokenSession::class,
            static fn (Container $_): PreviewTokenSession => new PreviewTokenSession(),
        );
        $container->singleton(
            PreviewController::class,
            static fn (Container $container): PreviewController => new PreviewController(
                $container->get(PreviewService::class),
                $container->get(SearchCriteriaFactory::class),
                $container->get(PreviewTokenSession::class),
                $container->get(IdentitySession::class),
                $container->get(SessionBootstrap::class),
                $container->get(ViewRenderer::class),
                $container->get(FlashBag::class),
                $container->get(AppConfig::class),
            ),
        );
        $container->singleton(
            BookingRepository::class,
            static fn (Container $container): BookingRepository => new BookingRepository($container->get(PDO::class)),
        );
        $container->singleton(
            PaymentRepository::class,
            static fn (Container $container): PaymentRepository => new PaymentRepository($container->get(PDO::class)),
        );
        $container->singleton(
            PaymentGateway::class,
            static fn (Container $_): PaymentGateway => new MockPaymentGateway(),
        );
        $container->singleton(
            BookingCodeGenerator::class,
            static fn (Container $_): BookingCodeGenerator => new BookingCodeGenerator(),
        );
        $container->singleton(
            BookingAccessService::class,
            static fn (Container $container): BookingAccessService => new BookingAccessService(
                $container->get(BookingRepository::class),
                $container->get(AuditTrail::class),
                $container->get(TransactionManager::class),
            ),
        );
        $container->singleton(
            BookingModificationService::class,
            static fn (Container $container): BookingModificationService => new BookingModificationService(
                $container->get(BookingRepository::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
                $container->get(ClockInterface::class),
            ),
        );
        $container->singleton(
            BookingService::class,
            static fn (Container $container): BookingService => new BookingService(
                $container->get(PreviewRepository::class),
                $container->get(BookingRepository::class),
                $container->get(PaymentRepository::class),
                $container->get(PropertyAvailabilityService::class),
                $container->get(PreviewSnapshotFactory::class),
                $container->get(InventoryReservationService::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
                $container->get(BookingCodeGenerator::class),
                $container->get(Hasher::class),
                $container->get(ClockInterface::class),
            ),
        );
        $container->singleton(
            OnlinePaymentService::class,
            static fn (Container $container): OnlinePaymentService => new OnlinePaymentService(
                $container->get(PDO::class),
                $container->get(BookingRepository::class),
                $container->get(PaymentRepository::class),
                $container->get(InventoryReservationService::class),
                $container->get(PaymentGateway::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
                $container->get(ClockInterface::class),
            ),
        );
        $container->singleton(
            PaymentExpiryService::class,
            static fn (Container $container): PaymentExpiryService => new PaymentExpiryService(
                $container->get(BookingRepository::class),
                $container->get(PaymentRepository::class),
                $container->get(InventoryReservationService::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
                $container->get(ClockInterface::class),
            ),
        );
        $container->singleton(
            BookingController::class,
            static fn (Container $container): BookingController => new BookingController(
                $container->get(BookingService::class),
                $container->get(OnlinePaymentService::class),
                $container->get(PreviewTokenSession::class),
                $container->get(IdentitySession::class),
                $container->get(SessionBootstrap::class),
                $container->get(ViewRenderer::class),
                $container->get(FlashBag::class),
                $container->get(AppConfig::class),
            ),
        );
        $container->singleton(
            BookingViewPresenter::class,
            static fn (Container $_): BookingViewPresenter => new BookingViewPresenter(),
        );
        $container->singleton(
            BookingPortalController::class,
            static fn (Container $container): BookingPortalController => new BookingPortalController(
                $container->get(BookingAccessService::class),
                $container->get(BookingModificationService::class),
                $container->get(BookingViewPresenter::class),
                $container->get(IdentitySession::class),
                $container->get(SessionBootstrap::class),
                $container->get(ViewRenderer::class),
                $container->get(FlashBag::class),
                $container->get(AppConfig::class),
            ),
        );
        $container->singleton(
            InventoryRepository::class,
            static fn (Container $container): InventoryRepository => new InventoryRepository(
                $container->get(PDO::class),
            ),
        );
        $container->singleton(
            InventoryCalendarService::class,
            static fn (Container $container): InventoryCalendarService => new InventoryCalendarService(
                $container->get(PropertyRepository::class),
                $container->get(RoomTypeRepository::class),
                $container->get(InventoryRepository::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
            ),
        );
        $container->singleton(
            InventoryReservationService::class,
            static fn (Container $container): InventoryReservationService => new TransactionalInventoryReservationService(
                $container->get(PDO::class),
                $container->get(InventoryRepository::class),
                $container->get(ClockInterface::class),
            ),
        );
        $container->singleton(
            InventoryReconciliationService::class,
            static fn (Container $container): InventoryReconciliationService => new InventoryReconciliationService(
                $container->get(InventoryRepository::class),
            ),
        );
        $container->singleton(
            PartnerInventoryController::class,
            static fn (Container $container): PartnerInventoryController => new PartnerInventoryController(
                $container->get(PropertyRepository::class),
                $container->get(RoomTypeRepository::class),
                $container->get(InventoryRepository::class),
                $container->get(InventoryCalendarService::class),
                $container->get(IdentitySession::class),
                $container->get(SessionBootstrap::class),
                $container->get(ViewRenderer::class),
                $container->get(FlashBag::class),
                $container->get(AppConfig::class),
            ),
        );
        $container->singleton(
            RoomTypeRepository::class,
            static fn (Container $container): RoomTypeRepository => new RoomTypeRepository($container->get(PDO::class)),
        );
        $container->singleton(
            FacilityRepository::class,
            static fn (Container $container): FacilityRepository => new FacilityRepository($container->get(PDO::class)),
        );
        $container->singleton(
            PropertyReadinessRepository::class,
            static fn (Container $container): PropertyReadinessRepository => new PropertyReadinessRepository(
                $container->get(PDO::class),
            ),
        );
        $container->singleton(
            PropertyReadinessChecker::class,
            static fn (Container $_): PropertyReadinessChecker => new PropertyReadinessChecker(),
        );
        $container->singleton(
            PropertyImageStorage::class,
            static fn (Container $container): PropertyImageStorage => new PropertyImageStorage(
                $projectRoot . '/storage/uploads/properties',
                $container->get(TokenGenerator::class),
            ),
        );
        $container->singleton(
            CreatePropertyService::class,
            static fn (Container $container): CreatePropertyService => new CreatePropertyService(
                $container->get(PropertyRepository::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
                $container->get(TokenGenerator::class),
            ),
        );
        $container->singleton(
            UpdatePropertyService::class,
            static fn (Container $container): UpdatePropertyService => new UpdatePropertyService(
                $container->get(PropertyRepository::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
            ),
        );
        $container->singleton(
            SubmitPropertyForReviewService::class,
            static fn (Container $container): SubmitPropertyForReviewService => new SubmitPropertyForReviewService(
                $container->get(PropertyRepository::class),
                $container->get(PropertyReadinessRepository::class),
                $container->get(PropertyReadinessChecker::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
            ),
        );
        $container->singleton(
            ReviewPropertyService::class,
            static fn (Container $container): ReviewPropertyService => new ReviewPropertyService(
                $container->get(PropertyRepository::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
            ),
        );
        $container->singleton(
            ManageRoomTypeService::class,
            static fn (Container $container): ManageRoomTypeService => new ManageRoomTypeService(
                $container->get(PropertyRepository::class),
                $container->get(RoomTypeRepository::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
            ),
        );
        $container->singleton(
            UpdateFacilitiesService::class,
            static fn (Container $container): UpdateFacilitiesService => new UpdateFacilitiesService(
                $container->get(PropertyRepository::class),
                $container->get(RoomTypeRepository::class),
                $container->get(FacilityRepository::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
            ),
        );
        $container->singleton(
            ManageCancellationPolicyService::class,
            static fn (Container $container): ManageCancellationPolicyService => new ManageCancellationPolicyService(
                $container->get(PropertyRepository::class),
                $container->get(CancellationPolicyRepository::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
            ),
        );
        $container->singleton(
            ManageRoomProductService::class,
            static fn (Container $container): ManageRoomProductService => new ManageRoomProductService(
                $container->get(PropertyRepository::class),
                $container->get(RoomProductRepository::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
            ),
        );
        $container->singleton(
            ManageDailyRateService::class,
            static fn (Container $container): ManageDailyRateService => new ManageDailyRateService(
                $container->get(PropertyRepository::class),
                $container->get(RoomProductRepository::class),
                $container->get(DailyRateRepository::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
            ),
        );
        $container->singleton(
            ManagePromotionService::class,
            static fn (Container $container): ManagePromotionService => new ManagePromotionService(
                $container->get(PropertyRepository::class),
                $container->get(PromotionRepository::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
            ),
        );
        $container->singleton(
            PartnerCommercialController::class,
            static fn (Container $container): PartnerCommercialController => new PartnerCommercialController(
                $container->get(PropertyRepository::class),
                $container->get(RoomTypeRepository::class),
                $container->get(CancellationPolicyRepository::class),
                $container->get(RoomProductRepository::class),
                $container->get(DailyRateRepository::class),
                $container->get(PromotionRepository::class),
                $container->get(ManageCancellationPolicyService::class),
                $container->get(ManageRoomProductService::class),
                $container->get(ManageDailyRateService::class),
                $container->get(ManagePromotionService::class),
                $container->get(IdentitySession::class),
                $container->get(SessionBootstrap::class),
                $container->get(ViewRenderer::class),
                $container->get(FlashBag::class),
                $container->get(AppConfig::class),
            ),
        );
        $container->singleton(
            PartnerPropertyController::class,
            static fn (Container $container): PartnerPropertyController => new PartnerPropertyController(
                $container->get(PropertyRepository::class),
                $container->get(RoomTypeRepository::class),
                $container->get(FacilityRepository::class),
                $container->get(CreatePropertyService::class),
                $container->get(UpdatePropertyService::class),
                $container->get(SubmitPropertyForReviewService::class),
                $container->get(ManageRoomTypeService::class),
                $container->get(UpdateFacilitiesService::class),
                $container->get(PropertyImageStorage::class),
                $container->get(IdentitySession::class),
                $container->get(SessionBootstrap::class),
                $container->get(ViewRenderer::class),
                $container->get(FlashBag::class),
                $container->get(AppConfig::class),
            ),
        );
        $container->singleton(
            AdminPropertyController::class,
            static fn (Container $container): AdminPropertyController => new AdminPropertyController(
                $container->get(PropertyRepository::class),
                $container->get(PropertyReadinessRepository::class),
                $container->get(ReviewPropertyService::class),
                $container->get(IdentitySession::class),
                $container->get(SessionBootstrap::class),
                $container->get(ViewRenderer::class),
                $container->get(FlashBag::class),
                $container->get(AppConfig::class),
            ),
        );
        $container->instance(
            PropertyImageController::class,
            new PropertyImageController($projectRoot . '/storage/uploads/properties'),
        );

        // Đăng ký router
        $router = new Router();
        $registerWebRoutes = require $projectRoot . '/src/routes/web.php';
        $registerApiRoutes = require $projectRoot . '/src/routes/api.php';
        $registerWebRoutes($router, $container);
        $registerApiRoutes($router, $container);

        $container->instance(Router::class, $router);
        $exceptionHandler = new ExceptionHandler($appConfig, $container->get(Logger::class));
        $container->instance(ExceptionHandler::class, $exceptionHandler);

        return new self($router, $container, $exceptionHandler);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function run(): void
    {
        try {
            $request = Request::fromGlobals();
            $response = $this->handle($request);
        } catch (Throwable $exception) {
            $request = Request::fallbackFromGlobals();
            $response = $this->exceptionHandler->handle($exception, $request);
        }

        $response->send();
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->router->dispatch($request);
        } catch (Throwable $exception) {
            $response = $this->exceptionHandler->handle($exception, $request);
        }

        return $response->withHeader('X-Request-ID', $request->requestId);
    }
}
