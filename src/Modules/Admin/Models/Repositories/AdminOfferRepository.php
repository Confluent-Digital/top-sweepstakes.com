<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models\Repositories;

use App\Core\Database;
use Doctrine\DBAL\Connection;

final class AdminOfferRepository
{
    public function __construct(private Database $database)
    {
    }

    private function connection(): Connection
    {
        return $this->database->connection();
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT o.*,
                    (SELECT COALESCE(SUM(s.offer_stats_daily_nb), 0)
                       FROM t_offer_stats_daily s
                      WHERE s.offer_stats_daily_id_offer = o.offer_id
                        AND s.offer_stats_daily_action = "impression") AS impressions,
                    (SELECT COALESCE(SUM(s.offer_stats_daily_nb), 0)
                       FROM t_offer_stats_daily s
                      WHERE s.offer_stats_daily_id_offer = o.offer_id
                        AND s.offer_stats_daily_action = "click") AS clicks
               FROM t_offer o
              ORDER BY o.offer_active DESC, o.offer_ecpm_15d DESC, o.offer_id DESC'
        );
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $this->connection()->insert('t_offer', $data);
        return (int) $this->connection()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->connection()->update('t_offer', $data, ['offer_id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public function targetingRules(int $offerId): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT * FROM t_offer_targeting WHERE offer_targeting_id_offer = :id ORDER BY offer_targeting_id',
            ['id' => $offerId]
        );
    }

    /** @param list<array{param:string, operator:string, value:string}> $rules */
    public function replaceTargetingRules(int $offerId, array $rules): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $connection->delete('t_offer_targeting', ['offer_targeting_id_offer' => $offerId]);
            foreach ($rules as $rule) {
                if ($rule['param'] === '' || $rule['operator'] === '') {
                    continue;
                }
                $connection->insert('t_offer_targeting', [
                    'offer_targeting_id_offer' => $offerId,
                    'offer_targeting_param' => $rule['param'],
                    'offer_targeting_operator' => $rule['operator'],
                    'offer_targeting_value' => mb_substr($rule['value'], 0, 1000),
                ]);
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }
}
