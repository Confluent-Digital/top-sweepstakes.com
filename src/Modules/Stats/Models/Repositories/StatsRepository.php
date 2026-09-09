<?php

declare(strict_types=1);

namespace App\Modules\Stats\Models\Repositories;

use App\Core\Database;
use Doctrine\DBAL\Connection;

final class StatsRepository
{
    public function __construct(private Database $database)
    {
    }

    private function connection(): Connection
    {
        return $this->database->connection();
    }

    /**
     * Agrege les evenements d'une journee vers t_offer_stats_daily.
     *
     * L'operation est **idempotente** : elle remplace le compte au lieu de
     * l'incrementer, si bien que rejouer une journee donne exactement le meme
     * resultat. Un rollup qui incremente ne se rattrape pas — il double.
     */
    public function rollupDay(string $date): int
    {
        $this->connection()->executeStatement(
            'INSERT INTO t_offer_stats_daily (
                 offer_stats_daily_date, offer_stats_daily_id_sweepstake,
                 offer_stats_daily_id_variant, offer_stats_daily_id_offer,
                 offer_stats_daily_step, offer_stats_daily_device,
                 offer_stats_daily_subid, offer_stats_daily_action, offer_stats_daily_nb
             )
             SELECT created_day, offer_event_id_sweepstake, offer_event_id_variant,
                    offer_event_id_offer, offer_event_step, offer_event_device,
                    offer_event_subid, offer_event_action, COUNT(*)
               FROM t_offer_event
              WHERE created_day = :date
              GROUP BY created_day, offer_event_id_sweepstake, offer_event_id_variant,
                       offer_event_id_offer, offer_event_step, offer_event_device,
                       offer_event_subid, offer_event_action
             ON DUPLICATE KEY UPDATE offer_stats_daily_nb = VALUES(offer_stats_daily_nb)',
            ['date' => $date]
        );

        // On rend le nombre de lignes d'agregat de la journee, et non le nombre
        // de lignes affectees : sur un rejeu, MySQL rapporte zero quand les
        // valeurs sont inchangees, et « 0 ligne » se lirait comme un echec
        // alors que l'agregat est bien la.
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM t_offer_stats_daily WHERE offer_stats_daily_date = :date',
            ['date' => $date]
        );
    }

    /**
     * Impressions et clics d'une journee, par offre et par source.
     *
     * @return list<array<string,mixed>>
     */
    public function trafficByDay(string $date): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT offer_stats_daily_id_offer      AS offer_id,
                    offer_stats_daily_id_sweepstake AS sweepstake_id,
                    offer_stats_daily_subid         AS subid,
                    SUM(IF(offer_stats_daily_action = "impression", offer_stats_daily_nb, 0)) AS impressions,
                    SUM(IF(offer_stats_daily_action = "click", offer_stats_daily_nb, 0))      AS clicks
               FROM t_offer_stats_daily
              WHERE offer_stats_daily_date = :date
              GROUP BY offer_stats_daily_id_offer, offer_stats_daily_id_sweepstake, offer_stats_daily_subid',
            ['date' => $date]
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return int nombre de lignes soumises — et non de lignes affectees, qui
     *             vaudrait zero sur un rejeu aux valeurs identiques
     */
    public function upsertRevenue(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $placeholders = [];
            $values = [];
            foreach ($chunk as $i => $row) {
                $placeholders[] = sprintf(
                    '(:date_%1$d, :offer_%1$d, :sweepstake_%1$d, :subid_%1$d, '
                    . ':impressions_%1$d, :clicks_%1$d, :revenue_%1$d, :ecpm_%1$d)',
                    $i
                );
                $values += [
                    'date_' . $i => $row['date'],
                    'offer_' . $i => $row['offer_id'],
                    'sweepstake_' . $i => $row['sweepstake_id'],
                    'subid_' . $i => $row['subid'],
                    'impressions_' . $i => $row['impressions'],
                    'clicks_' . $i => $row['clicks'],
                    'revenue_' . $i => $row['revenue'],
                    'ecpm_' . $i => $row['ecpm'],
                ];
            }

            $this->connection()->executeStatement(
                'INSERT INTO t_offer_revenue_daily (
                     offer_revenue_daily_date, offer_revenue_daily_id_offer,
                     offer_revenue_daily_id_sweepstake, offer_revenue_daily_subid,
                     offer_revenue_daily_impressions, offer_revenue_daily_clicks,
                     offer_revenue_daily_revenue, offer_revenue_daily_ecpm
                 ) VALUES ' . implode(', ', $placeholders) . '
                 ON DUPLICATE KEY UPDATE
                     offer_revenue_daily_impressions = VALUES(offer_revenue_daily_impressions),
                     offer_revenue_daily_clicks      = VALUES(offer_revenue_daily_clicks),
                     offer_revenue_daily_revenue     = VALUES(offer_revenue_daily_revenue),
                     offer_revenue_daily_ecpm        = VALUES(offer_revenue_daily_ecpm)',
                $values
            );
        }

        return count($rows);
    }

    /**
     * Recalcule les eCPM de chaque offre sur trois fenetres.
     *
     * Ce sont eux qui pilotent l'ordre d'affichage : ils se recalculent en une
     * requete, a partir de t_offer_revenue_daily, et jamais a la main.
     */
    public function refreshOfferEcpm(): int
    {
        return (int) $this->connection()->executeStatement(
            'UPDATE t_offer o
                LEFT JOIN (
                    SELECT offer_revenue_daily_id_offer AS offer_id,
                           SUM(IF(offer_revenue_daily_date = CURDATE(), offer_revenue_daily_revenue, 0)) AS rev_1d,
                           SUM(IF(offer_revenue_daily_date = CURDATE(), offer_revenue_daily_impressions, 0)) AS imp_1d,
                           SUM(IF(offer_revenue_daily_date >= CURDATE() - INTERVAL 5 DAY,
                                  offer_revenue_daily_revenue, 0)) AS rev_5d,
                           SUM(IF(offer_revenue_daily_date >= CURDATE() - INTERVAL 5 DAY,
                                  offer_revenue_daily_impressions, 0)) AS imp_5d,
                           SUM(IF(offer_revenue_daily_date >= CURDATE() - INTERVAL 15 DAY,
                                  offer_revenue_daily_revenue, 0)) AS rev_15d,
                           SUM(IF(offer_revenue_daily_date >= CURDATE() - INTERVAL 15 DAY,
                                  offer_revenue_daily_impressions, 0)) AS imp_15d
                      FROM t_offer_revenue_daily
                     WHERE offer_revenue_daily_date >= CURDATE() - INTERVAL 15 DAY
                     GROUP BY offer_revenue_daily_id_offer
                ) r ON r.offer_id = o.offer_id
                SET o.offer_ecpm     = IF(COALESCE(r.imp_1d, 0)  > 0, r.rev_1d  / r.imp_1d  * 1000, 0),
                    o.offer_ecpm_5d  = IF(COALESCE(r.imp_5d, 0)  > 0, r.rev_5d  / r.imp_5d  * 1000, 0),
                    o.offer_ecpm_15d = IF(COALESCE(r.imp_15d, 0) > 0, r.rev_15d / r.imp_15d * 1000, 0)'
        );
    }

    /** Purge l'evenementiel au-dela de la duree de conservation. */
    public function purgeEvents(int $months = 25): int
    {
        return (int) $this->connection()->executeStatement(
            'DELETE FROM t_offer_event WHERE created_day < (CURDATE() - INTERVAL :months MONTH)',
            ['months' => $months]
        );
    }
}
