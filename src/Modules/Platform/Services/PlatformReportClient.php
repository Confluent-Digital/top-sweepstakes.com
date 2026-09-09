<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Core\Config;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

/**
 * Lecture du flux de reporting de la regie d'affiliation.
 *
 * Contrat du flux :
 *
 *   GET {AFFILIATE_REPORT_URL}?login=&pass=&flux=xml&stat=global|cpx
 *                             &debut=YYYY-MM-DD&fin=YYYY-MM-DD&ids=
 *   -> <campagne> : nom, idc, ids, sid, affichage, clic, dbclic,
 *                   cpl_valide, cpl_attente, cpa_valide, cpa_attente,
 *                   gains_valide, gains_attente
 *
 * **Les identifiants viennent du `.env`.** Dans meilleursconcours.com ils sont
 * en clair dans le code, a six endroits — un depot clone, c'est l'acces au
 * compte de la regie.
 */
final class PlatformReportClient
{
    public function __construct(
        private Config $config,
        private Client $http,
        private LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->config->get('AFFILIATE_REPORT_URL') !== null
            && $this->config->get('AFFILIATE_REPORT_LOGIN') !== null
            && $this->config->get('AFFILIATE_REPORT_PASSWORD') !== null;
    }

    /**
     * Lignes du flux pour une journee.
     *
     * @return list<array<string,mixed>>
     */
    public function fetchDay(string $date, string $stat = 'global'): array
    {
        if (!$this->isConfigured()) {
            $this->logger->warning('Flux de reporting non configure', ['date' => $date]);
            return [];
        }

        try {
            $response = $this->http->get((string) $this->config->get('AFFILIATE_REPORT_URL'), [
                'query' => [
                    'login' => $this->config->get('AFFILIATE_REPORT_LOGIN'),
                    'pass' => $this->config->get('AFFILIATE_REPORT_PASSWORD'),
                    'flux' => 'xml',
                    'stat' => $stat,
                    'debut' => $date,
                    'fin' => $date,
                    'ids' => $this->config->get('AFFILIATE_SITE_IDS', ''),
                ],
                'timeout' => 30,
                'connect_timeout' => 5,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error('Flux de reporting : reponse inattendue', [
                    'date' => $date,
                    'status' => $response->getStatusCode(),
                ]);
                return [];
            }

            return $this->parse((string) $response->getBody(), $date);
        } catch (\Throwable $e) {
            $this->logger->error('Flux de reporting injoignable', [
                'date' => $date,
                'message' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function parse(string $xml, string $date): array
    {
        if (trim($xml) === '') {
            return [];
        }

        // Le flux historique est encode en CP1252 : sans conversion, un nom de
        // campagne accentue casse le parseur XML et fait perdre la journee.
        $xml = $this->toUtf8($xml);

        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET : ce document vient d'un tiers, il ne doit declencher
        // aucune requete reseau au parsing.
        $document = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document === false) {
            $this->logger->error('Flux de reporting illisible', ['date' => $date]);
            return [];
        }

        $rows = [];
        foreach ($document->campagne as $campaign) {
            $sid = $this->text($campaign->sid);
            $rows[] = [
                'platform_report_date' => $date,
                'platform_report_idc' => $this->text($campaign->idc),
                'platform_report_ids' => $this->text($campaign->ids),
                'platform_report_sid' => $sid,
                'platform_report_campaign_name' => mb_substr($this->text($campaign->nom), 0, 255),
                'platform_report_impressions' => $this->int($campaign->affichage),
                'platform_report_clicks' => $this->int($campaign->clic),
                'platform_report_dbclicks' => $this->int($campaign->dbclic),
                'platform_report_cpl_valid' => $this->int($campaign->cpl_valide),
                'platform_report_cpl_pending' => $this->int($campaign->cpl_attente),
                'platform_report_cpa_valid' => $this->int($campaign->cpa_valide),
                'platform_report_cpa_pending' => $this->int($campaign->cpa_attente),
                'platform_report_gains_valid' => $this->float($campaign->gains_valide),
                'platform_report_gains_pending' => $this->float($campaign->gains_attente),
            ];
        }

        return $rows;
    }

    /**
     * Ramene le document en UTF-8 ET met sa declaration d'encodage en accord.
     *
     * Convertir les octets sans corriger la declaration ne suffit pas : libxml
     * fait confiance a `encoding="ISO-8859-1"` et reconvertit un document deja
     * en UTF-8, ce qui produit du double encodage (« santé » devient
     * « santÃ© ») sans qu'aucune erreur ne soit levee.
     */
    private function toUtf8(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
            if (is_string($converted)) {
                $value = $converted;
            }
        }

        return (string) preg_replace(
            '/(<\?xml[^>]*\bencoding=)(["\'])[^"\']*\2/i',
            '$1$2UTF-8$2',
            $value,
            1
        );
    }

    private function text(mixed $node): string
    {
        return trim((string) $node);
    }

    private function int(mixed $node): int
    {
        return (int) trim((string) $node);
    }

    private function float(mixed $node): float
    {
        // Le flux ecrit parfois les montants a la francaise (« 12,50 »).
        return (float) str_replace(',', '.', trim((string) $node));
    }
}
