<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="shroud-dark.svg">
    <img src="shroud.svg" alt="" width="128" height="128">
  </picture>
</p>

# Shroud pour SPIP

Plateforme SPIP de [Shroud](https://github.com/bricebou/shroud), pseudonymisation déterministe
et indépendante du CMS d'une base de données relationnelle : la déclaration de la distribution
nue, les schémas figés de chaque version de SPIP, le fichier de connexion et les conventions
de texte.

Le noyau ne connaît aucun CMS ; ce paquet lui apporte ce qui appartient à SPIP :

- la **déclaration du SPIP nu**, noyau et plugins de la distribution, au préfixe de tables du
  site : les auteurs et les messages de forum sont pseudonymisés ; les contenus publics sont
  conservés, les textes des articles, rubriques et documents sans les courriels, numéros de
  téléphone et adresses IP qu'ils citent, les mots-clés et les sites syndiqués tels quels ; les
  secrets, les statistiques et les révisions sont vidés ;
- les **schémas figés** de chaque version de SPIP dont le schéma change, auxquels `shroud-diff`
  compare la base d'un site ;
- la **lecture de `config/connect.php`**, pour se connecter sans répéter les accès, et de la
  version de SPIP dans la méta `plugin` ;
- les **conventions de texte** : les blocs `<multi>` et leurs marqueurs de langue sont préservés,
  et les raccourcis `[->aut12]` et modèles `<img7|left>` suivent la renumérotation de leur objet,
  quand son type est renuméroté ;
- les **règles du brouillon** : secrets de SPIP à vider, liaisons `objet` + `id_objet`, textes
  éditoriaux à conserver sans leurs identifiants, glossaires français puis anglais.

## Prérequis

- **PHP 8.4** ou supérieur, avec les extensions `intl`, `mbstring`, `pdo` et `pdo_mysql`.
- **Une base MySQL ou MariaDB.** Shroud lit le schéma dans `information_schema` et écrit un dump
  au format de MySQL : un SPIP installé sur SQLite ou PostgreSQL n'est pas pris en charge. Les
  essais ont porté sur MariaDB 10.11 et 11.8.
- **Un accès en lecture à la base**, depuis la machine qui lance les commandes : les accès de
  `config/connect.php` suffisent.

## Installation

```sh
composer require --dev momh/shroud-spip
```

Le paquet installe le noyau, [momh/shroud](https://github.com/bricebou/shroud), et ses commandes
dans `vendor/bin/`. La documentation du noyau détaille la déclaration, les stratégies, les deux
régimes au sens du RGPD et les commandes.

## Couvrir un site

Un site part de la déclaration du SPIP nu et la complète de ce que sa base porte en plus : les
tables et les colonnes de ses plugins communautaires, et ses ajouts propres. Le fichier
`shroud.php`, versionné à la racine du site, en est le point de départ :

```php
<?php

declare(strict_types=1);

use Shroud\Configuration;
use Shroud\Language\French;
use Shroud\Spip\SpipPlatform;

// Le préfixe des tables est celui de config/connect.php.
$platform = SpipPlatform::forSite(__DIR__);
$declaration = $platform->bareDeclaration();

return new Configuration(
    declaration: $declaration,
    salt: 'un sel propre au projet',
    language: new French(),
    platform: $platform,
);
```

`shroud-diff` compare alors la base du site à sa déclaration, en se servant du schéma figé du
SPIP nu de même version comme ligne de base : ce que la base porte en plus du SPIP nu revient
au site, plugins et ajouts ; ce que le SPIP nu porte et que la déclaration du paquet ignore
revient à ce paquet, et mérite d'y être signalé.

```sh
vendor/bin/shroud-diff --connect           # les écarts, et un brouillon de déclaration
vendor/bin/shroud-diff --connect --write   # le brouillon écrit dans shroud.suggestions.php
```

Le brouillon propose une stratégie par colonne ; il se relit, colonne par colonne, avant d'être
intégré à `shroud.php`, soit recopié, soit appliqué à la déclaration :

```php
$declaration = (require __DIR__ . '/shroud.suggestions.php')($declaration);
```

Quand `shroud-diff` répond « Complete declaration », la passe à blanc vérifie toutes les
valeurs de la base, puis le dump s'écrit :

```sh
vendor/bin/shroud-dump --connect --dry-run
vendor/bin/shroud-dump --connect --output=dump.sql
```

Chaque plugin ajouté, chaque mise à jour qui touche au schéma, rouvre l'écart : `shroud-diff`
le signale par son code de sortie, et une intégration continue qui dispose de la base peut en
faire un garde-fou.

## Figer le schéma d'une nouvelle version de SPIP

À la sortie d'une version de SPIP, ce paquet doit en connaître le schéma nu : c'est la ligne de
base des sites qui y passent. Les commandes se lancent depuis une copie de travail de ce dépôt,
dont le `shroud.php` nomme la plateforme SPIP et la déclaration du SPIP nu :

1. Installer un SPIP nu de cette version, avec les seuls plugins de la distribution, sur une
   base vide.
2. Figer son schéma ; la version est lue dans la base, et le fichier s'écrit dans
   `resources/schema/` :

   ```sh
   vendor/bin/shroud-baseline --connect=/chemin/du/spip/nu/config/connect.php
   ```

3. Le comparer au schéma figé précédent, colonnes triées : un site mis à jour et une
   installation neuve ne rangent pas toujours leurs colonnes dans le même ordre. Un schéma
   identique ne justifie aucun fichier : le supprimer, les sites de cette version se rapportent
   au précédent.

   ```sh
   diff <(jq -S . resources/schema/spip-4.4.28.json) <(jq -S . resources/schema/spip-x.y.z.json)
   ```

4. Vérifier que la déclaration du SPIP nu, dans `src/Distribution.php`, couvre ce SPIP nu : une
   table ou une colonne nouvelle s'y déclare, avec sa stratégie, jusqu'à ce que la commande
   réponde « Complete declaration ». `composer check` le vérifie aussi sur le dernier schéma figé.

   ```sh
   vendor/bin/shroud-diff --connect=/chemin/du/spip/nu/config/connect.php
   ```

## Développement

```sh
composer install
composer check   # syntaxe, style, Rector, analyse statique, tests
composer fix     # appliquer Rector, puis le style
```

## Licence

Shroud pour SPIP est distribué sous la [GNU General Public License v3.0 ou ultérieure](LICENSE).
