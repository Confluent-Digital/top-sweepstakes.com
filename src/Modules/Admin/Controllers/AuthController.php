<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Session\SessionStore;
use App\Modules\Admin\Middleware\AdminAuthMiddleware;
use App\Modules\Admin\Models\Repositories\AdminUserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class AuthController
{
    public function __construct(
        private Twig $view,
        private SessionStore $session,
        private AdminUserRepository $users,
    ) {
    }

    public function login(Request $request, Response $response): Response
    {
        $error = null;
        $email = '';

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            $email = trim((string) ($input['email'] ?? ''));
            $password = (string) ($input['password'] ?? '');

            $user = $this->users->findByEmail($email);

            if ($user !== null && $this->users->isLocked($user)) {
                $error = 'Trop de tentatives. Ce compte est temporairement verrouille.';
            } elseif ($user === null || !(bool) $user['admin_user_active']) {
                // Message identique dans tous les cas d'echec : distinguer
                // « compte inconnu » de « mot de passe faux » revient a offrir
                // un enumerateur de comptes.
                $error = 'Identifiants invalides.';
            } elseif (!password_verify($password, (string) $user['admin_user_password_hash'])) {
                $this->users->registerFailure((int) $user['admin_user_id']);
                $error = 'Identifiants invalides.';
            } else {
                $this->users->registerSuccess((int) $user['admin_user_id']);
                $this->session->set(AdminAuthMiddleware::SESSION_KEY, (int) $user['admin_user_id']);
                $this->users->log(
                    (int) $user['admin_user_id'],
                    'login',
                    $email,
                    '',
                    (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '')
                );
                return $response->withHeader('Location', '/admin')->withStatus(302);
            }
        }

        return $this->view->render($response, 'admin/login.html.twig', [
            'error' => $error,
            'email' => $email,
        ]);
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->session->remove(AdminAuthMiddleware::SESSION_KEY);
        return $response->withHeader('Location', '/admin/login')->withStatus(302);
    }
}
