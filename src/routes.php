<?php

declare(strict_types=1);

use App\Core\Database;
use App\Middleware\CsrfMiddleware;
use App\Modules\Admin\Controllers\AuthController;
use App\Modules\Admin\Controllers\DashboardController;
use App\Modules\Admin\Controllers\LeadAdminController;
use App\Modules\Admin\Controllers\OfferAdminController;
use App\Modules\Admin\Controllers\SettingController;
use App\Modules\Admin\Controllers\SweepstakeAdminController;
use App\Modules\Admin\Middleware\AdminAuthMiddleware;
use App\Modules\Stats\Controllers\StatsController;
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

// ---------------------------------------------------------------- Back-office
// AdminAuthMiddleware est pose sur le GROUPE et non route par route : toute
// route ajoutee ici est protegee par construction. C'est la seule facon de ne
// pas laisser un ecran ouvert par oubli.
$app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $admin): void {
    $admin->map(['GET', 'POST'], '/login', AuthController::class . ':login');
    $admin->get('/logout', AuthController::class . ':logout');

    $admin->get('', DashboardController::class . ':index');
    $admin->get('/', DashboardController::class . ':index');

    $admin->get('/sweepstakes', SweepstakeAdminController::class . ':index');
    $admin->get('/sweepstakes/new', SweepstakeAdminController::class . ':create');
    $admin->map(['GET', 'POST'], '/sweepstakes/{id:[0-9]+}/edit', SweepstakeAdminController::class . ':edit');
    $admin->post('/sweepstakes/{id:[0-9]+}/duplicate', SweepstakeAdminController::class . ':duplicate');
    $admin->post('/sweepstakes/{id:[0-9]+}/offers', SweepstakeAdminController::class . ':attachOffers');

    $admin->get('/offers', OfferAdminController::class . ':index');
    $admin->get('/offers/new', OfferAdminController::class . ':create');
    $admin->map(['GET', 'POST'], '/offers/{id:[0-9]+}/edit', OfferAdminController::class . ':edit');

    $admin->get('/leads', LeadAdminController::class . ':index');
    $admin->get('/leads/export', LeadAdminController::class . ':export');
    $admin->get('/leads/{id:[0-9]+}', LeadAdminController::class . ':show');

    $admin->map(['GET', 'POST'], '/settings', SettingController::class . ':edit');

    $admin->get('/stats/offers', StatsController::class . ':offers');
    $admin->get('/stats/sources', StatsController::class . ':sources');
})
    ->add(CsrfMiddleware::class)
    ->add(AdminAuthMiddleware::class);

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
// Une offre par page. L'etape precede la route generique du parcours.
$app->get('/{slug}/offers/{step:[0-9]+}', SweepstakeController::class . ':offerStep');
$app->get('/{slug}/thank-you', SweepstakeController::class . ':thankYou');
$app->get('/{slug}/rules', SweepstakeController::class . ':rules');
$app->get('/{slug}', SweepstakeController::class . ':landing');
