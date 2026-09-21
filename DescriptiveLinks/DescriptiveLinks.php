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

namespace Addons\DescriptiveLinks;

use ElkArte\Helper\Util;

/**
 * Searches a post for all links and attempts to replace them with the destination page title
 *
 * - Uses database query for internal links and web request for external links
 * - Will not change links if they resolve to names in the admin disabled list
 * - Truncates long titles per admin panel settings
 * - Updates the link in the message body itself
 * - User permission to allow disabling of this option for a given message.
 */
class DescriptiveLinks
{
	/** @var DescriptiveLinks Holds the current instance of the class */
	private static $_descriptive_links;

	/** @var array Cache resolved internal titles to avoid duplicate DB queries */
	protected static $_internal_cache = [];

	/** @var array Comma separated string or titles to not convert */
	protected $_links_title_generic_names;

	/** @var array Comma separated string of links to skip */
	protected $_skip_video_links;

	/** @var int maximum length of a title tag */
	protected $_max_title_length = 0;

	/** @var int Number of conversions done in this message */
	protected $_conversions = 0;

	/** @var string dna of internal links boardurl or scripturl */
	protected $_internal = '';

	/** @var int maximum number of conversions allowed, more = more time */
	protected $_max_conversions = 0;

	/** @var array urls found in the message to inspect for conversion */
	protected $_urls = [];

	/** @var string current url tag being processed */
	protected $_url = '';

	/** @var string Holds the message string we are working on */
	protected $_message = '';

	/** @var string Cleaned, fully qualified url for use in the converted tag */
	protected $_url_return = '';

	/** @var string Title text found in a given url */
	protected $_title = '';

	/** @var DescriptiveUrlBatchFetcher Addon fetcher service for external URLs */
	private DescriptiveUrlBatchFetcher $_urlBatchFetcher;

	/**
	 * Loads some modSettings for this addon to the class vars
	 */
	public function __construct()
	{
		global $modSettings, $boardurl, $scripturl;

		// Get the generic names that we will never allow a link to convert to
		$generic = !empty($modSettings['descriptive_links_title_url_generic']) ? explode(',', $modSettings['descriptive_links_title_url_generic']) : [];
		$this->_links_title_generic_names = array_map(static fn($v) => strtolower(trim($v)), $generic);
		$this->_internal = !empty($modSettings['queryless_urls']) ? $boardurl : $scripturl;
		$this->_max_conversions = (int) ($modSettings['descriptive_links_title_url_count'] ?? 0);
		$this->_max_title_length = (int) ($modSettings['descriptive_links_title_url_length'] ?? 0);
		$skip = !empty($modSettings['descriptive_links_title_url_video']) ? explode(',', $modSettings['descriptive_links_title_url_video']) : [];
		$this->_skip_video_links = array_values(array_filter(array_map('trim', $skip)));
		$this->_urlBatchFetcher = new DescriptiveUrlBatchFetcher([
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_TIMEOUT => 5,
		], 3);
	}

	/**
	 * Takes a message string and converts url and plain text links to titled links
	 *
	 * - Does NOT check if the link is inside tags (e.g. code) that should not be converted
	 * here that is taken care of by ipc_descriptive_links
	 * - It must be supplied strings in which you want the tags converted
	 * - If bbc urls are enabled will convert them back to URL's for processing
	 *
	 * @param string $message
	 * @return string
	 */
	public function replaceUrlsWithTitles($message = '')
	{
		global $modSettings;

		// Init
		require_once(SUBSDIR . '/Package.subs.php');
		$this->_message = $message;
		$this->_conversions = 0;

		// If convert URLS (bbc url) [url]$1[/url] & [url=https://$1]$1[/url] is enabled,
		// then we need to revert them to standard links for this addon to work and convert as needed
		if (!empty($modSettings['descriptive_links_title_bbcurl']))
		{
			// Maybe its [url=http://bbb.bbb.bbb]bbb.bbb.bbb[/url]
			$pre_urls = [];
			preg_match_all("~\[url=(https?://(.+?))](?:https?://)?(.+?)\[/url]~siu", $this->_message, $pre_urls, PREG_SET_ORDER);
			foreach ($pre_urls as $url_check)
			{
				// The link IS the same as the title, so set it to be a non bbc link so we can work on it
				if (isset($url_check[2], $url_check[3]) && ($url_check[2] === $url_check[3]))
				{
					$url_check[2] = trim((!str_contains($url_check[2], 'http://') && !str_contains($url_check[2], 'https://')) ? 'https://' . $url_check[2] : $url_check[2]);
					$this->_message = str_replace($url_check[0], $url_check[2], $this->_message);
				}
			}

			// Maybe it is [url]bbb.bbb.bbb[/url]
			preg_match_all("~\[url]((https?://)?(.+?))\[/url]~sui", $this->_message, $pre_urls, PREG_SET_ORDER);
			foreach ($pre_urls as $url_check)
			{
				$this->_message = str_replace($url_check[0], $url_check[1], $this->_message);
			}
		}

		// Wrap all (non bbc) links in this message in a custom bbc tag [%url]$0[/url%].
		$this->_message = preg_replace('~((?<=[\s>.(;\'"]|^)(https?://)|(?<=[\s>\'<]|^)www\.[^ [\]()\n\r\t]+|((?<=[\s\n\r\t]|^)(?:[012]?\d{1,2}\.){3}[012]?\d{1,2})/)([^ [\]()"\'<>\n\r\t]+)([^. [\](),;"\'<>\n\r\t])|((?<=[\s\n\r\t]|^)(?:[012]?\d{1,2}\.){3}[012]?\d{1,2})~iu', '[%url]$0[/url%]', $this->_message);

		// Find the special bbc tags that we just created, if any, so we can run through them and get titles
		$this->_urls = [];
		preg_match_all("~\[%url](.+?)\[/url%]~isum", $this->_message, $this->_urls);
		if (!empty($this->_urls[0]))
		{
			// Process all these links!
			$this->processLinks();
		}

		return $this->_message;
	}

	/**
	 * Process links found in the message to find page titles
	 *
	 * - Uses addon-local URL batch fetcher (with multi-curl for concurrent external links)
	 * - Resolves internal links via database queries with caching
	 * - Cleans and formats titles
	 * - Replaces links in a single pass to optimize performance
	 */
	private function processLinks()
	{
		global $modSettings, $boardurl, $scripturl;

		if (empty($this->_urls[1]))
		{
			return;
		}

		$replacements = [];
		$external_fetch_map = [];
		$internal_resolve_map = [];

		// Look at all found links
		foreach ($this->_urls[1] as $url)
		{
			if (isset($replacements[$url]))
			{
				continue;
			}

			// If the url has any skip fragment, then we skip it
			if (!empty($this->_skip_video_links))
			{
				foreach ($this->_skip_video_links as $check)
				{
					if ($check !== '' && stripos($url, $check) !== false)
					{
						$replacements[$url] = $url;
						continue 2;
					}
				}
			}

			// If our counter has exceeded the allowed number of conversions, skip remaining
			if (!empty($this->_max_conversions) && $this->_conversions >= $this->_max_conversions)
			{
				$replacements[$url] = $url;
				continue;
			}

			// Normalize URL
			$url_temp = str_replace(['HTTP://', 'HTTPS://'], ['http://', 'https://'], $url);
			$url_return = trim((!str_contains($url_temp, 'http://') && !str_contains($url_temp, 'https://')) ? 'http://' . $url_temp : $url_temp);

			$url_modified = $url_return;
			$urlinfo = parse_url($url_modified);
			if (!isset($urlinfo['path']))
			{
				$url_modified .= '/';
			}

			// Check if internal link
			$is_internal = (stripos($url_modified, $this->_internal) !== false)
				|| (!empty($boardurl) && stripos($url_modified, $boardurl) !== false)
				|| (!empty($scripturl) && stripos($url_modified, $scripturl) !== false);

			if ($is_internal)
			{
				if (!empty($modSettings['descriptive_links_title_internal']))
				{
					$internal_resolve_map[$url_modified][$url] = $url_return;
				}
				else
				{
					$replacements[$url] = $url;
				}
			}
			else
			{
				// Make sure this has html link DNA, and not a static resource/file
				$check = isset($urlinfo['path']) ? pathinfo($urlinfo['path']) : [];

				if (isset($check['extension']) && !isset($check['extension'][4]) && (!in_array(strtolower($check['extension']), ['htm', 'html', '', '//', 'php'], true)))
				{
					$replacements[$url] = $url;
				}
				else
				{
					$this->_conversions++;
					$external_fetch_map[$url_modified][$url] = $url_return;
				}
			}
		}

		// Resolve internal links (with caching)
		if (!empty($internal_resolve_map))
		{
			foreach ($internal_resolve_map as $url_modified => $raw_map)
			{
				$request = $this->resolveLinkTitle($url_modified);
				if (!empty($request) && preg_match('~<title[^>]*>(.*?)</title>~ism', $request, $matches))
				{
					$title = $this->formatTitle($matches[1]);
					if ($title !== false)
					{
						foreach ($raw_map as $raw_url => $url_ret)
						{
							$replacements[$raw_url] = '[url=' . $url_ret . ']' . $title . '[/url]';
						}
						continue;
					}
				}

				foreach ($raw_map as $raw_url => $url_ret)
				{
					$replacements[$raw_url] = $raw_url;
				}
			}
		}

		// Resolve external links
		if (!empty($external_fetch_map))
		{
			$urls_to_fetch = [];
			foreach (array_keys($external_fetch_map) as $u)
			{
				$urls_to_fetch[$u] = un_htmlspecialchars(un_htmlspecialchars($u));
			}

			$fetch_results = $this->_urlBatchFetcher->fetch($urls_to_fetch);

			foreach ($external_fetch_map as $url_modified => $raw_map)
			{
				$res = $fetch_results[$url_modified] ?? null;
				$body = is_array($res) ? ($res['body'] ?? '') : (is_string($res) ? $res : '');

				if (!empty($body) && preg_match('~<title[^>]*>(.*?)</title>~ism', $body, $matches))
				{
					$title = $this->formatTitle($matches[1]);
					if ($title !== false)
					{
						foreach ($raw_map as $raw_url => $url_ret)
						{
							$replacements[$raw_url] = '[url=' . $url_ret . ']' . $title . '[/url]';
						}
						continue;
					}
				}

				foreach ($raw_map as $raw_url => $url_ret)
				{
					$replacements[$raw_url] = $raw_url;
				}
			}
		}

		// Perform single-pass callback replacement for all [%url] tags in message
		$this->_message = preg_replace_callback(
			'~\[%url](.+?)\[/url%]~isum',
			static function ($matches) use (&$replacements) {
				$raw_url = $matches[1];
				return $replacements[$raw_url] ?? $raw_url;
			},
			$this->_message
		);
	}

	/**
	 * Prepares titles found from a page scrape for use in a link
	 *
	 * - Removes tags and entities
	 * - Shortens length as needed
	 * - Checks for generic naming
	 *
	 * @param string $title
	 * @return string|false Formatted title or false if invalid/generic
	 */
	public function formatTitle($title)
	{
		// Decode and undo htmlspecialchars
		$title = trim(html_entity_decode(un_htmlspecialchars($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

		// Remove newlines, tabs, dashes, etc.
		$title = str_replace(['&mdash;', "\n", "\r", "\t"], ['-', ' ', ' ', ' '], $title);
		$title = preg_replace('~\s{2,}~u', ' ', $title);
		$title = trim($title);

		// Some titles are just too long
		if ($this->_max_title_length > 0)
		{
			$title = Util::shorten_text($title, $this->_max_title_length, true);
		}

		// Ensure non-empty, length > 2 and not in generic list
		if (!empty($title)
			&& Util::strlen($title) > 2
			&& !in_array(strtolower($title), $this->_links_title_generic_names, true))
		{
			return Util::htmlspecialchars(stripslashes($title), ENT_QUOTES);
		}

		return false;
	}

	/**
	 * Prepares titles found from a page scrape for use in a link (legacy wrapper)
	 *
	 * - Removes tags and entities
	 * - Shortens length as needed
	 * - Checks for generic naming
	 * - Replaces that link in the text with the cleaned link+title
	 */
	private function sanitizeAndFormatTitle()
	{
		$formatted = $this->formatTitle($this->_title);
		if ($formatted !== false)
		{
			$this->_title = $formatted;
			$this->_message = preg_replace_callback(
				'~\[%url]' . preg_quote($this->_url, '~') . '\[/url%]~isu',
				fn() => '[url=' . $this->_url_return . ']' . $this->_title . '[/url]',
				$this->_message
			);
		}
		else
		{
			$this->_message = preg_replace_callback(
				'~\[%url]' . preg_quote($this->_url, '~') . '\[/url%]~isu',
				fn() => $this->_url,
				$this->_message
			);
		}
	}

	/**
	 * Called by Add_title_to_link to resolve the name of internal links
	 *
	 * - Returns the topic or post, subject if it's a iURL message link
	 * - Returns the board name `if it's a board link
	 *
	 * @param string $url
	 * @return string|false
	 */
	private function resolveLinkTitle($url)
	{
		global $scripturl, $boardurl, $txt, $modSettings;

		if (isset(self::$_internal_cache[$url]))
		{
			return self::$_internal_cache[$url];
		}

		$db = database();

		// let's pull out the topic number and possibly a message number for this link
		//
		// https://xxx/index.php?topic=5.0
		// https://xxx/index.php/topic,5.msg9.html#msg9
		// -or-
		// https://xxx/index.php/topic,5.0.html
		// https://xxx/index.php?topic=5.msg10#msg10
		// -or-
		// https://xxx/index.php?board=1.0
		//
		$internal_bases = array_unique(array_filter([$this->_internal, $scripturl ?? '', $boardurl ?? '']));
		$patterns_base = !empty($internal_bases)
			? '(?:' . implode('|', array_map(static fn($u) => preg_quote($u, '`'), $internal_bases)) . ')'
			: preg_quote($scripturl, '`');

		$pattern_message = $patterns_base . '[/?]topic[=,](\d{1,8})(.msg\d{1,8})?(#new)?';
		$pattern_board = $patterns_base . '[/?]board[=,](\d{1,8})';
		$title = false;

		// Find the topic or message number in this link
		$match = [];
		if (preg_match('`' . $pattern_message . '`iu', $url, $match))
		{
			// Found the topic number ... let's get the subject
			if (isset($match[2]))
			{
				$match[2] = str_replace('.msg', '', $match[2]);
			}
			else
			{
				$match[2] = '';
			}

			// Set the message part of the query
			if (isset($match[3]) && !empty($match[2]))
			{
				$query = 'm.id_msg >= {int:message_id}';
			}
			elseif (!empty($match[2]))
			{
				$query = '(m.id_msg = {int:message_id} OR m.id_msg = t.id_first_msg)';
			}
			else
			{
				$query = 'm.id_msg = t.id_first_msg';
			}

			// Off to the database we go, convert this link to the message title,
			// check for any hackyness as well, such as
			// the message is on a board they can see, not in the recycle bin, is approved, etc.,
			// so we show only what they can see.
			$request = $db->query('', '
				SELECT
					m.subject
				FROM {db_prefix}topics AS t
					INNER JOIN {db_prefix}messages AS m ON (m.id_topic = t.id_topic)
					LEFT JOIN {db_prefix}boards AS b ON (t.id_board = b.id_board)
				WHERE t.id_topic = {int:topic_id} 
					AND {query_wanna_see_board}' . (!empty($modSettings['recycle_enable']) && !empty($modSettings['recycle_board']) && $modSettings['recycle_board'] > 0 ? '
					AND b.id_board != {int:recycle_board}' : '') . '
					AND m.approved = {int:is_approved}
					AND ' . $query . '
				LIMIT 1',
				[
					'topic_id' => $match[1],
					'message_id' => !empty($match[2]) ? (int) $match[2] : 0,
					'recycle_board' => $modSettings['recycle_board'] ?? 0,
					'is_approved' => 1,
				]
			);

			// Hummm bad info in the link
			if ($request->num_rows() === 0)
			{
				self::$_internal_cache[$url] = false;
				return false;
			}

			// Found the topic data, load the subject er I mean title!
			list($title) = $request->fetch_row();
			$request->free_result();
		}
		elseif (preg_match('`' . $pattern_board . '`iu', $url, $match))
		{
			// Found a board number ... let's get the board name
			$request = $db->query('', '
			SELECT
				b.name
			FROM {db_prefix}boards as b
			WHERE b.id_board = {int:board_id} AND {query_wanna_see_board}' . (!empty($modSettings['recycle_enable']) && !empty($modSettings['recycle_board']) && $modSettings['recycle_board'] > 0 ? '
				AND b.id_board != {int:recycle_board}' : '') . '
			LIMIT 1',
				[
					'board_id' => $match[1],
					'recycle_board' => $modSettings['recycle_board'] ?? 0,
				]
			);

			// Nothing found, nothing gained
			if ($request->num_rows() === 0)
			{
				self::$_internal_cache[$url] = false;
				return false;
			}

			// Found the board name, load the name for the title
			list($title) = $request->fetch_row();
			$request->free_result();
		}

		if ($title === false)
		{
			self::$_internal_cache[$url] = false;
			return false;
		}

		// Make it look good
		$response_prefix = $txt['response_prefix'] ?? 'Re: ';
		$title = trim(str_replace($response_prefix, '', $title));
		$result = '<title>' . $title . '</title>';
		self::$_internal_cache[$url] = $result;

		return $result;
	}

	/**
	 * Returns a reference to the existing instance
	 */
	public static function instance()
	{
		if (self::$_descriptive_links === null)
		{
			self::$_descriptive_links = new DescriptiveLinks();
		}

		return self::$_descriptive_links;
	}
}
