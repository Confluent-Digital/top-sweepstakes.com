<?php

declare(strict_types=1);

namespace App\Modules\Sweepstakes\Services;

/**
 * Ce qui fait qu'un reglement de jeu-concours tient debout.
 *
 * Deux endroits jugent un reglement : le formulaire de publication, qui refuse
 * de mettre un concours en ligne, et l'ecran des reserves d'ouverture, qui
 * signale ceux deja publies. Les deux doivent mesurer la MEME chose — sinon on
 * obtient un concours refuse a la publication et pourtant declare bon par le
 * controle, ce qui suffit a faire cesser de croire l'ecran.
 *
 * La mesure porte sur le texte debalise. Un reglement tronque a 1 400
 * caracteres de texte depasse 1 500 caracteres une fois balise : compter le
 * HTML reviendrait a valider la mise en forme plutot que le contenu, et un
 * `<p><br></p>` passerait pour un reglement.
 */
final class OfficialRules
{
    /**
     * Longueur minimale du texte, en caracteres.
     *
     * « Non vide » ne suffisait pas : des regles tronquees en plein mot ont ete
     * publiees sans que rien ne le signale. Un reglement complet — NO PURCHASE
     * NECESSARY, AMOE, sponsor et adresse, dates, eligibilite et Etats exclus,
     * ARV, probabilites, selection et publication des gagnants — fait plusieurs
     * milliers de caracteres. Ce seuil ecarte un fragment sans jamais atteindre
     * un texte reel.
     */
    public const MIN_LENGTH = 1500;

    /**
     * Mentions dont l'absence coute le plus cher, et qui se perdent le plus
     * facilement dans un copier-coller tronque.
     *
     * Libelle affiche => chaine cherchee dans le texte, en minuscules.
     *
     * @var array<string,string>
     */
    public const REQUIRED_MENTIONS = [
        'NO PURCHASE NECESSARY' => 'no purchase necessary',
        'Methode alternative d\'entree (AMOE)' => 'alternate method of entry',
        'Probabilites de gain' => 'odds of winning',
    ];

    /**
     * Mentions obligatoires absentes du reglement.
     *
     * Meme liste pour le formulaire de publication et pour le controle des
     * concours deja en ligne : un concours publie autrement que par le
     * formulaire — un seed, un SQL direct, une mise en ligne anterieure a ce
     * controle — doit etre juge sur les memes criteres, sinon il ressort vert
     * alors que sa republication serait refusee.
     *
     * @return list<string> libelles affichables
     */
    public static function missingMentions(string $html): array
    {
        $text = mb_strtolower(self::text($html));

        $missing = [];
        foreach (self::REQUIRED_MENTIONS as $label => $needle) {
            if (!str_contains($text, $needle)) {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    /** Texte reellement lu par un participant, sans le balisage. */
    public static function text(string $html): string
    {
        return trim(strip_tags($html));
    }

    public static function length(string $html): int
    {
        return mb_strlen(self::text($html));
    }

    public static function isEmpty(string $html): bool
    {
        return self::text($html) === '';
    }

    public static function isTooShort(string $html): bool
    {
        $length = self::length($html);
        return $length > 0 && $length < self::MIN_LENGTH;
    }
}
