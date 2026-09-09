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
            $environment->addGlobal('site', $this->settings->all());
        } catch (\Throwable) {
            $environment->addGlobal('site', SettingRepository::DEFAULTS);
        }

        return $handler->handle($request);
    }
}
