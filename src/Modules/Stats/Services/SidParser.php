<?php

declare(strict_types=1);

namespace App\Modules\Stats\Services;

/**
 * Lecture du `sid` renvoye par la regie.
 *
 * Format positionnel produit par OfferLinkBuilder :
 * `{sweepstake_id}_{subid}_{email_md5}_{date}`.
 *
 * C'est l'unique cle qui relie leurs chiffres aux notres. Le parseur est donc
 * volontairement strict : un `sid` qui ne se lit pas est signale, pas devine.
 * Deviner reviendrait a attribuer un revenu au mauvais concours ou a la
 * mauvaise source, silencieusement.
 */
final class SidParser
{
    /**
     * @return array{sweepstake_id: int, subid: string, email_md5: string, date: string}|null
     */
    public function parse(string $sid): ?array
    {
        $parts = explode('_', trim($sid));
        if (count($parts) !== 4) {
            return null;
        }

        [$sweepstakeId, $subid, $emailMd5, $date] = $parts;

        if (!ctype_digit($sweepstakeId)) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        return [
            'sweepstake_id' => (int) $sweepstakeId,
            // Le segment vide vaut « - » a la construction : on le rend vide ici.
            'subid' => $subid === '-' ? '' : $subid,
            'email_md5' => $emailMd5 === '-' ? '' : $emailMd5,
            'date' => $date,
        ];
    }
}
