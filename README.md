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

Shroud pour SPIP se construit brique après brique. Ce dépôt ne contient pour l'instant que
l'outillage.

## Développement

```sh
composer install
composer check   # syntaxe, style, Rector, analyse statique, tests
composer fix     # appliquer Rector, puis le style
```

Shroud pour SPIP requiert PHP 8.4 ou supérieur.

## Licence

Shroud pour SPIP est distribué sous la [GNU General Public License v3.0 ou ultérieure](LICENSE).
