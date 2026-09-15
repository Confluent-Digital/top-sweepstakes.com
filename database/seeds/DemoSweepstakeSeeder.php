<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Concours de demonstration, pour faire tourner le site en developpement.
 *
 * Aucune donnee personnelle reelle, aucun identifiant de regie valide : les
 * `offer_platform_idv` sont des valeurs de test, et un clic sur ces offres ne
 * doit jamais partir vers la regie.
 */
final class DemoSweepstakeSeeder extends AbstractSeed
{
    public function run(): void
    {
        \App\Core\SeedGuard::refuseInProduction('DemoSweepstakeSeeder');

        // Le seed doit rester rejouable. On ne supprime pas le concours : des
        // qu'un participant y est rattache, la cle etrangere RESTRICT s'y
        // oppose — et c'est le bon comportement, on ne supprime pas un concours
        // qui a collecte. On met donc a jour s'il existe deja.
        $existingId = $this->scalar(
            'SELECT sweepstake_id FROM t_sweepstake WHERE sweepstake_slug = :slug',
            ['slug' => 'demo-amazon-750']
        );

        if ($existingId !== false && $existingId !== null) {
            $this->refreshSweepstake((int) $existingId);
            return;
        }

        $this->execute('DELETE FROM t_offer WHERE offer_name LIKE "DEMO %"');

        $this->table('t_sweepstake')->insert([
            'sweepstake_slug' => 'demo-amazon-750',
            'sweepstake_name' => 'Demo — $750 Gift Card',
            'sweepstake_status' => 'published',
            'sweepstake_prize_title' => 'Win a $750 Gift Card',
            'sweepstake_prize_value_usd' => 750.00,
            'sweepstake_prize_image' => '',
            'sweepstake_sponsor_name' => 'Top Sweepstakes LLC',
            'sweepstake_sponsor_address' => '1 Demo Street, Suite 100, New York, NY 10001',
            'sweepstake_brand_disclaimer' =>
                'This sweepstake is administered by Top Sweepstakes LLC and is not sponsored by, '
                . 'endorsed by, or affiliated with any third-party brand.',
            'sweepstake_date_start' => date('Y-m-d', strtotime('-7 days')),
            'sweepstake_date_end' => date('Y-m-d', strtotime('+90 days')),
            'sweepstake_min_age' => 18,
            'sweepstake_excluded_states' => 'RI,PR',
            'sweepstake_meta_title' => 'Win a $750 Gift Card — Enter Free',
            'sweepstake_meta_description' => 'Enter for your chance to win a $750 gift card. No purchase necessary.',
            'sweepstake_official_rules_html' => $this->officialRules(),
            'sweepstake_thankyou_html' => '',
            'sweepstake_theme' => json_encode([
                'primary' => '#1b4dff',
                'accent' => '#ff9900',
                'text' => '#161a22',
                'background' => '#f5f7fb',
                'surface' => '#ffffff',
            ]),
        ])->saveData();

        $sweepstakeId = (int) $this->scalar(
            'SELECT sweepstake_id FROM t_sweepstake WHERE sweepstake_slug = :slug',
            ['slug' => 'demo-amazon-750']
        );

        $this->seedFields($sweepstakeId);
        $this->seedOffers($sweepstakeId);
    }

    private function seedFields(int $sweepstakeId): void
    {
        $fields = [
            // Etape 1 : le minimum pour identifier le participant.
            ['email', 1, 1, 1],
            ['first_name', 1, 2, 1],
            ['last_name', 1, 3, 1],
            // Etape 2 : adresse et telephone, la ou se joue le consentement TCPA.
            ['address', 2, 1, 1],
            ['city', 2, 2, 1],
            ['state', 2, 3, 1],
            ['zip', 2, 4, 1],
            ['phone', 2, 5, 1],
            ['dob', 2, 6, 1],
            ['gender', 2, 7, 0],
        ];

        $rows = [];
        foreach ($fields as [$key, $step, $position, $required]) {
            $rows[] = [
                'sweepstake_field_id_sweepstake' => $sweepstakeId,
                'sweepstake_field_key' => $key,
                'sweepstake_field_step' => $step,
                'sweepstake_field_position' => $position,
                'sweepstake_field_required' => $required,
                'sweepstake_field_active' => 1,
                'sweepstake_field_label' => '',
            ];
        }
        $this->table('t_sweepstake_field')->insert($rows)->saveData();
    }

    private function seedOffers(int $sweepstakeId): void
    {
        $this->table('t_offer_block')->insert([
            'offer_block_id_sweepstake' => $sweepstakeId,
            'offer_block_title' => 'Optional offers from our partners',
            'offer_block_layout' => 'grid',
            'offer_block_position' => 1,
            'offer_block_max_offers' => 4,
        ])->saveData();

        $blockId = (int) $this->scalar(
            'SELECT offer_block_id FROM t_offer_block WHERE offer_block_id_sweepstake = :id',
            ['id' => $sweepstakeId]
        );

        $offers = [
            ['DEMO Auto Insurance Quotes', 'banner', 9.50, 5000],
            ['DEMO Meal Kit Discount', 'coupon', 6.20, 5000],
            ['DEMO Credit Score Check', 'banner', 4.10, 5000],
            // Offre neuve, sans historique : elle doit passer par l'exploration.
            ['DEMO New Partner Offer', 'banner', 0.00, 0],
        ];

        foreach ($offers as $index => [$name, $type, $ecpm, $impressions]) {
            $this->table('t_offer')->insert([
                'offer_name' => $name,
                'offer_advertiser' => 'Demo Advertiser',
                'offer_type' => $type,
                'offer_image' => '',
                'offer_headline' => $name,
                'offer_text_html' => $type === 'coupon' ? '<p>Save up to 40% on your first box.</p>' : null,
                'offer_cta_label' => 'See offer',
                'offer_target_blank' => 1,
                'offer_platform_ids' => '996',
                // Valeur de test : ne correspond a aucune crea reelle.
                'offer_platform_idv' => 'DEMO' . (1000 + $index),
                'offer_platform_idc' => 'DEMOC' . (100 + $index),
                'offer_passthrough_fields' => json_encode(['email', 'zip']),
                'offer_country' => 'US',
                'offer_active' => 1,
                'offer_weight' => 100,
                'offer_ecpm' => $ecpm,
                'offer_ecpm_15d' => $ecpm,
                'offer_notes' => 'Offre de demonstration — ne pas activer en production.',
            ])->saveData();

            $offerId = (int) $this->scalar(
                'SELECT offer_id FROM t_offer WHERE offer_name = :name',
                ['name' => $name]
            );

            $this->table('t_sweepstake_offer')->insert([
                'sweepstake_offer_id_sweepstake' => $sweepstakeId,
                'sweepstake_offer_id_variant' => 0,
                'sweepstake_offer_id_offer' => $offerId,
                'sweepstake_offer_id_block' => $blockId,
                'sweepstake_offer_step' => 3,
                'sweepstake_offer_position' => $index + 1,
                'sweepstake_offer_active' => 1,
            ])->saveData();

            // Historique d'impressions, pour que l'eCPM soit considere comme
            // significatif par OfferSelector (seuil EXPLORATION_THRESHOLD).
            if ($impressions > 0) {
                $this->table('t_offer_stats_daily')->insert([
                    'offer_stats_daily_date' => date('Y-m-d', strtotime('-2 days')),
                    'offer_stats_daily_id_sweepstake' => $sweepstakeId,
                    'offer_stats_daily_id_variant' => 0,
                    'offer_stats_daily_id_offer' => $offerId,
                    'offer_stats_daily_step' => 3,
                    'offer_stats_daily_device' => 'desktop',
                    'offer_stats_daily_subid' => '',
                    'offer_stats_daily_action' => 'impression',
                    'offer_stats_daily_nb' => $impressions,
                ])->saveData();
            }
        }
    }

    /**
     * Remet le concours de demonstration dans son etat de reference, sans y
     * toucher au-dela : ses participants, ses offres et ses statistiques
     * restent en place.
     */
    private function refreshSweepstake(int $id): void
    {
        $statement = $this->getAdapter()->getConnection()->prepare(
            'UPDATE t_sweepstake SET
                sweepstake_official_rules_html = :rules,
                sweepstake_date_start = :start,
                sweepstake_date_end   = :end,
                sweepstake_status     = :status
              WHERE sweepstake_id = :id'
        );
        $statement->execute([
            'rules' => $this->officialRules(),
            'start' => date('Y-m-d', strtotime('-7 days')),
            'end' => date('Y-m-d', strtotime('+90 days')),
            'status' => 'published',
            'id' => $id,
        ]);
    }

    /**
     * Lecture d'une valeur unique, en requete preparee. Phinx::fetchRow n'accepte
     * pas de parametres, et concatener une valeur dans du SQL reste a proscrire,
     * y compris dans un seed.
     *
     * @param array<string,mixed> $params
     */
    private function scalar(string $sql, array $params): mixed
    {
        $statement = $this->getAdapter()->getConnection()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }

    private function officialRules(): string
    {
        return <<<'HTML'
<h2>NO PURCHASE NECESSARY TO ENTER OR WIN</h2>
<p>A purchase will not increase your chances of winning.</p>

<h3>1. Eligibility</h3>
<p>Open only to legal residents of the fifty (50) United States and the District of Columbia
who are at least eighteen (18) years old at the time of entry. Void in Rhode Island, Puerto Rico
and where prohibited by law.</p>

<h3>2. Sponsor</h3>
<p>Top Sweepstakes LLC, 1 Demo Street, Suite 100, New York, NY 10001.</p>

<h3>3. Sweepstakes Period</h3>
<p>Entries are accepted between the start and end dates shown on the entry page.</p>

<h3>4. How to Enter</h3>
<p>Complete the entry form on this website. Limit one (1) entry per person.</p>

<h3>5. Alternate Method of Entry (AMOE)</h3>
<p>To enter without submitting the online form, hand-print your full name, address, email address,
date of birth and telephone number on a 3&quot;x5&quot; card and mail it in a stamped envelope to the
Sponsor address above. Mail-in entries receive the same chance of winning as online entries.</p>

<h3>6. Prize</h3>
<p>One (1) prize consisting of a gift card with an approximate retail value (ARV) of $750.00.
No substitution or transfer of prize is permitted except at Sponsor&#39;s discretion.</p>

<h3>7. Odds of Winning</h3>
<p>Odds of winning depend on the total number of eligible entries received.</p>

<h3>8. Winner Selection and Notification</h3>
<p>The winner will be selected in a random drawing from among all eligible entries received.
The winner will be notified using the contact details provided at entry and must respond within
seven (7) days, failing which an alternate winner may be selected.</p>

<h3>9. Winners List</h3>
<p>For the name of the winner, send a stamped, self-addressed envelope to the Sponsor address above.</p>

<h3>10. Taxes</h3>
<p>All federal, state and local taxes on the prize are the sole responsibility of the winner.</p>

<h3>11. Not Sponsored by Third-Party Brands</h3>
<p>This sweepstake is administered solely by the Sponsor and is not sponsored by, endorsed by, or
affiliated with any third-party brand whose products may appear as a prize.</p>
HTML;
    }
}
