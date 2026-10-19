<?php
/* Copyright (C) 2026 School contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

/**
 * \file    class/chamiloclient.class.php
 * \ingroup school
 * \brief   HTTP client for the Chamilo REST API (API Platform), authenticated with an external API key.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
require_once __DIR__.'/../lib/schoollogic.lib.php';

/**
 * Thin client. Every call returns array{ok:bool,status:int,data:mixed,error:string,retryable:bool}
 * and never throws, so a job can be marked failed or retried from the result alone.
 */
class ChamiloClient
{
	/** @var string */
	private $baseUrl;
	/** @var string */
	private $apiKey;
	/** @var bool */
	private $allowLocal;
	/** @var int */
	private $timeout;
	/** @var int|null 0 when looked up and not found */
	private $accessUrlId = null;

	/**
	 * @param string $baseUrl    Portal URL without "/api"
	 * @param string $apiKey     Key issued by chamilo:security:generate-external-api-key
	 * @param bool   $allowLocal Allow a private or loopback address (development only)
	 * @param int    $timeout    Seconds
	 */
	public function __construct(string $baseUrl, string $apiKey, bool $allowLocal = false, int $timeout = 30)
	{
		$this->baseUrl = $baseUrl;
		$this->apiKey = $apiKey;
		$this->allowLocal = $allowLocal;
		$this->timeout = $timeout;
	}

	/**
	 * @param string                    $method GET, POST, PATCH, PUT or DELETE
	 * @param string                    $path   Path under the portal, such as "/api/sessions/3"
	 * @param array<string,mixed>       $query  Query string parameters
	 * @param array<mixed>|null         $body   JSON body
	 * @return array{ok:bool,status:int,data:mixed,error:string,retryable:bool}
	 */
	public function request(string $method, string $path, array $query = array(), ?array $body = null): array
	{
		$url = $this->baseUrl.'/'.ltrim($path, '/');
		if ($query) {
			$url .= '?'.http_build_query($query);
		}

		$headers = array(
			'Authorization: Bearer '.$this->apiKey,
			'Accept: application/ld+json',
		);
		$payload = '';
		if ($body !== null) {
			$payload = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			// API Platform requires the merge-patch media type for PATCH.
			$headers[] = 'Content-Type: '.($method === 'PATCH' ? 'application/merge-patch+json' : 'application/json');
		}

		$verb = $method;
		if ($body !== null && in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
			$verb = $method.'ALREADYFORMATED';
		}

		// Redirects are not followed: the Bearer header must not leave the configured portal.
		$res = getURLContent($url, $verb, $payload, 0, $headers, array('http', 'https'), $this->allowLocal ? 1 : 0, -1, 10, $this->timeout);

		$status = (int) ($res['http_code'] ?? 0);
		$raw = (string) ($res['content'] ?? '');
		$data = $raw !== '' ? json_decode($raw, true) : null;
		$ok = empty($res['curl_error_no']) && $status >= 200 && $status < 300;

		$error = '';
		if (!$ok) {
			if (!empty($res['curl_error_msg'])) {
				$error = (string) $res['curl_error_msg'];
			} elseif (is_array($data)) {
				foreach (array('detail', 'description', 'hydra:description', 'message', 'error') as $k) {
					if (!empty($data[$k]) && is_string($data[$k])) {
						$error = $data[$k];
						break;
					}
				}
			}
			if ($error === '') {
				$error = 'HTTP '.$status;
			}
			$status = $status > 0 && empty($res['curl_error_no']) ? $status : 0;
		}

		return array(
			'ok' => $ok,
			'status' => $status,
			'data' => $data,
			'error' => $error,
			'retryable' => $ok ? false : schoolIsRetryableStatus($status),
		);
	}

	/**
	 * Items of a collection response, whichever JSON-LD key the server used.
	 *
	 * @param mixed $data Decoded response
	 * @return array<int,mixed>
	 */
	public static function members($data): array
	{
		if (!is_array($data)) {
			return array();
		}
		foreach (array('member', 'hydra:member') as $k) {
			if (isset($data[$k]) && is_array($data[$k])) {
				return $data[$k];
			}
		}
		return array_is_list($data) ? $data : array();
	}

	/**
	 * Id of the Chamilo portal (access URL) this client talks to: the one whose address has the same host and port as
	 * the configured URL. Some endpoints, such as the creation of a user, are addressed to a portal.
	 *
	 * @return int|null null when no portal matches
	 */
	public function accessUrlId(): ?int
	{
		if ($this->accessUrlId !== null) {
			return $this->accessUrlId ?: null;
		}
		$res = $this->request('GET', '/api/access_urls', array('itemsPerPage' => 200));
		$mine = parse_url($this->baseUrl);
		$found = 0;
		foreach (ChamiloClient::members($res['ok'] ? $res['data'] : null) as $a) {
			$theirs = is_array($a) ? parse_url((string) ($a['url'] ?? '')) : false;
			if ($theirs && $mine && strcasecmp((string) ($theirs['host'] ?? ''), (string) ($mine['host'] ?? '')) === 0
				&& (int) ($theirs['port'] ?? 0) === (int) ($mine['port'] ?? 0)) {
				$found = (int) $a['id'];
				break;
			}
		}
		$this->accessUrlId = $found;
		return $found ?: null;
	}

	/**
	 * Check the URL, the key and the admin rights the sync needs. /api/sessions needs ROLE_ADMIN.
	 *
	 * @return array{ok:bool,status:int,data:mixed,error:string,retryable:bool}
	 */
	public function ping(): array
	{
		return $this->request('GET', '/api/sessions', array('itemsPerPage' => 1));
	}
}
