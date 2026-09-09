<?php

declare(strict_types=1);

namespace App\Modules\Drawings\Services;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Le tirage proprement dit.
 *
 * Isole du reste pour une raison precise : c'est la seule partie du systeme qui
 * doit pouvoir etre **rejouee des mois plus tard** et rendre exactement le meme
 * resultat. Un tirage qu'on ne peut pas reproduire n'est pas verifiable, et un
 * tirage non verifiable ne vaut rien devant une contestation.
 *
 * Deux garanties :
 *
 * 1. **La graine est fournie de l'exterieur**, tiree avant que la liste des
 *    participants ne soit lue. Tirer la graine apres reviendrait a pouvoir la
 *    choisir en fonction du resultat qu'elle produit.
 *
 * 2. **Le resultat ne depend que de la graine et de la liste**, dans cet ordre.
 *    Aucune horloge, aucun etat global, aucun appel a random_int() : deux
 *    executions avec les memes entrees rendent la meme sortie, sur n'importe
 *    quelle machine.
 */
final class DrawingRandomizer
{
    /**
     * Tire `$count` participants distincts, dans l'ordre.
     *
     * Le premier est le gagnant, les suivants sont ses suppleants. Ils sont
     * tires dans le meme geste : le reglement promet un remplacant
     * « selectionne au hasard », et en designer un apres coup, une fois le
     * premier injoignable, ne serait plus du hasard.
     *
     * @param list<int> $pool identifiants eligibles, deja ordonnes
     * @return list<int>
     */
    public function draw(array $pool, string $seed, int $count): array
    {
        if ($pool === [] || $count < 1) {
            return [];
        }

        $randomizer = new Randomizer(new Xoshiro256StarStar($this->engineSeed($seed)));

        /** @var list<int> $shuffled */
        $shuffled = $randomizer->shuffleArray(array_values($pool));

        return array_slice($shuffled, 0, min($count, count($shuffled)));
    }

    /**
     * Empreinte de la liste des eligibles.
     *
     * Conservee avec le tirage, elle prouve **sur quelle liste** il a porte.
     * Sans elle, on saurait rejouer le tirage mais pas verifier qu'on le rejoue
     * sur les memes participants — et c'est la liste, bien plus que la graine,
     * qu'il serait tentant de retoucher.
     *
     * @param list<int> $pool
     */
    public function poolHash(array $pool): string
    {
        return hash('sha256', implode(',', $pool));
    }

    /** Graine imprevisible, a tirer AVANT de lire la liste des participants. */
    public function newSeed(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Xoshiro256** attend 32 octets de graine. On derive de facon
     * deterministe : la meme chaine donnera toujours le meme etat initial, y
     * compris dans plusieurs annees et sur une autre machine.
     */
    private function engineSeed(string $seed): string
    {
        return hash('sha256', 'tsw-drawing:' . $seed, true);
    }
}
