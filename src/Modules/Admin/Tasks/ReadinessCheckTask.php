<?php

declare(strict_types=1);

namespace App\Modules\Admin\Tasks;

use App\Modules\Admin\Services\ReadinessCatalog;
use App\Modules\Admin\Services\ReadinessService;
use Psr\Log\LoggerInterface;

/**
 * Reevaluation des reserves d'ouverture, hors requete HTTP.
 *
 * Sans elle, le premier operateur a ouvrir le back-office apres l'expiration du
 * cache paie l'evaluation : quelques requetes, et surtout les appels au service
 * de contenus juridiques, dont le delai d'attente se compte en secondes quand
 * il ne repond pas. Cette tache tient le cache chaud pour que les ecrans se
 * contentent de le lire.
 *
 * Elle sert aussi de sonde : sa sortie dit, en clair, ce qui empeche d'ouvrir
 * le site au trafic — utile dans un journal de deploiement, ou l'ecran du
 * back-office n'est pas sous les yeux.
 */
final class ReadinessCheckTask
{
    public function __construct(
        private ReadinessService $readiness,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string,string> $options
     * @return array{ok:bool, message:string}
     */
    public function run(array $options = []): array
    {
        $report = $this->readiness->report(true);
        $counts = $report['counts'];

        printf(
            "Reserves d'ouverture : %d ouverte(s) sur %d — %d bloquante(s), %d a traiter, %d en attente.\n",
            $counts['blocking'],
            $counts['total'],
            $counts['blocker'],
            $counts['warning'],
            $counts['info']
        );

        foreach ($report['items'] as $item) {
            if (!$item['blocking']) {
                continue;
            }
            printf(
                "  [%s] %s — %s%s\n",
                ReadinessCatalog::SEVERITIES[(string) $item['severity']] ?? (string) $item['severity'],
                (string) $item['key'],
                (string) $item['title'],
                $item['detail'] !== '' ? ' : ' . (string) $item['detail'] : ''
            );
        }

        $this->logger->info('Reserves d\'ouverture reevaluees', [
            'bloquantes' => $counts['blocker'],
            'ouvertes' => $counts['blocking'],
        ]);

        // `ok` a faux des qu'un point BLOQUANT reste ouvert : bin/cli.php en fait
        // un code de sortie 1, dont une mise en production peut se servir comme
        // garde-fou et qu'un cron remonte.
        return [
            'ok' => $counts['blocker'] === 0,
            'message' => sprintf(
                '%d reserve(s) bloquante(s), %d ouverte(s) sur %d.',
                $counts['blocker'],
                $counts['blocking'],
                $counts['total']
            ),
        ];
    }
}
