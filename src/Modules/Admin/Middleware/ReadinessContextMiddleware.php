<?php

declare(strict_types=1);

namespace App\Modules\Admin\Middleware;

use App\Modules\Admin\Services\ReadinessService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

/**
 * Expose le compte des reserves d'ouverture a tous les gabarits du back-office.
 *
 * Le bandeau doit suivre l'exploitant partout : une reserve bloquante consultee
 * uniquement sur l'ecran qui la liste ne sert a rien, puisque personne n'ouvre
 * cet ecran de lui-meme. Il est donc affiche sur chaque page.
 *
 * Pose a l'INTERIEUR du groupe protege, apres l'authentification : la page de
 * connexion n'a pas a reveler l'etat de conformite du site, et l'attribut
 * `admin_user` sert justement a la distinguer.
 */
final class ReadinessContextMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Twig $view,
        private ReadinessService $readiness,
        private LoggerInterface $logger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $counts = null;

        if ($request->getAttribute('admin_user') !== null) {
            try {
                $counts = $this->readiness->summary();
            } catch (\Throwable $e) {
                // Un controle qui echoue ne doit pas emporter le back-office :
                // l'exploitant a d'autres choses a y faire. L'absence de
                // bandeau est tracee pour ne pas passer pour un site conforme.
                $this->logger->error('Reserves d\'ouverture : calcul impossible', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->view->getEnvironment()->addGlobal('readiness', $counts);

        return $handler->handle($request);
    }
}
