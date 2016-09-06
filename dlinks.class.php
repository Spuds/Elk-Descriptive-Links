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
 * Searches a post for all links and trys to replace them with the destinations
 * page title
 *
 * - Uses database querys for internal links and web request for external links
 * - Will not change links if they resolve to names in the admin disabled list
 * - truncates long titles per admin panel settings
 * - updates the link in the link in the message body itself
 * - user permission to allow disabling of this option for a given message.
 */
class Add_Title_Link
{
	/**
	 * Holds current instance of the class
	 * @var Add_Title_Link
	 */
	private static $_dlinks = null;

	/**
	 * comma separated string or titles to not covert
	 * @var array
	 */
	protected $_links_title_generic_names;

	/**
	 * comma separated string of links to skip
	 * @var array
	 */
	protected $_skip_video_links;

	/**
	 * maximum length of a title tag
	 * @var int
	 */
	protected $_max_title_length = 0;

	/**
	 * Number of conversions done in this message
	 * @var int
	 */
	protected $_conversions = 0;

	/**
	 * dna of internal links boardurl or scriptutl
	 * @var string
	 */
	protected $_internal = '';

	/**
	 * maximum number of conversions allowed, more = more time
	 * @var int
	 */
	protected $_max_conversions = 0;

	/**
	 * urls found in the message to inspect for conversion
	 * @var string[]
	 */
	protected $_urls = array();

	/**
	 * current url tag being processed
	 * @var string
	 */
	protected $_url = '';

	/**
	 * Holds the message string we are working on
	 * @var string
	 */
	protected $_message = '';

	/**
	 * Cleaned, fully qualified url for use in the converted tag
	 * @var string
	 */
	protected $_url_return = '';

	/**
	 * Title text found in a given url
	 * @var string
	 */
	protected $_title = '';

	/**
	 * Constructor, just loads some modSettings for this addon to the class vars
	 */
	public function __construct()
	{
		global $modSettings, $boardurl, $scripturl;

		// Get the generic names that we will never allow a link to convert to
		$this->_links_title_generic_names = !empty($modSettings['descriptivelinks_title_url_generic']) ? explode(',', $modSettings['descriptivelinks_title_url_generic']) : array();
		$this->_internal = !empty($modSettings['queryless_urls']) ? $boardurl : $scripturl;
		$this->_max_conversions = $modSettings['descriptivelinks_title_url_count'];
		$this->_max_title_length = $modSettings['descriptivelinks_title_url_length'];
		$this->_skip_video_links = !empty($modSettings['descriptivelinks_title_url_video']) ? explode(',', $modSettings['descriptivelinks_title_url_video']) : array();
	}

	/**
	 * Takes a message string and converts url and plain text links to titled links
	 *
	 * - Does NOT check if the link is inside tags (e.g. code) that should not be converted
	 * here that is taken care of by ipc_dlinks
	 * - it must be supplied strings in which you want the tags converted
	 * - If bbc urls is enable will convert them back to URL's for processing
	 *
	 * @param string $message
	 * @return string
	 */
	public function Add_title_to_link($message = '')
	{
		global $modSettings;

		// Init
		require_once (SUBSDIR . '/Package.subs.php');
		$this->_message = $message;

		// If convert URLS (bbc url) [url]$1[/url] & [url=http://$1]$1[/url] is enabled
		// we need to revert them to standard links for this addon to work and put them back as needed
		if (!empty($modSettings['descriptivelinks_title_bbcurl']))
		{
			// Maybe its [url=http://bbb.bbb.bbb]bbb.bbb.bbb[/url]
			$pre_urls = array();
			preg_match_all("~\[url=(http(?:s)?:\/\/(.+?))\](?:http(?:s)?:\/\/)?(.+?)\[/url\]~siu", $this->_message, $pre_urls, PREG_SET_ORDER);
			foreach ($pre_urls as $url_check)
			{
				// The link IS the same as the title, so set it to be a non bbc link so we can work on it
				if (isset($url_check[2]) && isset($url_check[3]) && ($url_check[2] === $url_check[3]))
				{
					$url_check[2] = trim((strpos($url_check[2], 'http://') === false && strpos($url_check[2], 'https://') === false) ? 'http://' . $url_check[2] : $url_check[2]);
					$this->_message = str_replace($url_check[0], $url_check[2], $this->_message);
				}
			}

			// Maybe it just like [url]bbb.bbb.bbb[/url]
			preg_match_all("~\[url\](http(?:s)?:\/\/(.+?))\[/url\]~si", $this->_message, $pre_urls, PREG_SET_ORDER);
			foreach ($pre_urls as $url_check)
				$this->_message = str_replace($url_check[0], $url_check[1], $this->_message);
		}

		// Wrap all (non bbc) links in this message in a custom bbc tag ([%url]$0[/url%.
		$this->_message = preg_replace('~((?:(?<=[\s>\.\(;\'"]|^)(https?:\/\/))|(?:(?<=[\s>\'<]|^)www\.[^ \[\]\(\)\n\r\t]+)|((?:(?<=[\s\n\r\t]|^))(?:[012]?[0-9]{1,2}\.){3}[012]?[0-9]{1,2})\/)([^ \[\]\(\)"\'<>\n\r\t]+)([^\. \[\]\(\),;"\'<>\n\r\t])|((?:(?<=[\s\n\r\t]|^))(?:[012]?[0-9]{1,2}\.){3}[012]?[0-9]{1,2})~iu', '[%url]$0[/url%]', $this->_message);

		// Find the special bbc tags that we just created, if any, so we can run through them and get titles
		$this->_urls = array();
		preg_match_all("~\[%url\](.+?)\[/url%\]~ism", $this->_message, $this->_urls);
		if (!empty($this->_urls[0]))
		{
			// Set a timeout on getting the url ... don't want to get stuck waiting
			$timeout = ini_get('default_socket_timeout');
			@ini_set('default_socket_timeout', 3);

			// Process all these links !
			$this->process_links();

			// Put the server socket timeout back to what is was originally
			@ini_set('default_socket_timeout', $timeout);
		}

		return $this->_message;
	}

	/**
	 * Process each link in order to find the page title
	 *
	 * - Makes a fetch web data call to each link found
	 * - Process the fetch results looking to page title
	 * - Cleans any titles found and replaces the link with a titled link
	 */
	private function process_links()
	{
		global $modSettings;

		// Look at all these links !
		foreach ($this->_urls[1] as $this->_url)
		{
			// If the url has any skip fragment, then we skip it
			if (!empty($this->_skip_video_links))
			{
				foreach ($this->_skip_video_links as $check)
				{
					if (strpos($this->_url, $check) !== false)
					{
						$this->_message = preg_replace('`\[%url\]' . preg_quote($this->_url) . '\[/url%\]`', $this->_url, $this->_message);
						continue;
					}
				}
			}

			// If our counter has exceeded the allowed number of conversions then put the remaining urls
			// back to what they were and finish
			if (!empty($this->_max_conversions) && $this->_conversions++ >= $this->_max_conversions)
			{
				$this->_message = preg_replace('`\[%url\]' . preg_quote($this->_url) . '\[/url%\]`', $this->_url, $this->_message);
				continue;
			}

			// Make sure the link is lower case and leads with http:// so fetch web data does not drop a spacely space sprocket
			$url_temp = str_replace(array('HTTP://', 'HTTPS://'), array('http://', 'https://'), $this->_url);
			$this->_url_return = $url_modified = trim((strpos($url_temp, 'http://') === false && strpos($url_temp, 'https://') === false) ? 'http://' . $url_temp : $url_temp);

			// Make sure there is a trailing '/' *when needed* so fetch_web_data does not blow a cogswell cog
			$urlinfo = parse_url($url_modified);
			if (!isset($urlinfo['path']))
				$url_modified .= '/';

			// Get the title from the web or if an internal link from the database
			$request = false;
			if (stripos($url_modified, $this->_internal) !== false)
			{
				// Internal link it is, give the counter back, its a freebie
				if (!empty($modSettings['descriptivelinks_title_internal']))
				{
					$request = $this->load_topic_subject($url_modified);
					$this->_conversions--;
				}
			}
			else
			{
				// Make sure this has the DNA of an html link and not a file
				$check = isset($urlinfo['path']) ? pathinfo($urlinfo['path']) : array();

				// Looks like an extension, 4 or less characters, then it needs to be htmlish
				if (isset($check['extension']) && !isset($check['extension'][4]) && (!in_array($check['extension'], array('htm', 'html', '', '//', 'php'))))
				{
					$this->_conversions--;
					$request = false;
				}
				// External links are good too, but protect against double encoded pasted links
				else
					$request = fetch_web_data(un_htmlspecialchars(un_htmlspecialchars($url_modified)));
			}

			// Request went through and there is a page title in the result
			if ($request !== false && !empty($request) && preg_match('~<title>(.+?)</title>~ism', $request, $matches))
			{
				$this->_title = $matches[1];
				$this->sanitize_title();
			}
			// No title or an error, back to the original we go...
			else
				$this->_message = preg_replace('`\[%url\]' . preg_quote($this->_url) . '\[/url%\]`', $this->_url, $this->_message);

			// Pop the connection to keep it alive
			//$db->db_server_info();
		}
	}

	/**
	 * Prepares titles found from a page scrape for use in a link
	 *
	 * - Removes tags and entities
	 * - Shortens length as needed
	 * - Checks for generic naming
	 * - Replaces that link in the text with the cleaned link+title
	 */
	private function sanitize_title()
	{
		// Decode and undo htmlspecial first so we can clean this dirty baby
		$this->_title = trim(html_entity_decode(un_htmlspecialchars($this->_title)));

		// Remove crazy stuff we find in title tags, what are those web "masters" thinking?
		$this->_title = str_replace(array('&mdash;', "\n", "\t"), array('-', ' ', ' '), $this->_title);
		$this->_title = preg_replace('~\s{2,30}~', ' ', $this->_title);
		$this->_title = trim($this->_title);

		// Some titles are just tooooooooo long
		if ($this->_max_title_length > 0)
		{
			$this->_title = Util::shorten_text($this->_title, $this->_max_title_length, true);
		}

		// Make sure we did not get a turd title, makes the link even worse, plus no one likes turds
		if (!empty($this->_title) && Util::strlen($this->_title) > 2 && array_search(strtolower($this->_title), $this->_links_title_generic_names) === false)
		{
			// Protect special characters and our database
			$this->_title = Util::htmlspecialchars(stripslashes($this->_title), ENT_QUOTES);

			// Update the link with the title we found
			$this->_message = preg_replace('`\[%url\]' . preg_quote($this->_url) . '\[/url%\]`', '[url=' . $this->_url_return . ']' . $this->_title . '[/url]', $this->_message);
		}
		// Generic title, like welcome, or home, etc ... lets set things back to the way they were
		else
		{
			$this->_message = preg_replace('`\[%url\]' . preg_quote($this->_url) . '\[/url%\]`', $this->_url, $this->_message);
		}
	}

	/**
	 * Called by Add_title_to_link to resolve the name of internal links
	 *
	 * - Returns the topic or post subject if its a message link
	 * - Returns the board name if its a board link
	 *
	 * @param string $url
	 * @return string
	 */
	private function load_topic_subject($url)
	{
		global $scripturl, $txt, $modSettings;

		$db = database();

		// lets pull out the topic number and possibly a message number for this link
		//
		// http://xxx/index.php?topic=5.0
		// http://xxx/index.php/topic,5.msg9.html#msg9
		// -or-
		// http://xxx/index.php/topic,5.0.html
		// http://xxx/index.php?topic=5.msg10#msg10
		// -or-
		// http://xxx/index.php?board=1.0
		//
		$pattern_message = preg_quote($scripturl) . '[\/?]{1}topic[\=,]{1}(\d{1,8})(.msg\d{1,8})?(#new)?';
		$pattern_board = preg_quote($scripturl) . '[\/?]{1}board[\=,]{1}(\d{1,8})';
		$title = false;

		// Find the topic or message number in this link
		$match = array();
		if (preg_match('`' . $pattern_message . '`iu', $url, $match))
		{
			// Found the topic number .... lets get the subject
			if (isset($match[2]))
				$match[2] = str_replace('.msg', '', $match[2]);
			else
				$match[2] = '';

			// Set the message part of the query
			if (isset($match[3]) && !empty($match[2]))
				$query = 'm.id_msg >= {int:message_id}';
			elseif (!empty($match[2]))
				$query = '(m.id_msg = {int:message_id} OR m.id_msg = t.id_first_msg)';
			else
				$query = 'm.id_msg = t.id_first_msg';

			// Off to the database we go, convert this link to the message title,
			// check for any hackyness as well, such as
			// the message is on a board they can see, not in the recycle bin, is approved, etc,
			// so we show only what they can see.
			$request = $db->query('', '
				SELECT
					m.subject
				FROM {db_prefix}topics AS t
					INNER JOIN {db_prefix}messages AS m ON m.id_topic = {int:topic_id}
					LEFT JOIN {db_prefix}boards AS b ON (t.id_board = b.id_board)
				WHERE t.id_topic = {int:topic_id} 
					AND {query_wanna_see_board}' . (!empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] > 0 ? '
					AND b.id_board != {int:recycle_board}' : '') . '
					AND m.approved = {int:is_approved}
					AND ' . $query . '
				LIMIT 1',
					array(
						'topic_id' => $match[1],
						'message_id' => $match[2],
						'recycle_board' => $modSettings['recycle_board'],
						'is_approved' => 1,
						'query' => $query,
					)
			);

			// Hummm bad info in the link
			if ($db->num_rows($request) == 0)
				return false;

			// Found the topic data, load the subject er I mean title !
			list($title) = $db->fetch_row($request);
			$db->free_result($request);
		}
		elseif (preg_match('`' . $pattern_board . '`iu', $url, $match))
		{
			// Found a board number .... lets get the board name
			$request = $db->query('', '
			SELECT
				b.name
			FROM {db_prefix}boards as b
			WHERE b.id_board = {int:board_id} AND {query_wanna_see_board}' . (!empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] > 0 ? '
				AND b.id_board != {int:recycle_board}' : '') . '
			LIMIT 1',
				array(
					'board_id' => $match[1],
					'recycle_board' => $modSettings['recycle_board'],
				)
			);

			// Nothing found, nothing gained
			if ($db->num_rows($request) == 0)
				return false;

			// Found the board name, load the name for the title
			list($title) = $db->fetch_row($request);
			$db->free_result($request);
		}

		// Make it look good
		$title = trim(str_replace($txt['response_prefix'], '', $title));
		$title = '<title>' . $title . '</title>';

		return $title;
	}

	/**
	 * Returns a reference to the existing instance
	 */
	public static function dlinks()
	{
		if (self::$_dlinks === null)
			self::$_dlinks = new Add_Title_Link();

		return self::$_dlinks;
	}
}