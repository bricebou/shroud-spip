<?php

declare(strict_types=1);

namespace Shroud\Spip;

use Shroud\Adapter\Pdo\ConnectionSettings;
use Shroud\Context;
use Shroud\Declaration\Declaration;
use Shroud\Platform\AbstractPlatform;
use Shroud\Schema\DraftRule;
use Shroud\Schema\FrozenSchemas;
use Shroud\Schema\Glossary\English;
use Shroud\Schema\Glossary\French;
use Shroud\Strategy\Reference\EmbeddedReferences;
use Shroud\Strategy\Reference\RenumberedPolymorphicId;
use Shroud\Strategy\Value\Clear;
use Shroud\Strategy\Value\Keep;

/**
 * Décrit SPIP au noyau de Shroud : son balisage, ses raccourcis, sa distribution, ses schémas figés,
 * sa version, son fichier de connexion et le vocabulaire de ses colonnes.
 *
 * Les tables d'un site portent le préfixe que fixe son `config/connect.php`, `spip` par défaut ;
 * `forSite()` le lit.
 */
final readonly class SpipPlatform extends AbstractPlatform
{
    /**
     * Les alias de raccourcis de SPIP et le type d'objet qu'ils désignent, tels que `typer_raccourci()`
     * les fixe dans `ecrire/inc/lien.php`. Sans préfixe, `[->12]` désigne l'article 12. Un préfixe
     * qui est lui-même un type d'objet n'a pas besoin d'y figurer.
     *
     * @var array<string, string>
     */
    public const array SHORTCUT_ALIASES = [
        '' => 'article',
        'art' => 'article',
        'aut' => 'auteur',
        'br' => 'breve',
        'breve' => 'breve',
        'brève' => 'breve',
        'doc' => 'document',
        'emb' => 'document',
        'im' => 'document',
        'image' => 'document',
        'img' => 'document',
        'rub' => 'rubrique',
    ];

    /**
     * @param string $prefix le préfixe des tables du site, sans le tiret bas
     */
    public function __construct(
        public string $prefix = 'spip',
    ) {}

    /**
     * Rend la plateforme d'un site, au préfixe de tables que fixe son `config/connect.php`.
     */
    public static function forSite(string $directory): self
    {
        return new self(ConnectFile::read($directory . '/config/connect.php')->prefix);
    }

    public function name(): string
    {
        return 'spip';
    }

    /**
     * Rend les marqueurs de langue des blocs `<multi>`, `[en]`, et les raccourcis de liens, `->art12`,
     * `-> aut 12` ; les modèles, `<img12|left>`, sont déjà des balises pour le noyau.
     */
    public function preservedMarkup(): array
    {
        return ['\[[a-z]{2,3}(?:_[a-zA-Z]{2,4})?\]', '->>?\s*\p{L}*\s*\d+'];
    }

    /**
     * Renumérote les raccourcis `[->aut12]` et les modèles `<img7|left>` dont le type est renuméroté.
     *
     * Comme dans `typer_raccourci()`, la cible d'un lien admet des espaces autour du préfixe,
     * `[-> aut 12]`, et un modèle une espace avant son numéro, `<img 7|left>`. Le préfixe se résout
     * par les alias de cette même fonction ; un lien sans préfixe, `[->12]`, vise un article, et un
     * modèle sans préfixe, `<12>`, n'est pas un raccourci.
     */
    public function renumberReferences(string $text, Context $context): string
    {
        return preg_replace_callback(
            '/(->>?\s*|<)(\p{Ll}*)(\s*)(\d+)/u',
            static function (array $match) use ($context): string {
                if ($match[2] === '' && $match[1] === '<') {
                    return $match[0];
                }
                $type = self::SHORTCUT_ALIASES[$match[2]] ?? $match[2];
                if (! $context->declaration->isRenumbered($type)) {
                    return $match[0];
                }

                return $match[1] . $match[2] . $match[3] . $context->generator->permutationFor($type)->apply((int) $match[4]);
            },
            $text,
        ) ?? $text;
    }

    public function bareDeclaration(): Declaration
    {
        return Distribution::declaration($this->prefix);
    }

    public function frozenSchemas(): FrozenSchemas
    {
        return new FrozenSchemas(__DIR__ . '/../resources/schema', 'spip', 'spip_', $this->prefix . '_');
    }

    /**
     * Lit la version de SPIP dans la méta `plugin`, où SPIP se liste lui-même parmi les plugins actifs.
     */
    public function readVersion(\PDO $pdo): ?string
    {
        try {
            $statement = $pdo->query('SELECT valeur FROM ' . $this->prefix . "_meta WHERE nom = 'plugin'");
        } catch (\PDOException) {
            return null;
        }
        $value = $statement === false ? false : $statement->fetchColumn();
        // La connexion du noyau lit en flux : un curseur resté ouvert bloquerait la requête suivante.
        if ($statement !== false) {
            $statement->closeCursor();
        }

        return is_string($value) ? self::versionFromMeta($value) : null;
    }

    /**
     * Extrait la version de SPIP de la valeur sérialisée de la méta `plugin`, sans instancier d'objet.
     */
    public static function versionFromMeta(string $serialized): ?string
    {
        $plugins = @unserialize($serialized, ['allowed_classes' => false]);
        if (! is_array($plugins) || ! is_array($plugins['SPIP'] ?? null)) {
            return null;
        }
        $version = $plugins['SPIP']['version'] ?? null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    public function connectionFile(): string
    {
        return 'config/connect.php';
    }

    public function readConnection(string $path): ConnectionSettings
    {
        return ConnectFile::read($path)->settings();
    }

    /**
     * Rend les secrets de SPIP à vider, le couple `objet` + `id_objet` des liaisons, et les textes
     * éditoriaux, dont seuls les raccourcis changeraient.
     */
    public function draftRules(): array
    {
        return [
            new DraftRule('/^(pass|htpass|low_sec|pgp|alea_actuel|alea_futur|cookie_oubli|backup_cles)$/', 'new Clear()', imports: [Clear::class]),
            new DraftRule('/^id_objet$/', "new RenumberedPolymorphicId('objet')", sibling: 'objet', imports: [RenumberedPolymorphicId::class]),
            new DraftRule('/^objet$/', 'new Keep()', imports: [Keep::class]),
            new DraftRule('/^(surtitre|titre|soustitre|chapo|descriptif|texte|ps)$/', 'new EmbeddedReferences()', review: true, imports: [EmbeddedReferences::class]),
        ];
    }

    /**
     * Rend le français d'abord, langue des tables de SPIP et de ses plugins, puis l'anglais de leurs emprunts.
     */
    public function glossaries(): array
    {
        return [new French(), new English()];
    }
}
