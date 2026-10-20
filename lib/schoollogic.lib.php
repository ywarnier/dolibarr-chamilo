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

/**
 * Usernames to try, in order, when creating a teacher's Chamilo account. The first is the Dolibarr login, so both
 * systems share one name when possible. The creation endpoint requires a username and does not generate one, so when
 * a name is taken the next candidate adds a number: jdoe, jdoe.2, jdoe.3... When the login has no usable character,
 * firstname.lastname is used instead.
 *
 * @param string $login     Dolibarr login
 * @param string $firstname First name
 * @param string $lastname  Last name
 * @param int    $max       Number of candidates
 * @return list<string> Empty when no name can be built
 */
function schoolTeacherUsernameCandidates(string $login, string $firstname, string $lastname, int $max = 5): array
{
	$base = schoolWantedUsername($login);
	if ($base === '') {
		$base = schoolWantedUsername(strtolower(schoolAsciiFold(trim($firstname).'.'.trim($lastname))));
		$base = trim($base, '.');
	}
	if ($base === '' || $max < 1) {
		return array();
	}
	$candidates = array($base);
	for ($n = 2; $n <= $max; $n++) {
		$candidates[] = substr($base, 0, 100 - strlen('.'.$n)).'.'.$n;
	}
	return $candidates;
}

/**
 * What Chamilo's user update takes from a Dolibarr user. A phone number is sent only when there is one, so an
 * empty field in Dolibarr never blanks a number that someone typed in Chamilo.
 *
 * @param array{firstname?:string,lastname?:string,email?:string,phone?:string,statut?:int|string} $user Dolibarr user fields
 * @return array<string,mixed>
 */
function schoolTeacherPayload(array $user): array
{
	$payload = array(
		'firstname' => trim((string) ($user['firstname'] ?? '')),
		'lastname' => trim((string) ($user['lastname'] ?? '')),
		'email' => trim((string) ($user['email'] ?? '')),
		'active' => ((int) ($user['statut'] ?? 1)) === 1 ? 1 : 0,
	);
	$phone = trim((string) ($user['phone'] ?? ''));
	if ($phone !== '') {
		$payload['phone'] = $phone;
	}
	return $payload;
}

/**
 * Which field a 409 answer of Chamilo's user creation is about.
 *
 * @param string $message Error message of the response
 * @return string 'username', 'email' or '' for anything else
 */
function schoolConflictKind(string $message): string
{
	if (strpos($message, 'username_already_exists') !== false) {
		return 'username';
	}
	if (strpos($message, 'email_already_exists') !== false) {
		return 'email';
	}
	return '';
}

/**
 * What to do to make a list in Chamilo match the wanted one.
 *
 * @param list<int> $wanted Ids that must be present
 * @param list<int> $have   Ids present now
 * @return array{add:list<int>,remove:list<int>}
 */
function schoolIdsDiff(array $wanted, array $have): array
{
	return array(
		'add' => array_values(array_diff($wanted, $have)),
		'remove' => array_values(array_diff($have, $wanted)),
	);
}

/**
 * Letters with a diacritic replaced by their plain ASCII form, for a username built from a name ("Élodie" gives
 * "Elodie"). A table is used rather than iconv or intl, whose result depends on the server's locale and extensions.
 *
 * @param string $text Text in UTF-8
 * @return string
 */
function schoolAsciiFold(string $text): string
{
	static $map = array(
		'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'AE', 'Ç' => 'C', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
		'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Œ' => 'OE',
		'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'ß' => 'ss',
		'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
		'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'œ' => 'oe',
		'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
		'Ā' => 'A', 'ā' => 'a', 'Ă' => 'A', 'ă' => 'a', 'Ą' => 'A', 'ą' => 'a', 'Ć' => 'C', 'ć' => 'c', 'Č' => 'C', 'č' => 'c', 'Ď' => 'D', 'ď' => 'd',
		'Đ' => 'D', 'đ' => 'd', 'Ē' => 'E', 'ē' => 'e', 'Ę' => 'E', 'ę' => 'e', 'Ě' => 'E', 'ě' => 'e', 'Ğ' => 'G', 'ğ' => 'g', 'Ī' => 'I', 'ī' => 'i',
		'İ' => 'I', 'ı' => 'i', 'Ł' => 'L', 'ł' => 'l', 'Ń' => 'N', 'ń' => 'n', 'Ň' => 'N', 'ň' => 'n', 'Ō' => 'O', 'ō' => 'o', 'Ő' => 'O', 'ő' => 'o',
		'Ř' => 'R', 'ř' => 'r', 'Ś' => 'S', 'ś' => 's', 'Ş' => 'S', 'ş' => 's', 'Š' => 'S', 'š' => 's', 'Ţ' => 'T', 'ţ' => 't', 'Ť' => 'T', 'ť' => 't',
		'Ū' => 'U', 'ū' => 'u', 'Ů' => 'U', 'ů' => 'u', 'Ű' => 'U', 'ű' => 'u', 'Ź' => 'Z', 'ź' => 'z', 'Ż' => 'Z', 'ż' => 'z', 'Ž' => 'Z', 'ž' => 'z',
	);
	return strtr($text, $map);
}

/**
 * Typed reference stored in the dolibarr_id extra field of a Chamilo user. Teachers, students and parents come from
 * three different Dolibarr tables, so a bare number would be ambiguous: it is "user:7", "student:12" or "contact:30".
 *
 * @param string $type 'user', 'student' or 'contact'
 * @param int    $id   Id in the matching Dolibarr table
 * @return string
 */
function schoolDolibarrRef(string $type, int $id): string
{
	return $type.':'.$id;
}

/**
 * Email address to give a student's Chamilo account. Chamilo requires one, and it must be unique, while a child
 * often has none and must not share the parent's address. In order: the student's own address; else the address of a
 * parent with the username added after a plus sign (jane+leo.doe@example.org), which reaches the parent's mailbox
 * on most providers, so the parent receives the message to choose a password; else a placeholder on the domain set in
 * the module settings.
 *
 * @param string $own               Student's own email, may be empty
 * @param string $parentEmail       Email of a parent, may be empty
 * @param string $username          Chamilo username of the student
 * @param string $placeholderDomain Domain for the last resort, may be empty
 * @return string '' when no address can be built
 */
function schoolStudentEmail(string $own, string $parentEmail, string $username, string $placeholderDomain): string
{
	$own = trim($own);
	if ($own !== '') {
		return $own;
	}
	$tag = (string) preg_replace('/[^A-Za-z0-9._-]/', '', $username);
	$parentEmail = trim($parentEmail);
	if ($tag !== '' && preg_match('/^([^@+\s]+)(\+[^@\s]*)?@([^@\s]+)$/', $parentEmail, $m)) {
		return $m[1].'+'.$tag.'@'.$m[3];
	}
	$domain = trim($placeholderDomain);
	if ($tag !== '' && $domain !== '' && preg_match('/^[A-Za-z0-9.-]+$/', $domain)) {
		return $tag.'@'.$domain;
	}
	return '';
}

/**
 * Whether a class membership counts today. A student who left keeps the row with an end date, so that past invoices and
 * announcements stay traceable, but is no longer a member from the day after the end date.
 *
 * @param string|null $start Start date (YYYY-MM-DD), null when unknown
 * @param string|null $end   End date (YYYY-MM-DD), null while the student is in the class
 * @param string      $today Today (YYYY-MM-DD)
 * @return bool
 */
function schoolIsCurrentMember(?string $start, ?string $end, string $today): bool
{
	$start = $start !== null ? substr($start, 0, 10) : null;
	$end = $end !== null ? substr($end, 0, 10) : null;
	return ($start === null || $start <= $today) && ($end === null || $end >= $today);
}

/**
 * Parents to write to when announcing something to a class, without duplicates: a parent of two children in the class
 * gets one message. The address of the chosen contact is used, else that of the third party. Parents without an
 * address are skipped.
 *
 * @param array<int,array{contact_email?:?string,soc_email?:?string,name?:string,id?:int}> $links Parent links of the current members
 * @return list<array{email:string,name:string,id:int}> One entry per distinct address (case-insensitive), in order
 */
function schoolDistinctRecipients(array $links): array
{
	$seen = array();
	$out = array();
	foreach ($links as $l) {
		$email = trim((string) ($l['contact_email'] ?? ''));
		if ($email === '') {
			$email = trim((string) ($l['soc_email'] ?? ''));
		}
		if ($email === '') {
			continue;
		}
		$key = strtolower($email);
		if (isset($seen[$key])) {
			continue;
		}
		$seen[$key] = true;
		$out[] = array('email' => $email, 'name' => (string) ($l['name'] ?? ''), 'id' => (int) ($l['id'] ?? 0));
	}
	return $out;
}

/**
 * Split an amount between payers by percentage so that the parts add up to the amount exactly, to the cent. Each part is
 * rounded down to the cent and the cents that remain go to the largest fractions, in the order of the keys when equal,
 * so the result is the same every time. Shares do not have to add up to 100: they are taken as proportions.
 *
 * @param float                    $total  Amount to split
 * @param array<int|string,float>  $shares Percentage of each payer, by key
 * @return array<int|string,float> Amount of each payer, same keys, in the currency's cents
 */
function schoolSplitAmount(float $total, array $shares): array
{
	$sum = array_sum($shares);
	if ($shares === array() || $sum <= 0) {
		return array_fill_keys(array_keys($shares), 0.0);
	}
	$cents = (int) round($total * 100);
	$parts = array();
	$fractions = array();
	foreach ($shares as $key => $share) {
		$exact = $cents * ($share / $sum);
		$parts[$key] = (int) floor($exact + 1e-9);
		$fractions[$key] = $exact - $parts[$key];
	}
	$left = $cents - array_sum($parts);
	$order = array_keys($fractions);
	usort($order, static function ($a, $b) use ($fractions, $order) {
		$cmp = $fractions[$b] <=> $fractions[$a];
		return $cmp !== 0 ? $cmp : array_search($a, $order, true) <=> array_search($b, $order, true);
	});
	for ($i = 0; $i < $left; $i++) {
		$parts[$order[$i % count($order)]]++;
	}
	return array_map(static function (int $c): float {
		return $c / 100;
	}, $parts);
}

/**
 * Shares to propose for the payers of a student. The ones kept from the last time are used when they add up to 100, so a
 * decision taken once is proposed again. Otherwise a single payer pays everything, and several payers split it equally.
 *
 * @param array<int|string,float> $stored Percentage kept for each payer, by key (0 when never set)
 * @return array<int|string,float> Percentage proposed for each payer, same keys, adding up to 100
 */
function schoolProposeShares(array $stored): array
{
	if ($stored === array()) {
		return array();
	}
	if (schoolSharesValid($stored)) {
		return $stored;
	}
	$n = count($stored);
	if ($n === 1) {
		return array(array_key_first($stored) => 100.0);
	}
	// Equal split: the cents of a percent that do not divide go to the first payers.
	$base = floor(10000 / $n) / 100;
	$out = array_fill_keys(array_keys($stored), $base);
	$left = (int) round(10000 - $base * 100 * $n);
	foreach (array_keys($out) as $key) {
		if ($left <= 0) {
			break;
		}
		$out[$key] = round($out[$key] + 0.01, 2);
		$left--;
	}
	return $out;
}

/**
 * Whether the shares of the payers of a student are acceptable: each between 0 and 100, at least one above 0, and the
 * total 100 (a cent of a percent of tolerance for what was typed).
 *
 * @param array<int|string,float> $shares Percentage of each payer
 * @return bool
 */
function schoolSharesValid(array $shares): bool
{
	if ($shares === array()) {
		return false;
	}
	foreach ($shares as $share) {
		if ($share < 0 || $share > 100) {
			return false;
		}
	}
	// A margin on top of the cent of a percent: 33.33 three times is 99.99, and in floating point its distance to 100 is
	// a hair above 0.01.
	return abs(array_sum($shares) - 100) <= 0.01 + 1e-9 && max($shares) > 0;
}

/**
 * Whether two time ranges overlap. The test is strict: a class that ends at 10:00 and one that starts at 10:00 in the same
 * room do not overlap, which Dolibarr's own booking check (inclusive at both ends) would report as a conflict.
 *
 * @param int $aStart Start of the first range (timestamp)
 * @param int $aEnd   End of the first range
 * @param int $bStart Start of the second range
 * @param int $bEnd   End of the second range
 * @return bool
 */
function schoolIntervalsOverlap(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
{
	return $aStart < $bEnd && $bStart < $aEnd;
}

/**
 * Dates of a recurring schedule. From $start to $until included, on the given days of the week, every $everyWeeks weeks
 * (counted from the week of $start, which begins on Monday). Without days of the week, the day of the week of $start is
 * used, so a single date is just $start with $until = $start.
 *
 * @param string    $start      First day (YYYY-MM-DD)
 * @param string    $until      Last day (YYYY-MM-DD)
 * @param list<int> $weekdays   ISO days of the week: 1 Monday ... 7 Sunday
 * @param int       $everyWeeks 1 for every week, 2 for every other week...
 * @param int       $max        Most dates returned, so a typo in the end date cannot create thousands of events
 * @return list<string> Dates (YYYY-MM-DD) in order; empty when a date is invalid or $until is before $start
 */
function schoolRecurringDates(string $start, string $until, array $weekdays = array(), int $everyWeeks = 1, int $max = 200): array
{
	$from = DateTimeImmutable::createFromFormat('!Y-m-d', $start, new DateTimeZone('UTC'));
	$to = DateTimeImmutable::createFromFormat('!Y-m-d', $until, new DateTimeZone('UTC'));
	if (!$from || !$to || $from->format('Y-m-d') !== $start || $to->format('Y-m-d') !== $until || $to < $from) {
		return array();
	}
	$days = array_values(array_unique(array_filter(array_map('intval', $weekdays), static function (int $d): bool {
		return $d >= 1 && $d <= 7;
	})));
	if (!$days) {
		$days = array((int) $from->format('N'));
	}
	$everyWeeks = max(1, $everyWeeks);
	$firstMonday = $from->modify('-'.((int) $from->format('N') - 1).' days');
	$out = array();
	for ($day = $from; $day <= $to && count($out) < $max; $day = $day->modify('+1 day')) {
		if (!in_array((int) $day->format('N'), $days, true)) {
			continue;
		}
		$weeks = intdiv((int) $firstMonday->diff($day)->days, 7);
		if ($weeks % $everyWeeks === 0) {
			$out[] = $day->format('Y-m-d');
		}
	}
	return $out;
}

/**
 * What Chamilo's room takes from a room of the school.
 *
 * @param string      $title       Name of the room
 * @param string|null $description Description
 * @param int|null    $floor       Floor
 * @param int|null    $capacity    Capacity, kept only when positive (Chamilo refuses zero)
 * @return array<string,mixed>
 */
function schoolRoomPayload(string $title, ?string $description, ?int $floor, ?int $capacity): array
{
	$payload = array('title' => trim($title), 'description' => trim((string) $description) !== '' ? trim((string) $description) : null);
	if ($floor !== null) {
		$payload['floorNumber'] = $floor;
	}
	if ($capacity !== null && $capacity > 0) {
		$payload['capacity'] = $capacity;
	}
	return $payload;
}

/**
 * Chamilo's presence codes, as Dolibarr keeps them, and the group each belongs to.
 * 0 absent, 1 present, 2 late by less than 15 minutes, 3 late by more, 4 absent with a reason.
 *
 * @param int $code Presence code of Chamilo
 * @return string 'present', 'late', 'absent' or 'excused'; '' for a code that is not known
 */
function schoolPresenceGroup(int $code): string
{
	$groups = array(0 => 'absent', 1 => 'present', 2 => 'late', 3 => 'late', 4 => 'excused');
	return $groups[$code] ?? '';
}

/**
 * What Chamilo answers for the presences of a date, as a list of user id => presence code. Entries that are not a pair
 * of whole numbers, or whose code is unknown, are left out, so nothing odd is stored.
 *
 * @param mixed $data Decoded answer of Chamilo
 * @return array{done:bool,presences:array<int,int>}|null null when the answer is not what was expected
 */
function schoolParsePresences($data): ?array
{
	if (!is_array($data) || !array_key_exists('sheets', $data) || !is_array($data['sheets'])) {
		return null;
	}
	$presences = array();
	foreach ($data['sheets'] as $row) {
		if (!is_array($row) || !isset($row['userId'], $row['presence']) || !is_int($row['userId']) || !is_int($row['presence'])) {
			continue;
		}
		if ($row['userId'] > 0 && schoolPresenceGroup($row['presence']) !== '') {
			$presences[$row['userId']] = $row['presence'];
		}
	}
	return array('done' => !empty($data['done']), 'presences' => $presences);
}

/**
 * Totals of a list of presence codes by group.
 *
 * @param list<int> $codes Presence codes
 * @return array{present:int,late:int,absent:int,excused:int}
 */
function schoolPresenceTotals(array $codes): array
{
	$totals = array('present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0);
	foreach ($codes as $code) {
		$group = schoolPresenceGroup((int) $code);
		if ($group !== '') {
			$totals[$group]++;
		}
	}
	return $totals;
}
