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
 *
 * @param string $message
 * @param int $id_msg
 */
class Add_Title_Link
{
	/**
	 * Holds current instance of the class
	 * @var Add_Title_Link
	 */
	private static $_dlinks = null;

	protected $_links_title_generic_names;
	protected $_max_title_length = 0;
	protected $_conversions = 0;
	protected $_internal = '';
	protected $_max_conversions = 0;
	protected $_parts = array();
	protected $_current = 0;

	public function __construct()
	{
		global $modSettings, $boardurl, $scripturl;

		// Get the generic names that we will never allow a link to convert to
		$this->_links_title_generic_names = !empty($modSettings['descriptivelinks_title_url_generic']) ? explode(',', $modSettings['descriptivelinks_title_url_generic']) : '';
		$this->_internal = !empty($modSettings['queryless_urls']) ? $boardurl : $scripturl;
		$this->_max_conversions = $modSettings['descriptivelinks_title_url_count'];
		$this->_max_title_length = $modSettings['descriptivelinks_title_url_length'];
	}

	public function Add_title_to_link($message = '')
	{
		global $modSettings, $context;

		// Init
		require_once (SUBSDIR . '/Package.subs.php');
		$this->_message = $message;

		// If asked, lets create a nice title for the link [url=ddd]great title[/url]
		if (!empty($modSettings['descriptivelinks_title_url']))
		{
			// Only convert tags that are outside [code] tags.
			$this->_parts = preg_split('~(\[/code\]|\[code(?:=[^\]]+)?\])~i', $this->_message, -1, PREG_SPLIT_DELIM_CAPTURE);
			for ($this->_current = 0, $n = count($this->_parts); $this->_current < $n; $this->_current++)
			{
				if ($this->_current % 4 === 0)
				{
					// If convert URLS (bbc url) [url]$1[/url] & [url=http://$1]$1[/url] is enabled
					// we need to revert these for this mod to work and put them back as needed
					if (!empty($modSettings['descriptivelinks_title_bbcurl']))
					{
						// Maybe its [url=http://bbb.bbb.bbb]bbb.bbb.bbb[/url]
						$pre_urls = array();
						preg_match_all("~\[url=(http(?:s)?:\/\/(.+?))\](?:http(?:s)?:\/\/)?(.+?)\[/url\]~si" . ($context['utf8'] ? 'u' : ''), $this->_parts[$this->_current], $pre_urls, PREG_SET_ORDER);
						foreach ($pre_urls as $url_check)
						{
							// The link IS the same as the title, so set it to be a non bbc link so we can work on it
							if (isset($url_check[2]) && isset($url_check[3]) && ($url_check[2] === $url_check[3]))
							{
								$url_check[2] = trim((strpos($url_check[2], 'http://') === false && strpos($url_check[2], 'https://') === false) ? 'http://' . $url_check[2] : $url_check[2]);
								$this->_parts[$this->_current] = str_replace($url_check[0], $url_check[2], $this->_parts[$this->_current]);
							}
						}

						// Maybe it just like [url]bbb.bbb.bbb[/url]
						preg_match_all("~\[url\](http(?:s)?:\/\/(.+?))\[/url\]~si", $this->_parts[$this->_current], $pre_urls, PREG_SET_ORDER);

						// Just a bbc link so back to a non bbc link it goes
						foreach ($pre_urls as $url_check)
							$this->_parts[$this->_current] = str_replace($url_check[0], $url_check[1], $this->_parts[$this->_current]);
					}

					// Find all (non bbc) links in this message and wrap them in a custom bbc url tag so we can inspect.
					$this->_parts[$this->_current] = preg_replace('~((?:(?<=[\s>\.\(;\'"]|^)(https?:\/\/))|(?:(?<=[\s>\'<]|^)www\.[^ \[\]\(\)\n\r\t]+)|((?:(?<=[\s\n\r\t]|^))(?:[012]?[0-9]{1,2}\.){3}[012]?[0-9]{1,2})\/)([^ \[\]\(\),"\'<>\n\r\t]+)([^\. \[\]\(\),;"\'<>\n\r\t])|((?:(?<=[\s\n\r\t]|^))(?:[012]?[0-9]{1,2}\.){3}[012]?[0-9]{1,2})~iu', '[%url]$0[/url%]', $this->_parts[$this->_current]);

					// Find the special bbc urls that we just created, if any, so we can run through them and get titles
					$urls = array();
					preg_match_all("~\[%url\](.+?)\[/url%\]~ism", $this->_parts[$this->_current], $urls);
					if (!empty($urls[0]))
					{
						// Set a timeout on getting the url ... don't want to get stuck waiting
						$timeout = ini_get('default_socket_timeout');
						@ini_set('default_socket_timeout', 3);

						// Look at all these links !
						process_links($urls[1]);

						// Put the server socket timeout back to what is was originally
						@ini_set('default_socket_timeout', $timeout);
					}
				}
				$this->_message = implode('', $this->_parts);
			}
		}

		return;
	}

	private function process_links($urls)
	{
		global $modSettings;

		// Look at all these links !
		foreach ($urls as $url)
		{
			// Make sure the link is lower case and leads with http:// so fetch web data
			// does not drop a spacely space sprocket
			$url_temp = str_replace(array('HTTP://', 'HTTPS://'), array('http://', 'https://'), $url);
			$url_return = $url_modified = trim((strpos($url_temp, 'http://') === false && strpos($url_temp, 'https://') === false) ? 'http://' . $url_temp : $url_temp);

			// Make sure there is a trailing '/' *when needed* so fetch_web_data
			// does not blow a cogswell cog
			$urlinfo = parse_url($url_modified);
			if (!isset($urlinfo['path']))
				$url_modified .= '/';

			// If our counter has exceeded the allowed number of conversions then put the remaining urls
			// back to what they were and finish
			if (!empty($this->_max_conversions) && $this->_conversions++ >= $this->_max_conversions)
			{
				$this->_parts[$this->_current] = preg_replace('`\[%url\]' . preg_quote($url) . '\[/url%\]`', $url, $this->_parts[$this->_current]);
				continue;
			}

			// Get the title from the web or if an internal link from the database ;)
			$request = false;
			if (stripos($url_modified, $this->_internal) !== false)
			{
				// Internal link it is, give the counter back, its a freebie
				if (!empty($modSettings['descriptivelinks_title_internal']))
				{
					$request = Load_topic_subject($url_modified);
					$this->_conversions--;
				}
			}
			else
			{
				// Make sure this has the DNA of an html link and not a file
				$check = isset($urlinfo['path']) ? pathinfo($urlinfo['path']) : array();

				// Looks like an extesion, 4 or less characters, then it needs to be htmlish
				if (isset($check['extension']) && !isset($check['extension'][4]) && (!in_array($check['extension'], array('htm', 'html', '', '//', 'php'))))
					$request = false;
				// External links are good too, but protect against double encoded pasted links
				else
					$request = fetch_web_data(un_htmlspecialchars(un_htmlspecialchars($url_modified)));
			}

			// Request went through and there is a page title in the result
			if ($request !== false && preg_match('~<title>(.+?)</title>~ism', $request, $matches))
			{
				// Decode and undo htmlspecial first so we can clean this dirty baby
				$title = trim(html_entity_decode(un_htmlspecialchars($matches[1])));

				// Remove crazy stuff we find in title tags, what are those web "masters" thinking?
				$title = str_replace(array('&mdash;', "\n", "\t"), array('-', ' ', ' '), $title);
				$title = preg_replace('~\s{2,30}~', ' ', $title);

				// Some titles are just tooooooooo long
				$title = shorten_text($title, $this->_max_title_length, true);

				// Make sure we did not get a turd title, makes the link even worse, plus no one likes turds
				if (!empty($title) && array_search(strtolower($title), $this->_links_title_generic_names) === false)
				{
					// Protect special characters and our database
					$title = Util::htmlspecialchars(stripslashes($title), ENT_QUOTES);

					// Update the link with the title we found
					$this->_parts[$this->_current] = preg_replace('`\[%url\]' . preg_quote($url) . '\[/url%\]`', '[url=' . $url_return . ']' . $title . '[/url]', $this->_parts[$this->_current]);
				}
				// Generic title, like welcome, or home, etc ... lets set things back to the way they were
				else
					$this->_parts[$this->_current] = preg_replace('`\[%url\]' . preg_quote($url) . '\[/url%\]`', $url, $this->_parts[$this->_current]);
			}
			// No title or an error, back to the original we go...
			else
				$this->_parts[$this->_current] = preg_replace('`\[%url\]' . preg_quote($url) . '\[/url%\]`', $url, $this->_parts[$this->_current]);

			// Pop the connection to keep it alive
			$db->db_server_info();
		}
	}

	/**
	 * Called by Add_title_to_link to resolve the name of internal links
	 *
	 * - returns the topic or post subject if its a message link
	 * - returns the board name if its a board link
	 *
	 * @param string $url
	 */
	function Load_topic_subject($url)
	{
		global $scripturl, $txt, $context, $modSettings;

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
		$pattern_message = preg_quote($scripturl) . '[\/?]{1}topic[\=,]{1}(\d{1,8})(.msg\d{1,8})?';
		$pattern_board = preg_quote($scripturl) . '[\/?]{1}board[\=,]{1}(\d{1,8})';
		$title = false;

		// Find the topic or message number in this link
		$match = array();
		if (preg_match('`' . $pattern_message . '`i' . ($context['utf8'] ? 'u' : ''), $url, $match))
		{
			// found the topic number .... lets get the subject
			if (isset($match[2]))
				$match[2] = str_replace('.msg', '', $match[2]);
			else
				$match[2] = '';

			// off to the database we go, convert this link to the message title, check for any hackyness as well, such as
			// the message is on a board they can see, not in the recycle bin, is approved, etc, so we show only what they can see.
			$request = $db->query('', '
			SELECT m.subject
			FROM {db_prefix}topics AS t
				INNER JOIN {db_prefix}messages AS m ON (m.id_msg = ' . (($match[2] != '') ? '{int:message_id}' : 't.id_first_msg') . ')
				LEFT JOIN {db_prefix}boards AS b ON (t.id_board = b.id_board)
			WHERE t.id_topic = {int:topic_id} && {query_wanna_see_board}' . (!empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] > 0 ? '
				AND b.id_board != {int:recycle_board}' : '') . '
				AND m.approved = {int:is_approved}
			LIMIT 1', array(
				'topic_id' => $match[1],
				'message_id' => $match[2],
				'recycle_board' => $modSettings['recycle_board'],
				'is_approved' => 1,
					)
			);

			// Hummm bad info in the link
			if ($db->num_rows($request) == 0)
				return false;

			// Found the topic data, load the subject er I mean title !
			list($title) = $db->fetch_row($request);
			$db->free_result($request);

			// Clean it up a bit
			$title = trim(str_replace($txt['response_prefix'], '', $title));
			$title = '<title>' . $title . '</title>';
		}
		elseif (preg_match('`' . $pattern_board . '`iu', $url, $match))
		{
			// found a board number .... lets get the board name
			$request = $db->query('', '
			SELECT b.name
			FROM {db_prefix}boards as b
			WHERE b.id_board = {int:board_id} AND {query_wanna_see_board}' . (!empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] > 0 ? '
				AND b.id_board != {int:recycle_board}' : '') . '
			LIMIT 1', array(
				'board_id' => $match[1],
				'recycle_board' => $modSettings['recycle_board'],
					)
			);

			// nothing found, nothing gained
			if ($db->num_rows($request) == 0)
				return false;

			// Found the board name, load the name for the title
			list($title) = $db->fetch_row($request);
			$db->free_result($request);

			// and make it look good
			$title = trim(str_replace($txt['response_prefix'], '', $title));
			$title = '<title>' . $title . '</title>';
		}

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