<?php

declare(strict_types=1);

namespace App\Modules\Admin\Middleware;

use App\Core\Session\SessionStore;
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

        return $handler->handle($request->withAttribute('admin_user', $user));
    }
}
