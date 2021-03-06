<?php

/**
 * @package Descriptive Links
 * @author Spuds
 * @copyright (c) 2011-2021 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://mozilla.org/MPL/1.1/.
 *
 * @version 1.0.3
 *
 */

/**
 * integrate_preparse_code, called from Post.subs
 *
 * - Allows access to the preparse code function on each section of the message
 * after preparse has run on that section
 * - Parts will be 0 = outside, 1 = begin tag, 2 = inside, 3 = close tag
 *
 * @param string $part
 * @param int $i
 * @param boolean $previewing
 */
function ipc_dlinks(&$part, $i, $previewing)
{
	global $modSettings;

	// We are only interested with what's outside of code tags
	if ($i % 4 !== 0 || $previewing)
	{
		return;
	}

	// Addon enabled and not turned off for this post?
	if (!empty($modSettings['descriptivelinks_enabled']) && empty($_POST['disable_title_convert_url']) && empty($_REQUEST['nt']))
	{
		require_once(SUBSDIR . '/dlinks.class.php');

		// Get the instance of the title class
		$dlinks = Add_Title_Link::dlinks();
		$part = $dlinks->Add_title_to_link($part);
	}
}

/**
 * iaa_dlinks()
 *
 * - Admin Hook, integrate_admin_areas, called from Admin.php
 * - used to add/modify admin menu areas
 *
 * @param array $admin_areas
 */
function iaa_dlinks(&$admin_areas)
{
	global $txt;

	// Should be but op cache causes a delay
	if (!isset($txt['mods_cat_modifications_dlinks']))
	{
		$txt['mods_cat_modifications_dlinks'] = 'DLinks';
	}

	$admin_areas['config']['areas']['addonsettings']['subsections']['dlinks'] = array($txt['mods_cat_modifications_dlinks']);
}

/**
 * imm_dlinks()
 *
 * - Admin Hook, integrate_modify_modifications, called from AddonSettings.controller.php
 * - used to add subactions to the addon area
 *
 * @param array $sub_actions
 */
function imm_dlinks(&$sub_actions)
{
	global $context, $txt;

	$sub_actions['dlinks'] = array(
		'dir' => SOURCEDIR,
		'file' => 'dlinks.integration.php',
		'function' => 'ModifydlinksSettings',
		'permission' => 'admin_forum',
	);

	$context[$context['admin_menu_name']]['tab_data']['tabs']['dlinks']['description'] = $txt['descriptivelinks_desc'];
}

/**
 * ilp_dlinks()
 *
 * - Permissions hook, integrate_load_permissions, called from ManagePermissions.php
 * - used to add new permissions
 *
 * @param array $permissionGroups
 * @param array $permissionList
 * @param array $leftPermissionGroups
 * @param array $hiddenPermissions
 * @param array $relabelPermissions
 */
function ilp_dlinks(&$permissionGroups, &$permissionList, &$leftPermissionGroups, &$hiddenPermissions, &$relabelPermissions)
{
	$permissionList['board']['disable_title_convert_url'] = array(false, 'topic', 'moderate');
}

/**
 * ModifydlinksSettings()
 */
function ModifydlinksSettings()
{
	global $txt, $scripturl, $context;

	// Lets build a settings form
	require_once(SUBSDIR . '/SettingsForm.class.php');

	// Instantiate the form
	$dlSettings = new Settings_Form();

	$config_vars = array(
		array('check', 'descriptivelinks_enabled'),
		array('check', 'descriptivelinks_title_url'),
		array('check', 'descriptivelinks_title_internal'),
		array('check', 'descriptivelinks_title_bbcurl'),
		array('int', 'descriptivelinks_title_url_count', 'subtext' => $txt['descriptivelinks_title_url_count_sub'], 'postinput' => $txt['descriptivelinks_title_url_count_urls']),
		array('int', 'descriptivelinks_title_url_length'),
		array('text', 'descriptivelinks_title_url_generic', 60, 'subtext' => $txt['descriptivelinks_title_url_generic_sub']),
		array('text', 'descriptivelinks_title_url_video', 60, 'subtext' => $txt['descriptivelinks_title_url_video_sub']),
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
