<?php

declare(strict_types=1);

namespace App\Modules\Leads\Models\Repositories;

use App\Core\Database;

/**
 * Preuve de consentement.
 *
 * Cette classe n'expose volontairement AUCUNE methode de modification ni de
 * suppression : la table est en ecriture seule. Modifier un texte de
 * consentement ne modifie jamais les lignes deja ecrites — un nouveau texte
 * produit de nouvelles lignes. Voir .claude/rules/legal-us.md.
 *
 * La purge RGPD passe par gdpr:purge, qui supprime en cascade avec le
 * participant, pas par ce depot.
 */
final class ConsentRepository
{
    public function __construct(private Database $database)
    {
    }

    /**
     * @param array<string,mixed> $consent
     */
    public function record(array $consent): int
    {
        $this->database->connection()->insert('t_lead_consent', $consent);
        return (int) $this->database->connection()->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function findForLead(int $leadId): array
    {
        return $this->database->connection()->fetchAllAssociative(
            'SELECT * FROM t_lead_consent
              WHERE lead_consent_id_lead = :id
              ORDER BY created_at ASC, lead_consent_id ASC',
            ['id' => $leadId]
        );
    }
}
