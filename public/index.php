<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/** @var \Slim\App $app */
$app = require __DIR__ . '/../src/app.php';

$app->run();
