<?php

declare(strict_types=1);

namespace App\Modules\Stats\Controllers;

use App\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Ecrans de reporting.
 *
 * Ils lisent les agregats (`t_offer_stats_daily`, `t_offer_revenue_daily`) et
 * jamais l'evenementiel : une page de stats ne doit pas scanner des millions
 * de lignes a chaque affichage.
 */
final class StatsController
{
    public function __construct(
        private Twig $view,
        private Database $database,
    ) {
    }

    /** Performance par offre sur la periode. */
    public function offers(Request $request, Response $response): Response
    {
        [$from, $to] = $this->period($request);

        $rows = $this->database->connection()->fetchAllAssociative(
            'SELECT o.offer_id, o.offer_name, o.offer_advertiser, o.offer_active,
                    o.offer_ecpm, o.offer_ecpm_5d, o.offer_ecpm_15d,
                    COALESCE(SUM(r.offer_revenue_daily_impressions), 0) AS impressions,
                    COALESCE(SUM(r.offer_revenue_daily_clicks), 0)      AS clicks,
                    COALESCE(SUM(r.offer_revenue_daily_revenue), 0)     AS revenue
               FROM t_offer o
               LEFT JOIN t_offer_revenue_daily r
                      ON r.offer_revenue_daily_id_offer = o.offer_id
                     AND r.offer_revenue_daily_date BETWEEN :from AND :to
              GROUP BY o.offer_id, o.offer_name, o.offer_advertiser, o.offer_active,
                       o.offer_ecpm, o.offer_ecpm_5d, o.offer_ecpm_15d
              ORDER BY revenue DESC, impressions DESC',
            ['from' => $from, 'to' => $to]
        );

        return $this->view->render($response, 'admin/stats/offers.html.twig', [
            'rows' => $rows,
            'from' => $from,
            'to' => $to,
            'totals' => $this->totals($rows),
        ]);
    }

    /**
     * Performance par source d'acquisition.
     *
     * L'ecran part des **participations** et non des revenus. C'est
     * volontaire : une source qui apporte du volume sans rien rapporter
     * n'apparaitrait pas si l'on partait de `t_offer_revenue_daily`, alors que
     * c'est exactement celle qu'il faut voir — elle coute son cout
     * d'acquisition et ne le rembourse pas.
     */
    public function sources(Request $request, Response $response): Response
    {
        [$from, $to] = $this->period($request);
        $connection = $this->database->connection();

        // Participations et qualite des numeros, par source.
        //
        // Le telephone est obligatoire : son taux de collecte vaut toujours
        // 100 % et n'apprend rien. Ce qui distingue une source d'une autre,
        // c'est la part de numeros assortis d'un consentement TCPA, donc
        // reellement demarchables.
        $entries = $connection->fetchAllAssociative(
            'SELECT COALESCE(NULLIF(l.lead_subid, ""), "(direct)") AS subid,
                    COUNT(DISTINCT l.lead_id) AS entries,
                    COUNT(DISTINCT IF(l.lead_phone != "", l.lead_id, NULL)) AS phones,
                    COUNT(DISTINCT IF(c.lead_consent_granted = 1, l.lead_id, NULL)) AS tcpa_granted
               FROM t_lead l
               LEFT JOIN t_lead_consent c
                      ON c.lead_consent_id_lead = l.lead_id
                     AND c.lead_consent_type = :type
              WHERE l.lead_status = :status
                AND DATE(l.created_at) BETWEEN :from AND :to
              GROUP BY subid',
            ['type' => 'tcpa_phone', 'status' => 'complete', 'from' => $from, 'to' => $to]
        );

        $revenue = $connection->fetchAllAssociative(
            'SELECT COALESCE(NULLIF(offer_revenue_daily_subid, ""), "(direct)") AS subid,
                    SUM(offer_revenue_daily_impressions) AS impressions,
                    SUM(offer_revenue_daily_clicks)      AS clicks,
                    SUM(offer_revenue_daily_revenue)     AS revenue
               FROM t_offer_revenue_daily
              WHERE offer_revenue_daily_date BETWEEN :from AND :to
              GROUP BY subid',
            ['from' => $from, 'to' => $to]
        );

        $rows = $this->mergeSources($entries, $revenue);

        // Le revenu decroissant en tete, mais les sources sans revenu restent
        // dans le tableau : ce sont elles qui posent question.
        usort($rows, static fn(array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return $this->view->render($response, 'admin/stats/sources.html.twig', [
            'rows' => $rows,
            'from' => $from,
            'to' => $to,
            'totals' => $this->totals($rows),
        ]);
    }

    /**
     * Fusionne participations et revenus sur l'ensemble des sources connues de
     * l'une ou l'autre origine.
     *
     * @param list<array<string,mixed>> $entries
     * @param list<array<string,mixed>> $revenue
     * @return list<array<string,mixed>>
     */
    private function mergeSources(array $entries, array $revenue): array
    {
        $rows = [];

        foreach ($entries as $row) {
            $subid = (string) $row['subid'];
            $phones = (int) $row['phones'];
            $granted = (int) $row['tcpa_granted'];
            $rows[$subid] = [
                'subid' => $subid,
                'entries' => (int) $row['entries'],
                'phones' => $phones,
                'tcpa_granted' => $granted,
                'tcpa_rate' => $phones > 0 ? round($granted / $phones * 100, 1) : null,
                'impressions' => 0,
                'clicks' => 0,
                'revenue' => 0.0,
            ];
        }

        foreach ($revenue as $row) {
            $subid = (string) $row['subid'];
            // Revenu sans participation sur la periode : la participation date
            // d'avant la fenetre, mais la regie a paye dedans. La source doit
            // apparaitre quand meme.
            $rows[$subid] ??= [
                'subid' => $subid,
                'entries' => 0,
                'phones' => 0,
                'tcpa_granted' => 0,
                'tcpa_rate' => null,
                'impressions' => 0,
                'clicks' => 0,
                'revenue' => 0.0,
            ];
            $rows[$subid]['impressions'] = (int) $row['impressions'];
            $rows[$subid]['clicks'] = (int) $row['clicks'];
            $rows[$subid]['revenue'] = (float) $row['revenue'];
        }

        foreach ($rows as $subid => $row) {
            $rows[$subid]['revenue_per_entry'] = $row['entries'] > 0
                ? round($row['revenue'] / $row['entries'], 4)
                : null;
        }

        return array_values($rows);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,float|int|null>
     */
    private function totals(array $rows): array
    {
        $impressions = 0;
        $clicks = 0;
        $revenue = 0.0;
        foreach ($rows as $row) {
            $impressions += (int) $row['impressions'];
            $clicks += (int) $row['clicks'];
            $revenue += (float) $row['revenue'];
        }

        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'revenue' => $revenue,
            // Sans impression il n'y a ni taux ni eCPM : null, et le gabarit
            // l'affiche comme tel plutot qu'en zero.
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : null,
            'ecpm' => $impressions > 0 ? round($revenue / $impressions * 1000, 4) : null,
        ];
    }

    /** @return array{string, string} */
    private function period(Request $request): array
    {
        $query = $request->getQueryParams();
        $from = $this->date($query['from'] ?? null) ?? date('Y-m-d', strtotime('-29 days'));
        $to = $this->date($query['to'] ?? null) ?? date('Y-m-d');
        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
