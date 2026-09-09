<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tracking display et revenus.
 *
 * Deux niveaux : l'evenementiel (avec lead_id, pour recouper une facturation
 * contestee) et l'agregat au jour (pour les ecrans de stats). Voir
 * .claude/rules/tracking-stats.md.
 */
final class CreateTrackingTables extends AbstractMigration
{
    public function change(): void
    {
        // Grain evenementiel. C'est ce qui manque a meilleursconcours.com, dont
        // le compteur agrege n'a pas d'identifiant de prospect : impossible d'y
        // dire qui a clique sur quoi.
        $this->table('t_offer_event', [
            'id' => false,
            'primary_key' => 'offer_event_id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Impressions et clics, au grain evenement. Conservation 25 mois.',
        ])
            ->addColumn('offer_event_id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('offer_event_session_uid', 'string', ['limit' => 40, 'default' => '', 'null' => false])
            // Nullable : une impression peut precede l'enregistrement du participant.
            ->addColumn('offer_event_id_lead', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('offer_event_id_sweepstake', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('offer_event_id_variant', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('offer_event_id_offer', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('offer_event_id_block', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('offer_event_step', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('offer_event_position', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('offer_event_device', 'enum', [
                'values' => ['desktop', 'mobile', 'tablet', 'unknown'],
                'null' => false,
                'default' => 'unknown',
            ])
            ->addColumn('offer_event_action', 'enum', [
                'values' => ['impression', 'viewable', 'click'],
                'null' => false,
            ])
            ->addColumn('offer_event_subid', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
            ->addColumn('created_day', 'date', [
                'null' => false,
                'comment' => 'Jour de created_at, denormalise pour le rollup et la purge.',
            ])
            ->addIndex(['created_day', 'offer_event_action'])
            ->addIndex(['offer_event_id_offer', 'created_day'])
            ->addIndex(['offer_event_id_lead'])
            ->addIndex(['offer_event_session_uid'])
            ->create();

        // Agregat. La cle unique porte toutes les dimensions : le rollup est
        // idempotent par INSERT ... ON DUPLICATE KEY UPDATE, jamais par
        // « lire puis ecrire » (qui perd des evenements en concurrence).
        $this->table('t_offer_stats_daily', [
            'id' => false,
            'primary_key' => 'offer_stats_daily_id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Agregat journalier des evenements display.',
        ])
            ->addColumn('offer_stats_daily_id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('offer_stats_daily_date', 'date', ['null' => false])
            ->addColumn('offer_stats_daily_id_sweepstake', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('offer_stats_daily_id_variant', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('offer_stats_daily_id_offer', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('offer_stats_daily_step', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('offer_stats_daily_device', 'enum', [
                'values' => ['desktop', 'mobile', 'tablet', 'unknown'],
                'null' => false,
                'default' => 'unknown',
            ])
            ->addColumn('offer_stats_daily_subid', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('offer_stats_daily_action', 'enum', [
                'values' => ['impression', 'viewable', 'click'],
                'null' => false,
            ])
            ->addColumn('offer_stats_daily_nb', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(
                [
                    'offer_stats_daily_date',
                    'offer_stats_daily_id_sweepstake',
                    'offer_stats_daily_id_variant',
                    'offer_stats_daily_id_offer',
                    'offer_stats_daily_step',
                    'offer_stats_daily_device',
                    'offer_stats_daily_subid',
                    'offer_stats_daily_action',
                ],
                ['unique' => true, 'name' => 'uq_offer_stats_daily']
            )
            ->addIndex(['offer_stats_daily_id_offer', 'offer_stats_daily_date'])
            ->create();

        // Revenus tires du flux de la regie. La cle de jointure avec notre
        // tracking est le sid : {sweepstake_id}_{subid}_{email_md5}_{date}.
        $this->table('t_platform_report', [
            'id' => false,
            'primary_key' => 'platform_report_id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Flux de reporting de la regie d\'affiliation.',
        ])
            ->addColumn('platform_report_id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('platform_report_date', 'date', ['null' => false])
            ->addColumn('platform_report_idc', 'string', ['limit' => 50, 'default' => '', 'null' => false])
            ->addColumn('platform_report_ids', 'string', ['limit' => 50, 'default' => '', 'null' => false])
            ->addColumn('platform_report_sid', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('platform_report_campaign_name', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('platform_report_impressions', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('platform_report_clicks', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('platform_report_dbclicks', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('platform_report_cpl_valid', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('platform_report_cpl_pending', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('platform_report_cpa_valid', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('platform_report_cpa_pending', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('platform_report_gains_valid', 'decimal', ['precision' => 12, 'scale' => 4, 'default' => 0, 'null' => false])
            ->addColumn('platform_report_gains_pending', 'decimal', ['precision' => 12, 'scale' => 4, 'default' => 0, 'null' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(
                ['platform_report_date', 'platform_report_idc', 'platform_report_ids', 'platform_report_sid'],
                ['unique' => true, 'name' => 'uq_platform_report']
            )
            ->addIndex(['platform_report_date'])
            ->create();

        $this->table('t_offer_revenue_daily', [
            'id' => false,
            'primary_key' => 'offer_revenue_daily_id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Croisement tracking local x revenus regie. Alimente les eCPM de t_offer.',
        ])
            ->addColumn('offer_revenue_daily_id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('offer_revenue_daily_date', 'date', ['null' => false])
            ->addColumn('offer_revenue_daily_id_offer', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('offer_revenue_daily_id_sweepstake', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('offer_revenue_daily_subid', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('offer_revenue_daily_impressions', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('offer_revenue_daily_clicks', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('offer_revenue_daily_revenue', 'decimal', ['precision' => 12, 'scale' => 4, 'default' => 0, 'null' => false])
            ->addColumn('offer_revenue_daily_ecpm', 'decimal', ['precision' => 10, 'scale' => 4, 'default' => 0, 'null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(
                [
                    'offer_revenue_daily_date',
                    'offer_revenue_daily_id_offer',
                    'offer_revenue_daily_id_sweepstake',
                    'offer_revenue_daily_subid',
                ],
                ['unique' => true, 'name' => 'uq_offer_revenue_daily']
            )
            ->addIndex(['offer_revenue_daily_id_offer', 'offer_revenue_daily_date'])
            ->create();
    }
}
