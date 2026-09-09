<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models\Repositories;

use App\Core\Database;

/**
 * Acces base des reserves d'ouverture.
 *
 * Deux roles distincts :
 *
 * - les LECTURES dont les controles ont besoin — annonceurs diffuses, offres
 *   sans identifiant de regie, concours publies. Elles vivent ici et non dans
 *   le service parce que c'est la seule couche d'acces base du depot ;
 * - les DECISIONS prises sur chaque reserve. Le catalogue, lui, vit dans le
 *   code : une cle sans ligne est ouverte, c'est l'etat par defaut, et il n'y a
 *   rien a semer.
 *
 * Ce qui releve d'un autre domaine n'est pas reimplemente ici : les concours en
 * attente de tirage se lisent dans DrawingRepository, qui fait deja foi pour
 * l'ecran des tirages et pour `drawing:run --pending`. Deux requetes du meme
 * nom aux criteres differents donneraient deux verites.
 */
final class ReadinessRepository
{
    /**
     * Statuts acceptes en base. Voir la migration CreateReadinessTable.
     *
     * Publique : le catalogue propose des libelles pour ces memes statuts, et
     * un test verifie que les deux listes ne divergent pas.
     *
     * @var list<string>
     */
    public const STATUSES = ['open', 'done', 'accepted'];

    public function __construct(private Database $database)
    {
    }

    /**
     * Toutes les decisions, indexees par cle.
     *
     * Une seule requete : l'ecran affiche une vingtaine de points et ne doit
     * pas faire une vingtaine d'allers-retours.
     *
     * @return array<string,array{status:string, note:string, by:string, at:string}>
     */
    public function all(): array
    {
        $rows = $this->database->connection()->fetchAllAssociative(
            'SELECT readiness_ack_key, readiness_ack_status, readiness_ack_note,
                    readiness_ack_by, COALESCE(updated_at, created_at) AS decided_at
               FROM t_readiness_ack'
        );

        $acks = [];
        foreach ($rows as $row) {
            $acks[(string) $row['readiness_ack_key']] = [
                'status' => (string) $row['readiness_ack_status'],
                'note' => (string) ($row['readiness_ack_note'] ?? ''),
                'by' => (string) ($row['readiness_ack_by'] ?? ''),
                'at' => (string) ($row['decided_at'] ?? ''),
            ];
        }
        return $acks;
    }

    // ------------------------------------------------- Lectures des controles

    /**
     * Annonceurs des offres ACTIVES.
     *
     * « Active » et non « diffusee » : la diffusion reelle depend en plus de
     * l'idv, du calendrier, du pays, des caps, du ciblage et du rattachement a
     * un concours (OfferSelector). Le controle vise plus large a dessein — une
     * offre activee est destinee a etre diffusee, et la page « Marketing
     * Partners » doit nommer ses destinataires avant le premier clic, pas
     * apres.
     *
     * @return list<string>
     */
    public function activeAdvertisers(): array
    {
        return array_map('strval', $this->database->connection()->fetchFirstColumn(
            'SELECT DISTINCT offer_advertiser FROM t_offer
              WHERE offer_active = 1 AND offer_advertiser != :empty
              ORDER BY offer_advertiser',
            ['empty' => '']
        ));
    }

    /**
     * Offres ACTIVES auxquelles il manque un identifiant de regie.
     *
     * @param 'offer_platform_idv'|'offer_platform_idc' $column
     * @return list<string> noms des offres
     */
    public function activeOffersMissing(string $column): array
    {
        // La colonne ne vient jamais d'une entree : le type l'enumere, et
        // l'appelant est le registre interne des controles.
        if (!in_array($column, ['offer_platform_idv', 'offer_platform_idc'], true)) {
            throw new \InvalidArgumentException('Colonne d\'identifiant inconnue : ' . $column);
        }

        return array_map('strval', $this->database->connection()->fetchFirstColumn(
            sprintf(
                'SELECT offer_name FROM t_offer WHERE offer_active = 1 AND %s = :empty ORDER BY offer_name',
                $column
            ),
            ['empty' => '']
        ));
    }

    /**
     * Concours publies, avec ce dont les controles ont besoin.
     *
     * Une seule lecture pour cinq controles : le reglement, les Etats exclus,
     * la dotation et l'adresse du sponsor se jugent ensemble.
     *
     * @return list<array{slug:string, rules:string, excluded:string, prize:float, sponsor_address:string}>
     */
    public function publishedSweepstakes(): array
    {
        $rows = $this->database->connection()->fetchAllAssociative(
            'SELECT sweepstake_slug, sweepstake_official_rules_html, sweepstake_excluded_states,
                    sweepstake_prize_value_usd, sweepstake_sponsor_address
               FROM t_sweepstake
              WHERE sweepstake_status = :status
              ORDER BY sweepstake_slug',
            ['status' => 'published']
        );

        return array_map(static fn(array $row): array => [
            'slug' => (string) $row['sweepstake_slug'],
            'rules' => (string) ($row['sweepstake_official_rules_html'] ?? ''),
            'excluded' => (string) ($row['sweepstake_excluded_states'] ?? ''),
            'prize' => (float) ($row['sweepstake_prize_value_usd'] ?? 0),
            'sponsor_address' => (string) ($row['sweepstake_sponsor_address'] ?? ''),
        ], $rows);
    }


    /**
     * Enregistre une decision.
     *
     * `open` supprime la ligne plutot que de l'ecrire : l'absence de ligne est
     * deja l'etat ouvert, et garder une trace « ouvert » ferait croire a une
     * decision la ou il n'y en a pas.
     */
    public function decide(string $key, string $status, string $note, string $by): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Statut de réserve inconnu : ' . $status);
        }

        $connection = $this->database->connection();

        if ($status === 'open') {
            $connection->delete('t_readiness_ack', ['readiness_ack_key' => $key]);
            return;
        }

        $connection->executeStatement(
            'INSERT INTO t_readiness_ack
                 (readiness_ack_key, readiness_ack_status, readiness_ack_note, readiness_ack_by)
             VALUES (:key, :status, :note, :by)
             ON DUPLICATE KEY UPDATE
                 readiness_ack_status = VALUES(readiness_ack_status),
                 readiness_ack_note = VALUES(readiness_ack_note),
                 readiness_ack_by = VALUES(readiness_ack_by)',
            ['key' => $key, 'status' => $status, 'note' => $note, 'by' => $by]
        );
    }
}
