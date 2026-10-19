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
		$this->assertSame('jdoe', schoolWantedUsername('j doe@école'));
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
}
