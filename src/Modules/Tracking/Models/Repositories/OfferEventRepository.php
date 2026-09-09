<?php

declare(strict_types=1);

namespace App\Modules\Tracking\Models\Repositories;

use App\Core\Database;

/**
 * Ecriture des evenements display.
 *
 * Une offre affichee produit UNE ligne d'impression, un clic produit UNE ligne
 * de clic. Voir .claude/rules/offers-display.md : dans meilleursconcours.com,
 * un display coupon porte le meme identifiant sur trois liens et compte donc
 * trois impressions pour une seule offre — les eCPM calcules la-dessus sont
 * faux, et ce sont eux qui pilotent l'arbitrage.
 */
final class OfferEventRepository
{
    public function __construct(private Database $database)
    {
    }

    /**
     * Insere les impressions d'un rendu en une seule requete.
     *
     * @param list<array<string,mixed>> $events
     */
    public function recordMany(array $events): int
    {
        if ($events === []) {
            return 0;
        }

        $columns = [
            'offer_event_session_uid',
            'offer_event_id_lead',
            'offer_event_id_sweepstake',
            'offer_event_id_variant',
            'offer_event_id_offer',
            'offer_event_id_block',
            'offer_event_step',
            'offer_event_position',
            'offer_event_device',
            'offer_event_action',
            'offer_event_subid',
            'created_day',
        ];

        $placeholders = [];
        $values = [];
        foreach ($events as $i => $event) {
            $row = [];
            foreach ($columns as $column) {
                $key = $column . '_' . $i;
                $row[] = ':' . $key;
                $values[$key] = $event[$column] ?? null;
            }
            $placeholders[] = '(' . implode(', ', $row) . ')';
        }

        $sql = 'INSERT INTO t_offer_event (' . implode(', ', $columns) . ') VALUES '
            . implode(', ', $placeholders);

        return (int) $this->database->connection()->executeStatement($sql, $values);
    }

    /** @param array<string,mixed> $event */
    public function record(array $event): int
    {
        return $this->recordMany([$event]);
    }

    /**
     * Un clic deja enregistre pour ce couple (session, offre) dans la fenetre
     * donnee. Sert a ne pas compter deux fois un double-clic ou un retour
     * arriere du navigateur.
     */
    public function hasRecentClick(string $sessionUid, int $offerId, int $withinSeconds = 5): bool
    {
        if ($sessionUid === '') {
            return false;
        }
        return $this->database->connection()->fetchOne(
            'SELECT 1 FROM t_offer_event
              WHERE offer_event_session_uid = :session
                AND offer_event_id_offer = :offer
                AND offer_event_action = :action
                AND created_at >= (NOW() - INTERVAL :seconds SECOND)
              LIMIT 1',
            [
                'session' => $sessionUid,
                'offer' => $offerId,
                'action' => 'click',
                'seconds' => $withinSeconds,
            ]
        ) !== false;
    }

    public function countByAction(int $offerId, string $action, string $day): int
    {
        return (int) $this->database->connection()->fetchOne(
            'SELECT COUNT(*) FROM t_offer_event
              WHERE offer_event_id_offer = :offer
                AND offer_event_action = :action
                AND created_day = :day',
            ['offer' => $offerId, 'action' => $action, 'day' => $day]
        );
    }
}
