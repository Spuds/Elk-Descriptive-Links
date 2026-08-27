<?php

/**
 * @package Descriptive Links
 * @author Spuds
 * @copyright (c) 2011-2026 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * https://mozilla.org/MPL/1.1/.
 *
 * @version 2.0.0
 *
 */

use Addons\DescriptiveLinks\DescriptiveLinks;
use ElkArte\Helper\HttpReq;
use ElkArte\Languages\Txt;
use ElkArte\Menu\Menu;
use ElkArte\SettingsForm\SettingsForm;

/**
 * integrate_preparse_tokenized_code, called from \BBC\PreparseCode.php
 *
 * - Allow access to preparse code function on each section of the message
 * after preparse has run on that section
 *
 * @param string $part
 * @param boolean $previewing
 */
function ipc_descriptive_links(&$part, $previewing)
{
	global $modSettings;

	// We are only interested with what's outside of code tags
	if ($previewing)
	{
		return;
	}

	// Addon enabled and not turned off for this post?
	if (!empty($modSettings['descriptive_links_enabled']) && empty($_POST['disable_title_convert_url']) && empty($_REQUEST['nt']))
	{
		// Get the instance of the DescriptiveLinks class
		$descriptiveLinks = DescriptiveLinks::instance();
		$part = $descriptiveLinks->replaceUrlsWithTitles($part);
	}
}

/**
 * Iaa_descriptive_links()
 *
 * - Admin Menu Hook, integrate_admin_areas, called from Menu.php via generic hook
 * - used to add/modify admin menu areas
 *
 * @param Menu $admin_areas
 */
function iaa_descriptive_links($admin_areas)
{
	global $txt;

	// Should already be set but op cache can cause a delay
	if (!isset($txt['mods_cat_modifications_descriptive_links']))
	{
		$txt['mods_cat_modifications_descriptive_links'] = 'Descriptive Links';
	}

	/* @var \ElkArte\Menu\Menu $admin_areas */
	$admin_areas->insertSubsection('addons', 'addonsettings', 'dlinks',  [$txt['mods_cat_modifications_descriptive_links']]);
}

/*
 * Imm_descriptive_links()
 *
 * - Admin Hook, integrate_sa_modify_modifications, called from AddonSettings.php
 * - Used to add/update menu items to the addon area menus
 *
 * @param array $sub_actions
 */
function imm_descriptive_links(&$sub_actions)
{
	global $context, $txt;

	$sub_actions['dlinks'] = [
		'dir' => 'ADDONSDIR\DescriptiveLinks',
		'file' => 'DescriptiveLinksIntegrate.php',
		'function' => 'Modify_Descriptive_Links_Settings',
		'permission' => 'admin_forum',
		'description' => $txt['descriptive_links_desc'],
	];

	if ($context[$context['admin_menu_name']]['current_subsection'] === 'dlinks')
	{
		$context[$context['admin_menu_name']]['object']->prepareTabData([
				'title' => $txt['mods_cat_modifications_descriptive_links'],
				'description' => $txt['descriptive_links_desc'],
				'class' => 'i-clip']
		);
	}
}

/**
 * Modify_Descriptive_Links_Settings()
 */
function Modify_Descriptive_Links_Settings()
{
	global $txt, $scripturl, $context;

	$_req = HttpReq::instance();
	Txt::load('Addons');

	// Instantiate and build a settings form
	$settingsForm = new SettingsForm(SettingsForm::DB_ADAPTER);

	$context['post_url'] = $scripturl . '?action=admin;area=addonsettings;save;sa=dlinks';
	$context['settings_title'] = $txt['mods_cat_modifications_descriptive_links'];
	$config_vars = [
		['check', 'descriptive_links_enabled'],
		['check', 'descriptive_links_title_url'],
		['check', 'descriptive_links_title_internal'],
		['check', 'descriptive_links_title_bbcurl'],
		['int', 'descriptive_links_title_url_count', 'subtext' => $txt['descriptive_links_title_url_count_sub'], 'postinput' => $txt['descriptive_links_title_url_count_urls']],
		['int', 'descriptive_links_title_url_length'],
		['text', 'descriptive_links_title_url_generic', 60, 'subtext' => $txt['descriptive_links_title_url_generic_sub']],
		['text', 'descriptive_links_title_url_video', 60, 'subtext' => $txt['descriptive_links_title_url_video_sub']],
	];

	// Load the settings to the form class
	$settingsForm->setConfigVars($config_vars);

	// Saving
	if (isset($_GET['save']))
	{
		checkSession();

		$settingsForm->setConfigValues((array) $_req->post);
		$settingsForm->save();

		redirectexit('action=admin;area=addonsettings;sa=dlinks');
	}

	// Show the form
	$settingsForm->prepare();
}
