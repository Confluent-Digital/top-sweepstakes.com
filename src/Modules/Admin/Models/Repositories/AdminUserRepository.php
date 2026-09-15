<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models\Repositories;

use App\Core\Database;

final class AdminUserRepository
{
    public function __construct(private Database $database)
    {
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $row = $this->database->connection()->fetchAssociative(
            'SELECT * FROM t_admin_user WHERE admin_user_email = :email LIMIT 1',
            ['email' => strtolower(trim($email))]
        );
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = $this->database->connection()->fetchAssociative(
            'SELECT * FROM t_admin_user WHERE admin_user_id = :id LIMIT 1',
            ['id' => $id]
        );
        return $row === false ? null : $row;
    }

    public function registerSuccess(int $id): void
    {
        $this->database->connection()->update('t_admin_user', [
            'admin_user_last_login_at' => date('Y-m-d H:i:s'),
            'admin_user_failed_attempts' => 0,
            'admin_user_locked_until' => null,
        ], ['admin_user_id' => $id]);
    }

    /**
     * Compte les echecs et verrouille temporairement le compte.
     *
     * Le back-office est expose sur Internet comme le reste du site : sans
     * cela, un mot de passe se teste a la vitesse du reseau.
     */
    public function registerFailure(int $id, int $threshold = 5, int $lockMinutes = 15): void
    {
        $attempts = (int) $this->database->connection()->fetchOne(
            'SELECT admin_user_failed_attempts FROM t_admin_user WHERE admin_user_id = :id',
            ['id' => $id]
        ) + 1;

        $data = ['admin_user_failed_attempts' => $attempts];
        if ($attempts >= $threshold) {
            $data['admin_user_locked_until'] = date('Y-m-d H:i:s', time() + $lockMinutes * 60);
        }

        $this->database->connection()->update('t_admin_user', $data, ['admin_user_id' => $id]);
    }

    /** @param array<string,mixed> $user */
    public function isLocked(array $user): bool
    {
        $until = $user['admin_user_locked_until'] ?? null;
        return is_string($until) && $until !== '' && strtotime($until) > time();
    }

    public function create(string $email, string $name, string $password, string $role = 'admin'): int
    {
        $this->database->connection()->insert('t_admin_user', [
            'admin_user_email' => strtolower(trim($email)),
            'admin_user_name' => $name,
            'admin_user_password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'admin_user_role' => $role,
            'admin_user_active' => 1,
        ]);
        return (int) $this->database->connection()->lastInsertId();
    }

    /**
     * Tous les comptes, le plus recemment connecte en tete.
     *
     * Le hachage du mot de passe n'est PAS selectionne : il n'a rien a faire
     * dans un gabarit, et ne pas le charger est plus sur que se souvenir de ne
     * pas l'afficher.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->database->connection()->fetchAllAssociative(
            'SELECT admin_user_id, admin_user_email, admin_user_name, admin_user_role,
                    admin_user_active, admin_user_last_login_at, admin_user_failed_attempts,
                    admin_user_locked_until, created_at
               FROM t_admin_user
              ORDER BY admin_user_active DESC, admin_user_last_login_at DESC, admin_user_id ASC'
        );
    }

    /** Administrateurs actifs, hors celui qu'on s'apprete a modifier. */
    public function countOtherActiveAdmins(int $exceptId): int
    {
        return (int) $this->database->connection()->fetchOne(
            'SELECT COUNT(*) FROM t_admin_user
              WHERE admin_user_role = :role AND admin_user_active = 1 AND admin_user_id != :id',
            ['role' => 'admin', 'id' => $exceptId]
        );
    }

    /** @param array<string,mixed> $values */
    public function update(int $id, array $values): void
    {
        $permis = ['admin_user_email', 'admin_user_name', 'admin_user_role', 'admin_user_active'];
        $ecrit = array_intersect_key($values, array_flip($permis));
        if ($ecrit === []) {
            return;
        }
        if (isset($ecrit['admin_user_email'])) {
            $ecrit['admin_user_email'] = strtolower(trim((string) $ecrit['admin_user_email']));
        }
        $this->database->connection()->update('t_admin_user', $ecrit, ['admin_user_id' => $id]);
    }

    /**
     * Change le mot de passe et LEVE le verrouillage.
     *
     * Les deux vont ensemble : on change le mot de passe d'un compte bloque
     * precisement pour le debloquer, et laisser le compteur d'echecs en place
     * ferait croire a un nouveau probleme.
     */
    public function setPassword(int $id, string $password): void
    {
        $this->database->connection()->update('t_admin_user', [
            'admin_user_password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'admin_user_failed_attempts' => 0,
            'admin_user_locked_until' => null,
        ], ['admin_user_id' => $id]);
    }

    public function unlock(int $id): void
    {
        $this->database->connection()->update('t_admin_user', [
            'admin_user_failed_attempts' => 0,
            'admin_user_locked_until' => null,
        ], ['admin_user_id' => $id]);
    }

    public function log(?int $userId, string $action, string $target, string $detail, string $ip): void
    {
        $this->database->connection()->insert('t_admin_log', [
            'admin_log_id_user' => $userId,
            'admin_log_action' => $action,
            'admin_log_target' => mb_substr($target, 0, 255),
            'admin_log_detail' => $detail,
            'admin_log_ip' => $ip,
        ]);
    }
}
