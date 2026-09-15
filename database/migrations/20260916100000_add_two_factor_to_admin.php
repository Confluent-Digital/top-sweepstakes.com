<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Double authentification du back-office.
 *
 * Le back-office donne acces aux participants et a leurs donnees personnelles —
 * nom, adresse, telephone, preuves de consentement — derriere un seul facteur.
 * Un mot de passe reutilise ailleurs et retrouve dans une fuite suffisait.
 *
 * Trois colonnes et une table :
 *
 * - `totp_secret` : le secret partage, en base32. NULL tant que le compte n'a
 *   pas active la double authentification.
 * - `totp_confirmed_at` : date d'activation. Un secret pose mais non confirme
 *   ne compte PAS — sans quoi un compte se retrouverait verrouille par une
 *   configuration abandonnee en cours de route.
 * - `totp_last_period` : numero de la derniere periode utilisee. Un code TOTP
 *   reste valide trente secondes ; sans cette memoire, un code intercepte se
 *   rejoue dans cet intervalle.
 * - `t_admin_recovery_code` : codes de secours, HACHES. Un telephone perdu sans
 *   eux, et le seul recours est la ligne de commande sur le serveur — donc un
 *   acces SSH, que tout le monde n'a pas.
 */
final class AddTwoFactorToAdmin extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_admin_user')
            ->addColumn('admin_user_totp_secret', 'string', [
                'limit' => 64,
                'null' => true,
                'default' => null,
                'after' => 'admin_user_password_hash',
                'comment' => 'Secret TOTP en base32. NULL = double authentification inactive.',
            ])
            ->addColumn('admin_user_totp_confirmed_at', 'datetime', [
                'null' => true,
                'default' => null,
                'after' => 'admin_user_totp_secret',
                'comment' => 'Activation confirmee par un premier code valide. NULL = non active.',
            ])
            ->addColumn('admin_user_totp_last_period', 'biginteger', [
                'null' => true,
                'default' => null,
                'signed' => false,
                'after' => 'admin_user_totp_confirmed_at',
                'comment' => 'Derniere periode TOTP consommee, contre le rejeu dans la fenetre de 30 s.',
            ])
            ->update();

        $this->table('t_admin_recovery_code', [
            'id' => 'admin_recovery_code_id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Codes de secours de la double authentification. Haches, a usage unique.',
        ])
            ->addColumn('admin_recovery_code_id_user', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('admin_recovery_code_hash', 'string', [
                'limit' => 255,
                'null' => false,
                'comment' => 'password_hash du code. Le code en clair n\'est montre qu\'une fois.',
            ])
            ->addColumn('admin_recovery_code_used_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['admin_recovery_code_id_user'])
            ->addForeignKey('admin_recovery_code_id_user', 't_admin_user', 'admin_user_id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }
}
