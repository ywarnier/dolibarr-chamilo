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
 * \file    class/schoolreconcile.class.php
 * \ingroup school
 * \brief   Compares what Dolibarr holds with what Chamilo holds and reports the differences. It changes nothing.
 */

require_once __DIR__.'/../lib/schoollogic.lib.php';
require_once __DIR__.'/chamiloclient.class.php';
require_once __DIR__.'/syncqueue.class.php';
require_once __DIR__.'/schoolschedule.class.php';
require_once __DIR__.'/schoolclass.class.php';

/**
 * Dolibarr is the source of what the school creates (sessions it made, students, enrolments, classes, rooms, dates), so a
 * difference is reported as something to send again; Chamilo is the source of what teachers do there, so the module
 * never undoes it. Nothing here writes to Chamilo: a finding that can be fixed carries the job that would fix it, and a
 * person decides whether to queue it.
 *
 * A finding is: area, label, url, kind (missing: Chamilo does not have it; differs; extra: Chamilo has it and Dolibarr
 * does not expect it; unreadable: Chamilo could not be asked), detail, and fix (job type, id, action) or null.
 */
class SchoolReconcile
{
	/** Objects looked at per area and run, so a run stays short on a big school. */
	public const LIMIT = 300;

	/** @var DoliDB */
	private $db;
	/** @var ChamiloClient */
	private $client;
	/** @var list<array<string,mixed>> */
	private $findings = array();
	/** @var array<string,int> Objects looked at, per area */
	private $checked = array();
	/** @var list<string> Areas that had more objects than LIMIT */
	private $truncated = array();
	/** @var array<string,int> Things Chamilo has that Dolibarr does not manage at all, per area (not findings) */
	private $unmanaged = array();

	/**
	 * @param DoliDB        $db     Database handler
	 * @param ChamiloClient $client Client
	 */
	public function __construct($db, ChamiloClient $client)
	{
		$this->db = $db;
		$this->client = $client;
	}

	/**
	 * @return list<string> The areas that can be checked
	 */
	public static function areas(): array
	{
		return array('courses', 'sessions', 'students', 'teachers', 'enrolments', 'classes', 'rooms', 'dates');
	}

	/**
	 * Run the checks of the chosen areas.
	 *
	 * @param list<string> $areas Areas to check
	 * @return array{findings:list<array<string,mixed>>,checked:array<string,int>,truncated:list<string>,unmanaged:array<string,int>}
	 */
	public function run(array $areas): array
	{
		@set_time_limit(300);
		foreach (self::areas() as $area) {
			if (in_array($area, $areas, true)) {
				$this->{'check'.ucfirst($area)}();
			}
		}
		return array('findings' => $this->findings, 'checked' => $this->checked, 'truncated' => $this->truncated, 'unmanaged' => $this->unmanaged);
	}

	/**
	 * @param string                           $area   Area
	 * @param string                           $label  What the finding is about
	 * @param string                           $url    Page that shows it, or empty
	 * @param string                           $kind   missing, differs, extra or unreadable
	 * @param string                           $detail What is wrong
	 * @param array{0:string,1:int,2:string}|null $fix [job type, id, action] that would fix it
	 */
	private function add(string $area, string $label, string $url, string $kind, string $detail, ?array $fix = null): void
	{
		$this->findings[] = array('area' => $area, 'label' => $label, 'url' => $url, 'kind' => $kind, 'detail' => $detail, 'fix' => $fix);
	}

	/**
	 * Rows of a query, at most LIMIT of them; the area is noted when there were more.
	 *
	 * @param string $area Area
	 * @param string $sql  Query without LIMIT
	 * @return list<object>
	 */
	private function rows(string $area, string $sql): array
	{
		$rows = array();
		$q = $this->db->query($sql.' LIMIT '.(self::LIMIT + 1));
		while ($q && ($o = $this->db->fetch_object($q))) {
			$rows[] = $o;
		}
		if (count($rows) > self::LIMIT) {
			array_pop($rows);
			$this->truncated[] = $area;
		}
		$this->checked[$area] = count($rows);
		return $rows;
	}

	/**
	 * @param string $page  Page of the module
	 * @param string $query Query string
	 * @return string
	 */
	private function link(string $page, string $query = ''): string
	{
		return dol_buildpath('/school/'.$page, 1).$query;
	}

	/**
	 * Every item of a collection.
	 *
	 * @param string $path Collection path
	 * @return array<int,array<string,mixed>>|null null when Chamilo could not be read
	 */
	private function all(string $path): ?array
	{
		$all = array();
		for ($page = 1; $page <= 100; $page++) {
			$res = $this->client->request('GET', $path, array('itemsPerPage' => 100, 'page' => $page));
			if (!$res['ok']) {
				return null;
			}
			$items = ChamiloClient::members($res['data']);
			$all = array_merge($all, array_filter($items, 'is_array'));
			if (count($items) < 100) {
				break;
			}
		}
		return $all;
	}

	/**
	 * Read an item. A 404 is a fact, not an error.
	 *
	 * @param string $path Item path
	 * @return array{0:string,1:array<string,mixed>} ['ok'|'gone'|'error', data or [message]]
	 */
	private function item(string $path): array
	{
		$res = $this->client->request('GET', $path);
		if ($res['ok'] && is_array($res['data'])) {
			return array('ok', $res['data']);
		}
		return array($res['status'] === 404 ? 'gone' : 'error', array('message' => $res['error']));
	}

	private function checkCourses(): void
	{
		$P = MAIN_DB_PREFIX;
		$known = array();
		foreach ($this->rows('courses', "SELECT c.rowid, c.chamilo_id, p.label FROM {$P}school_course AS c LEFT JOIN {$P}product AS p ON p.rowid = c.fk_product WHERE c.entity = ".((int) $this->entity())." AND c.chamilo_id IS NOT NULL ORDER BY c.rowid") as $o) {
			$known[(int) $o->chamilo_id] = true;
			[$state, $data] = $this->item('/api/courses/'.((int) $o->chamilo_id));
			if ($state === 'gone') {
				$this->add('courses', (string) $o->label, $this->link('course_list.php'), 'missing', 'The course no longer exists in Chamilo.');
			} elseif ($state === 'error') {
				$this->add('courses', (string) $o->label, '', 'unreadable', (string) $data['message']);
			}
		}
		$list = $this->all('/api/courses');
		if ($list === null) {
			return;
		}
		foreach ($list as $item) {
			$m = schoolMapCourse($item);
			if ($m && empty($known[$m['chamilo_id']]) && !in_array('courses', $this->truncated, true)) {
				$this->add('courses', $m['title'].' ('.$m['code'].')', $this->link('course_list.php'), 'extra', 'In Chamilo, not imported in Dolibarr. Use Import on the Courses page.');
			}
		}
	}

	private function checkSessions(): void
	{
		$P = MAIN_DB_PREFIX;
		$known = array();
		foreach ($this->rows('sessions', "SELECT s.rowid, s.chamilo_id, p.label FROM {$P}school_session AS s LEFT JOIN {$P}product AS p ON p.rowid = s.fk_product WHERE s.entity = ".((int) $this->entity())." AND s.chamilo_id IS NOT NULL ORDER BY s.rowid") as $o) {
			$known[(int) $o->chamilo_id] = true;
			$label = (string) $o->label;
			$url = $this->link('schedule.php', '?session='.((int) $o->rowid));
			[$state, $data] = $this->item('/api/sessions/'.((int) $o->chamilo_id));
			if ($state !== 'ok') {
				$this->add('sessions', $label, $state === 'gone' ? $this->link('session_list.php') : '', $state === 'gone' ? 'missing' : 'unreadable', $state === 'gone' ? 'The session no longer exists in Chamilo.' : (string) $data['message']);
				continue;
			}
			$m = schoolMapSession($data);
			$wanted = array();
			$cq = $this->db->query("SELECT c.chamilo_id FROM {$P}school_session_course AS sc INNER JOIN {$P}school_course AS c ON c.rowid = sc.fk_course WHERE sc.fk_session = ".((int) $o->rowid)." AND c.chamilo_id IS NOT NULL");
			while ($cq && ($x = $this->db->fetch_object($cq))) {
				$wanted[] = (int) $x->chamilo_id;
			}
			$diff = schoolIdsDiff($wanted, (array) ($m['course_ids'] ?? array()));
			if ($diff['add']) {
				$this->add('sessions', $label, $url, 'differs', 'Courses of the session that Chamilo does not have: '.implode(', ', $diff['add']).'.', array('session', (int) $o->rowid, 'update'));
			}
			if ($diff['remove']) {
				$this->add('sessions', $label, $url, 'extra', 'Courses in the Chamilo session that Dolibarr does not list: '.implode(', ', $diff['remove']).'.');
			}
		}
		$list = $this->all('/api/sessions');
		if ($list !== null) {
			foreach ($list as $item) {
				$id = (int) ($item['id'] ?? 0);
				if ($id > 0 && empty($known[$id]) && !in_array('sessions', $this->truncated, true)) {
					$this->add('sessions', trim((string) ($item['title'] ?? '#'.$id)), $this->link('session_list.php'), 'extra', 'In Chamilo, not imported in Dolibarr. Use Import on the Sessions page.');
				}
			}
		}
	}

	/**
	 * @param string               $area     Area
	 * @param string               $label    Name of the person
	 * @param string               $url      Page
	 * @param int                  $chamilo  Chamilo user id, 0 when none
	 * @param array<string,mixed>  $expected Fields Dolibarr holds
	 * @param array{0:string,1:int,2:string} $fix Job that sends the person again
	 */
	private function checkAccount(string $area, string $label, string $url, int $chamilo, array $expected, array $fix): void
	{
		if ($chamilo <= 0) {
			$this->add($area, $label, $url, 'missing', 'No Chamilo account yet.', $fix);
			return;
		}
		[$state, $data] = $this->item('/api/users/'.$chamilo);
		if ($state !== 'ok') {
			$this->add($area, $label, $url, $state === 'gone' ? 'missing' : 'unreadable', $state === 'gone' ? 'The Chamilo account #'.$chamilo.' no longer exists. It is not created again by itself.' : (string) $data['message']);
			return;
		}
		if ((int) ($data['active'] ?? 1) === -2) {
			$this->add($area, $label, $url, 'missing', 'The Chamilo account #'.$chamilo.' was deleted in Chamilo. It is not created again by itself.');
			return;
		}
		$labels = array('firstname' => 'first name', 'lastname' => 'last name', 'active' => 'active');
		$diff = schoolCompareFields($expected, $data);
		if ($diff) {
			$parts = array();
			foreach ($diff as $field => $both) {
				$parts[] = ($labels[$field] ?? $field).': Dolibarr "'.$both['expected'].'", Chamilo "'.$both['actual'].'"';
			}
			$this->add($area, $label, $url, 'differs', implode('; ', $parts).'.', $fix);
		}
	}

	private function checkStudents(): void
	{
		$P = MAIN_DB_PREFIX;
		foreach ($this->rows('students', "SELECT rowid, firstname, lastname, status, chamilo_user_id FROM {$P}school_student WHERE entity = ".((int) $this->entity())." ORDER BY rowid") as $o) {
			$this->checkAccount('students', trim($o->firstname.' '.$o->lastname), $this->link('student_card.php', '?id='.((int) $o->rowid)), (int) $o->chamilo_user_id,
				array('firstname' => trim((string) $o->firstname), 'lastname' => trim((string) $o->lastname), 'active' => (int) $o->status === 1 ? 1 : 0), array('student', (int) $o->rowid, 'update'));
		}
	}

	private function checkTeachers(): void
	{
		$P = MAIN_DB_PREFIX;
		foreach ($this->rows('teachers', "SELECT t.rowid, t.chamilo_user_id, u.firstname, u.lastname, u.statut FROM {$P}school_teacher AS t INNER JOIN {$P}user AS u ON u.rowid = t.fk_user WHERE t.entity = ".((int) $this->entity())." ORDER BY t.rowid") as $o) {
			$this->checkAccount('teachers', trim($o->firstname.' '.$o->lastname), $this->link('teacher_list.php'), (int) $o->chamilo_user_id,
				array('firstname' => trim((string) $o->firstname), 'lastname' => trim((string) $o->lastname), 'active' => (int) $o->statut === 1 ? 1 : 0), array('teacher', (int) $o->rowid, 'update'));
		}
	}

	private function checkEnrolments(): void
	{
		$P = MAIN_DB_PREFIX;
		$rows = $this->rows('enrolments', "SELECT e.rowid, e.fk_session, e.fk_course, st.rowid AS sid, st.firstname, st.lastname, st.chamilo_user_id, s.chamilo_id AS session_cid, c.chamilo_id AS course_cid, ps.label AS session_label, pc.label AS course_label"
			." FROM {$P}school_enrolment AS e INNER JOIN {$P}school_student AS st ON st.rowid = e.fk_student"
			." LEFT JOIN {$P}school_session AS s ON s.rowid = e.fk_session LEFT JOIN {$P}product AS ps ON ps.rowid = s.fk_product"
			." LEFT JOIN {$P}school_course AS c ON c.rowid = e.fk_course LEFT JOIN {$P}product AS pc ON pc.rowid = c.fk_product"
			." WHERE e.entity = ".((int) $this->entity())." AND e.status = 1 ORDER BY e.rowid");
		$cache = array();
		$expectedBy = array();
		foreach ($rows as $o) {
			$isSession = (int) $o->fk_session > 0;
			$cid = (int) ($isSession ? $o->session_cid : $o->course_cid);
			$what = ($isSession ? 'Session ' : 'Course ').($isSession ? $o->session_label : $o->course_label);
			$label = trim($o->firstname.' '.$o->lastname).' → '.$what;
			$url = $this->link('student_card.php', '?id='.((int) $o->sid));
			if ($cid <= 0 || (int) $o->chamilo_user_id <= 0) {
				$this->add('enrolments', $label, $url, 'missing', $cid <= 0 ? 'The session or course is not in Chamilo yet.' : 'The student has no Chamilo account yet.', array('enrolment', (int) $o->rowid, 'create'));
				continue;
			}
			$key = ($isSession ? 's' : 'c').$cid;
			if (!isset($cache[$key])) {
				$cache[$key] = $isSession ? $this->sessionStudents($cid) : $this->courseStudents($cid);
			}
			if ($cache[$key] === null) {
				$this->add('enrolments', $label, $url, 'unreadable', 'Chamilo could not be asked for the students of the '.($isSession ? 'session (it may not be on the portal this module talks to)' : 'course').'.');
				continue;
			}
			$expectedBy[$key][(int) $o->chamilo_user_id] = true;
			if (!in_array((int) $o->chamilo_user_id, $cache[$key], true)) {
				$this->add('enrolments', $label, $url, 'missing', 'Not enrolled in Chamilo.', array('enrolment', (int) $o->rowid, 'create'));
			}
		}
		// Students that Dolibarr manages and that are in a session of Chamilo without being expected there (neither enrolled
		// one by one nor through a class). Users that Dolibarr does not manage are only counted.
		$managed = array();
		$q = $this->db->query("SELECT rowid, firstname, lastname, chamilo_user_id FROM {$P}school_student WHERE entity = ".((int) $this->entity())." AND chamilo_user_id IS NOT NULL");
		while ($q && ($o = $this->db->fetch_object($q))) {
			$managed[(int) $o->chamilo_user_id] = trim($o->firstname.' '.$o->lastname);
		}
		$classes = new SchoolClass($this->db);
		foreach ($cache as $key => $have) {
			if ($have === null || $key[0] !== 's') {
				continue;
			}
			$sid = (int) substr($key, 1);
			$viaClass = array();
			$cq = $this->db->query("SELECT cs.fk_class FROM {$P}school_class_session AS cs INNER JOIN {$P}school_session AS s ON s.rowid = cs.fk_session WHERE s.chamilo_id = ".$sid);
			while ($cq && ($x = $this->db->fetch_object($cq))) {
				$ids = $classes->currentMemberIds((int) $x->fk_class);
				if ($ids) {
					$mq = $this->db->query("SELECT chamilo_user_id FROM {$P}school_student WHERE rowid IN (".implode(',', array_map('intval', $ids)).") AND chamilo_user_id IS NOT NULL");
					while ($mq && ($m = $this->db->fetch_object($mq))) {
						$viaClass[(int) $m->chamilo_user_id] = true;
					}
				}
			}
			foreach ($have as $uid) {
				if (!empty($expectedBy[$key][$uid]) || !empty($viaClass[$uid])) {
					continue;
				}
				if (isset($managed[$uid])) {
					$this->add('enrolments', $managed[$uid].' → Chamilo session #'.$sid, '', 'extra', 'In the Chamilo session, but neither enrolled nor in a class that follows it in Dolibarr.');
				} else {
					$this->unmanaged['enrolments'] = ($this->unmanaged['enrolments'] ?? 0) + 1;
				}
			}
		}
	}

	/**
	 * @param int $sid Chamilo session id
	 * @return list<int>|null
	 */
	private function sessionStudents(int $sid): ?array
	{
		$res = $this->client->request('GET', '/api/sessions/'.$sid.'/students');
		return $res['ok'] ? array_map('intval', (array) ($res['data']['userIds'] ?? array())) : null;
	}

	/**
	 * @param int $cid Chamilo course id
	 * @return list<int>|null
	 */
	private function courseStudents(int $cid): ?array
	{
		$res = $this->client->request('GET', '/api/course_rel_users', array('course' => '/api/courses/'.$cid, 'itemsPerPage' => 500));
		if (!$res['ok']) {
			return null;
		}
		$ids = array();
		foreach (ChamiloClient::members($res['data']) as $row) {
			if (!is_array($row) || (int) ($row['status'] ?? 0) !== 5) {
				continue;
			}
			$u = $row['user'] ?? null;
			$id = is_array($u) ? (int) ($u['id'] ?? schoolIriId((string) ($u['@id'] ?? '')) ?? 0) : (int) (schoolIriId((string) $u) ?? 0);
			if ($id > 0) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	private function checkClasses(): void
	{
		$P = MAIN_DB_PREFIX;
		$classes = new SchoolClass($this->db);
		foreach ($this->rows('classes', "SELECT rowid, label, chamilo_id FROM {$P}school_class WHERE entity = ".((int) $this->entity())." ORDER BY rowid") as $o) {
			$label = (string) $o->label;
			$url = $this->link('class_card.php', '?id='.((int) $o->rowid));
			if ((int) $o->chamilo_id <= 0) {
				$this->add('classes', $label, $url, 'missing', 'Not in Chamilo yet.', array('class', (int) $o->rowid, 'update'));
				continue;
			}
			$res = $this->client->request('GET', '/api/usergroups/'.((int) $o->chamilo_id).'/subscriptions');
			if (!$res['ok']) {
				$gone = $res['status'] === 404 && stripos($res['error'], 'No route found') === false;
				$this->add('classes', $label, $url, $gone ? 'missing' : 'unreadable', $gone ? 'The class no longer exists in Chamilo.' : $res['error']);
				continue;
			}
			$wantedSessions = array();
			$sq = $this->db->query("SELECT s.chamilo_id FROM {$P}school_class_session AS cs INNER JOIN {$P}school_session AS s ON s.rowid = cs.fk_session WHERE cs.fk_class = ".((int) $o->rowid)." AND s.chamilo_id IS NOT NULL");
			while ($sq && ($x = $this->db->fetch_object($sq))) {
				$wantedSessions[] = (int) $x->chamilo_id;
			}
			$wantedUsers = array();
			$members = $classes->currentMemberIds((int) $o->rowid);
			if ($members) {
				$uq = $this->db->query("SELECT chamilo_user_id FROM {$P}school_student WHERE rowid IN (".implode(',', array_map('intval', $members)).") AND chamilo_user_id IS NOT NULL");
				while ($uq && ($x = $this->db->fetch_object($uq))) {
					$wantedUsers[] = (int) $x->chamilo_user_id;
				}
			}
			$problems = array();
			foreach (array('students' => array($wantedUsers, 'userIds'), 'sessions' => array($wantedSessions, 'sessionIds')) as $what => $pair) {
				$diff = schoolIdsDiff($pair[0], array_map('intval', (array) ($res['data'][$pair[1]] ?? array())));
				if ($diff['add']) {
					$problems[] = 'missing '.$what.' in Chamilo: '.implode(', ', $diff['add']);
				}
				if ($diff['remove']) {
					$problems[] = $what.' in Chamilo that the class does not have: '.implode(', ', $diff['remove']);
				}
			}
			if ($problems) {
				$this->add('classes', $label, $url, 'differs', implode('; ', $problems).' (Chamilo ids).', array('class', (int) $o->rowid, 'update'));
			}
		}
	}

	private function checkRooms(): void
	{
		$P = MAIN_DB_PREFIX;
		$rows = $this->rows('rooms', "SELECT r.rowid, r.chamilo_room_id, r.floor_number, x.ref, x.max_users FROM {$P}school_room AS r INNER JOIN {$P}resource AS x ON x.rowid = r.fk_resource WHERE r.entity = ".((int) $this->entity())." ORDER BY r.rowid");
		$list = $rows ? $this->all('/api/rooms') : array();
		$byId = array();
		foreach ($list ?? array() as $item) {
			$byId[(int) ($item['id'] ?? 0)] = $item;
		}
		foreach ($rows as $o) {
			$label = (string) $o->ref;
			$url = $this->link('room_list.php');
			$fix = array('room', (int) $o->rowid, 'update');
			if ((int) $o->chamilo_room_id <= 0) {
				$this->add('rooms', $label, $url, 'missing', 'Not in Chamilo yet.', $fix);
			} elseif ($list === null) {
				$this->add('rooms', $label, '', 'unreadable', 'Chamilo could not be asked for its rooms.');
			} elseif (!isset($byId[(int) $o->chamilo_room_id])) {
				$this->add('rooms', $label, $url, 'missing', 'The room #'.((int) $o->chamilo_room_id).' no longer exists in Chamilo.');
			} else {
				$expected = array('title' => (string) $o->ref);
				if ($o->max_users !== null && (int) $o->max_users > 0) {
					$expected['capacity'] = (int) $o->max_users;
				}
				if ($o->floor_number !== null) {
					$expected['floorNumber'] = (int) $o->floor_number;
				}
				$diff = schoolCompareFields($expected, $byId[(int) $o->chamilo_room_id]);
				if ($diff) {
					$parts = array();
					foreach ($diff as $field => $both) {
						$parts[] = $field.': Dolibarr "'.$both['expected'].'", Chamilo "'.$both['actual'].'"';
					}
					$this->add('rooms', $label, $url, 'differs', implode('; ', $parts).'.', $fix);
				}
			}
		}
	}

	private function checkDates(): void
	{
		$P = MAIN_DB_PREFIX;
		$today = $this->db->idate(dol_mktime(0, 0, 0, (int) dol_print_date(dol_now(), '%m'), (int) dol_print_date(dol_now(), '%d'), (int) dol_print_date(dol_now(), '%Y')));
		$rows = $this->rows('dates', "SELECT o.rowid, o.fk_session, o.fk_course, o.date_start, o.date_end, o.chamilo_calendar_id, c.chamilo_id AS cid, s.chamilo_id AS sid, a.chamilo_attendance_id AS att, pc.label AS course_label, ps.label AS session_label"
			." FROM {$P}school_occurrence AS o INNER JOIN {$P}school_course AS c ON c.rowid = o.fk_course LEFT JOIN {$P}product AS pc ON pc.rowid = c.fk_product"
			." LEFT JOIN {$P}school_session AS s ON s.rowid = o.fk_session AND o.fk_session > 0 LEFT JOIN {$P}product AS ps ON ps.rowid = s.fk_product"
			." LEFT JOIN {$P}school_attendance AS a ON a.fk_course = o.fk_course AND a.fk_session = o.fk_session"
			." WHERE o.entity = ".((int) $this->entity())." AND o.status = ".SchoolSchedule::ACTIVE." AND o.date_end >= '".$today."' ORDER BY o.date_start");
		$cache = array();
		foreach ($rows as $o) {
			$start = (int) $this->db->jdate($o->date_start);
			$label = ($o->session_label ? $o->session_label.' - ' : '').$o->course_label.' — '.dol_print_date($start, 'dayhour');
			$url = $this->link('schedule.php', (int) $o->fk_session > 0 ? '?session='.((int) $o->fk_session) : '?course='.((int) $o->fk_course));
			$fix = array('occurrence', (int) $o->rowid, 'update');
			if ((int) $o->chamilo_calendar_id <= 0 || (int) $o->att <= 0) {
				$this->add('dates', $label, $url, 'missing', 'Not in Chamilo yet.', $fix);
				continue;
			}
			$att = (int) $o->att;
			if (!array_key_exists($att, $cache)) {
				$ctx = array('cid' => (int) $o->cid) + ((int) $o->sid > 0 ? array('sid' => (int) $o->sid) : array());
				$res = $this->client->request('GET', '/api/attendances/'.$att, $ctx);
				$cache[$att] = $res['ok'] && is_array($res['data']) ? (array) ($res['data']['calendars'] ?? array()) : ($res['status'] === 404 ? array() : null);
			}
			if ($cache[$att] === null) {
				$this->add('dates', $label, $url, 'unreadable', 'Chamilo could not be asked for the attendance.');
				continue;
			}
			$found = null;
			foreach ($cache[$att] as $cal) {
				if (is_array($cal) && (int) ($cal['iid'] ?? 0) === (int) $o->chamilo_calendar_id) {
					$found = $cal;
				}
			}
			if ($found === null) {
				$this->add('dates', $label, $url, 'missing', 'The date no longer exists in Chamilo.', $fix);
				continue;
			}
			$minutes = (int) round(((int) $this->db->jdate($o->date_end) - $start) / 60);
			$problems = array();
			if (strtotime((string) ($found['dateTime'] ?? '')) !== $start) {
				$problems[] = 'start: Chamilo '.dol_print_date((int) strtotime((string) ($found['dateTime'] ?? '')), 'dayhour');
			}
			if ((int) ($found['duration'] ?? 0) !== $minutes) {
				$problems[] = 'duration: Dolibarr '.$minutes.' min, Chamilo '.((int) ($found['duration'] ?? 0)).' min';
			}
			if ($problems) {
				$this->add('dates', $label, $url, 'differs', implode('; ', $problems).(empty($found['doneAttendance']) ? '.' : '. Attendance was taken in Chamilo: the date will not be moved there.'), empty($found['doneAttendance']) ? $fix : null);
			}
		}
	}

	/**
	 * @return int Entity of the data
	 */
	private function entity(): int
	{
		global $conf;
		return (int) $conf->entity;
	}
}
