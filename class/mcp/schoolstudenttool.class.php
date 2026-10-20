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

/**
 * \file    class/mcp/schoolstudenttool.class.php
 * \ingroup school
 * \brief   MCP tools for students, their parents, their enrolments and their attendance.
 */

require_once __DIR__.'/schoolmcptool.class.php';
require_once __DIR__.'/../schoolstudent.class.php';

class SchoolStudentTool extends SchoolMcpTool
{
	/**
	 * @return array<string,array<string,mixed>>
	 */
	protected function specs(): array
	{
		$student = array('student_id' => array('type' => 'integer', 'description' => 'School student id, from list_school_students'));
		return array(
			'list_school_students' => array(
				'desc' => 'List students with their parents (third parties, payer flag and share), Chamilo account and synchronization state. Pass id to read one student, or search to filter by name or reference.',
				'props' => array('id' => array('type' => 'integer'), 'search' => array('type' => 'string'), 'limit' => array('type' => 'integer')),
				'right' => 'read',
			),
			'create_school_student' => array(
				'desc' => 'Create a student and queue their Chamilo account (student role). The email is optional: without one the account uses the first parent\'s address. birthdate is YYYY-MM-DD.',
				'props' => array('firstname' => array('type' => 'string'), 'lastname' => array('type' => 'string'), 'birthdate' => array('type' => 'string'), 'email' => array('type' => 'string')),
				'required' => array('firstname', 'lastname'),
				'right' => 'write', 'write' => true,
				'preview' => 'Create the student {firstname} {lastname}.',
			),
			'update_school_student' => array(
				'desc' => 'Change a student. Pass every field you want to keep: omitted fields are kept as they are. active=false deactivates the Chamilo account.',
				'props' => $student + array('firstname' => array('type' => 'string'), 'lastname' => array('type' => 'string'), 'birthdate' => array('type' => 'string', 'description' => 'YYYY-MM-DD, or empty to clear'), 'email' => array('type' => 'string'), 'active' => array('type' => 'boolean')),
				'required' => array('student_id'),
				'right' => 'write', 'write' => true,
				'preview' => 'Change the student {student_id}.',
			),
			'add_school_student_parent' => array(
				'desc' => 'Link a parent (a Dolibarr third party, optionally one of its contacts) to a student. A payer is invoiced; share is the percentage of the cost (0-100). follow=true gives the parent a Chamilo account that follows the child (needs a contact with an email).',
				'props' => $student + array('third_party_id' => array('type' => 'integer'), 'contact_id' => array('type' => 'integer'), 'relation' => array('type' => 'string', 'enum' => array('parent', 'guardian', 'other')), 'is_payer' => array('type' => 'boolean'), 'share' => array('type' => 'number'), 'follow' => array('type' => 'boolean')),
				'required' => array('student_id', 'third_party_id'),
				'right' => 'write', 'write' => true,
				'preview' => 'Link the third party {third_party_id} to the student {student_id}.',
			),
			'update_school_student_parent' => array(
				'desc' => 'Change a parent link of a student (from list_school_students: parents[].link_id).',
				'props' => array('link_id' => array('type' => 'integer'), 'contact_id' => array('type' => 'integer'), 'relation' => array('type' => 'string', 'enum' => array('parent', 'guardian', 'other')), 'is_payer' => array('type' => 'boolean'), 'share' => array('type' => 'number'), 'follow' => array('type' => 'boolean')),
				'required' => array('link_id'),
				'right' => 'write', 'write' => true,
				'preview' => 'Change the parent link {link_id}.',
			),
			'remove_school_student_parent' => array(
				'desc' => 'Remove a parent link. The parent\'s Chamilo account is kept; its following relation is removed.',
				'props' => array('link_id' => array('type' => 'integer')),
				'required' => array('link_id'),
				'right' => 'write', 'write' => true,
				'preview' => 'Remove the parent link {link_id}.',
			),
			'add_school_enrolment' => array(
				'desc' => 'Enrol a student in a session, or in a course on its own (give exactly one of session_id and course_id). Queued for Chamilo.',
				'props' => $student + array('session_id' => array('type' => 'integer'), 'course_id' => array('type' => 'integer')),
				'required' => array('student_id'),
				'right' => 'write', 'write' => true,
				'preview' => 'Enrol the student {student_id} in session {session_id} / course {course_id}.',
			),
			'remove_school_enrolment' => array(
				'desc' => 'Remove an enrolment (from list_school_students: enrolments[].id). Queued for Chamilo.',
				'props' => array('enrolment_id' => array('type' => 'integer')),
				'required' => array('enrolment_id'),
				'right' => 'write', 'write' => true,
				'preview' => 'Remove the enrolment {enrolment_id}.',
			),
			'list_school_attendance' => array(
				'desc' => 'Attendance read back from Chamilo: for a student, the presence at each date (present, late, absent, absent with a reason), with totals. A date is "final" once Chamilo says the attendance was taken.',
				'props' => $student + array('limit' => array('type' => 'integer')),
				'required' => array('student_id'),
				'right' => 'read',
			),
		);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_list_school_students(array $args): array
	{
		$P = MAIN_DB_PREFIX;
		$where = "s.entity = ".$this->entity();
		if ($this->argInt($args, 'id') > 0) {
			$where .= " AND s.rowid = ".$this->argInt($args, 'id');
		}
		$search = $this->argString($args, 'search');
		if ($search !== '') {
			$like = $this->db->escape($this->db->escapeforlike($search));
			$where .= " AND (s.firstname LIKE '%".$like."%' OR s.lastname LIKE '%".$like."%' OR s.ref LIKE '%".$like."%')";
		}
		$rows = array();
		foreach ($this->rows("SELECT s.rowid, s.ref, s.firstname, s.lastname, s.birthdate, s.email, s.status, s.chamilo_user_id, s.chamilo_username, s.sync_status, s.last_error FROM {$P}school_student AS s WHERE ".$where." ORDER BY s.lastname, s.firstname LIMIT ".$this->limit($args)) as $o) {
			$parents = array();
			foreach ($this->rows("SELECT sp.rowid, sp.fk_soc, sp.fk_socpeople, sp.relation, sp.is_payer, sp.payer_share, sp.chamilo_follow, so.nom FROM {$P}school_student_parent AS sp LEFT JOIN {$P}societe AS so ON so.rowid = sp.fk_soc WHERE sp.fk_student = ".((int) $o->rowid)." ORDER BY sp.rowid") as $p) {
				$parents[] = array('link_id' => (int) $p->rowid, 'third_party_id' => (int) $p->fk_soc, 'third_party' => (string) $p->nom, 'contact_id' => (int) $p->fk_socpeople, 'relation' => (string) $p->relation, 'is_payer' => (bool) $p->is_payer, 'share' => (float) $p->payer_share, 'follows_in_chamilo' => (int) $p->chamilo_follow === 1);
			}
			$enrolments = array();
			foreach ($this->rows("SELECT e.rowid, e.fk_session, e.fk_course, e.status, e.sync_status, ps.label AS session_label, pc.label AS course_label FROM {$P}school_enrolment AS e LEFT JOIN {$P}school_session AS ss ON ss.rowid = e.fk_session LEFT JOIN {$P}product AS ps ON ps.rowid = ss.fk_product LEFT JOIN {$P}school_course AS c ON c.rowid = e.fk_course LEFT JOIN {$P}product AS pc ON pc.rowid = c.fk_product WHERE e.fk_student = ".((int) $o->rowid)." ORDER BY e.rowid") as $e) {
				$enrolments[] = array('id' => (int) $e->rowid, 'session_id' => $e->fk_session ? (int) $e->fk_session : null, 'course_id' => $e->fk_course ? (int) $e->fk_course : null, 'label' => (string) ($e->session_label ?: $e->course_label), 'status' => (int) $e->status === SchoolStudent::TO_REMOVE ? 'removal waiting' : ((int) $e->status === SchoolStudent::ENROLLED ? 'enrolled' : 'waiting'), 'sync_status' => (int) $e->sync_status);
			}
			$rows[] = array('id' => (int) $o->rowid, 'ref' => (string) $o->ref, 'firstname' => (string) $o->firstname, 'lastname' => (string) $o->lastname, 'birthdate' => $o->birthdate ? substr((string) $o->birthdate, 0, 10) : null, 'email' => (string) $o->email,
				'active' => (int) $o->status === 1, 'chamilo_user_id' => $o->chamilo_user_id !== null ? (int) $o->chamilo_user_id : null, 'chamilo_username' => $o->chamilo_username, 'sync_status' => (int) $o->sync_status, 'last_error' => $o->last_error, 'parents' => $parents, 'enrolments' => $enrolments);
		}
		return $rows;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return int|null|false Timestamp, null for none, false for a date that is not valid
	 */
	private function birthdate(array $args)
	{
		if ($this->argString($args, 'birthdate') === '') {
			return null;
		}
		$day = $this->argDay($args, 'birthdate');
		return $day === null ? false : schoolParseDate($day);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_create_school_student(array $args): array
	{
		$birth = $this->birthdate($args);
		if ($birth === false) {
			return $this->fail('birthdate must be YYYY-MM-DD');
		}
		$res = (new SchoolStudent($this->db))->create($this->argString($args, 'firstname'), $this->argString($args, 'lastname'), $birth, $this->argString($args, 'email'));
		if ($res['error'] !== '') {
			return $this->fail($res['error']);
		}
		return $this->done(array('student_id' => $res['id'], 'queued' => true));
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_update_school_student(array $args): array
	{
		$id = $this->argInt($args, 'student_id');
		$cur = $this->rows("SELECT firstname, lastname, birthdate, email, status FROM ".MAIN_DB_PREFIX."school_student WHERE rowid = ".$id." AND entity = ".$this->entity());
		if (!$cur) {
			return $this->fail('SchoolErrNotFound');
		}
		$cur = $cur[0];
		if (array_key_exists('birthdate', $args)) {
			$birth = $this->birthdate($args);
			if ($birth === false) {
				return $this->fail('birthdate must be YYYY-MM-DD');
			}
		} else {
			$birth = $cur->birthdate ? (int) $this->db->jdate($cur->birthdate) : null;
		}
		$error = (new SchoolStudent($this->db))->update($id,
			array_key_exists('firstname', $args) ? $this->argString($args, 'firstname') : (string) $cur->firstname,
			array_key_exists('lastname', $args) ? $this->argString($args, 'lastname') : (string) $cur->lastname,
			$birth,
			array_key_exists('email', $args) ? $this->argString($args, 'email') : (string) $cur->email,
			$this->argBool($args, 'active', (int) $cur->status === 1));
		return $error === '' ? $this->done(array('student_id' => $id, 'queued' => true)) : $this->fail($error);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 */
	private function relation(array $args, string $default): string
	{
		$r = $this->argString($args, 'relation');
		return in_array($r, array('parent', 'guardian', 'other'), true) ? $r : $default;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_add_school_student_parent(array $args): array
	{
		$share = $this->argFloat($args, 'share');
		if ($share < 0 || $share > 100) {
			return $this->fail('share must be between 0 and 100');
		}
		$error = (new SchoolStudent($this->db))->addParent($this->argInt($args, 'student_id'), $this->argInt($args, 'third_party_id'), $this->argInt($args, 'contact_id'), $this->relation($args, 'parent'), $this->argBool($args, 'is_payer'), $share, $this->argBool($args, 'follow'));
		return $error === '' ? $this->done(array('student_id' => $this->argInt($args, 'student_id'), 'linked' => true)) : $this->fail($error);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_update_school_student_parent(array $args): array
	{
		$id = $this->argInt($args, 'link_id');
		$cur = $this->rows("SELECT sp.fk_socpeople, sp.relation, sp.is_payer, sp.payer_share, sp.chamilo_follow FROM ".MAIN_DB_PREFIX."school_student_parent AS sp INNER JOIN ".MAIN_DB_PREFIX."school_student AS s ON s.rowid = sp.fk_student WHERE sp.rowid = ".$id." AND s.entity = ".$this->entity());
		if (!$cur) {
			return $this->fail('SchoolErrNotFound');
		}
		$cur = $cur[0];
		$share = array_key_exists('share', $args) ? $this->argFloat($args, 'share') : (float) $cur->payer_share;
		if ($share < 0 || $share > 100) {
			return $this->fail('share must be between 0 and 100');
		}
		$error = (new SchoolStudent($this->db))->updateParent($id,
			array_key_exists('contact_id', $args) ? $this->argInt($args, 'contact_id') : (int) $cur->fk_socpeople,
			$this->relation($args, (string) $cur->relation),
			$this->argBool($args, 'is_payer', (bool) $cur->is_payer), $share,
			$this->argBool($args, 'follow', (int) $cur->chamilo_follow === 1));
		return $error === '' ? $this->done(array('link_id' => $id, 'updated' => true)) : $this->fail($error);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_remove_school_student_parent(array $args): array
	{
		$id = $this->argInt($args, 'link_id');
		if (!$this->rows("SELECT sp.rowid FROM ".MAIN_DB_PREFIX."school_student_parent AS sp INNER JOIN ".MAIN_DB_PREFIX."school_student AS s ON s.rowid = sp.fk_student WHERE sp.rowid = ".$id." AND s.entity = ".$this->entity())) {
			return $this->fail('SchoolErrNotFound');
		}
		$error = (new SchoolStudent($this->db))->removeParent($id);
		return $error === '' ? $this->done(array('link_id' => $id, 'removed' => true)) : $this->fail($error);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_add_school_enrolment(array $args): array
	{
		$session = $this->argInt($args, 'session_id');
		$course = $this->argInt($args, 'course_id');
		if (($session > 0) === ($course > 0)) {
			return $this->fail('Give exactly one of session_id and course_id');
		}
		$error = (new SchoolStudent($this->db))->enrol($this->argInt($args, 'student_id'), $session, $course);
		return $error === '' ? $this->done(array('student_id' => $this->argInt($args, 'student_id'), 'queued' => true)) : $this->fail($error);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_remove_school_enrolment(array $args): array
	{
		$id = $this->argInt($args, 'enrolment_id');
		if (!$this->rows("SELECT rowid FROM ".MAIN_DB_PREFIX."school_enrolment WHERE rowid = ".$id." AND entity = ".$this->entity())) {
			return $this->fail('SchoolErrNotFound');
		}
		$error = (new SchoolStudent($this->db))->unenrol($id);
		return $error === '' ? $this->done(array('enrolment_id' => $id, 'queued' => true)) : $this->fail($error);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_list_school_attendance(array $args): array
	{
		$P = MAIN_DB_PREFIX;
		$id = $this->argInt($args, 'student_id');
		if (!$this->rows("SELECT rowid FROM {$P}school_student WHERE rowid = ".$id." AND entity = ".$this->entity())) {
			return $this->fail('SchoolErrNotFound');
		}
		$codes = array();
		$dates = array();
		foreach ($this->rows("SELECT pr.presence, o.date_start, o.attendance_done, pc.label AS course_label, ps.label AS session_label FROM {$P}school_presence AS pr INNER JOIN {$P}school_occurrence AS o ON o.rowid = pr.fk_occurrence INNER JOIN {$P}school_course AS c ON c.rowid = o.fk_course LEFT JOIN {$P}product AS pc ON pc.rowid = c.fk_product LEFT JOIN {$P}school_session AS s ON s.rowid = o.fk_session AND o.fk_session > 0 LEFT JOIN {$P}product AS ps ON ps.rowid = s.fk_product WHERE pr.fk_student = ".$id." AND pr.entity = ".$this->entity()." ORDER BY o.date_start DESC") as $o) {
			$codes[] = (int) $o->presence;
			if (count($dates) < $this->limit($args)) {
				$dates[] = array('date' => $this->iso($o->date_start), 'course' => trim((string) ($o->session_label ? $o->session_label.' - ' : '').$o->course_label), 'presence' => schoolPresenceGroup((int) $o->presence), 'code' => (int) $o->presence, 'final' => (int) $o->attendance_done === 1);
			}
		}
		return array(array('student_id' => $id, 'totals' => schoolPresenceTotals($codes), 'dates' => $dates));
	}
}
