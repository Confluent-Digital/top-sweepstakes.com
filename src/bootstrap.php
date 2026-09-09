<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$rootDir = dirname(__DIR__);

// safeLoad : ne plante pas si .env manque et n'ecrase pas ce que docker-compose
// a deja injecte dans l'environnement du conteneur.
Dotenv::createImmutable($rootDir)->safeLoad();

date_default_timezone_set($_ENV['TZ'] ?? 'America/New_York');

if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => ($_ENV['APP_ENV'] ?? 'development') === 'production',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('tsw_session');
    session_start();
}

return $rootDir;
