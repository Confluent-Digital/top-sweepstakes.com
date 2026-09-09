<?php

declare(strict_types=1);

namespace App\Modules\Platform\Tasks;

use App\Modules\Platform\Models\Repositories\PlatformReportRepository;
use App\Modules\Platform\Services\PlatformReportClient;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Tire le flux de reporting de la regie.
 *
 * Par defaut sur les trois derniers jours et pas seulement la veille : la
 * regie revise ses chiffres pendant plusieurs jours (validations, annulations),
 * et l'ecriture est un upsert, donc rejouer est sans effet de bord.
 */
final class PlatformReportTask
{
    private const DEFAULT_LOOKBACK_DAYS = 3;

    public function __construct(
        private PlatformReportClient $client,
        private PlatformReportRepository $repository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string,string> $options
     * @return array{ok: bool, message: string}
     */
    public function run(array $options): array
    {
        if (!$this->client->isConfigured()) {
            return [
                'ok' => false,
                'message' => 'Flux de reporting non configure : renseigner AFFILIATE_REPORT_URL, '
                    . 'AFFILIATE_REPORT_LOGIN et AFFILIATE_REPORT_PASSWORD dans le .env.',
            ];
        }

        $to = $this->date($options['to'] ?? null) ?? new DateTimeImmutable('today');
        $from = $this->date($options['from'] ?? null)
            ?? $to->modify('-' . (self::DEFAULT_LOOKBACK_DAYS - 1) . ' days');

        if ($from > $to) {
            return ['ok' => false, 'message' => '--from est posterieur a --to.'];
        }

        $days = 0;
        $rowsWritten = 0;
        $emptyDays = [];

        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $rows = $this->client->fetchDay($date);
            $days++;

            if ($rows === []) {
                $emptyDays[] = $date;
                continue;
            }
            $rowsWritten += $this->repository->upsertMany($rows);
        }

        // Une journee vide n'est pas anormale en soi, mais toutes les journees
        // vides le sont : c'est le symptome d'identifiants invalides ou d'un
        // format de flux qui a change.
        if ($emptyDays !== [] && count($emptyDays) === $days) {
            $this->logger->error('Flux de reporting : aucune ligne sur toute la periode', [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ]);
            return [
                'ok' => false,
                'message' => sprintf(
                    'Aucune ligne recuperee sur %d jour(s). Verifier les identifiants et le format du flux.',
                    $days
                ),
            ];
        }

        return [
            'ok' => true,
            'message' => sprintf(
                '%d jour(s) traite(s), %d ligne(s) ecrite(s)%s.',
                $days,
                $rowsWritten,
                $emptyDays === [] ? '' : ' — sans donnee : ' . implode(', ', $emptyDays)
            ),
        ];
    }

    private function date(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        return $date === false ? null : $date;
    }
}
