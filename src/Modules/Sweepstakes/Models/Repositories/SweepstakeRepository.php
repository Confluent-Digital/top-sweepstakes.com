<?php

declare(strict_types=1);

namespace App\Modules\Sweepstakes\Models\Repositories;

use App\Core\Database;
use Doctrine\DBAL\Connection;

final class SweepstakeRepository
{
    public function __construct(private Database $database)
    {
    }

    private function connection(): Connection
    {
        return $this->database->connection();
    }

    /** @return array<string,mixed>|null */
    public function findPublishedBySlug(string $slug): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT * FROM t_sweepstake
              WHERE sweepstake_slug = :slug
                AND sweepstake_status = :status
              LIMIT 1',
            ['slug' => $slug, 'status' => 'published']
        );
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT * FROM t_sweepstake WHERE sweepstake_id = :id LIMIT 1',
            ['id' => $id]
        );
        return $row === false ? null : $row;
    }

    /**
     * Concours ouverts, avec de quoi les qualifier sur l'accueil.
     *
     * Le badge (nouveau, populaire, bientot fini) est **derive des donnees**
     * et non saisi : un champ « mis en avant » se remplit une fois puis ne se
     * met plus jamais a jour, et finit par mentir.
     *
     * @return list<array<string,mixed>>
     */
    public function listPublished(): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM t_lead l
                      WHERE l.lead_id_sweepstake = s.sweepstake_id
                        AND l.lead_status = "complete"
                        AND l.created_at >= (NOW() - INTERVAL 7 DAY)) AS entries_7d,
                    DATEDIFF(s.sweepstake_date_end, CURDATE()) AS days_left,
                    DATEDIFF(CURDATE(), DATE(s.created_at))    AS days_online
               FROM t_sweepstake s
              WHERE s.sweepstake_status = :status
                AND (s.sweepstake_date_start IS NULL OR s.sweepstake_date_start <= CURDATE())
                AND (s.sweepstake_date_end IS NULL OR s.sweepstake_date_end >= CURDATE())
              ORDER BY s.sweepstake_prize_value_usd DESC, s.sweepstake_name ASC',
            ['status' => 'published']
        );

        $busiest = 0;
        foreach ($rows as $row) {
            $busiest = max($busiest, (int) $row['entries_7d']);
        }

        foreach ($rows as $i => $row) {
            $rows[$i]['badge'] = $this->badgeFor($row, $busiest);
        }

        return $rows;
    }

    /**
     * Un badge, ou aucun.
     *
     * L'urgence passe avant la nouveaute : un concours qui se termine dans
     * trois jours merite plus l'attention qu'un concours ouvert hier. Et on
     * n'annonce « bientot fini » qu'a sept jours ou moins — « 87 jours
     * restants » invite a revenir plus tard, c'est-a-dire a ne pas participer.
     *
     * @param array<string,mixed> $row
     */
    private function badgeFor(array $row, int $busiest): ?string
    {
        $daysLeft = $row['days_left'];
        if ($daysLeft !== null && (int) $daysLeft >= 0 && (int) $daysLeft <= 7) {
            return 'ending';
        }

        // « Populaire » n'a de sens que compare aux autres, et seulement s'il y
        // a de quoi comparer : sur cinq participations, le premier n'est pas
        // populaire, il est juste premier.
        if ($busiest >= 20 && (int) $row['entries_7d'] >= $busiest) {
            return 'popular';
        }

        if ($row['days_online'] !== null && (int) $row['days_online'] <= 14) {
            return 'new';
        }

        return null;
    }

    /**
     * Champs du formulaire pour une etape. C'est ce qui remplace les gabarits
     * dupliques par concours : la structure du formulaire est une donnee.
     *
     * @return list<array<string,mixed>>
     */
    public function findFields(int $sweepstakeId, ?int $step = null): array
    {
        $sql = 'SELECT * FROM t_sweepstake_field
                 WHERE sweepstake_field_id_sweepstake = :id
                   AND sweepstake_field_active = 1';
        $params = ['id' => $sweepstakeId];

        if ($step !== null) {
            $sql .= ' AND sweepstake_field_step = :step';
            $params['step'] = $step;
        }
        $sql .= ' ORDER BY sweepstake_field_step ASC, sweepstake_field_position ASC';

        return $this->connection()->fetchAllAssociative($sql, $params);
    }

    /**
     * Variantes actives pour ce concours et ce type d'appareil.
     *
     * @return list<array<string,mixed>>
     */
    public function findActiveVariants(int $sweepstakeId, string $device): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT * FROM t_sweepstake_variant
              WHERE sweepstake_variant_id_sweepstake = :id
                AND sweepstake_variant_active = 1
                AND sweepstake_variant_device IN (:all, :device)
              ORDER BY sweepstake_variant_id ASC',
            ['id' => $sweepstakeId, 'all' => 'all', 'device' => $device]
        );
    }

    /**
     * Un concours est ouvert s'il est publie et dans sa fenetre de dates.
     *
     * @param array<string,mixed> $sweepstake
     */
    public function isOpen(array $sweepstake, ?string $today = null): bool
    {
        if (($sweepstake['sweepstake_status'] ?? '') !== 'published') {
            return false;
        }
        $today ??= date('Y-m-d');

        $start = $sweepstake['sweepstake_date_start'] ?? null;
        $end = $sweepstake['sweepstake_date_end'] ?? null;

        if (is_string($start) && $start !== '' && $today < substr($start, 0, 10)) {
            return false;
        }
        if (is_string($end) && $end !== '' && $today > substr($end, 0, 10)) {
            return false;
        }
        return true;
    }
}
