<?php

//
// Website Information
// 

$LANG['LABEL_LICENSE_NAME'] = 'Website Name';
$LANG['ERROR_LICENSE_NAME'] = 'Please enter your website name.';

$LANG['LABEL_INSTALLATION_KEY'] = 'Installation Key';
$LANG['ERROR_INSTALLATION_KEY'] = 'Please enter installation key.';

$LANG['LABEL_KEYLESS_ENTRY'] = 'I do not have an installation key.';

// 
// Mixin defaults to global language variable
// 

global $PHPR_INSTALLER_LANG;
$PHPR_INSTALLER_LANG = isset($PHPR_INSTALLER_LANG) ? $PHPR_INSTALLER_LANG : array();
$PHPR_INSTALLER_LANG = array_merge($LANG, $PHPR_INSTALLER_LANG);