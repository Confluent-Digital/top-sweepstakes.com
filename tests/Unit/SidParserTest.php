<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Modules\Stats\Services\SidParser;
use PHPUnit\Framework\TestCase;

final class SidParserTest extends TestCase
{
    private SidParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SidParser();
    }

    public function testLectureNominale(): void
    {
        $md5 = str_repeat('a', 32);
        self::assertSame(
            ['sweepstake_id' => 12, 'subid' => 'aff42', 'email_md5' => $md5, 'date' => '2026-09-09'],
            $this->parser->parse('12_aff42_' . $md5 . '_2026-09-09')
        );
    }

    public function testLesSegmentsVidesSontRendusVides(): void
    {
        $parsed = $this->parser->parse('12_-_-_2026-09-09');
        self::assertSame('', $parsed['subid']);
        self::assertSame('', $parsed['email_md5']);
    }

    /**
     * Un sid illisible est signale, pas devine : deviner attribuerait un revenu
     * au mauvais concours ou a la mauvaise source, sans que rien ne le dise.
     */
    public function testUnSidIllisibleRendNull(): void
    {
        foreach ([
            '',
            '12_aff42',
            '12_aff42_abc_2026-09-09_extra',
            'abc_aff42_abc_2026-09-09',
            '12_aff42_abc_09/09/2026',
            '12_aff42_abc_pas-une-date',
        ] as $sid) {
            self::assertNull($this->parser->parse($sid), "attendu illisible : '$sid'");
        }
    }

    public function testLesEspacesDeBordSontIgnores(): void
    {
        self::assertNotNull($this->parser->parse('  12_aff42_abc_2026-09-09  '));
    }
}
