<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Modules\Admin\Services\AdminRole;
use PHPUnit\Framework\TestCase;

/**
 * Les droits des roles de back-office.
 *
 * La colonne existait depuis l'origine et n'etait lue nulle part : un compte
 * « viewer » pouvait publier un concours et exporter les participants. Un role
 * qui ne cloisonne rien est pire que pas de role, puisqu'on croit le contraire.
 */
final class AdminRoleTest extends TestCase
{
    /** @return array<string, array{string, string, string, bool}> */
    public static function acces(): array
    {
        return [
            // L'administrateur, partout.
            'admin — comptes'            => [AdminRole::ADMIN, 'GET', '/admin/users', true],
            'admin — creation de compte' => [AdminRole::ADMIN, 'POST', '/admin/users/new', true],
            'admin — reglages'           => [AdminRole::ADMIN, 'POST', '/admin/settings', true],
            'admin — export'             => [AdminRole::ADMIN, 'GET', '/admin/leads/export', true],

            // L'operateur exploite, mais ne se donne pas de droits et n'engage
            // pas l'editeur.
            'operateur — concours'       => [AdminRole::OPERATOR, 'POST', '/admin/sweepstakes/3/edit', true],
            'operateur — tirage'         => [AdminRole::OPERATOR, 'POST', '/admin/drawings/grand-prize', true],
            'operateur — export'         => [AdminRole::OPERATOR, 'GET', '/admin/leads/export', true],
            'operateur — comptes'        => [AdminRole::OPERATOR, 'GET', '/admin/users', false],
            'operateur — reglages'       => [AdminRole::OPERATOR, 'GET', '/admin/settings', false],
            'operateur — reglages POST'  => [AdminRole::OPERATOR, 'POST', '/admin/settings', false],

            // Le lecteur lit, et rien d'autre.
            'lecteur — tableau de bord'  => [AdminRole::VIEWER, 'GET', '/admin', true],
            'lecteur — fiche concours'   => [AdminRole::VIEWER, 'GET', '/admin/sweepstakes/3/edit', true],
            'lecteur — enregistrement'   => [AdminRole::VIEWER, 'POST', '/admin/sweepstakes/3/edit', false],
            'lecteur — tirage'           => [AdminRole::VIEWER, 'POST', '/admin/drawings/grand-prize', false],
            'lecteur — comptes'          => [AdminRole::VIEWER, 'GET', '/admin/users', false],
            // Techniquement une lecture ; en pratique une extraction de la base
            // de donnees personnelles.
            'lecteur — export'           => [AdminRole::VIEWER, 'GET', '/admin/leads/export', false],
        ];
    }

    /** @dataProvider acces */
    public function testDroitsParRole(string $role, string $methode, string $chemin, bool $attendu): void
    {
        self::assertSame($attendu, AdminRole::allows($role, $methode, $chemin));
    }

    /**
     * Une valeur de role inconnue — ecrite a la main en base, ou retiree du
     * code — ne doit donner que la lecture. L'inverse ouvrirait tout sur une
     * faute de frappe.
     */
    public function testUnRoleInconnuNeDonneQueLaLecture(): void
    {
        self::assertTrue(AdminRole::allows('redacteur', 'GET', '/admin'));
        self::assertFalse(AdminRole::allows('redacteur', 'POST', '/admin/sweepstakes/3/edit'));
        self::assertFalse(AdminRole::allows('', 'GET', '/admin/users'));
        self::assertFalse(AdminRole::allows('redacteur', 'GET', '/admin/leads/export'));
    }

    /** Un prefixe ne doit pas deborder sur un chemin qui lui ressemble. */
    public function testLesPrefixesNeDebordentPas(): void
    {
        self::assertTrue(AdminRole::allows(AdminRole::OPERATOR, 'GET', '/admin/users-quelque-chose'));
        self::assertFalse(AdminRole::allows(AdminRole::OPERATOR, 'GET', '/admin/users'));
        self::assertFalse(AdminRole::allows(AdminRole::OPERATOR, 'GET', '/admin/users/4/edit'));
    }

    public function testLesLibellesCouvrentLesTroisRoles(): void
    {
        foreach ([AdminRole::ADMIN, AdminRole::OPERATOR, AdminRole::VIEWER] as $role) {
            self::assertTrue(AdminRole::isKnown($role));
            self::assertNotSame('', AdminRole::label($role));
            self::assertArrayHasKey($role, AdminRole::DESCRIPTIONS);
        }
    }
}
