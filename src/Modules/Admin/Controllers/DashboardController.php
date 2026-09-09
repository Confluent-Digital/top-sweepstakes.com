<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class DashboardController
{
    public function __construct(
        private Twig $view,
        private Database $database,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $connection = $this->database->connection();

        $entriesToday = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM t_lead WHERE DATE(created_at) = CURDATE() AND lead_status = "complete"'
        );
        $entries30d = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM t_lead
              WHERE created_at >= (NOW() - INTERVAL 30 DAY) AND lead_status = "complete"'
        );
        $impressionsToday = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM t_offer_event
              WHERE created_day = CURDATE() AND offer_event_action = "impression"'
        );
        $clicksToday = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM t_offer_event
              WHERE created_day = CURDATE() AND offer_event_action = "click"'
        );

        $byDay = $connection->fetchAllAssociative(
            'SELECT DATE(created_at) AS jour, COUNT(*) AS nb
               FROM t_lead
              WHERE created_at >= (NOW() - INTERVAL 30 DAY) AND lead_status = "complete"
              GROUP BY DATE(created_at)
              ORDER BY jour ASC'
        );

        $bySource = $connection->fetchAllAssociative(
            'SELECT COALESCE(NULLIF(lead_subid, ""), "(direct)") AS source, COUNT(*) AS nb
               FROM t_lead
              WHERE created_at >= (NOW() - INTERVAL 30 DAY) AND lead_status = "complete"
              GROUP BY source
              ORDER BY nb DESC
              LIMIT 15'
        );

        return $this->view->render($response, 'admin/dashboard.html.twig', [
            'entries_today' => $entriesToday,
            'entries_30d' => $entries30d,
            'impressions_today' => $impressionsToday,
            'clicks_today' => $clicksToday,
            // « Vide » ne se lit pas « zero » : le gabarit dit explicitement
            // quand il n'y a pas encore de donnee.
            'ctr_today' => $impressionsToday > 0
                ? round($clicksToday / $impressionsToday * 100, 2)
                : null,
            'by_day' => $byDay,
            'by_source' => $bySource,
        ]);
    }
}
