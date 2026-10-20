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
 * \file    test/phpunit/SchoolLogicTest.php
 * \ingroup school
 * \brief   Pure rules. This file does not load Dolibarr and is not part of the core suite.
 */

require_once dirname(__FILE__).'/../../lib/schoollogic.lib.php';

use PHPUnit\Framework\TestCase;

/**
 * URL normalisation, IRI ids, backoff, payload hash and duration conversion.
 */
class SchoolLogicTest extends TestCase
{
	public function testNormalizeBaseUrl(): void
	{
		$this->assertSame('https://my.chamilo.net', schoolNormalizeBaseUrl(' https://my.chamilo.net/ '));
		$this->assertSame('https://my.chamilo.net', schoolNormalizeBaseUrl('https://my.chamilo.net/api/'));
		$this->assertSame('http://localhost:8080/chamilo', schoolNormalizeBaseUrl('http://localhost:8080/chamilo'));
		$this->assertSame('', schoolNormalizeBaseUrl('ftp://my.chamilo.net'));
		$this->assertSame('', schoolNormalizeBaseUrl('my.chamilo.net'));
		$this->assertSame('', schoolNormalizeBaseUrl(''));
	}

	public function testIriId(): void
	{
		$this->assertSame(12, schoolIriId('/api/courses/12'));
		$this->assertNull(schoolIriId('/api/courses'));
		$this->assertNull(schoolIriId('/api/courses/abc'));
	}

	public function testBackoff(): void
	{
		$this->assertSame(0, schoolBackoffSeconds(0));
		$this->assertSame(60, schoolBackoffSeconds(1));
		$this->assertSame(120, schoolBackoffSeconds(2));
		$this->assertSame(240, schoolBackoffSeconds(3));
		$this->assertSame(3600, schoolBackoffSeconds(50));
	}

	public function testPayloadHashIgnoresKeyOrder(): void
	{
		$a = array('title' => 'X', 'dates' => array('b' => 2, 'a' => 1), 'list' => array(3, 1));
		$b = array('list' => array(3, 1), 'dates' => array('a' => 1, 'b' => 2), 'title' => 'X');
		$this->assertSame(schoolPayloadHash($a), schoolPayloadHash($b));
		$this->assertNotSame(schoolPayloadHash($a), schoolPayloadHash(array('title' => 'Y')));
		// List order is meaningful.
		$this->assertNotSame(schoolPayloadHash(array('list' => array(1, 3))), schoolPayloadHash(array('list' => array(3, 1))));
	}

	public function testDurationToHours(): void
	{
		$this->assertSame(3.0, schoolDurationToHours('3h'));
		$this->assertSame(48.0, schoolDurationToHours('2d'));
		$this->assertSame(0.5, schoolDurationToHours('30mn'));
		$this->assertNull(schoolDurationToHours('soon'));
		$this->assertNull(schoolDurationToHours(''));
	}

	public function testRetryableStatus(): void
	{
		$this->assertTrue(schoolIsRetryableStatus(0));
		$this->assertTrue(schoolIsRetryableStatus(429));
		$this->assertTrue(schoolIsRetryableStatus(503));
		$this->assertFalse(schoolIsRetryableStatus(400));
		$this->assertFalse(schoolIsRetryableStatus(403));
		$this->assertFalse(schoolIsRetryableStatus(409));
		$this->assertFalse(schoolIsRetryableStatus(200));
	}

	public function testWantedUsername(): void
	{
		$this->assertSame('jdoe', schoolWantedUsername(' jdoe '));
		$this->assertSame('j.doe-2_x', schoolWantedUsername('j.doe-2_x'));
		// Characters outside A-Z a-z 0-9 . _ - are dropped, accented letters included.
		$this->assertSame('jdoecole', schoolWantedUsername('j doe@école'));
		$this->assertSame('', schoolWantedUsername('@@ '));
		$this->assertSame(100, strlen(schoolWantedUsername(str_repeat('a', 150))));
	}

	public function testMapCourse(): void
	{
		$c = schoolMapCourse(array('id' => 3, 'code' => 'ENGLISH101', 'title' => 'English for beginners', 'courseLanguage' => 'en_US'));
		$this->assertSame(array('chamilo_id' => 3, 'code' => 'ENGLISH101', 'title' => 'English for beginners', 'language' => 'en_US'), $c);
		$c = schoolMapCourse(array('@id' => '/api/courses/9', 'code' => 'X', 'title' => ''));
		$this->assertSame(9, $c['chamilo_id']);
		$this->assertSame('X', $c['title']);
		$this->assertSame('', $c['language']);
		$this->assertNull(schoolMapCourse(array('id' => 4, 'code' => '')));
		$this->assertNull(schoolMapCourse(array('code' => 'X')));
	}

	public function testMapSession(): void
	{
		$s = schoolMapSession(array(
			'id' => 1, 'title' => 'Session 1AT', 'displayStartDate' => '2026-08-17T22:47:00+00:00',
			'accessStartDate' => '2026-08-17T22:47:00+00:00',
			'courses' => array(array('@id' => '/api/session_rel_courses/1', 'course' => array('@id' => '/api/courses/1', 'id' => 1)), array('course' => '/api/courses/5')),
		));
		$this->assertSame(1, $s['chamilo_id']);
		$this->assertSame(gmmktime(22, 47, 0, 8, 17, 2026), $s['display_start']);
		$this->assertNull($s['display_end']);
		$this->assertNull($s['access_end']);
		$this->assertSame(array(1, 5), $s['course_ids']);
		$this->assertNull(schoolMapSession(array('title' => 'no id')));
		$this->assertSame('Session 7', schoolMapSession(array('id' => 7, 'title' => ' '))['title']);
	}

	public function testParseDateAndProductRef(): void
	{
		$this->assertNull(schoolParseDate(null));
		$this->assertNull(schoolParseDate(''));
		$this->assertNull(schoolParseDate('not a date'));
		$this->assertSame('SCH-C-ENGLISH101', schoolProductRef('C', 'ENGLISH101'));
		$this->assertSame('SCH-S-12', schoolProductRef('S', 12));
		$this->assertSame('SCH-C-ab', schoolProductRef('C', 'a b/é'));
	}

	public function testFormatPrice(): void
	{
		$this->assertSame('', schoolFormatPrice(0.0));
		$this->assertSame('', schoolFormatPrice(-3.0));
		$this->assertSame('120', schoolFormatPrice(120.0));
		$this->assertSame('99.9', schoolFormatPrice(99.9));
		$this->assertSame('1234.57', schoolFormatPrice(1234.567));
	}

	public function testTeacherUsernameCandidates(): void
	{
		$this->assertSame(array('jdoe', 'jdoe.2', 'jdoe.3'), schoolTeacherUsernameCandidates('jdoe', 'Jane', 'Doe', 3));
		// No usable login: fall back to firstname.lastname.
		$this->assertSame(array('jane.doe', 'jane.doe.2'), schoolTeacherUsernameCandidates('@@', 'Jane', 'Doe', 2));
		$this->assertSame(array(), schoolTeacherUsernameCandidates('@@', '', '', 3));
		$this->assertSame(array(), schoolTeacherUsernameCandidates('jdoe', 'Jane', 'Doe', 0));
		// A 100 character login keeps room for the suffix.
		$long = schoolTeacherUsernameCandidates(str_repeat('a', 100), '', '', 3);
		$this->assertSame(100, strlen($long[0]));
		$this->assertSame(100, strlen($long[2]));
		$this->assertStringEndsWith('.3', $long[2]);
	}

	public function testTeacherPayload(): void
	{
		$this->assertSame(
			array('firstname' => 'Jane', 'lastname' => 'Doe', 'email' => 'j@x.org', 'active' => 1, 'phone' => '+33 1'),
			schoolTeacherPayload(array('firstname' => ' Jane ', 'lastname' => 'Doe', 'email' => 'j@x.org', 'phone' => '+33 1', 'statut' => 1))
		);
		$p = schoolTeacherPayload(array('firstname' => 'Jane', 'lastname' => 'Doe', 'email' => 'j@x.org', 'phone' => '', 'statut' => '0'));
		$this->assertSame(0, $p['active']);
		$this->assertArrayNotHasKey('phone', $p);
	}

	public function testConflictKindAndIdsDiff(): void
	{
		$this->assertSame('username', schoolConflictKind('username_already_exists'));
		$this->assertSame('email', schoolConflictKind('email_already_exists'));
		$this->assertSame('', schoolConflictKind('Internal Server Error'));
		$this->assertSame(array('add' => array(3), 'remove' => array(1)), schoolIdsDiff(array(2, 3), array(1, 2)));
		$this->assertSame(array('add' => array(), 'remove' => array()), schoolIdsDiff(array(), array()));
	}

	public function testAsciiFoldAndNameBasedUsernames(): void
	{
		$this->assertSame('Elodie Francois', schoolAsciiFold('Élodie François'));
		$this->assertSame('Zoe Lukasz Dvorak', schoolAsciiFold('Zoë Łukasz Dvořák'));
		$this->assertSame('plain', schoolAsciiFold('plain'));
		// A student has no login: the username comes from the name, accents folded, spaces dropped.
		$this->assertSame(array('elodie.francois', 'elodie.francois.2'), schoolTeacherUsernameCandidates('', 'Élodie', 'François', 2));
		$this->assertSame(array('jeanpierre.dupont'), schoolTeacherUsernameCandidates('', 'Jean Pierre', 'Dupont', 1));
	}

	public function testDolibarrRef(): void
	{
		$this->assertSame('user:7', schoolDolibarrRef('user', 7));
		$this->assertSame('student:12', schoolDolibarrRef('student', 12));
		$this->assertSame('contact:30', schoolDolibarrRef('contact', 30));
	}

	public function testStudentEmail(): void
	{
		// Own address first.
		$this->assertSame('leo@kids.org', schoolStudentEmail(' leo@kids.org ', 'jane@example.org', 'leo.doe', 'school.test'));
		// Else the parent's address with the username after a plus sign.
		$this->assertSame('jane+leo.doe@example.org', schoolStudentEmail('', 'jane@example.org', 'leo.doe', 'school.test'));
		// A parent address that already has a tag keeps only its base.
		$this->assertSame('jane+leo.doe@example.org', schoolStudentEmail('', 'jane+old@example.org', 'leo.doe', ''));
		// Else a placeholder on the configured domain.
		$this->assertSame('leo.doe@school.test', schoolStudentEmail('', '', 'leo.doe', 'school.test'));
		$this->assertSame('leo.doe@school.test', schoolStudentEmail('', 'not an email', 'leo.doe', 'school.test'));
		// Nothing to build it from.
		$this->assertSame('', schoolStudentEmail('', '', 'leo.doe', ''));
		$this->assertSame('', schoolStudentEmail('', 'jane@example.org', '', ''));
		$this->assertSame('', schoolStudentEmail('', '', 'leo.doe', 'bad domain!'));
	}

	public function testCurrentMember(): void
	{
		$this->assertTrue(schoolIsCurrentMember('2026-09-01', null, '2026-10-20'));
		$this->assertTrue(schoolIsCurrentMember(null, null, '2026-10-20'));
		// Not started yet.
		$this->assertFalse(schoolIsCurrentMember('2026-11-01', null, '2026-10-20'));
		// The end day itself still counts, the day after does not.
		$this->assertTrue(schoolIsCurrentMember('2026-09-01', '2026-10-20', '2026-10-20'));
		$this->assertFalse(schoolIsCurrentMember('2026-09-01', '2026-10-19', '2026-10-20'));
		// A datetime from the database is cut to its date.
		$this->assertTrue(schoolIsCurrentMember('2026-09-01 00:00:00', '2026-10-20 00:00:00', '2026-10-20'));
	}

	public function testDistinctRecipients(): void
	{
		$links = array(
			array('contact_email' => 'Jane@Example.org', 'soc_email' => 'family@example.org', 'name' => 'Doe family', 'id' => 1),
			// Second child of the same parent: same address, different case.
			array('contact_email' => 'jane@example.org', 'soc_email' => '', 'name' => 'Doe family', 'id' => 1),
			// No contact address: the third party's is used.
			array('contact_email' => '', 'soc_email' => 'roe@example.org', 'name' => 'Roe', 'id' => 2),
			// No address at all: skipped.
			array('contact_email' => null, 'soc_email' => ' ', 'name' => 'Poe', 'id' => 3),
		);
		$r = schoolDistinctRecipients($links);
		$this->assertSame(array('Jane@Example.org', 'roe@example.org'), array_column($r, 'email'));
		$this->assertSame(array(1, 2), array_column($r, 'id'));
		$this->assertSame(array(), schoolDistinctRecipients(array()));
	}

	public function testSplitAmountAddsUpToTheCent(): void
	{
		$this->assertSame(array('a' => 100.0), schoolSplitAmount(100.0, array('a' => 100.0)));
		$this->assertSame(array('a' => 60.0, 'b' => 40.0), schoolSplitAmount(100.0, array('a' => 60.0, 'b' => 40.0)));
		// 100 / 3 does not divide: 33.34 + 33.33 + 33.33, the extra cent to the first.
		$r = schoolSplitAmount(100.0, array('a' => 33.33, 'b' => 33.33, 'c' => 33.34));
		$this->assertEqualsWithDelta(100.0, array_sum($r), 0.0001);
		$this->assertSame(array(33.33, 33.33, 33.34), array_values($r));
		// 0.01 between two equal payers: one cent cannot be halved, it goes to the first.
		$this->assertSame(array('a' => 0.01, 'b' => 0.0), schoolSplitAmount(0.01, array('a' => 50.0, 'b' => 50.0)));
		// An odd cent is given to the largest fraction, whatever the order.
		$this->assertSame(array('x' => 50.01, 'y' => 50.0), schoolSplitAmount(100.01, array('x' => 50.0, 'y' => 50.0)));
		// Amounts that are awkward in binary floating point still add up.
		foreach (array(19.99, 0.07, 1234.56, 99.995, 0.30) as $total) {
			$r = schoolSplitAmount($total, array(1 => 33.34, 2 => 33.33, 3 => 33.33));
			$this->assertEqualsWithDelta(round($total, 2), array_sum($r), 0.0001, 'total '.$total);
			foreach ($r as $part) {
				$this->assertEqualsWithDelta(round($part, 2), $part, 1e-9);
			}
		}
		// Shares are proportions: 1 and 3 give a quarter and three quarters.
		$this->assertSame(array('a' => 25.0, 'b' => 75.0), schoolSplitAmount(100.0, array('a' => 1.0, 'b' => 3.0)));
		// Nothing to split.
		$this->assertSame(array('a' => 0.0), schoolSplitAmount(100.0, array('a' => 0.0)));
		$this->assertSame(array(), schoolSplitAmount(100.0, array()));
		$this->assertSame(array('a' => 0.0, 'b' => 0.0), schoolSplitAmount(0.0, array('a' => 50.0, 'b' => 50.0)));
	}

	public function testProposeShares(): void
	{
		// What was decided before is proposed again.
		$this->assertSame(array(5 => 70.0, 9 => 30.0), schoolProposeShares(array(5 => 70.0, 9 => 30.0)));
		// A single payer pays everything, even when nothing was stored.
		$this->assertSame(array(5 => 100.0), schoolProposeShares(array(5 => 0.0)));
		// Several payers with nothing stored: an equal split that adds up to 100.
		$this->assertSame(array(5 => 50.0, 9 => 50.0), schoolProposeShares(array(5 => 0.0, 9 => 0.0)));
		$three = schoolProposeShares(array(1 => 0.0, 2 => 0.0, 3 => 0.0));
		$this->assertEqualsWithDelta(100.0, array_sum($three), 0.0001);
		$this->assertSame(array(33.34, 33.33, 33.33), array_values($three));
		// Stored shares that no longer add up (a payer was added) are not reused.
		$stale = schoolProposeShares(array(5 => 100.0, 9 => 0.0, 11 => 0.0));
		$this->assertEqualsWithDelta(100.0, array_sum($stale), 0.0001);
		$this->assertSame(array(), schoolProposeShares(array()));
	}

	public function testSharesValid(): void
	{
		$this->assertTrue(schoolSharesValid(array(1 => 100.0)));
		$this->assertTrue(schoolSharesValid(array(1 => 60.0, 2 => 40.0)));
		$this->assertTrue(schoolSharesValid(array(1 => 33.33, 2 => 33.33, 3 => 33.34)));
		// What was typed with a cent of a percent of difference.
		$this->assertTrue(schoolSharesValid(array(1 => 33.33, 2 => 33.33, 3 => 33.33)));
		$this->assertFalse(schoolSharesValid(array(1 => 60.0, 2 => 30.0)));
		$this->assertFalse(schoolSharesValid(array(1 => 110.0, 2 => -10.0)));
		$this->assertFalse(schoolSharesValid(array(1 => 0.0)));
		$this->assertFalse(schoolSharesValid(array()));
	}

	public function testIntervalsOverlapStrictly(): void
	{
		$nine = strtotime('2030-01-07 09:00:00');
		$ten = strtotime('2030-01-07 10:00:00');
		$eleven = strtotime('2030-01-07 11:00:00');
		$this->assertTrue(schoolIntervalsOverlap($nine, $ten, $nine, $ten));
		$this->assertTrue(schoolIntervalsOverlap($nine, $eleven, $ten, $eleven));
		$this->assertTrue(schoolIntervalsOverlap($nine + 1800, $ten + 1800, $nine, $ten));
		// Back to back in the same room is not a conflict.
		$this->assertFalse(schoolIntervalsOverlap($nine, $ten, $ten, $eleven));
		$this->assertFalse(schoolIntervalsOverlap($ten, $eleven, $nine, $ten));
		$this->assertFalse(schoolIntervalsOverlap($nine, $ten, $eleven, $eleven + 3600));
	}

	public function testRecurringDates(): void
	{
		// 2030-01-07 is a Monday.
		$this->assertSame(array('2030-01-07'), schoolRecurringDates('2030-01-07', '2030-01-07'));
		$this->assertSame(array('2030-01-07', '2030-01-14', '2030-01-21'), schoolRecurringDates('2030-01-07', '2030-01-21'));
		// Monday and Wednesday.
		$this->assertSame(array('2030-01-07', '2030-01-09', '2030-01-14', '2030-01-16'), schoolRecurringDates('2030-01-07', '2030-01-17', array(1, 3)));
		// Every other week, counted from the week of the start.
		$this->assertSame(array('2030-01-07', '2030-01-21', '2030-02-04'), schoolRecurringDates('2030-01-07', '2030-02-10', array(), 2));
		// A start on a Wednesday with Monday and Wednesday: the Monday of that week is before the start, so it is not used.
		$this->assertSame(array('2030-01-09', '2030-01-14', '2030-01-16'), schoolRecurringDates('2030-01-09', '2030-01-16', array(1, 3)));
		// Every other week from a Wednesday: the following week's Monday belongs to the next week and is skipped.
		$this->assertSame(array('2030-01-09', '2030-01-21', '2030-01-23'), schoolRecurringDates('2030-01-09', '2030-01-25', array(1, 3), 2));
		// The start day is not a chosen day: the first date is the next chosen one.
		$this->assertSame(array('2030-01-11'), schoolRecurringDates('2030-01-07', '2030-01-12', array(5)));
		// Invalid input, or an end before the start.
		$this->assertSame(array(), schoolRecurringDates('2030-02-30', '2030-03-01'));
		$this->assertSame(array(), schoolRecurringDates('soon', '2030-03-01'));
		$this->assertSame(array(), schoolRecurringDates('2030-03-01', '2030-01-01'));
		// Days of the week outside 1..7 are ignored.
		$this->assertSame(array('2030-01-07'), schoolRecurringDates('2030-01-07', '2030-01-13', array(0, 9, 1)));
		// A cap, so a typo in the end date does not create thousands of events.
		$this->assertCount(200, schoolRecurringDates('2030-01-01', '2099-12-31', array(1, 2, 3, 4, 5, 6, 7)));
		$this->assertCount(5, schoolRecurringDates('2030-01-01', '2099-12-31', array(1, 2, 3, 4, 5, 6, 7), 1, 5));
		// A year of Mondays crossing the end of the year.
		$all = schoolRecurringDates('2030-12-30', '2031-01-13');
		$this->assertSame(array('2030-12-30', '2031-01-06', '2031-01-13'), $all);
	}

	public function testRoomPayload(): void
	{
		$this->assertSame(array('title' => 'R001', 'description' => null), schoolRoomPayload(' R001 ', '', null, null));
		$this->assertSame(array('title' => 'Lab', 'description' => 'Chemistry', 'floorNumber' => 0, 'capacity' => 24), schoolRoomPayload('Lab', ' Chemistry ', 0, 24));
		// Chamilo refuses a capacity of zero: it is left out.
		$this->assertArrayNotHasKey('capacity', schoolRoomPayload('Lab', null, 1, 0));
		$this->assertArrayNotHasKey('capacity', schoolRoomPayload('Lab', null, 1, -3));
	}

	public function testPresences(): void
	{
		$this->assertSame('present', schoolPresenceGroup(1));
		$this->assertSame('late', schoolPresenceGroup(2));
		$this->assertSame('late', schoolPresenceGroup(3));
		$this->assertSame('excused', schoolPresenceGroup(4));
		$this->assertSame('', schoolPresenceGroup(9));
		$this->assertSame('', schoolPresenceGroup(-1));

		$ok = schoolParsePresences(array('done' => true, 'sheets' => array(array('userId' => 5, 'presence' => 1), array('userId' => 6, 'presence' => 0))));
		$this->assertSame(array('done' => true, 'presences' => array(5 => 1, 6 => 0)), $ok);
		// Odd rows are dropped: unknown code, string values, missing keys, user 0, not an array.
		$odd = schoolParsePresences(array('done' => false, 'sheets' => array(array('userId' => 5, 'presence' => 7), array('userId' => '6', 'presence' => 1), array('userId' => 7), array('userId' => 0, 'presence' => 1), 'x', array('userId' => 8, 'presence' => 4))));
		$this->assertSame(array('done' => false, 'presences' => array(8 => 4)), $odd);
		// A missing 'done' means not done; an answer without 'sheets' is not an answer.
		$this->assertSame(array('done' => false, 'presences' => array()), schoolParsePresences(array('sheets' => array())));
		$this->assertNull(schoolParsePresences(array('error' => 'nope')));
		$this->assertNull(schoolParsePresences(null));
		$this->assertNull(schoolParsePresences(array('sheets' => 'x')));

		$this->assertSame(array('present' => 2, 'late' => 2, 'absent' => 1, 'excused' => 1), schoolPresenceTotals(array(1, 1, 2, 3, 0, 4, 9)));
		$this->assertSame(array('present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0), schoolPresenceTotals(array()));
	}

	public function testCompareFields(): void
	{
		$this->assertSame(array(), schoolCompareFields(array('a' => 'x', 'n' => 5, 'z' => null), array('a' => ' x ', 'n' => '5', 'extra' => 'ignored')));
		$this->assertSame(array('a' => array('expected' => 'x', 'actual' => 'y')), schoolCompareFields(array('a' => 'x', 'n' => 1), array('a' => 'y', 'n' => 1)));
		// Missing in Chamilo counts as empty; an array value is not text.
		$this->assertSame(array('a' => array('expected' => 'x', 'actual' => '')), schoolCompareFields(array('a' => 'x'), array()));
		$this->assertSame(array('a' => array('expected' => 'x', 'actual' => '')), schoolCompareFields(array('a' => 'x'), array('a' => array('x'))));
		// Case matters: a name typed differently is a difference.
		$this->assertArrayHasKey('a', schoolCompareFields(array('a' => 'Ana'), array('a' => 'ana')));
		$this->assertSame(array(), schoolCompareFields(array(), array('a' => 1)));
		// 0 and empty are not the same thing for a flag.
		$this->assertArrayHasKey('active', schoolCompareFields(array('active' => 0), array()));
	}
}
