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

    /** @return list<array<string,mixed>> */
    public function listPublished(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT * FROM t_sweepstake
              WHERE sweepstake_status = :status
                AND (sweepstake_date_start IS NULL OR sweepstake_date_start <= CURDATE())
                AND (sweepstake_date_end IS NULL OR sweepstake_date_end >= CURDATE())
              ORDER BY sweepstake_prize_value_usd DESC, sweepstake_name ASC',
            ['status' => 'published']
        );
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
