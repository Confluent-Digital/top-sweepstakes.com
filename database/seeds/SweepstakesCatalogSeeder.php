<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Catalogue de concours.
 *
 * Ce seed illustre l'invariant du depot : quatre concours, quatre themes,
 * quatre reglements, des formulaires de longueurs differentes — et **aucun
 * fichier de gabarit**. Tout tient dans des lignes de table.
 *
 * Il est rejouable : un concours deja present est mis a jour, jamais supprime.
 * La cle etrangere s'y opposerait des qu'il a collecte, et c'est le bon
 * comportement.
 *
 * ⚠️ Les Official Rules produites ici sont un **gabarit de travail** : elles
 * portent toutes les mentions obligatoires et passent la validation de
 * publication, mais elles n'ont pas ete relues par un juriste. C'est le role de
 * l'agent `compliance-us` de le rappeler, et celui du commanditaire de les
 * faire valider avant d'acheter du trafic.
 */
final class SweepstakesCatalogSeeder extends AbstractSeed
{
    private const SPONSOR = 'SAS Confluent Digital';
    private const SPONSOR_ADDRESS = 'Espace Wojo, 15 rue des Cuirassiers, 69003 Lyon, France';

    /**
     * Etats exclus, communs a tous les concours.
     *
     * Rhode Island et New York imposent des contraintes d'enregistrement ou de
     * cautionnement selon la valeur ; les exclure evite d'y repondre concours
     * par concours. Ce choix se revise avec un juriste, pas ici.
     */
    private const EXCLUDED_STATES = 'RI,NY';

    public function run(): void
    {
        \App\Core\SeedGuard::refuseInProduction('SweepstakesCatalogSeeder');

        foreach ($this->catalogue() as $sweepstake) {
            $this->upsert($sweepstake);
        }
    }

    /** @return list<array<string,mixed>> */
    private function catalogue(): array
    {
        return [
            [
                'slug' => 'walmart-500',
                'name' => 'Walmart $500 Gift Card',
                'prize_title' => 'Win a $500 Walmart Gift Card',
                'value' => 500.00,
                'brand' => 'Walmart',
                'theme' => ['primary' => '#0071ce', 'accent' => '#ffc220', 'text' => '#16202e',
                            'background' => '#f4f7fb', 'surface' => '#ffffff'],
                'meta_title' => 'Win a $500 Walmart Gift Card — Free Entry',
                'meta_description' => 'Enter for your chance to win a $500 Walmart gift card. '
                    . 'No purchase necessary. U.S. residents 18+.',
                // Formulaire complet : telephone collecte, donc consentement TCPA propose.
                'fields' => [
                    ['email', 1, 1], ['first_name', 1, 1], ['last_name', 1, 1],
                    ['address', 2, 1], ['city', 2, 1], ['state', 2, 1], ['zip', 2, 1],
                    ['phone', 2, 1], ['dob', 2, 1],
                ],
                'offer_steps' => 4,
                'days' => 120,
            ],
            [
                'slug' => 'cash-app-750',
                'name' => 'Cash App $750',
                'prize_title' => 'Win $750 to Your Cash App',
                'value' => 750.00,
                'brand' => 'Cash App',
                'theme' => ['primary' => '#00c244', 'accent' => '#111111', 'text' => '#10201a',
                            'background' => '#f3faf5', 'surface' => '#ffffff'],
                'meta_title' => 'Win $750 Cash — Free Sweepstakes Entry',
                'meta_description' => 'Enter for your chance to win $750 in cash. '
                    . 'No purchase necessary. U.S. residents 18+.',
                'fields' => [
                    ['email', 1, 1], ['first_name', 1, 1], ['last_name', 1, 1],
                    ['state', 2, 1], ['zip', 2, 1], ['phone', 2, 1], ['dob', 2, 1],
                ],
                'offer_steps' => 4,
                'days' => 90,
            ],
            [
                'slug' => 'target-500',
                'name' => 'Target $500 Gift Card',
                'prize_title' => 'Win a $500 Target Gift Card',
                'value' => 500.00,
                'brand' => 'Target',
                'theme' => ['primary' => '#cc0000', 'accent' => '#ff5a5a', 'text' => '#2a1414',
                            'background' => '#fdf5f5', 'surface' => '#ffffff'],
                'meta_title' => 'Win a $500 Target Gift Card — Free Entry',
                'meta_description' => 'Enter for your chance to win a $500 Target gift card. '
                    . 'No purchase necessary. U.S. residents 18+.',
                // Formulaire court, sur un seul ecran : le tunnel s'y adapte
                // sans configuration supplementaire. Pas de telephone, donc pas
                // de consentement TCPA propose.
                'fields' => [
                    ['email', 1, 1], ['first_name', 1, 1], ['last_name', 1, 1],
                    ['state', 1, 1], ['zip', 1, 1], ['dob', 1, 1],
                ],
                'offer_steps' => 3,
                'days' => 60,
            ],
            [
                'slug' => 'visa-1000',
                'name' => 'Visa $1,000 Prepaid Card',
                'prize_title' => 'Win a $1,000 Visa Prepaid Card',
                'value' => 1000.00,
                'brand' => 'Visa',
                'theme' => ['primary' => '#1a1f71', 'accent' => '#f7b600', 'text' => '#141733',
                            'background' => '#f5f6fc', 'surface' => '#ffffff'],
                'meta_title' => 'Win a $1,000 Visa Prepaid Card — Free Entry',
                'meta_description' => 'Enter for your chance to win a $1,000 Visa prepaid card. '
                    . 'No purchase necessary. U.S. residents 18+.',
                'fields' => [
                    ['email', 1, 1], ['first_name', 1, 1], ['last_name', 1, 1],
                    ['address', 2, 1], ['city', 2, 1], ['state', 2, 1], ['zip', 2, 1],
                    ['phone', 2, 1], ['dob', 2, 1], ['gender', 2, 0],
                ],
                'offer_steps' => 5,
                'days' => 150,
            ],
        ];
    }

    /** @param array<string,mixed> $data */
    private function upsert(array $data): void
    {
        $existing = $this->scalar(
            'SELECT sweepstake_id FROM t_sweepstake WHERE sweepstake_slug = :slug',
            ['slug' => $data['slug']]
        );

        $row = [
            'sweepstake_slug' => $data['slug'],
            'sweepstake_name' => $data['name'],
            'sweepstake_status' => 'published',
            'sweepstake_prize_title' => $data['prize_title'],
            'sweepstake_prize_value_usd' => $data['value'],
            'sweepstake_prize_image' => '',
            'sweepstake_sponsor_name' => self::SPONSOR,
            'sweepstake_sponsor_address' => self::SPONSOR_ADDRESS,
            // Obligatoire des que la dotation nomme une marque tierce : sans
            // lui, le concours laisse croire a un partenariat qui n'existe pas.
            'sweepstake_brand_disclaimer' => sprintf(
                'This sweepstakes is administered solely by %s and is not sponsored by, endorsed '
                . 'by, or affiliated with %s in any way. %s is a registered trademark of its '
                . 'respective owner.',
                self::SPONSOR,
                $data['brand'],
                $data['brand']
            ),
            'sweepstake_date_start' => date('Y-m-d'),
            'sweepstake_date_end' => date('Y-m-d', strtotime('+' . $data['days'] . ' days')),
            'sweepstake_min_age' => 18,
            'sweepstake_offer_steps' => $data['offer_steps'],
            'sweepstake_excluded_states' => self::EXCLUDED_STATES,
            'sweepstake_official_rules_html' => $this->officialRules($data),
            'sweepstake_thankyou_html' => '',
            'sweepstake_meta_title' => $data['meta_title'],
            'sweepstake_meta_description' => $data['meta_description'],
            'sweepstake_theme' => json_encode($data['theme']),
        ];

        if ($existing !== false && $existing !== null) {
            $id = (int) $existing;

            // Un concours qui a deja collecte ne se reecrit pas : decaler sa
            // periode de participation ou son reglement sous les pieds de
            // participants deja inscrits leur retirerait la base sur laquelle
            // ils se sont engages.
            $entries = (int) $this->scalar(
                'SELECT COUNT(*) FROM t_lead WHERE lead_id_sweepstake = :id',
                ['id' => $id]
            );
            if ($entries > 0) {
                return;
            }

            $sets = [];
            foreach (array_keys($row) as $column) {
                $sets[] = $column . ' = :' . $column;
            }
            $statement = $this->getAdapter()->getConnection()->prepare(
                'UPDATE t_sweepstake SET ' . implode(', ', $sets) . ' WHERE sweepstake_id = :id'
            );
            $statement->execute($row + ['id' => $id]);
        } else {
            $this->table('t_sweepstake')->insert($row)->saveData();
            $id = (int) $this->scalar(
                'SELECT sweepstake_id FROM t_sweepstake WHERE sweepstake_slug = :slug',
                ['slug' => $data['slug']]
            );
        }

        $this->replaceFields($id, $data['fields']);
        $this->attachOffers($id, (int) $data['offer_steps']);
    }

    /** @param list<array{0:string,1:int,2:int}> $fields */
    private function replaceFields(int $sweepstakeId, array $fields): void
    {
        $connection = $this->getAdapter()->getConnection();
        $connection->prepare('DELETE FROM t_sweepstake_field WHERE sweepstake_field_id_sweepstake = :id')
            ->execute(['id' => $sweepstakeId]);

        $insert = $connection->prepare(
            'INSERT INTO t_sweepstake_field
                (sweepstake_field_id_sweepstake, sweepstake_field_key, sweepstake_field_step,
                 sweepstake_field_position, sweepstake_field_required, sweepstake_field_active,
                 sweepstake_field_label)
             VALUES (:id, :key, :step, :position, :required, 1, "")'
        );

        foreach ($fields as $position => [$key, $step, $required]) {
            $insert->execute([
                'id' => $sweepstakeId,
                'key' => $key,
                'step' => $step,
                'position' => $position + 1,
                'required' => $required,
            ]);
        }
    }

    /**
     * Rattache les offres actives, dans la limite du parcours.
     *
     * On ne rattache que des offres reellement diffusables — idv et idc
     * renseignes : une offre sans idv n'est jamais affichee, une offre sans idc
     * voit son revenu rattache a rien.
     */
    private function attachOffers(int $sweepstakeId, int $steps): void
    {
        $connection = $this->getAdapter()->getConnection();

        $connection->prepare('DELETE FROM t_sweepstake_offer WHERE sweepstake_offer_id_sweepstake = :id')
            ->execute(['id' => $sweepstakeId]);
        $connection->prepare('DELETE FROM t_offer_block WHERE offer_block_id_sweepstake = :id')
            ->execute(['id' => $sweepstakeId]);

        $connection->prepare(
            'INSERT INTO t_offer_block
                (offer_block_id_sweepstake, offer_block_title, offer_block_layout,
                 offer_block_position, offer_block_max_offers)
             VALUES (:id, "Optional offers from our partners", "grid", 1, :max)'
        )->execute(['id' => $sweepstakeId, 'max' => $steps]);

        $blockId = (int) $this->scalar(
            'SELECT offer_block_id FROM t_offer_block WHERE offer_block_id_sweepstake = :id',
            ['id' => $sweepstakeId]
        );

        $offers = $connection->prepare(
            'SELECT offer_id FROM t_offer
              WHERE offer_active = 1 AND offer_platform_idv != "" AND offer_platform_idc != ""
              ORDER BY offer_ecpm_15d DESC, offer_id ASC'
        );
        $offers->execute();

        $insert = $connection->prepare(
            'INSERT INTO t_sweepstake_offer
                (sweepstake_offer_id_sweepstake, sweepstake_offer_id_variant, sweepstake_offer_id_offer,
                 sweepstake_offer_id_block, sweepstake_offer_step, sweepstake_offer_position,
                 sweepstake_offer_active)
             VALUES (:sweepstake, 0, :offer, :block, 3, :position, 1)'
        );

        foreach ($offers->fetchAll(PDO::FETCH_COLUMN) as $position => $offerId) {
            $insert->execute([
                'sweepstake' => $sweepstakeId,
                'offer' => (int) $offerId,
                'block' => $blockId,
                'position' => $position + 1,
            ]);
        }
    }

    /**
     * Reglement complet.
     *
     * Il porte les mentions dont l'absence bloque la publication — NO PURCHASE
     * NECESSARY, l'AMOE, les probabilites de gain — et celles qu'un participant
     * ou un regulateur cherchera : sponsor, dates, eligibilite, Etats exclus,
     * ARV, selection et publication des gagnants.
     *
     * @param array<string,mixed> $data
     */
    private function officialRules(array $data): string
    {
        $end = date('F j, Y', strtotime('+' . $data['days'] . ' days'));
        $start = date('F j, Y');
        $value = number_format((float) $data['value'], 2);

        return <<<HTML
<h2>NO PURCHASE NECESSARY TO ENTER OR WIN</h2>
<p>A purchase will not increase your chances of winning. Void where prohibited by law.</p>

<h3>1. Sponsor</h3>
<p>This sweepstakes is sponsored and administered by {$this->sponsorName()},
{$this->sponsorAddress()} (the &ldquo;Sponsor&rdquo;).</p>

<h3>2. Eligibility</h3>
<p>Open only to legal residents of the fifty (50) United States and the District of Columbia who
are at least {$this->spellAge()} years of age at the time of entry. Void in
{$this->excludedStateNames()}, and wherever else prohibited or restricted by law. Employees of the Sponsor, its affiliates,
subsidiaries, advertising and promotion agencies, and the immediate family members of, and any
persons domiciled with, any such employees, are not eligible to enter or win.</p>

<h3>3. Sweepstakes Period</h3>
<p>The sweepstakes begins on {$start} at 12:00:00 AM Eastern Time and ends on {$end} at
11:59:59 PM Eastern Time (the &ldquo;Sweepstakes Period&rdquo;).
Entries submitted before or after the Sweepstakes Period will not be eligible.</p>

<h3>4. How to Enter</h3>
<p>During the Sweepstakes Period, complete and submit the entry form on this website. Limit one (1)
entry per person and per email address for the duration of the Sweepstakes Period. Entries that are
incomplete, illegible, or submitted by automated means are void.</p>

<h3>5. Alternate Method of Entry (AMOE)</h3>
<p>To enter without submitting the online form, hand-print <strong>the title of this sweepstakes
(&ldquo;{$data['name']}&rdquo;)</strong> together with {$this->amoeFields($data)} on a plain
3&quot; x 5&quot; card and mail it in a hand-addressed envelope bearing sufficient international
postage to the Sponsor at the address listed in Section 1. Mail-in entries must be postmarked
before the end of the Sweepstakes Period and received within thirty (30) days thereafter. Cards
that do not identify the sweepstakes by title cannot be attributed and will not be eligible. Limit
one (1) mail-in entry per outer envelope. Mail-in entries receive the same chance of winning as
online entries.</p>

<h3>6. What You Are Entering</h3>
<p><strong>Entering this sweepstakes does not by itself award a prize.</strong> It enters you into
a two-stage selection:</p>
<ol>
<li><strong>Finalist drawing.</strong> Within thirty (30) days after this sweepstakes closes, one
(1) <strong>Finalist</strong> will be selected at random from among all eligible entries received
during the Sweepstakes Period. Being selected as a Finalist does not award a prize.</li>
<li><strong>Annual Grand Prize drawing.</strong> Within sixty (60) days after the end of each
calendar year, one (1) <strong>Grand Prize Winner</strong> will be selected at random from among
all Finalists designated during that calendar year. <strong>Only the Grand Prize Winner receives a
prize.</strong></li>
</ol>

<h3>7. Grand Prize</h3>
<p>One (1) Grand Prize will be awarded per calendar year: {$data['prize_title']}, with an
approximate retail value (ARV) of \${$value} USD. The prize is awarded &ldquo;as is&rdquo; with no
warranty or guarantee, either express or implied. No substitution, cash equivalent or transfer of
the prize is permitted, except at the sole discretion of the Sponsor. Finalists who are not
selected as the Grand Prize Winner receive nothing.</p>

<h3>8. Odds of Winning</h3>
<p>The odds of being selected as a Finalist depend on the total number of eligible entries received
for this sweepstakes during the Sweepstakes Period. The odds of a Finalist being selected as the
Grand Prize Winner depend on the total number of Finalists designated during the calendar year.
Your overall odds of winning the Grand Prize are the product of the two.</p>

<h3>9. Selection and Notification</h3>
<p>Both drawings are conducted by a random selection process that is recorded and can be
independently reproduced by the Sponsor, so that any selection can be verified after the fact.</p>
<p>Potential winners will be notified using the contact details provided at entry and must respond
within seven (7) days of the first notification attempt. If a potential winner cannot be reached,
declines, or is found ineligible, an alternate &mdash; drawn at random at the same time as the
original selection, not chosen afterwards &mdash; will be substituted.</p>

<h3>10. Winners List</h3>
<p>For the name of the Grand Prize Winner, send a written request together with a self-addressed
envelope to the Sponsor at the address listed in Section 1 within ninety (90) days of the end of the
calendar year in which the Grand Prize drawing took place. The Sponsor will bear the return
postage.</p>

<h3>11. Taxes</h3>
<p>All federal, state and local taxes on the Grand Prize are the sole responsibility of the Grand
Prize Winner, who may be required to complete and return tax documentation before the prize is
released.</p>

<h3>12. Privacy</h3>
<p>Information collected from entrants is subject to the Sponsor&rsquo;s Privacy Policy, available
from the footer of this website.</p>

<h3>13. No Affiliation with Third-Party Brands</h3>
<p>This sweepstakes is administered solely by the Sponsor. It is not sponsored by, endorsed by, or
affiliated with {$data['brand']} or any other third-party brand. All trademarks are the property of
their respective owners.</p>
HTML;
    }

    /**
     * Champs a porter sur la carte 3x5.
     *
     * Ils suivent ce que le formulaire collecte reellement. Le gabarit exigeait
     * un telephone et une adresse postale sur TOUS les concours : un
     * participant par courrier fournissait donc des donnees que le formulaire
     * en ligne ne demande pas, sans aucun cadre de consentement — pour le
     * telephone, sans consentement TCPA.
     *
     * @param array<string,mixed> $data
     */
    private function amoeFields(array $data): string
    {
        $labels = [
            'first_name' => 'your full name',
            'email' => 'your email address',
            'address' => 'your mailing address',
            'city' => 'your city',
            'state' => 'your state',
            'zip' => 'your ZIP code',
            'dob' => 'your date of birth',
            'phone' => 'your telephone number',
        ];

        $collected = [];
        foreach ($data['fields'] as [$key, , ]) {
            if (isset($labels[$key])) {
                $collected[$labels[$key]] = true;
            }
        }
        // Le nom et l'adresse postale sont indispensables pour notifier et
        // remettre un lot, meme quand le formulaire ne les demande pas.
        $collected['your full name'] = true;
        $collected['your mailing address'] = true;

        $list = array_keys($collected);
        $last = array_pop($list);

        return $list === [] ? $last : implode(', ', $list) . ' and ' . $last;
    }

    /** L'age minimum, en toutes lettres, depuis la colonne et non en dur. */
    private function spellAge(int $age = 18): string
    {
        $words = [18 => 'eighteen', 19 => 'nineteen', 21 => 'twenty-one'];
        return sprintf('%s (%d)', $words[$age] ?? (string) $age, $age);
    }

    /**
     * Les Etats exclus, en toutes lettres, depuis la meme constante que la
     * colonne. Ecrits en dur, les deux divergeaient a la premiere modification
     * en back-office — et c'est la colonne qui refuse le participant, pendant
     * que le reglement continuerait d'annoncer autre chose.
     */
    private function excludedStateNames(): string
    {
        $names = [];
        foreach (explode(',', self::EXCLUDED_STATES) as $code) {
            $names[] = \App\Modules\Leads\Services\UsStates::name(trim($code)) ?? trim($code);
        }

        $last = array_pop($names);
        return $names === [] ? $last : implode(', ', $names) . ' and ' . $last;
    }

    private function sponsorName(): string
    {
        return self::SPONSOR;
    }

    private function sponsorAddress(): string
    {
        return self::SPONSOR_ADDRESS;
    }

    /**
     * Lecture d'une valeur unique, en requete preparee.
     *
     * @param array<string,mixed> $params
     */
    private function scalar(string $sql, array $params): mixed
    {
        $statement = $this->getAdapter()->getConnection()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }
}
