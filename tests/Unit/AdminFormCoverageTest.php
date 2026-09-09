<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Filet contre une perte de donnees silencieuse.
 *
 * Les fiches du back-office suivent le meme motif : `extract($input)` construit
 * le tableau COMPLET des colonnes, et `update()` les ecrit toutes. Un champ que
 * le controleur sait lire mais que le gabarit n'expose pas est donc ecrase par
 * sa valeur par defaut a chaque enregistrement — sans erreur, sans message,
 * sans trace.
 *
 * C'est arrive : `theme_text`, `theme_surface` et `sweepstake_thankyou_html`
 * etaient lus par le controleur et absents du formulaire. Chaque sauvegarde de
 * la fiche les vidait. On ne s'en apercoit qu'en constatant qu'un texte a
 * disparu, longtemps apres.
 *
 * Ce test compare ce que le controleur lit a ce que le gabarit envoie. Il ne
 * remplace pas une revue : il rend le motif impossible a reintroduire par
 * inadvertance.
 */
final class AdminFormCoverageTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * @return array<string, array{string, string}>
     */
    public static function fiches(): array
    {
        return [
            'fiche concours' => [
                'src/Modules/Admin/Controllers/SweepstakeAdminController.php',
                'src/Views/admin/sweepstakes/edit.html.twig',
            ],
            'fiche offre' => [
                'src/Modules/Admin/Controllers/OfferAdminController.php',
                'src/Views/admin/offers/edit.html.twig',
            ],
        ];
    }

    /** @dataProvider fiches */
    public function testChaqueChampLuParLeControleurExisteDansLeFormulaire(
        string $controller,
        string $template
    ): void {
        $read = $this->fieldsReadByController(self::ROOT . '/' . $controller);
        $sent = $this->fieldsSentByTemplate(self::ROOT . '/' . $template);

        self::assertNotEmpty($read, 'Aucun champ detecte dans ' . $controller);

        $prefixes = array_map(
            static fn(string $name): string => substr($name, 0, -1),
            array_filter($sent, static fn(string $name): bool => str_ends_with($name, '*'))
        );

        $missing = array_values(array_filter(
            array_diff($read, $sent),
            static function (string $field) use ($prefixes): bool {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($field, $prefix)) {
                        return false;
                    }
                }
                return true;
            }
        ));

        self::assertSame(
            [],
            $missing,
            sprintf(
                "Champ(s) lu(s) par %s mais absent(s) de %s : %s\n"
                . "Ces champs seront ECRASES par leur valeur par defaut a chaque enregistrement.\n"
                . "Ajoute-les au formulaire, ou cesse de les lire dans extract().",
                basename($controller),
                basename($template),
                implode(', ', $missing)
            )
        );
    }

    /**
     * Noms de champs que le controleur va chercher dans le corps de la requete.
     *
     * @return list<string>
     */
    private function fieldsReadByController(string $path): array
    {
        $source = (string) file_get_contents($path);

        // On ne retient que le corps des methodes qui construisent les donnees
        // a ecrire : ailleurs, `$input[...]` sert a lire un consentement ou un
        // filtre, pas a alimenter un UPDATE.
        $names = [];
        foreach (['extract', 'extractFields', 'extractRules'] as $method) {
            $start = strpos($source, 'private function ' . $method . '(');
            if ($start === false) {
                continue;
            }
            $body = substr($source, $start, 4000);

            // Cas simple : $input['nom_du_champ']
            if (preg_match_all('/\$input\[\'([a-z0-9_]+)\'\]/i', $body, $matches) > 0) {
                $names = array_merge($names, $matches[1]);
            }

            // Cas construit : foreach (['a','b'] as $k) { ... $input['prefixe_' . $k] }
            //
            // C'est la moitie du bug d'origine : les cinq couleurs du theme sont
            // lues par concatenation, et deux d'entre elles n'etaient pas dans le
            // formulaire. Ne chercher que la forme litterale les manquerait.
            $names = array_merge($names, $this->concatenatedNames($body));
        }

        return array_values(array_unique($names));
    }

    /**
     * Noms construits par concatenation dans une boucle sur une liste litterale.
     *
     * @return list<string>
     */
    private function concatenatedNames(string $body): array
    {
        preg_match_all(
            '/foreach\s*\(\s*\[([^\]]+)\]\s+as\s+\$(\w+)\s*\)(.{0,600})/s',
            $body,
            $loops,
            PREG_SET_ORDER
        );

        $names = [];
        foreach ($loops as $loop) {
            [, $listSource, $variable, $loopBody] = $loop;

            if (
                preg_match(
                    '/\$input\[\'([a-z0-9_]+)\'\s*\.\s*\$' . preg_quote($variable, '/') . '\]/i',
                    $loopBody,
                    $usage
                ) !== 1
            ) {
                continue;
            }

            preg_match_all('/\'([a-z0-9_]+)\'/i', $listSource, $items);
            foreach ($items[1] as $item) {
                $names[] = $usage[1] . $item;
            }
        }

        return $names;
    }

    /**
     * Noms de champs reellement envoyes par le formulaire.
     *
     * @return list<string>
     */
    private function fieldsSentByTemplate(string $path): array
    {
        $source = (string) file_get_contents($path);

        // `name="offer_ids[]"` et `name="field_active[{{ key }}]"` arrivent en
        // PHP sous la cle racine : on ne garde que celle-ci.
        preg_match_all('/name="([a-z0-9_]+)(\[[^"]*\])?"/i', $source, $matches);
        $names = $matches[1];

        // Le gabarit construit lui aussi certains noms dans une boucle :
        // `name="theme_{{ key }}"`. On considere alors tout le prefixe comme
        // couvert.
        //
        // Limite assumee : ce test ne verifie pas que la liste parcourue par le
        // gabarit est la meme que celle du controleur. Il attrape le cas qui a
        // reellement cause une perte de donnees — un champ litteral lu et jamais
        // envoye — et laisse a la revue humaine les listes qui divergeraient.
        if (preg_match_all('/name="([a-z0-9_]+_)\{\{/i', $source, $prefixes) > 0) {
            $names = array_merge($names, array_map(
                static fn(string $prefix): string => $prefix . '*',
                $prefixes[1]
            ));
        }

        return array_values(array_unique($names));
    }
}
