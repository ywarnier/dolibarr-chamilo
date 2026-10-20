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
 * \file    class/mcp/schoolcatalogtool.class.php
 * \ingroup school
 * \brief   MCP tools for courses, sessions and teachers.
 */

require_once __DIR__.'/schoolmcptool.class.php';
require_once __DIR__.'/../schoolcatalog.class.php';
require_once __DIR__.'/../schoolsessionsync.class.php';
require_once __DIR__.'/../schoolteachersync.class.php';

class SchoolCatalogTool extends SchoolMcpTool
{
	/**
	 * @return array<string,array<string,mixed>>
	 */
	protected function specs(): array
	{
		return array(
			'list_school_courses' => array(
				'desc' => 'List the courses of the school (Chamilo courses, kept as Dolibarr service products), with the Chamilo id, price, language and synchronization state.',
				'props' => array('limit' => array('type' => 'integer', 'description' => 'Rows at most, 200 maximum')),
				'right' => 'read',
			),
			'list_school_sessions' => array(
				'desc' => 'List the sessions of the school with their dates, courses and synchronization state. A session without a Chamilo id is still waiting to be created in Chamilo.',
				'props' => array('limit' => array('type' => 'integer')),
				'right' => 'read',
			),
			'create_school_session' => array(
				'desc' => 'Create a session in Dolibarr (a service product) and queue its creation in Chamilo, with the courses it contains. Dates are YYYY-MM-DD. The price is excl. tax, 0 for none.',
				'props' => array(
					'title' => array('type' => 'string'),
					'display_start' => array('type' => 'string', 'description' => 'YYYY-MM-DD'),
					'display_end' => array('type' => 'string', 'description' => 'YYYY-MM-DD'),
					'price' => array('type' => 'number'),
					'course_ids' => array('type' => 'array', 'items' => array('type' => 'integer'), 'description' => 'School course ids, from list_school_courses'),
				),
				'required' => array('title', 'display_start', 'display_end', 'course_ids'),
				'right' => 'write', 'write' => true,
				'preview' => 'Create the school session "{title}" from {display_start} to {display_end}.',
			),
			'update_school_catalog_from_chamilo' => array(
				'desc' => 'Read the courses and sessions of Chamilo and create or link the matching service products in Dolibarr. Nothing is deleted.',
				'props' => array(),
				'right' => 'sync', 'write' => true,
				'preview' => 'Import the courses and sessions of Chamilo as service products.',
			),
			'list_school_teachers' => array(
				'desc' => 'List the teachers (Dolibarr employees with a Chamilo account). With available=true, list instead the employees who are not teachers yet.',
				'props' => array('available' => array('type' => 'boolean')),
				'right' => 'read',
			),
			'add_school_teacher' => array(
				'desc' => 'Make a Dolibarr employee a teacher: queues the creation of the Chamilo account (teacher role only), or links the account with the same email.',
				'props' => array('user_id' => array('type' => 'integer', 'description' => 'Dolibarr user id of an employee')),
				'required' => array('user_id'),
				'right' => 'teacher', 'write' => true,
				'preview' => 'Make the Dolibarr user {user_id} a teacher and create or link their Chamilo account.',
			),
			'update_school_teacher_assignments' => array(
				'desc' => 'Set the teachers of a course, or the coaches of the courses of a session. For each listed course the teachers not listed are taken off. Use kind=course with the course id, or kind=session with the session id.',
				'props' => array(
					'kind' => array('type' => 'string', 'enum' => array('course', 'session')),
					'id' => array('type' => 'integer', 'description' => 'School course id or school session id'),
					'assignments' => array('type' => 'array', 'description' => 'One entry per course: {course_id, teacher_ids}. teacher_ids are school teacher ids from list_school_teachers.', 'items' => array(
						'type' => 'object',
						'properties' => array('course_id' => array('type' => 'integer'), 'teacher_ids' => array('type' => 'array', 'items' => array('type' => 'integer'))),
						'required' => array('course_id', 'teacher_ids'),
					)),
				),
				'required' => array('kind', 'id', 'assignments'),
				'right' => 'teacher', 'write' => true,
				'preview' => 'Set the teachers of the {kind} {id}.',
			),
		);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_list_school_courses(array $args): array
	{
		$P = MAIN_DB_PREFIX;
		$rows = array();
		foreach ($this->rows("SELECT c.rowid, c.chamilo_id, c.code, c.language, c.sync_status, c.last_error, p.ref, p.label, p.price, p.duration_value, p.duration_unit FROM {$P}school_course AS c LEFT JOIN {$P}product AS p ON p.rowid = c.fk_product WHERE c.entity = ".$this->entity()." ORDER BY p.label LIMIT ".$this->limit($args)) as $o) {
			$rows[] = array('id' => (int) $o->rowid, 'label' => (string) $o->label, 'ref' => (string) $o->ref, 'code' => (string) $o->code, 'chamilo_id' => $o->chamilo_id !== null ? (int) $o->chamilo_id : null,
				'language' => (string) $o->language, 'price' => $o->price !== null ? (float) $o->price : null, 'duration' => $o->duration_value ? (string) $o->duration_value.' '.(string) $o->duration_unit : null,
				'sync_status' => (int) $o->sync_status, 'last_error' => $o->last_error);
		}
		return $rows;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_list_school_sessions(array $args): array
	{
		$P = MAIN_DB_PREFIX;
		$rows = array();
		foreach ($this->rows("SELECT s.rowid, s.chamilo_id, s.display_start, s.display_end, s.sync_status, s.last_error, p.ref, p.label, p.price FROM {$P}school_session AS s LEFT JOIN {$P}product AS p ON p.rowid = s.fk_product WHERE s.entity = ".$this->entity()." ORDER BY s.display_start DESC, s.rowid DESC LIMIT ".$this->limit($args)) as $o) {
			$courses = array();
			foreach ($this->rows("SELECT sc.fk_course FROM {$P}school_session_course AS sc WHERE sc.fk_session = ".((int) $o->rowid)) as $c) {
				$courses[] = (int) $c->fk_course;
			}
			$rows[] = array('id' => (int) $o->rowid, 'label' => (string) $o->label, 'ref' => (string) $o->ref, 'chamilo_id' => $o->chamilo_id !== null ? (int) $o->chamilo_id : null,
				'start' => $this->iso($o->display_start), 'end' => $this->iso($o->display_end), 'price' => $o->price !== null ? (float) $o->price : null, 'course_ids' => $courses,
				'sync_status' => (int) $o->sync_status, 'last_error' => $o->last_error);
		}
		return $rows;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_create_school_session(array $args): array
	{
		$title = $this->argString($args, 'title');
		if ($title === '') {
			return $this->fail('SchoolErrTitle');
		}
		$start = $this->argDay($args, 'display_start');
		$end = $this->argDay($args, 'display_end');
		if ($start === null || $end === null) {
			return $this->fail('SchoolErrDates');
		}
		$startTs = schoolParseDate($start);
		$endTs = schoolParseDate($end);
		if ($startTs === null || $endTs === null) {
			return $this->fail('SchoolErrDates');
		}
		if ($endTs < $startTs) {
			return $this->fail('SchoolErrDateOrder');
		}
		$courseIds = $this->argIds($args, 'course_ids');
		if (!$courseIds) {
			return $this->fail('SchoolSessionHasNoCourse');
		}
		$known = array();
		foreach ($this->rows("SELECT rowid FROM ".MAIN_DB_PREFIX."school_course WHERE entity = ".$this->entity()." AND rowid IN (".implode(',', $courseIds).")") as $o) {
			$known[] = (int) $o->rowid;
		}
		if (count($known) !== count($courseIds)) {
			return $this->fail('SchoolErrNotFound');
		}
		$res = (new SchoolSessionSync($this->db, $this->user))->createLocal(array('title' => $title, 'display_start' => (int) $startTs, 'display_end' => (int) $endTs, 'access_start' => $startTs, 'access_end' => $endTs, 'price' => max(0.0, $this->argFloat($args, 'price')), 'course_ids' => $courseIds));
		if ($res['error'] !== '') {
			return $this->fail($res['error']);
		}
		return $this->done(array('session_id' => $res['id'], 'queued' => true));
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_update_school_catalog_from_chamilo(array $args): array
	{
		$client = schoolGetClient();
		if ($client === null) {
			return $this->fail('SchoolNotConfigured');
		}
		$report = (new SchoolCatalog($this->db, $this->user))->importAll($client);
		return $this->done(array('courses' => $report['courses'], 'sessions' => $report['sessions'], 'errors' => $report['errors']));
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_list_school_teachers(array $args): array
	{
		$P = MAIN_DB_PREFIX;
		$rows = array();
		if ($this->argBool($args, 'available')) {
			foreach ($this->rows("SELECT u.rowid, u.login, u.firstname, u.lastname, u.email FROM {$P}user AS u WHERE u.employee = 1 AND u.statut = 1 AND u.entity IN (0, ".$this->entity().") AND u.rowid NOT IN (SELECT fk_user FROM {$P}school_teacher WHERE entity = ".$this->entity().") ORDER BY u.lastname, u.firstname LIMIT ".$this->limit($args)) as $o) {
				$rows[] = array('user_id' => (int) $o->rowid, 'login' => (string) $o->login, 'name' => trim($o->firstname.' '.$o->lastname), 'email' => (string) $o->email);
			}
			return $rows;
		}
		foreach ($this->rows("SELECT t.rowid, t.fk_user, t.chamilo_user_id, t.chamilo_username, t.sync_status, t.last_error, u.login, u.firstname, u.lastname FROM {$P}school_teacher AS t INNER JOIN {$P}user AS u ON u.rowid = t.fk_user WHERE t.entity = ".$this->entity()." ORDER BY u.lastname, u.firstname LIMIT ".$this->limit($args)) as $o) {
			$rows[] = array('id' => (int) $o->rowid, 'user_id' => (int) $o->fk_user, 'name' => trim($o->firstname.' '.$o->lastname), 'login' => (string) $o->login,
				'chamilo_user_id' => $o->chamilo_user_id !== null ? (int) $o->chamilo_user_id : null, 'chamilo_username' => $o->chamilo_username, 'sync_status' => (int) $o->sync_status, 'last_error' => $o->last_error);
		}
		return $rows;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_add_school_teacher(array $args): array
	{
		$res = (new SchoolTeacherSync($this->db))->enable($this->argInt($args, 'user_id'));
		if ($res['error'] !== '') {
			return $this->fail($res['error']);
		}
		return $this->done(array('teacher_id' => $res['id'], 'queued' => true));
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_update_school_teacher_assignments(array $args): array
	{
		$kind = $this->argString($args, 'kind');
		if (!in_array($kind, array('course', 'session'), true)) {
			return $this->fail('kind must be course or session');
		}
		$wanted = array();
		foreach ((array) ($args['assignments'] ?? array()) as $entry) {
			$entry = (array) $entry;
			$courseId = $this->argInt($entry, 'course_id');
			if ($courseId <= 0) {
				return $this->fail('Each assignment needs a course_id');
			}
			$wanted[$courseId] = $this->argIds($entry, 'teacher_ids');
		}
		if (!$wanted) {
			return $this->fail('No assignment given');
		}
		$res = (new SchoolTeacherSync($this->db))->assign($kind, $this->argInt($args, 'id'), $wanted);
		if ($res['error'] !== '') {
			return $this->fail($res['error']);
		}
		return $this->done(array('changed' => $res['changed'], 'queued' => $res['changed']));
	}
}
