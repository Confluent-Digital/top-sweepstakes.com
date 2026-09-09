<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models\Repositories;

use App\Core\Database;
use App\Modules\Leads\Services\LeadValidator;
use Doctrine\DBAL\Connection;

/**
 * Ecriture des concours depuis le back-office.
 *
 * La duplication est ici le geste central : c'est ce qui permet de lancer un
 * concours en quelques minutes, sans toucher au code. Elle copie la fiche, ses
 * champs et le rattachement de ses offres.
 */
final class AdminSweepstakeRepository
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
            'SELECT s.*,
                    (SELECT COUNT(*) FROM t_lead l
                      WHERE l.lead_id_sweepstake = s.sweepstake_id
                        AND l.lead_status = "complete") AS entries
               FROM t_sweepstake s
              ORDER BY s.sweepstake_status ASC, s.sweepstake_id DESC'
        );
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $this->connection()->insert('t_sweepstake', $data);
        return (int) $this->connection()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->connection()->update('t_sweepstake', $data, ['sweepstake_id' => $id]);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM t_sweepstake WHERE sweepstake_slug = :slug';
        $params = ['slug' => $slug];
        if ($exceptId !== null) {
            $sql .= ' AND sweepstake_id != :id';
            $params['id'] = $exceptId;
        }
        return $this->connection()->fetchOne($sql . ' LIMIT 1', $params) !== false;
    }

    /**
     * Remplace la definition des champs d'un concours.
     *
     * @param list<array{key:string, step:int, position:int, required:bool}> $fields
     */
    public function replaceFields(int $sweepstakeId, array $fields): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $connection->delete('t_sweepstake_field', ['sweepstake_field_id_sweepstake' => $sweepstakeId]);
            foreach ($fields as $field) {
                if (!in_array($field['key'], LeadValidator::FIELDS, true)) {
                    continue;
                }
                $connection->insert('t_sweepstake_field', [
                    'sweepstake_field_id_sweepstake' => $sweepstakeId,
                    'sweepstake_field_key' => $field['key'],
                    'sweepstake_field_step' => $field['step'],
                    'sweepstake_field_position' => $field['position'],
                    'sweepstake_field_required' => $field['required'] ? 1 : 0,
                    'sweepstake_field_active' => 1,
                    'sweepstake_field_label' => '',
                ]);
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Duplique un concours : fiche, champs, blocs et rattachement des offres.
     *
     * Le nouveau concours part en `draft` : ses Official Rules, ses dates et sa
     * dotation viennent d'etre copiees d'un autre, elles doivent etre relues
     * avant publication.
     */
    public function duplicate(int $sourceId, string $newSlug, string $newName): int
    {
        $connection = $this->connection();
        $source = $connection->fetchAssociative(
            'SELECT * FROM t_sweepstake WHERE sweepstake_id = :id',
            ['id' => $sourceId]
        );
        if ($source === false) {
            throw new \RuntimeException('Concours source introuvable : ' . $sourceId);
        }

        $connection->beginTransaction();
        try {
            $copy = $source;
            unset($copy['sweepstake_id'], $copy['created_at'], $copy['updated_at']);
            $copy['sweepstake_slug'] = $newSlug;
            $copy['sweepstake_name'] = $newName;
            $copy['sweepstake_status'] = 'draft';

            $connection->insert('t_sweepstake', $copy);
            $newId = (int) $connection->lastInsertId();

            $this->copyFields($sourceId, $newId);
            $blockMap = $this->copyBlocks($sourceId, $newId);
            $this->copyOfferLinks($sourceId, $newId, $blockMap);

            $connection->commit();
            return $newId;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    private function copyFields(int $sourceId, int $newId): void
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT * FROM t_sweepstake_field WHERE sweepstake_field_id_sweepstake = :id',
            ['id' => $sourceId]
        );
        foreach ($rows as $row) {
            unset($row['sweepstake_field_id'], $row['created_at'], $row['updated_at']);
            $row['sweepstake_field_id_sweepstake'] = $newId;
            $this->connection()->insert('t_sweepstake_field', $row);
        }
    }

    /** @return array<int,int> ancien identifiant de bloc => nouveau */
    private function copyBlocks(int $sourceId, int $newId): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT * FROM t_offer_block WHERE offer_block_id_sweepstake = :id',
            ['id' => $sourceId]
        );

        $map = [];
        foreach ($rows as $row) {
            $oldId = (int) $row['offer_block_id'];
            unset($row['offer_block_id'], $row['created_at'], $row['updated_at']);
            $row['offer_block_id_sweepstake'] = $newId;
            $this->connection()->insert('t_offer_block', $row);
            $map[$oldId] = (int) $this->connection()->lastInsertId();
        }
        return $map;
    }

    /** @param array<int,int> $blockMap */
    private function copyOfferLinks(int $sourceId, int $newId, array $blockMap): void
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT * FROM t_sweepstake_offer WHERE sweepstake_offer_id_sweepstake = :id',
            ['id' => $sourceId]
        );

        foreach ($rows as $row) {
            unset($row['sweepstake_offer_id'], $row['created_at'], $row['updated_at']);
            $row['sweepstake_offer_id_sweepstake'] = $newId;
            // Les variantes ne sont pas dupliquees : un rattachement lie a une
            // variante de la source n'aurait plus de sens ici. Il repasse sur
            // « toutes les variantes ».
            $row['sweepstake_offer_id_variant'] = 0;

            $oldBlock = $row['sweepstake_offer_id_block'] ?? null;
            $row['sweepstake_offer_id_block'] = $oldBlock === null
                ? null
                : ($blockMap[(int) $oldBlock] ?? null);

            $this->connection()->insert('t_sweepstake_offer', $row);
        }
    }

    /** @return list<array<string,mixed>> */
    public function attachedOffers(int $sweepstakeId): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT so.*, o.offer_name, o.offer_active, o.offer_platform_idv, o.offer_ecpm_15d
               FROM t_sweepstake_offer so
               JOIN t_offer o ON o.offer_id = so.sweepstake_offer_id_offer
              WHERE so.sweepstake_offer_id_sweepstake = :id
              ORDER BY so.sweepstake_offer_position ASC',
            ['id' => $sweepstakeId]
        );
    }

    /** @param list<int> $offerIds */
    public function replaceAttachedOffers(int $sweepstakeId, array $offerIds, ?int $blockId): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $connection->delete('t_sweepstake_offer', ['sweepstake_offer_id_sweepstake' => $sweepstakeId]);
            foreach (array_values(array_unique($offerIds)) as $position => $offerId) {
                $connection->insert('t_sweepstake_offer', [
                    'sweepstake_offer_id_sweepstake' => $sweepstakeId,
                    'sweepstake_offer_id_variant' => 0,
                    'sweepstake_offer_id_offer' => $offerId,
                    'sweepstake_offer_id_block' => $blockId,
                    'sweepstake_offer_step' => 3,
                    'sweepstake_offer_position' => $position + 1,
                    'sweepstake_offer_active' => 1,
                ]);
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    public function firstBlockId(int $sweepstakeId): ?int
    {
        $id = $this->connection()->fetchOne(
            'SELECT offer_block_id FROM t_offer_block
              WHERE offer_block_id_sweepstake = :id
              ORDER BY offer_block_position ASC LIMIT 1',
            ['id' => $sweepstakeId]
        );
        return $id === false || $id === null ? null : (int) $id;
    }

    public function createDefaultBlock(int $sweepstakeId): int
    {
        $this->connection()->insert('t_offer_block', [
            'offer_block_id_sweepstake' => $sweepstakeId,
            'offer_block_title' => 'Optional offers from our partners',
            'offer_block_layout' => 'grid',
            'offer_block_position' => 1,
            'offer_block_max_offers' => 6,
        ]);
        return (int) $this->connection()->lastInsertId();
    }
}
