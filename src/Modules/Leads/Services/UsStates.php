<?php

declare(strict_types=1);

namespace App\Modules\Leads\Services;

/**
 * Etats et territoires des Etats-Unis.
 *
 * Les territoires sont separes des cinquante Etats et du District de Columbia :
 * la plupart des reglements de jeux-concours limitent la participation aux
 * « 50 United States and the District of Columbia », et y inclure Porto Rico ou
 * Guam par inadvertance rend le reglement faux.
 */
final class UsStates
{
    /** @var array<string,string> */
    public const STATES = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
        'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
        'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
        'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
        'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
        'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
        'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
        'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
        'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
        'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
        'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
        'WI' => 'Wisconsin', 'WY' => 'Wyoming',
        'DC' => 'District of Columbia',
    ];

    /** @var array<string,string> */
    public const TERRITORIES = [
        'AS' => 'American Samoa', 'GU' => 'Guam', 'MP' => 'Northern Mariana Islands',
        'PR' => 'Puerto Rico', 'VI' => 'U.S. Virgin Islands',
    ];

    public static function isValid(string $code, bool $allowTerritories = false): bool
    {
        $code = strtoupper(trim($code));
        if (isset(self::STATES[$code])) {
            return true;
        }
        return $allowTerritories && isset(self::TERRITORIES[$code]);
    }

    public static function name(string $code): ?string
    {
        $code = strtoupper(trim($code));
        return self::STATES[$code] ?? self::TERRITORIES[$code] ?? null;
    }

    /** @return array<string,string> */
    public static function all(bool $withTerritories = false): array
    {
        return $withTerritories ? self::STATES + self::TERRITORIES : self::STATES;
    }

    /**
     * Codes d'Etat exclus, tels que saisis dans `sweepstake_excluded_states`.
     *
     * @return list<string>
     */
    public static function parseExcluded(string $raw): array
    {
        $codes = array_map(
            static fn(string $c): string => strtoupper(trim($c)),
            explode(',', $raw)
        );
        return array_values(array_unique(array_filter($codes, static fn(string $c): bool => $c !== '')));
    }
}
