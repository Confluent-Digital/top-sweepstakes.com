<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Core\SeedGuard;
use PHPUnit\Framework\TestCase;

/**
 * Le garde-fou qui empeche un concours de demonstration d'etre publie sur un
 * domaine reel.
 *
 * Les seeders ne posent pas des donnees neutres : ils ecrivent
 * `sweepstake_status = 'published'`. Sur une production, cela met en ligne de
 * faux jeux-concours americains — reglements generes jamais relus, offres
 * pointant sur des identifiants de regie factices. Rien dans `phinx seed:run`
 * ne distingue un environnement d'un autre : un seul `-e prod` de trop suffit.
 */
final class SeedGuardTest extends TestCase
{
    public function testUnEnvironnementDeDeveloppementLaissePasser(): void
    {
        $this->expectNotToPerformAssertions();
        SeedGuard::refuseInProduction('DemoSweepstakeSeeder', ['APP_ENV' => 'development']);
    }

    /** APP_ENV absent vaut « development », comme Config::isProduction(). */
    public function testUnEnvironnementNonRenseigneLaissePasser(): void
    {
        $this->expectNotToPerformAssertions();
        SeedGuard::refuseInProduction('DemoSweepstakeSeeder', []);
    }

    public function testLaProductionEstRefusee(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/refuse de s\'executer/');
        SeedGuard::refuseInProduction('DemoSweepstakeSeeder', ['APP_ENV' => 'production']);
    }

    /** Le message doit dire quoi faire A LA PLACE, pas seulement refuser. */
    public function testLeRefusDonneLaMarcheASuivre(): void
    {
        try {
            SeedGuard::refuseInProduction('SweepstakesCatalogSeeder', ['APP_ENV' => 'production']);
            self::fail('Le garde-fou aurait du refuser.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('SweepstakesCatalogSeeder', $e->getMessage());
            self::assertStringContainsString('phinx migrate -e prod', $e->getMessage());
            self::assertStringContainsString('admin:create', $e->getMessage());
            self::assertStringContainsString(SeedGuard::OVERRIDE, $e->getMessage());
        }
    }

    /**
     * La derogation doit etre explicite. Elle existe pour un premier
     * deploiement qui voudrait les reglages du site — pas pour contourner le
     * garde-fou par inadvertance.
     */
    public function testLaDerogationExpliciteLaissePasser(): void
    {
        $this->expectNotToPerformAssertions();
        SeedGuard::refuseInProduction('SiteSettingsSeeder', [
            'APP_ENV' => 'production',
            SeedGuard::OVERRIDE => '1',
        ]);
    }

    /** Une valeur qui n'est pas vraie ne vaut pas derogation. */
    public function testUneDerogationVideNeSuffitPas(): void
    {
        $this->expectException(\RuntimeException::class);
        SeedGuard::refuseInProduction('SiteSettingsSeeder', [
            'APP_ENV' => 'production',
            SeedGuard::OVERRIDE => '0',
        ]);
    }

    /**
     * Chaque seeder doit appeler le garde-fou. En ajouter un sans l'appel
     * rouvrirait le trou en silence.
     */
    public function testChaqueSeederAppelleLeGardeFou(): void
    {
        $seeders = glob(__DIR__ . '/../../database/seeds/*Seeder.php') ?: [];
        self::assertNotEmpty($seeders);

        foreach ($seeders as $fichier) {
            self::assertStringContainsString(
                'SeedGuard::refuseInProduction',
                (string) file_get_contents($fichier),
                basename($fichier) . ' n\'appelle pas le garde-fou.'
            );
        }
    }
}
