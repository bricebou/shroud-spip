<?php

declare(strict_types=1);

namespace Shroud\Spip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Shroud\Context;
use Shroud\Declaration\Declaration;
use Shroud\Declaration\Table;
use Shroud\Generator;
use Shroud\Language\French;
use Shroud\Schema\Draft;
use Shroud\Schema\TableSchema;
use Shroud\Spip\SpipPlatform;
use Shroud\Strategy\Reference\RenumberedId;

final class SpipPlatformTest extends TestCase
{
    public function testTheShortcutsOfARenumberedTypeFollowItsRenumbering(): void
    {
        $generator = new Generator('sel de test', platform: new SpipPlatform());
        $auteur = $generator->permutationFor('auteur')->apply(12);
        $document = $generator->permutationFor('document')->apply(7);

        self::assertSame(
            "Voir [la fiche->aut{$auteur}] et [->art12], [->auteur{$auteur}] <img{$document}|left> <doc{$document}> <12> [->12]",
            new SpipPlatform()->renumberReferences(
                'Voir [la fiche->aut12] et [->art12], [->auteur12] <img7|left> <doc7> <12> [->12]',
                $this->context($generator, ['auteur', 'document']),
            ),
        );
    }

    public function testTheShortcutsAdmitTheSpacesThatSpipAdmits(): void
    {
        $generator = new Generator('sel de test', platform: new SpipPlatform());
        $auteur = $generator->permutationFor('auteur')->apply(12);
        $document = $generator->permutationFor('document')->apply(7);

        self::assertSame(
            "[-> aut{$auteur}] [->aut {$auteur}] [-> auteur {$auteur} ] [->>aut{$auteur}] [->aut{$auteur}?var=1#bas] <img {$document}|left> <doc {$document} >",
            new SpipPlatform()->renumberReferences(
                '[-> aut12] [->aut 12] [-> auteur 12 ] [->>aut12] [->aut12?var=1#bas] <img 7|left> <doc 7 >',
                $this->context($generator, ['auteur', 'document']),
            ),
        );
    }

    public function testALinkWithoutPrefixTargetsAnArticle(): void
    {
        $generator = new Generator('sel de test', platform: new SpipPlatform());
        $article = $generator->permutationFor('article')->apply(12);

        self::assertSame("[->{$article}] [->art{$article}] <12>", new SpipPlatform()->renumberReferences('[->12] [->art12] <12>', $this->context($generator, ['article'])));
    }

    public function testTheMarkupOfSpipIsLeftUntouched(): void
    {
        $text = new Generator('sel de test', language: new French(), platform: new SpipPlatform())->text();

        self::assertSame(
            '<multi>[en]Alpha[fr]Beta</multi> [gamma delta->art12] [->12] [-> aut 12] <img7|left>',
            $text->replaceWords('<multi>[en]Partners[fr]Partenaires</multi> [la fiche->art12] [->12] [-> aut 12] <img7|left>', static fn(int $rank): string => ['alpha', 'beta', 'gamma'][$rank] ?? 'delta'),
        );
        self::assertSame('[->art12] [->12] [fr]', $text->replaceCodes('[->art12] [->12] [fr]', static fn(): string => 'x', static fn(): int => 7));
    }

    public function testTheVersionIsReadFromThePluginMeta(): void
    {
        $meta = serialize([
            'SPIP' => ['nom' => 'SPIP', 'etat' => 'stable', 'version' => '4.4.28', 'dir_type' => '_DIR_RESTREINT', 'dir' => ''],
            'FORUM' => ['dir' => 'forum', 'nom' => 'Forum', 'version' => '3.1.19'],
        ]);
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE spip_meta (nom TEXT, valeur TEXT)');
        $pdo->prepare('INSERT INTO spip_meta VALUES (?, ?)')->execute(['plugin', $meta]);

        self::assertSame('4.4.28', new SpipPlatform()->readVersion($pdo));
        self::assertSame('4.4.28', SpipPlatform::versionFromMeta($meta));
    }

    public function testABaseWithoutVersionGivesNothing(): void
    {
        self::assertNull(SpipPlatform::versionFromMeta(serialize(['FORUM' => ['version' => '3.1.19']])));
        self::assertNull(SpipPlatform::versionFromMeta('pas une sérialisation'));
        self::assertNull(new SpipPlatform()->readVersion(new \PDO('sqlite::memory:')), 'sans table spip_meta');
    }

    public function testTheConnectionFileIsThatOfASite(): void
    {
        self::assertSame('config/connect.php', new SpipPlatform()->connectionFile());
        self::assertSame('mysql:host=db;dbname=la_base;charset=utf8mb4;port=3307', new SpipPlatform()->readConnection(__DIR__ . '/../fixtures/connect.php')->dsn());
    }

    public function testTheFrozenSchemasAreShippedWithThePackage(): void
    {
        $frozen = new SpipPlatform()->frozenSchemas();

        self::assertContains('4.4.28', $frozen->versions());
        self::assertSame('4.4.28', $frozen->nearest('4.4.30'));
        self::assertCount(28, $frozen->load('4.4.28')->tables());
    }

    public function testTheDraftKnowsTheSecretsAndTheLinksOfSpip(): void
    {
        $table = new TableSchema('spip_contacts_liens', ['id_contact', 'id_objet', 'objet'], [
            'id_contact' => 'bigint(21)',
            'id_objet' => 'bigint(21)',
            'objet' => 'varchar(25)',
            'vu' => 'varchar(6)',
        ]);
        $contacts = new TableSchema('spip_contacts', ['id_contact'], [
            'id_contact' => 'bigint(21)',
            'id_auteur' => 'bigint(21)',
            'prenom' => 'text',
            'nom' => 'text',
            'alea_actuel' => 'tinytext',
            'texte' => 'longtext',
            'courriel' => 'text',
        ]);
        $draft = new Draft(new SpipPlatform());

        $links = $draft->table($table, new SpipPlatform()->bareDeclaration());
        self::assertStringContainsString("'id_objet' => new RenumberedPolymorphicId('objet'),", $links);
        self::assertStringContainsString("'objet' => new Keep(),", $links);

        $code = $draft->table($contacts, new SpipPlatform()->bareDeclaration());
        self::assertStringContainsString("'id_auteur' => new RenumberedId('auteur'),", $code);
        self::assertStringContainsString("'prenom' => new FirstName(),", $code);
        self::assertStringContainsString("'nom' => new FullName(), // @todo review", $code);
        self::assertStringContainsString("'alea_actuel' => new Clear(),", $code);
        self::assertStringContainsString("'texte' => new EmbeddedReferences(), // @todo review", $code);
        self::assertStringContainsString("'courriel' => new Email(),", $code);
    }

    public function testASiteWithAnotherPrefixGetsItsTablesRenamed(): void
    {
        $platform = SpipPlatform::forSite(__DIR__ . '/../fixtures/site');

        self::assertSame('site', $platform->prefix);
        self::assertTrue($platform->bareDeclaration()->has('site_auteurs'));
        self::assertFalse($platform->bareDeclaration()->has('spip_auteurs'));
        self::assertTrue($platform->bareDeclaration()->isRenumbered('auteur'));
        self::assertTrue($platform->frozenSchemas()->load('4.4.28')->has('site_auteurs'));
        self::assertSame('spip', new SpipPlatform()->prefix);
    }

    public function testTheVersionIsReadUnderThePrefixOfTheSite(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE site_meta (nom TEXT, valeur TEXT)');
        $pdo->prepare('INSERT INTO site_meta VALUES (?, ?)')->execute(['plugin', serialize(['SPIP' => ['version' => '4.4.28']])]);

        self::assertSame('4.4.28', new SpipPlatform('site')->readVersion($pdo));
        self::assertNull(new SpipPlatform()->readVersion($pdo));
    }

    /**
     * @param list<string> $renumbered
     */
    private function context(Generator $generator, array $renumbered): Context
    {
        $tables = array_map(static fn(string $type): Table => new Table('t_' . $type, 'id', ['id' => new RenumberedId($type)]), $renumbered);

        return new Context('spip_articles', 'texte', '1', [], reference: null, generator: $generator, declaration: new Declaration($tables));
    }
}
