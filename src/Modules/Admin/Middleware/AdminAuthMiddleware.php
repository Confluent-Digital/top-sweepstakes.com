<?php

declare(strict_types=1);

namespace App\Modules\Admin\Middleware;

use App\Core\Session\SessionStore;
use App\Modules\Admin\Services\AdminRole;
use App\Modules\Admin\Models\Repositories\AdminUserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Protege TOUT le groupe /admin.
 *
 * Il est pose sur le groupe et non route par route : une route ajoutee plus
 * tard est protegee par construction, sans qu'on ait a y penser. C'est la
 * seule facon de ne pas laisser un ecran ouvert par oubli.
 */
final class AdminAuthMiddleware implements MiddlewareInterface
{
    public const SESSION_KEY = 'admin_user_id';

    public function __construct(
        private SessionStore $session,
        private AdminUserRepository $users,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        // La page de connexion elle-meme doit rester accessible.
        // /admin/login/code est couverte par ce prefixe : le second facteur se
        // presente avant d'etre authentifie, c'est tout son objet.
        if (str_starts_with($path, '/admin/login') || str_starts_with($path, '/admin/logout')) {
            return $handler->handle($request);
        }

        $userId = $this->session->get(self::SESSION_KEY);
        $user = is_numeric($userId) ? $this->users->findById((int) $userId) : null;

        if ($user === null || !(bool) $user['admin_user_active']) {
            $this->session->remove(self::SESSION_KEY);
            return (new ResponseFactory())->createResponse(302)
                ->withHeader('Location', '/admin/login');
        }

        // Le role n'etait lu NULLE PART : « viewer » pouvait publier un concours
        // et exporter les participants. Il se verifie ici, sur le groupe, et non
        // route par route — une route ajoutee demain est couverte par
        // construction.
        $role = (string) ($user['admin_user_role'] ?? '');
        if (!AdminRole::allows($role, $request->getMethod(), $path)) {
            return $this->refuse($request, $role);
        }

        return $handler->handle($request->withAttribute('admin_user', $user));
    }

    /**
     * Refus de droits.
     *
     * 403 et non 404 : l'ecran existe, c'est l'acces qui manque. Repondre 404
     * ferait chercher un bogue la ou il n'y en a pas. Pour une requete de
     * modification, on renvoie sur l'ecran demande en lecture — l'operateur
     * voit la page et l'explication, plutot qu'une impasse.
     */
    private function refuse(ServerRequestInterface $request, string $role): ResponseInterface
    {
        $response = (new ResponseFactory())->createResponse(403);
        $response->getBody()->write(sprintf(
            '<!doctype html><meta charset="utf-8"><title>Acces refuse</title>'
            . '<div style="font:16px/1.6 system-ui;max-width:34rem;margin:15vh auto;padding:0 1rem">'
            . '<h1 style="font-size:1.25rem">Acces refuse</h1>'
            . '<p>Votre compte a le role <strong>%s</strong>. %s</p>'
            . '<p><a href="/admin">Retour au tableau de bord</a></p></div>',
            htmlspecialchars(AdminRole::label($role), ENT_QUOTES),
            htmlspecialchars(AdminRole::DESCRIPTIONS[$role] ?? 'Cette action ne lui est pas ouverte.', ENT_QUOTES)
        ));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
