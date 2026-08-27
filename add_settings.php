<?php

/**
 * This file is a simplified database installer. It does what it is supposed to.
 */

// If we have found SSI.php and we are outside ElkArte, then we are running standalone.
if (file_exists(__DIR__ . '/SSI.php') && !defined('ELK'))
{
	require_once(__DIR__ . '/SSI.php');
}
elseif (!defined('ELK'))
{
	die('<b>Error:</b> Cannot install - please verify you put this file in the same place as ElkArte\'s SSI.php.');
}

global $modSettings;

// List settings here in the format: setting_key => default_value.  Escape any "s. (" => \")
// No that's not hard coded ;) ... regardless of what site or from what country you may visit those are still valid blacklist titles
$mod_settings = [
	'descriptive_links_enabled' => 0,
	'descriptive_links_title_url' => 1,
	'descriptive_links_title_internal' => 1,
	'descriptive_links_title_bbcurl' => 1,
	'descriptive_links_title_url_count' => 5,
	'descriptive_links_title_url_generic' => 'home,index,page title,default,login,logon,welcome,ebay,redirecting...',
	'descriptive_links_title_url_length' => 80,
];

// Update mod settings if applicable
foreach ($mod_settings as $new_setting => $new_value)
{
	if (!isset($modSettings[$new_setting]))
	{
		updateSettings(array($new_setting => $new_value));
	}
}

if (ELK === 'SSI')
{
	echo 'Congratulations! You have successfully installed this modification!';
}
