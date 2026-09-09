<?php

declare(strict_types=1);

namespace App\Modules\Offers\Services;

use App\Core\Signer;
use App\Modules\Offers\Models\Repositories\OfferRepository;
use App\Modules\Tracking\Models\Repositories\OfferEventRepository;

/**
 * Prepare le parcours d'offres et enregistre les impressions.
 *
 * Les offres sont presentees **une par une**, chacune sur sa page. Deux
 * consequences sur le comptage, et ce sont elles qui justifient la separation
 * en deux temps de cette classe :
 *
 *  1. La sequence est choisie **une seule fois**, a l'entree du parcours, puis
 *     figee en session par l'appelant. Sans cela, l'ordre serait retire a
 *     chaque page : le visiteur reverrait deux fois la meme offre, et l'ordre
 *     par eCPM ne voudrait plus rien dire.
 *
 *  2. L'impression est enregistree **a l'affichage de la page**, pas a la
 *     constitution de la sequence. Une offre placee en cinquieme position, que
 *     le visiteur n'atteint jamais parce qu'il abandonne en troisieme, ne doit
 *     compter aucune impression — sinon son eCPM s'effondre sans qu'elle ait
 *     jamais ete vue, et l'arbitrage la relegue a tort.
 *
 * Voir .claude/rules/offers-display.md.
 */
final class OfferDisplayService
{
    public function __construct(
        private OfferRepository $offers,
        private OfferSelector $selector,
        private OfferLinkBuilder $links,
        private OfferEventRepository $events,
        private Signer $signer,
    ) {
    }

    /**
     * Sequence d'offres pour ce participant, dans l'ordre d'affichage.
     *
     * N'enregistre rien : c'est un choix, pas une exposition.
     *
     * @param array<string,mixed> $sweepstake
     * @param array<string,mixed> $participant contexte de ciblage
     * @return list<int> identifiants d'offres
     */
    public function selectSequence(array $sweepstake, int $variantId, array $participant, int $step = 3): array
    {
        $limit = (int) ($sweepstake['sweepstake_offer_steps'] ?? 0);
        if ($limit <= 0) {
            return [];
        }

        $candidates = $this->offers->findCandidates((int) $sweepstake['sweepstake_id'], $variantId, $step);
        if ($candidates === []) {
            return [];
        }

        return array_map(
            static fn(array $offer): int => (int) $offer['offer_id'],
            $this->selector->select($candidates, $participant, $limit)
        );
    }

    /**
     * Donnees d'affichage d'une offre. L'URL de la regie n'y figure pas : le
     * gabarit ne connait que le lien interne `/out/...`.
     *
     * @return array<string,mixed>|null
     */
    public function presentOffer(int $offerId, string $sessionUid, int $position, int $sweepstakeId): ?array
    {
        $offer = $this->offers->findById($offerId);
        if ($offer === null || !(bool) $offer['offer_active']) {
            return null;
        }

        return [
            'id' => (int) $offer['offer_id'],
            'type' => (string) $offer['offer_type'],
            'name' => (string) $offer['offer_name'],
            'advertiser' => (string) $offer['offer_advertiser'],
            'image' => (string) $offer['offer_image'],
            // Dimensions reelles du fichier, relevees au televersement : le
            // gabarit les declare pour que la creation ne repousse pas les
            // boutons au chargement. 0 quand le visuel precede la migration.
            'image_width' => (int) ($offer['offer_image_width'] ?? 0),
            'image_height' => (int) ($offer['offer_image_height'] ?? 0),
            'headline' => (string) $offer['offer_headline'],
            'text_html' => (string) ($offer['offer_text_html'] ?? ''),
            'cta_label' => (string) $offer['offer_cta_label'] !== ''
                ? (string) $offer['offer_cta_label']
                : 'Yes, show me this',
            'target_blank' => (bool) $offer['offer_target_blank'],
            'position' => $position,
            'out_url' => '/out/' . $this->token((int) $offer['offer_id'], $sessionUid) . '?s=' . $sweepstakeId,
        ];
    }

    /**
     * Enregistre l'exposition d'une offre : une ligne, une seule.
     *
     * L'appelant garantit qu'une etape deja vue n'est pas recomptee — un
     * rechargement de page ne cree pas une seconde impression.
     */
    public function recordImpression(
        int $offerId,
        int $sweepstakeId,
        int $variantId,
        ?int $leadId,
        string $sessionUid,
        int $position,
        string $device,
        string $subid,
        int $step = 3,
    ): void {
        $this->events->record([
            'offer_event_session_uid' => $sessionUid,
            'offer_event_id_lead' => $leadId,
            'offer_event_id_sweepstake' => $sweepstakeId,
            'offer_event_id_variant' => $variantId,
            'offer_event_id_offer' => $offerId,
            'offer_event_id_block' => null,
            'offer_event_step' => $step,
            'offer_event_position' => $position,
            'offer_event_device' => $device,
            'offer_event_action' => 'impression',
            'offer_event_subid' => $subid,
            'created_day' => date('Y-m-d'),
        ]);
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
}
