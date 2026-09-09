<?php

declare(strict_types=1);

namespace App\Modules\Drawings\Tasks;

use App\Modules\Drawings\Models\Repositories\DrawingRepository;
use App\Modules\Drawings\Services\DrawingService;

/**
 * Tirages en ligne de commande.
 *
 * **Aucun tirage n'est automatique.** Un cron qui tirerait les concours clos
 * chaque nuit designerait des gagnants sans que personne ne l'ait decide, et
 * sans que personne ne sache quand. Un tirage est un acte : il se declenche,
 * il est trace, et son auteur est enregistre.
 *
 * La tache liste en revanche ce qui attend d'etre tire, pour qu'on ne
 * l'oublie pas.
 */
final class DrawingTask
{
    public function __construct(
        private DrawingService $service,
        private DrawingRepository $drawings,
    ) {
    }

    /**
     * @param array<string,string> $options
     * @return array{ok: bool, message: string}
     */
    public function run(array $options): array
    {
        if (isset($options['pending'])) {
            return $this->listPending();
        }

        if (isset($options['grand-prize'])) {
            $year = (int) ($options['year'] ?? (date('Y') - 1));
            return $this->summarize($this->service->drawGrandPrize($year, 'cli'));
        }

        $sweepstakeId = (int) ($options['sweepstake'] ?? 0);
        if ($sweepstakeId > 0) {
            return $this->summarize($this->service->drawSweepstake($sweepstakeId, 'cli'));
        }

        return [
            'ok' => false,
            'message' => 'Precisez --sweepstake=<id>, --grand-prize [--year=YYYY], ou --pending '
                . 'pour lister les concours en attente de tirage.',
        ];
    }

    /** @return array{ok: bool, message: string} */
    private function listPending(): array
    {
        $pending = $this->drawings->sweepstakesAwaitingDrawing();

        if ($pending === []) {
            return ['ok' => true, 'message' => 'Aucun concours clos en attente de tirage.'];
        }

        $lines = [sprintf('%d concours clos attendent leur tirage :', count($pending))];
        foreach ($pending as $row) {
            $lines[] = sprintf(
                '  #%d  %-28s clos le %s',
                $row['sweepstake_id'],
                mb_substr((string) $row['sweepstake_name'], 0, 28),
                substr((string) $row['sweepstake_date_end'], 0, 10)
            );
        }

        return ['ok' => true, 'message' => implode("\n", $lines)];
    }

    /**
     * @param array{ok: bool, message: string, winners?: list<int>} $result
     * @return array{ok: bool, message: string}
     */
    private function summarize(array $result): array
    {
        return ['ok' => $result['ok'], 'message' => $result['message']];
    }
}
