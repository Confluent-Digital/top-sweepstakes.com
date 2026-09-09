<?php

declare(strict_types=1);

namespace App\Modules\Tracking\Controllers;

use App\Modules\Leads\Models\Repositories\LeadRepository;
use App\Modules\Offers\Models\Repositories\OfferRepository;
use App\Modules\Offers\Services\OfferDisplayService;
use App\Modules\Sweepstakes\Services\VisitorContext;
use App\Modules\Tracking\Models\Repositories\OfferEventRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Sortie vers une offre : le clic est enregistre COTE SERVEUR, puis le
 * visiteur est redirige.
 *
 * Pourquoi pas un lien direct vers la regie double d'un appel AJAX, comme dans
 * meilleursconcours.com : un bloqueur, un JavaScript en erreur ou un onglet
 * ferme trop vite perdent l'appel — et le clic, c'est le revenu. Ici le clic
 * est ecrit avant la redirection, donc il ne peut pas manquer.
 *
 * Trois autres proprietes tiennent a ce passage par notre serveur :
 *  - l'URL de la regie n'apparait pas dans le HTML ;
 *  - le participant est connu au moment du clic, donc le lien lead <-> offre
 *    existe et une facturation contestee peut se recouper ;
 *  - la destination vient exclusivement de la base, ce qui interdit d'en faire
 *    une redirection ouverte.
 */
final class OutController
{
    public function __construct(
        private OfferRepository $offers,
        private OfferDisplayService $display,
        private OfferEventRepository $events,
        private LeadRepository $leads,
        private VisitorContext $visitor,
        private LoggerInterface $logger,
    ) {
    }

    /** @param array<string,string> $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $this->visitor->bootstrap($request);
        $sessionUid = $this->visitor->sessionUid();

        // Le jeton est signe et lie a la session : un identifiant d'offre
        // sequentiel ne suffit pas a fabriquer un clic.
        $offerId = $this->display->resolveToken((string) $args['token'], $sessionUid);
        if ($offerId === null) {
            throw new HttpNotFoundException($request);
        }

        $offer = $this->offers->findById($offerId);
        if ($offer === null || !(bool) $offer['offer_active']) {
            throw new HttpNotFoundException($request);
        }

        $leadId = $this->leadIdFor($request);
        $lead = $leadId !== null ? $this->leads->findById($leadId) : null;

        // Un double-clic ou un retour arriere du navigateur ne doit pas compter
        // deux fois : la regie ne paie qu'un clic, notre compte doit dire pareil.
        if (!$this->events->hasRecentClick($sessionUid, $offerId)) {
            $this->events->record([
                'offer_event_session_uid' => $sessionUid,
                'offer_event_id_lead' => $leadId,
                'offer_event_id_sweepstake' => (int) ($lead['lead_id_sweepstake'] ?? 0),
                'offer_event_id_variant' => (int) ($lead['lead_id_variant'] ?? 0),
                'offer_event_id_offer' => $offerId,
                'offer_event_id_block' => null,
                'offer_event_step' => 3,
                'offer_event_position' => 0,
                'offer_event_device' => $this->visitor->device(),
                'offer_event_action' => 'click',
                'offer_event_subid' => $this->visitor->subid(),
                'created_day' => date('Y-m-d'),
            ]);
        }

        try {
            $url = $this->display->outboundUrl($offer, [
                'sweepstake_id' => (int) ($lead['lead_id_sweepstake'] ?? 0),
                'subid' => $this->visitor->subid(),
                'email_md5' => (string) ($lead['lead_email_md5'] ?? ''),
                'lead' => $this->passthroughValues($lead),
            ]);
        } catch (\RuntimeException $e) {
            // Offre mal parametree : le clic est deja compte, mais on ne peut
            // pas envoyer le visiteur nulle part. On le ramene sur le site
            // plutot que de lui montrer une erreur.
            $this->logger->error('Lien de sortie impossible', [
                'offer_id' => $offerId,
                'message' => $e->getMessage(),
            ]);
            throw new HttpNotFoundException($request);
        }

        return $response->withHeader('Location', $url)->withStatus(302);
    }

    private function leadIdFor(Request $request): ?int
    {
        $sweepstakeId = (int) ($request->getQueryParams()['s'] ?? 0);
        if ($sweepstakeId > 0) {
            return $this->visitor->leadId($sweepstakeId);
        }
        return null;
    }

    /**
     * Valeurs candidates au passage vers l'annonceur. Le filtrage effectif est
     * fait par OfferLinkBuilder, a partir de la liste blanche de l'offre : ce
     * qui est fourni ici n'est pas ce qui sortira.
     *
     * @param array<string,mixed>|null $lead
     * @return array<string,string>
     */
    private function passthroughValues(?array $lead): array
    {
        if ($lead === null) {
            return [];
        }
        return [
            'email' => (string) ($lead['lead_email'] ?? ''),
            'first_name' => (string) ($lead['lead_first_name'] ?? ''),
            'last_name' => (string) ($lead['lead_last_name'] ?? ''),
            'address' => (string) ($lead['lead_address'] ?? ''),
            'city' => (string) ($lead['lead_city'] ?? ''),
            'state' => (string) ($lead['lead_state'] ?? ''),
            'zip' => (string) ($lead['lead_zip'] ?? ''),
            'phone' => (string) ($lead['lead_phone'] ?? ''),
            'dob' => (string) ($lead['lead_dob'] ?? ''),
            'gender' => (string) ($lead['lead_gender'] ?? ''),
        ];
    }
}
