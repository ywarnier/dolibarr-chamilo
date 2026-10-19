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
 * \file    lib/schoollogic.lib.php
 * \ingroup school
 * \brief   Pure rules. This file does not load Dolibarr.
 */

/**
 * Normalize the Chamilo portal URL typed in the setup page.
 *
 * @param string $url Raw value
 * @return string Base URL without trailing slash or "/api" suffix, or '' when it is not an http(s) URL
 */
function schoolNormalizeBaseUrl(string $url): string
{
	$url = trim($url);
	if (!preg_match('#^https?://[^\s/?\#]+#i', $url)) {
		return '';
	}
	$url = rtrim($url, '/');
	$url = preg_replace('#/api$#i', '', $url);
	return (string) $url;
}

/**
 * Numeric id at the end of an API Platform IRI, such as "/api/courses/12".
 *
 * @param string $iri IRI
 * @return int|null
 */
function schoolIriId(string $iri): ?int
{
	if (preg_match('#/(\d+)$#', $iri, $m)) {
		return (int) $m[1];
	}
	return null;
}

/**
 * Seconds to wait before the next attempt: 1 minute, doubling, capped at 1 hour.
 *
 * @param int $attempts Attempts already made (1 after the first failure)
 * @return int
 */
function schoolBackoffSeconds(int $attempts): int
{
	if ($attempts < 1) {
		return 0;
	}
	return min(3600, 60 * (2 ** min($attempts - 1, 6)));
}

/**
 * Stable hash of a payload, independent of key order, to skip pushes that change nothing.
 *
 * @param array<mixed> $payload Payload
 * @return string sha1
 */
function schoolPayloadHash(array $payload): string
{
	$sort = static function (array $a) use (&$sort): array {
		foreach ($a as $k => $v) {
			if (is_array($v)) {
				$a[$k] = $sort($v);
			}
		}
		if (array_keys($a) !== range(0, count($a) - 1)) {
			ksort($a);
		}
		return $a;
	};
	return sha1((string) json_encode($sort($payload)));
}

/**
 * Duration stored by Dolibarr on a product ("3h", "2d", "1w", "6m") converted to hours.
 * A day counts for 24 hours, a week for 168, a month for 720, a year for 8760.
 *
 * @param string $duration Dolibarr duration string
 * @return float|null Hours, or null when the string is not understood
 */
function schoolDurationToHours(string $duration): ?float
{
	if (!preg_match('/^([\d.]+)\s*(s|i|mn|min|h|d|w|m|y)$/', trim($duration), $m)) {
		return null;
	}
	$factor = array('s' => 1 / 3600, 'i' => 1 / 60, 'mn' => 1 / 60, 'min' => 1 / 60, 'h' => 1, 'd' => 24, 'w' => 168, 'm' => 720, 'y' => 8760);
	return (float) $m[1] * $factor[$m[2]];
}

/**
 * Whether a failed Chamilo call is worth retrying. Network errors (status 0), timeouts, rate limiting
 * and server errors are; other client errors (validation, permission, not found) will fail again.
 *
 * @param int $status HTTP status, 0 when the request did not complete
 * @return bool
 */
function schoolIsRetryableStatus(int $status): bool
{
	return $status === 0 || $status === 408 || $status === 429 || $status >= 500;
}

/**
 * Chamilo username to ask for a teacher: the Dolibarr login, so both systems share one name when possible.
 * Only letters, digits, dot, underscore and hyphen are kept, which every Chamilo portal accepts, and the
 * result is cut to 100 characters (the length of the stored column). The caller must keep the username
 * Chamilo actually returned: when this one is taken or refused, Chamilo generates its own.
 *
 * @param string $login Dolibarr login
 * @return string Wanted username, '' when nothing usable is left
 */
function schoolWantedUsername(string $login): string
{
	$name = (string) preg_replace('/[^A-Za-z0-9._-]/', '', trim($login));
	return substr($name, 0, 100);
}

/**
 * ISO 8601 date sent by Chamilo as a Unix timestamp.
 *
 * @param mixed $value Value from the API, absent or empty when unset
 * @return int|null
 */
function schoolParseDate($value): ?int
{
	if (!is_string($value) || trim($value) === '') {
		return null;
	}
	$ts = strtotime($value);
	return $ts === false ? null : $ts;
}

/**
 * Fields of a Chamilo course that the School module keeps.
 *
 * @param array<string,mixed> $item Course as returned by /api/courses
 * @return array{chamilo_id:int,code:string,title:string,language:string}|null null when the item has no id or code
 */
function schoolMapCourse(array $item): ?array
{
	$id = isset($item['id']) ? (int) $item['id'] : (schoolIriId((string) ($item['@id'] ?? '')) ?? 0);
	$code = trim((string) ($item['code'] ?? ''));
	if ($id <= 0 || $code === '') {
		return null;
	}
	$title = trim((string) ($item['title'] ?? ''));
	return array(
		'chamilo_id' => $id,
		'code' => substr($code, 0, 40),
		'title' => $title !== '' ? $title : $code,
		'language' => substr(trim((string) ($item['courseLanguage'] ?? '')), 0, 10),
	);
}

/**
 * Fields of a Chamilo session that the School module keeps. Use the item response (/api/sessions/{id}):
 * the collection response leaves the dates out.
 *
 * @param array<string,mixed> $item Session
 * @return array<string,mixed>|null null when the item has no id
 */
function schoolMapSession(array $item): ?array
{
	$id = isset($item['id']) ? (int) $item['id'] : (schoolIriId((string) ($item['@id'] ?? '')) ?? 0);
	if ($id <= 0) {
		return null;
	}
	$courseIds = array();
	foreach ((array) ($item['courses'] ?? array()) as $link) {
		$course = is_array($link) ? ($link['course'] ?? null) : null;
		if (is_array($course)) {
			$cid = isset($course['id']) ? (int) $course['id'] : (schoolIriId((string) ($course['@id'] ?? '')) ?? 0);
		} else {
			$cid = schoolIriId((string) $course);
		}
		if ($cid > 0) {
			$courseIds[$cid] = $cid;
		}
	}
	$title = trim((string) ($item['title'] ?? ''));
	return array(
		'chamilo_id' => $id,
		'title' => $title !== '' ? $title : 'Session '.$id,
		'display_start' => schoolParseDate($item['displayStartDate'] ?? null),
		'display_end' => schoolParseDate($item['displayEndDate'] ?? null),
		'access_start' => schoolParseDate($item['accessStartDate'] ?? null),
		'access_end' => schoolParseDate($item['accessEndDate'] ?? null),
		'coach_access_start' => schoolParseDate($item['coachAccessStartDate'] ?? null),
		'coach_access_end' => schoolParseDate($item['coachAccessEndDate'] ?? null),
		'course_ids' => array_values($courseIds),
	);
}

/**
 * Reference of the Dolibarr product that stands for a Chamilo course or session.
 *
 * @param string     $kind  'C' for a course, 'S' for a session
 * @param string|int $ident Course code or session id
 * @return string
 */
function schoolProductRef(string $kind, $ident): string
{
	return 'SCH-'.$kind.'-'.substr((string) preg_replace('/[^A-Za-z0-9._-]/', '', (string) $ident), 0, 100);
}

/**
 * Price as Chamilo stores it in a decimal extra field: dot separator, no thousands separator, no trailing zeros.
 *
 * @param float $price Price
 * @return string '' when the price is not above zero (no price set)
 */
function schoolFormatPrice(float $price): string
{
	if ($price <= 0) {
		return '';
	}
	return rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.');
}
