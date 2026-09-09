<?php

declare(strict_types=1);

namespace App\Modules\Drawings\Controllers;

use App\Modules\Admin\Models\Repositories\AdminUserRepository;
use App\Modules\Drawings\Models\Repositories\DrawingRepository;
use App\Modules\Drawings\Services\DrawingService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Ecran des tirages.
 *
 * Un tirage n'est jamais automatique : il se declenche ici ou en ligne de
 * commande, et l'operateur qui l'a lance est enregistre. Un cron qui
 * designerait des gagnants chaque nuit le ferait sans que personne ne l'ait
 * decide ni ne sache quand.
 */
final class DrawingAdminController
{
    private const STATUSES = ['pending', 'notified', 'confirmed', 'forfeited', 'unreachable'];

    public function __construct(
        private Twig $view,
        private DrawingRepository $drawings,
        private DrawingService $service,
        private AdminUserRepository $users,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/drawings/index.html.twig', [
            'drawings' => $this->drawings->all(),
            'pending' => $this->drawings->sweepstakesAwaitingDrawing(),
            // L'annee precedente est la premiere tirable : l'annee en cours
            // peut encore designer des finalistes.
            'grand_prize_year' => (int) date('Y') - 1,
            'grand_prize_done' => $this->drawings->findGrandPrize((int) date('Y') - 1) !== null,
            'flash' => $request->getQueryParams()['flash'] ?? null,
            'error' => $request->getQueryParams()['error'] ?? null,
        ]);
    }

    /** @param array<string,string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $drawings = $this->drawings->all();
        $drawing = null;
        foreach ($drawings as $row) {
            if ((int) $row['drawing_id'] === (int) $args['id']) {
                $drawing = $row;
                break;
            }
        }
        if ($drawing === null) {
            throw new HttpNotFoundException($request);
        }

        // La verification refait le tirage a partir de ce qui a ete enregistre.
        // C'est la reponse a « comment ce gagnant a-t-il ete choisi ? », et elle
        // doit pouvoir etre produite des mois plus tard.
        $pool = $drawing['drawing_type'] === 'sweepstake'
            ? $this->drawings->eligibleForSweepstake((int) $drawing['drawing_id_sweepstake'])
            : $this->drawings->finalistsForYear((int) $drawing['drawing_year']);

        $replay = $this->service->replay($drawing, $pool);
        $winners = $this->drawings->winners((int) $drawing['drawing_id']);

        $recorded = array_map(
            static fn(array $w): int => (int) $w['drawing_winner_id_lead'],
            $winners
        );

        return $this->view->render($response, 'admin/drawings/show.html.twig', [
            'drawing' => $drawing,
            'winners' => $winners,
            'statuses' => self::STATUSES,
            'replay' => $replay,
            'replay_matches' => $recorded === $replay['winners'],
            'pool_now' => count($pool),
            'flash' => $request->getQueryParams()['flash'] ?? null,
        ]);
    }

    /** @param array<string,string> $args */
    public function runSweepstake(Request $request, Response $response, array $args): Response
    {
        $result = $this->service->drawSweepstake((int) $args['id'], $this->operator($request));
        $this->log($request, 'drawing.sweepstake', (string) $args['id'], $result['message']);

        return $this->back($response, $result);
    }

    public function runGrandPrize(Request $request, Response $response): Response
    {
        $input = (array) $request->getParsedBody();
        $year = (int) ($input['year'] ?? (date('Y') - 1));

        $result = $this->service->drawGrandPrize($year, $this->operator($request));
        $this->log($request, 'drawing.grand_prize', (string) $year, $result['message']);

        return $this->back($response, $result);
    }

    /** @param array<string,string> $args */
    public function updateWinner(Request $request, Response $response, array $args): Response
    {
        $input = (array) $request->getParsedBody();
        $status = (string) ($input['status'] ?? '');

        if (in_array($status, self::STATUSES, true)) {
            $this->drawings->updateWinnerStatus((int) $args['winner'], $status);
            $this->log($request, 'drawing.winner_status', (string) $args['winner'], $status);
        }

        $location = sprintf(
            '/admin/drawings/%d?flash=%s',
            (int) $args['id'],
            urlencode('Statut mis a jour.')
        );

        return $response->withHeader('Location', $location)->withStatus(302);
    }

    /** @param array{ok: bool, message: string} $result */
    private function back(Response $response, array $result): Response
    {
        $key = $result['ok'] ? 'flash' : 'error';

        return $response
            ->withHeader('Location', '/admin/drawings?' . $key . '=' . urlencode($result['message']))
            ->withStatus(302);
    }

    private function operator(Request $request): string
    {
        $user = $request->getAttribute('admin_user');
        return is_array($user) ? (string) $user['admin_user_email'] : 'back-office';
    }

    private function log(Request $request, string $action, string $target, string $detail): void
    {
        $user = $request->getAttribute('admin_user');
        $this->users->log(
            is_array($user) ? (int) $user['admin_user_id'] : null,
            $action,
            $target,
            $detail,
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '')
        );
    }
}
