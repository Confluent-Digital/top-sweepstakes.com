<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

/**
 * Ce que chaque role a le droit de faire.
 *
 * La colonne `admin_user_role` existait depuis l'origine, avec trois valeurs
 * possibles — et n'etait lue NULLE PART. Un compte « viewer » avait donc
 * exactement les memes pouvoirs qu'un administrateur : creer un concours, le
 * publier, exporter les participants. Le role donnait l'apparence d'un
 * cloisonnement sans en poser aucun, ce qui est pire que de ne pas en avoir,
 * puisqu'on croit le contraire.
 *
 * Le decoupage est volontairement grossier — trois roles, une regle par zone —
 * parce qu'un modele de droits fin que personne ne comprend finit par etre
 * contourne en donnant « admin » a tout le monde.
 */
final class AdminRole
{
    public const ADMIN = 'admin';
    public const OPERATOR = 'operator';
    public const VIEWER = 'viewer';

    /** @var array<string,string> */
    public const LABELS = [
        self::ADMIN => 'Administrateur',
        self::OPERATOR => 'Opérateur',
        self::VIEWER => 'Lecture seule',
    ];

    /** @var array<string,string> */
    public const DESCRIPTIONS = [
        self::ADMIN => 'Tout, y compris les comptes et les réglages du site.',
        self::OPERATOR => 'Concours, offres, participants, tirages, statistiques. '
            . 'Ni les comptes, ni les réglages.',
        self::VIEWER => 'Consultation seule. Aucune modification, aucun export de participants.',
    ];

    /**
     * Zones reservees a l'administrateur.
     *
     * Les comptes, parce qu'on ne se donne pas des droits soi-meme. Les
     * reglages, parce qu'ils portent l'adresse postale et les liens legaux
     * affiches sur chaque page — les modifier engage l'editeur.
     */
    private const ADMIN_ONLY = ['/admin/users', '/admin/settings'];

    /**
     * Chemins qu'un lecteur ne doit pas atteindre, meme en GET.
     *
     * L'export des participants sort la base de donnees personnelles en un
     * fichier. Que ce soit techniquement une lecture ne change rien a ce que
     * c'est : une extraction.
     */
    private const NOT_FOR_VIEWERS = ['/admin/leads/export'];

    public static function isKnown(string $role): bool
    {
        return array_key_exists($role, self::LABELS);
    }

    public static function label(string $role): string
    {
        return self::LABELS[$role] ?? $role;
    }

    /**
     * Ce role peut-il emettre cette requete ?
     *
     * Le raisonnement porte sur le chemin et la methode, pas sur un catalogue
     * de permissions nommees : une route ajoutee demain est couverte par
     * construction, du bon cote de la barriere. Une permission nommee qu'on
     * oublie de poser, elle, ne protege rien.
     */
    public static function allows(string $role, string $method, string $path): bool
    {
        $method = strtoupper($method);
        $lecture = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);

        if (self::matches($path, self::ADMIN_ONLY) && $role !== self::ADMIN) {
            return false;
        }

        if ($role === self::VIEWER) {
            return $lecture && !self::matches($path, self::NOT_FOR_VIEWERS);
        }

        // Un role inconnu — valeur ecrite a la main en base, role retire du
        // code — ne se voit accorder que la lecture. L'inverse ouvrirait tout
        // sur une faute de frappe.
        if (!self::isKnown($role)) {
            return $lecture && !self::matches($path, self::NOT_FOR_VIEWERS);
        }

        return true;
    }

    /** @param list<string> $prefixes */
    private static function matches(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }
}
