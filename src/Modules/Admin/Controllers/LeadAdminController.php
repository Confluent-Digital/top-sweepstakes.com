<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Models\Repositories\AdminLeadRepository;
use App\Modules\Admin\Models\Repositories\AdminSweepstakeRepository;
use App\Modules\Admin\Models\Repositories\AdminUserRepository;
use App\Modules\Leads\Models\Repositories\ConsentRepository;
use App\Modules\Leads\Models\Repositories\LeadRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

final class LeadAdminController
{
    /** Colonnes de l'export. */
    private const EXPORT_COLUMNS = [
        'lead_id', 'lead_uniqid', 'created_at', 'lead_email', 'lead_first_name', 'lead_last_name',
        'lead_address', 'lead_city', 'lead_state', 'lead_zip', 'lead_phone', 'lead_dob', 'lead_gender',
        'lead_id_sweepstake', 'lead_source', 'lead_subid', 'lead_utm_source', 'lead_utm_medium',
        'lead_utm_campaign', 'lead_device', 'lead_ip', 'lead_status',
    ];

    public function __construct(
        private Twig $view,
        private AdminLeadRepository $admin,
        private AdminSweepstakeRepository $sweepstakes,
        private LeadRepository $leads,
        private ConsentRepository $consents,
        private AdminUserRepository $users,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $filters = $this->filters($query);
        $page = max(1, (int) ($query['page'] ?? 1));

        $result = $this->admin->search($filters, $page);
        $pageSize = $this->admin->pageSize();

        return $this->view->render($response, 'admin/leads/index.html.twig', [
            'leads' => $result['rows'],
            // Le total porte sur le jeu FILTRE, pas sur la page affichee.
            'total' => $result['total'],
            'page' => $page,
            'pages' => (int) ceil($result['total'] / $pageSize),
            'filters' => $filters,
            'sweepstakes' => $this->sweepstakes->all(),
        ]);
    }

    /** @param array<string,string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $lead = $this->leads->findById((int) $args['id']);
        if ($lead === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->view->render($response, 'admin/leads/show.html.twig', [
            'lead' => $lead,
            // La fiche montre la preuve de consentement telle qu'elle a ete
            // archivee : c'est elle qu'on produirait en cas de contestation.
            'consents' => $this->consents->findForLead((int) $lead['lead_id']),
        ]);
    }

    /**
     * Export CSV, streame par lots : la table des participants grossit vite et
     * un export en memoire finirait par depasser la limite du processus.
     */
    public function export(Request $request, Response $response): Response
    {
        $filters = $this->filters($request->getQueryParams());
        $this->log($request, 'lead.export', json_encode($filters) ?: '');

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Flux d\'export indisponible.');
        }

        fputcsv($stream, self::EXPORT_COLUMNS);
        foreach ($this->admin->exportBatches($filters) as $batch) {
            foreach ($batch as $row) {
                $line = [];
                foreach (self::EXPORT_COLUMNS as $column) {
                    $line[] = (string) ($row[$column] ?? '');
                }
                fputcsv($stream, $line);
            }
        }
        rewind($stream);

        $response->getBody()->write((string) stream_get_contents($stream));
        fclose($stream);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="entries-' . date('Ymd-His') . '.csv"'
            );
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,string>
     */
    private function filters(array $query): array
    {
        return [
            'sweepstake_id' => trim((string) ($query['sweepstake_id'] ?? '')),
            'email' => trim((string) ($query['email'] ?? '')),
            'state' => trim((string) ($query['state'] ?? '')),
            'subid' => trim((string) ($query['subid'] ?? '')),
            'from' => $this->date($query['from'] ?? null),
            'to' => $this->date($query['to'] ?? null),
        ];
    }

    private function date(mixed $value): string
    {
        $value = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    private function log(Request $request, string $action, string $detail): void
    {
        $user = $request->getAttribute('admin_user');
        $this->users->log(
            is_array($user) ? (int) $user['admin_user_id'] : null,
            $action,
            '',
            $detail,
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '')
        );
    }
}
