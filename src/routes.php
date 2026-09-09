<?php

declare(strict_types=1);

use App\Core\Database;
use App\Modules\Legal\Controllers\ComplianceController;
use App\Modules\Legal\Controllers\LegalController;
use App\Modules\Sweepstakes\Controllers\SweepstakeController;
use App\Modules\Tracking\Controllers\OutController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** @var Slim\App $app */
$container = $app->getContainer();

// ⚠️ ORDRE SIGNIFICATIF
//
// La route de concours `/{slug}` est un attrape-tout : declaree trop tot, elle
// avale /health, /out, les pages legales et le back-office, qui repondraient
// alors 404 sans que rien ne le signale. Toutes les routes fixes viennent
// AVANT, la famille `/{slug}` vient en dernier.
// Voir .claude/rules/architecture.md.

// ---------------------------------------------------------------- Sonde
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

// ---------------------------------------------------------------- Sortie vers une offre
// Le clic est enregistre cote serveur AVANT la redirection : voir OutController.
$app->get('/out/{token}', OutController::class);

// ---------------------------------------------------------------- Conformite
// CAN-SPAM et CCPA/CPRA : ces pages sont liees depuis le pied de page de chaque
// page du site et doivent aboutir a un traitement reel, pas a un formulaire
// decoratif.
$app->map(['GET', 'POST'], '/unsubscribe', ComplianceController::class . ':unsubscribe');
$app->map(['GET', 'POST'], '/do-not-sell', ComplianceController::class . ':doNotSell');

// ---------------------------------------------------------------- Pages legales
$app->get('/{page:privacy|terms|legal|partners|cookies}', LegalController::class . ':show');

// ---------------------------------------------------------------- Accueil
$app->get('/', SweepstakeController::class . ':home');

// ---------------------------------------------------------------- Tunnel de concours
// Les sous-routes precedent la landing : /{slug}/entry doit etre reconnue avant
// que /{slug} ne capte le premier segment.
$app->map(['GET', 'POST'], '/{slug}/entry', SweepstakeController::class . ':entry');
$app->map(['GET', 'POST'], '/{slug}/details', SweepstakeController::class . ':details');
$app->get('/{slug}/offers', SweepstakeController::class . ':offers');
$app->get('/{slug}/thank-you', SweepstakeController::class . ':thankYou');
$app->get('/{slug}/rules', SweepstakeController::class . ':rules');
$app->get('/{slug}', SweepstakeController::class . ':landing');
