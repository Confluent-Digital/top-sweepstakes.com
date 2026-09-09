<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models\Repositories;

use App\Core\Database;

final class AdminLeadRepository
{
    private const PAGE_SIZE = 100;

    public function __construct(private Database $database)
    {
    }

    /**
     * Participants, filtres et pagines cote serveur.
     *
     * La pagination n'est pas un confort : cette table grossit vite, et charger
     * l'integralite dans le navigateur casserait l'ecran des le premier mois de
     * trafic.
     *
     * @param array<string,mixed> $filters
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public function search(array $filters, int $page = 1): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $total = (int) $this->database->connection()->fetchOne(
            'SELECT COUNT(*) FROM t_lead l ' . $where,
            $params
        );

        $offset = max(0, ($page - 1) * self::PAGE_SIZE);
        $rows = $this->database->connection()->fetchAllAssociative(
            'SELECT l.*, s.sweepstake_name
               FROM t_lead l
               LEFT JOIN t_sweepstake s ON s.sweepstake_id = l.lead_id_sweepstake '
            . $where
            . ' ORDER BY l.lead_id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset,
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{string, array<string,mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (!empty($filters['sweepstake_id'])) {
            $clauses[] = 'l.lead_id_sweepstake = :sweepstake';
            $params['sweepstake'] = (int) $filters['sweepstake_id'];
        }
        if (!empty($filters['email'])) {
            $clauses[] = 'l.lead_email LIKE :email';
            $params['email'] = '%' . $filters['email'] . '%';
        }
        if (!empty($filters['state'])) {
            $clauses[] = 'l.lead_state = :state';
            $params['state'] = strtoupper((string) $filters['state']);
        }
        if (!empty($filters['subid'])) {
            $clauses[] = 'l.lead_subid = :subid';
            $params['subid'] = $filters['subid'];
        }
        if (!empty($filters['from'])) {
            $clauses[] = 'l.created_at >= :from';
            $params['from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $clauses[] = 'l.created_at <= :to';
            $params['to'] = $filters['to'] . ' 23:59:59';
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * Export CSV. Il streame par lots plutot que de tout charger en memoire.
     *
     * @param array<string,mixed> $filters
     * @return iterable<list<array<string,mixed>>>
     */
    public function exportBatches(array $filters, int $batchSize = 500): iterable
    {
        [$where, $params] = $this->buildWhere($filters);
        $lastId = PHP_INT_MAX;

        while (true) {
            $sql = 'SELECT l.* FROM t_lead l '
                . ($where === '' ? 'WHERE ' : $where . ' AND ')
                . 'l.lead_id < :lastId ORDER BY l.lead_id DESC LIMIT ' . $batchSize;

            $rows = $this->database->connection()->fetchAllAssociative(
                $sql,
                $params + ['lastId' => $lastId]
            );
            if ($rows === []) {
                return;
            }
            yield $rows;
            $lastId = (int) $rows[count($rows) - 1]['lead_id'];
        }
    }

    public function pageSize(): int
    {
        return self::PAGE_SIZE;
    }
}
