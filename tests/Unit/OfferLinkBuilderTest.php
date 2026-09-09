<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Core\Config;
use App\Modules\Offers\Services\OfferLinkBuilder;
use PHPUnit\Framework\TestCase;

final class OfferLinkBuilderTest extends TestCase
{
    private const BASE = 'https://www.cdflow4.com/tracking/cpc.php';

    private function builder(string $siteIds = '996'): OfferLinkBuilder
    {
        return new OfferLinkBuilder(new Config([
            'AFFILIATE_TRACKING_BASE' => self::BASE,
            'AFFILIATE_SITE_IDS' => $siteIds,
        ]));
    }

    /** @return array<string,mixed> */
    private function offer(array $overrides = []): array
    {
        return $overrides + [
            'offer_id' => 7,
            'offer_platform_ids' => '996',
            'offer_platform_idv' => '4303',
            'offer_passthrough_fields' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function context(array $overrides = []): array
    {
        return $overrides + [
            'sweepstake_id' => 12,
            'subid' => 'aff42',
            'email_md5' => str_repeat('a', 32),
            'date' => '2026-09-09',
            'lead' => [],
        ];
    }

    /** @return array<string,string> */
    private function queryOf(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str((string) $query, $out);
        /** @var array<string,string> $out */
        return $out;
    }

    public function testUrlDeBaseEtIdentifiantsDeRegie(): void
    {
        $url = $this->builder()->build($this->offer(), $this->context());

        self::assertStringStartsWith(self::BASE . '?', $url);
        $q = $this->queryOf($url);
        self::assertSame('996', $q['ids']);
        self::assertSame('4303', $q['idv']);
    }

    public function testIdsRetombeSurLaConfigurationQuandLOffreNEnPortePas(): void
    {
        $url = $this->builder('1234')->build($this->offer(['offer_platform_ids' => '']), $this->context());
        self::assertSame('1234', $this->queryOf($url)['ids']);
    }

    public function testFormatDuSid(): void
    {
        $url = $this->builder()->build($this->offer(), $this->context());
        self::assertSame('12_aff42_' . str_repeat('a', 32) . '_2026-09-09', $this->queryOf($url)['sid']);
    }

    /**
     * Le sid est positionnel et se decoupe sur « _ ». Un underscore dans le
     * subid decalerait tous les segments suivants, et le rapprochement des
     * revenus serait faux sans qu'aucune erreur ne soit levee.
     */
    public function testUnUnderscoreDansLeSubidNeCassePasLeDecoupageDuSid(): void
    {
        $url = $this->builder()->build($this->offer(), $this->context(['subid' => 'aff_42_x']));
        $sid = $this->queryOf($url)['sid'];

        self::assertCount(4, explode('_', $sid));
        self::assertSame('12_aff-42-x_' . str_repeat('a', 32) . '_2026-09-09', $sid);
    }

    public function testUnSegmentVideResteUnSegment(): void
    {
        $url = $this->builder()->build($this->offer(), $this->context(['subid' => '']));
        self::assertCount(4, explode('_', $this->queryOf($url)['sid']));
        self::assertStringContainsString('12_-_', $this->queryOf($url)['sid']);
    }

    public function testAucuneDonneePersonnelleSansListeBlanche(): void
    {
        $url = $this->builder()->build(
            $this->offer(),
            $this->context(['lead' => ['email' => 'john@example.com', 'first_name' => 'John']])
        );

        $q = $this->queryOf($url);
        self::assertSame(['ids', 'idv', 'sid'], array_keys($q));
    }

    public function testSeulsLesChampsDeLaListeBlancheSortent(): void
    {
        $url = $this->builder()->build(
            $this->offer(['offer_passthrough_fields' => '["email","zip"]']),
            $this->context(['lead' => [
                'email' => 'john@example.com',
                'first_name' => 'John',
                'zip' => '10001',
                'phone' => '2125550147',
            ]])
        );

        $q = $this->queryOf($url);
        self::assertSame('john@example.com', $q['email']);
        self::assertSame('10001', $q['zip']);
        self::assertArrayNotHasKey('firstname', $q);
        self::assertArrayNotHasKey('phone', $q);
    }

    /** Un champ inconnu inscrit en base ne doit pas ouvrir une fuite. */
    public function testUnChampHorsTableDeCorrespondanceEstIgnore(): void
    {
        $url = $this->builder()->build(
            $this->offer(['offer_passthrough_fields' => '["email","ssn","password"]']),
            $this->context(['lead' => [
                'email' => 'john@example.com',
                'ssn' => '123-45-6789',
                'password' => 'hunter2',
            ]])
        );

        $q = $this->queryOf($url);
        self::assertArrayNotHasKey('ssn', $q);
        self::assertArrayNotHasKey('password', $q);
        self::assertStringNotContainsString('123-45-6789', $url);
    }

    /**
     * Le cas qui casse l'implementation historique : une valeur contenant « & »
     * ou « = » non encodee injecte des parametres dans l'URL de la regie.
     */
    public function testLesValeursSontEncodees(): void
    {
        $url = $this->builder()->build(
            $this->offer(['offer_passthrough_fields' => '["first_name","city","email"]']),
            $this->context(['lead' => [
                'first_name' => 'Jean & Marie=1',
                'city' => 'New York',
                'email' => 'jose+tag@exämple.com',
            ]])
        );

        // L'URL brute ne contient plus ni esperluette ni signe egal parasites.
        self::assertStringNotContainsString('Jean & Marie', $url);
        self::assertStringNotContainsString('New York', $url);

        // Et apres decodage, les valeurs sont intactes.
        $q = $this->queryOf($url);
        self::assertSame('Jean & Marie=1', $q['firstname']);
        self::assertSame('New York', $q['city']);
        self::assertSame('jose+tag@exämple.com', $q['email']);

        // Le nombre de parametres reste celui attendu : rien n'a ete injecte.
        self::assertCount(6, $q);
    }

    public function testUneValeurVideNEstPasTransmise(): void
    {
        $url = $this->builder()->build(
            $this->offer(['offer_passthrough_fields' => '["email","phone"]']),
            $this->context(['lead' => ['email' => 'john@example.com', 'phone' => '']])
        );

        self::assertArrayNotHasKey('phone', $this->queryOf($url));
    }

    public function testListeBlancheAcceptantUnTableauDejaDecode(): void
    {
        $url = $this->builder()->build(
            $this->offer(['offer_passthrough_fields' => ['zip']]),
            $this->context(['lead' => ['zip' => '10001']])
        );

        self::assertSame('10001', $this->queryOf($url)['zip']);
    }

    public function testUneOffreSansIdvLeve(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/offer_platform_idv/');
        $this->builder()->build($this->offer(['offer_platform_idv' => '']), $this->context());
    }

    public function testUneBaseNonConfigureeLeve(): void
    {
        $builder = new OfferLinkBuilder(new Config([]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/AFFILIATE_TRACKING_BASE/');
        $builder->build($this->offer(), $this->context());
    }

    public function testLeSidUtiliseLaDateDuJourParDefaut(): void
    {
        $sid = $this->builder()->buildSid(3, 'src', str_repeat('b', 32));
        self::assertStringEndsWith('_' . date('Y-m-d'), $sid);
    }
}
