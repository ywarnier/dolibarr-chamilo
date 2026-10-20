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
 * \file    class/schoolclass.class.php
 * \ingroup school
 * \brief   Classes of students, in Dolibarr. Nothing here talks to Chamilo: each change is queued.
 */

require_once __DIR__.'/syncqueue.class.php';

/**
 * A class is the students who follow the same sessions for a teaching cycle. It is mirrored in Chamilo as a class (a
 * user group), and Chamilo enrols the members of a class in the sessions linked to it, so Dolibarr keeps no enrolment of
 * its own for them. A student who leaves keeps the membership row with an end date, so that past invoices and
 * announcements stay traceable.
 */
class SchoolClass
{
	/** Class states. */
	public const ACTIVE = 1;
	public const ARCHIVED = 0;

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
	 * @param string      $label    Name of the class, such as "6th grade A"
	 * @param string      $level    Level or cycle, may be empty
	 * @param string|null $start    Start of the cycle (YYYY-MM-DD)
	 * @param string|null $end      End of the cycle (YYYY-MM-DD)
	 * @param int         $homeroom School teacher id of the homeroom teacher, 0 for none
	 * @return array{id:int,error:string} error is a language key, or a database message
	 */
	public function create(string $label, string $level, ?string $start, ?string $end, int $homeroom): array
	{
		global $conf, $user;
		$check = $this->check($label, $start, $end, $homeroom);
		if ($check !== '') {
			return array('id' => 0, 'error' => $check);
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."school_class (entity, ref, label, level, date_start, date_end, fk_homeroom_teacher, status, sync_status, datec, fk_user_creat) VALUES ("
			.((int) $conf->entity).", '".$this->db->escape('tmp'.uniqid())."', '".$this->db->escape(trim($label))."', ".$this->text($level).", ".$this->date($start).", ".$this->date($end).", "
			.($homeroom > 0 ? $homeroom : 'NULL').", ".self::ACTIVE.", 0, '".$this->db->idate(dol_now())."', ".((int) ($user->id ?? 0)).")";
		if (!$this->db->query($sql)) {
			return array('id' => 0, 'error' => $this->db->lasterror());
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'school_class');
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."school_class SET ref = 'CLS".str_pad((string) $id, 4, '0', STR_PAD_LEFT)."' WHERE rowid = ".$id);
		$this->queue($id);
		return array('id' => $id, 'error' => '');
	}

	/**
	 * @param int         $id       Class id
	 * @param string      $label    Name of the class
	 * @param string      $level    Level or cycle
	 * @param string|null $start    Start of the cycle
	 * @param string|null $end      End of the cycle
	 * @param int         $homeroom School teacher id, 0 for none
	 * @param bool        $active   Whether the class is active (an archived class is hidden from the lists)
	 * @return string '' when saved
	 */
	public function update(int $id, string $label, string $level, ?string $start, ?string $end, int $homeroom, bool $active): string
	{
		$check = $this->check($label, $start, $end, $homeroom);
		if ($check !== '') {
			return $check;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."school_class SET label = '".$this->db->escape(trim($label))."', level = ".$this->text($level).", date_start = ".$this->date($start).", date_end = ".$this->date($end)
			.", fk_homeroom_teacher = ".($homeroom > 0 ? $homeroom : 'NULL').", status = ".($active ? self::ACTIVE : self::ARCHIVED)." WHERE rowid = ".$id;
		if (!$this->db->query($sql)) {
			return $this->db->lasterror();
		}
		$this->queue($id);
		return '';
	}

	/**
	 * Put a student in a class from a date. A student who is a member already is refused.
	 *
	 * @param int         $classId   Class id
	 * @param int         $studentId School student id
	 * @param string|null $start     First day in the class (YYYY-MM-DD), today when null
	 * @return string '' when added
	 */
	public function addStudent(int $classId, int $studentId, ?string $start): string
	{
		$today = dol_print_date(dol_now(), '%Y-%m-%d');
		$start = $start !== null && $this->isDate($start) ? $start : $today;
		$st = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."school_student WHERE rowid = ".$studentId);
		if (!$st || !$this->db->fetch_object($st)) {
			return 'SchoolErrNotFound';
		}
		if (in_array($studentId, $this->currentMemberIds($classId), true)) {
			return 'SchoolErrAlreadyMember';
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."school_class_student (fk_class, fk_student, date_start) VALUES (".$classId.", ".$studentId.", '".$this->db->escape($start)."')";
		if (!$this->db->query($sql)) {
			return $this->db->lasterror();
		}
		$this->queue($classId);
		return '';
	}

	/**
	 * Take a student out of the class now. The row stays, ended the day before, so that the history is kept and the
	 * student is no longer a member today.
	 *
	 * @param int $memberId Membership row id
	 * @return string '' when done
	 */
	public function removeStudent(int $memberId): string
	{
		$resql = $this->db->query("SELECT fk_class FROM ".MAIN_DB_PREFIX."school_class_student WHERE rowid = ".$memberId);
		$row = $resql ? $this->db->fetch_object($resql) : null;
		if (!$row) {
			return 'SchoolErrNotFound';
		}
		$yesterday = dol_print_date(dol_now() - 86400, '%Y-%m-%d');
		// A membership that starts today or later is simply cancelled: it never counted.
		$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."school_class_student WHERE rowid = ".$memberId." AND date_start > '".$this->db->escape($yesterday)."'");
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."school_class_student SET date_end = '".$this->db->escape($yesterday)."' WHERE rowid = ".$memberId." AND (date_end IS NULL OR date_end > '".$this->db->escape($yesterday)."')");
		$this->queue((int) $row->fk_class);
		return '';
	}

	/**
	 * @param int $classId   Class id
	 * @param int $sessionId School session id
	 * @return string '' when added
	 */
	public function addSession(int $classId, int $sessionId): string
	{
		$s = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."school_session WHERE rowid = ".$sessionId);
		if (!$s || !$this->db->fetch_object($s)) {
			return 'SchoolErrNotFound';
		}
		$exists = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."school_class_session WHERE fk_class = ".$classId." AND fk_session = ".$sessionId);
		if ($exists && $this->db->fetch_object($exists)) {
			return 'SchoolErrSessionAlreadyInClass';
		}
		if (!$this->db->query("INSERT INTO ".MAIN_DB_PREFIX."school_class_session (fk_class, fk_session) VALUES (".$classId.", ".$sessionId.")")) {
			return $this->db->lasterror();
		}
		$this->queue($classId);
		return '';
	}

	/**
	 * @param int $classId   Class id
	 * @param int $sessionId School session id
	 * @return string '' when removed
	 */
	public function removeSession(int $classId, int $sessionId): string
	{
		$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."school_class_session WHERE fk_class = ".$classId." AND fk_session = ".$sessionId);
		$this->queue($classId);
		return '';
	}

	/**
	 * Queue an update of the Chamilo class of every class the student is a current member of.
	 *
	 * @param int $studentId School student id
	 */
	public function queueUpdatesForStudent(int $studentId): void
	{
		$resql = $this->db->query("SELECT DISTINCT fk_class FROM ".MAIN_DB_PREFIX."school_class_student WHERE fk_student = ".$studentId);
		while ($resql && ($o = $this->db->fetch_object($resql))) {
			$this->queue((int) $o->fk_class);
		}
	}

	/**
	 * @param int $classId Class id
	 * @return list<int> School student ids of the members of the class today
	 */
	public function currentMemberIds(int $classId): array
	{
		$today = dol_print_date(dol_now(), '%Y-%m-%d');
		$ids = array();
		$resql = $this->db->query("SELECT fk_student, date_start, date_end FROM ".MAIN_DB_PREFIX."school_class_student WHERE fk_class = ".$classId);
		while ($resql && ($o = $this->db->fetch_object($resql))) {
			if (schoolIsCurrentMember($o->date_start ? (string) $o->date_start : null, $o->date_end ? (string) $o->date_end : null, $today)) {
				$ids[] = (int) $o->fk_student;
			}
		}
		return array_values(array_unique($ids));
	}

	/**
	 * Parent links of the current members of a class, for an announcement: one row per link, with the contact's and the
	 * third party's addresses. Reduce them with schoolDistinctRecipients().
	 *
	 * @param int $classId Class id
	 * @return array<int,array{contact_email:?string,soc_email:?string,name:string,id:int,contact_id:int,contact_firstname:?string,contact_lastname:?string}>
	 */
	public function parentLinksOfMembers(int $classId): array
	{
		$ids = $this->currentMemberIds($classId);
		if (!$ids) {
			return array();
		}
		$P = MAIN_DB_PREFIX;
		$sql = "SELECT sp.fk_soc AS id, s.nom AS name, s.email AS soc_email, c.rowid AS contact_id, c.email AS contact_email, c.firstname AS contact_firstname, c.lastname AS contact_lastname"
			." FROM ".$P."school_student_parent AS sp INNER JOIN ".$P."societe AS s ON s.rowid = sp.fk_soc LEFT JOIN ".$P."socpeople AS c ON c.rowid = sp.fk_socpeople"
			." WHERE sp.fk_student IN (".implode(',', array_map('intval', $ids)).") AND sp.chamilo_follow <> 2 AND s.status = 1 ORDER BY sp.is_payer DESC, s.nom";
		$out = array();
		$resql = $this->db->query($sql);
		while ($resql && ($o = $this->db->fetch_object($resql))) {
			$out[] = array('id' => (int) $o->id, 'name' => (string) $o->name, 'soc_email' => $o->soc_email, 'contact_id' => (int) $o->contact_id, 'contact_email' => $o->contact_email, 'contact_firstname' => $o->contact_firstname, 'contact_lastname' => $o->contact_lastname);
		}
		return $out;
	}

	/**
	 * @param int $classId Class id
	 */
	private function queue(int $classId): void
	{
		(new SyncQueue($this->db))->enqueue('class', $classId, 'update');
	}

	/**
	 * @param string      $label    Name
	 * @param string|null $start    Start date
	 * @param string|null $end      End date
	 * @param int         $homeroom School teacher id
	 * @return string '' when valid, else a language key
	 */
	private function check(string $label, ?string $start, ?string $end, int $homeroom): string
	{
		if (trim($label) === '') {
			return 'SchoolErrClassLabel';
		}
		if (($start !== null && !$this->isDate($start)) || ($end !== null && !$this->isDate($end))) {
			return 'SchoolErrClassDates';
		}
		if ($start !== null && $end !== null && $end < $start) {
			return 'SchoolErrClassDateOrder';
		}
		if ($homeroom > 0) {
			$t = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."school_teacher WHERE rowid = ".$homeroom);
			if (!$t || !$this->db->fetch_object($t)) {
				return 'SchoolErrNotFound';
			}
		}
		return '';
	}

	/**
	 * @param string $value Value
	 * @return bool True for a real YYYY-MM-DD date
	 */
	private function isDate(string $value): bool
	{
		return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
	}

	/**
	 * @param string|null $value Date
	 * @return string SQL literal
	 */
	private function date(?string $value): string
	{
		return $value === null || $value === '' ? 'NULL' : "'".$this->db->escape($value)."'";
	}

	/**
	 * @param string $value Text
	 * @return string SQL literal
	 */
	private function text(string $value): string
	{
		return trim($value) === '' ? 'NULL' : "'".$this->db->escape(trim($value))."'";
	}
}
