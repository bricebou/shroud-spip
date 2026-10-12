<?php

if (!defined('_ECRIRE_INC_VERSION')) {
    return;
}
$GLOBALS['spip_connect_version'] = 0.8;
spip_connect_db('db', '', 'utilisateur', 'pas un vrai mot de passe', 'la_base', 'mysql', 'site', '', '');
