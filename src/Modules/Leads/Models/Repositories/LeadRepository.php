<?php

declare(strict_types=1);

namespace App\Modules\Leads\Models\Repositories;

use App\Core\Database;
use Doctrine\DBAL\Connection;

final class LeadRepository
{
    public function __construct(private Database $database)
    {
    }

    private function connection(): Connection
    {
        return $this->database->connection();
    }

    /**
     * Enregistre ou met a jour un participant.
     *
     * La cle fonctionnelle est (concours, email) : une re-participation au meme
     * concours met a jour la ligne, une participation a un autre concours en
     * cree une nouvelle. C'est ce qui permet de conserver la date du premier
     * consentement par concours — l'unicite globale sur l'email de
     * meilleursconcours.com l'ecrase a chaque passage.
     *
     * @param array<string,mixed> $data
     */
    public function save(array $data): int
    {
        $existing = $this->findIdByEmail((int) $data['lead_id_sweepstake'], (string) $data['lead_email']);

        if ($existing !== null) {
            // created_at et lead_uniqid ne sont jamais reecrits : ce sont les
            // reperes de la premiere participation.
            unset($data['lead_uniqid'], $data['created_at']);
            $this->connection()->update('t_lead', $data, ['lead_id' => $existing]);
            return $existing;
        }

        $this->connection()->insert('t_lead', $data);
        return (int) $this->connection()->lastInsertId();
    }

    public function findIdByEmail(int $sweepstakeId, string $email): ?int
    {
        $id = $this->connection()->fetchOne(
            'SELECT lead_id FROM t_lead
              WHERE lead_id_sweepstake = :sweepstake AND lead_email = :email
              LIMIT 1',
            ['sweepstake' => $sweepstakeId, 'email' => $email]
        );
        return $id === false || $id === null ? null : (int) $id;
    }

    /** @return array<string,mixed>|null */
    public function findByUniqid(string $uniqid): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT * FROM t_lead WHERE lead_uniqid = :uniqid LIMIT 1',
            ['uniqid' => $uniqid]
        );
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $leadId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT * FROM t_lead WHERE lead_id = :id LIMIT 1',
            ['id' => $leadId]
        );
        return $row === false ? null : $row;
    }

    public function markComplete(int $leadId): void
    {
        $this->connection()->update('t_lead', ['lead_status' => 'complete'], ['lead_id' => $leadId]);
    }
}
