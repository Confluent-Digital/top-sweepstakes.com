<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Modules\Admin\Services\Totp;
use PHPUnit\Framework\TestCase;

/**
 * Conformite TOTP, contre les vecteurs de la RFC 6238.
 *
 * Ecrire l'algorithme soi-meme n'est defendable que si on le PROUVE. La RFC
 * publie une table de codes attendus pour un secret et des instants connus :
 * c'est elle qui fait foi, pas une comparaison avec une application du
 * commerce. Si ces valeurs passent, une application d'authentification lira nos
 * codes, et reciproquement.
 *
 * Le secret de la RFC pour SHA-1 est la chaine ASCII « 12345678901234567890 ».
 */
final class TotpTest extends TestCase
{
    /** Secret de la RFC, encode en base32 comme le veulent les applications. */
    private static function secretRfc(): string
    {
        return Totp::base32Encode('12345678901234567890');
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function vecteursRfc6238(): array
    {
        // Table 1 de la RFC, colonne SHA-1, tronquee a huit chiffres dans le
        // document ; on compare les six de poids faible, qui sont ceux que
        // produit un TOTP a six chiffres.
        return [
            '1970-01-01 00:00:59' => [59, '287082'],
            '2005-03-18 01:58:29' => [1111111109, '081804'],
            '2005-03-18 01:58:31' => [1111111111, '050471'],
            '2009-02-13 23:31:30' => [1234567890, '005924'],
            '2033-05-18 03:33:20' => [2000000000, '279037'],
            '2603-10-11 11:33:20' => [20000000000, '353130'],
        ];
    }

    /** @dataProvider vecteursRfc6238 */
    public function testVecteursDeLaRfc(int $instant, string $attendu): void
    {
        self::assertSame($attendu, Totp::code(self::secretRfc(), $instant));
    }

    public function testUnCodeValideEstAccepteEtRendSaPeriode(): void
    {
        $secret = Totp::generateSecret();
        $instant = 1_700_000_000;

        $periode = Totp::verify($secret, Totp::code($secret, $instant), $instant);

        self::assertSame(intdiv($instant, Totp::PERIOD), $periode);
    }

    /**
     * Trente secondes de tolerance de chaque cote : une horloge de telephone
     * mal reglee ne doit pas empecher de se connecter.
     */
    public function testLaToleranceCouvreUnePeriodeDeChaqueCote(): void
    {
        $secret = Totp::generateSecret();
        $instant = 1_700_000_000;

        self::assertNotNull(Totp::verify($secret, Totp::code($secret, $instant - 30), $instant));
        self::assertNotNull(Totp::verify($secret, Totp::code($secret, $instant + 30), $instant));
    }

    /** Au-dela, le code est refuse : la fenetre ne doit pas s'elargir. */
    public function testAuDelaDeLaToleranceLeCodeEstRefuse(): void
    {
        $secret = Totp::generateSecret();
        $instant = 1_700_000_000;

        self::assertNull(Totp::verify($secret, Totp::code($secret, $instant - 90), $instant));
        self::assertNull(Totp::verify($secret, Totp::code($secret, $instant + 90), $instant));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function saisiesInvalides(): array
    {
        return [
            'vide' => [''],
            'trop court' => ['12345'],
            'trop long' => ['1234567'],
            'lettres' => ['abcdef'],
            'injection' => ["123456' OR '1"],
        ];
    }

    /** @dataProvider saisiesInvalides */
    public function testUneSaisieMalFormeeEstRefusee(string $saisie): void
    {
        self::assertNull(Totp::verify(Totp::generateSecret(), $saisie, 1_700_000_000));
    }

    /** Les espaces de recopie ne doivent pas faire echouer une saisie juste. */
    public function testLesEspacesSontTolerees(): void
    {
        $secret = Totp::generateSecret();
        $instant = 1_700_000_000;
        $code = Totp::code($secret, $instant);

        self::assertNotNull(Totp::verify($secret, substr($code, 0, 3) . ' ' . substr($code, 3), $instant));
    }

    public function testUnSecretEtrangerNeValidePas(): void
    {
        $instant = 1_700_000_000;
        $code = Totp::code(Totp::generateSecret(), $instant);

        self::assertNull(Totp::verify(Totp::generateSecret(), $code, $instant));
    }

    /** Aller-retour base32, y compris sur des longueurs non multiples de 5. */
    public function testBase32FaitUnAllerRetourFidele(): void
    {
        foreach (['a', 'ab', 'abc', 'abcd', 'abcde', '12345678901234567890', random_bytes(20)] as $clair) {
            self::assertSame($clair, Totp::base32Decode(Totp::base32Encode($clair)));
        }
    }

    public function testLeSecretGenereEstLisibleParUneApplication(): void
    {
        $secret = Totp::generateSecret();

        self::assertSame(32, strlen($secret), '20 octets donnent 32 caracteres en base32');
        self::assertSame(1, preg_match('/^[A-Z2-7]+$/', $secret), 'alphabet base32 uniquement');
    }

    /**
     * L'emetteur figure DEUX fois — dans le chemin et en parametre. Les
     * applications anciennes lisent l'un, les recentes l'autre ; en omettre un
     * laisse des entrees sans nom.
     */
    public function testUriOtpauth(): void
    {
        $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'guillaume@confluent-digital.com', 'Top Sweepstakes');

        self::assertStringStartsWith('otpauth://totp/Top%20Sweepstakes:guillaume%40confluent-digital.com?', $uri);
        self::assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        self::assertStringContainsString('issuer=Top%20Sweepstakes', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }
}
