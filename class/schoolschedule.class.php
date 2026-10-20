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
 * \file    class/schoolschedule.class.php
 * \ingroup school
 * \brief   The dates of courses and sessions, as events of the Dolibarr agenda with the room booked as a resource.
 */

require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once __DIR__.'/syncqueue.class.php';
require_once __DIR__.'/../lib/schoollogic.lib.php';

/**
 * An occurrence is one date of a course, in a session or on its own. It is an event of the Dolibarr agenda, so it shows
 * in the agenda of the teachers, and the room is booked on it as a resource of the Resources module, so it shows in the
 * planning of the room. Nothing here talks to Chamilo: each change is queued.
 *
 * Whether a room is free is decided here, with a strict overlap: a class that ends when the next one starts is not a
 * conflict, which the check of the Resources module (inclusive at both ends) would report as one.
 */
class SchoolSchedule
{
	/** Occurrence states. */
	public const ACTIVE = 0;
	public const TO_REMOVE = 3;

	/** Shortest and longest occurrence, in minutes. */
	public const MIN_MINUTES = 5;
	public const MAX_MINUTES = 720;

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
	 * The events of the agenda that have a room booked over a time range.
	 *
	 * @param int $roomId          School room id
	 * @param int $start           Start (timestamp)
	 * @param int $end             End (timestamp)
	 * @param int $excludeActionId Agenda event to leave out (the one being moved)
	 * @return list<array{id:int,label:string,start:int,end:int}>
	 */
	public function roomConflicts(int $roomId, int $start, int $end, int $excludeActionId = 0): array
	{
		$r = $this->db->query("SELECT fk_resource FROM ".MAIN_DB_PREFIX."school_room WHERE rowid = ".$roomId);
		$room = $r ? $this->db->fetch_object($r) : null;
		if (!$room) {
			return array();
		}
		$sql = "SELECT ac.id, ac.label, ac.datep, ac.datep2 FROM ".MAIN_DB_PREFIX."element_resources AS er"
			." INNER JOIN ".MAIN_DB_PREFIX."actioncomm AS ac ON ac.id = er.element_id AND er.element_type = 'action'"
			." WHERE er.resource_id = ".((int) $room->fk_resource)." AND er.resource_type = 'dolresource' AND er.busy = 1"
			.($excludeActionId > 0 ? " AND ac.id <> ".$excludeActionId : '')
			." AND ac.datep < '".$this->db->idate($end)."' AND (ac.datep2 IS NULL OR ac.datep2 > '".$this->db->idate($start)."')";
		$out = array();
		$resql = $this->db->query($sql);
		while ($resql && ($o = $this->db->fetch_object($resql))) {
			$a = (int) $this->db->jdate($o->datep);
			// An event with no end is a moment: it conflicts when that moment is inside the range.
			$b = $o->datep2 ? (int) $this->db->jdate($o->datep2) : $a;
			if ($a === $b ? ($a >= $start && $a < $end) : schoolIntervalsOverlap($a, $b, $start, $end)) {
				$out[] = array('id' => (int) $o->id, 'label' => (string) $o->label, 'start' => $a, 'end' => $b);
			}
		}
		return $out;
	}

	/**
	 * The occurrences, other than the one being moved, during which these teachers are already busy. This only warns, and
	 * it only looks at the occurrences of the school.
	 *
	 * @param list<int> $userIds   Dolibarr user ids of the teachers
	 * @param int       $start     Start (timestamp)
	 * @param int       $end       End (timestamp)
	 * @param int       $excludeId Occurrence to leave out
	 * @return list<array{occurrence:int,user:int,start:int,end:int}>
	 */
	public function teacherConflicts(array $userIds, int $start, int $end, int $excludeId = 0): array
	{
		global $conf;
		if (!$userIds) {
			return array();
		}
		$sql = "SELECT o.rowid, o.date_start, o.date_end, ar.fk_element AS uid FROM ".MAIN_DB_PREFIX."school_occurrence AS o"
			." INNER JOIN ".MAIN_DB_PREFIX."actioncomm_resources AS ar ON ar.fk_actioncomm = o.fk_actioncomm AND ar.element_type = 'user'"
			." WHERE o.entity = ".((int) $conf->entity)." AND o.status = ".self::ACTIVE." AND ar.fk_element IN (".implode(',', array_map('intval', $userIds)).")"
			.($excludeId > 0 ? " AND o.rowid <> ".$excludeId : '')
			." AND o.date_start < '".$this->db->idate($end)."' AND o.date_end > '".$this->db->idate($start)."'";
		$out = array();
		$resql = $this->db->query($sql);
		while ($resql && ($o = $this->db->fetch_object($resql))) {
			$out[] = array('occurrence' => (int) $o->rowid, 'user' => (int) $o->uid, 'start' => (int) $this->db->jdate($o->date_start), 'end' => (int) $this->db->jdate($o->date_end));
		}
		return $out;
	}

	/**
	 * Dolibarr users who teach a course, or coach it in a session.
	 *
	 * @param int $sessionId School session id, 0 for a course on its own
	 * @param int $courseId  School course id
	 * @return list<int>
	 */
	public function teacherUserIds(int $sessionId, int $courseId): array
	{
		$P = MAIN_DB_PREFIX;
		$sql = $sessionId > 0
			? "SELECT t.fk_user FROM {$P}school_session_teacher AS x INNER JOIN {$P}school_teacher AS t ON t.rowid = x.fk_teacher WHERE x.fk_session = {$sessionId} AND x.fk_course = {$courseId} AND x.sync_status <> 3"
			: "SELECT t.fk_user FROM {$P}school_course_teacher AS x INNER JOIN {$P}school_teacher AS t ON t.rowid = x.fk_teacher WHERE x.fk_course = {$courseId} AND x.sync_status <> 3";
		$out = array();
		$resql = $this->db->query($sql);
		while ($resql && ($o = $this->db->fetch_object($resql))) {
			$out[] = (int) $o->fk_user;
		}
		return $out;
	}

	/**
	 * Whether the course already has an occurrence, in this session, starting at this very minute. Chamilo identifies a date
	 * of an attendance by its start, so two such occurrences would be one there.
	 *
	 * @param int $sessionId School session id, 0 for a course on its own
	 * @param int $courseId  School course id
	 * @param int $start     Start (timestamp)
	 * @param int $excludeId Occurrence to leave out
	 * @return bool
	 */
	public function hasSameStart(int $sessionId, int $courseId, int $start, int $excludeId = 0): bool
	{
		$minute = $start - ($start % 60);
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."school_occurrence WHERE fk_session = ".$sessionId." AND fk_course = ".$courseId." AND status = ".self::ACTIVE
			." AND date_start >= '".$this->db->idate($minute)."' AND date_start < '".$this->db->idate($minute + 60)."'".($excludeId > 0 ? " AND rowid <> ".$excludeId : '');
		$resql = $this->db->query($sql);
		return $resql && $this->db->fetch_object($resql);
	}

	/**
	 * Create the dates of a course. All the dates are checked first: when a room is not free on one of them and the
	 * conflicting dates are not to be skipped, nothing is created.
	 *
	 * @param int          $sessionId     School session id, 0 for a course on its own
	 * @param int          $courseId      School course id
	 * @param list<string> $dates         Days (YYYY-MM-DD)
	 * @param string       $time          Start time (HH:MM)
	 * @param int          $minutes       Duration
	 * @param int          $roomId        School room id, 0 for none
	 * @param bool         $skipConflicts Create the dates where the room is free and skip the others
	 * @return array{created:list<int>,skipped:list<array{date:string,conflicts:list<array<string,mixed>>}>,warnings:list<string>,error:string}
	 */
	public function createSeries(int $sessionId, int $courseId, array $dates, string $time, int $minutes, int $roomId, bool $skipConflicts): array
	{
		$result = array('created' => array(), 'skipped' => array(), 'warnings' => array(), 'error' => '');
		$check = $this->checkInput($sessionId, $courseId, $time, $minutes, $roomId);
		if ($check !== '') {
			$result['error'] = $check;
			return $result;
		}
		if (!$dates) {
			$result['error'] = 'SchoolErrNoDate';
			return $result;
		}

		$teachers = $this->teacherUserIds($sessionId, $courseId);
		$plan = array();
		foreach ($dates as $date) {
			[$start, $end] = $this->range($date, $time, $minutes);
			if ($this->hasSameStart($sessionId, $courseId, $start)) {
				$result['error'] = 'SchoolErrDuplicateDate';
				$result['skipped'][] = array('date' => $date, 'conflicts' => array());
				return $result;
			}
			$conflicts = $roomId > 0 ? $this->roomConflicts($roomId, $start, $end) : array();
			if ($conflicts) {
				$result['skipped'][] = array('date' => $date, 'conflicts' => $conflicts);
				continue;
			}
			$plan[] = array($date, $start, $end);
		}
		if ($result['skipped'] && !$skipConflicts) {
			$result['error'] = 'SchoolErrRoomBusy';
			return $result;
		}

		foreach ($plan as [$date, $start, $end]) {
			foreach ($this->teacherConflicts($teachers, $start, $end) as $c) {
				$result['warnings'][] = $date;
				break;
			}
			$id = $this->createOne($sessionId, $courseId, $start, $end, $roomId, $teachers);
			if ($id <= 0) {
				$result['error'] = $this->db->lasterror() ?: 'Error';
				return $result;
			}
			$result['created'][] = $id;
		}
		return $result;
	}

	/**
	 * Move an occurrence, or change its length or its room.
	 *
	 * @param int    $id      Occurrence id
	 * @param string $date    Day (YYYY-MM-DD)
	 * @param string $time    Start time (HH:MM)
	 * @param int    $minutes Duration
	 * @param int    $roomId  School room id, 0 for none
	 * @return array{error:string,conflicts:list<array<string,mixed>>}
	 */
	public function update(int $id, string $date, string $time, int $minutes, int $roomId): array
	{
		$occ = $this->occurrence($id);
		if (!$occ || (int) $occ->status === self::TO_REMOVE) {
			return array('error' => 'SchoolErrNotFound', 'conflicts' => array());
		}
		$check = $this->checkInput((int) $occ->fk_session, (int) $occ->fk_course, $time, $minutes, $roomId);
		if ($check !== '') {
			return array('error' => $check, 'conflicts' => array());
		}
		[$start, $end] = $this->range($date, $time, $minutes);
		if ($this->hasSameStart((int) $occ->fk_session, (int) $occ->fk_course, $start, $id)) {
			return array('error' => 'SchoolErrDuplicateDate', 'conflicts' => array());
		}
		$conflicts = $roomId > 0 ? $this->roomConflicts($roomId, $start, $end, (int) $occ->fk_actioncomm) : array();
		if ($conflicts) {
			return array('error' => 'SchoolErrRoomBusy', 'conflicts' => $conflicts);
		}

		$this->db->begin();
		$ac = new ActionComm($this->db);
		if ($occ->fk_actioncomm && $ac->fetch((int) $occ->fk_actioncomm) > 0) {
			$ac->datep = $start;
			$ac->datef = $end;
			if ($ac->update($this->actingUser()) < 0) {
				$this->db->rollback();
				return array('error' => $ac->error ?: 'Error', 'conflicts' => array());
			}
			$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."element_resources WHERE element_type = 'action' AND element_id = ".((int) $ac->id));
			if ($roomId > 0 && !$this->bookRoom($ac, $roomId)) {
				$this->db->rollback();
				return array('error' => $this->db->lasterror() ?: 'Error', 'conflicts' => array());
			}
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."school_occurrence SET date_start = '".$this->db->idate($start)."', date_end = '".$this->db->idate($end)."', fk_room = ".($roomId > 0 ? $roomId : 'NULL')." WHERE rowid = ".$id;
		if (!$this->db->query($sql)) {
			$this->db->rollback();
			return array('error' => $this->db->lasterror(), 'conflicts' => array());
		}
		$this->db->commit();
		(new SyncQueue($this->db))->enqueue('occurrence', $id, 'update');
		return array('error' => '', 'conflicts' => array());
	}

	/**
	 * Cancel an occurrence. The agenda event goes and the room is free at once; the row stays, marked, until Chamilo has
	 * removed the date, and goes at once when Chamilo never had it.
	 *
	 * @param int $id Occurrence id
	 * @return string '' when done
	 */
	public function delete(int $id): string
	{
		$occ = $this->occurrence($id);
		if (!$occ) {
			return 'SchoolErrNotFound';
		}
		if ($occ->fk_actioncomm) {
			$ac = new ActionComm($this->db);
			if ($ac->fetch((int) $occ->fk_actioncomm) > 0) {
				$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."element_resources WHERE element_type = 'action' AND element_id = ".((int) $ac->id));
				$ac->delete($this->actingUser());
			}
		}
		if ((int) $occ->chamilo_calendar_id > 0) {
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."school_occurrence SET status = ".self::TO_REMOVE.", fk_actioncomm = NULL WHERE rowid = ".$id);
			(new SyncQueue($this->db))->enqueue('occurrence', $id, 'delete');
		} else {
			$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."school_presence WHERE fk_occurrence = ".$id);
			$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."school_occurrence WHERE rowid = ".$id);
		}
		return '';
	}

	/**
	 * @param int      $sessionId School session id
	 * @param int      $courseId  School course id
	 * @param int      $start     Start (timestamp)
	 * @param int      $end       End (timestamp)
	 * @param int      $roomId    School room id, 0 for none
	 * @param list<int> $teachers Dolibarr user ids to assign to the event
	 * @return int Occurrence id, <=0 on error
	 */
	private function createOne(int $sessionId, int $courseId, int $start, int $end, int $roomId, array $teachers): int
	{
		global $conf;
		$user = $this->actingUser();
		$this->db->begin();
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."school_occurrence (entity, fk_session, fk_course, fk_room, date_start, date_end, status, sync_status, datec, fk_user_creat) VALUES ("
			.((int) $conf->entity).", ".$sessionId.", ".$courseId.", ".($roomId > 0 ? $roomId : 'NULL').", '".$this->db->idate($start)."', '".$this->db->idate($end)."', ".self::ACTIVE.", 0, '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->db->rollback();
			return -1;
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'school_occurrence');

		$ac = new ActionComm($this->db);
		$ac->type_code = 'AC_OTH';
		$ac->label = $this->label($sessionId, $courseId);
		$ac->datep = $start;
		$ac->datef = $end;
		$ac->fulldayevent = 0;
		$ac->percentage = -1;
		$ac->userownerid = (int) $user->id;
		$ac->userassigned = array();
		foreach ($teachers as $uid) {
			$ac->userassigned[$uid] = array('id' => $uid, 'transparency' => 1);
		}
		$ac->elementtype = 'school_occurrence@school';
		$ac->fk_element = $id;
		$acId = $ac->create($user);
		if ($acId <= 0) {
			$this->db->rollback();
			return -1;
		}
		if ($roomId > 0 && !$this->bookRoom($ac, $roomId)) {
			$this->db->rollback();
			return -1;
		}
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."school_occurrence SET fk_actioncomm = ".((int) $acId)." WHERE rowid = ".$id);
		$this->db->commit();
		(new SyncQueue($this->db))->enqueue('occurrence', $id, 'update');
		return $id;
	}

	/**
	 * Book the room on the event: a busy resource.
	 *
	 * @param ActionComm $ac     Event
	 * @param int        $roomId School room id
	 * @return bool
	 */
	private function bookRoom(ActionComm $ac, int $roomId): bool
	{
		$r = $this->db->query("SELECT fk_resource FROM ".MAIN_DB_PREFIX."school_room WHERE rowid = ".$roomId);
		$room = $r ? $this->db->fetch_object($r) : null;
		return $room && $ac->add_element_resource((int) $room->fk_resource, 'dolresource', 1, 0) > 0;
	}

	/**
	 * @param int    $sessionId School session id
	 * @param int    $courseId  School course id
	 * @param string $time      Start time
	 * @param int    $minutes   Duration
	 * @param int    $roomId    School room id
	 * @return string '' when valid, else a language key
	 */
	private function checkInput(int $sessionId, int $courseId, string $time, int $minutes, int $roomId): string
	{
		$P = MAIN_DB_PREFIX;
		$c = $this->db->query("SELECT rowid FROM {$P}school_course WHERE rowid = ".$courseId);
		if (!$c || !$this->db->fetch_object($c)) {
			return 'SchoolErrNotFound';
		}
		if ($sessionId > 0) {
			$s = $this->db->query("SELECT 1 FROM {$P}school_session_course WHERE fk_session = {$sessionId} AND fk_course = {$courseId}");
			if (!$s || !$this->db->fetch_object($s)) {
				return 'SchoolErrCourseNotInSession';
			}
		}
		if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
			return 'SchoolErrTime';
		}
		if ($minutes < self::MIN_MINUTES || $minutes > self::MAX_MINUTES) {
			return 'SchoolErrDuration';
		}
		if ($roomId > 0) {
			$r = $this->db->query("SELECT rowid FROM {$P}school_room WHERE rowid = ".$roomId);
			if (!$r || !$this->db->fetch_object($r)) {
				return 'SchoolErrNotFound';
			}
		}
		return '';
	}

	/**
	 * @param string $date    Day (YYYY-MM-DD)
	 * @param string $time    Start time (HH:MM)
	 * @param int    $minutes Duration
	 * @return array{0:int,1:int} Start and end (timestamps)
	 */
	private function range(string $date, string $time, int $minutes): array
	{
		$start = dol_mktime((int) substr($time, 0, 2), (int) substr($time, 3, 2), 0, (int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
		return array((int) $start, (int) $start + $minutes * 60);
	}

	/**
	 * @param int $sessionId School session id
	 * @param int $courseId  School course id
	 * @return string Title of the agenda event: the session and the course
	 */
	private function label(int $sessionId, int $courseId): string
	{
		$P = MAIN_DB_PREFIX;
		$c = $this->db->query("SELECT p.label FROM {$P}school_course AS c INNER JOIN {$P}product AS p ON p.rowid = c.fk_product WHERE c.rowid = ".$courseId);
		$course = $c ? $this->db->fetch_object($c) : null;
		$label = $course ? (string) $course->label : 'Course';
		if ($sessionId > 0) {
			$s = $this->db->query("SELECT p.label FROM {$P}school_session AS s INNER JOIN {$P}product AS p ON p.rowid = s.fk_product WHERE s.rowid = ".$sessionId);
			$session = $s ? $this->db->fetch_object($s) : null;
			if ($session) {
				$label = $session->label.' - '.$label;
			}
		}
		return dol_substr($label, 0, 128);
	}

	/**
	 * @param int $id Occurrence id
	 * @return object|null
	 */
	private function occurrence(int $id)
	{
		$resql = $this->db->query("SELECT * FROM ".MAIN_DB_PREFIX."school_occurrence WHERE rowid = ".$id);
		return $resql ? ($this->db->fetch_object($resql) ?: null) : null;
	}

	/**
	 * @return User The user doing the action
	 */
	private function actingUser()
	{
		global $user;
		return $user;
	}
}
