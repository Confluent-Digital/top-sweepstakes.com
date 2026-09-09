<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Core\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testRendLaValeurDuTableauEnv(): void
    {
        $config = new Config(['APP_NAME' => 'top-sweepstakes']);
        self::assertSame('top-sweepstakes', $config->get('APP_NAME'));
    }

    public function testUneValeurVideTombeSurLeDefaut(): void
    {
        $config = new Config(['DB_PASSWORD' => '']);
        self::assertSame('secret', $config->get('DB_PASSWORD', 'secret'));
    }

    public function testCleAbsenteRendNullSansDefaut(): void
    {
        self::assertNull((new Config([]))->get('CLE_INEXISTANTE_XYZ'));
    }

    public function testRequireLeveQuandLaValeurManque(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Config([]))->require('APP_SECRET');
    }

    /** Un secret vide doit lever, pas passer silencieusement : c'est tout l'objet de require(). */
    public function testRequireLeveQuandLaValeurEstVide(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Config(['APP_SECRET' => '']))->require('APP_SECRET');
    }

    /** @return array<string, array{string, bool}> */
    public static function fournisseurBooleens(): array
    {
        return [
            '1' => ['1', true],
            'true' => ['true', true],
            'TRUE majuscule' => ['TRUE', true],
            'yes' => ['yes', true],
            'on' => ['on', true],
            '0' => ['0', false],
            'false' => ['false', false],
            'valeur arbitraire' => ['peut-etre', false],
        ];
    }

    /** @dataProvider fournisseurBooleens */
    public function testInterpretationDesBooleens(string $brut, bool $attendu): void
    {
        self::assertSame($attendu, (new Config(['FLAG' => $brut]))->bool('FLAG'));
    }

    public function testIsProductionNeSeDeclencheQueSurLaValeurExacte(): void
    {
        self::assertTrue((new Config(['APP_ENV' => 'production']))->isProduction());
        self::assertFalse((new Config(['APP_ENV' => 'prod']))->isProduction());
        self::assertFalse((new Config([]))->isProduction());
    }
}
