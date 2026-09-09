<?php

declare(strict_types=1);

namespace App\Modules\Leads\Models\Repositories;

use App\Core\Database;

/**
 * Liste de suppression : desinscriptions, demandes d'effacement, plaintes.
 *
 * Elle est consultee A LA CAPTURE et pas seulement a l'envoi : un e-mail qui y
 * figure ne doit jamais etre reintroduit dans la base, quelle que soit la
 * source du trafic.
 */
final class SuppressionRepository
{
    public function __construct(private Database $database)
    {
    }

    public function isSuppressed(string $emailMd5, string $phoneMd5 = ''): bool
    {
        $sql = 'SELECT 1 FROM t_suppression WHERE suppression_email_md5 = :email';
        $params = ['email' => $emailMd5];

        if ($phoneMd5 !== '') {
            $sql .= ' OR suppression_phone_md5 = :phone';
            $params['phone'] = $phoneMd5;
        }
        $sql .= ' LIMIT 1';

        return $this->database->connection()->fetchOne($sql, $params) !== false;
    }

    public function add(string $emailMd5, string $phoneMd5, string $type, string $source): int
    {
        $this->database->connection()->insert('t_suppression', [
            'suppression_email_md5' => $emailMd5,
            'suppression_phone_md5' => $phoneMd5,
            'suppression_type' => $type,
            'suppression_source' => $source,
        ]);
        return (int) $this->database->connection()->lastInsertId();
    }
}
