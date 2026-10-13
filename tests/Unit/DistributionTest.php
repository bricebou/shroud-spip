<?php

declare(strict_types=1);

namespace Shroud\Spip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Shroud\Disguiser;
use Shroud\Generator;
use Shroud\Language\French;
use Shroud\Schema\Coverage;
use Shroud\Spip\Distribution;
use Shroud\Spip\SpipPlatform;

final class DistributionTest extends TestCase
{
    private const string SALT = 'sel de test';

    /**
     * La distribution couvre exactement le dernier schéma figé d'un SPIP nu : toute colonne qu'une
     * nouvelle version ajoute fait échouer ce test, jusqu'à ce qu'elle soit classée.
     */
    public function testTheDistributionCoversTheLatestFrozenSchema(): void
    {
        $frozen = new SpipPlatform()->frozenSchemas();

        $coverage = Coverage::compare($frozen->load($frozen->latest()), Distribution::declaration());

        self::assertTrue($coverage->isComplete(), implode("\n", $coverage->report()));
    }

    public function testOnlyTheAuthorsAreRenumbered(): void
    {
        $declaration = Distribution::declaration();

        self::assertSame(['auteur'], $declaration->renumberedTypes());
        foreach (['article', 'rubrique', 'mot', 'document', 'forum', 'syndic'] as $type) {
            self::assertFalse($declaration->isRenumbered($type), $type);
        }
    }

    public function testAnAuthorAndTheirArticleStayLinked(): void
    {
        $generator = new Generator(self::SALT, language: new French(), platform: new SpipPlatform());
        $disguiser = new Disguiser(Distribution::declaration(), $generator);

        $result = $disguiser->disguiseTables([
            'spip_auteurs' => [[
                'id_auteur' => '12', 'nom' => 'Zoé Kowalczyk', 'bio' => 'Associée [ici->aut12]', 'email' => 'z@exemple-reel.test',
                'nom_site' => '', 'url_site' => '', 'login' => 'zoe', 'pass' => 'x', 'low_sec' => 'x', 'statut' => '0minirezo',
                'webmestre' => 'non', 'maj' => '2026-01-01 00:00:00', 'pgp' => '', 'htpass' => 'x', 'en_ligne' => '2026-01-01 00:00:00',
                'alea_actuel' => 'x', 'alea_futur' => 'x', 'prefs' => serialize(['couleur' => 2, 'digest' => ['a' => 'b']]),
                'cookie_oubli' => 'x', 'source' => 'spip', 'lang' => 'fr', 'imessage' => 'oui', 'backup_cles' => 'x',
            ]],
            'spip_auteurs_liens' => [['id_auteur' => '12', 'id_objet' => '3', 'objet' => 'article', 'vu' => 'non']],
            'spip_articles' => [[
                'id_article' => '3', 'surtitre' => '', 'titre' => 'Titre', 'soustitre' => '', 'id_rubrique' => '1',
                'descriptif' => '', 'chapo' => '', 'texte' => 'Par [l\'auteur->aut12] et [->art2]', 'ps' => '', 'date' => '2026-01-01 00:00:00',
                'statut' => 'publie', 'id_secteur' => '1', 'maj' => '2026-01-01 00:00:00', 'export' => 'oui', 'date_redac' => '0000-00-00 00:00:00',
                'visites' => '0', 'referers' => '0', 'popularite' => '0', 'accepter_forum' => 'oui', 'date_modif' => '0000-00-00 00:00:00',
                'lang' => 'fr', 'langue_choisie' => 'non', 'id_trad' => '0', 'nom_site' => '', 'url_site' => '', 'virtuel' => '',
            ]],
            'spip_meta' => [
                ['nom' => 'secret_du_site', 'valeur' => 'abc', 'impt' => 'non', 'maj' => '2026-01-01 00:00:00'],
                ['nom' => 'email_webmaster', 'valeur' => 'moi@exemple-reel.test', 'impt' => 'non', 'maj' => '2026-01-01 00:00:00'],
                ['nom' => 'nom_site', 'valeur' => 'Mon site', 'impt' => 'non', 'maj' => '2026-01-01 00:00:00'],
            ],
            'spip_visites' => [['date' => '2026-01-01', 'visites' => '3', 'maj' => '2026-01-01 00:00:00']],
        ]);

        $renumbered = (string) $generator->permutationFor('auteur')->apply(12);
        self::assertSame($renumbered, $result['spip_auteurs'][0]['id_auteur']);
        self::assertSame($renumbered, $result['spip_auteurs_liens'][0]['id_auteur']);
        self::assertSame('3', $result['spip_auteurs_liens'][0]['id_objet'], 'l\'article n\'est pas renuméroté');
        self::assertSame("Par [l'auteur->aut{$renumbered}] et [->art2]", $result['spip_articles'][0]['texte']);
        self::assertIsString($result['spip_auteurs'][0]['bio']);
        self::assertStringContainsString("->aut{$renumbered}]", $result['spip_auteurs'][0]['bio']);
        self::assertSame('', $result['spip_auteurs'][0]['pass']);
        self::assertIsString($result['spip_auteurs'][0]['prefs']);
        self::assertSame(['couleur' => 2, 'digest' => []], unserialize($result['spip_auteurs'][0]['prefs']));
        self::assertSame('', $result['spip_meta'][0]['valeur']);
        self::assertSame('webmestre@example.org', $result['spip_meta'][1]['valeur']);
        self::assertSame('Mon site', $result['spip_meta'][2]['valeur']);
        self::assertSame([], $result['spip_visites'], 'une table vidée sort sans ligne');
    }

    public function testAForumMessageOfAVisitorGetsItsOwnPseudonym(): void
    {
        $generator = new Generator(self::SALT, platform: new SpipPlatform());
        $disguiser = new Disguiser(Distribution::declaration(), $generator);
        $message = static fn(string $id, string $auteur): array => [
            'id_forum' => $id, 'id_objet' => '3', 'objet' => 'article', 'id_parent' => '0', 'id_thread' => $id,
            'date_heure' => '2026-01-01 00:00:00', 'date_thread' => '2026-01-01 00:00:00', 'titre' => 'Question', 'texte' => 'Bonjour',
            'auteur' => 'Jean Dupont', 'email_auteur' => 'jean@exemple-reel.test', 'nom_site' => '', 'url_site' => '', 'statut' => 'publie',
            'ip' => '203.0.114.7', 'maj' => '2026-01-01 00:00:00', 'id_auteur' => $auteur,
        ];

        $visitor = $disguiser->disguiseRow('spip_forum', $message('1', '0'));
        $other = $disguiser->disguiseRow('spip_forum', $message('2', '0'));
        $author = $disguiser->disguiseRow('spip_forum', $message('3', '12'));

        self::assertNotSame('203.0.114.7', $visitor['ip'], 'une adresse IP fictive');
        self::assertSame($visitor['ip'], $other['ip'], 'la même pour la même adresse');
        self::assertNotSame($visitor['email_auteur'], $other['email_auteur'], 'un visiteur par message');
        self::assertSame($generator->pseudonymFor(new \Shroud\Identity\Reference('auteur', '12'))->email, $author['email_auteur']);
    }

    /**
     * Renommées, les tables gardent leur déclaration, et la couverture de leur schéma figé renommé reste complète.
     */
    public function testTheDistributionUnderAnotherPrefixCoversTheRenamedSchema(): void
    {
        $platform = new SpipPlatform('site');
        $frozen = $platform->frozenSchemas();

        $coverage = Coverage::compare($frozen->load($frozen->latest()), $platform->bareDeclaration());

        self::assertTrue($coverage->isComplete(), implode("\n", $coverage->report()));
        self::assertSame(['auteur'], $platform->bareDeclaration()->renumberedTypes());
    }

    public function testAPublicTextLosesTheIdentifiersItQuotes(): void
    {
        $disguiser = new Disguiser(Distribution::declaration(), new Generator(self::SALT, platform: new SpipPlatform()));
        $article = $disguiser->disguiseRow('spip_articles', [
            'id_article' => '1', 'id_rubrique' => '1', 'date' => '2026-01-01 00:00:00', 'statut' => 'publie', 'id_secteur' => '1',
            'maj' => '2026-01-01 00:00:00', 'export' => 'oui', 'date_redac' => '0000-00-00 00:00:00', 'visites' => '0',
            'referers' => '0', 'popularite' => '0', 'accepter_forum' => 'non', 'date_modif' => '2026-01-01 00:00:00',
            'lang' => 'fr', 'langue_choisie' => 'non', 'id_trad' => '0', 'nom_site' => '', 'url_site' => '', 'virtuel' => '',
            'surtitre' => '', 'titre' => 'Contact', 'soustitre' => '', 'descriptif' => '', 'chapo' => '', 'ps' => '',
            'texte' => 'Écrire à zoe@exemple-reel.test ou appeler le 06 12 34 56 78.',
        ]);

        self::assertIsString($article['texte']);
        self::assertStringStartsWith('Écrire à ', $article['texte']);
        self::assertStringNotContainsString('zoe@exemple-reel.test', $article['texte']);
        self::assertStringNotContainsString('12 34 56 78', $article['texte']);
        self::assertSame('Contact', $article['titre']);
    }

    public function testAnOrdinaryMetaLosesTheIdentifiersItQuotes(): void
    {
        $disguiser = new Disguiser(Distribution::declaration(), new Generator(self::SALT, platform: new SpipPlatform()));
        $meta = static fn(string $nom, string $valeur): mixed => $disguiser->disguiseRow('spip_meta', ['nom' => $nom, 'valeur' => $valeur, 'impt' => 'oui', 'maj' => '2026-01-01 00:00:00'])['valeur'];

        self::assertSame('Mon site', $meta('nom_site', 'Mon site'));
        $contact = $meta('adresse_contact', 'contact@exemple-reel.test');
        self::assertIsString($contact);
        self::assertStringNotContainsString('contact@exemple-reel.test', $contact);
        $configuration = $meta('plugin_x', serialize(['email' => 'contact@exemple-reel.test', 'couleur' => 'bleu']));
        self::assertIsString($configuration);
        $decoded = unserialize($configuration);
        self::assertIsArray($decoded);
        self::assertSame('bleu', $decoded['couleur']);
        self::assertNotSame('contact@exemple-reel.test', $decoded['email'], 'dans une configuration sérialisée aussi');
    }
}
