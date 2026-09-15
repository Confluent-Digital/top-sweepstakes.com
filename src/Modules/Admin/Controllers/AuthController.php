<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Session\SessionStore;
use App\Modules\Admin\Middleware\AdminAuthMiddleware;
use App\Modules\Admin\Models\Repositories\AdminUserRepository;
use App\Modules\Admin\Services\Totp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Connexion au back-office, en deux temps quand la double authentification est
 * active : mot de passe, puis code a usage unique.
 *
 * Entre les deux, la session ne porte PAS `admin_user_id` — sinon le premier
 * facteur suffirait a atteindre les ecrans. Elle porte une cle distincte, avec
 * une date limite : un premier facteur valide ne doit pas rester indefiniment
 * ouvert sur un poste laisse sans surveillance.
 */
final class AuthController
{
    /** Cle de session portant l'utilisateur ayant passe le PREMIER facteur. */
    private const PENDING_KEY = 'admin_pending_user_id';
    private const PENDING_UNTIL = 'admin_pending_until';

    /** Delai pour saisir le code, en secondes. */
    private const PENDING_TTL = 300;

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
            } elseif (AdminUserRepository::hasTwoFactor($user)) {
                // Premier facteur franchi, et rien de plus : la session ne
                // recoit pas encore l'identite, seulement une autorisation a
                // presenter le second facteur, bornee dans le temps.
                $this->session->set(self::PENDING_KEY, (int) $user['admin_user_id']);
                $this->session->set(self::PENDING_UNTIL, time() + self::PENDING_TTL);
                return $response->withHeader('Location', '/admin/login/code')->withStatus(302);
            } else {
                $this->complete($request, (int) $user['admin_user_id'], $email, 'mot de passe seul');
                return $response->withHeader('Location', '/admin')->withStatus(302);
            }
        }

        return $this->view->render($response, 'admin/login.html.twig', [
            'error' => $error,
            'email' => $email,
        ]);
    }

    /**
     * Second facteur : code de l'application, ou code de secours.
     */
    public function code(Request $request, Response $response): Response
    {
        $user = $this->pendingUser();
        if ($user === null) {
            return $response->withHeader('Location', '/admin/login')->withStatus(302);
        }

        $error = null;
        $id = (int) $user['admin_user_id'];

        if ($request->getMethod() === 'POST') {
            $saisi = trim((string) (((array) $request->getParsedBody())['code'] ?? ''));

            // Le verrouillage du compte s'applique ICI aussi. Sans cela, le
            // second facteur — six chiffres — s'attaquerait par force brute
            // sans limite, une fois le mot de passe connu.
            if ($this->users->isLocked($user)) {
                $error = 'Trop de tentatives. Ce compte est temporairement verrouille.';
            } else {
                $periode = Totp::verify((string) $user['admin_user_totp_secret'], $saisi);

                if ($periode !== null && $this->users->consumePeriod($id, $periode)) {
                    $this->finish($request, $response, $id, (string) $user['admin_user_email'], 'code');
                    return $response->withHeader('Location', '/admin')->withStatus(302);
                }

                // Un code de secours n'a de sens que si le code n'a pas marche.
                // L'ordre compte : essayer d'abord le secours consommerait un
                // code a usage unique sur une saisie correcte.
                if ($periode === null && $this->users->consumeRecoveryCode($id, $saisi)) {
                    $this->finish($request, $response, $id, (string) $user['admin_user_email'], 'code de secours');
                    return $response->withHeader('Location', '/admin?recovery=1')->withStatus(302);
                }

                $this->users->registerFailure($id);
                $error = $periode !== null
                    ? 'Ce code a deja servi. Attendez le suivant.'
                    : 'Code invalide.';
            }
        }

        return $this->view->render($response, 'admin/login-code.html.twig', [
            'error' => $error,
            'email' => (string) $user['admin_user_email'],
            'restants' => $this->users->countUnusedRecoveryCodes($id),
        ]);
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->session->remove(AdminAuthMiddleware::SESSION_KEY);
        $this->clearPending();
        return $response->withHeader('Location', '/admin/login')->withStatus(302);
    }

    /**
     * Utilisateur ayant passe le premier facteur, si le delai n'est pas ecoule.
     *
     * @return array<string,mixed>|null
     */
    private function pendingUser(): ?array
    {
        $id = $this->session->get(self::PENDING_KEY);
        $until = $this->session->get(self::PENDING_UNTIL);

        if (!is_numeric($id) || !is_numeric($until) || (int) $until < time()) {
            $this->clearPending();
            return null;
        }

        $user = $this->users->findById((int) $id);
        if ($user === null || !(bool) $user['admin_user_active'] || !AdminUserRepository::hasTwoFactor($user)) {
            $this->clearPending();
            return null;
        }
        return $user;
    }

    private function clearPending(): void
    {
        $this->session->remove(self::PENDING_KEY);
        $this->session->remove(self::PENDING_UNTIL);
    }

    private function finish(Request $request, Response $response, int $id, string $email, string $facteur): void
    {
        $this->clearPending();
        $this->complete($request, $id, $email, 'double authentification — ' . $facteur);
    }

    private function complete(Request $request, int $id, string $email, string $detail): void
    {
        $this->users->registerSuccess($id);
        // Identifiant de session renouvele a l'ouverture : un identifiant fixe
        // par un tiers avant la connexion ne doit pas survivre a celle-ci.
        $this->session->regenerate();
        $this->session->set(AdminAuthMiddleware::SESSION_KEY, $id);
        $this->users->log(
            $id,
            'login',
            $email,
            $detail,
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '')
        );
    }
}
