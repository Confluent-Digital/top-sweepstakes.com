<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Models\Repositories\AdminOfferRepository;
use App\Modules\Admin\Models\Repositories\AdminSweepstakeRepository;
use App\Modules\Admin\Models\Repositories\AdminUserRepository;
use App\Modules\Admin\Services\ImageUploadService;
use App\Modules\Leads\Services\LeadValidator;
use App\Modules\Leads\Services\UsStates;
use App\Modules\Sweepstakes\Models\Repositories\SweepstakeRepository;
use App\Modules\Sweepstakes\Services\OfficialRules;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Gestion des concours.
 *
 * Tout ce qui distingue un concours d'un autre se regle ici, en base : aucune
 * action de cet ecran ne demande de deploiement.
 */
final class SweepstakeAdminController
{
    public function __construct(
        private Twig $view,
        private AdminSweepstakeRepository $admin,
        private SweepstakeRepository $sweepstakes,
        private AdminOfferRepository $offers,
        private AdminUserRepository $users,
        private ImageUploadService $uploads,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/sweepstakes/index.html.twig', [
            'sweepstakes' => $this->admin->all(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->edit($request, $response, ['id' => '0']);
    }

    /** @param array<string,string> $args */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $sweepstake = $id > 0 ? $this->sweepstakes->findById($id) : $this->blank();
        if ($sweepstake === null) {
            throw new HttpNotFoundException($request);
        }

        $errors = [];

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            $data = $this->extract($input);
            $errors = $this->validate($data, $id);

            if ($errors === []) {
                if ($id > 0) {
                    $this->admin->update($id, $data);
                } else {
                    $id = $this->admin->create($data);
                }
                $this->admin->replaceFields($id, $this->extractFields($input));

                // Le visuel est traite apres l'enregistrement : le repertoire
                // de destination porte l'identifiant du concours, qui n'existe
                // pas encore a la creation.
                $upload = $this->storePrizeImage($request, $id);
                if ($upload !== null) {
                    $errors['prize_image'] = $upload;
                }

                $this->log($request, 'sweepstake.save', (string) $id);

                if ($errors === []) {
                    return $this->redirect($response, '/admin/sweepstakes/' . $id . '/edit?saved=1');
                }

                // Le concours est enregistre, seul le visuel a echoue : on
                // recharge la fiche telle qu'elle est en base pour ne pas
                // laisser croire que rien n'a ete sauvegarde.
                $sweepstake = $this->sweepstakes->findById($id) ?? $data;
            } else {
                $sweepstake = $data + $sweepstake;
                $sweepstake['sweepstake_id'] = $id;
            }
        }

        return $this->view->render($response, 'admin/sweepstakes/edit.html.twig', [
            'sweepstake' => $sweepstake,
            'theme' => $this->decodeTheme($sweepstake['sweepstake_theme'] ?? null),
            'fields' => $id > 0 ? $this->sweepstakes->findFields($id) : [],
            'field_keys' => LeadValidator::FIELDS,
            'states' => UsStates::all(),
            'errors' => $errors,
            'saved' => $request->getQueryParams()['saved'] ?? null,
            'attached_offers' => $id > 0 ? $this->admin->attachedOffers($id) : [],
            'all_offers' => $this->offers->all(),
        ]);
    }

    /**
     * Enregistre le visuel de dotation, s'il y en a un dans la requete.
     *
     * Rend le message d'erreur a afficher, ou null si tout va bien — y compris
     * quand aucun fichier n'a ete depose, ce qui est le cas le plus frequent :
     * on ne televerse pas le visuel a chaque enregistrement de la fiche.
     */
    private function storePrizeImage(Request $request, int $sweepstakeId): ?string
    {
        $file = $request->getUploadedFiles()['prize_image'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $result = $this->uploads->store($file, 'img/sweepstakes/' . $sweepstakeId, 'prize');
        if (!($result['ok'] ?? false)) {
            return (string) ($result['error'] ?? 'Televersement impossible.');
        }

        // Les dimensions reelles sont enregistrees pour que le gabarit reserve
        // la bonne place : sans elles, le bouton descend au chargement de
        // l'image, au moment ou le visiteur vise.
        $this->admin->update($sweepstakeId, [
            'sweepstake_prize_image' => $result['file'],
            'sweepstake_prize_image_width' => $result['width'],
            'sweepstake_prize_image_height' => $result['height'],
        ]);

        return null;
    }

    /** @param array<string,string> $args */
    public function duplicate(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $source = $this->sweepstakes->findById($id);
        if ($source === null) {
            throw new HttpNotFoundException($request);
        }

        $slug = $this->uniqueSlug((string) $source['sweepstake_slug'] . '-copy');
        $newId = $this->admin->duplicate($id, $slug, (string) $source['sweepstake_name'] . ' (copie)');
        $this->log($request, 'sweepstake.duplicate', $id . ' -> ' . $newId);

        return $this->redirect($response, '/admin/sweepstakes/' . $newId . '/edit?saved=1');
    }

    /** @param array<string,string> $args */
    public function attachOffers(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->sweepstakes->findById($id) === null) {
            throw new HttpNotFoundException($request);
        }

        $input = (array) $request->getParsedBody();
        $offerIds = array_map('intval', (array) ($input['offer_ids'] ?? []));

        $blockId = $this->admin->firstBlockId($id) ?? $this->admin->createDefaultBlock($id);
        $this->admin->replaceAttachedOffers($id, $offerIds, $blockId);
        $this->log($request, 'sweepstake.attach_offers', (string) $id, implode(',', $offerIds));

        return $this->redirect($response, '/admin/sweepstakes/' . $id . '/edit?saved=1');
    }

    // ---------------------------------------------------------------- interne

    /** @return array<string,mixed> */
    private function blank(): array
    {
        return [
            'sweepstake_id' => 0,
            'sweepstake_slug' => '',
            'sweepstake_name' => '',
            'sweepstake_status' => 'draft',
            'sweepstake_prize_title' => '',
            'sweepstake_prize_value_usd' => 0,
            'sweepstake_prize_image' => '',
            'sweepstake_sponsor_name' => '',
            'sweepstake_sponsor_address' => '',
            'sweepstake_brand_disclaimer' => '',
            'sweepstake_date_start' => null,
            'sweepstake_date_end' => null,
            'sweepstake_min_age' => 18,
            'sweepstake_offer_steps' => 4,
            'sweepstake_excluded_states' => '',
            'sweepstake_official_rules_html' => '',
            'sweepstake_thankyou_html' => '',
            'sweepstake_meta_title' => '',
            'sweepstake_meta_description' => '',
            'sweepstake_theme' => null,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function extract(array $input): array
    {
        $theme = [];
        foreach (['primary', 'accent', 'text', 'background', 'surface'] as $key) {
            $value = trim((string) ($input['theme_' . $key] ?? ''));
            if ($value !== '') {
                $theme[$key] = $value;
            }
        }

        return [
            'sweepstake_slug' => $this->slugify((string) ($input['sweepstake_slug'] ?? '')),
            'sweepstake_name' => trim((string) ($input['sweepstake_name'] ?? '')),
            'sweepstake_status' => in_array(
                $input['sweepstake_status'] ?? '',
                ['draft', 'published', 'paused', 'ended'],
                true
            ) ? (string) $input['sweepstake_status'] : 'draft',
            'sweepstake_prize_title' => trim((string) ($input['sweepstake_prize_title'] ?? '')),
            'sweepstake_prize_value_usd' => (float) ($input['sweepstake_prize_value_usd'] ?? 0),
            'sweepstake_sponsor_name' => trim((string) ($input['sweepstake_sponsor_name'] ?? '')),
            'sweepstake_sponsor_address' => trim((string) ($input['sweepstake_sponsor_address'] ?? '')),
            'sweepstake_brand_disclaimer' => trim((string) ($input['sweepstake_brand_disclaimer'] ?? '')),
            'sweepstake_date_start' => $this->nullableDate($input['sweepstake_date_start'] ?? null),
            'sweepstake_date_end' => $this->nullableDate($input['sweepstake_date_end'] ?? null),
            'sweepstake_min_age' => max(13, (int) ($input['sweepstake_min_age'] ?? 18)),
            // Borne haute volontaire : au-dela, la fatigue fait abandonner bien
            // avant la derniere offre, et les impressions de fin de parcours ne
            // se transforment plus.
            'sweepstake_offer_steps' => max(0, min(12, (int) ($input['sweepstake_offer_steps'] ?? 4))),
            'sweepstake_excluded_states' => implode(
                ',',
                UsStates::parseExcluded((string) ($input['sweepstake_excluded_states'] ?? ''))
            ),
            'sweepstake_official_rules_html' => (string) ($input['sweepstake_official_rules_html'] ?? ''),
            'sweepstake_thankyou_html' => (string) ($input['sweepstake_thankyou_html'] ?? ''),
            'sweepstake_meta_title' => trim((string) ($input['sweepstake_meta_title'] ?? '')),
            'sweepstake_meta_description' => trim((string) ($input['sweepstake_meta_description'] ?? '')),
            'sweepstake_theme' => $theme === [] ? null : json_encode($theme),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return list<array{key:string, step:int, position:int, required:bool}>
     */
    private function extractFields(array $input): array
    {
        $fields = [];
        $position = 0;
        foreach (LeadValidator::FIELDS as $key) {
            if (empty($input['field_active'][$key])) {
                continue;
            }
            $position++;
            $fields[] = [
                'key' => $key,
                'step' => ((int) ($input['field_step'][$key] ?? 1)) === 2 ? 2 : 1,
                'position' => $position,
                'required' => !empty($input['field_required'][$key]),
            ];
        }
        return $fields;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private function validate(array $data, int $id): array
    {
        $errors = [];

        if ($data['sweepstake_slug'] === '') {
            $errors['sweepstake_slug'] = 'Le slug est obligatoire.';
        } elseif ($this->admin->slugExists((string) $data['sweepstake_slug'], $id > 0 ? $id : null)) {
            $errors['sweepstake_slug'] = 'Ce slug est deja utilise.';
        }

        if ($data['sweepstake_name'] === '') {
            $errors['sweepstake_name'] = 'Le nom est obligatoire.';
        }

        if ($data['sweepstake_status'] === 'published') {
            $errors += $this->validateForPublication($data);
        }

        $start = $data['sweepstake_date_start'];
        $end = $data['sweepstake_date_end'];
        if (is_string($start) && is_string($end) && $start > $end) {
            $errors['sweepstake_date_end'] = 'La date de fin precede la date de debut.';
        }

        return $errors;
    }

    /**
     * @param mixed $raw
     * @return array<string,string>
     */
    private function decodeTheme(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_map('strval', $raw);
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }

    /**
     * Ce qu'un concours doit porter pour etre publie.
     *
     * Le blocage vit ici, pas dans une consigne : un concours publie sans
     * regles opposables ou sans periode de participation est une
     * non-conformite immediate, et c'est le premier document qu'un attorney
     * general demande.
     *
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private function validateForPublication(array $data): array
    {
        $errors = [];

        $html = (string) $data['sweepstake_official_rules_html'];
        $rules = OfficialRules::text($html);

        if (OfficialRules::isEmpty($html)) {
            $errors['sweepstake_official_rules_html'] =
                'Les Official Rules sont obligatoires pour publier un concours.';
        } else {
            // « Non vide » ne suffisait pas : des regles tronquees en plein mot
            // ont ete publiees sans que rien ne le signale. Un reglement
            // complet fait plusieurs milliers de caracteres ; ce seuil ecarte
            // un fragment sans jamais atteindre un texte reel.
            if (OfficialRules::isTooShort($html)) {
                $errors['sweepstake_official_rules_html'] = sprintf(
                    'Les Official Rules paraissent incompletes (%d caracteres de texte). '
                    . 'Un reglement complet porte au minimum : NO PURCHASE NECESSARY, l\'AMOE, '
                    . 'le sponsor et son adresse, les dates, l\'eligibilite et les Etats exclus, '
                    . 'l\'ARV, les probabilites de gain, la selection et la publication des gagnants.',
                    mb_strlen($rules)
                );
            } else {
                // Meme liste que le controle des concours deja en ligne : voir
                // OfficialRules::REQUIRED_MENTIONS.
                $missing = OfficialRules::missingMentions($html);
                if ($missing !== []) {
                    $errors['sweepstake_official_rules_html'] =
                        'Mention(s) obligatoire(s) introuvable(s) dans les Official Rules : '
                        . implode(', ', $missing) . '.';
                }
            }
        }

        if ($data['sweepstake_sponsor_name'] === '') {
            $errors['sweepstake_sponsor_name'] = 'Le sponsor doit etre nomme pour publier un concours.';
        }
        if ($data['sweepstake_sponsor_address'] === '') {
            $errors['sweepstake_sponsor_address'] =
                'L\'adresse postale du sponsor est obligatoire pour publier (CAN-SPAM, et AMOE : '
                . 'c\'est l\'adresse ou l\'on postera une participation par courrier).';
        }

        // Sans dates, la periode de participation ne se delimite pas — donc
        // l'eligibilite d'un tirage ne se justifie pas. Et un concours sans
        // date de fin ne se ferme jamais : il continue de collecter.
        if ($data['sweepstake_date_start'] === null) {
            $errors['sweepstake_date_start'] = 'La date d\'ouverture est obligatoire pour publier.';
        }
        if ($data['sweepstake_date_end'] === null) {
            $errors['sweepstake_date_end'] =
                'La date de cloture est obligatoire pour publier : sans elle, le concours collecte indefiniment.';
        }

        return $errors;
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $this->slugify($base);
        $candidate = $slug;
        $suffix = 2;
        while ($this->admin->slugExists($candidate)) {
            $candidate = $slug . '-' . $suffix;
            $suffix++;
        }
        return $candidate;
    }

    private function nullableDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private function log(Request $request, string $action, string $target, string $detail = ''): void
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

    private function redirect(Response $response, string $location): Response
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }
}
