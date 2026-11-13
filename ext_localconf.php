<?php

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['l10n_page_perms']
    = \Belsignum\L10nPagePerms\Hook\DataHandlerHook::class;
