<?php

declare(strict_types=1);

namespace Shroud\Spip;

use Shroud\Declaration\Declaration;
use Shroud\Declaration\ReferenceColumns;
use Shroud\Declaration\Table;
use Shroud\Strategy\Nested\ByColumn;
use Shroud\Strategy\Nested\PerKey;
use Shroud\Strategy\Nested\Serialized;
use Shroud\Strategy\Pseudonym\Email;
use Shroud\Strategy\Pseudonym\FullName;
use Shroud\Strategy\Pseudonym\Login;
use Shroud\Strategy\Pseudonym\Url;
use Shroud\Strategy\Reference\EmbeddedReferences;
use Shroud\Strategy\Reference\RenumberedId;
use Shroud\Strategy\Reference\RenumberedPolymorphicId;
use Shroud\Strategy\Value\Clear;
use Shroud\Strategy\Value\Constant;
use Shroud\Strategy\Value\Keep;
use Shroud\Strategy\Value\ShapedText;
use Shroud\Strategy\Value\ShiftedDate;
use Shroud\Strategy\Wrapper\WithoutIdentifiers;
use Shroud\Strategy\Wrapper\WithoutIps;

/**
 * Déclare un SPIP nu, noyau et plugins de la distribution, dans l'état de SPIP 4.
 *
 * Les auteurs sont renumérotés et pseudonymisés, les messages de forum aussi ; les objets
 * éditoriaux publics, articles, rubriques, mots, documents, sites syndiqués, sont conservés ; les
 * textes des articles, rubriques et documents ne changent que par les raccourcis vers les auteurs
 * et les courriels, numéros de téléphone et adresses IP qu'ils citent, les mots et les sites
 * restent tels quels. Les statistiques de visites et de référents, les
 * résultats de recherche, la file des travaux et les révisions sont vidés. Les secrets de
 * `spip_meta` sont vidés, SPIP les recrée ; les autres métas sont conservées sans leurs
 * courriels, numéros et adresses IP.
 *
 * Un site qui étend ces tables repart de chaque méthode et complète ses colonnes. Les tables sont
 * nommées avec le préfixe `spip` ; `declaration()` les renomme pour un site à un autre préfixe.
 */
final class Distribution
{
    /**
     * Les métas qui portent un secret, un aléa ou une adresse personnelle.
     *
     * @var array<string, string>
     */
    private const array SENSITIVE_METAS = [
        'alea_ephemere' => 'clear',
        'alea_ephemere_ancien' => 'clear',
        'cache_signature' => 'clear',
        'email_envoi' => 'email',
        'email_webmaster' => 'email',
        'secret_des_actions' => 'clear',
        'secret_des_actions_cache' => 'clear',
        'secret_du_site' => 'clear',
        'secret_du_site_cache' => 'clear',
    ];

    /**
     * Rend la déclaration du SPIP nu, ses tables au préfixe du site.
     */
    public static function declaration(string $prefix = 'spip'): Declaration
    {
        if ($prefix === 'spip') {
            return new Declaration(self::tables());
        }

        return new Declaration(array_map(
            static fn(Table $table): Table => new Table($prefix . '_' . substr($table->name, strlen('spip_')), $table->primaryKey, $table->columns, $table->reference, $table->truncated),
            self::tables(),
        ));
    }

    /**
     * Liste les tables du SPIP nu, chacune par sa méthode.
     *
     * @return list<Table>
     */
    public static function tables(): array
    {
        return [
            self::articles(),
            self::auteurs(),
            self::auteursLiens(),
            self::kept('spip_depots', 'id_depot', [
                'id_depot', 'titre', 'descriptif', 'type', 'url_serveur', 'url_brouteur', 'url_archives', 'url_commits',
                'xml_paquets', 'sha_paquets', 'nbr_paquets', 'nbr_plugins', 'nbr_autres', 'maj',
            ]),
            self::kept('spip_depots_plugins', ['id_depot', 'id_plugin'], ['id_depot', 'id_plugin']),
            self::documents(),
            self::liens('spip_documents_liens', 'id_document', ['vu', 'rang_lien']),
            self::forum(),
            self::kept('spip_groupes_mots', 'id_groupe', [
                'id_groupe', 'titre', 'descriptif', 'texte', 'unseul', 'obligatoire', 'tables_liees', 'minirezo', 'comite',
                'forum', 'maj',
            ]),
            Table::truncated('spip_jobs'),
            Table::truncated('spip_jobs_liens'),
            self::meta(),
            self::kept('spip_mots', 'id_mot', ['id_mot', 'titre', 'descriptif', 'texte', 'id_groupe', 'type', 'maj']),
            self::liens('spip_mots_liens', 'id_mot'),
            self::kept('spip_paquets', 'id_paquet', [
                'id_paquet', 'id_plugin', 'prefixe', 'logo', 'version', 'version_base', 'compatibilite_spip', 'branches_spip',
                'description', 'auteur', 'credit', 'licence', 'copyright', 'lien_doc', 'lien_demo', 'lien_dev', 'etat',
                'etatnum', 'dependances', 'procure', 'date_crea', 'date_modif', 'id_depot', 'nom_archive', 'nbo_archive',
                'maj_archive', 'src_archive', 'traductions', 'actif', 'installe', 'recent', 'maj_version', 'superieur',
                'obsolete', 'attente', 'constante', 'signature',
            ]),
            self::kept('spip_plugins', 'id_plugin', [
                'id_plugin', 'prefixe', 'nom', 'slogan', 'vmax', 'date_crea', 'date_modif', 'compatibilite_spip', 'branches_spip',
            ]),
            Table::truncated('spip_referers'),
            Table::truncated('spip_referers_articles'),
            Table::truncated('spip_resultats'),
            self::rubriques(),
            self::kept('spip_syndic', 'id_syndic', [
                'id_syndic', 'id_rubrique', 'id_secteur', 'nom_site', 'url_site', 'url_syndic', 'descriptif', 'maj',
                'syndication', 'statut', 'date', 'date_syndic', 'date_index', 'moderation', 'miroir', 'oubli', 'resume',
            ]),
            self::kept('spip_syndic_articles', 'id_syndic_article', [
                'id_syndic_article', 'id_syndic', 'titre', 'url', 'date', 'lesauteurs', 'maj', 'statut', 'descriptif',
                'lang', 'url_source', 'source', 'tags', 'raw_data', 'raw_format', 'raw_methode',
            ]),
            // La table d'essai d'ALTER TABLE des mises à jour : absente d'un site neuf, SPIP la recrée.
            Table::truncated('spip_test'),
            self::kept('spip_types_documents', 'extension', [
                'extension', 'titre', 'descriptif', 'mime_type', 'inclus', 'upload', 'media_defaut', 'maj',
            ]),
            self::urls(),
            Table::truncated('spip_versions'),
            Table::truncated('spip_versions_fragments'),
            Table::truncated('spip_visites'),
            Table::truncated('spip_visites_articles'),
        ];
    }

    /**
     * Déclare les articles : contenu public conservé, seuls les raccourcis vers les auteurs et les identifiants cités changent.
     */
    public static function articles(): Table
    {
        return new Table('spip_articles', 'id_article', [
            ...Keep::forColumns([
                'id_article', 'id_rubrique', 'date', 'statut', 'id_secteur', 'maj', 'export', 'date_redac', 'visites',
                'referers', 'popularite', 'accepter_forum', 'date_modif', 'lang', 'langue_choisie', 'id_trad', 'nom_site',
                'url_site', 'virtuel',
            ]),
            ...self::publicTexts(['surtitre', 'titre', 'soustitre', 'descriptif', 'chapo', 'texte', 'ps']),
        ]);
    }

    /**
     * Déclare les auteurs : renumérotés, pseudonymisés, leurs secrets vidés ; leur biographie garde
     * sa forme, et les courriels, numéros de téléphone et adresses IP qu'elle cite deviennent fictifs.
     */
    public static function auteurs(): Table
    {
        return new Table('spip_auteurs', 'id_auteur', [
            'id_auteur' => new RenumberedId('auteur'),
            'nom' => new FullName(),
            'bio' => new WithoutIdentifiers(new ShapedText()),
            'email' => new Email(),
            'nom_site' => new ShapedText(),
            'url_site' => new Url(keepHost: false),
            'login' => new Login(),
            'pass' => new Clear(),
            'low_sec' => new Clear(),
            'statut' => new Keep(),
            'webmestre' => new Keep(),
            'maj' => new Keep(),
            'pgp' => new Clear(),
            'htpass' => new Clear(),
            'en_ligne' => new ShiftedDate(),
            'alea_actuel' => new Clear(),
            'alea_futur' => new Clear(),
            // Les secrets WebDAV de `digest` sont indexés par l'email.
            'prefs' => new Serialized(new PerKey(['digest' => new Clear()], new Keep())),
            'cookie_oubli' => new Clear(),
            'source' => new Keep(),
            'lang' => new Keep(),
            'imessage' => new Keep(),
            'backup_cles' => new Clear(),
        ], ReferenceColumns::fromFixedType('auteur', 'id_auteur'));
    }

    /**
     * Déclare les liens des auteurs aux objets qu'ils signent.
     */
    public static function auteursLiens(): Table
    {
        return new Table('spip_auteurs_liens', ['id_auteur', 'id_objet', 'objet'], [
            'id_auteur' => new RenumberedId('auteur'),
            'id_objet' => new RenumberedPolymorphicId('objet'),
            'objet' => new Keep(),
            'vu' => new Keep(),
        ]);
    }

    /**
     * Déclare les documents : fichiers et légendes publics ; les fichiers eux-mêmes sont hors périmètre.
     */
    public static function documents(): Table
    {
        return new Table('spip_documents', 'id_document', [
            ...Keep::forColumns([
                'id_document', 'id_vignette', 'extension', 'date', 'fichier', 'taille', 'largeur', 'hauteur', 'duree',
                'media', 'mode', 'distant', 'statut', 'date_publication', 'brise', 'maj',
            ]),
            ...self::publicTexts(['titre', 'descriptif', 'credits', 'alt']),
        ]);
    }

    /**
     * Déclare les messages de forum : un auteur inscrit garde son pseudonyme, un visiteur en reçoit
     * un par message ; le titre et le texte gardent leur forme, les courriels, numéros de téléphone
     * et adresses IP qu'ils citent deviennent fictifs ; l'adresse IP du message aussi, la même pour
     * la même adresse.
     */
    public static function forum(): Table
    {
        return new Table('spip_forum', 'id_forum', [
            'id_forum' => new Keep(),
            'id_objet' => new RenumberedPolymorphicId('objet'),
            'objet' => new Keep(),
            'id_parent' => new Keep(),
            'id_thread' => new Keep(),
            'date_heure' => new Keep(),
            'date_thread' => new Keep(),
            'titre' => new WithoutIdentifiers(new ShapedText()),
            'texte' => new WithoutIdentifiers(new ShapedText()),
            'auteur' => new FullName(),
            'email_auteur' => new Email(),
            'nom_site' => new ShapedText(),
            'url_site' => new Url(keepHost: false),
            'statut' => new Keep(),
            'ip' => new WithoutIps(),
            'maj' => new Keep(),
            'id_auteur' => new RenumberedId('auteur'),
        ], ReferenceColumns::fromFixedType('auteur', 'id_auteur')->withFallback(ReferenceColumns::fromFixedType('forum', 'id_forum')));
    }

    /**
     * Déclare les métas : les secrets sont vidés, SPIP les recrée ; le courriel du webmestre devient
     * fictif ; tout le reste, configuration du site et des plugins, est conservé, sérialisé ou non,
     * sans les courriels, numéros de téléphone et adresses IP qu'il cite.
     */
    public static function meta(): Table
    {
        $cases = [];
        foreach (self::SENSITIVE_METAS as $name => $treatment) {
            $cases[$name] = $treatment === 'email' ? new Constant('webmestre@example.org') : new Clear();
        }

        return new Table('spip_meta', 'nom', [
            'nom' => new Keep(),
            'valeur' => new ByColumn('nom', $cases, new Serialized(new PerKey([], new WithoutIdentifiers(), deep: true), new WithoutIdentifiers())),
            'impt' => new Keep(),
            'maj' => new Keep(),
        ]);
    }

    /**
     * Déclare les rubriques : contenu public conservé, seuls les raccourcis et les identifiants cités changent.
     */
    public static function rubriques(): Table
    {
        return new Table('spip_rubriques', 'id_rubrique', [
            ...Keep::forColumns([
                'id_rubrique', 'id_parent', 'id_secteur', 'maj', 'statut', 'date', 'lang', 'langue_choisie', 'statut_tmp',
                'date_tmp', 'profondeur',
            ]),
            ...self::publicTexts(['titre', 'descriptif', 'texte']),
        ]);
    }

    /**
     * Déclare les URL propres : celle d'un auteur porte son nom et se réécrit, les autres restent.
     */
    public static function urls(): Table
    {
        return new Table('spip_urls', ['url', 'id_parent'], [
            'id_parent' => new Keep(),
            // Unique : deux auteurs au même pseudonyme ne doivent pas se heurter sur la clé primaire.
            'url' => new ByColumn('type', ['auteur' => new Url(unique: true)], new Keep()),
            'type' => new Keep(),
            'id_objet' => new RenumberedPolymorphicId('type'),
            'date' => new Keep(),
            'segments' => new Keep(),
            'perma' => new Keep(),
            'langue' => new Keep(),
        ], ReferenceColumns::fromTypeColumn('type', 'id_objet'));
    }

    /**
     * Déclare une table de liens polymorphe : l'objet lié suit sa renumérotation, le reste est conservé.
     *
     * @param list<string> $extraColumns
     */
    private static function liens(string $name, string $idColumn, array $extraColumns = []): Table
    {
        return new Table($name, [$idColumn, 'id_objet', 'objet'], [
            $idColumn => new Keep(),
            'id_objet' => new RenumberedPolymorphicId('objet'),
            'objet' => new Keep(),
            ...Keep::forColumns($extraColumns),
        ]);
    }

    /**
     * Déclare une table entièrement conservée.
     *
     * @param string|list<string> $primaryKey
     * @param list<string> $columns
     */
    private static function kept(string $name, string|array $primaryKey, array $columns): Table
    {
        return new Table($name, $primaryKey, Keep::forColumns($columns));
    }

    /**
     * Déclare des textes publics conservés : les raccourcis vers les auteurs suivent la renumérotation,
     * les courriels, numéros de téléphone et adresses IP qu'ils citent sont remplacés.
     *
     * @param list<string> $columns
     *
     * @return array<string, WithoutIdentifiers>
     */
    private static function publicTexts(array $columns): array
    {
        return array_fill_keys($columns, new WithoutIdentifiers(new EmbeddedReferences()));
    }
}
