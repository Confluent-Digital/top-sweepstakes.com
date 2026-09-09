<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Leads\Services\UsStates;

/**
 * Les jugements que portent les controles d'ouverture, isoles du reste.
 *
 * ReadinessService va chercher les contenus — base, service de contenus
 * juridiques, configuration. Ce qui est ici ne fait que TRANCHER : ce texte
 * est-il une politique de donnees deguisee en conditions generales ? cette
 * adresse est-elle americaine ? ce document parle-t-il des droits californiens ?
 *
 * La separation n'est pas cosmetique. C'est exactement la ou se logent les
 * erreurs qui comptent, et ce sont des fonctions pures : elles se testent sur
 * des chaines, sans base ni reseau. Une premiere version cherchait « do not
 * sell » dans la politique de confidentialite et tombait sur « WHAT HAPPENS IF
 * YOU DO NOT SELL US YOUR DATA ? » — un point bloquant passait au vert sur un
 * document ou le mot California n'apparait pas une seule fois. Un faux vert est
 * pire que pas de controle : il fait croire que quelqu'un a verifie.
 */
final class ReadinessProbes
{
    /**
     * Signatures d'une politique de protection des donnees.
     *
     * On cherche la signature du MAUVAIS document plutot que l'absence de
     * celle du bon : un texte peut etre de vraies conditions generales sans
     * employer un vocabulaire attendu, alors qu'une politique de donnees
     * s'annonce toujours par son titre.
     */
    public const PRIVACY_SIGNATURES = [
        'personal data protection policy',
        'data protection policy',
        'politique de protection des donnees',
    ];

    /**
     * Mentions qui attestent d'un volet californien.
     *
     * Volontairement longues : aucune ne peut apparaitre par hasard dans un
     * texte qui ne traite pas du CCPA. « california » seul est ecarte — il
     * figure dans n'importe quelle adresse.
     */
    public const CALIFORNIA_MARKERS = [
        'ccpa',
        'cpra',
        'california consumer privacy',
        'california privacy rights',
        'california resident',
        'do not sell or share my personal information',
        'do not sell my personal information',
    ];

    /** Le document servi comme conditions generales est-il une politique de donnees ? */
    public static function looksLikePrivacyPolicy(string $text): ?string
    {
        return self::firstMatch($text, self::PRIVACY_SIGNATURES);
    }

    /** Le document traite-t-il des droits des residents de Californie ? */
    public static function mentionsCaliforniaRights(string $text): ?string
    {
        return self::firstMatch($text, self::CALIFORNIA_MARKERS);
    }

    /**
     * Ceux des noms cherches qui apparaissent dans le texte.
     *
     * @param list<string> $needles
     * @return list<string>
     */
    public static function names(string $text, array $needles): array
    {
        $found = [];
        foreach ($needles as $needle) {
            if ($needle !== '' && mb_stripos($text, $needle) !== false) {
                $found[] = $needle;
            }
        }
        return $found;
    }

    /**
     * Mentions de pays tolerees apres le code postal.
     *
     * Une adresse americaine destinee a des expediteurs internationaux — ce
     * qu'est une adresse AMOE — se termine tres normalement par « USA ». Ancrer
     * le motif juste apres le code postal rejetait « 1 Demo Street, New York,
     * NY 10001, USA » : un exploitant qui remplace l'adresse francaise par une
     * adresse americaine correcte gardait le controle au rouge, sans moyen de
     * comprendre pourquoi.
     */
    private const COUNTRY_SUFFIX = 'USA|U\.S\.A\.?|US|U\.S\.|United States(?: of America)?';

    /**
     * L'adresse se termine-t-elle par « ETAT 12345 », eventuellement suivi du
     * pays ?
     *
     * Volontairement grossier : on ne valide pas une adresse, on repere celle
     * qui n'est manifestement pas americaine. Le seul faux negatif concevable —
     * une adresse etrangere finissant par un code d'Etat et cinq chiffres —
     * n'existe pas en pratique.
     */
    public static function looksLikeUsAddress(string $address): bool
    {
        $codes = implode('|', array_keys(UsStates::all(true)));
        $pattern = '/\b(' . $codes . ')\s+\d{5}(-\d{4})?'
            . '(\s*,?\s*(' . self::COUNTRY_SUFFIX . '))?'
            . '\s*\.?\s*$/i';

        return preg_match($pattern, trim($address)) === 1;
    }

    /** @param list<string> $needles */
    private static function firstMatch(string $text, array $needles): ?string
    {
        foreach ($needles as $needle) {
            if (mb_stripos($text, $needle) !== false) {
                return $needle;
            }
        }
        return null;
    }
}
