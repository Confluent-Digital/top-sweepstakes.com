<?php

declare(strict_types=1);

namespace App\Modules\Offers\Services;

use DateTimeImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Choisit les offres a afficher pour un participant donne.
 *
 * Trois etapes, dans cet ordre : ecarter ce qui n'est pas diffusable, classer
 * ce qui rapporte, reserver quelques emplacements aux offres qu'on ne connait
 * pas encore.
 *
 * La reference negative est `getPartenairesToShow()` de meilleursconcours.com :
 * cinq cent cinquante lignes dont deux cent cinquante commentees, des quotas
 * codes en dur par type de partenaire, et des `echo` de debug en plein rendu.
 * Voir .claude/rules/offers-display.md.
 */
final class OfferSelector
{
    /**
     * En dessous de ce nombre d'impressions cumulees, l'eCPM d'une offre n'est
     * pas significatif : elle passe par l'exploration plutot que par le tri.
     * Sans cela une offre nouvelle, dont l'eCPM vaut zero, ne serait jamais
     * affichee et ne pourrait donc jamais accumuler d'historique.
     */
    public const EXPLORATION_THRESHOLD = 1000;

    /** Part des emplacements reservee aux offres encore non mesurees. */
    public const EXPLORATION_RATE = 0.2;

    private Randomizer $randomizer;

    public function __construct(
        private TargetingService $targeting,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /** Fabrique un selecteur au tirage reproductible, pour les tests. */
    public static function seeded(TargetingService $targeting, int $seed): self
    {
        return new self($targeting, new Randomizer(new Mt19937($seed)));
    }

    /**
     * @param list<array<string,mixed>> $candidates offres, avec leurs regles sous
     *        `targeting_rules` et leurs compteurs sous `impressions_today` / `impressions_total`
     * @param array<string,mixed>       $context    participant
     * @return list<array<string,mixed>>
     */
    public function select(array $candidates, array $context, int $limit, ?DateTimeImmutable $now = null): array
    {
        if ($limit <= 0) {
            return [];
        }
        $now ??= new DateTimeImmutable('today');

        $eligible = array_values(array_filter(
            $candidates,
            fn(array $offer): bool => $this->isEligible($offer, $context, $now)
        ));
        if ($eligible === []) {
            return [];
        }

        [$measured, $unmeasured] = $this->splitByExperience($eligible);

        usort($measured, fn(array $a, array $b): int => $this->score($b) <=> $this->score($a));
        $unmeasured = $this->shuffle($unmeasured);

        return $this->interleave($measured, $unmeasured, $limit);
    }

    /**
     * @param array<string,mixed> $offer
     * @param array<string,mixed> $context
     */
    private function isEligible(array $offer, array $context, DateTimeImmutable $now): bool
    {
        if (!(bool) ($offer['offer_active'] ?? false)) {
            return false;
        }

        // Une offre sans identifiant de crea ne peut pas etre facturee :
        // l'afficher consommerait des impressions pour rien. On l'ecarte ici
        // pour qu'OfferLinkBuilder n'ait jamais a la traiter.
        if (trim((string) ($offer['offer_platform_idv'] ?? '')) === '') {
            return false;
        }

        if (!$this->isWithinSchedule($offer, $now)) {
            return false;
        }

        $country = strtoupper(trim((string) ($offer['offer_country'] ?? '')));
        $target = strtoupper(trim((string) ($context['country'] ?? 'US')));
        if ($country !== '' && $target !== '' && $country !== $target) {
            return false;
        }

        if ($this->isCapped($offer)) {
            return false;
        }

        $rules = $offer['targeting_rules'] ?? [];
        return $this->targeting->matches(is_array($rules) ? array_values($rules) : [], $context);
    }

    /** @param array<string,mixed> $offer */
    private function isWithinSchedule(array $offer, DateTimeImmutable $now): bool
    {
        $today = $now->format('Y-m-d');
        $start = trim((string) ($offer['offer_date_start'] ?? ''));
        $end = trim((string) ($offer['offer_date_end'] ?? ''));

        if ($start !== '' && $today < substr($start, 0, 10)) {
            return false;
        }
        if ($end !== '' && $today > substr($end, 0, 10)) {
            return false;
        }
        return true;
    }

    /**
     * Les plafonds sont verifies A L'AFFICHAGE. Dans l'implementation
     * historique ils ne s'appliquent qu'au cron d'envoi : une offre plafonnee
     * continue de s'afficher et consomme des impressions qui ne seront jamais
     * payees.
     *
     * @param array<string,mixed> $offer
     */
    private function isCapped(array $offer): bool
    {
        $capDay = $offer['offer_cap_day'] ?? null;
        if (
            $capDay !== null && (int) $capDay > 0
            && (int) ($offer['impressions_today'] ?? 0) >= (int) $capDay
        ) {
            return true;
        }

        $capTotal = $offer['offer_cap_total'] ?? null;
        if (
            $capTotal !== null && (int) $capTotal > 0
            && (int) ($offer['impressions_total'] ?? 0) >= (int) $capTotal
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param list<array<string,mixed>> $offers
     * @return array{list<array<string,mixed>>, list<array<string,mixed>>}
     */
    private function splitByExperience(array $offers): array
    {
        $measured = [];
        $unmeasured = [];
        foreach ($offers as $offer) {
            if ((int) ($offer['impressions_total'] ?? 0) >= self::EXPLORATION_THRESHOLD) {
                $measured[] = $offer;
            } else {
                $unmeasured[] = $offer;
            }
        }
        return [$measured, $unmeasured];
    }

    /**
     * eCPM sur quinze jours, pondere par `offer_weight` exprime en pourcentage.
     * Le poids sert a favoriser ou a brider une offre a performance egale ; il
     * ne remplace pas la mesure.
     *
     * @param array<string,mixed> $offer
     */
    private function score(array $offer): float
    {
        $ecpm = (float) ($offer['offer_ecpm_15d'] ?? 0);
        if ($ecpm <= 0.0) {
            $ecpm = (float) ($offer['offer_ecpm'] ?? 0);
        }
        $weight = (float) ($offer['offer_weight'] ?? 100);
        return $ecpm * ($weight / 100);
    }

    /**
     * Repartit les emplacements entre les offres mesurees et celles qu'on
     * decouvre.
     *
     * L'exploration recoit sa part (EXPLORATION_RATE), au minimum un
     * emplacement des qu'il existe une offre non mesuree — sans ce minimum,
     * un bloc de trois offres n'explorerait jamais rien. Ce que l'un des deux
     * groupes ne peut pas remplir revient a l'autre : on n'affiche jamais
     * moins d'offres que ce qu'on pourrait.
     *
     * @param list<array<string,mixed>> $measured
     * @param list<array<string,mixed>> $unmeasured
     * @return list<array<string,mixed>>
     */
    private function interleave(array $measured, array $unmeasured, int $limit): array
    {
        $wanted = $unmeasured === [] ? 0 : max(1, (int) floor($limit * self::EXPLORATION_RATE));
        $exploration = min(count($unmeasured), $wanted);
        $exploitation = min(count($measured), $limit - $exploration);
        // Emplacements laisses libres par les offres mesurees : rendus a l'exploration.
        $exploration = min(count($unmeasured), $limit - $exploitation);

        $selected = array_merge(
            array_slice($measured, 0, $exploitation),
            array_slice($unmeasured, 0, $exploration)
        );

        // L'ordre d'affichage reste celui du score. Les offres explorees, sans
        // eCPM significatif, se placent donc en fin de bloc : elles accumulent
        // de l'historique sans prendre la place des offres qui rapportent.
        usort($selected, fn(array $a, array $b): int => $this->score($b) <=> $this->score($a));

        return array_slice($selected, 0, $limit);
    }

    /**
     * @param list<array<string,mixed>> $offers
     * @return list<array<string,mixed>>
     */
    private function shuffle(array $offers): array
    {
        if (count($offers) < 2) {
            return $offers;
        }
        /** @var list<array<string,mixed>> $shuffled */
        $shuffled = $this->randomizer->shuffleArray($offers);
        return $shuffled;
    }
}
