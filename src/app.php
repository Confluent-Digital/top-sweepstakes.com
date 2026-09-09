<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Session\PhpSessionStore;
use App\Core\Session\SessionStore;
use App\Core\Signer;
use App\Middleware\SecurityHeadersMiddleware;
use App\Middleware\TemplateContextMiddleware;
use App\Modules\Admin\Controllers\AuthController;
use App\Modules\Admin\Controllers\DashboardController;
use App\Modules\Admin\Controllers\LeadAdminController;
use App\Modules\Admin\Controllers\OfferAdminController;
use App\Modules\Admin\Controllers\SweepstakeAdminController;
use App\Modules\Admin\Middleware\AdminAuthMiddleware;
use App\Modules\Admin\Models\Repositories\AdminLeadRepository;
use App\Modules\Admin\Models\Repositories\AdminOfferRepository;
use App\Modules\Admin\Models\Repositories\AdminSweepstakeRepository;
use App\Modules\Admin\Models\Repositories\AdminUserRepository;
use App\Modules\Admin\Services\ImageUploadService;
use App\Modules\Leads\Models\Repositories\ConsentRepository;
use App\Modules\Leads\Models\Repositories\LeadRepository;
use App\Modules\Leads\Models\Repositories\SuppressionRepository;
use App\Modules\Leads\Services\ConsentCatalog;
use App\Modules\Leads\Services\ConsentRecorder;
use App\Modules\Leads\Services\LeadValidator;
use App\Modules\Leads\Services\SpamGuard;
use App\Modules\Leads\Tasks\GdprPurgeTask;
use App\Modules\Legal\Controllers\ComplianceController;
use App\Modules\Legal\Services\LegalContentService;
use App\Modules\Offers\Models\Repositories\OfferRepository;
use App\Modules\Offers\Services\OfferDisplayService;
use App\Modules\Offers\Services\OfferLinkBuilder;
use App\Modules\Offers\Services\OfferSelector;
use App\Modules\Offers\Services\TargetingService;
use App\Modules\Platform\Models\Repositories\PlatformReportRepository;
use App\Modules\Platform\Services\PlatformReportClient;
use App\Modules\Platform\Tasks\PlatformReportTask;
use App\Modules\Stats\Controllers\StatsController;
use App\Modules\Stats\Models\Repositories\StatsRepository;
use App\Modules\Stats\Services\SidParser;
use App\Modules\Stats\Tasks\StatsRollupTask;
use App\Modules\Sweepstakes\Models\Repositories\SweepstakeRepository;
use App\Modules\Sweepstakes\Services\DeviceDetector;
use App\Modules\Sweepstakes\Services\VisitorContext;
use DI\Container;
use App\Modules\Tracking\Models\Repositories\OfferEventRepository;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Twig\TwigFunction;

$rootDir = require __DIR__ . '/bootstrap.php';

$container = new Container();

// ---------------------------------------------------------------- Noyau
$container->set(Config::class, fn() => new Config($_ENV));
$container->set(Database::class, fn(Container $c) => new Database($c->get(Config::class)));
$container->set(Signer::class, fn(Container $c) => new Signer($c->get(Config::class)));
$container->set(Logger::class, fn(Container $c) => Logger::create($c->get(Config::class), $rootDir));
$container->set(Client::class, fn() => new Client(['timeout' => 10, 'connect_timeout' => 3]));
$container->set(LoggerInterface::class, fn(Container $c) => $c->get(Logger::class));
$container->set(SessionStore::class, fn() => new PhpSessionStore());

// ---------------------------------------------------------------- Repositories
$container->set(SweepstakeRepository::class, fn(Container $c) => new SweepstakeRepository($c->get(Database::class)));
$container->set(OfferRepository::class, fn(Container $c) => new OfferRepository($c->get(Database::class)));
$container->set(LeadRepository::class, fn(Container $c) => new LeadRepository($c->get(Database::class)));
$container->set(ConsentRepository::class, fn(Container $c) => new ConsentRepository($c->get(Database::class)));
$container->set(SuppressionRepository::class, fn(Container $c) => new SuppressionRepository($c->get(Database::class)));
$container->set(OfferEventRepository::class, fn(Container $c) => new OfferEventRepository($c->get(Database::class)));
$container->set(AdminUserRepository::class, fn(Container $c) => new AdminUserRepository($c->get(Database::class)));
$container->set(
    AdminSweepstakeRepository::class,
    fn(Container $c) => new AdminSweepstakeRepository($c->get(Database::class))
);
$container->set(AdminOfferRepository::class, fn(Container $c) => new AdminOfferRepository($c->get(Database::class)));
$container->set(AdminLeadRepository::class, fn(Container $c) => new AdminLeadRepository($c->get(Database::class)));
$container->set(ImageUploadService::class, fn() => new ImageUploadService($rootDir . '/public'));
$container->set(
    PlatformReportRepository::class,
    fn(Container $c) => new PlatformReportRepository($c->get(Database::class))
);
$container->set(StatsRepository::class, fn(Container $c) => new StatsRepository($c->get(Database::class)));

// ---------------------------------------------------------------- Revenus et statistiques
$container->set(SidParser::class, fn() => new SidParser());
$container->set(PlatformReportClient::class, fn(Container $c) => new PlatformReportClient(
    $c->get(Config::class),
    $c->get(Client::class),
    $c->get(LoggerInterface::class),
));
$container->set(PlatformReportTask::class, fn(Container $c) => new PlatformReportTask(
    $c->get(PlatformReportClient::class),
    $c->get(PlatformReportRepository::class),
    $c->get(LoggerInterface::class),
));
$container->set(GdprPurgeTask::class, fn(Container $c) => new GdprPurgeTask(
    $c->get(Database::class),
    $c->get(StatsRepository::class),
    $c->get(LoggerInterface::class),
));
$container->set(StatsRollupTask::class, fn(Container $c) => new StatsRollupTask(
    $c->get(StatsRepository::class),
    $c->get(PlatformReportRepository::class),
    $c->get(OfferRepository::class),
    $c->get(SidParser::class),
    $c->get(LoggerInterface::class),
));

// ---------------------------------------------------------------- Services metier
$container->set(DeviceDetector::class, fn() => new DeviceDetector());
$container->set(TargetingService::class, fn() => new TargetingService());
$container->set(LeadValidator::class, fn() => new LeadValidator());
$container->set(SpamGuard::class, fn() => new SpamGuard());
$container->set(ConsentCatalog::class, fn(Container $c) => new ConsentCatalog($c->get(Config::class)));
$container->set(ConsentRecorder::class, fn(Container $c) => new ConsentRecorder($c->get(ConsentRepository::class)));
$container->set(OfferSelector::class, fn(Container $c) => new OfferSelector($c->get(TargetingService::class)));
$container->set(OfferLinkBuilder::class, fn(Container $c) => new OfferLinkBuilder($c->get(Config::class)));
$container->set(OfferDisplayService::class, fn(Container $c) => new OfferDisplayService(
    $c->get(OfferRepository::class),
    $c->get(OfferSelector::class),
    $c->get(OfferLinkBuilder::class),
    $c->get(OfferEventRepository::class),
    $c->get(Signer::class),
));
$container->set(VisitorContext::class, fn(Container $c) => new VisitorContext(
    $c->get(SessionStore::class),
    $c->get(DeviceDetector::class),
));
$container->set(ComplianceController::class, fn(Container $c) => new ComplianceController(
    $c->get(Twig::class),
    $c->get(SuppressionRepository::class),
));
// ---------------------------------------------------------------- Back-office
$container->set(AdminAuthMiddleware::class, fn(Container $c) => new AdminAuthMiddleware(
    $c->get(SessionStore::class),
    $c->get(AdminUserRepository::class),
));
$container->set(AuthController::class, fn(Container $c) => new AuthController(
    $c->get(Twig::class),
    $c->get(SessionStore::class),
    $c->get(AdminUserRepository::class),
));
$container->set(DashboardController::class, fn(Container $c) => new DashboardController(
    $c->get(Twig::class),
    $c->get(Database::class),
));
$container->set(SweepstakeAdminController::class, fn(Container $c) => new SweepstakeAdminController(
    $c->get(Twig::class),
    $c->get(AdminSweepstakeRepository::class),
    $c->get(SweepstakeRepository::class),
    $c->get(AdminOfferRepository::class),
    $c->get(AdminUserRepository::class),
    $c->get(ImageUploadService::class),
));
$container->set(OfferAdminController::class, fn(Container $c) => new OfferAdminController(
    $c->get(Twig::class),
    $c->get(AdminOfferRepository::class),
    $c->get(OfferRepository::class),
    $c->get(OfferLinkBuilder::class),
    $c->get(AdminUserRepository::class),
    $c->get(ImageUploadService::class),
));
$container->set(StatsController::class, fn(Container $c) => new StatsController(
    $c->get(Twig::class),
    $c->get(Database::class),
));
$container->set(LeadAdminController::class, fn(Container $c) => new LeadAdminController(
    $c->get(Twig::class),
    $c->get(AdminLeadRepository::class),
    $c->get(AdminSweepstakeRepository::class),
    $c->get(LeadRepository::class),
    $c->get(ConsentRepository::class),
    $c->get(AdminUserRepository::class),
));

$container->set(LegalContentService::class, fn(Container $c) => new LegalContentService(
    $c->get(Config::class),
    $c->get(Client::class),
    $c->get(LoggerInterface::class),
    $rootDir . '/cache/legal',
));

/** @var Config $config */
$config = $container->get(Config::class);

// ---------------------------------------------------------------- Twig
$container->set(Twig::class, function (Container $c) use ($rootDir, $config) {
    $twig = Twig::create($rootDir . '/src/Views', [
        'cache' => $config->isProduction() ? $rootDir . '/cache/twig' : false,
        'debug' => $config->bool('APP_DEBUG'),
        'auto_reload' => true,
        'strict_variables' => false,
    ]);
    $env = $twig->getEnvironment();
    $env->addGlobal('app_name', $config->get('APP_NAME', 'Top Sweepstakes'));
    $env->addGlobal('app_url', rtrim((string) $config->get('APP_URL', ''), '/'));
    $env->addFunction(new TwigFunction('csrf_token', static fn(): string => Csrf::token()));

    // Existence d'un fichier servi depuis `public/`. Sert a n'annoncer une
    // variante WebP que si elle a reellement ete ecrite : un <source> qui
    // pointe dans le vide fait afficher un cadre casse chez les navigateurs
    // qui l'ont retenu, et les visuels deposes avant la migration n'en ont pas.
    // Le chemin est contraint a `public/` et le resultat memorise : la page
    // d'accueil liste tous les concours ouverts, un stat() par vignette et par
    // requete se paierait sur le seul ecran du site qui a vocation a etre
    // indexe.
    $publicDir = $rootDir . '/public';
    $seen = [];
    $env->addFunction(new TwigFunction(
        'public_file_exists',
        static function (string $path) use ($publicDir, &$seen): bool {
            $clean = '/' . ltrim($path, '/');
            if (str_contains($clean, '..')) {
                return false;
            }
            return $seen[$clean] ??= is_file($publicDir . $clean);
        }
    ));

    return $twig;
});

AppFactory::setContainer($container);
$app = AppFactory::create();

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->add(TwigMiddleware::createFromContainer($app, Twig::class));
$app->add(new TemplateContextMiddleware($container->get(Twig::class)));
$app->add(new SecurityHeadersMiddleware());
$app->addErrorMiddleware($config->bool('APP_DEBUG'), true, true, $container->get(Logger::class));

require __DIR__ . '/routes.php';

return $app;
