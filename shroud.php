<?php

/**
 * Configuration du SPIP nu, pour les commandes lancées depuis une copie de travail de ce dépôt :
 * `shroud-baseline` y trouve la plateforme dont il fige le schéma, et `shroud-diff` vérifie que la
 * déclaration du SPIP nu couvre la base d'un SPIP nu. Elle ne sert à aucun dump : son sel n'a pas
 * à rester secret.
 */

declare(strict_types=1);

use Shroud\Configuration;
use Shroud\Language\French;
use Shroud\Spip\SpipPlatform;

$platform = new SpipPlatform();

return new Configuration(
    declaration: $platform->bareDeclaration(),
    salt: 'spip-nu',
    locale: 'fr_FR',
    language: new French(),
    platform: $platform,
);
