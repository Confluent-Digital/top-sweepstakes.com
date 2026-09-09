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

    /** Performance par source d'acquisition. */
    public function sources(Request $request, Response $response): Response
    {
        [$from, $to] = $this->period($request);
        $connection = $this->database->connection();

        $rows = $connection->fetchAllAssociative(
            'SELECT COALESCE(NULLIF(r.offer_revenue_daily_subid, ""), "(direct)") AS subid,
                    SUM(r.offer_revenue_daily_impressions) AS impressions,
                    SUM(r.offer_revenue_daily_clicks)      AS clicks,
                    SUM(r.offer_revenue_daily_revenue)     AS revenue
               FROM t_offer_revenue_daily r
              WHERE r.offer_revenue_daily_date BETWEEN :from AND :to
              GROUP BY subid
              ORDER BY revenue DESC',
            ['from' => $from, 'to' => $to]
        );

        // Participations par source, pour rapporter le revenu au volume :
        // c'est le revenu par participation qui dit si une source est rentable,
        // pas le revenu brut.
        $entries = $connection->fetchAllAssociative(
            'SELECT COALESCE(NULLIF(lead_subid, ""), "(direct)") AS subid, COUNT(*) AS entries
               FROM t_lead
              WHERE lead_status = "complete" AND DATE(created_at) BETWEEN :from AND :to
              GROUP BY subid',
            ['from' => $from, 'to' => $to]
        );

        $bySubid = [];
        foreach ($entries as $row) {
            $bySubid[(string) $row['subid']] = (int) $row['entries'];
        }
        foreach ($rows as $i => $row) {
            $count = $bySubid[(string) $row['subid']] ?? 0;
            $rows[$i]['entries'] = $count;
            $rows[$i]['revenue_per_entry'] = $count > 0
                ? round((float) $row['revenue'] / $count, 4)
                : null;
        }

        return $this->view->render($response, 'admin/stats/sources.html.twig', [
            'rows' => $rows,
            'from' => $from,
            'to' => $to,
            'totals' => $this->totals($rows),
        ]);
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
