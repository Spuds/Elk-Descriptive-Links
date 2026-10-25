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

use ElkArte\Http\UrlValidator;

/**
 * Addon URL fetch service used by DescriptiveLinks.
 *
 * Uses validated curl_multi requests for concurrent fetches.
 */
class DescriptiveUrlBatchFetcher
{
	/** @var array<int, mixed> */
	private array $_curlOptions;

	/** @var int */
	private int $_maxRedirects;

	/** @var int */
	private int $_maxBatchSize = 10;

	/** @var int */
	private int $_maxBodyBytes = 1048576;

	/**
	 * @param array<int, mixed> $curlOptions
	 * @param int $maxRedirects
	 */
	public function __construct(array $curlOptions = [], int $maxRedirects = 3)
	{
		$this->_curlOptions = $curlOptions;
		$this->_maxRedirects = max(0, $maxRedirects);
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

		return $this->fetchMulti($urls);
	}

	/**
	 * Concurrent fetch path using curl_multi.
	 *
	 * @param array<string, string> $urls
	 * @return array<string, array<string, mixed>>
	 */
	private function fetchMulti(array $urls): array
	{
		$results = [];
		$pending = [];

		foreach ($urls as $key => $url)
		{
			$url = (string) $url;
			$validated = UrlValidator::validate($url);
			if ($validated === false)
			{
				$results[$key] = $this->errorResult($url, 'Invalid URL or target host not allowed', 400);
				continue;
			}

			$pending[] = [
				'key' => $key,
				'url' => $validated['url'],
				'validated' => $validated,
				'redirects' => 0,
				'referer' => '',
			];
		}

		while ($pending !== [])
		{
			$batch = array_splice($pending, 0, $this->_maxBatchSize);
			$batchResults = $this->fetchBatch($batch);

			foreach ($batch as $index => $item)
			{
				$result = $batchResults[$index];
				$redirectUrl = $result['redirect_url'];
				if ($redirectUrl === '' && is_array($result['headers']) && isset($result['headers']['location']))
				{
					$redirectUrl = $result['headers']['location'];
				}

				if ($result['error'] === false
					&& $redirectUrl !== ''
					&& $this->isRedirect($result['code']))
				{
					if ($item['redirects'] >= $this->_maxRedirects)
					{
						$result['error'] = 'Too many redirects';
						$results[$item['key']] = $result;
						continue;
					}

					$target = UrlValidator::buildAbsoluteUrl($result['url'], $redirectUrl);
					$validated = $target === '' ? false : UrlValidator::validate($target);
					if ($validated === false)
					{
						$results[$item['key']] = $this->errorResult($result['url'], 'Redirect to prohibited target host blocked', 400);
						continue;
					}

					$pending[] = [
						'key' => $item['key'],
						'url' => $validated['url'],
						'validated' => $validated,
						'redirects' => $item['redirects'] + 1,
						'referer' => $result['url'],
					];
					continue;
				}

				$results[$item['key']] = $result;
			}
		}

		return $results;
	}

	/**
	 * Fetch a batch of validated URLs.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchBatch(array $items): array
	{
		$results = [];
		$handles = [];
		$multi = curl_multi_init();

		try
		{
			foreach ($items as $index => $item)
			{
				$state = (object) [
					'headers' => [],
					'body' => '',
					'too_large' => false,
				];

				$options = $this->buildOptions($item);
				$options[CURLOPT_HEADERFUNCTION] = static function ($handle, string $header) use ($state): int {
					$line = trim($header);
					if ($line === '')
					{
						return strlen($header);
					}

					if (stripos($line, 'HTTP/') === 0)
					{
						$state->headers = [];
						return strlen($header);
					}

					$parts = explode(':', $line, 2);
					if (count($parts) === 2)
					{
						$state->headers[strtolower(trim($parts[0]))] = trim($parts[1]);
					}

					return strlen($header);
				};
				$options[CURLOPT_WRITEFUNCTION] = function ($handle, string $data) use ($state): int {
					if ($state->too_large)
					{
						return 0;
					}

					$length = strlen($data);
					if (strlen($state->body) + $length > $this->_maxBodyBytes)
					{
						$state->too_large = true;
						return 0;
					}

					$state->body .= $data;
					return $length;
				};

				$handle = curl_init();
				if ($handle === false)
				{
					$results[$index] = $this->errorResult($item['url'], 'Unable to initialize cURL', 400);
					continue;
				}

				try
				{
					if (!curl_setopt_array($handle, $options))
					{
						throw new \RuntimeException('Unable to apply cURL options');
					}
				}
				catch (\Throwable $e)
				{
					curl_close($handle);
					$results[$index] = $this->errorResult($item['url'], $e->getMessage(), 400);
					continue;
				}

				if (curl_multi_add_handle($multi, $handle) !== CURLM_OK)
				{
					curl_close($handle);
					$results[$index] = $this->errorResult($item['url'], 'Unable to add cURL handle', 400);
					continue;
				}

				$handles[spl_object_id($handle)] = [
					'handle' => $handle,
					'index' => $index,
					'item' => $item,
					'state' => $state,
				];
			}

			if ($handles === [])
			{
				return $results;
			}

			$active = null;
			do
			{
				do
				{
					$status = curl_multi_exec($multi, $active);
				} while ($status === CURLM_CALL_MULTI_PERFORM);

				if ($status !== CURLM_OK)
				{
					break;
				}

				if ($active > 0)
				{
					$selected = curl_multi_select($multi, 0.5);
					if ($selected === -1)
					{
						usleep(10000);
					}
				}
			} while ($active > 0);

			$messages = [];
			while (($message = curl_multi_info_read($multi)) !== false)
			{
				if (($message['msg'] ?? null) === CURLMSG_DONE)
				{
					$messages[spl_object_id($message['handle'])] = (int) ($message['result'] ?? CURLE_OK);
				}
			}

			$multiError = $status !== CURLM_OK;

			foreach ($handles as $id => $entry)
			{
				$handle = $entry['handle'];
				$state = $entry['state'];
				$info = curl_getinfo($handle);
				$curlResult = $messages[$id] ?? ($multiError ? -1 : curl_errno($handle));

				$error = false;
				if ($state->too_large || $curlResult === CURLE_FILESIZE_EXCEEDED)
				{
					$error = 'Response exceeds maximum allowed size';
				}
				elseif ($curlResult !== CURLE_OK)
				{
					$curlError = curl_error($handle);
					$error = $curlError !== '' ? $curlError : ($multiError ? 'cURL multi error' : 'cURL error ' . $curlResult);
				}

				$results[$entry['index']] = [
					'url' => ($info['url'] ?? '') ?: $entry['item']['url'],
					'code' => $error === 'Response exceeds maximum allowed size' ? 413 : (int) ($info['http_code'] ?? 0),
					'error' => $error,
					'size' => strlen($state->body),
					'headers' => $state->headers ?: false,
					'body' => $state->body,
					'redirect_url' => (string) ($info['redirect_url'] ?? ''),
				];
			}
		} finally
		{
			foreach ($handles as $entry)
			{
				curl_multi_remove_handle($multi, $entry['handle']);
				curl_close($entry['handle']);
			}

			curl_multi_close($multi);
		}

		foreach ($items as $index => $item)
		{
			if (!isset($results[$index]))
			{
				$results[$index] = $this->errorResult($item['url'], 'Request did not complete', 400);
			}
		}

		return $results;
	}

	/**
	 * Build the cURL options for a validated request.
	 *
	 * @param array<string, mixed> $item
	 * @return array<int, mixed>
	 */
	private function buildOptions(array $item): array
	{
		$validated = $item['validated'];
		$defaults = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => false,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_MAXREDIRS => 0,
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_TIMEOUT => 5,
			CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
			CURLOPT_ENCODING => 'gzip,deflate',
			CURLOPT_HTTPHEADER => [
				'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language: en-US,en;q=0.9',
			],
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_HTTPGET => true,
			CURLOPT_URL => $validated['url'],
		];

		$options = $this->_curlOptions + $defaults;

		$options[CURLOPT_RETURNTRANSFER] = true;
		$options[CURLOPT_HEADER] = false;
		$options[CURLOPT_FOLLOWLOCATION] = false;
		$options[CURLOPT_MAXREDIRS] = 0;
		$options[CURLOPT_SSL_VERIFYPEER] = true;
		$options[CURLOPT_SSL_VERIFYHOST] = 2;
		$options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
		$options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
		$options[CURLOPT_HTTPGET] = true;
		$options[CURLOPT_URL] = $validated['url'];

		unset(
			$options[CURLOPT_POST],
			$options[CURLOPT_POSTFIELDS],
			$options[CURLOPT_UPLOAD],
			$options[CURLOPT_CUSTOMREQUEST],
			$options[CURLOPT_NOBODY]
		);

		$connectTimeout = (int) ($options[CURLOPT_CONNECTTIMEOUT] ?? 3);
		$options[CURLOPT_CONNECTTIMEOUT] = $connectTimeout > 0 ? min($connectTimeout, 10) : 3;
		$timeout = (int) ($options[CURLOPT_TIMEOUT] ?? 5);
		$options[CURLOPT_TIMEOUT] = $timeout > 0 ? min($timeout, 10) : 5;

		if ($item['referer'] !== '')
		{
			$options[CURLOPT_REFERER] = $item['referer'];
		}
		else
		{
			unset($options[CURLOPT_REFERER]);
		}

		$resolveEntry = UrlValidator::resolveEntry($validated);
		if ($resolveEntry !== '')
		{
			$options[CURLOPT_RESOLVE] = [$resolveEntry];
		}
		else
		{
			unset($options[CURLOPT_RESOLVE]);
		}

		return $options;
	}

	private function isRedirect(int $code): bool
	{
		return in_array($code, [301, 302, 303, 307, 308], true);
	}

	private function errorResult(string $url, string $error, int $code = 400): array
	{
		return [
			'url' => $url,
			'code' => $code,
			'error' => $error,
			'size' => 0,
			'headers' => false,
			'body' => false,
			'redirect_url' => '',
		];
	}
}
