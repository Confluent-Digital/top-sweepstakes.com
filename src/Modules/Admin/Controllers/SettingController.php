<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Models\Repositories\AdminUserRepository;
use App\Modules\Admin\Models\Repositories\SettingRepository;
use App\Modules\Admin\Services\ImageUploadService;
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

        return $this->view->render($response, 'admin/settings.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'saved' => $request->getQueryParams()['saved'] ?? null,
        ]);
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
