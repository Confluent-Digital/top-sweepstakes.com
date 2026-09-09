<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Offres display et leur rattachement aux concours.
 *
 * C'est la zone sensible du depot : ces tables decident de ce qui s'affiche et
 * de ce qui se facture. Voir .claude/rules/offers-display.md.
 */
final class CreateOfferTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_offer', [
            'id' => false,
            'primary_key' => 'offer_id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Offres partenaires en display. Aucune donnee personnelle.',
        ])
            ->addColumn('offer_id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('offer_name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('offer_advertiser', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('offer_type', 'enum', [
                'values' => ['banner', 'coupon'],
                'null' => false,
                'default' => 'banner',
            ])
            // Rendu
            ->addColumn('offer_image', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('offer_headline', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('offer_text_html', 'text', ['null' => true])
            ->addColumn('offer_cta_label', 'string', ['limit' => 100, 'default' => '', 'null' => false])
            ->addColumn('offer_target_blank', 'boolean', ['null' => false, 'default' => 1])
            // Identifiants de la regie. offer_platform_idv est la cle de
            // rapprochement des revenus : une erreur ici attribue le chiffre
            // d'affaires a une autre offre.
            ->addColumn('offer_platform_ids', 'string', ['limit' => 50, 'default' => '', 'null' => false])
            ->addColumn('offer_platform_idv', 'string', ['limit' => 50, 'default' => '', 'null' => false])
            ->addColumn('offer_platform_idc', 'string', ['limit' => 50, 'default' => '', 'null' => false])
            // Liste blanche des champs transmis dans l'URL de sortie.
            // Vide par defaut : on n'envoie rien tant qu'on ne l'a pas decide.
            ->addColumn('offer_passthrough_fields', 'json', ['null' => true])
            // Diffusion
            ->addColumn('offer_country', 'string', ['limit' => 2, 'default' => 'US', 'null' => false])
            ->addColumn('offer_active', 'boolean', ['null' => false, 'default' => 0])
            ->addColumn('offer_date_start', 'date', ['null' => true])
            ->addColumn('offer_date_end', 'date', ['null' => true])
            // Plafonds, verifies A L'AFFICHAGE et pas seulement a l'envoi.
            ->addColumn('offer_cap_day', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('offer_cap_total', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('offer_weight', 'integer', ['signed' => false, 'default' => 100, 'null' => false])
            // Recalcules par stats:rollup. Pilotent l'ordre d'affichage.
            ->addColumn('offer_ecpm', 'decimal', ['precision' => 10, 'scale' => 4, 'default' => 0, 'null' => false])
            ->addColumn('offer_ecpm_5d', 'decimal', ['precision' => 10, 'scale' => 4, 'default' => 0])
            ->addColumn('offer_ecpm_15d', 'decimal', ['precision' => 10, 'scale' => 4, 'default' => 0])
            ->addColumn('offer_notes', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['offer_active', 'offer_country'])
            ->addIndex(['offer_platform_idv'])
            ->create();

        $this->table('t_offer_targeting', [
            'id' => false,
            'primary_key' => 'offer_targeting_id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Regles de ciblage, interpretees par TargetingService uniquement.',
        ])
            ->addColumn('offer_targeting_id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('offer_targeting_id_offer', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('offer_targeting_param', 'enum', [
                'values' => ['state', 'zip', 'dob', 'gender', 'phone', 'email_domain', 'subid'],
                'null' => false,
            ])
            ->addColumn('offer_targeting_operator', 'enum', [
                'values' => ['in', 'not_in', 'gt', 'gte', 'lt', 'lte', 'between', 'not_empty', 'regex'],
                'null' => false,
            ])
            ->addColumn('offer_targeting_value', 'string', ['limit' => 1000, 'default' => '', 'null' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
            ->addIndex(['offer_targeting_id_offer'])
            ->addForeignKey('offer_targeting_id_offer', 't_offer', 'offer_id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE',
            ])
            ->create();

        $this->table('t_offer_block', [
            'id' => false,
            'primary_key' => 'offer_block_id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Regroupement visuel des offres sur une page.',
        ])
            ->addColumn('offer_block_id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('offer_block_id_sweepstake', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('offer_block_title', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('offer_block_layout', 'enum', [
                'values' => ['grid', 'list', 'coupon'],
                'null' => false,
                'default' => 'grid',
            ])
            ->addColumn('offer_block_position', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('offer_block_max_offers', 'integer', ['signed' => false, 'default' => 6, 'null' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['offer_block_id_sweepstake', 'offer_block_position'])
            ->addForeignKey('offer_block_id_sweepstake', 't_sweepstake', 'sweepstake_id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE',
            ])
            ->create();

        // La variante fait partie de la cle : dans meilleursconcours.com le code
        // filtre sur la version alors qu'elle n'est pas dans la PK, ce qui rend
        // possible deux lignes contradictoires pour un meme couple.
        $this->table('t_sweepstake_offer', [
            'id' => false,
            'primary_key' => 'sweepstake_offer_id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Rattachement offre <-> concours <-> variante <-> bloc.',
        ])
            ->addColumn('sweepstake_offer_id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('sweepstake_offer_id_sweepstake', 'integer', ['signed' => false, 'null' => false])
            // 0 = toutes les variantes du concours.
            ->addColumn('sweepstake_offer_id_variant', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('sweepstake_offer_id_offer', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('sweepstake_offer_id_block', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('sweepstake_offer_step', 'integer', ['signed' => false, 'default' => 3, 'null' => false])
            ->addColumn('sweepstake_offer_position', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('sweepstake_offer_active', 'boolean', ['null' => false, 'default' => 1])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(
                [
                    'sweepstake_offer_id_sweepstake',
                    'sweepstake_offer_id_variant',
                    'sweepstake_offer_id_offer',
                    'sweepstake_offer_step',
                ],
                ['unique' => true, 'name' => 'uq_sweepstake_variant_offer_step']
            )
            ->addIndex(['sweepstake_offer_id_offer'])
            ->addIndex(['sweepstake_offer_id_block'])
            ->addForeignKey('sweepstake_offer_id_sweepstake', 't_sweepstake', 'sweepstake_id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE',
            ])
            ->addForeignKey('sweepstake_offer_id_offer', 't_offer', 'offer_id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE',
            ])
            ->create();
    }
}
