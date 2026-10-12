<?php

if (!defined('_ECRIRE_INC_VERSION')) {
    return;
}
defined('_MYSQL_SET_SQL_MODE') || define('_MYSQL_SET_SQL_MODE', value: true);
$GLOBALS['spip_connect_version'] = 0.8;
spip_connect_db(
    'db',
    '3307',
    'utilisateur',
    'mot de \'passe\' "à" espaces',
    'la_base',
    'mysql',
    'prefixe',
    '',
    ''
);
