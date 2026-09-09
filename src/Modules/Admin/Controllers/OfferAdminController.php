<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Models\Repositories\AdminOfferRepository;
use App\Modules\Admin\Models\Repositories\AdminUserRepository;
use App\Modules\Offers\Models\Repositories\OfferRepository;
use App\Modules\Offers\Services\OfferLinkBuilder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

final class OfferAdminController
{
    /** Champs qu'une offre peut recevoir. Voir OfferLinkBuilder. */
    private const PASSTHROUGH_FIELDS = [
        'email', 'first_name', 'last_name', 'address',
        'city', 'state', 'zip', 'phone', 'dob', 'gender',
    ];

    private const TARGETING_PARAMS = ['state', 'zip', 'dob', 'gender', 'phone', 'email_domain', 'subid'];
    private const TARGETING_OPERATORS = ['in', 'not_in', 'gt', 'gte', 'lt', 'lte', 'between', 'not_empty', 'regex'];

    public function __construct(
        private Twig $view,
        private AdminOfferRepository $admin,
        private OfferRepository $offers,
        private OfferLinkBuilder $links,
        private AdminUserRepository $users,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/offers/index.html.twig', [
            'offers' => $this->admin->all(),
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
        $offer = $id > 0 ? $this->offers->findById($id) : $this->blank();
        if ($offer === null) {
            throw new HttpNotFoundException($request);
        }

        $errors = [];

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            $data = $this->extract($input);
            $errors = $this->validate($data);

            if ($errors === []) {
                if ($id > 0) {
                    $this->admin->update($id, $data);
                } else {
                    $id = $this->admin->create($data);
                }
                $this->admin->replaceTargetingRules($id, $this->extractRules($input));
                $this->log($request, 'offer.save', (string) $id);

                return $this->redirect($response, '/admin/offers/' . $id . '/edit?saved=1');
            }
            $offer = $data + $offer;
            $offer['offer_id'] = $id;
        }

        return $this->view->render($response, 'admin/offers/edit.html.twig', [
            'offer' => $offer,
            'rules' => $id > 0 ? $this->admin->targetingRules($id) : [],
            'passthrough_fields' => self::PASSTHROUGH_FIELDS,
            'selected_passthrough' => $this->decodePassthrough($offer['offer_passthrough_fields'] ?? null),
            'targeting_params' => self::TARGETING_PARAMS,
            'targeting_operators' => self::TARGETING_OPERATORS,
            'errors' => $errors,
            'saved' => $request->getQueryParams()['saved'] ?? null,
            // Previsualisation de l'URL de sortie, avec un participant fictif.
            // Elle permet de verifier ids, idv, sid et champs transmis SANS
            // ouvrir le lien : un clic reel est facture a l'annonceur.
            'preview_url' => $id > 0 ? $this->previewUrl($offer) : null,
        ]);
    }

    /** @param array<string,mixed> $offer */
    private function previewUrl(array $offer): ?string
    {
        try {
            return $this->links->build($offer, [
                'sweepstake_id' => 0,
                'subid' => 'PREVIEW',
                'email_md5' => md5('preview@example.com'),
                'lead' => [
                    'email' => 'preview@example.com',
                    'first_name' => 'Jean & Marie',
                    'last_name' => 'Test',
                    'address' => '1 Main Street',
                    'city' => 'New York',
                    'state' => 'NY',
                    'zip' => '10001',
                    'phone' => '2125550147',
                    'dob' => '1990-06-15',
                    'gender' => 'male',
                ],
            ]);
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function blank(): array
    {
        return [
            'offer_id' => 0,
            'offer_name' => '',
            'offer_advertiser' => '',
            'offer_type' => 'banner',
            'offer_image' => '',
            'offer_headline' => '',
            'offer_text_html' => '',
            'offer_cta_label' => 'See offer',
            'offer_target_blank' => 1,
            'offer_platform_ids' => '',
            'offer_platform_idv' => '',
            'offer_platform_idc' => '',
            'offer_passthrough_fields' => null,
            'offer_country' => 'US',
            'offer_active' => 0,
            'offer_date_start' => null,
            'offer_date_end' => null,
            'offer_cap_day' => null,
            'offer_cap_total' => null,
            'offer_weight' => 100,
            'offer_notes' => '',
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function extract(array $input): array
    {
        $passthrough = array_values(array_intersect(
            self::PASSTHROUGH_FIELDS,
            array_map('strval', (array) ($input['passthrough'] ?? []))
        ));

        return [
            'offer_name' => trim((string) ($input['offer_name'] ?? '')),
            'offer_advertiser' => trim((string) ($input['offer_advertiser'] ?? '')),
            'offer_type' => ($input['offer_type'] ?? '') === 'coupon' ? 'coupon' : 'banner',
            'offer_image' => trim((string) ($input['offer_image'] ?? '')),
            'offer_headline' => trim((string) ($input['offer_headline'] ?? '')),
            'offer_text_html' => (string) ($input['offer_text_html'] ?? ''),
            'offer_cta_label' => trim((string) ($input['offer_cta_label'] ?? '')),
            'offer_target_blank' => empty($input['offer_target_blank']) ? 0 : 1,
            'offer_platform_ids' => trim((string) ($input['offer_platform_ids'] ?? '')),
            'offer_platform_idv' => trim((string) ($input['offer_platform_idv'] ?? '')),
            'offer_platform_idc' => trim((string) ($input['offer_platform_idc'] ?? '')),
            'offer_passthrough_fields' => $passthrough === [] ? null : json_encode($passthrough),
            'offer_country' => strtoupper(substr(trim((string) ($input['offer_country'] ?? 'US')), 0, 2)),
            'offer_active' => empty($input['offer_active']) ? 0 : 1,
            'offer_date_start' => $this->nullableDate($input['offer_date_start'] ?? null),
            'offer_date_end' => $this->nullableDate($input['offer_date_end'] ?? null),
            'offer_cap_day' => $this->nullableInt($input['offer_cap_day'] ?? null),
            'offer_cap_total' => $this->nullableInt($input['offer_cap_total'] ?? null),
            'offer_weight' => max(0, (int) ($input['offer_weight'] ?? 100)),
            'offer_notes' => (string) ($input['offer_notes'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return list<array{param:string, operator:string, value:string}>
     */
    private function extractRules(array $input): array
    {
        $params = (array) ($input['rule_param'] ?? []);
        $operators = (array) ($input['rule_operator'] ?? []);
        $values = (array) ($input['rule_value'] ?? []);

        $rules = [];
        foreach ($params as $i => $param) {
            $param = (string) $param;
            $operator = (string) ($operators[$i] ?? '');
            if (
                !in_array($param, self::TARGETING_PARAMS, true)
                || !in_array($operator, self::TARGETING_OPERATORS, true)
            ) {
                continue;
            }
            $rules[] = [
                'param' => $param,
                'operator' => $operator,
                'value' => trim((string) ($values[$i] ?? '')),
            ];
        }
        return $rules;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private function validate(array $data): array
    {
        $errors = [];

        if ($data['offer_name'] === '') {
            $errors['offer_name'] = 'Le nom est obligatoire.';
        }

        // Une offre active sans crea ne peut pas etre diffusee : OfferSelector
        // l'ecarterait, elle occuperait une place dans le back-office sans
        // jamais s'afficher.
        if ($data['offer_active'] === 1 && $data['offer_platform_idv'] === '') {
            $errors['offer_platform_idv'] =
                'L\'identifiant de crea (idv) est obligatoire pour activer une offre : '
                . 'sans lui, le lien de sortie ne peut pas etre construit et l\'offre n\'est jamais affichee.';
        }

        // Sans idc, l'offre s'affiche et rapporte, mais le flux de reporting ne
        // pourra pas lui rattacher ce revenu : son eCPM restera a zero et elle
        // sera releguee par l'arbitrage, sans qu'on comprenne pourquoi.
        if ($data['offer_active'] === 1 && $data['offer_platform_idc'] === '') {
            $errors['offer_platform_idc'] =
                'L\'identifiant de campagne (idc) est obligatoire pour activer une offre : '
                . 'c\'est la cle de rapprochement des revenus.';
        }

        $start = $data['offer_date_start'];
        $end = $data['offer_date_end'];
        if (is_string($start) && is_string($end) && $start > $end) {
            $errors['offer_date_end'] = 'La date de fin precede la date de debut.';
        }

        return $errors;
    }

    /** @return list<string> */
    private function decodePassthrough(mixed $raw): array
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

    private function nullableDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = trim((string) $value);
        return $value === '' ? null : max(0, (int) $value);
    }

    private function log(Request $request, string $action, string $target): void
    {
        $user = $request->getAttribute('admin_user');
        $this->users->log(
            is_array($user) ? (int) $user['admin_user_id'] : null,
            $action,
            $target,
            '',
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '')
        );
    }

    private function redirect(Response $response, string $location): Response
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }
}
