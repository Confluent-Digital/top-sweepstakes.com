<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Models\Repositories\AdminUserRepository;
use App\Modules\Admin\Services\AdminRole;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Comptes de back-office.
 *
 * Reserve aux administrateurs (AdminRole::ADMIN_ONLY) : on ne se donne pas des
 * droits soi-meme.
 *
 * Deux garde-fous qui n'existent que pour empecher de se fermer la porte :
 * personne ne peut desactiver ni retrograder le DERNIER administrateur actif,
 * et personne ne peut desactiver son propre compte. Sans eux, il faudrait
 * revenir par la ligne de commande — ce qui suppose un acces au serveur.
 */
final class UserAdminController
{
    private const MIN_PASSWORD = 12;

    public function __construct(
        private Twig $view,
        private AdminUserRepository $users,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();

        return $this->view->render($response, 'admin/users/index.html.twig', [
            'users' => $this->users->all(),
            'roles' => AdminRole::LABELS,
            'descriptions' => AdminRole::DESCRIPTIONS,
            'current_id' => $this->currentId($request),
            'saved' => $query['saved'] ?? null,
            'error' => $query['error'] ?? null,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $input = (array) $request->getParsedBody();
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $role = (string) ($input['role'] ?? AdminRole::VIEWER);
        $password = (string) ($input['password'] ?? '');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->back($response, 'error=email');
        }
        if (!AdminRole::isKnown($role)) {
            return $this->back($response, 'error=role');
        }
        if (strlen($password) < self::MIN_PASSWORD) {
            return $this->back($response, 'error=password');
        }
        if ($this->users->findByEmail($email) !== null) {
            return $this->back($response, 'error=exists');
        }

        $id = $this->users->create($email, $name !== '' ? $name : $email, $password, $role);
        $this->log($request, 'user.create', (string) $id, $email . ' — ' . $role);

        return $this->back($response, 'saved=created');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $cible = $this->users->findById($id);
        if ($cible === null) {
            return $this->back($response, 'error=unknown');
        }

        $input = (array) $request->getParsedBody();
        $role = (string) ($input['role'] ?? $cible['admin_user_role']);
        $actif = isset($input['active']) ? 1 : 0;

        if (!AdminRole::isKnown($role)) {
            return $this->back($response, 'error=role');
        }

        // Se fermer la porte a soi-meme, ou fermer la derniere porte : les deux
        // se rattrapent uniquement en ligne de commande, donc avec un acces au
        // serveur. Autant l'empecher.
        $dernierAdmin = $cible['admin_user_role'] === AdminRole::ADMIN
            && $this->users->countOtherActiveAdmins($id) === 0;

        if ($dernierAdmin && ($actif === 0 || $role !== AdminRole::ADMIN)) {
            return $this->back($response, 'error=last_admin');
        }
        if ($id === $this->currentId($request) && $actif === 0) {
            return $this->back($response, 'error=self');
        }

        $this->users->update($id, [
            'admin_user_name' => trim((string) ($input['name'] ?? $cible['admin_user_name'])),
            'admin_user_role' => $role,
            'admin_user_active' => $actif,
        ]);
        $this->log($request, 'user.update', (string) $id, sprintf(
            '%s — role %s, %s',
            (string) $cible['admin_user_email'],
            $role,
            $actif === 1 ? 'actif' : 'desactive'
        ));

        return $this->back($response, 'saved=updated');
    }

    public function password(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->users->findById($id) === null) {
            return $this->back($response, 'error=unknown');
        }

        $password = (string) (((array) $request->getParsedBody())['password'] ?? '');
        if (strlen($password) < self::MIN_PASSWORD) {
            return $this->back($response, 'error=password');
        }

        $this->users->setPassword($id, $password);
        // Le mot de passe n'apparait evidemment pas dans le journal.
        $this->log($request, 'user.password', (string) $id, 'mot de passe change');

        return $this->back($response, 'saved=password');
    }

    public function unlock(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->users->findById($id) === null) {
            return $this->back($response, 'error=unknown');
        }

        $this->users->unlock($id);
        $this->log($request, 'user.unlock', (string) $id, 'verrouillage leve');

        return $this->back($response, 'saved=unlocked');
    }

    private function currentId(Request $request): int
    {
        $user = $request->getAttribute('admin_user');
        return is_array($user) ? (int) $user['admin_user_id'] : 0;
    }

    private function log(Request $request, string $action, string $target, string $detail): void
    {
        $this->users->log(
            $this->currentId($request) ?: null,
            $action,
            $target,
            $detail,
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '')
        );
    }

    private function back(Response $response, string $suffix): Response
    {
        return $response->withHeader('Location', '/admin/users?' . $suffix)->withStatus(302);
    }
}
