<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

/**
 * Mots de passe a usage unique fondes sur le temps (RFC 6238).
 *
 * Ecrit ici plutot qu'importe : l'algorithme tient en une page — un HMAC-SHA1
 * sur le numero de periode, puis une troncature dynamique — et la RFC publie
 * des vecteurs de test qui permettent d'en prouver la conformite. Une
 * dependance de plus pour cela se paierait a chaque mise a jour sans rien
 * apporter de verifiable en retour.
 *
 * Compatible avec Google Authenticator, Authy, 1Password, Bitwarden : SHA-1,
 * six chiffres, periode de trente secondes. Ce ne sont pas des choix de
 * confort — ce sont ceux que les applications grand public savent lire.
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;

    /**
     * Tolerance, en periodes, de part et d'autre de l'instant present.
     *
     * Une periode, soit trente secondes avant et apres : de quoi absorber une
     * horloge de telephone mal reglee et le temps de frappe, sans elargir la
     * fenetre au point de rendre un code interessant a rejouer.
     */
    private const WINDOW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Secret aleatoire, en base32, pret a etre affiche ou encode en QR. */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * Code attendu pour une periode donnee.
     *
     * `$timestamp` explicite plutot que `time()` implicite : c'est ce qui rend
     * les vecteurs de la RFC rejouables en test.
     */
    public static function code(string $secret, ?int $timestamp = null, int $period = self::PERIOD): string
    {
        $counter = intdiv($timestamp ?? time(), $period);
        $binaire = self::base32Decode($secret);

        $hash = hash_hmac('sha1', pack('J', $counter), $binaire, true);

        // Troncature dynamique : les quatre bits de poids faible du dernier
        // octet designent l'offset de lecture. Le bit de poids fort est masque
        // pour que le resultat soit lu comme un entier positif quelle que soit
        // la plateforme.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $valeur = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($valeur % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Le code fourni est-il valide ? Renvoie le NUMERO DE PERIODE accepte, ou
     * null.
     *
     * Le numero est rendu, et non un simple booleen, parce que l'appelant doit
     * le retenir : un meme code reste valide pendant trente secondes, et sans
     * memoire de la derniere periode utilisee, un code intercepte se rejoue
     * dans cet intervalle.
     *
     * La comparaison est a temps constant. La difference de duree entre deux
     * comparaisons de six chiffres est infime, mais elle ne coute rien a
     * eliminer.
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }

        $maintenant = $timestamp ?? time();
        for ($decalage = -self::WINDOW; $decalage <= self::WINDOW; $decalage++) {
            $instant = $maintenant + ($decalage * self::PERIOD);
            if (hash_equals(self::code($secret, $instant), $code)) {
                return intdiv($instant, self::PERIOD);
            }
        }
        return null;
    }

    /**
     * URI `otpauth://`, celle que lisent les applications d'authentification.
     *
     * L'emetteur apparait deux fois — dans le chemin et en parametre — et ce
     * n'est pas une erreur : les applications anciennes lisent le chemin, les
     * recentes le parametre. L'omettre d'un cote laisse des entrees sans nom
     * dans certaines applications.
     */
    public static function uri(string $secret, string $compte, string $emetteur): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?%s',
            rawurlencode($emetteur),
            rawurlencode($compte),
            http_build_query([
                'secret' => $secret,
                'issuer' => $emetteur,
                'algorithm' => 'SHA1',
                'digits' => self::DIGITS,
                'period' => self::PERIOD,
            ], '', '&', PHP_QUERY_RFC3986)
        );
    }

    /** Secret presente par groupes de quatre : on le recopie a la main. */
    public static function humanize(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    public static function base32Encode(string $binaire): string
    {
        $bits = '';
        foreach (str_split($binaire) as $octet) {
            $bits .= str_pad(decbin(ord($octet)), 8, '0', STR_PAD_LEFT);
        }

        $sortie = '';
        foreach (str_split($bits, 5) as $bloc) {
            $sortie .= self::ALPHABET[bindec(str_pad($bloc, 5, '0', STR_PAD_RIGHT))];
        }
        return $sortie;
    }

    public static function base32Decode(string $base32): string
    {
        $base32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $base32) ?? '');

        $bits = '';
        foreach (str_split($base32) as $caractere) {
            $index = strpos(self::ALPHABET, $caractere);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $sortie = '';
        foreach (str_split($bits, 8) as $bloc) {
            if (strlen($bloc) === 8) {
                $sortie .= chr(bindec($bloc));
            }
        }
        return $sortie;
    }
}
