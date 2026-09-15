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
                    admin_user_locked_until, admin_user_totp_confirmed_at, created_at
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

    // ------------------------------------------------- Double authentification

    /** Le compte a-t-il une double authentification ACTIVE ? */
    public static function hasTwoFactor(array $user): bool
    {
        return ($user['admin_user_totp_secret'] ?? null) !== null
            && ($user['admin_user_totp_confirmed_at'] ?? null) !== null;
    }

    /**
     * Pose un secret, sans l'activer.
     *
     * L'activation attend un premier code valide : un secret pose mais jamais
     * confirme — application desinstallee, page fermee en cours de route — ne
     * doit pas verrouiller le compte a la prochaine connexion.
     */
    public function startTwoFactor(int $id, string $secret): void
    {
        $this->database->connection()->update('t_admin_user', [
            'admin_user_totp_secret' => $secret,
            'admin_user_totp_confirmed_at' => null,
            'admin_user_totp_last_period' => null,
        ], ['admin_user_id' => $id]);
    }

    public function confirmTwoFactor(int $id, int $period): void
    {
        $this->database->connection()->update('t_admin_user', [
            'admin_user_totp_confirmed_at' => date('Y-m-d H:i:s'),
            'admin_user_totp_last_period' => $period,
        ], ['admin_user_id' => $id]);
    }

    public function disableTwoFactor(int $id): void
    {
        $this->database->connection()->update('t_admin_user', [
            'admin_user_totp_secret' => null,
            'admin_user_totp_confirmed_at' => null,
            'admin_user_totp_last_period' => null,
        ], ['admin_user_id' => $id]);
        $this->database->connection()->delete('t_admin_recovery_code', ['admin_recovery_code_id_user' => $id]);
    }

    /**
     * Consomme une periode TOTP, si elle n'a pas deja servi.
     *
     * Un code reste valide trente secondes. Sans cette barriere, un code
     * intercepte — epaule, journal de proxy, capture d'ecran — se rejoue dans
     * cet intervalle. La condition est dans le WHERE et non lue puis ecrite :
     * deux tentatives simultanees ne doivent pas passer toutes les deux.
     */
    public function consumePeriod(int $id, int $period): bool
    {
        $affectees = $this->database->connection()->executeStatement(
            'UPDATE t_admin_user
                SET admin_user_totp_last_period = :period
              WHERE admin_user_id = :id
                AND (admin_user_totp_last_period IS NULL OR admin_user_totp_last_period < :period)',
            ['id' => $id, 'period' => $period]
        );
        return $affectees > 0;
    }

    /**
     * Remplace les codes de secours et rend les codes EN CLAIR.
     *
     * Ils ne sont montres qu'ici : la base n'en garde que le hachage, au meme
     * titre qu'un mot de passe. Les perdre impose de les regenerer.
     *
     * @return list<string>
     */
    public function resetRecoveryCodes(int $id, int $combien = 10): array
    {
        $connection = $this->database->connection();
        $connection->delete('t_admin_recovery_code', ['admin_recovery_code_id_user' => $id]);

        $codes = [];
        for ($i = 0; $i < $combien; $i++) {
            // Dix caracteres base32 : assez pour resister a la force brute,
            // assez court pour etre recopie depuis un papier. L'alphabet exclut
            // 0, 1, 8 et O, I, B — les confusions de lecture manuscrite.
            $code = '';
            for ($j = 0; $j < 10; $j++) {
                $code .= 'ACDEFGHJKLMNPQRSTUVWXYZ234567'[random_int(0, 28)];
            }
            $code = substr($code, 0, 5) . '-' . substr($code, 5);
            $codes[] = $code;

            $connection->insert('t_admin_recovery_code', [
                'admin_recovery_code_id_user' => $id,
                'admin_recovery_code_hash' => password_hash($code, PASSWORD_DEFAULT),
            ]);
        }
        return $codes;
    }

    /**
     * Consomme un code de secours. Vrai s'il etait valide et inutilise.
     *
     * Le parcours de tous les codes est volontaire : `password_verify` ne
     * permet pas de retrouver une ligne par index, et dix verifications sur un
     * chemin de connexion ne coutent rien.
     */
    public function consumeRecoveryCode(int $id, string $code): bool
    {
        $code = strtoupper(trim($code));
        $lignes = $this->database->connection()->fetchAllAssociative(
            'SELECT admin_recovery_code_id, admin_recovery_code_hash
               FROM t_admin_recovery_code
              WHERE admin_recovery_code_id_user = :id AND admin_recovery_code_used_at IS NULL',
            ['id' => $id]
        );

        foreach ($lignes as $ligne) {
            if (password_verify($code, (string) $ligne['admin_recovery_code_hash'])) {
                $this->database->connection()->update(
                    't_admin_recovery_code',
                    ['admin_recovery_code_used_at' => date('Y-m-d H:i:s')],
                    ['admin_recovery_code_id' => (int) $ligne['admin_recovery_code_id']]
                );
                return true;
            }
        }
        return false;
    }

    public function countUnusedRecoveryCodes(int $id): int
    {
        return (int) $this->database->connection()->fetchOne(
            'SELECT COUNT(*) FROM t_admin_recovery_code
              WHERE admin_recovery_code_id_user = :id AND admin_recovery_code_used_at IS NULL',
            ['id' => $id]
        );
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
