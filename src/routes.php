<?php

declare(strict_types=1);

use App\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** @var Slim\App $app */
$container = $app->getContainer();

// Sonde de vie : utilisee par le smoke test de deploiement. Verifie que le
// conteneur repond ET que la base est joignable, sinon un 200 ne prouve rien.
$app->get('/health', function (Request $request, Response $response) use ($container): Response {
    $status = ['status' => 'ok', 'time' => date('c')];
    try {
        $container?->get(Database::class)->connection()->executeQuery('SELECT 1');
        $status['database'] = 'ok';
    } catch (\Throwable) {
        $status['status'] = 'degraded';
        $status['database'] = 'ko';
    }
    $response->getBody()->write((string) json_encode($status, JSON_PRETTY_PRINT));
    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus($status['status'] === 'ok' ? 200 : 503);
});
