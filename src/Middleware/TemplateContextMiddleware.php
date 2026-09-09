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
        // Le decodage vit dans SettingRepository : c'est la meme liste que
        // lisent le formulaire de reglages et le controle d'ouverture.
        $links = [];
        $decoded = SettingRepository::decodeLegalLinks((string) ($settings['site_legal_links'] ?? ''));
        foreach ($decoded as $page => $label) {
            $links[] = ['page' => $page, 'label' => $label];
        }
        $environment->addGlobal('legal_links', $links);

        return $handler->handle($request);
    }
}
