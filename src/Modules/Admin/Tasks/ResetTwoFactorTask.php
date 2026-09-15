<?php

declare(strict_types=1);

namespace App\Modules\Admin\Tasks;

use App\Modules\Admin\Models\Repositories\AdminUserRepository;

/**
 * Retire la double authentification d'un compte, depuis la ligne de commande.
 *
 * Le dernier recours : plus de telephone, plus de codes de secours, et aucun
 * autre administrateur pour le faire depuis le back-office. Il suppose un acces
 * au serveur — c'est precisement ce qui le rend acceptable comme issue.
 *
 * Le compte retombe a un seul facteur. La tache le dit, et l'inscrit au journal
 * d'administration : une protection retiree doit laisser une trace.
 */
final class ResetTwoFactorTask
{
    public function __construct(private AdminUserRepository $users)
    {
    }

    /**
     * @param array<string,string> $options
     * @return array{ok: bool, message: string}
     */
    public function run(array $options): array
    {
        $email = trim($options['email'] ?? '');
        if ($email === '') {
            return ['ok' => false, 'message' => '--email est obligatoire.'];
        }

        $user = $this->users->findByEmail($email);
        if ($user === null) {
            return ['ok' => false, 'message' => 'Aucun compte pour ' . $email . '.'];
        }
        if (!AdminUserRepository::hasTwoFactor($user)) {
            return ['ok' => true, 'message' => $email . ' n\'a pas de double authentification active.'];
        }

        $id = (int) $user['admin_user_id'];
        $this->users->disableTwoFactor($id);
        $this->users->log($id, 'user.2fa.reset', (string) $id, $email . ' — retiree en ligne de commande', 'cli');

        return [
            'ok' => true,
            'message' => 'Double authentification retiree pour ' . $email
                . '. Ce compte n\'a plus qu\'un seul facteur : la reactiver depuis /admin/account.',
        ];
    }
}
