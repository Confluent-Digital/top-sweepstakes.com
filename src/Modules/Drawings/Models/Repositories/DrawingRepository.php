<?php

declare(strict_types=1);

namespace App\Modules\Drawings\Models\Repositories;

use App\Core\Database;
use Doctrine\DBAL\Connection;

final class DrawingRepository
{
    public function __construct(private Database $database)
    {
    }

    private function connection(): Connection
    {
        return $this->database->connection();
    }

    /**
     * Participants eligibles au tirage d'un concours.
     *
     * Ordonnes par identifiant : l'ordre doit etre **stable et reproductible**,
     * sinon rejouer le tirage sur la meme liste donnerait un autre gagnant.
     * Trier par date de creation ne suffirait pas — deux participations de la
     * meme seconde n'auraient pas d'ordre garanti.
     *
     * Les participants anonymises sont exclus : on ne peut ni les contacter ni
     * verifier leur eligibilite.
     *
     * @return list<int>
     */
    public function eligibleForSweepstake(int $sweepstakeId): array
    {
        return array_map('intval', $this->connection()->fetchFirstColumn(
            'SELECT lead_id FROM t_lead
              WHERE lead_id_sweepstake = :id
                AND lead_status = :status
                AND lead_test = 0
                AND lead_anonymized_at IS NULL
              ORDER BY lead_id ASC',
            ['id' => $sweepstakeId, 'status' => 'complete']
        ));
    }

    /**
     * Finalistes de l'annee : les gagnants de chaque concours, qui concourent
     * pour la dotation.
     *
     * On ne retient que le rang 1 — un suppleant n'a gagne son concours que si
     * le premier s'est desiste, auquel cas son statut le dit.
     *
     * @return list<int>
     */
    public function finalistsForYear(int $year): array
    {
        return array_map('intval', $this->connection()->fetchFirstColumn(
            'SELECT w.drawing_winner_id_lead
               FROM t_drawing_winner w
               JOIN t_drawing d ON d.drawing_id = w.drawing_winner_id_drawing
               JOIN t_lead l ON l.lead_id = w.drawing_winner_id_lead
              WHERE d.drawing_type = :type
                AND YEAR(d.drawing_executed_at) = :year
                AND w.drawing_winner_rank = 1
                AND w.drawing_winner_status IN (:pending, :notified, :confirmed)
                AND l.lead_anonymized_at IS NULL
              ORDER BY w.drawing_winner_id_lead ASC',
            [
                'type' => 'sweepstake',
                'year' => $year,
                'pending' => 'pending',
                'notified' => 'notified',
                'confirmed' => 'confirmed',
            ]
        ));
    }

    /** @param array<string,mixed> $drawing */
    public function create(array $drawing): int
    {
        $this->connection()->insert('t_drawing', $drawing);
        return (int) $this->connection()->lastInsertId();
    }

    /**
     * Enregistre les gagnants et **retient les participants concernes**.
     *
     * Le drapeau `lead_drawing_hold` les exclut de la purge automatique : un
     * gagnant anonymise a 36 mois ne pourrait plus etre contacte, et la preuve
     * du tirage perdrait son objet.
     *
     * @param list<int> $leadIds dans l'ordre tire
     */
    public function recordWinners(int $drawingId, array $leadIds): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            foreach ($leadIds as $index => $leadId) {
                $connection->insert('t_drawing_winner', [
                    'drawing_winner_id_drawing' => $drawingId,
                    'drawing_winner_id_lead' => $leadId,
                    'drawing_winner_rank' => $index + 1,
                    'drawing_winner_status' => 'pending',
                ]);
                $connection->update('t_lead', ['lead_drawing_hold' => 1], ['lead_id' => $leadId]);
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    public function findForSweepstake(int $sweepstakeId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT * FROM t_drawing
              WHERE drawing_type = :type AND drawing_id_sweepstake = :id
              LIMIT 1',
            ['type' => 'sweepstake', 'id' => $sweepstakeId]
        );
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findGrandPrize(int $year): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT * FROM t_drawing
              WHERE drawing_type = :type AND drawing_year = :year
              LIMIT 1',
            ['type' => 'grand_prize', 'year' => $year]
        );
        return $row === false ? null : $row;
    }

    /**
     * Concours clos dont le tirage n'a pas encore eu lieu.
     *
     * @return list<array<string,mixed>>
     */
    public function sweepstakesAwaitingDrawing(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT s.sweepstake_id, s.sweepstake_slug, s.sweepstake_name, s.sweepstake_date_end
               FROM t_sweepstake s
               LEFT JOIN t_drawing d
                      ON d.drawing_id_sweepstake = s.sweepstake_id AND d.drawing_type = :type
              WHERE s.sweepstake_date_end IS NOT NULL
                AND s.sweepstake_date_end < CURDATE()
                AND d.drawing_id IS NULL
              ORDER BY s.sweepstake_date_end ASC',
            ['type' => 'sweepstake']
        );
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT d.*, s.sweepstake_name, s.sweepstake_slug,
                    (SELECT COUNT(*) FROM t_drawing_winner w
                      WHERE w.drawing_winner_id_drawing = d.drawing_id) AS winners
               FROM t_drawing d
               LEFT JOIN t_sweepstake s ON s.sweepstake_id = d.drawing_id_sweepstake
              ORDER BY d.drawing_executed_at DESC'
        );
    }

    /** @return list<array<string,mixed>> */
    public function winners(int $drawingId): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT w.*, l.lead_email, l.lead_first_name, l.lead_last_name,
                    l.lead_state, l.lead_anonymized_at
               FROM t_drawing_winner w
               JOIN t_lead l ON l.lead_id = w.drawing_winner_id_lead
              WHERE w.drawing_winner_id_drawing = :id
              ORDER BY w.drawing_winner_rank ASC',
            ['id' => $drawingId]
        );
    }

    public function updateWinnerStatus(int $winnerId, string $status): void
    {
        $data = ['drawing_winner_status' => $status];
        if ($status === 'notified') {
            $data['drawing_winner_notified_at'] = date('Y-m-d H:i:s');
        }
        if ($status === 'confirmed') {
            $data['drawing_winner_confirmed_at'] = date('Y-m-d H:i:s');
        }

        $this->connection()->update('t_drawing_winner', $data, ['drawing_winner_id' => $winnerId]);
    }
}
