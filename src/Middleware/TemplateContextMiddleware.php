<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use App\Modules\Admin\Models\Repositories\SettingRepository;
use Slim\Views\Twig;

/**
 * Expose a tous les gabarits ce dont ils ont besoin partout : le chemin courant
 * et les reglages du site.
 *
 * Le faire ici plutot que dans chaque controleur evite qu'un ecran ajoute plus
 * tard oublie de les passer — et pour l'adresse postale, un oubli n'est pas une
 * gene d'affichage mais une mention legale manquante.
 */
final class TemplateContextMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Twig $view,
        private SettingRepository $settings,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $environment = $this->view->getEnvironment();
        $environment->addGlobal('app_path', $request->getUri()->getPath());

        // Une base injoignable ne doit pas empecher d'afficher une page
        // d'erreur : les reglages degradent sur leurs valeurs par defaut.
        try {
            $settings = $this->settings->all();
        } catch (\Throwable) {
            $settings = SettingRepository::DEFAULTS;
        }

        $environment->addGlobal('site', $settings);
        // Decode ici plutot que dans le gabarit : Twig n'a pas de filtre
        // json_decode, et en ajouter un pour un seul usage compliquerait la
        // lecture des vues pour rien.
        $environment->addGlobal('legal_links', $this->decodeLinks($settings['site_legal_links'] ?? ''));

        return $handler->handle($request);
    }

    /**
     * @return list<array{page: string, label: string}>
     */
    private function decodeLinks(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $links = [];
        foreach ($decoded as $link) {
            if (!is_array($link) || !isset($link['page'])) {
                continue;
            }
            $page = (string) $link['page'];
            $links[] = [
                'page' => $page,
                'label' => (string) ($link['label'] ?? $page),
            ];
        }
        return $links;
    }
}
