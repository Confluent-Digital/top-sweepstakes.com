<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;

/**
 * Expose le chemin courant aux gabarits.
 *
 * Il sert a marquer l'entree de menu active dans le back-office. Le faire ici
 * plutot que dans chaque controleur evite qu'un ecran ajoute plus tard oublie
 * de le passer et perde son repere de navigation.
 */
final class TemplateContextMiddleware implements MiddlewareInterface
{
    public function __construct(private Twig $view)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->view->getEnvironment()->addGlobal('app_path', $request->getUri()->getPath());
        return $handler->handle($request);
    }
}
