<?php

/**
 * @package Descriptive Links
 * @author Spuds
 * @copyright (c) 2011-2014 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://mozilla.org/MPL/1.1/.
 *
 * @version 1.0
 *
 */

if (!defined('ELK'))
	die('No access...');

/**
 * 	integrate_preparse_code
 */
function ipc_dlinks(&$part, $i, $previewing)
{
	global $modSettings;

	// We are only interested with whats outside of code tags
	if ($i % 4 !== 0 || $previewing)
		return;

	// Addon enabled and not turned off for this post?
	if (!empty($modSettings['descriptivelinks_enabled']) && empty($_POST['disable_title_convert_url']) && empty($_REQUEST['nt']))
	{
		require_once(SUBSDIR . '/dlinks.class.php');

		// Get the instance of the title class
		$dlinks = Add_Title_Link::context();
		$part = $dlinks->Add_title_to_link($part);
	}
}

/**
 * iaa_dlinks()
 *
 * - integrate_admin_areas
 * - Add a line under modification config
 *
 * @param mixed[] $admin_areas
 */
function iaa_dlinks(&$admin_areas)
{
	global $txt;

	$admin_areas['config']['areas']['modsettings']['subsections']['dlinks'] = array($txt['mods_cat_modifications_dlinks']);
}

/**
 * imm_dlinks()
 *
 * - integrate_modify_modifications hook
 *
 * @param mixed $sub_actions
 */
function imm_dlinks(&$sub_actions)
{
	$sub_actions['dlinks'] = 'ModifydlinksSettings';
}

/**
 * ilp_dlinks()
 *
 * - Permissions hook, integrate_load_permissions, called from ManagePermissions.php
 * - used to add new permisssions
 *
 * @param mixed[] $permissionGroups
 * @param mixed[] $permissionList
 * @param mixed[] $leftPermissionGroups
 * @param mixed[] $hiddenPermissions
 * @param mixed[] $relabelPermissions
 */
function ilp_dlinks(&$permissionGroups, &$permissionList, &$leftPermissionGroups, &$hiddenPermissions, &$relabelPermissions)
{
	$permissionList['board']['disable_title_convert_url'] = array(false, 'topic', 'moderate');
}

/**
 * ModifydlinksSettings()
 *
 * @param mixed $return_config
 * @return
 */
function ModifydlinksSettings()
{
	global $txt, $scripturl, $context;

	$context[$context['admin_menu_name']]['tab_data']['tabs']['dlinks']['description'] = $txt['descriptivelinks_desc'];

	// Lets build a settings form
	require_once(SUBSDIR . '/Settings.class.php');

	// Instantiate the form
	$dlSettings = new Settings_Form();

	$config_vars = array(
		array('check', 'descriptivelinks_enabled'),
		array('check', 'descriptivelinks_title_url'),
		array('check', 'descriptivelinks_title_internal'),
		array('check', 'descriptivelinks_title_bbcurl'),
		array('int', 'descriptivelinks_title_url_count', 'subtext' => $txt['descriptivelinks_title_url_count_sub'], 'postinput' => $txt['descriptivelinks_title_url_count_urls']),
		array('int', 'descriptivelinks_title_url_length'),
		array('text','descriptivelinks_title_url_generic', 60, 'subtext' => $txt['descriptivelinks_title_url_generic_sub']),
	);

	// Load the settings to the form class
	$dlSettings->settings($config_vars);

	// Saving
	if (isset($_GET['save']))
	{
		checkSession();
		Settings_Form::save_db($config_vars);

		redirectexit('action=admin;area=addonsettings;sa=dlinks');
	}

	$context['post_url'] = $scripturl . '?action=admin;area=addonsettings;save;sa=dlinks';
	$context['settings_title'] = $txt['mods_cat_modifications_dlinks'];

	Settings_Form::prepare_db($config_vars);
}