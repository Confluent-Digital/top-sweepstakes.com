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
