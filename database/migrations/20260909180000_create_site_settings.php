<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Reglages du site, editables sans deploiement.
 *
 * Jusqu'ici, le nom du site, les textes de l'accueil et l'adresse postale
 * vivaient dans le `.env` ou en dur dans les gabarits. Deux consequences :
 * changer une adresse demandait une mise en production, et l'adresse postale —
 * exigee par CAN-SPAM — n'apparaissait que sur les pages rattachees a un
 * concours, donc pas sur /unsubscribe, qui est precisement la page d'atterrissage
 * d'un lien de desinscription.
 *
 * Table a ligne unique (`setting_key` / `setting_value`) plutot qu'une colonne
 * par reglage : ajouter un reglage ne demande alors plus de migration.
 */
final class CreateSiteSettings extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_setting', [
            'id' => false,
            'primary_key' => 'setting_key',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Reglages du site. Aucune donnee personnelle.',
        ])
            ->addColumn('setting_key', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('setting_value', 'text', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'update' => 'CURRENT_TIMESTAMP'])
            ->create();
    }
}
