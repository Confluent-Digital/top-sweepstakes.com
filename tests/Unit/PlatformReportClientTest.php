<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Core\Config;
use App\Modules\Platform\Services\PlatformReportClient;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Le parsing du flux se teste sans reseau : aucun appel a la regie n'est fait
 * ici, conformement a .claude/rules/testing.md.
 */
final class PlatformReportClientTest extends TestCase
{
    private function client(array $env = []): PlatformReportClient
    {
        return new PlatformReportClient(new Config($env), new Client(), new NullLogger());
    }

    private function xml(string $inner): string
    {
        return '<?xml version="1.0" encoding="ISO-8859-1"?><reporting>' . $inner . '</reporting>';
    }

    public function testLectureNominale(): void
    {
        $rows = $this->client()->parse($this->xml(
            '<campagne>
                <nom>Auto Insurance</nom><idc>1516</idc><ids>996</ids>
                <sid>12_aff42_abc_2026-09-09</sid>
                <affichage>1200</affichage><clic>48</clic><dbclic>3</dbclic>
                <cpl_valide>7</cpl_valide><cpl_attente>2</cpl_attente>
                <cpa_valide>1</cpa_valide><cpa_attente>0</cpa_attente>
                <gains_valide>21.50</gains_valide><gains_attente>4.00</gains_attente>
             </campagne>'
        ), '2026-09-09');

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('2026-09-09', $row['platform_report_date']);
        self::assertSame('1516', $row['platform_report_idc']);
        self::assertSame('996', $row['platform_report_ids']);
        self::assertSame('12_aff42_abc_2026-09-09', $row['platform_report_sid']);
        self::assertSame('Auto Insurance', $row['platform_report_campaign_name']);
        self::assertSame(1200, $row['platform_report_impressions']);
        self::assertSame(48, $row['platform_report_clicks']);
        self::assertSame(21.50, $row['platform_report_gains_valid']);
        self::assertSame(4.00, $row['platform_report_gains_pending']);
    }

    public function testPlusieursCampagnes(): void
    {
        $rows = $this->client()->parse($this->xml(
            '<campagne><idc>1</idc><gains_valide>1</gains_valide></campagne>'
            . '<campagne><idc>2</idc><gains_valide>2</gains_valide></campagne>'
        ), '2026-09-09');

        self::assertCount(2, $rows);
        self::assertSame('2', $rows[1]['platform_report_idc']);
    }

    /**
     * Le flux historique est encode en CP1252. Sans conversion, un nom de
     * campagne accentue casse le parseur et fait perdre toute la journee.
     */
    public function testUnNomAccentueEnCp1252NeCassePasLaJournee(): void
    {
        $inner = '<campagne><nom>' . mb_convert_encoding('Assurance santé', 'Windows-1252', 'UTF-8')
            . '</nom><idc>7</idc></campagne>';

        $rows = $this->client()->parse($this->xml($inner), '2026-09-09');

        self::assertCount(1, $rows);
        self::assertSame('Assurance santé', $rows[0]['platform_report_campaign_name']);
    }

    /** Certains montants arrivent a la francaise. */
    public function testUnMontantAVirguleEstLu(): void
    {
        $rows = $this->client()->parse($this->xml(
            '<campagne><idc>1</idc><gains_valide>12,50</gains_valide></campagne>'
        ), '2026-09-09');

        self::assertSame(12.50, $rows[0]['platform_report_gains_valid']);
    }

    public function testChampsAbsentsEtFluxVide(): void
    {
        $rows = $this->client()->parse($this->xml('<campagne><idc>9</idc></campagne>'), '2026-09-09');
        self::assertSame(0, $rows[0]['platform_report_impressions']);
        self::assertSame(0.0, $rows[0]['platform_report_gains_valid']);
        self::assertSame('', $rows[0]['platform_report_sid']);

        self::assertSame([], $this->client()->parse('', '2026-09-09'));
        self::assertSame([], $this->client()->parse('   ', '2026-09-09'));
    }

    public function testUnFluxIllisibleNeLevePas(): void
    {
        self::assertSame([], $this->client()->parse('<reporting><campagne>', '2026-09-09'));
        self::assertSame([], $this->client()->parse('403 Forbidden', '2026-09-09'));
    }

    public function testLeNomDeCampagneEstBorne(): void
    {
        $rows = $this->client()->parse($this->xml(
            '<campagne><nom>' . str_repeat('x', 400) . '</nom><idc>1</idc></campagne>'
        ), '2026-09-09');

        self::assertSame(255, mb_strlen($rows[0]['platform_report_campaign_name']));
    }

    public function testConfigurationIncomplete(): void
    {
        self::assertFalse($this->client()->isConfigured());
        self::assertFalse($this->client(['AFFILIATE_REPORT_URL' => 'https://x'])->isConfigured());
        self::assertTrue($this->client([
            'AFFILIATE_REPORT_URL' => 'https://x',
            'AFFILIATE_REPORT_LOGIN' => 'u',
            'AFFILIATE_REPORT_PASSWORD' => 'p',
        ])->isConfigured());
    }

    /** Sans identifiants, la tache ne doit pas tenter d'appel. */
    public function testFetchDaySansConfigurationRendUnTableauVide(): void
    {
        self::assertSame([], $this->client()->fetchDay('2026-09-09'));
    }
}
