<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Models\Repositories\AdminUserRepository;
use App\Modules\Admin\Models\Repositories\ReadinessRepository;
use App\Modules\Admin\Services\ReadinessCatalog;
use App\Modules\Admin\Services\ReadinessService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Ecran des reserves d'ouverture.
 *
 * Il repond a une seule question : peut-on ouvrir le robinet a trafic
 * aujourd'hui, et si non, qu'est-ce qui l'en empeche exactement.
 */
final class ReadinessController
{
    public function __construct(
        private Twig $view,
        private ReadinessService $readiness,
        private ReadinessRepository $decisions,
        private AdminUserRepository $users,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        // Reevaluer force le calcul : apres avoir corrige une offre, on veut
        // voir l'alerte tomber sans attendre l'expiration du cache.
        $report = $this->readiness->report(isset($query['refresh']));

        // Le bandeau du gabarit a ete alimente par le middleware, AVANT ce
        // recalcul. Sans cette republication, la page affichait « n reserves
        // bloquantes » en haut et « Aucune reserve ouverte » deux centimetres
        // plus bas — sur l'ecran meme qui decide d'ouvrir le budget
        // d'acquisition. Les deux blocs doivent parler de la meme evaluation.
        $this->view->getEnvironment()->addGlobal('readiness', $report['counts']);

        return $this->view->render($response, 'admin/readiness.html.twig', [
            'report' => $report,
            'severities' => ReadinessCatalog::SEVERITIES,
            'statuses' => ReadinessCatalog::STATUSES,
            'saved' => $query['saved'] ?? null,
            'error' => $query['error'] ?? null,
        ]);
    }

    /**
     * Enregistre une decision sur une reserve.
     *
     * Deux garde-fous, tous deux destines a empecher qu'on fasse taire une
     * alerte sans rien dire :
     *
     * 1. accepter un risque exige une note ;
     * 2. fermer une reserve dont le controle automatique voit TOUJOURS le
     *    probleme exige une note aussi — c'est le cas ou la contradiction entre
     *    la machine et l'humain doit rester lisible dans six mois.
     */
    public function decide(Request $request, Response $response): Response
    {
        $input = (array) $request->getParsedBody();
        $key = trim((string) ($input['key'] ?? ''));
        $status = trim((string) ($input['status'] ?? 'open'));
        $note = trim((string) ($input['note'] ?? ''));

        $item = $this->find($key);
        if ($item === null || !array_key_exists($status, ReadinessCatalog::STATUSES)) {
            return $this->back($response, 'error=unknown');
        }

        // `auto && open`, et non `open` seul : un point declare est ouvert par
        // construction, exiger une note pour le fermer reviendrait a en
        // demander une a chaque fois — et le message parlerait d'un controle
        // qui, pour un declare, n'existe pas.
        //
        // Pas `contradicted` non plus : la contradiction n'existe qu'APRES la
        // decision, elle vaut donc toujours faux au moment ou l'on decide. Ce
        // qui se juge ici, c'est l'etat du controle a cet instant.
        $controleAuRouge = (bool) $item['auto'] && (bool) $item['open'];
        if ($status !== 'open' && $note === '' && ($status === 'accepted' || $controleAuRouge)) {
            return $this->back($response, 'error=note#' . $key);
        }

        $user = $request->getAttribute('admin_user');
        $by = is_array($user) ? (string) ($user['admin_user_name'] ?: $user['admin_user_email']) : '';

        $this->decisions->decide($key, $status, $note, $by);

        $this->users->log(
            is_array($user) ? (int) $user['admin_user_id'] : null,
            'readiness.decide',
            $key,
            $status . ($note !== '' ? ' — ' . mb_substr($note, 0, 500) : ''),
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '')
        );

        return $this->back($response, 'saved=1#' . $key);
    }

    /** @return array<string,mixed>|null */
    private function find(string $key): ?array
    {
        foreach ($this->readiness->report()['items'] as $item) {
            if ((string) $item['key'] === $key) {
                return $item;
            }
        }
        return null;
    }

    private function back(Response $response, string $suffix): Response
    {
        return $response->withHeader('Location', '/admin/readiness?' . $suffix)->withStatus(302);
    }
}
