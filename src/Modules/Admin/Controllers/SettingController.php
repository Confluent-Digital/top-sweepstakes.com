<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Models\Repositories\AdminUserRepository;
use App\Modules\Admin\Models\Repositories\SettingRepository;
use App\Modules\Admin\Services\ImageUploadService;
use App\Modules\Admin\Services\ReadinessCatalog;
use App\Modules\Legal\Services\LegalContentService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;

/**
 * Reglages du site : identite, accueil, mentions, favicon.
 *
 * Ce qui vivait en dur dans les gabarits ou dans le `.env` se regle ici, sans
 * mise en production.
 */
final class SettingController
{
    /** Champs texte du formulaire. Voir SettingRepository::DEFAULTS. */
    private const TEXT_FIELDS = [
        'site_name',
        'site_tagline',
        'site_intro',
        'site_meta_title',
        'site_meta_description',
        'site_company_name',
        'site_postal_address',
        'site_contact_email',
        'site_empty_message',
    ];

    public function __construct(
        private Twig $view,
        private SettingRepository $settings,
        private ImageUploadService $uploads,
        private AdminUserRepository $users,
        private LegalContentService $legal,
    ) {
    }

    public function edit(Request $request, Response $response): Response
    {
        $errors = [];
        $values = $this->settings->all();

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            $submitted = [];
            foreach (self::TEXT_FIELDS as $field) {
                $submitted[$field] = trim((string) ($input[$field] ?? ''));
            }

            $submitted['site_legal_links'] = $this->extractLegalLinks($input);
            $errors = $this->validate($submitted);

            if ($errors === []) {
                $this->settings->save($submitted);

                foreach (
                    [
                    'favicon' => ['site_favicon', 'favicon'],
                    'og_image' => ['site_og_image', 'social'],
                    ] as $field => [$setting, $basename]
                ) {
                    $error = $this->storeImage($request, $field, $setting, $basename);
                    if ($error !== null) {
                        $errors[$field] = $error;
                    }
                }

                $this->log($request);

                if ($errors === []) {
                    return $response->withHeader('Location', '/admin/settings?saved=1')->withStatus(302);
                }
            }

            $values = $submitted + $values;
        }

        // Ce que l'operateur vient de soumettre, pas ce qui est en base : une
        // erreur sur un autre champ ne doit pas lui faire perdre sa selection.
        $selectedLinks = SettingRepository::decodeLegalLinks((string) ($values['site_legal_links'] ?? ''));

        return $this->view->render($response, 'admin/settings.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'saved' => $request->getQueryParams()['saved'] ?? null,
            'legal_pages' => LegalContentService::PAGES,
            'legal_selected' => $selectedLinks,
            // Testé pour de vrai : la couverture de legals varie par langue, et
            // une page absente y répond 200 avec un avertissement PHP.
            'legal_availability' => $this->legal->availability(),
            // Un document cite dans un texte de consentement mais absent du
            // pied de page : le participant a accepte quelque chose qu'il ne
            // pouvait pas lire.
            'legal_cited_missing' => $this->citedButNotLinked($selectedLinks),
        ]);
    }

    /**
     * Documents nommes dans les textes de consentement mais pas affiches.
     *
     * Les textes de `ConsentCatalog` renvoient a des documents ; s'ils ne sont
     * pas atteignables, le participant a accepte quelque chose qu'il ne pouvait
     * pas lire. Le controle est volontairement grossier — une recherche de
     * libelle — parce que le seul cas qui compte est celui d'un document retire
     * du pied de page alors qu'il reste cite.
     *
     * La liste des documents cites vient de `ReadinessCatalog` : l'ecran des
     * reserves d'ouverture porte le meme controle, et deux copies d'une liste
     * de conformite finissent toujours par diverger.
     *
     * @param array<string,string> $linked
     * @return list<string>
     */
    private function citedButNotLinked(array $linked): array
    {
        $missing = [];
        foreach (ReadinessCatalog::citedDocuments() as $label => $page) {
            if (!array_key_exists($page, $linked)) {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    /**
     * @param array<string,mixed> $input
     */
    private function extractLegalLinks(array $input): string
    {
        $pages = (array) ($input['legal_page'] ?? []);
        $labels = (array) ($input['legal_label'] ?? []);

        $links = [];
        foreach ($pages as $page) {
            $page = (string) $page;
            if (!$this->legal->isKnownPage($page)) {
                continue;
            }
            $label = trim((string) ($labels[$page] ?? ''));
            $links[] = ['page' => $page, 'label' => $label !== '' ? $label : $page];
        }

        return json_encode($links, JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    /**
     * @param array<string,string> $values
     * @return array<string,string>
     */
    private function validate(array $values): array
    {
        $errors = [];

        if ($values['site_name'] === '') {
            $errors['site_name'] = 'Le nom du site est obligatoire : il apparait sur chaque page.';
        }

        // CAN-SPAM impose une adresse postale physique sur les envois et en pied
        // de page. La saisir ici la rend disponible sur TOUTES les pages, y
        // compris /unsubscribe, qui n'est rattachee a aucun concours et n'en
        // affichait donc aucune.
        if ($values['site_postal_address'] === '') {
            $errors['site_postal_address'] =
                'L\'adresse postale est obligatoire (CAN-SPAM) : elle doit figurer sur les pages '
                . 'hors concours, dont la page de desinscription.';
        }

        if (
            $values['site_contact_email'] !== ''
            && filter_var($values['site_contact_email'], FILTER_VALIDATE_EMAIL) === false
        ) {
            $errors['site_contact_email'] = 'Adresse e-mail invalide.';
        }

        return $errors;
    }

    private function storeImage(Request $request, string $field, string $setting, string $basename): ?string
    {
        $file = $request->getUploadedFiles()[$field] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $result = $this->uploads->store($file, 'img/site', $basename);
        if (!($result['ok'] ?? false)) {
            return (string) ($result['error'] ?? 'Televersement impossible.');
        }

        $this->settings->save([$setting => (string) $result['file']]);

        return null;
    }

    private function log(Request $request): void
    {
        $user = $request->getAttribute('admin_user');
        $this->users->log(
            is_array($user) ? (int) $user['admin_user_id'] : null,
            'settings.save',
            '',
            '',
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '')
        );
    }
}
