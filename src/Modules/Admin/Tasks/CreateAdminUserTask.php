<?php

declare(strict_types=1);

namespace App\Modules\Admin\Tasks;

use App\Modules\Admin\Models\Repositories\AdminUserRepository;

/**
 * Creation d'un compte de back-office, en ligne de commande.
 *
 * Il n'existe pas d'ecran d'inscription : le premier compte se cree ici, et les
 * suivants aussi. Une page publique de creation de compte administrateur serait
 * la premiere chose qu'un robot trouverait.
 */
final class CreateAdminUserTask
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
        $name = trim($options['name'] ?? '');
        $password = $options['password'] ?? '';
        $role = $options['role'] ?? 'admin';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => '--email est obligatoire et doit etre une adresse valide.'];
        }
        if (strlen($password) < 12) {
            return ['ok' => false, 'message' => '--password doit faire au moins 12 caracteres.'];
        }
        if (!in_array($role, ['admin', 'operator', 'viewer'], true)) {
            return ['ok' => false, 'message' => '--role doit valoir admin, operator ou viewer.'];
        }
        if ($this->users->findByEmail($email) !== null) {
            return ['ok' => false, 'message' => 'Un compte existe deja pour ' . $email . '.'];
        }

        $id = $this->users->create($email, $name !== '' ? $name : $email, $password, $role);

        return ['ok' => true, 'message' => sprintf('Compte %s cree (#%d, role %s).', $email, $id, $role)];
    }
}
