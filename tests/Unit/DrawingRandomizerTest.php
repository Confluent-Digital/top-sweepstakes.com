<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Modules\Drawings\Services\DrawingRandomizer;
use PHPUnit\Framework\TestCase;

/**
 * Ces tests portent sur la seule propriété qui rend un tirage défendable :
 * pouvoir le rejouer et retrouver le même gagnant.
 */
final class DrawingRandomizerTest extends TestCase
{
    private DrawingRandomizer $randomizer;

    protected function setUp(): void
    {
        $this->randomizer = new DrawingRandomizer();
    }

    /** @return list<int> */
    private function pool(int $size): array
    {
        return range(1, $size);
    }

    /**
     * La garantie principale : mêmes entrées, même sortie. Sans elle, un tirage
     * contesté ne peut pas être vérifié.
     */
    public function testLeTirageEstReproductible(): void
    {
        $pool = $this->pool(500);
        $seed = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

        $first = $this->randomizer->draw($pool, $seed, 5);
        $second = $this->randomizer->draw($pool, $seed, 5);

        self::assertSame($first, $second);
        self::assertCount(5, $first);
    }

    /** Reproductible ne veut pas dire prévisible : la graine change tout. */
    public function testUneGraineDifferenteDonneUnAutreResultat(): void
    {
        $pool = $this->pool(500);

        self::assertNotSame(
            $this->randomizer->draw($pool, 'graine-une', 3),
            $this->randomizer->draw($pool, 'graine-deux', 3)
        );
    }

    /**
     * Le résultat doit dépendre de la liste, pas seulement de la graine —
     * sinon retirer un participant ne changerait rien, et la liste ne serait
     * plus une entrée du tirage.
     */
    public function testUneListeDifferenteDonneUnAutreResultat(): void
    {
        $seed = 'meme-graine-pour-les-deux-tirages';

        self::assertNotSame(
            $this->randomizer->draw($this->pool(500), $seed, 3),
            $this->randomizer->draw($this->pool(400), $seed, 3)
        );
    }

    public function testLesGagnantsSontDistincts(): void
    {
        $winners = $this->randomizer->draw($this->pool(50), 'graine', 10);

        self::assertCount(10, $winners);
        self::assertCount(10, array_unique($winners), 'un participant ne peut pas être tiré deux fois');
    }

    public function testTousLesGagnantsViennentDeLaListe(): void
    {
        $pool = [7, 19, 42, 88, 101];
        foreach ($this->randomizer->draw($pool, 'graine', 3) as $winner) {
            self::assertContains($winner, $pool);
        }
    }

    /** Moins de participants que de gagnants demandés : on rend ce qu'on a. */
    public function testUneListePlusPetiteQueLeNombreDemande(): void
    {
        self::assertCount(3, $this->randomizer->draw([1, 2, 3], 'graine', 10));
    }

    public function testUneListeVideNeRendRien(): void
    {
        self::assertSame([], $this->randomizer->draw([], 'graine', 3));
        self::assertSame([], $this->randomizer->draw([1, 2, 3], 'graine', 0));
    }

    public function testUnSeulParticipant(): void
    {
        self::assertSame([42], $this->randomizer->draw([42], 'graine', 3));
    }

    // ---------------------------------------------------------------- empreinte

    public function testLEmpreinteDeListeEstStable(): void
    {
        $pool = $this->pool(100);
        self::assertSame($this->randomizer->poolHash($pool), $this->randomizer->poolHash($pool));
        self::assertSame(64, strlen($this->randomizer->poolHash($pool)));
    }

    /**
     * C'est la liste, bien plus que la graine, qu'il serait tentant de
     * retoucher : ajouter un participant après coup doit se voir.
     */
    public function testUnParticipantAjouteChangeLEmpreinte(): void
    {
        self::assertNotSame(
            $this->randomizer->poolHash([1, 2, 3]),
            $this->randomizer->poolHash([1, 2, 3, 4])
        );
    }

    public function testLOrdreDeLaListeChangeLEmpreinte(): void
    {
        self::assertNotSame(
            $this->randomizer->poolHash([1, 2, 3]),
            $this->randomizer->poolHash([3, 2, 1])
        );
    }

    // ---------------------------------------------------------------- graine

    public function testChaqueGraineEstUnique(): void
    {
        $seeds = [];
        for ($i = 0; $i < 50; $i++) {
            $seeds[] = $this->randomizer->newSeed();
        }

        self::assertCount(50, array_unique($seeds));
        self::assertSame(32, strlen($seeds[0]));
    }

    /**
     * Le tirage doit être équitable : sur un grand nombre de tirages, chaque
     * participant doit sortir à peu près aussi souvent. Un biais ici
     * favoriserait systématiquement les mêmes personnes.
     */
    public function testLeTirageEstEquitable(): void
    {
        $pool = $this->pool(10);
        $counts = array_fill_keys($pool, 0);

        for ($i = 0; $i < 4000; $i++) {
            $counts[$this->randomizer->draw($pool, 'graine-' . $i, 1)[0]]++;
        }

        // 400 attendus par participant. La borne est large : on cherche un
        // biais grossier, pas à valider la qualité du générateur.
        foreach ($counts as $participant => $count) {
            self::assertGreaterThan(280, $count, "participant $participant trop rarement tiré");
            self::assertLessThan(520, $count, "participant $participant trop souvent tiré");
        }
    }
}
