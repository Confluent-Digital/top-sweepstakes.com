<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Modules\Admin\Middleware\AdminAuthMiddleware;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Chemins laisses ouverts a un compte sans double authentification.
 *
 * L'activation est obligatoire : tant qu'elle n'est pas faite, tout renvoie
 * vers l'ecran qui permet de la faire. Cette liste est donc la seule porte, et
 * elle doit rester exactement de la bonne taille.
 *
 * Trop etroite, le compte ne peut plus rien faire — pas meme se deconnecter.
 * Trop large, l'obligation ne vaut plus rien : il suffirait qu'un prefixe
 * deborde sur un ecran de donnees pour qu'on y accede sans second facteur.
 */
final class EnrolmentPathTest extends TestCase
{
    private static function ouvert(string $path): bool
    {
        $methode = new ReflectionMethod(AdminAuthMiddleware::class, 'isEnrolmentPath');
        $methode->setAccessible(true);

        return (bool) $methode->invoke(
            (new \ReflectionClass(AdminAuthMiddleware::class))->newInstanceWithoutConstructor(),
            $path
        );
    }

    /** @return array<string, array{string, bool}> */
    public static function chemins(): array
    {
        return [
            // La porte : activer, et repartir.
            'ecran de compte'        => ['/admin/account', true],
            'activation'             => ['/admin/account/2fa/enable', true],
            'codes de secours'       => ['/admin/account/recovery-codes', true],
            'deconnexion'            => ['/admin/logout', true],
            'connexion'              => ['/admin/login', true],
            'second facteur'         => ['/admin/login/code', true],

            // Tout le reste est ferme, y compris le tableau de bord : il
            // affiche deja des volumes de participations.
            'tableau de bord'        => ['/admin', false],
            'tableau de bord (slash)' => ['/admin/', false],
            'participants'           => ['/admin/leads', false],
            'export des participants' => ['/admin/leads/export', false],
            'concours'               => ['/admin/sweepstakes', false],
            'comptes'                => ['/admin/users', false],
            'reglages'               => ['/admin/settings', false],
            'reserves'               => ['/admin/readiness', false],

            // Un prefixe ne doit pas deborder sur un chemin qui lui ressemble.
            'faux voisin de account' => ['/admin/accounts', false],
            'faux voisin de login'   => ['/admin/login-history', false],
        ];
    }

    /** @dataProvider chemins */
    public function testPorteOuverteAuStrictNecessaire(string $chemin, bool $attendu): void
    {
        self::assertSame($attendu, self::ouvert($chemin));
    }
}
