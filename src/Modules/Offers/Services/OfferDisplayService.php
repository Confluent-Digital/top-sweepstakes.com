<?php

declare(strict_types=1);

namespace App\Modules\Offers\Services;

use App\Core\Signer;
use App\Modules\Offers\Models\Repositories\OfferRepository;
use App\Modules\Tracking\Models\Repositories\OfferEventRepository;

/**
 * Prepare les blocs d'offres a afficher et enregistre leurs impressions.
 *
 * **Une offre retenue produit exactement une impression**, ecrite ici, au
 * moment du rendu. Ni le gabarit ni le JavaScript n'en ecrivent : c'est ce qui
 * garantit le compte. Dans meilleursconcours.com, l'impression est declenchee
 * en JavaScript par element du DOM portant un identifiant d'offre — un display
 * coupon, qui en porte trois, compte donc trois impressions pour une seule
 * offre, et les eCPM calcules la-dessus pilotent l'arbitrage.
 *
 * Voir .claude/rules/offers-display.md.
 */
final class OfferDisplayService
{
    /** Nombre d'offres par bloc quand le bloc n'en fixe pas. */
    private const DEFAULT_BLOCK_SIZE = 6;

    public function __construct(
        private OfferRepository $offers,
        private OfferSelector $selector,
        private OfferLinkBuilder $links,
        private OfferEventRepository $events,
        private Signer $signer,
    ) {
    }

    /**
     * @param array<string,mixed> $sweepstake
     * @param array<string,mixed> $participant contexte de ciblage
     * @return list<array{block: array<string,mixed>|null, offers: list<array<string,mixed>>}>
     */
    public function buildBlocks(
        array $sweepstake,
        int $variantId,
        int $step,
        array $participant,
        string $sessionUid,
        ?int $leadId,
        string $device,
        string $subid,
    ): array {
        $sweepstakeId = (int) $sweepstake['sweepstake_id'];
        $candidates = $this->offers->findCandidates($sweepstakeId, $variantId, $step);
        if ($candidates === []) {
            return [];
        }

        $blocks = $this->offers->findBlocks($sweepstakeId);
        $grouped = $this->groupByBlock($candidates, $blocks);

        $rendered = [];
        $events = [];
        $position = 0;

        foreach ($grouped as $group) {
            $block = $group['block'];
            $limit = $block !== null
                ? (int) ($block['offer_block_max_offers'] ?? self::DEFAULT_BLOCK_SIZE)
                : self::DEFAULT_BLOCK_SIZE;

            $selected = $this->selector->select($group['offers'], $participant, $limit);
            if ($selected === []) {
                continue;
            }

            $presented = [];
            foreach ($selected as $offer) {
                $position++;
                $presented[] = $this->present($offer, $sessionUid, $position, $sweepstakeId);
                $events[] = [
                    'offer_event_session_uid' => $sessionUid,
                    'offer_event_id_lead' => $leadId,
                    'offer_event_id_sweepstake' => $sweepstakeId,
                    'offer_event_id_variant' => $variantId,
                    'offer_event_id_offer' => (int) $offer['offer_id'],
                    'offer_event_id_block' => $block !== null ? (int) $block['offer_block_id'] : null,
                    'offer_event_step' => $step,
                    'offer_event_position' => $position,
                    'offer_event_device' => $device,
                    'offer_event_action' => 'impression',
                    'offer_event_subid' => $subid,
                    'created_day' => date('Y-m-d'),
                ];
            }

            $rendered[] = ['block' => $block, 'offers' => $presented];
        }

        // Une seule requete pour toutes les impressions du rendu.
        $this->events->recordMany($events);

        return $rendered;
    }

    /**
     * Donnees strictement necessaires au gabarit. L'URL de la regie n'y figure
     * pas : le gabarit ne connait que le lien interne `/out/...`.
     *
     * @param array<string,mixed> $offer
     * @return array<string,mixed>
     */
    private function present(array $offer, string $sessionUid, int $position, int $sweepstakeId): array
    {
        return [
            'id' => (int) $offer['offer_id'],
            'type' => (string) $offer['offer_type'],
            'name' => (string) $offer['offer_name'],
            'image' => (string) $offer['offer_image'],
            'headline' => (string) $offer['offer_headline'],
            'text_html' => (string) ($offer['offer_text_html'] ?? ''),
            'cta_label' => (string) $offer['offer_cta_label'] !== ''
                ? (string) $offer['offer_cta_label']
                : 'See offer',
            'target_blank' => (bool) $offer['offer_target_blank'],
            'position' => $position,
            // Le concours est passe en clair : il sert a retrouver le
            // participant de cette session, pas a decider de la destination.
            'out_url' => '/out/' . $this->token((int) $offer['offer_id'], $sessionUid)
                . '?s=' . $sweepstakeId,
        ];
    }

    /**
     * Jeton de sortie : identifiant d'offre et signature HMAC liee a la
     * session. Sans lui, un identifiant sequentiel dans `/out/` permettrait de
     * parcourir le catalogue d'offres et de fabriquer des clics.
     */
    public function token(int $offerId, string $sessionUid): string
    {
        return $offerId . '-' . $this->signer->sign($offerId . '|' . $sessionUid);
    }

    /** Rend l'identifiant d'offre si le jeton est valide pour cette session. */
    public function resolveToken(string $token, string $sessionUid): ?int
    {
        $parts = explode('-', $token, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0])) {
            return null;
        }
        $offerId = (int) $parts[0];
        if (!$this->signer->verify($offerId . '|' . $sessionUid, $parts[1])) {
            return null;
        }
        return $offerId;
    }

    /**
     * URL de sortie reelle, construite au moment du clic et jamais exposee
     * dans le HTML.
     *
     * @param array<string,mixed> $offer
     * @param array<string,mixed> $context
     */
    public function outboundUrl(array $offer, array $context): string
    {
        return $this->links->build($offer, $context);
    }

    /**
     * Regroupe les offres par bloc, en preservant l'ordre des blocs. Les offres
     * sans bloc forment un groupe final sans titre.
     *
     * @param list<array<string,mixed>> $candidates
     * @param list<array<string,mixed>> $blocks
     * @return list<array{block: array<string,mixed>|null, offers: list<array<string,mixed>>}>
     */
    private function groupByBlock(array $candidates, array $blocks): array
    {
        $byBlock = [];
        $orphans = [];

        foreach ($candidates as $offer) {
            $blockId = $offer['block_id'] ?? null;
            if ($blockId === null) {
                $orphans[] = $offer;
                continue;
            }
            $byBlock[(int) $blockId][] = $offer;
        }

        $groups = [];
        foreach ($blocks as $block) {
            $id = (int) $block['offer_block_id'];
            if (!empty($byBlock[$id])) {
                $groups[] = ['block' => $block, 'offers' => $byBlock[$id]];
            }
        }
        if ($orphans !== []) {
            $groups[] = ['block' => null, 'offers' => $orphans];
        }

        return $groups;
    }
}
