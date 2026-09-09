<?php

declare(strict_types=1);

namespace App\Modules\Sweepstakes\Controllers;

use App\Modules\Leads\Models\Repositories\LeadRepository;
use App\Modules\Leads\Models\Repositories\SuppressionRepository;
use App\Modules\Leads\Services\ConsentCatalog;
use App\Modules\Leads\Services\ConsentRecorder;
use App\Modules\Leads\Services\LeadValidator;
use App\Modules\Leads\Services\SpamGuard;
use App\Modules\Leads\Services\UsStates;
use App\Modules\Offers\Services\OfferDisplayService;
use App\Modules\Sweepstakes\Models\Repositories\SweepstakeRepository;
use App\Modules\Sweepstakes\Services\VisitorContext;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Le tunnel public, pour TOUS les concours.
 *
 * Aucun chemin de gabarit ne depend d'un identifiant de concours : la
 * difference entre deux concours tient a des donnees (champs, theme, dotation,
 * offres), jamais a des fichiers. Voir .claude/rules/sweepstakes.md.
 */
final class SweepstakeController
{
    public function __construct(
        private Twig $view,
        private SweepstakeRepository $sweepstakes,
        private LeadRepository $leads,
        private SuppressionRepository $suppressions,
        private LeadValidator $validator,
        private ConsentCatalog $consents,
        private ConsentRecorder $recorder,
        private SpamGuard $spamGuard,
        private LoggerInterface $logger,
        private OfferDisplayService $offers,
        private VisitorContext $visitor,
    ) {
    }

    public function home(Request $request, Response $response): Response
    {
        $this->visitor->bootstrap($request);

        return $this->view->render($response, 'front/home.html.twig', [
            'sweepstakes' => $this->sweepstakes->listPublished(),
        ]);
    }

    /** @param array<string,string> $args */
    public function landing(Request $request, Response $response, array $args): Response
    {
        $sweepstake = $this->requireOpenSweepstake($request, (string) $args['slug']);
        $this->visitor->bootstrap($request);

        return $this->view->render($response, 'front/landing.html.twig', [
            'sweepstake' => $sweepstake,
            'theme' => $this->theme($sweepstake),
        ]);
    }

    /** @param array<string,string> $args */
    public function rules(Request $request, Response $response, array $args): Response
    {
        $sweepstake = $this->requireSweepstake($request, (string) $args['slug']);

        return $this->view->render($response, 'front/rules.html.twig', [
            'sweepstake' => $sweepstake,
            'theme' => $this->theme($sweepstake),
        ]);
    }

    /**
     * Etape 1 : identite. Rien n'est ecrit en base ici — la saisie vit en
     * session jusqu'a l'etape 2, ou le consentement est recueilli. Enregistrer
     * un participant avant son consentement n'aurait aucune valeur probante.
     *
     * @param array<string,string> $args
     */
    public function entry(Request $request, Response $response, array $args): Response
    {
        return $this->renderStep($request, $response, (string) $args['slug'], 1);
    }

    /** @param array<string,string> $args */
    public function details(Request $request, Response $response, array $args): Response
    {
        return $this->renderStep($request, $response, (string) $args['slug'], 2);
    }

    private function renderStep(Request $request, Response $response, string $slug, int $step): Response
    {
        $sweepstake = $this->requireOpenSweepstake($request, $slug);
        $this->visitor->bootstrap($request);

        $sweepstakeId = (int) $sweepstake['sweepstake_id'];

        // Nombre d'etapes reellement peuplees. Un concours dont tous les champs
        // tiennent a l'etape 1 ne doit pas afficher un second ecran vide, ne
        // portant que les consentements : c'est un abandon offert.
        $totalSteps = $this->countPopulatedSteps($sweepstakeId);

        // Etape demandee au-dela de ce qui existe : on renvoie sur la derniere
        // reelle plutot que d'afficher un formulaire sans champ.
        if ($step > $totalSteps) {
            return $this->redirect($response, '/' . $slug . ($totalSteps === 1 ? '/entry' : '/details'));
        }

        $fields = $this->sweepstakes->findFields($sweepstakeId, $step);
        $errors = [];
        $values = $this->visitor->lead($sweepstakeId);
        /** @var list<string> $checkedConsents */
        $checkedConsents = [];

        // Les consentements ne sont presentes qu'a la derniere etape, une fois
        // que le participant sait ce qu'il donne.
        // Les consentements se presentent a la DERNIERE etape, quelle qu'elle
        // soit : le participant doit savoir ce qu'il donne avant de consentir.
        $isLastStep = $step >= $totalSteps;
        $presented = $isLastStep
            ? $this->consents->forSweepstake($sweepstake, $this->collectedFieldKeys($sweepstakeId))
            : [];

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            $result = $this->validator->validate($input, $fields, $sweepstake);
            $errors = $result['errors'];

            if ($isLastStep) {
                // Ce que le participant a REELLEMENT coche, pour le lui rendre
                // si un autre champ le renvoie au formulaire. Sans cela il doit
                // tout recocher, y compris les opt-in facultatifs — et il ne le
                // fait pas.
                foreach ($presented as $consent) {
                    if ($this->isChecked($input, $consent['type'])) {
                        $checkedConsents[] = $consent['type'];
                    }
                }

                foreach ($presented as $consent) {
                    if ($consent['required'] && !$this->isChecked($input, $consent['type'])) {
                        $errors['consent_' . $consent['type']] = 'Please accept to continue.';
                    }
                }
            }

            if ($errors === []) {
                $this->visitor->mergeLead($sweepstakeId, $result['values']);

                if (!$isLastStep) {
                    return $this->redirect($response, '/' . $slug . '/details');
                }

                // Le filtre anti-robot s'applique a la derniere etape, la ou le
                // participant serait enregistre. Un rejet ne dit PAS pourquoi :
                // l'ecran de remerciement est servi comme pour une vraie
                // participation, faute de quoi on apprendrait a un robot
                // comment passer.
                $rejection = $this->spamGuard->reject($input, $this->visitor->lead($sweepstakeId));
                if ($rejection !== null) {
                    $this->logger->info('Participation ecartee', [
                        'raison' => $rejection,
                        'sweepstake' => $sweepstakeId,
                        'ip' => $this->clientIp($request),
                    ]);
                    $this->visitor->forgetLead($sweepstakeId);
                    return $this->redirect($response, '/' . $slug . '/thank-you');
                }

                return $this->completeEntry($request, $response, $sweepstake, $presented, $input, $slug);
            }

            $values = $result['values'] + $values;
        }

        return $this->view->render($response, 'front/form.html.twig', [
            'sweepstake' => $sweepstake,
            'theme' => $this->theme($sweepstake),
            'step' => $step,
            'total_steps' => $totalSteps,
            'fields' => $fields,
            'values' => $values,
            'errors' => $errors,
            'consents' => $presented,
            // Vide au premier affichage : aucune case n'est jamais pre-cochee.
            'checked_consents' => $checkedConsents,
            'states' => UsStates::all(),
            'action' => '/' . $slug . ($step === 1 ? '/entry' : '/details'),
            'honeypot_field' => SpamGuard::HONEYPOT_FIELD,
            'timestamp_field' => SpamGuard::TIMESTAMP_FIELD,
            'form_opened_at' => time(),
        ]);
    }

    /**
     * Ecrit le participant, archive ses consentements, puis l'envoie vers les
     * offres. L'ordre compte : la preuve est ecrite dans la meme requete que
     * l'enregistrement, jamais differee.
     *
     * @param array<string,mixed>                                  $sweepstake
     * @param list<array{type:string, required:bool, text:string}> $presented
     * @param array<string,mixed>                                  $input
     */
    private function completeEntry(
        Request $request,
        Response $response,
        array $sweepstake,
        array $presented,
        array $input,
        string $slug,
    ): Response {
        $sweepstakeId = (int) $sweepstake['sweepstake_id'];
        $values = $this->visitor->lead($sweepstakeId);
        $email = (string) ($values['email'] ?? '');
        $phone = (string) ($values['phone'] ?? '');

        $emailMd5 = md5(strtolower($email));
        $phoneMd5 = $phone === '' ? '' : md5($phone);

        // La liste de suppression se consulte A LA CAPTURE, pas seulement a
        // l'envoi : un e-mail desinscrit ne doit pas rentrer a nouveau en base.
        if ($this->suppressions->isSuppressed($emailMd5, $phoneMd5)) {
            $this->visitor->forgetLead($sweepstakeId);
            return $this->redirect($response, '/' . $slug . '/thank-you');
        }

        $attribution = $this->visitor->attribution();
        $leadId = $this->leads->save([
            'lead_uniqid' => uniqid('tsw_', true),
            'lead_email' => $email,
            'lead_email_md5' => $emailMd5,
            'lead_first_name' => (string) ($values['first_name'] ?? ''),
            'lead_last_name' => (string) ($values['last_name'] ?? ''),
            'lead_dob' => ($values['dob'] ?? '') !== '' ? $values['dob'] : null,
            'lead_gender' => (string) ($values['gender'] ?? 'unknown'),
            'lead_address' => (string) ($values['address'] ?? ''),
            'lead_city' => (string) ($values['city'] ?? ''),
            'lead_state' => (string) ($values['state'] ?? ''),
            'lead_zip' => (string) ($values['zip'] ?? ''),
            'lead_phone' => $phone,
            'lead_phone_md5' => $phoneMd5,
            'lead_ip' => $this->clientIp($request),
            'lead_user_agent' => mb_substr($request->getHeaderLine('User-Agent'), 0, 500),
            'lead_device' => $this->visitor->device(),
            'lead_id_sweepstake' => $sweepstakeId,
            'lead_id_variant' => $this->currentVariant($sweepstakeId),
            'lead_source' => (string) ($attribution['source'] ?? ''),
            'lead_subid' => (string) ($attribution['subid'] ?? ''),
            'lead_clickid' => (string) ($attribution['clickid'] ?? ''),
            'lead_utm_source' => (string) ($attribution['utm_source'] ?? ''),
            'lead_utm_medium' => (string) ($attribution['utm_medium'] ?? ''),
            'lead_utm_campaign' => (string) ($attribution['utm_campaign'] ?? ''),
            'lead_utm_term' => (string) ($attribution['utm_term'] ?? ''),
            'lead_utm_content' => (string) ($attribution['utm_content'] ?? ''),
            'lead_fbclid' => (string) ($attribution['fbclid'] ?? ''),
            'lead_gclid' => (string) ($attribution['gclid'] ?? ''),
            'lead_ttclid' => (string) ($attribution['ttclid'] ?? ''),
            'lead_msclkid' => (string) ($attribution['msclkid'] ?? ''),
            'lead_referer' => (string) ($attribution['referer'] ?? ''),
            'lead_status' => 'complete',
        ]);

        $this->recorder->record($leadId, $sweepstakeId, $presented, $input, $request);
        $this->visitor->setLeadId($sweepstakeId, $leadId);

        return $this->redirect($response, '/' . $slug . '/offers');
    }

    /**
     * Entree du parcours d'offres : constitue la sequence et renvoie a la
     * premiere etape.
     *
     * @param array<string,string> $args
     */
    public function offers(Request $request, Response $response, array $args): Response
    {
        $slug = (string) $args['slug'];
        $sweepstake = $this->requireSweepstake($request, $slug);
        $this->visitor->bootstrap($request);

        $sweepstakeId = (int) $sweepstake['sweepstake_id'];
        $leadId = $this->visitor->leadId($sweepstakeId);

        // Sans participation enregistree, il n'y a pas de raison d'afficher des
        // offres : on renvoie au formulaire.
        if ($leadId === null) {
            return $this->redirect($response, '/' . $slug . '/entry');
        }

        $sequence = $this->visitor->offerSequence($sweepstakeId);
        if ($sequence === []) {
            $lead = $this->leads->findById($leadId) ?? [];
            $sequence = $this->offers->selectSequence(
                $sweepstake,
                $this->currentVariant($sweepstakeId),
                $this->targetingContext($lead)
            );
            $this->visitor->setOfferSequence($sweepstakeId, $sequence);
        }

        if ($sequence === []) {
            return $this->redirect($response, '/' . $slug . '/thank-you');
        }

        return $this->redirect($response, '/' . $slug . '/offers/1');
    }

    /**
     * Une offre, une page.
     *
     * L'impression est enregistree ici, a l'affichage effectif, et une seule
     * fois par etape : une offre placee en fin de parcours que le visiteur
     * n'atteint jamais ne doit compter aucune exposition, et un rechargement
     * ne doit pas en compter deux.
     *
     * @param array<string,string> $args
     */
    public function offerStep(Request $request, Response $response, array $args): Response
    {
        $slug = (string) $args['slug'];
        $sweepstake = $this->requireSweepstake($request, $slug);
        $this->visitor->bootstrap($request);

        $sweepstakeId = (int) $sweepstake['sweepstake_id'];
        $leadId = $this->visitor->leadId($sweepstakeId);
        if ($leadId === null) {
            return $this->redirect($response, '/' . $slug . '/entry');
        }

        $sequence = $this->visitor->offerSequence($sweepstakeId);
        $step = max(1, (int) $args['step']);
        $total = count($sequence);

        // Sequence absente (session expiree) ou parcours termine : on sort par
        // la page de remerciement plutot que sur une erreur.
        if ($sequence === [] || $step > $total) {
            return $this->redirect($response, '/' . $slug . '/thank-you');
        }

        $offer = $this->offers->presentOffer(
            $sequence[$step - 1],
            $this->visitor->sessionUid(),
            $step,
            $sweepstakeId
        );

        // Offre desactivee depuis la constitution de la sequence : on passe a
        // la suivante sans rien compter.
        if ($offer === null) {
            return $this->redirect($response, '/' . $slug . '/offers/' . ($step + 1));
        }

        if (!$this->visitor->hasSeenOfferStep($sweepstakeId, $step)) {
            $this->offers->recordImpression(
                $offer['id'],
                $sweepstakeId,
                $this->currentVariant($sweepstakeId),
                $leadId,
                $this->visitor->sessionUid(),
                $step,
                $this->visitor->device(),
                $this->visitor->subid()
            );
            $this->visitor->markOfferStepSeen($sweepstakeId, $step);
        }

        return $this->view->render($response, 'front/offer.html.twig', [
            'sweepstake' => $sweepstake,
            'theme' => $this->theme($sweepstake),
            'offer' => $offer,
            'step' => $step,
            'total' => $total,
            'progress' => (int) round($step / max(1, $total) * 100),
            'next_url' => $step < $total
                ? '/' . $slug . '/offers/' . ($step + 1)
                : '/' . $slug . '/thank-you',
            'skip_url' => '/' . $slug . '/thank-you',
            'is_last' => $step >= $total,
        ]);
    }

    /** @param array<string,string> $args */
    public function thankYou(Request $request, Response $response, array $args): Response
    {
        $sweepstake = $this->requireSweepstake($request, (string) $args['slug']);
        $this->visitor->bootstrap($request);
        $this->visitor->forgetLead((int) $sweepstake['sweepstake_id']);
        $this->visitor->forgetOfferPath((int) $sweepstake['sweepstake_id']);

        return $this->view->render($response, 'front/thankyou.html.twig', [
            'sweepstake' => $sweepstake,
            'theme' => $this->theme($sweepstake),
        ]);
    }

    // ---------------------------------------------------------------- utilitaires

    /** @return array<string,mixed> */
    private function requireSweepstake(Request $request, string $slug): array
    {
        $sweepstake = $this->sweepstakes->findPublishedBySlug($slug);
        if ($sweepstake === null) {
            throw new HttpNotFoundException($request);
        }
        return $sweepstake;
    }

    /**
     * Comme requireSweepstake, mais refuse aussi un concours hors de sa fenetre
     * de dates : on ne collecte pas de participation a un concours termine.
     *
     * @return array<string,mixed>
     */
    private function requireOpenSweepstake(Request $request, string $slug): array
    {
        $sweepstake = $this->sweepstakes->findPublishedBySlug($slug);
        if ($sweepstake === null || !$this->sweepstakes->isOpen($sweepstake)) {
            throw new HttpNotFoundException($request);
        }
        return $sweepstake;
    }

    private function currentVariant(int $sweepstakeId): int
    {
        return $this->visitor->variantFor(
            $sweepstakeId,
            $this->sweepstakes->findActiveVariants($sweepstakeId, $this->visitor->device())
        );
    }

    /**
     * Nombre d'etapes du formulaire qui portent au moins un champ actif.
     *
     * Rend toujours au moins 1 : un concours sans aucun champ n'existe pas en
     * pratique, mais un tunnel a zero etape n'aurait aucun sens.
     */
    private function countPopulatedSteps(int $sweepstakeId): int
    {
        $steps = [];
        foreach ($this->sweepstakes->findFields($sweepstakeId) as $field) {
            $steps[(int) $field['sweepstake_field_step']] = true;
        }
        return $steps === [] ? 1 : max(array_keys($steps));
    }

    /** @return list<string> */
    private function collectedFieldKeys(int $sweepstakeId): array
    {
        return array_map(
            static fn(array $f): string => (string) $f['sweepstake_field_key'],
            $this->sweepstakes->findFields($sweepstakeId)
        );
    }

    /**
     * @param array<string,mixed> $lead
     * @return array<string,mixed>
     */
    private function targetingContext(array $lead): array
    {
        return [
            'country' => (string) ($lead['lead_country'] ?? 'US'),
            'state' => (string) ($lead['lead_state'] ?? ''),
            'zip' => (string) ($lead['lead_zip'] ?? ''),
            'dob' => (string) ($lead['lead_dob'] ?? ''),
            'gender' => (string) ($lead['lead_gender'] ?? ''),
            'phone' => (string) ($lead['lead_phone'] ?? ''),
            'email' => (string) ($lead['lead_email'] ?? ''),
            'subid' => $this->visitor->subid(),
        ];
    }

    /** @param array<string,mixed> $input */
    private function isChecked(array $input, string $type): bool
    {
        $value = $input['consent_' . $type] ?? null;
        return !is_array($value) && in_array((string) $value, ['1', 'on', 'true', 'yes'], true);
    }

    /** @return array<string,mixed> */
    private function theme(array $sweepstake): array
    {
        $raw = $sweepstake['sweepstake_theme'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function clientIp(Request $request): string
    {
        foreach (['X-Real-IP', 'X-Forwarded-For'] as $header) {
            $value = $request->getHeaderLine($header);
            if ($value === '') {
                continue;
            }
            $candidate = trim(explode(',', $value)[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }
        $remote = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '';
    }

    private function redirect(Response $response, string $location): Response
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }
}
