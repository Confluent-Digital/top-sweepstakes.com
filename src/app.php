<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Signer;
use App\Middleware\SecurityHeadersMiddleware;
use DI\Container;
use GuzzleHttp\Client;
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
