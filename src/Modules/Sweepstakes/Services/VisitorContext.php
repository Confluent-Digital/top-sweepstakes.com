<?php

declare(strict_types=1);

namespace App\Modules\Sweepstakes\Services;

use App\Core\Session\SessionStore;
use Psr\Http\Message\ServerRequestInterface;
use Random\Randomizer;

/**
 * Ce qu'on sait du visiteur pendant tout son parcours : son identifiant de
 * session, sa source d'acquisition, son appareil, la variante qui lui a ete
 * attribuee et les donnees deja saisies.
 *
 * Deux invariants :
 *
 * 1. **L'attribution se fige a la premiere page.** Un participant arrive avec
 *    un subid doit sortir avec le meme, sinon le revenu est attribue a la
 *    mauvaise source. Les parametres d'une page suivante ne l'ecrasent pas.
 *
 * 2. **La variante A/B se fige aussi.** Un participant qui change de variante
 *    entre deux etapes rend le test ininterpretable. Dans
 *    meilleursconcours.com la variante est stockee dans un cookie d'une heure
 *    et retiree a chaque expiration, en plein parcours.
 */
final class VisitorContext
{
    private const KEY_SESSION_UID = 'visitor_uid';
    private const KEY_ATTRIBUTION = 'attribution';
    private const KEY_DEVICE = 'device';
    private const KEY_VARIANT = 'variant';
    private const KEY_LEAD = 'lead';

    /** Parametres d'acquisition captures a l'entree. */
    private const ATTRIBUTION_PARAMS = [
        'source', 'subid', 'clickid',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'fbclid', 'gclid', 'ttclid', 'msclkid',
    ];

    private Randomizer $randomizer;

    public function __construct(
        private SessionStore $session,
        private DeviceDetector $devices,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * A appeler sur chaque page publique. Ne capture l'attribution que si elle
     * ne l'a pas deja ete.
     */
    public function bootstrap(ServerRequestInterface $request): void
    {
        if (!$this->session->has(self::KEY_SESSION_UID)) {
            $this->session->set(self::KEY_SESSION_UID, bin2hex(random_bytes(16)));
        }

        if (!$this->session->has(self::KEY_ATTRIBUTION)) {
            $this->session->set(self::KEY_ATTRIBUTION, $this->captureAttribution($request));
        }

        if (!$this->session->has(self::KEY_DEVICE)) {
            $ua = $request->getHeaderLine('User-Agent');
            $this->session->set(self::KEY_DEVICE, $this->devices->detect($ua));
        }
    }

    /** @return array<string,string> */
    private function captureAttribution(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();
        $attribution = [];
        foreach (self::ATTRIBUTION_PARAMS as $param) {
            $value = $query[$param] ?? '';
            $attribution[$param] = is_string($value) ? mb_substr(trim($value), 0, 255) : '';
        }

        // Le referer complete la source quand aucun parametre n'est passe :
        // c'est le seul indice pour du trafic organique ou d'un lien nu.
        $attribution['referer'] = mb_substr($request->getHeaderLine('Referer'), 0, 500);

        // Un subid vide mais un clickid present : la regie n'a pas passe le
        // subid, on garde le clickid comme identifiant de session publicitaire.
        if ($attribution['subid'] === '' && $attribution['clickid'] !== '') {
            $attribution['subid'] = $attribution['clickid'];
        }

        return $attribution;
    }

    public function sessionUid(): string
    {
        return (string) $this->session->get(self::KEY_SESSION_UID, '');
    }

    /** @return array<string,string> */
    public function attribution(): array
    {
        $stored = $this->session->get(self::KEY_ATTRIBUTION, []);
        return is_array($stored) ? $stored : [];
    }

    public function subid(): string
    {
        return (string) ($this->attribution()['subid'] ?? '');
    }

    public function device(): string
    {
        return (string) $this->session->get(self::KEY_DEVICE, 'unknown');
    }

    /**
     * Variante attribuee pour ce concours. Tiree une seule fois, au poids, puis
     * conservee. Rend 0 quand le concours n'a pas de variante active.
     *
     * @param list<array<string,mixed>> $variants
     */
    public function variantFor(int $sweepstakeId, array $variants): int
    {
        $key = self::KEY_VARIANT . ':' . $sweepstakeId;
        $stored = $this->session->get($key);

        if (is_int($stored) || (is_string($stored) && $stored !== '')) {
            $storedId = (int) $stored;
            // La variante memorisee peut avoir ete desactivee entre-temps : on
            // ne la conserve que si elle est toujours proposee.
            if ($storedId === 0 || $this->containsVariant($variants, $storedId)) {
                return $storedId;
            }
        }

        $chosen = $this->drawVariant($variants);
        $this->session->set($key, $chosen);
        return $chosen;
    }

    /** @param list<array<string,mixed>> $variants */
    private function containsVariant(array $variants, int $id): bool
    {
        foreach ($variants as $variant) {
            if ((int) ($variant['sweepstake_variant_id'] ?? 0) === $id) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array<string,mixed>> $variants */
    private function drawVariant(array $variants): int
    {
        $total = 0;
        foreach ($variants as $variant) {
            $total += max(0, (int) ($variant['sweepstake_variant_weight'] ?? 0));
        }
        if ($total <= 0) {
            return 0;
        }

        $draw = $this->randomizer->getInt(1, $total);
        $cumulative = 0;
        foreach ($variants as $variant) {
            $cumulative += max(0, (int) ($variant['sweepstake_variant_weight'] ?? 0));
            if ($draw <= $cumulative) {
                return (int) ($variant['sweepstake_variant_id'] ?? 0);
            }
        }
        return 0;
    }

    /**
     * Donnees de formulaire deja saisies pour ce concours. Le tunnel se fait en
     * deux etapes : la premiere est conservee en session le temps de la seconde.
     *
     * @return array<string,string>
     */
    public function lead(int $sweepstakeId): array
    {
        $stored = $this->session->get(self::KEY_LEAD . ':' . $sweepstakeId, []);
        return is_array($stored) ? $stored : [];
    }

    /** @param array<string,string> $values */
    public function mergeLead(int $sweepstakeId, array $values): void
    {
        $this->session->set(self::KEY_LEAD . ':' . $sweepstakeId, $values + $this->lead($sweepstakeId));
    }

    public function forgetLead(int $sweepstakeId): void
    {
        $this->session->remove(self::KEY_LEAD . ':' . $sweepstakeId);
    }

    /** Identifiant du participant enregistre, une fois le tunnel valide. */
    public function leadId(int $sweepstakeId): ?int
    {
        $id = $this->session->get('lead_id:' . $sweepstakeId);
        return $id === null ? null : (int) $id;
    }

    public function setLeadId(int $sweepstakeId, int $leadId): void
    {
        $this->session->set('lead_id:' . $sweepstakeId, $leadId);
    }
}
