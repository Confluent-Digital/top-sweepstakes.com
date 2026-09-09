<?php

declare(strict_types=1);

namespace App\Modules\Drawings\Services;

use App\Modules\Drawings\Models\Repositories\DrawingRepository;
use App\Modules\Sweepstakes\Models\Repositories\SweepstakeRepository;
use Psr\Log\LoggerInterface;

/**
 * Orchestration des tirages.
 *
 * Le modele retenu : participer a un concours ne fait pas gagner de lot, mais
 * donne acces au tirage annuel. A la cloture d'un concours, un **finaliste**
 * est tire parmi ses participants. Une fois par an, le **gagnant** est tire
 * parmi les finalistes de l'annee — c'est lui qui recoit la dotation.
 *
 * Les Official Rules doivent dire exactement cela. Un reglement qui annonce un
 * lot par concours pendant que le systeme n'en attribue qu'un par an est la
 * premiere chose que regarde un procureur d'Etat.
 *
 * **L'ordre des operations n'est pas negociable** : la graine est tiree AVANT
 * la lecture de la liste des participants. Tirer la graine ensuite reviendrait
 * a pouvoir la choisir en fonction du gagnant qu'elle produit — et rien, dans
 * les donnees enregistrees, ne permettrait de le detecter.
 */
final class DrawingService
{
    /**
     * Suppleants tires en meme temps que le gagnant.
     *
     * Le reglement promet un remplacant « selectionne au hasard » si le gagnant
     * ne repond pas. Le designer apres coup, une fois le premier injoignable,
     * ne serait plus du hasard : les suppleants sortent du meme tirage.
     */
    private const ALTERNATES = 3;

    public function __construct(
        private DrawingRepository $drawings,
        private SweepstakeRepository $sweepstakes,
        private DrawingRandomizer $randomizer,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Tire le finaliste d'un concours clos.
     *
     * @return array{ok: bool, message: string, drawing_id?: int, winners?: list<int>}
     */
    public function drawSweepstake(int $sweepstakeId, string $executedBy = 'cli'): array
    {
        $sweepstake = $this->sweepstakes->findById($sweepstakeId);
        if ($sweepstake === null) {
            return ['ok' => false, 'message' => sprintf('Concours %d introuvable.', $sweepstakeId)];
        }

        if ($this->drawings->findForSweepstake($sweepstakeId) !== null) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'Le concours « %s » a deja ete tire. Un second tirage remplacerait un gagnant '
                    . 'deja designe, ce que rien ne justifie.',
                    $sweepstake['sweepstake_name']
                ),
            ];
        }

        $end = $sweepstake['sweepstake_date_end'] ?? null;
        if (!is_string($end) || $end === '') {
            return [
                'ok' => false,
                'message' => 'Ce concours n\'a pas de date de cloture : la liste des participants '
                    . 'n\'est jamais close, donc le tirage ne peut pas etre delimite.',
            ];
        }
        if (substr($end, 0, 10) >= date('Y-m-d')) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'Le concours court jusqu\'au %s. Tirer avant la cloture priverait les '
                    . 'participants restants de leur chance.',
                    substr($end, 0, 10)
                ),
            ];
        }

        // La graine d'abord, la liste ensuite. Voir l'en-tete de classe.
        $seed = $this->randomizer->newSeed();
        $pool = $this->drawings->eligibleForSweepstake($sweepstakeId);

        if ($pool === []) {
            return [
                'ok' => false,
                'message' => sprintf('Aucun participant eligible pour « %s ».', $sweepstake['sweepstake_name']),
            ];
        }

        return $this->execute([
            'drawing_type' => 'sweepstake',
            'drawing_id_sweepstake' => $sweepstakeId,
            'drawing_year' => (int) date('Y', strtotime($end)),
            'drawing_period_start' => $sweepstake['sweepstake_date_start'] ?? substr($end, 0, 10),
            'drawing_period_end' => substr($end, 0, 10),
        ], $pool, $seed, $executedBy, sprintf(
            'Finaliste du concours « %s ». Ne recoit pas de lot : entre au tirage annuel.',
            $sweepstake['sweepstake_name']
        ));
    }

    /**
     * Tire le gagnant de l'annee parmi les finalistes.
     *
     * @return array{ok: bool, message: string, drawing_id?: int, winners?: list<int>}
     */
    public function drawGrandPrize(int $year, string $executedBy = 'cli'): array
    {
        if ($year >= (int) date('Y')) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'L\'annee %d n\'est pas terminee : des concours peuvent encore designer des '
                    . 'finalistes, et les tirer maintenant les exclurait.',
                    $year
                ),
            ];
        }

        if ($this->drawings->findGrandPrize($year) !== null) {
            return ['ok' => false, 'message' => sprintf('Le tirage annuel %d a deja eu lieu.', $year)];
        }

        $seed = $this->randomizer->newSeed();
        $pool = $this->drawings->finalistsForYear($year);

        if ($pool === []) {
            return [
                'ok' => false,
                'message' => sprintf('Aucun finaliste pour %d : aucun concours n\'a ete tire cette annee-la.', $year),
            ];
        }

        return $this->execute([
            'drawing_type' => 'grand_prize',
            'drawing_id_sweepstake' => null,
            'drawing_year' => $year,
            'drawing_period_start' => $year . '-01-01',
            'drawing_period_end' => $year . '-12-31',
        ], $pool, $seed, $executedBy, sprintf(
            'Tirage annuel %d parmi les finalistes des concours de l\'annee. Recoit la dotation.',
            $year
        ));
    }

    /**
     * @param array<string,mixed> $scope
     * @param list<int>           $pool
     * @return array{ok: bool, message: string, drawing_id: int, winners: list<int>}
     */
    private function execute(array $scope, array $pool, string $seed, string $executedBy, string $notes): array
    {
        $winners = $this->randomizer->draw($pool, $seed, 1 + self::ALTERNATES);

        $drawingId = $this->drawings->create($scope + [
            'drawing_seed' => $seed,
            'drawing_pool_hash' => $this->randomizer->poolHash($pool),
            'drawing_pool_size' => count($pool),
            'drawing_winners_count' => count($winners),
            'drawing_executed_at' => date('Y-m-d H:i:s'),
            'drawing_executed_by' => mb_substr($executedBy, 0, 100),
            'drawing_notes' => $notes,
        ]);

        $this->drawings->recordWinners($drawingId, $winners);

        $this->logger->info('Tirage effectue', [
            'drawing_id' => $drawingId,
            'type' => $scope['drawing_type'],
            'pool_size' => count($pool),
            'winner_lead_id' => $winners[0] ?? null,
        ]);

        return [
            'ok' => true,
            'message' => sprintf(
                'Tirage %d : %d participant(s) eligible(s), gagnant #%d et %d suppleant(s).',
                $drawingId,
                count($pool),
                $winners[0] ?? 0,
                max(0, count($winners) - 1)
            ),
            'drawing_id' => $drawingId,
            'winners' => $winners,
        ];
    }

    /**
     * Rejoue un tirage a partir de ce qui a ete enregistre.
     *
     * C'est la reponse a « comment ce gagnant a-t-il ete choisi ? ». Deux
     * verifications distinctes : la liste est-elle celle d'origine
     * (`pool_hash`), et la graine redonne-t-elle le meme gagnant.
     *
     * Une liste qui ne correspond plus n'est pas forcement une fraude — un
     * participant anonymise entre-temps en sort — mais elle empeche de refaire
     * la preuve, et cela doit se dire.
     *
     * @param array<string,mixed> $drawing
     * @param list<int>           $pool liste reconstituee aujourd'hui
     * @return array{pool_matches: bool, winners: list<int>}
     */
    public function replay(array $drawing, array $pool): array
    {
        return [
            'pool_matches' => $this->randomizer->poolHash($pool) === (string) $drawing['drawing_pool_hash'],
            'winners' => $this->randomizer->draw(
                $pool,
                (string) $drawing['drawing_seed'],
                (int) $drawing['drawing_winners_count']
            ),
        ];
    }
}
