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
use App\Modules\Leads\Models\Repositories\ConsentRepository;
use App\Modules\Leads\Models\Repositories\LeadRepository;
use App\Modules\Leads\Models\Repositories\SuppressionRepository;
use App\Modules\Leads\Services\ConsentCatalog;
use App\Modules\Leads\Services\ConsentRecorder;
use App\Modules\Leads\Services\LeadValidator;
use App\Modules\Legal\Controllers\ComplianceController;
use App\Modules\Legal\Services\LegalContentService;
use App\Modules\Offers\Models\Repositories\OfferRepository;
use App\Modules\Offers\Services\OfferDisplayService;
use App\Modules\Offers\Services\OfferLinkBuilder;
use App\Modules\Offers\Services\OfferSelector;
use App\Modules\Offers\Services\TargetingService;
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

// ---------------------------------------------------------------- Services metier
$container->set(DeviceDetector::class, fn() => new DeviceDetector());
$container->set(TargetingService::class, fn() => new TargetingService());
$container->set(LeadValidator::class, fn() => new LeadValidator());
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
    return $twig;
});

AppFactory::setContainer($container);
$app = AppFactory::create();

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->add(TwigMiddleware::createFromContainer($app, Twig::class));
$app->add(new SecurityHeadersMiddleware());
$app->addErrorMiddleware($config->bool('APP_DEBUG'), true, true, $container->get(Logger::class));

require __DIR__ . '/routes.php';

return $app;
