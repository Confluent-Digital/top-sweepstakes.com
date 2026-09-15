-- Concours « Amazon $150 Gift Card » — a executer sur la PRODUCTION.
--
--   docker exec -i topsweepstakes_mariadb mariadb -u root -p bd_top_sweepstakes < amazon-150.sql
--
-- Cree le concours en statut BROUILLON. Il n'est donc PAS en ligne : la
-- publication se fait depuis /admin/sweepstakes, ou la validation verifie les
-- mentions obligatoires, le sponsor, son adresse et les dates. Passer par le
-- back-office pour publier, c'est aussi ce qui garantit qu'un humain a relu.
--
-- AUCUN ETAT EXCLU. A 150 $ de valeur annoncee, aucun enregistrement d'Etat
-- n'est requis : le seuil de New York et de la Floride est a 5 000 $. Les deux
-- marches restent donc ouverts, contrairement aux quatre autres concours du
-- catalogue qui excluent RI et NY.
--
-- La clause d'eligibilite du reglement a ete regeneree en consequence : elle dit
-- « Void wherever prohibited or restricted by law » et ne nomme plus aucun Etat.
-- Un reglement qui exclurait des Etats que le formulaire accepte serait une
-- contradiction opposable — c'est ce que /admin/readiness surveille.
--
-- Le reste du reglement vient du generateur du catalogue : meme structure que
-- les autres concours, toutes les mentions obligatoires presentes (4972
-- caracteres de texte, seuil de publication : 1500). Il n'a PAS ete relu par un
-- juriste americain — voir /admin/readiness.

START TRANSACTION;

-- Le slug est dans l'URL ET dans le sid envoye a la regie : deux concours ne
-- peuvent pas le partager. Ne rien inserer s'il existe deja.
SET @existe = (SELECT COUNT(*) FROM t_sweepstake WHERE sweepstake_slug = 'amazon-150');

INSERT INTO t_sweepstake (
    sweepstake_slug, sweepstake_name, sweepstake_status,
    sweepstake_prize_title, sweepstake_prize_value_usd, sweepstake_prize_image,
    sweepstake_sponsor_name, sweepstake_sponsor_address, sweepstake_brand_disclaimer,
    sweepstake_date_start, sweepstake_date_end, sweepstake_min_age,
    sweepstake_offer_steps, sweepstake_excluded_states,
    sweepstake_official_rules_html, sweepstake_thankyou_html,
    sweepstake_meta_title, sweepstake_meta_description, sweepstake_theme
)
SELECT
    'amazon-150',
    'Amazon $150 Gift Card',
    'draft',
    'Win a $150 Amazon Gift Card',
    150.00,
    '',
    'SAS Confluent Digital',
    'Espace Wojo, 15 rue des Cuirassiers, 69003 Lyon, France',
    'This sweepstakes is administered solely by SAS Confluent Digital and is not sponsored by, endorsed by, or affiliated with Amazon in any way. Amazon is a registered trademark of its respective owner.',
    '2026-09-15',
    '2026-12-14',
    18,
    4,
    '',
    '<h2>NO PURCHASE NECESSARY TO ENTER OR WIN</h2>
<p>A purchase will not increase your chances of winning. Void where prohibited by law.</p>

<h3>1. Sponsor</h3>
<p>This sweepstakes is sponsored and administered by SAS Confluent Digital,
Espace Wojo, 15 rue des Cuirassiers, 69003 Lyon, France (the &ldquo;Sponsor&rdquo;).</p>

<h3>2. Eligibility</h3>
<p>Open only to legal residents of the fifty (50) United States and the District of Columbia who
are at least eighteen (18) years of age at the time of entry. Void wherever prohibited or restricted by law. Employees of the Sponsor, its affiliates,
subsidiaries, advertising and promotion agencies, and the immediate family members of, and any
persons domiciled with, any such employees, are not eligible to enter or win.</p>

<h3>3. Sweepstakes Period</h3>
<p>The sweepstakes begins on September 15, 2026 at 12:00:00 AM Eastern Time and ends on December 14, 2026 at
11:59:59 PM Eastern Time (the &ldquo;Sweepstakes Period&rdquo;).
Entries submitted before or after the Sweepstakes Period will not be eligible.</p>

<h3>4. How to Enter</h3>
<p>During the Sweepstakes Period, complete and submit the entry form on this website. Limit one (1)
entry per person and per email address for the duration of the Sweepstakes Period. Entries that are
incomplete, illegible, or submitted by automated means are void.</p>

<h3>5. Alternate Method of Entry (AMOE)</h3>
<p>To enter without submitting the online form, hand-print <strong>the title of this sweepstakes
(&ldquo;Amazon $150 Gift Card&rdquo;)</strong> together with your email address, your full name, your mailing address, your city, your state, your ZIP code, your telephone number and your date of birth on a plain
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
<p>One (1) Grand Prize will be awarded per calendar year: Win a $150 Amazon Gift Card, with an
approximate retail value (ARV) of $150.00 USD. The prize is awarded &ldquo;as is&rdquo; with no
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
affiliated with Amazon or any other third-party brand. All trademarks are the property of
their respective owners.</p>',
    '',
    'Win a $150 Amazon Gift Card — Free Entry',
    'Enter for your chance to win a $150 Amazon gift card. No purchase necessary. Open to U.S. residents 18 and older.',
    '{"primary": "#ff9900", "accent": "#232f3e", "text": "#0f1111", "background": "#f7f8f8", "surface": "#ffffff"}'
FROM DUAL WHERE @existe = 0;

SET @id = (SELECT sweepstake_id FROM t_sweepstake WHERE sweepstake_slug = 'amazon-150');

-- Champs du formulaire. Deux etapes : identite, puis coordonnees.
-- Le telephone est obligatoire : c'est lui qui porte la remuneration.
DELETE FROM t_sweepstake_field WHERE sweepstake_field_id_sweepstake = @id;

INSERT INTO t_sweepstake_field (
    sweepstake_field_id_sweepstake, sweepstake_field_key, sweepstake_field_step,
    sweepstake_field_position, sweepstake_field_required, sweepstake_field_active,
    sweepstake_field_label
) VALUES
    (@id, 'email', 1, 1, 1, 1, ''),
    (@id, 'first_name', 1, 2, 1, 1, ''),
    (@id, 'last_name', 1, 3, 1, 1, ''),
    (@id, 'address', 2, 4, 1, 1, ''),
    (@id, 'city', 2, 5, 1, 1, ''),
    (@id, 'state', 2, 6, 1, 1, ''),
    (@id, 'zip', 2, 7, 1, 1, ''),
    (@id, 'phone', 2, 8, 1, 1, ''),
    (@id, 'dob', 2, 9, 1, 1, '');

COMMIT;

SELECT sweepstake_id, sweepstake_slug, sweepstake_status,
       sweepstake_prize_value_usd,
       IF(sweepstake_excluded_states = '', 'aucun', sweepstake_excluded_states) AS etats_exclus,
       sweepstake_date_end,
       CHAR_LENGTH(sweepstake_official_rules_html) AS reglement_html
  FROM t_sweepstake WHERE sweepstake_slug = 'amazon-150';
