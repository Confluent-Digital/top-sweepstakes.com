<?php

declare(strict_types=1);

namespace App\Core;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;

final class Logger
{
    public static function create(Config $config, string $rootDir): MonologLogger
    {
        $logger = new MonologLogger($config->get('APP_NAME', 'top-sweepstakes'));
        $level = $config->isProduction() ? Level::Info : Level::Debug;
        $logger->pushHandler(new StreamHandler($rootDir . '/logs/app.log', $level));
        return $logger;
    }
}
