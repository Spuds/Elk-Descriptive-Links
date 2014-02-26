<?php

/**
 * This file is a simplified database installer. It does what it is supposed to.
 */

// If we have found SSI.php and we are outside of SMF, then we are running standalone.
if (file_exists(dirname(__FILE__) . '/SSI.php') && !defined('ELK'))
	require_once(dirname(__FILE__) . '/SSI.php');
elseif (!defined('ELK')) 
	die('<b>Error:</b> Cannot install - please verify you put this file in the same place as ElkArte\'s SSI.php.');

global $modSettings;

// List settings here in the format: setting_key => default_value.  Escape any "s. (" => \")
// No that's not hard coded ;) ... regardless of what site or from what country you may visit those are still valid blacklist titles
$mod_settings = array(
	'descriptivelinks_enabled' => 0,
	'descriptivelinks_title_url' => 1,
	'descriptivelinks_title_internal' => 1,
	'descriptivelinks_title_bbcurl' => 1,
	'descriptivelinks_title_url_count' => 5,
	'descriptivelinks_title_url_generic' => 'home,index,page title,default,login,logon,welcome,ebay',
	'descriptivelinks_title_url_length' => 80,
);

// Update mod settings if applicable
foreach ($mod_settings as $new_setting => $new_value)
{
	if (!isset($modSettings[$new_setting]))
		updateSettings(array($new_setting => $new_value));
}

if (ELK === 'SSI')
   echo 'Congratulations! You have successfully installed this modification!';