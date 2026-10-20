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
 * \file    class/mcp/schoolclasstool.class.php
 * \ingroup school
 * \brief   MCP tools for classes of students, their activities and the announcement to their parents.
 */

require_once __DIR__.'/schoolmcptool.class.php';
require_once __DIR__.'/../schoolclass.class.php';
require_once __DIR__.'/../schoolbilling.class.php';

class SchoolClassTool extends SchoolMcpTool
{
	/**
	 * @return array<string,array<string,mixed>>
	 */
	protected function specs(): array
	{
		$class = array('class_id' => array('type' => 'integer', 'description' => 'School class id, from list_school_classes'));
		$dates = array('start' => array('type' => 'string', 'description' => 'YYYY-MM-DD, start of the cycle (optional)'), 'end' => array('type' => 'string', 'description' => 'YYYY-MM-DD, end of the cycle (optional)'));
		return array(
			'list_school_classes' => array(
				'desc' => 'List the classes of students. Pass id to read one class with its members, sessions and extra activities. A class is mirrored in Chamilo.',
				'props' => array('id' => array('type' => 'integer'), 'limit' => array('type' => 'integer')),
				'right' => 'read',
			),
			'create_school_class' => array(
				'desc' => 'Create a class: a group of students who follow the same sessions during a cycle (not necessarily a school year). Queued for Chamilo.',
				'props' => array('label' => array('type' => 'string'), 'level' => array('type' => 'string')) + $dates + array('homeroom_teacher_id' => array('type' => 'integer', 'description' => 'School teacher id (optional)')),
				'required' => array('label'),
				'right' => 'class', 'write' => true,
				'preview' => 'Create the class "{label}".',
			),
			'update_school_class' => array(
				'desc' => 'Change a class. Omitted fields are kept. active=false archives it.',
				'props' => $class + array('label' => array('type' => 'string'), 'level' => array('type' => 'string')) + $dates + array('homeroom_teacher_id' => array('type' => 'integer'), 'active' => array('type' => 'boolean')),
				'required' => array('class_id'),
				'right' => 'class', 'write' => true,
				'preview' => 'Change the class {class_id}.',
			),
			'add_school_class_student' => array(
				'desc' => 'Add a student to a class from a day (today by default). Chamilo then enrols them in the sessions the class follows.',
				'props' => $class + array('student_id' => array('type' => 'integer'), 'from' => array('type' => 'string', 'description' => 'YYYY-MM-DD')),
				'required' => array('class_id', 'student_id'),
				'right' => 'class', 'write' => true,
				'preview' => 'Add the student {student_id} to the class {class_id}.',
			),
			'remove_school_class_student' => array(
				'desc' => 'End a student\'s membership of a class (members[].member_id from list_school_classes with id). The row is kept, ended the day before, so past announcements and invoices stay traceable.',
				'props' => array('member_id' => array('type' => 'integer')),
				'required' => array('member_id'),
				'right' => 'class', 'write' => true,
				'preview' => 'End the class membership {member_id}.',
			),
			'add_school_class_session' => array(
				'desc' => 'Make a class follow a session.',
				'props' => $class + array('session_id' => array('type' => 'integer')),
				'required' => array('class_id', 'session_id'),
				'right' => 'class', 'write' => true,
				'preview' => 'Make the class {class_id} follow the session {session_id}.',
			),
			'remove_school_class_session' => array(
				'desc' => 'Stop a class following a session.',
				'props' => $class + array('session_id' => array('type' => 'integer')),
				'required' => array('class_id', 'session_id'),
				'right' => 'class', 'write' => true,
				'preview' => 'Make the class {class_id} stop following the session {session_id}.',
			),
			'create_school_class_announcement_draft' => array(
				'desc' => 'Prepare a DRAFT campaign in Dolibarr\'s Emailing module addressed to the parents of the current members of a class (one message per address). Nothing is sent: it is reviewed and sent from the Emailing module. Needs the Emailing module and its create right.',
				'props' => $class + array('subject' => array('type' => 'string'), 'message' => array('type' => 'string', 'description' => 'Plain text')),
				'required' => array('class_id', 'subject', 'message'),
				'right' => array('class', 'mailing:creer'), 'write' => true,
				'preview' => 'Prepare a draft announcement "{subject}" to the parents of the class {class_id}. It is not sent.',
			),
			'create_school_class_activity' => array(
				'desc' => 'Record an extra activity done by a class (museum visit, trip) that will be invoiced to the parents of the participants. Give a price per student, or a service whose price is used.',
				'props' => $class + array('label' => array('type' => 'string'), 'date' => array('type' => 'string', 'description' => 'YYYY-MM-DD'), 'price' => array('type' => 'number', 'description' => 'Excl. tax per student'), 'product_id' => array('type' => 'integer', 'description' => 'Service whose price is used instead')),
				'required' => array('class_id', 'label', 'date'),
				'right' => 'class', 'write' => true,
				'preview' => 'Record the activity "{label}" of the class {class_id} on {date}.',
			),
			'delete_school_class_activity' => array(
				'desc' => 'Delete an activity that was not invoiced.',
				'props' => array('activity_id' => array('type' => 'integer')),
				'required' => array('activity_id'),
				'right' => 'class', 'write' => true,
				'preview' => 'Delete the class activity {activity_id}.',
			),
		);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_list_school_classes(array $args): array
	{
		$P = MAIN_DB_PREFIX;
		$id = $this->argInt($args, 'id');
		$classes = new SchoolClass($this->db);
		$rows = array();
		foreach ($this->rows("SELECT c.rowid, c.ref, c.label, c.level, c.date_start, c.date_end, c.fk_homeroom_teacher, c.status, c.chamilo_id, c.sync_status, c.last_error FROM {$P}school_class AS c WHERE c.entity = ".$this->entity().($id > 0 ? " AND c.rowid = ".$id : '')." ORDER BY c.label LIMIT ".$this->limit($args)) as $o) {
			$cid = (int) $o->rowid;
			$row = array('id' => $cid, 'ref' => (string) $o->ref, 'label' => (string) $o->label, 'level' => (string) $o->level, 'start' => $o->date_start ? substr((string) $o->date_start, 0, 10) : null, 'end' => $o->date_end ? substr((string) $o->date_end, 0, 10) : null,
				'homeroom_teacher_id' => $o->fk_homeroom_teacher ? (int) $o->fk_homeroom_teacher : null, 'active' => (int) $o->status === 1, 'chamilo_id' => $o->chamilo_id !== null ? (int) $o->chamilo_id : null,
				'sync_status' => (int) $o->sync_status, 'last_error' => $o->last_error, 'current_members' => count($classes->currentMemberIds($cid)));
			if ($id > 0) {
				$members = array();
				foreach ($this->rows("SELECT cs.rowid, cs.fk_student, cs.date_start, cs.date_end, s.ref, s.firstname, s.lastname FROM {$P}school_class_student AS cs INNER JOIN {$P}school_student AS s ON s.rowid = cs.fk_student WHERE cs.fk_class = ".$cid." ORDER BY s.lastname, s.firstname") as $m) {
					$members[] = array('member_id' => (int) $m->rowid, 'student_id' => (int) $m->fk_student, 'name' => trim($m->firstname.' '.$m->lastname), 'from' => $m->date_start ? substr((string) $m->date_start, 0, 10) : null, 'until' => $m->date_end ? substr((string) $m->date_end, 0, 10) : null);
				}
				$sessions = array();
				foreach ($this->rows("SELECT cs.fk_session, p.label FROM {$P}school_class_session AS cs INNER JOIN {$P}school_session AS s ON s.rowid = cs.fk_session LEFT JOIN {$P}product AS p ON p.rowid = s.fk_product WHERE cs.fk_class = ".$cid) as $s) {
					$sessions[] = array('session_id' => (int) $s->fk_session, 'label' => (string) $s->label);
				}
				$activities = array();
				foreach ($this->rows("SELECT a.rowid, a.label, a.date_activity, a.unit_price, a.fk_product, a.status FROM {$P}school_class_activity AS a WHERE a.fk_class = ".$cid." ORDER BY a.date_activity DESC") as $a) {
					$activities[] = array('activity_id' => (int) $a->rowid, 'label' => (string) $a->label, 'date' => substr((string) $a->date_activity, 0, 10), 'price' => (float) $a->unit_price, 'product_id' => $a->fk_product ? (int) $a->fk_product : null, 'invoiced' => (int) $a->status === 1);
				}
				$row += array('members' => $members, 'sessions' => $sessions, 'activities' => $activities);
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array{0:?string,1:?string,2:?string} start, end, error key: a bad date is an error, an absent one is null
	 */
	private function cycle(array $args, ?string $oldStart = null, ?string $oldEnd = null): array
	{
		$out = array($oldStart, $oldEnd, null);
		foreach (array('start' => 0, 'end' => 1) as $key => $i) {
			if (!array_key_exists($key, $args)) {
				continue;
			}
			if ($this->argString($args, $key) === '') {
				$out[$i] = null;
				continue;
			}
			$day = $this->argDay($args, $key);
			if ($day === null) {
				$out[2] = $key.' must be YYYY-MM-DD';
				return $out;
			}
			$out[$i] = $day;
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_create_school_class(array $args): array
	{
		[$start, $end, $err] = $this->cycle($args);
		if ($err !== null) {
			return $this->fail($err);
		}
		$res = (new SchoolClass($this->db))->create($this->argString($args, 'label'), $this->argString($args, 'level'), $start, $end, $this->argInt($args, 'homeroom_teacher_id'));
		return $res['error'] === '' ? $this->done(array('class_id' => $res['id'], 'queued' => true)) : $this->fail($res['error']);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_update_school_class(array $args): array
	{
		$id = $this->argInt($args, 'class_id');
		$cur = $this->rows("SELECT label, level, date_start, date_end, fk_homeroom_teacher, status FROM ".MAIN_DB_PREFIX."school_class WHERE rowid = ".$id." AND entity = ".$this->entity());
		if (!$cur) {
			return $this->fail('SchoolErrNotFound');
		}
		$cur = $cur[0];
		[$start, $end, $err] = $this->cycle($args, $cur->date_start ? substr((string) $cur->date_start, 0, 10) : null, $cur->date_end ? substr((string) $cur->date_end, 0, 10) : null);
		if ($err !== null) {
			return $this->fail($err);
		}
		$error = (new SchoolClass($this->db))->update($id,
			array_key_exists('label', $args) ? $this->argString($args, 'label') : (string) $cur->label,
			array_key_exists('level', $args) ? $this->argString($args, 'level') : (string) $cur->level,
			$start, $end,
			array_key_exists('homeroom_teacher_id', $args) ? $this->argInt($args, 'homeroom_teacher_id') : (int) $cur->fk_homeroom_teacher,
			$this->argBool($args, 'active', (int) $cur->status === 1));
		return $error === '' ? $this->done(array('class_id' => $id, 'queued' => true)) : $this->fail($error);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_add_school_class_student(array $args): array
	{
		$from = $this->argString($args, 'from') === '' ? null : $this->argDay($args, 'from');
		if ($this->argString($args, 'from') !== '' && $from === null) {
			return $this->fail('from must be YYYY-MM-DD');
		}
		$error = (new SchoolClass($this->db))->addStudent($this->argInt($args, 'class_id'), $this->argInt($args, 'student_id'), $from);
		return $error === '' ? $this->done(array('class_id' => $this->argInt($args, 'class_id'), 'queued' => true)) : $this->fail($error);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_remove_school_class_student(array $args): array
	{
		$id = $this->argInt($args, 'member_id');
		if (!$this->rows("SELECT cs.rowid FROM ".MAIN_DB_PREFIX."school_class_student AS cs INNER JOIN ".MAIN_DB_PREFIX."school_class AS c ON c.rowid = cs.fk_class WHERE cs.rowid = ".$id." AND c.entity = ".$this->entity())) {
			return $this->fail('SchoolErrNotFound');
		}
		$error = (new SchoolClass($this->db))->removeStudent($id);
		return $error === '' ? $this->done(array('member_id' => $id, 'queued' => true)) : $this->fail($error);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_add_school_class_session(array $args): array
	{
		$error = (new SchoolClass($this->db))->addSession($this->argInt($args, 'class_id'), $this->argInt($args, 'session_id'));
		return $error === '' ? $this->done(array('class_id' => $this->argInt($args, 'class_id'), 'queued' => true)) : $this->fail($error);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_remove_school_class_session(array $args): array
	{
		$error = (new SchoolClass($this->db))->removeSession($this->argInt($args, 'class_id'), $this->argInt($args, 'session_id'));
		return $error === '' ? $this->done(array('class_id' => $this->argInt($args, 'class_id'), 'queued' => true)) : $this->fail($error);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_create_school_class_announcement_draft(array $args): array
	{
		$res = (new SchoolClass($this->db))->prepareAnnouncement($this->user, $this->argInt($args, 'class_id'), $this->argString($args, 'subject'), $this->argText($args, 'message'));
		if ($res['id'] <= 0) {
			return $this->fail($res['error']);
		}
		return $this->done(array('mailing_id' => $res['id'], 'recipients' => $res['added'], 'sent' => false, 'review_at' => DOL_URL_ROOT.'/comm/mailing/card.php?id='.$res['id']));
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_create_school_class_activity(array $args): array
	{
		$day = $this->argDay($args, 'date');
		if ($day === null) {
			return $this->fail('SchoolErrClassDates');
		}
		$res = (new SchoolBilling($this->db))->createActivity($this->argInt($args, 'class_id'), $this->argString($args, 'label'), $day, max(0.0, $this->argFloat($args, 'price')), $this->argInt($args, 'product_id'));
		return $res['error'] === '' ? $this->done(array('activity_id' => $res['id'])) : $this->fail($res['error']);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_delete_school_class_activity(array $args): array
	{
		$id = $this->argInt($args, 'activity_id');
		if (!$this->rows("SELECT rowid FROM ".MAIN_DB_PREFIX."school_class_activity WHERE rowid = ".$id." AND entity = ".$this->entity())) {
			return $this->fail('SchoolErrNotFound');
		}
		$error = (new SchoolBilling($this->db))->deleteActivity($id);
		return $error === '' ? $this->done(array('activity_id' => $id, 'deleted' => true)) : $this->fail($error);
	}
}
