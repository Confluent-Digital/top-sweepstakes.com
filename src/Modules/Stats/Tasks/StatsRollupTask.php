<?php

declare(strict_types=1);

namespace App\Modules\Stats\Tasks;

use App\Modules\Offers\Models\Repositories\OfferRepository;
use App\Modules\Platform\Models\Repositories\PlatformReportRepository;
use App\Modules\Stats\Models\Repositories\StatsRepository;
use App\Modules\Stats\Services\SidParser;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Agrege l'evenementiel, croise avec les revenus de la regie, recalcule les eCPM.
 *
 * Le croisement se fait en deux temps :
 *  - `idc` relie une ligne du flux a une de nos offres ;
 *  - le `sid` fournit le concours et la source.
 *
 * Un revenu dont l'`idc` ne correspond a aucune offre, ou dont le `sid` est
 * illisible, est **compte comme non attribue** et journalise. Le repartir au
 * prorata reviendrait a inventer des chiffres qui serviront ensuite a arbitrer.
 */
final class StatsRollupTask
{
    private const DEFAULT_LOOKBACK_DAYS = 3;

    public function __construct(
        private StatsRepository $stats,
        private PlatformReportRepository $platform,
        private OfferRepository $offers,
        private SidParser $sids,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string,string> $options
     * @return array{ok: bool, message: string}
     */
    public function run(array $options): array
    {
        $to = $this->date($options['date'] ?? $options['to'] ?? null) ?? new DateTimeImmutable('today');
        $from = $this->date($options['from'] ?? null)
            ?? $to->modify('-' . (self::DEFAULT_LOOKBACK_DAYS - 1) . ' days');

        $aggregated = 0;
        $revenueRows = 0;
        $unattributed = 0.0;

        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');

            $aggregated += $this->stats->rollupDay($date);
            $result = $this->buildRevenue($date);
            $revenueRows += $this->stats->upsertRevenue($result['rows']);
            $unattributed += $result['unattributed'];
        }

        $this->stats->refreshOfferEcpm();

        return [
            'ok' => true,
            'message' => sprintf(
                'Agregat : %d ligne(s) sur la periode. Revenus : %d ligne(s). eCPM recalcules.%s',
                $aggregated,
                $revenueRows,
                $unattributed > 0
                    ? sprintf(' ⚠ %.2f de revenu non attribue — voir logs/app.log.', $unattributed)
                    : ''
            ),
        ];
    }

    /**
     * @return array{rows: list<array<string,mixed>>, unattributed: float}
     */
    private function buildRevenue(string $date): array
    {
        $traffic = $this->stats->trafficByDay($date);
        if ($traffic === []) {
            return ['rows' => [], 'unattributed' => 0.0];
        }

        $offersByIdc = $this->offers->idcToOfferId();
        $revenue = [];
        $unattributed = 0.0;

        foreach ($this->platform->revenueByDay($date) as $line) {
            $gains = (float) $line['gains_valid'] + (float) $line['gains_pending'];
            if ($gains <= 0.0) {
                continue;
            }

            $idc = (string) $line['idc'];
            $offerId = $offersByIdc[$idc] ?? null;
            $parsed = $this->sids->parse((string) $line['sid']);

            if ($offerId === null || $parsed === null) {
                $unattributed += $gains;
                $this->logger->warning('Revenu non attribue', [
                    'date' => $date,
                    'idc' => $idc,
                    'sid' => $line['sid'],
                    'gains' => $gains,
                    'raison' => $offerId === null ? 'idc inconnu' : 'sid illisible',
                ]);
                continue;
            }

            $key = $offerId . '|' . $parsed['sweepstake_id'] . '|' . $parsed['subid'];
            $revenue[$key] = ($revenue[$key] ?? 0.0) + $gains;
        }

        $rows = [];
        foreach ($traffic as $row) {
            $key = $row['offer_id'] . '|' . $row['sweepstake_id'] . '|' . $row['subid'];
            $impressions = (int) $row['impressions'];
            $amount = $revenue[$key] ?? 0.0;
            unset($revenue[$key]);

            $rows[] = [
                'date' => $date,
                'offer_id' => (int) $row['offer_id'],
                'sweepstake_id' => (int) $row['sweepstake_id'],
                'subid' => (string) $row['subid'],
                'impressions' => $impressions,
                'clicks' => (int) $row['clicks'],
                'revenue' => $amount,
                'ecpm' => $impressions > 0 ? round($amount / $impressions * 1000, 4) : 0.0,
            ];
        }

        // Revenu rattache a une offre et a un concours, mais sans trafic
        // correspondant de notre cote : il est conserve tel quel, sans
        // impression, plutot que perdu. Un eCPM ne peut pas s'en deduire.
        foreach ($revenue as $key => $amount) {
            [$offerId, $sweepstakeId, $subid] = explode('|', $key, 3);
            $rows[] = [
                'date' => $date,
                'offer_id' => (int) $offerId,
                'sweepstake_id' => (int) $sweepstakeId,
                'subid' => $subid,
                'impressions' => 0,
                'clicks' => 0,
                'revenue' => $amount,
                'ecpm' => 0.0,
            ];
        }

        return ['rows' => $rows, 'unattributed' => $unattributed];
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
