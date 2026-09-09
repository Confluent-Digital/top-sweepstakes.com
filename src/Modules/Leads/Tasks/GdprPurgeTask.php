<?php

declare(strict_types=1);

namespace App\Modules\Leads\Tasks;

use App\Core\Database;
use App\Modules\Stats\Models\Repositories\StatsRepository;
use Psr\Log\LoggerInterface;

/**
 * Application des durees de conservation.
 *
 * Les durees sont celles de .claude/rules/legal-us.md. Elles ne sont pas
 * decoratives : conserver des donnees personnelles au-dela de leur finalite est
 * en soi le manquement, meme si personne ne les consulte.
 *
 * Deux traitements distincts :
 *
 *  - **Anonymisation** des participants anciens. On ne supprime pas la ligne :
 *    les agregats de performance s'appuient dessus, et un concours reste
 *    auditable. On efface ce qui identifie la personne.
 *  - **Suppression** des evenements display anciens. Les agregats journaliers,
 *    qui ne portent aucune donnee personnelle, sont conserves.
 *
 * Les demandes explicites (droit a l'effacement, « do not sell ») sont traitees
 * en priorite, sans attendre l'echeance de conservation.
 */
final class GdprPurgeTask
{
    private const LEAD_RETENTION_MONTHS = 36;
    private const EVENT_RETENTION_MONTHS = 25;

    public function __construct(
        private Database $database,
        private StatsRepository $stats,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string,string> $options
     * @return array{ok: bool, message: string}
     */
    public function run(array $options): array
    {
        if (isset($options['dry-run'])) {
            return [
                'ok' => true,
                'message' => sprintf(
                    'Simulation : %d demande(s) explicite(s), %d participant(s) au-dela de %d mois, '
                    . '%d evenement(s) au-dela de %d mois.',
                    $this->countPendingRequests(),
                    $this->countExpiredLeads(),
                    self::LEAD_RETENTION_MONTHS,
                    $this->countExpiredEvents(),
                    self::EVENT_RETENTION_MONTHS
                ),
            ];
        }

        $anonymizedByRequest = $this->anonymizeRequested();
        $anonymizedByAge = $this->anonymizeExpired();
        $orphanConsents = $this->purgeOrphanConsents();
        $deletedEvents = $this->stats->purgeEvents(self::EVENT_RETENTION_MONTHS);

        $this->logger->info('Purge RGPD', [
            'sur_demande' => $anonymizedByRequest,
            'par_anciennete' => $anonymizedByAge,
            'preuves_orphelines' => $orphanConsents,
            'evenements_supprimes' => $deletedEvents,
        ]);

        return [
            'ok' => true,
            'message' => sprintf(
                '%d participant(s) anonymise(s) sur demande, %d par anciennete, '
                . '%d preuve(s) orpheline(s) retiree(s), %d evenement(s) supprime(s).',
                $anonymizedByRequest,
                $anonymizedByAge,
                $orphanConsents,
                $deletedEvents
            ),
        ];
    }

    private function countPendingRequests(): int
    {
        return (int) $this->database->connection()->fetchOne(
            'SELECT COUNT(*) FROM t_suppression
              WHERE suppression_type IN (:delete, :dns) AND suppression_processed_at IS NULL',
            ['delete' => 'delete_request', 'dns' => 'do_not_sell']
        );
    }

    private function countExpiredLeads(): int
    {
        return (int) $this->database->connection()->fetchOne(
            'SELECT COUNT(*) FROM t_lead l
              WHERE l.lead_anonymized_at IS NULL
                AND l.created_at < (NOW() - INTERVAL :months MONTH)',
            ['months' => self::LEAD_RETENTION_MONTHS]
        );
    }

    private function countExpiredEvents(): int
    {
        return (int) $this->database->connection()->fetchOne(
            'SELECT COUNT(*) FROM t_offer_event WHERE created_day < (CURDATE() - INTERVAL :months MONTH)',
            ['months' => self::EVENT_RETENTION_MONTHS]
        );
    }

    /** Demandes explicites : traitees sans attendre l'echeance. */
    private function anonymizeRequested(): int
    {
        $connection = $this->database->connection();

        $hashes = $connection->fetchFirstColumn(
            'SELECT suppression_email_md5 FROM t_suppression
              WHERE suppression_type IN (:delete, :dns)
                AND suppression_processed_at IS NULL
                AND suppression_email_md5 != :empty',
            ['delete' => 'delete_request', 'dns' => 'do_not_sell', 'empty' => '']
        );

        $count = 0;
        foreach ($hashes as $hash) {
            $count += $this->anonymizeWhere('l.lead_email_md5 = :hash', ['hash' => $hash]);
        }

        $connection->executeStatement(
            'UPDATE t_suppression SET suppression_processed_at = NOW()
              WHERE suppression_type IN (:delete, :dns) AND suppression_processed_at IS NULL',
            ['delete' => 'delete_request', 'dns' => 'do_not_sell']
        );

        return $count;
    }

    /**
     * Preuves rattachees a un participant deja anonymise.
     *
     * Le cas normal est couvert par anonymizeWhere(), qui les retire dans le
     * meme geste. Cette passe rattrape l'historique : des lignes anonymisees
     * avant que cette regle n'existe garderaient sinon indefiniment l'IP et le
     * user agent d'une personne dont la fiche a ete effacee.
     */
    private function purgeOrphanConsents(): int
    {
        return (int) $this->database->connection()->executeStatement(
            'DELETE c FROM t_lead_consent c
               JOIN t_lead l ON l.lead_id = c.lead_consent_id_lead
              WHERE l.lead_anonymized_at IS NOT NULL'
        );
    }

    private function anonymizeExpired(): int
    {
        return $this->anonymizeWhere(
            'l.lead_anonymized_at IS NULL AND l.created_at < (NOW() - INTERVAL :months MONTH)',
            ['months' => self::LEAD_RETENTION_MONTHS]
        );
    }

    /**
     * Efface ce qui identifie la personne, conserve ce qui fait la statistique.
     *
     * L'Etat et le concours restent : ils ne designent personne et portent
     * l'analyse. L'e-mail devient une valeur unique et inerte, pour ne pas
     * violer la contrainte d'unicite (concours, e-mail).
     *
     * Les preuves de consentement partent avec lui.
     *
     * C'est le point d'equilibre : elles portent l'IP, le user agent et l'URL,
     * c'est-a-dire precisement ce qu'on vient d'effacer de la fiche. Les garder
     * apres avoir anonymise le participant reviendrait a conserver ces donnees
     * dans une autre table en croyant s'en etre debarrasse — et c'est bien
     * l'ecriture d'origine, non modifiee, qui a valeur de preuve : une preuve
     * qu'on aurait expurgee de l'IP ne vaudrait de toute facon plus grand-chose.
     *
     * Concretement : au-dela de 36 mois, ou sur demande d'effacement, la preuve
     * disparait avec la personne. Elle n'est donc disponible que pendant la
     * duree ou le lead lui-meme l'est — ce que dit .claude/rules/legal-us.md.
     *
     * @param array<string,mixed> $params
     */
    private function anonymizeWhere(string $condition, array $params): int
    {
        $connection = $this->database->connection();

        $connection->executeStatement(
            'DELETE c FROM t_lead_consent c
               JOIN t_lead l ON l.lead_id = c.lead_consent_id_lead
              WHERE ' . $condition,
            $params
        );

        return (int) $connection->executeStatement(
            'UPDATE t_lead l SET
                l.lead_email         = CONCAT("anonymized-", l.lead_id, "@invalid"),
                l.lead_email_md5     = MD5(CONCAT("anonymized-", l.lead_id)),
                l.lead_first_name    = "",
                l.lead_last_name     = "",
                l.lead_phone         = "",
                l.lead_phone_md5     = "",
                l.lead_address       = "",
                l.lead_city          = "",
                l.lead_zip           = "",
                l.lead_dob           = NULL,
                l.lead_ip            = "",
                l.lead_user_agent    = "",
                l.lead_referer       = "",
                l.lead_anonymized_at = NOW()
              WHERE ' . $condition,
            $params
        );
    }
}
