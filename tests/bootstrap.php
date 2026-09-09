<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

date_default_timezone_set($_ENV['TZ'] ?? 'America/New_York');
