<?php

declare(strict_types=1);

namespace App\Modules\Offers\Models\Repositories;

use App\Core\Database;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class OfferRepository
{
    public function __construct(private Database $database)
    {
    }

    private function connection(): Connection
    {
        return $this->database->connection();
    }

    /**
     * Offres candidates pour un concours, une variante et une etape, avec tout
     * ce dont OfferSelector a besoin pour decider : regles de ciblage et
     * compteurs d'impressions.
     *
     * Trois requetes et non une par offre : cette page est sur le chemin
     * critique d'un trafic paye.
     *
     * @return list<array<string,mixed>>
     */
    public function findCandidates(int $sweepstakeId, int $variantId, int $step): array
    {
        $offers = $this->connection()->fetchAllAssociative(
            'SELECT o.*,
                    so.sweepstake_offer_id_block   AS block_id,
                    so.sweepstake_offer_position   AS attach_position
               FROM t_sweepstake_offer so
               JOIN t_offer o ON o.offer_id = so.sweepstake_offer_id_offer
              WHERE so.sweepstake_offer_id_sweepstake = :sweepstake
                AND so.sweepstake_offer_step = :step
                AND so.sweepstake_offer_active = 1
                -- 0 signifie « toutes les variantes de ce concours ».
                AND so.sweepstake_offer_id_variant IN (0, :variant)
              ORDER BY so.sweepstake_offer_position ASC',
            ['sweepstake' => $sweepstakeId, 'variant' => $variantId, 'step' => $step]
        );

        if ($offers === []) {
            return [];
        }

        $ids = array_map(static fn(array $o): int => (int) $o['offer_id'], $offers);
        $rules = $this->targetingRulesByOffer($ids);
        $counters = $this->impressionCounters($ids);

        foreach ($offers as $i => $offer) {
            $id = (int) $offer['offer_id'];
            $offers[$i]['targeting_rules'] = $rules[$id] ?? [];
            $offers[$i]['impressions_today'] = $counters[$id]['today'] ?? 0;
            $offers[$i]['impressions_total'] = $counters[$id]['total'] ?? 0;
        }

        return $offers;
    }

    /**
     * @param list<int> $offerIds
     * @return array<int, list<array<string,mixed>>>
     */
    public function targetingRulesByOffer(array $offerIds): array
    {
        if ($offerIds === []) {
            return [];
        }
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT * FROM t_offer_targeting WHERE offer_targeting_id_offer IN (:ids)',
            ['ids' => $offerIds],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $byOffer = [];
        foreach ($rows as $row) {
            $byOffer[(int) $row['offer_targeting_id_offer']][] = $row;
        }
        return $byOffer;
    }

    /**
     * Impressions du jour et cumulees, par offre.
     *
     * Le jour se compte sur l'evenementiel (le rollup tourne a l'heure, il est
     * donc toujours en retard sur la journee en cours, et un plafond calcule
     * sur des donnees d'il y a une heure ne plafonne rien). Le cumul se lit sur
     * l'agregat, plus les evenements du jour.
     *
     * @param list<int> $offerIds
     * @return array<int, array{today:int, total:int}>
     */
    public function impressionCounters(array $offerIds): array
    {
        if ($offerIds === []) {
            return [];
        }

        $today = $this->connection()->fetchAllAssociative(
            'SELECT offer_event_id_offer AS offer_id, COUNT(*) AS nb
               FROM t_offer_event
              WHERE offer_event_id_offer IN (:ids)
                AND offer_event_action = :action
                AND created_day = CURDATE()
              GROUP BY offer_event_id_offer',
            ['ids' => $offerIds, 'action' => 'impression'],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $past = $this->connection()->fetchAllAssociative(
            'SELECT offer_stats_daily_id_offer AS offer_id, SUM(offer_stats_daily_nb) AS nb
               FROM t_offer_stats_daily
              WHERE offer_stats_daily_id_offer IN (:ids)
                AND offer_stats_daily_action = :action
                AND offer_stats_daily_date < CURDATE()
              GROUP BY offer_stats_daily_id_offer',
            ['ids' => $offerIds, 'action' => 'impression'],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $counters = [];
        foreach ($offerIds as $id) {
            $counters[$id] = ['today' => 0, 'total' => 0];
        }
        foreach ($today as $row) {
            $counters[(int) $row['offer_id']]['today'] = (int) $row['nb'];
        }
        foreach ($past as $row) {
            $counters[(int) $row['offer_id']]['total'] = (int) $row['nb'];
        }
        foreach ($counters as $id => $counter) {
            $counters[$id]['total'] = $counter['total'] + $counter['today'];
        }

        return $counters;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $offerId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT * FROM t_offer WHERE offer_id = :id LIMIT 1',
            ['id' => $offerId]
        );
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function findBlocks(int $sweepstakeId): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT * FROM t_offer_block
              WHERE offer_block_id_sweepstake = :id
              ORDER BY offer_block_position ASC, offer_block_id ASC',
            ['id' => $sweepstakeId]
        );
    }
}
