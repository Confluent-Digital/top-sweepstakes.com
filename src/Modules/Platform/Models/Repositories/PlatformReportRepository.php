<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models\Repositories;

use App\Core\Database;

final class PlatformReportRepository
{
    public function __construct(private Database $database)
    {
    }

    /**
     * Ecrit les lignes du flux, en upsert sur (date, idc, ids, sid).
     *
     * Rejouer une journee doit donner exactement le meme resultat : la regie
     * revise ses chiffres pendant plusieurs jours (validations, annulations),
     * et le rattrapage est le mode de fonctionnement normal, pas l'exception.
     *
     * @param list<array<string,mixed>> $rows
     */
    public function upsertMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $columns = array_keys($rows[0]);
        $updatable = array_filter(
            $columns,
            static fn(string $c): bool => !in_array($c, [
                'platform_report_date',
                'platform_report_idc',
                'platform_report_ids',
                'platform_report_sid',
            ], true)
        );

        $connection = $this->database->connection();
        $written = 0;

        // Par paquets : une journee peut compter plusieurs milliers de lignes,
        // et une requete unique depasserait max_allowed_packet.
        foreach (array_chunk($rows, 200) as $chunk) {
            $placeholders = [];
            $values = [];
            foreach ($chunk as $i => $row) {
                $slots = [];
                foreach ($columns as $column) {
                    $key = $column . '_' . $i;
                    $slots[] = ':' . $key;
                    $values[$key] = $row[$column] ?? null;
                }
                $placeholders[] = '(' . implode(', ', $slots) . ')';
            }

            $updates = array_map(
                static fn(string $c): string => sprintf('%s = VALUES(%s)', $c, $c),
                $updatable
            );

            $sql = 'INSERT INTO t_platform_report (' . implode(', ', $columns) . ') VALUES '
                . implode(', ', $placeholders)
                . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);

            $written += (int) $connection->executeStatement($sql, $values);
        }

        return $written;
    }

    /**
     * Revenus d'une journee, agreges par campagne (idc) et par sid.
     *
     * C'est `idc` qui rapproche une ligne du flux d'une de nos offres
     * (`offer_platform_idc`) ; le `sid` fournit le concours et la source.
     *
     * @return list<array<string,mixed>>
     */
    public function revenueByDay(string $date): array
    {
        return $this->database->connection()->fetchAllAssociative(
            'SELECT platform_report_idc AS idc,
                    platform_report_sid AS sid,
                    SUM(platform_report_gains_valid)   AS gains_valid,
                    SUM(platform_report_gains_pending) AS gains_pending,
                    SUM(platform_report_clicks)        AS clicks
               FROM t_platform_report
              WHERE platform_report_date = :date
              GROUP BY platform_report_idc, platform_report_sid',
            ['date' => $date]
        );
    }
}
