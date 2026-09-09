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

        // Le telephone est obligatoire, le consentement TCPA ne l'est pas : un
        // numero sans ce consentement n'est pas demarchable et ne vaut que pour
        // le dedoublonnage. Le taux de collecte du numero est donc toujours de
        // 100 % et n'apprend rien ; c'est ce taux-ci qui dit combien de numeros
        // sont reellement exploitables.
        $tcpa = $connection->fetchAssociative(
            'SELECT COUNT(DISTINCT l.lead_id) AS total,
                    COUNT(DISTINCT IF(c.lead_consent_granted = 1, l.lead_id, NULL)) AS granted
               FROM t_lead l
               LEFT JOIN t_lead_consent c
                      ON c.lead_consent_id_lead = l.lead_id
                     AND c.lead_consent_type = :type
              WHERE l.lead_status = :status
                AND l.created_at >= (NOW() - INTERVAL 30 DAY)
                AND l.lead_phone != :empty',
            ['type' => 'tcpa_phone', 'status' => 'complete', 'empty' => '']
        );
        $tcpaTotal = (int) ($tcpa['total'] ?? 0);
        $tcpaGranted = (int) ($tcpa['granted'] ?? 0);

        // Ce qui bloque une mise en ligne se decide ici, pas en ouvrant les
        // fiches une par une : sans idv une offre n'est jamais affichee, sans
        // idc son revenu n'est rattache a rien, et un concours publie sans
        // Official Rules est une non-conformite ouverte.
        $blockers = [
            'offers_no_idv' => (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM t_offer WHERE offer_active = 1 AND offer_platform_idv = ""'
            ),
            'offers_no_idc' => (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM t_offer WHERE offer_active = 1 AND offer_platform_idc = ""'
            ),
            'sweepstakes_no_rules' => (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM t_sweepstake
                  WHERE sweepstake_status = "published"
                    AND COALESCE(sweepstake_official_rules_html, "") = ""'
            ),
        ];

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
            'tcpa_total' => $tcpaTotal,
            'tcpa_granted' => $tcpaGranted,
            'tcpa_rate' => $tcpaTotal > 0 ? round($tcpaGranted / $tcpaTotal * 100, 1) : null,
            'blockers' => $blockers,
            'by_day' => $byDay,
            'by_source' => $bySource,
        ]);
    }
}
