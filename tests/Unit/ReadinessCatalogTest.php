<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Modules\Admin\Models\Repositories\ReadinessRepository;
use App\Modules\Admin\Services\ReadinessCatalog;
use App\Modules\Admin\Services\ReadinessService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Coherence du catalogue des reserves d'ouverture.
 *
 * Le mecanisme a un point faible connu : un controle automatique est declare a
 * deux endroits — ses metadonnees dans ReadinessService::CHECKS, son execution
 * dans runChecks(). Si les deux cles divergent, l'ecran ne signale rien : le
 * point apparait simplement comme « declare », donc ouvert pour toujours, et
 * son controle ne s'execute jamais. C'est le genre de panne qui ne se voit pas.
 *
 * Ce test verifie l'appariement, et au passage qu'aucune fiche n'est publiee
 * incomplete — une reserve sans destinataire ni action ne se ferme jamais.
 */
final class ReadinessCatalogTest extends TestCase
{
    /** @return list<array<string,string>> */
    private static function checks(): array
    {
        /** @var list<array<string,string>> $checks */
        $checks = (new ReflectionClass(ReadinessService::class))->getConstant('CHECKS');
        return $checks;
    }

    /**
     * Le registre d'execution est lu par reflexion, pas par recherche dans le
     * source : c'est l'objet reellement construit qui repond, donc le test ne
     * peut pas etre trompe par un commentaire ou une chaine qui ressemble a une
     * cle.
     */
    public function testChaqueControleAUneExecution(): void
    {
        $reflection = new ReflectionClass(ReadinessService::class);
        $checkers = $reflection->getMethod('checkers');

        /** @var array<string, callable> $registre */
        $registre = $checkers->invoke($reflection->newInstanceWithoutConstructor());

        $executees = array_keys($registre);
        $declarees = array_column(self::checks(), 'key');

        sort($executees);
        sort($declarees);

        self::assertSame(
            $declarees,
            $executees,
            'Un controle declare sans execution reste ouvert pour toujours ; une execution sans '
            . 'declaration ne s\'affiche nulle part.'
        );
    }

    public function testLesClesSontUniques(): void
    {
        $keys = array_merge(
            array_column(self::checks(), 'key'),
            array_column(ReadinessCatalog::DECLARED, 'key')
        );

        self::assertSame(
            array_values(array_unique($keys)),
            $keys,
            'Deux reserves partageant une cle partageraient aussi leur decision.'
        );
    }

    /** @return array<string, array{array<string,string>}> */
    public static function fiches(): array
    {
        $cases = [];
        foreach (array_merge(self::checks(), ReadinessCatalog::DECLARED) as $item) {
            $cases[(string) $item['key']] = [$item];
        }
        return $cases;
    }

    /**
     * @param array<string,string> $item
     * @dataProvider fiches
     */
    public function testChaqueFicheEstExploitable(array $item): void
    {
        foreach (['severity', 'area', 'title', 'why', 'action', 'owner'] as $field) {
            self::assertArrayHasKey($field, $item);
            self::assertNotSame('', trim((string) $item[$field]), $field . ' est vide');
        }
        self::assertArrayHasKey('link', $item);
        self::assertArrayHasKey(
            (string) $item['severity'],
            ReadinessCatalog::SEVERITIES,
            'Severite inconnue : elle ne serait ni comptee ni coloree.'
        );
    }

    /**
     * Les documents cites dans les consentements doivent exister cote service
     * de contenus : sinon le controle « document cite mais pas atteignable »
     * signalerait une page qu'on ne peut de toute facon pas afficher.
     */
    public function testLesDocumentsCitesSontDesPagesConnues(): void
    {
        foreach (ReadinessCatalog::citedDocuments() as $label => $page) {
            self::assertArrayHasKey(
                $page,
                \App\Modules\Legal\Services\LegalContentService::PAGES,
                $label . ' renvoie a une page inconnue du service de contenus.'
            );
        }
    }

    /**
     * Le formulaire de decision propose exactement les statuts que le depot
     * accepte : un statut affiche mais refuse en base donnerait une erreur
     * incomprehensible a l'enregistrement.
     */
    public function testLesStatutsProposesSontCeuxAcceptesEnBase(): void
    {
        /** @var list<string> $acceptes */
        $acceptes = (new ReflectionClass(ReadinessRepository::class))->getConstant('STATUSES');

        self::assertSame(array_keys(ReadinessCatalog::STATUSES), $acceptes);
    }
}
