<?php

/**
 * @package Descriptive Links
 * @author Spuds
 * @copyright (c) 2026 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * https://mozilla.org/MPL/1.1/.
 *
 * @version 2.0.0
 *
 */

namespace Addons\DescriptiveLinks;

use ElkArte\Http\CurlFetchWebdata;

/**
 * Addon URL fetch service used by DescriptiveLinks.
 *
 * Uses CurlFetchWebdata for single URL fetches and curl_multi for concurrent fetches when available.
 */
class DescriptiveUrlBatchFetcher
{
	/** @var array<int, mixed> */
	private array $_curlOptions;

	/** @var int */
	private int $_maxRedirects;

	/**
	 * @param array<int, mixed> $curlOptions
	 * @param int $maxRedirects
	 */
	public function __construct(array $curlOptions = [], int $maxRedirects = 3)
	{
		$this->_curlOptions = $curlOptions;
		$this->_maxRedirects = $maxRedirects;
	}

	/**
	 * Fetch one or many URLs and return normalized response rows.
	 *
	 * @param array<string, string> $urls
	 * @return array<string, array<string, mixed>>
	 */
	public function fetch(array $urls): array
	{
		if (empty($urls))
		{
			return [];
		}

		if (function_exists('curl_multi_init') && count($urls) > 1)
		{
			return $this->fetchMulti($urls);
		}

		return $this->fetchSingle($urls);
	}

	/**
	 * Sequential fetch path using CurlFetchWebdata via composition.
	 *
	 * @param array<string, string> $urls
	 * @return array<string, array<string, mixed>>
	 */
	private function fetchSingle(array $urls): array
	{
		$results = [];

		foreach ($urls as $key => $url)
		{
			$client = new CurlFetchWebdata($this->_curlOptions, $this->_maxRedirects);
			$client->get_url_data($url);
			$result = $client->result_raw(0);

			$results[$key] = is_array($result)
				? $result
				: [
					'url' => $url,
					'code' => $result !== false ? 200 : 0,
					'error' => false,
					'size' => 0,
					'headers' => false,
					'body' => $result,
				];
		}

		return $results;
	}

	/**
	 * Concurrent fetch path using curl_multi.
	 *
	 * @param array<string, string> $urls
	 * @return array<string, array<string, mixed>>
	 */
	private function fetchMulti(array $urls): array
	{
		$multi = curl_multi_init();
		$handles = [];
		$results = [];

		foreach ($urls as $key => $url)
		{
			$target = trim(str_replace(' ', '%20', $url));
			if ($target === '')
			{
				continue;
			}

			$options = $this->_curlOptions + [
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_HEADER => true,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_MAXREDIRS => $this->_maxRedirects,
					CURLOPT_URL => $target,
				];

			if (parse_url($target, PHP_URL_SCHEME) === 'http')
			{
				$options[CURLOPT_SSL_VERIFYPEER] = false;
				$options[CURLOPT_SSL_VERIFYHOST] = 0;
			}

			$handle = curl_init();
			curl_setopt_array($handle, $options);
			curl_multi_add_handle($multi, $handle);
			$handles[(int) $handle] = [
				'key' => $key,
				'url' => $target,
				'handle' => $handle,
			];
		}

		if (empty($handles))
		{
			curl_multi_close($multi);
			return [];
		}

		$active = null;
		do
		{
			$status = curl_multi_exec($multi, $active);
			if ($active)
			{
				curl_multi_select($multi, 0.5);
			}
		} while ($active > 0 && $status === CURLM_OK);

		foreach ($handles as $item)
		{
			$handle = $item['handle'];
			$content = curl_multi_getcontent($handle);
			$info = curl_getinfo($handle);
			$errorString = curl_error($handle);
			$error = $errorString !== '' ? $errorString : false;

			$headerSize = (int) ($info['header_size'] ?? 0);
			$body = $error === false ? substr((string) $content, $headerSize) : false;

			$results[$item['key']] = [
				'url' => $info['url'] ?: $item['url'],
				'code' => (int) ($info['http_code'] ?? 0),
				'error' => $error,
				'size' => $info['download_content_length'] ?? 0,
				'headers' => false,
				'body' => $body,
			];

			curl_multi_remove_handle($multi, $handle);
			curl_close($handle);
		}

		curl_multi_close($multi);

		return $results;
	}
}
