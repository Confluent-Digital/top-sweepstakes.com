<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Comptes du back-office et journal des actions. */
final class CreateAdminTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_admin_user', [
            'id' => false,
            'primary_key' => 'admin_user_id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Comptes du back-office.',
        ])
            ->addColumn('admin_user_id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('admin_user_email', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('admin_user_name', 'string', ['limit' => 100, 'default' => '', 'null' => false])
            // password_hash(PASSWORD_DEFAULT) : jamais de hachage maison, jamais de md5.
            ->addColumn('admin_user_password_hash', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('admin_user_role', 'enum', [
                'values' => ['admin', 'operator', 'viewer'],
                'null' => false,
                'default' => 'operator',
            ])
            ->addColumn('admin_user_active', 'boolean', ['null' => false, 'default' => 1])
            ->addColumn('admin_user_last_login_at', 'datetime', ['null' => true])
            ->addColumn('admin_user_failed_attempts', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('admin_user_locked_until', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['admin_user_email'], ['unique' => true])
            ->create();

        $this->table('t_admin_log', [
            'id' => false,
            'primary_key' => 'admin_log_id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Journal des actions du back-office. Conservation 24 mois.',
        ])
            ->addColumn('admin_log_id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('admin_log_id_user', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('admin_log_action', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('admin_log_target', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('admin_log_detail', 'text', ['null' => true])
            ->addColumn('admin_log_ip', 'string', ['limit' => 45, 'default' => '', 'null' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
            ->addIndex(['admin_log_id_user'])
            ->addIndex(['created_at'])
            ->create();
    }
}
