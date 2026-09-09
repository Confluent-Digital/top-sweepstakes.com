<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Le concours et son gabarit, entierement en base.
 *
 * Aucune de ces colonnes n'a d'equivalent en fichier : c'est l'invariant du
 * depot. Un concours se cree ici, pas en copiant un dossier de vues.
 */
final class CreateSweepstakeTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_sweepstake', [
            'id' => false,
            'primary_key' => 'sweepstake_id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Jeux-concours. Donnees non personnelles, conservation illimitee.',
        ])
            ->addColumn('sweepstake_id', 'integer', ['identity' => true, 'signed' => false])
            // Le slug est dans l'URL publique ET dans le sid envoye a la regie :
            // le changer apres publication casse l'attribution des revenus deja
            // collectes. Voir .claude/rules/tracking-stats.md.
            ->addColumn('sweepstake_slug', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('sweepstake_name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('sweepstake_status', 'enum', [
                'values' => ['draft', 'published', 'paused', 'ended'],
                'null' => false,
                'default' => 'draft',
            ])
            // Dotation
            ->addColumn('sweepstake_prize_title', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('sweepstake_prize_value_usd', 'decimal', [
                'precision' => 10, 'scale' => 2, 'null' => false, 'default' => 0,
                'comment' => 'ARV. Au-dela de 5000 $, enregistrement requis dans NY et FL.',
            ])
            ->addColumn('sweepstake_prize_image', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('sweepstake_sponsor_name', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('sweepstake_sponsor_address', 'string', ['limit' => 500, 'default' => '', 'null' => false])
            // Obligatoire des que la dotation porte une marque tierce.
            ->addColumn('sweepstake_brand_disclaimer', 'string', ['limit' => 500, 'default' => '', 'null' => false])
            // Eligibilite. sweepstake_excluded_states est la source de verite :
            // c'est elle qui refuse effectivement le participant, et elle doit
            // dire la meme chose que les Official Rules.
            ->addColumn('sweepstake_date_start', 'date', ['null' => true])
            ->addColumn('sweepstake_date_end', 'date', ['null' => true])
            ->addColumn('sweepstake_min_age', 'integer', ['signed' => false, 'default' => 18, 'null' => false])
            ->addColumn('sweepstake_excluded_states', 'string', ['limit' => 255, 'default' => '',
                'comment' => 'Codes d\'Etat sur 2 lettres, separes par des virgules.',
                'null' => false,
            ])
            // Contenu editorial
            ->addColumn('sweepstake_official_rules_html', 'text', ['null' => true])
            ->addColumn('sweepstake_thankyou_html', 'text', ['null' => true])
            ->addColumn('sweepstake_meta_title', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('sweepstake_meta_description', 'string', ['limit' => 500, 'default' => '', 'null' => false])
            // Theme : couleurs, police, visuel de fond. Injecte en variables CSS.
            ->addColumn('sweepstake_theme', 'json', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['sweepstake_slug'], ['unique' => true])
            ->addIndex(['sweepstake_status'])
            ->create();

        $this->table('t_sweepstake_field', [
            'id' => false,
            'primary_key' => 'sweepstake_field_id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Champs du formulaire, par concours. Remplace les gabarits dupliques.',
        ])
            ->addColumn('sweepstake_field_id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('sweepstake_field_id_sweepstake', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('sweepstake_field_key', 'enum', [
                'values' => [
                    'email', 'first_name', 'last_name', 'address', 'city',
                    'state', 'zip', 'phone', 'dob', 'gender',
                ],
                'null' => false,
            ])
            ->addColumn('sweepstake_field_step', 'integer', ['signed' => false, 'default' => 1, 'null' => false])
            ->addColumn('sweepstake_field_position', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('sweepstake_field_required', 'boolean', ['null' => false, 'default' => 1])
            ->addColumn('sweepstake_field_active', 'boolean', ['null' => false, 'default' => 1])
            ->addColumn('sweepstake_field_label', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['sweepstake_field_id_sweepstake', 'sweepstake_field_key'], ['unique' => true])
            ->addIndex(['sweepstake_field_id_sweepstake', 'sweepstake_field_step'])
            ->addForeignKey('sweepstake_field_id_sweepstake', 't_sweepstake', 'sweepstake_id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE',
            ])
            ->create();

        $this->table('t_sweepstake_variant', [
            'id' => false,
            'primary_key' => 'sweepstake_variant_id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Variantes A/B. Le device est explicite, pas un id magique.',
        ])
            ->addColumn('sweepstake_variant_id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('sweepstake_variant_id_sweepstake', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('sweepstake_variant_name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('sweepstake_variant_device', 'enum', [
                'values' => ['all', 'desktop', 'mobile'],
                'null' => false,
                'default' => 'all',
            ])
            ->addColumn('sweepstake_variant_weight', 'integer', ['signed' => false, 'default' => 100, 'null' => false])
            ->addColumn('sweepstake_variant_active', 'boolean', ['null' => false, 'default' => 1])
            ->addColumn('sweepstake_variant_overrides', 'json', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['sweepstake_variant_id_sweepstake', 'sweepstake_variant_active'])
            ->addForeignKey('sweepstake_variant_id_sweepstake', 't_sweepstake', 'sweepstake_id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE',
            ])
            ->create();
    }
}
