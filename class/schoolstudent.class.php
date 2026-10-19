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
 * \file    class/schoolstudent.class.php
 * \ingroup school
 * \brief   Students, their parents and their enrolments in Dolibarr. Nothing here talks to Chamilo: each change is
 *          queued, and SchoolStudentSync sends it.
 */

require_once __DIR__.'/syncqueue.class.php';

/**
 * A student is a row of its own, not a third party: the parents are the customers. A student has one or more parents,
 * a parent has several children, and a parent can be chosen to follow the child in Chamilo.
 */
class SchoolStudent
{
	/** Enrolment states: to enrol in Chamilo, enrolled, to remove from Chamilo. */
	public const TO_ENROL = 0;
	public const ENROLLED = 1;
	public const TO_REMOVE = 3;

	/** Parent follow states: not following, following, stopped following (row deleted once Chamilo knows). */
	public const NOT_FOLLOWING = 0;
	public const FOLLOWING = 1;
	public const UNFOLLOW = 2;

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
	 * @param string   $firstname First name
	 * @param string   $lastname  Last name
	 * @param int|null $birthdate Birth date as a timestamp
	 * @param string   $email     Student's own email, may be empty
	 * @return array{id:int,error:string} error is a language key, or a database message
	 */
	public function create(string $firstname, string $lastname, ?int $birthdate, string $email): array
	{
		global $conf, $user;
		$firstname = trim($firstname);
		$lastname = trim($lastname);
		$email = trim($email);
		if ($firstname === '' || $lastname === '') {
			return array('id' => 0, 'error' => 'SchoolErrStudentName');
		}
		if ($email !== '' && !isValidEmail($email)) {
			return array('id' => 0, 'error' => 'ErrorBadEMail');
		}

		$now = $this->db->idate(dol_now());
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."school_student (entity, ref, firstname, lastname, birthdate, email, status, sync_status, datec, fk_user_creat) VALUES ("
			.((int) $conf->entity).", '".$this->db->escape('tmp'.uniqid())."', '".$this->db->escape($firstname)."', '".$this->db->escape($lastname)."', "
			.($birthdate === null ? 'NULL' : "'".$this->db->idate($birthdate)."'").", ".($email === '' ? 'NULL' : "'".$this->db->escape($email)."'").", 1, 0, '".$now."', ".((int) ($user->id ?? 0)).")";
		if (!$this->db->query($sql)) {
			return array('id' => 0, 'error' => $this->db->lasterror());
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'school_student');
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."school_student SET ref = 'STU".str_pad((string) $id, 5, '0', STR_PAD_LEFT)."' WHERE rowid = ".$id);
		(new SyncQueue($this->db))->enqueue('student', $id, 'create');
		return array('id' => $id, 'error' => '');
	}

	/**
	 * @param int      $id        Student id
	 * @param string   $firstname First name
	 * @param string   $lastname  Last name
	 * @param int|null $birthdate Birth date as a timestamp
	 * @param string   $email     Student's own email, may be empty
	 * @param bool     $active    Whether the student is active (an inactive student has a deactivated Chamilo account)
	 * @return string '' when saved, else a language key or a database message
	 */
	public function update(int $id, string $firstname, string $lastname, ?int $birthdate, string $email, bool $active): string
	{
		$firstname = trim($firstname);
		$lastname = trim($lastname);
		$email = trim($email);
		if ($firstname === '' || $lastname === '') {
			return 'SchoolErrStudentName';
		}
		if ($email !== '' && !isValidEmail($email)) {
			return 'ErrorBadEMail';
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."school_student SET firstname = '".$this->db->escape($firstname)."', lastname = '".$this->db->escape($lastname)."', birthdate = "
			.($birthdate === null ? 'NULL' : "'".$this->db->idate($birthdate)."'").", email = ".($email === '' ? 'NULL' : "'".$this->db->escape($email)."'").", status = ".($active ? 1 : 0)
			." WHERE rowid = ".$id;
		if (!$this->db->query($sql)) {
			return $this->db->lasterror();
		}
		(new SyncQueue($this->db))->enqueue('student', $id, 'update');
		return '';
	}

	/**
	 * Link a parent (a third party, and optionally one of its contacts) to a student.
	 *
	 * @param int    $studentId Student id
	 * @param int    $socid     Third party id
	 * @param int    $contactId Contact of that third party, 0 for none
	 * @param string $relation  parent, guardian or other
	 * @param bool   $isPayer   Whether this parent pays
	 * @param float  $share     Share of the cost this parent pays, 0 to 100
	 * @param bool   $follow    Whether the parent follows the child in Chamilo
	 * @return string '' when saved, else a language key or a database message
	 */
	public function addParent(int $studentId, int $socid, int $contactId, string $relation, bool $isPayer, float $share, bool $follow): string
	{
		$check = $this->checkParent($socid, $contactId, $share, $follow);
		if ($check !== '') {
			return $check;
		}
		$exists = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."school_student_parent WHERE fk_student = ".$studentId." AND fk_soc = ".$socid);
		if ($exists && $this->db->fetch_object($exists)) {
			return 'SchoolErrParentAlreadyLinked';
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."school_student_parent (fk_student, fk_soc, fk_socpeople, relation, is_payer, payer_share, chamilo_follow, datec) VALUES ("
			.$studentId.", ".$socid.", ".($contactId > 0 ? $contactId : 'NULL').", '".$this->db->escape($this->relation($relation))."', ".($isPayer ? 1 : 0).", ".$share.", ".($follow ? self::FOLLOWING : self::NOT_FOLLOWING).", '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			return $this->db->lasterror();
		}
		if ($follow) {
			(new SyncQueue($this->db))->enqueue('student', $studentId, 'update');
		}
		return '';
	}

	/**
	 * @param int    $linkId    Parent link id
	 * @param int    $contactId Contact of the third party, 0 for none
	 * @param string $relation  parent, guardian or other
	 * @param bool   $isPayer   Whether this parent pays
	 * @param float  $share     Share of the cost, 0 to 100
	 * @param bool   $follow    Whether the parent follows the child in Chamilo
	 * @return string '' when saved, else a language key or a database message
	 */
	public function updateParent(int $linkId, int $contactId, string $relation, bool $isPayer, float $share, bool $follow): string
	{
		$link = $this->link($linkId);
		if (!$link) {
			return 'SchoolErrNotFound';
		}
		$check = $this->checkParent((int) $link->fk_soc, $contactId, $share, $follow);
		if ($check !== '') {
			return $check;
		}
		$wasFollowing = (int) $link->chamilo_follow === self::FOLLOWING;
		$sql = "UPDATE ".MAIN_DB_PREFIX."school_student_parent SET fk_socpeople = ".($contactId > 0 ? $contactId : 'NULL').", relation = '".$this->db->escape($this->relation($relation))."', is_payer = ".($isPayer ? 1 : 0)
			.", payer_share = ".$share.", chamilo_follow = ".($follow ? self::FOLLOWING : self::NOT_FOLLOWING)." WHERE rowid = ".$linkId;
		if (!$this->db->query($sql)) {
			return $this->db->lasterror();
		}
		if ($follow || $wasFollowing) {
			(new SyncQueue($this->db))->enqueue('student', (int) $link->fk_student, 'update');
		}
		return '';
	}

	/**
	 * Remove a parent from a student. When the parent may follow the child in Chamilo, the row stays, marked, until
	 * Chamilo has been told to stop the following.
	 *
	 * @param int $linkId Parent link id
	 * @return string '' when done
	 */
	public function removeParent(int $linkId): string
	{
		$link = $this->link($linkId);
		if (!$link) {
			return 'SchoolErrNotFound';
		}
		$mayFollow = (int) $link->chamilo_follow === self::FOLLOWING;
		if (!$mayFollow && (int) $link->fk_socpeople > 0) {
			$g = $this->db->query("SELECT chamilo_user_id FROM ".MAIN_DB_PREFIX."school_guardian WHERE fk_socpeople = ".((int) $link->fk_socpeople)." AND chamilo_user_id IS NOT NULL");
			$mayFollow = (bool) ($g && $this->db->fetch_object($g));
		}
		if ($mayFollow) {
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."school_student_parent SET chamilo_follow = ".self::UNFOLLOW." WHERE rowid = ".$linkId);
			(new SyncQueue($this->db))->enqueue('student', (int) $link->fk_student, 'update');
		} else {
			$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."school_student_parent WHERE rowid = ".$linkId);
		}
		return '';
	}

	/**
	 * Enrol a student in a session or in a course. Exactly one of the two is given.
	 *
	 * @param int $studentId Student id
	 * @param int $sessionId School session id, 0 for none
	 * @param int $courseId  School course id, 0 for none
	 * @return string '' when queued, else a language key or a database message
	 */
	public function enrol(int $studentId, int $sessionId, int $courseId): string
	{
		global $conf, $user;
		if (($sessionId > 0) === ($courseId > 0)) {
			return 'SchoolErrEnrolTarget';
		}
		$where = $sessionId > 0 ? "fk_session = ".$sessionId : "fk_course = ".$courseId;
		$target = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX.($sessionId > 0 ? "school_session WHERE rowid = ".$sessionId : "school_course WHERE rowid = ".$courseId)." AND chamilo_id IS NOT NULL");
		if (!$target || !$this->db->fetch_object($target)) {
			return 'SchoolErrEnrolNotInChamilo';
		}
		$existing = $this->db->query("SELECT rowid, status FROM ".MAIN_DB_PREFIX."school_enrolment WHERE fk_student = ".$studentId." AND ".$where);
		$row = $existing ? $this->db->fetch_object($existing) : null;
		if ($row) {
			if ((int) $row->status !== self::TO_REMOVE) {
				return 'SchoolErrAlreadyEnrolled';
			}
			// Enrolled again before the removal was sent: send it as an enrolment, which checks Chamilo first.
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."school_enrolment SET status = ".self::TO_ENROL.", sync_status = 0, last_error = NULL WHERE rowid = ".((int) $row->rowid));
			$id = (int) $row->rowid;
		} else {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."school_enrolment (entity, fk_student, fk_session, fk_course, status, sync_status, datec, fk_user_creat) VALUES ("
				.((int) $conf->entity).", ".$studentId.", ".($sessionId > 0 ? $sessionId : 'NULL').", ".($courseId > 0 ? $courseId : 'NULL').", ".self::TO_ENROL.", 0, '".$this->db->idate(dol_now())."', ".((int) ($user->id ?? 0)).")";
			if (!$this->db->query($sql)) {
				return $this->db->lasterror();
			}
			$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'school_enrolment');
		}
		(new SyncQueue($this->db))->enqueue('enrolment', $id, 'create');
		return '';
	}

	/**
	 * Un-enrol a student. The row goes through the push even if it was never confirmed: the push only removes what
	 * Chamilo really has, then deletes the row.
	 *
	 * @param int $enrolmentId Enrolment id
	 * @return string '' when queued
	 */
	public function unenrol(int $enrolmentId): string
	{
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."school_enrolment SET status = ".self::TO_REMOVE." WHERE rowid = ".$enrolmentId);
		(new SyncQueue($this->db))->enqueue('enrolment', $enrolmentId, 'delete');
		return '';
	}

	/**
	 * @param int   $socid     Third party id
	 * @param int   $contactId Contact id, 0 for none
	 * @param float $share     Share, 0 to 100
	 * @param bool  $follow    Whether the parent will follow in Chamilo
	 * @return string '' when valid, else a language key
	 */
	private function checkParent(int $socid, int $contactId, float $share, bool $follow): string
	{
		$soc = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE rowid = ".$socid);
		if (!$soc || !$this->db->fetch_object($soc)) {
			return 'SchoolErrNotFound';
		}
		if ($share < 0 || $share > 100) {
			return 'SchoolErrShare';
		}
		if ($contactId > 0) {
			$c = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."socpeople WHERE rowid = ".$contactId." AND fk_soc = ".$socid);
			if (!$c || !$this->db->fetch_object($c)) {
				return 'SchoolErrContactNotOfParent';
			}
		} elseif ($follow) {
			return 'SchoolErrFollowNeedsContact';
		}
		return '';
	}

	/**
	 * @param string $relation Relation typed by the user
	 * @return string One of parent, guardian, other
	 */
	private function relation(string $relation): string
	{
		return in_array($relation, array('parent', 'guardian', 'other'), true) ? $relation : 'parent';
	}

	/**
	 * @param int $linkId Parent link id
	 * @return object|null
	 */
	private function link(int $linkId)
	{
		$resql = $this->db->query("SELECT rowid, fk_student, fk_soc, fk_socpeople, chamilo_follow FROM ".MAIN_DB_PREFIX."school_student_parent WHERE rowid = ".$linkId);
		return $resql ? ($this->db->fetch_object($resql) ?: null) : null;
	}
}
