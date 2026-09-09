<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Models\Repositories\AdminOfferRepository;
use App\Modules\Admin\Models\Repositories\AdminSweepstakeRepository;
use App\Modules\Admin\Models\Repositories\AdminUserRepository;
use App\Modules\Leads\Services\LeadValidator;
use App\Modules\Leads\Services\UsStates;
use App\Modules\Sweepstakes\Models\Repositories\SweepstakeRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
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
                $this->log($request, $id > 0 ? 'sweepstake.update' : 'sweepstake.create', (string) $id);

                return $this->redirect($response, '/admin/sweepstakes/' . $id . '/edit?saved=1');
            }
            $sweepstake = $data + $sweepstake;
            $sweepstake['sweepstake_id'] = $id;
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
            'sweepstake_prize_image' => trim((string) ($input['sweepstake_prize_image'] ?? '')),
            'sweepstake_sponsor_name' => trim((string) ($input['sweepstake_sponsor_name'] ?? '')),
            'sweepstake_sponsor_address' => trim((string) ($input['sweepstake_sponsor_address'] ?? '')),
            'sweepstake_brand_disclaimer' => trim((string) ($input['sweepstake_brand_disclaimer'] ?? '')),
            'sweepstake_date_start' => $this->nullableDate($input['sweepstake_date_start'] ?? null),
            'sweepstake_date_end' => $this->nullableDate($input['sweepstake_date_end'] ?? null),
            'sweepstake_min_age' => max(13, (int) ($input['sweepstake_min_age'] ?? 18)),
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

        // Un concours publie sans Official Rules serait une non-conformite
        // immediate : le blocage est ici, pas dans une consigne.
        $rules = trim((string) $data['sweepstake_official_rules_html']);
        if ($data['sweepstake_status'] === 'published' && $rules === '') {
            $errors['sweepstake_official_rules_html'] =
                'Les Official Rules sont obligatoires pour publier un concours.';
        }

        if ($data['sweepstake_status'] === 'published' && $data['sweepstake_sponsor_name'] === '') {
            $errors['sweepstake_sponsor_name'] = 'Le sponsor doit etre nomme pour publier un concours.';
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
