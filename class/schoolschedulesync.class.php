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
 * \file    class/schoolschedulesync.class.php
 * \ingroup school
 * \brief   The dates of courses and sessions: what is sent to Chamilo as the dates of the attendance of a course.
 */

require_once __DIR__.'/schoolschedule.class.php';
require_once __DIR__.'/chamiloclient.class.php';

/**
 * Each course, in a session or on its own, has one attendance in Chamilo, created by the module the first time a date has
 * to be sent, and each occurrence is a date of that attendance, with its room. Dolibarr owns the schedule: a date deleted
 * in Chamilo is created again, and one that Chamilo has but Dolibarr cancelled is removed.
 *
 * A date whose attendance has already been taken in Chamilo is protected: it is neither moved nor removed there, because
 * that would lose the presences that were recorded. The room can still be changed.
 */
class SchoolScheduleSync
{
	/** Marks the attendances created by the module, so that they are found again and not duplicated. */
	public const MARKER = 'Created by the School module';

	/** @var DoliDB */
	private $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Create, move or remove the date of an occurrence in Chamilo. Safe to repeat.
	 *
	 * @param ChamiloClient $client       Client
	 * @param int           $occurrenceId School occurrence id
	 * @return array{ok:bool,error:string,retryable:bool,hash:string}
	 */
	public function push(ChamiloClient $client, int $occurrenceId): array
	{
		$P = MAIN_DB_PREFIX;
		$fail = function (string $error, bool $retryable) use ($occurrenceId, $P): array {
			$this->db->query("UPDATE ".$P."school_occurrence SET sync_status = 2, last_error = '".$this->db->escape(dol_substr($error, 0, 2000))."' WHERE rowid = ".$occurrenceId);
			return array('ok' => false, 'error' => $error, 'retryable' => $retryable, 'hash' => '');
		};

		$sql = "SELECT o.*, c.chamilo_id AS cid, s.chamilo_id AS sid, r.chamilo_room_id AS rid FROM ".$P."school_occurrence AS o"
			." INNER JOIN ".$P."school_course AS c ON c.rowid = o.fk_course LEFT JOIN ".$P."school_session AS s ON s.rowid = o.fk_session AND o.fk_session > 0"
			." LEFT JOIN ".$P."school_room AS r ON r.rowid = o.fk_room WHERE o.rowid = ".$occurrenceId;
		$resql = $this->db->query($sql);
		$o = $resql ? $this->db->fetch_object($resql) : null;
		if (!$o) {
			return array('ok' => false, 'error' => 'Occurrence '.$occurrenceId.' not found', 'retryable' => false, 'hash' => '');
		}
		$remove = (int) $o->status === SchoolSchedule::TO_REMOVE;

		// A date that Chamilo never had has nothing to remove.
		if ($remove && (int) $o->chamilo_calendar_id <= 0) {
			$this->db->query("DELETE FROM ".$P."school_presence WHERE fk_occurrence = ".$occurrenceId);
			$this->db->query("DELETE FROM ".$P."school_occurrence WHERE rowid = ".$occurrenceId);
			return $this->ok('removed');
		}
		if ((int) $o->cid <= 0) {
			return $fail('The course is not in Chamilo yet', true);
		}
		if ((int) $o->fk_session > 0 && (int) $o->sid <= 0) {
			return $fail('The session is not in Chamilo yet', true);
		}
		$ctx = array('cid' => (int) $o->cid);
		if ((int) $o->fk_session > 0) {
			$ctx['sid'] = (int) $o->sid;
		}

		return $remove ? $this->remove($client, $o, $ctx, $fail) : $this->upsert($client, $o, $ctx, $fail);
	}

	/**
	 * @param ChamiloClient       $client Client
	 * @param object              $o      Occurrence row
	 * @param array<string,int>   $ctx    Course and session context
	 * @param callable            $fail   Failure reporter
	 * @return array{ok:bool,error:string,retryable:bool,hash:string}
	 */
	private function remove(ChamiloClient $client, $o, array $ctx, callable $fail): array
	{
		$att = $this->knownAttendance((int) $o->fk_session, (int) $o->fk_course);
		if ($att <= 0) {
			return $fail('The attendance of this course is unknown, so the date cannot be checked before removing it', false);
		}
		$cals = $this->calendars($client, $att, $ctx);
		if ($cals === null) {
			return $fail('Read the dates of the attendance in Chamilo failed', true);
		}
		$found = null;
		foreach ($cals as $c) {
			if ((int) ($c['iid'] ?? 0) === (int) $o->chamilo_calendar_id) {
				$found = $c;
			}
		}
		if ($found !== null) {
			if (!empty($found['doneAttendance'])) {
				return $fail('The attendance was already taken for this date in Chamilo: it was not removed there', false);
			}
			$res = $client->request('DELETE', '/api/c_attendance_calendars/'.((int) $o->chamilo_calendar_id), $ctx);
			if (!$res['ok'] && $res['status'] !== 404) {
				return $fail('Remove the date: '.$res['error'], $res['retryable']);
			}
		}
		$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."school_presence WHERE fk_occurrence = ".((int) $o->rowid));
		$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."school_occurrence WHERE rowid = ".((int) $o->rowid));
		return $this->ok('removed');
	}

	/**
	 * @param ChamiloClient       $client Client
	 * @param object              $o      Occurrence row
	 * @param array<string,int>   $ctx    Course and session context
	 * @param callable            $fail   Failure reporter
	 * @return array{ok:bool,error:string,retryable:bool,hash:string}
	 */
	private function upsert(ChamiloClient $client, $o, array $ctx, callable $fail): array
	{
		$P = MAIN_DB_PREFIX;
		$id = (int) $o->rowid;
		if ((int) $o->fk_room > 0 && (int) $o->rid <= 0) {
			return $fail('The room is not in Chamilo yet', true);
		}
		$att = $this->ensureAttendance($client, (int) $o->fk_session, (int) $o->fk_course, $ctx);
		if (is_array($att)) {
			return $fail($att['error'], $att['retryable']);
		}

		$start = (int) $this->db->jdate($o->date_start);
		$end = (int) $this->db->jdate($o->date_end);
		$minutes = (int) round(($end - $start) / 60);
		$iso = date('c', $start);
		$room = (int) $o->rid > 0 ? '/api/rooms/'.((int) $o->rid) : null;

		$cals = $this->calendars($client, $att, $ctx);
		if ($cals === null) {
			return $fail('Read the dates of the attendance in Chamilo failed', true);
		}
		$current = null;
		foreach ($cals as $c) {
			if ((int) ($c['iid'] ?? 0) === (int) $o->chamilo_calendar_id && (int) $o->chamilo_calendar_id > 0) {
				$current = $c;
			}
		}

		if ($current === null) {
			// New, or deleted in Chamilo: the date is created (again). Chamilo ignores a second date with the same start.
			$res = $client->request('POST', '/api/attendances/'.$att.'/calendars', $ctx, array('startDate' => $iso, 'repeatDate' => false, 'duration' => $minutes, 'room' => $room));
			if (!$res['ok']) {
				return $fail('Add the date: '.$res['error'], $res['retryable']);
			}
			$cals = $this->calendars($client, $att, $ctx);
			if ($cals === null) {
				return $fail('Read the dates of the attendance in Chamilo failed', true);
			}
			$taken = array();
			$q = $this->db->query("SELECT chamilo_calendar_id FROM {$P}school_occurrence WHERE chamilo_calendar_id IS NOT NULL AND rowid <> ".$id);
			while ($q && ($x = $this->db->fetch_object($q))) {
				$taken[(int) $x->chamilo_calendar_id] = true;
			}
			$newId = 0;
			foreach ($cals as $c) {
				if (strtotime((string) ($c['dateTime'] ?? '')) === $start && empty($taken[(int) ($c['iid'] ?? 0)])) {
					$newId = (int) $c['iid'];
				}
			}
			if ($newId <= 0) {
				return $fail('Chamilo did not return the date that was added', false);
			}
			$this->db->query("UPDATE {$P}school_occurrence SET chamilo_calendar_id = ".$newId." WHERE rowid = ".$id);
		} else {
			$sameStart = strtotime((string) ($current['dateTime'] ?? '')) === $start;
			$sameLength = (int) ($current['duration'] ?? 0) === $minutes;
			$sameRoom = (string) ($current['room'] ?? '') === (string) ($room ?? '');
			if (!$sameStart || !$sameLength) {
				if (!empty($current['doneAttendance'])) {
					return $fail('The attendance was already taken for this date in Chamilo: it was not moved there', false);
				}
			}
			if (!$sameStart || !$sameLength || !$sameRoom) {
				$res = $client->request('PATCH', '/api/c_attendance_calendars/'.((int) $o->chamilo_calendar_id), $ctx, array('dateTime' => $iso, 'duration' => $minutes, 'room' => $room));
				if (!$res['ok']) {
					return $fail('Change the date: '.$res['error'], $res['retryable']);
				}
			}
		}

		$this->db->query("UPDATE {$P}school_occurrence SET sync_status = 1, last_sync = '".$this->db->idate(dol_now())."', last_error = NULL WHERE rowid = ".$id);
		return $this->ok(schoolPayloadHash(array('o' => $id, 'start' => $start, 'minutes' => $minutes, 'room' => $room)));
	}

	/**
	 * The attendance of a course in a session, created in Chamilo when it does not exist. A known attendance that Chamilo no
	 * longer has is forgotten, and the dates that pointed to it are sent again.
	 *
	 * @param ChamiloClient     $client    Client
	 * @param int               $sessionId School session id, 0 for a course on its own
	 * @param int               $courseId  School course id
	 * @param array<string,int> $ctx       Course and session context
	 * @return int|array{error:string,retryable:bool} The Chamilo attendance id, or the error
	 */
	private function ensureAttendance(ChamiloClient $client, int $sessionId, int $courseId, array $ctx)
	{
		$P = MAIN_DB_PREFIX;
		$known = $this->knownAttendance($sessionId, $courseId);
		if ($known > 0) {
			$res = $client->request('GET', '/api/attendances/'.$known, $ctx);
			if ($res['ok']) {
				return $known;
			}
			if ($res['status'] !== 404) {
				return array('error' => 'Read the attendance: '.$res['error'], 'retryable' => $res['retryable']);
			}
			$this->db->query("DELETE FROM {$P}school_attendance WHERE fk_session = {$sessionId} AND fk_course = {$courseId}");
			$this->db->query("UPDATE {$P}school_occurrence SET chamilo_calendar_id = NULL WHERE fk_session = {$sessionId} AND fk_course = {$courseId}");
		}

		$course = $client->request('GET', '/api/courses/'.$ctx['cid']);
		$node = $course['ok'] && is_array($course['data']) ? (int) ($course['data']['resourceNode']['id'] ?? 0) : 0;
		if ($node <= 0) {
			return array('error' => 'The course in Chamilo has no resource node: '.($course['error'] ?: 'unexpected answer'), 'retryable' => !$course['ok'] && $course['retryable']);
		}

		// One that the module made earlier and lost track of is reused.
		$list = $client->request('GET', '/api/attendances', $ctx + array('resourceNode.parent' => $node));
		if ($list['ok']) {
			foreach (ChamiloClient::members($list['data']) as $a) {
				if (is_array($a) && !empty($a['iid']) && strpos((string) ($a['description'] ?? ''), self::MARKER) !== false) {
					$this->saveAttendance($sessionId, $courseId, (int) $a['iid']);
					return (int) $a['iid'];
				}
			}
		}

		$body = array('title' => getDolGlobalString('SCHOOL_ATTENDANCE_TITLE', 'Attendance'), 'description' => self::MARKER, 'cid' => $ctx['cid'], 'parentResourceNodeId' => $node, 'resourceLinkList' => array(array('visibility' => 2)));
		if (isset($ctx['sid'])) {
			$body['sid'] = $ctx['sid'];
		}
		$res = $client->request('POST', '/api/attendances', $ctx, $body);
		if (!$res['ok']) {
			return array('error' => 'Create the attendance: '.$res['error'], 'retryable' => $res['retryable']);
		}
		$iid = (int) (is_array($res['data']) ? ($res['data']['iid'] ?? schoolIriId((string) ($res['data']['@id'] ?? '')) ?? 0) : 0);
		if ($iid <= 0) {
			return array('error' => 'Create the attendance: Chamilo did not return an id', 'retryable' => false);
		}
		$this->saveAttendance($sessionId, $courseId, $iid);
		return $iid;
	}

	/**
	 * @param int $sessionId School session id, 0 for none
	 * @param int $courseId  School course id
	 * @return int Chamilo attendance id, 0 when unknown
	 */
	private function knownAttendance(int $sessionId, int $courseId): int
	{
		global $conf;
		$r = $this->db->query("SELECT chamilo_attendance_id FROM ".MAIN_DB_PREFIX."school_attendance WHERE entity = ".((int) $conf->entity)." AND fk_session = ".$sessionId." AND fk_course = ".$courseId);
		$o = $r ? $this->db->fetch_object($r) : null;
		return $o ? (int) $o->chamilo_attendance_id : 0;
	}

	/**
	 * @param int $sessionId School session id, 0 for none
	 * @param int $courseId  School course id
	 * @param int $iid       Chamilo attendance id
	 */
	private function saveAttendance(int $sessionId, int $courseId, int $iid): void
	{
		global $conf;
		$this->db->query("INSERT INTO ".MAIN_DB_PREFIX."school_attendance (entity, fk_session, fk_course, chamilo_attendance_id, datec) VALUES (".((int) $conf->entity).", ".$sessionId.", ".$courseId.", ".$iid.", '".$this->db->idate(dol_now())."')");
	}

	/**
	 * The dates of an attendance in Chamilo.
	 *
	 * @param ChamiloClient     $client Client
	 * @param int               $att    Chamilo attendance id
	 * @param array<string,int> $ctx    Course and session context
	 * @return list<array<string,mixed>>|null null when Chamilo could not be read
	 */
	private function calendars(ChamiloClient $client, int $att, array $ctx): ?array
	{
		$res = $client->request('GET', '/api/attendances/'.$att, $ctx);
		if (!$res['ok'] || !is_array($res['data'])) {
			return null;
		}
		return array_values(array_filter((array) ($res['data']['calendars'] ?? array()), 'is_array'));
	}

	/**
	 * @param string $hash Hash of what was sent
	 * @return array{ok:bool,error:string,retryable:bool,hash:string}
	 */
	private function ok(string $hash): array
	{
		return array('ok' => true, 'error' => '', 'retryable' => false, 'hash' => $hash);
	}
}
