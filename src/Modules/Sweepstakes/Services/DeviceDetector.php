<?php

declare(strict_types=1);

namespace App\Modules\Sweepstakes\Services;

/**
 * Type d'appareil, deduit du user agent.
 *
 * Il sert a choisir une variante A/B et a segmenter les statistiques, pas a
 * changer de gabarit : la mise en page est responsive, il n'existe pas de
 * « version mobile » separee.
 */
final class DeviceDetector
{
    public function detect(?string $userAgent): string
    {
        $ua = strtolower(trim((string) $userAgent));
        if ($ua === '') {
            return 'unknown';
        }

        // La tablette se teste avant le mobile : un iPad annonce « Safari » et
        // un Android tablette contient « android » sans « mobile ».
        if (preg_match('/ipad|tablet|kindle|silk|playbook|(android(?!.*mobile))/', $ua) === 1) {
            return 'tablet';
        }
        if (preg_match('/mobile|iphone|ipod|android|blackberry|opera mini|iemobile|webos/', $ua) === 1) {
            return 'mobile';
        }
        return 'desktop';
    }
}
