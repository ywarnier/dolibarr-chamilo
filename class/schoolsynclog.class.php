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
 * \file    class/schoolsynclog.class.php
 * \ingroup school
 * \brief   Reads the synchronization queue for the log screen: the jobs, and what each of them is about.
 */

require_once __DIR__.'/syncqueue.class.php';

class SchoolSyncLog
{
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
	 * Job types that can be in the queue.
	 *
	 * @return list<string>
	 */
	public static function types(): array
	{
		return array('session', 'teacher', 'course_teachers', 'session_teachers', 'student', 'enrolment', 'class', 'room', 'occurrence');
	}

	/**
	 * The jobs of the queue, newest first, with a readable name for what each one is about.
	 *
	 * @param int    $status Status to keep, -1 for all
	 * @param string $type   Job type to keep, empty for all
	 * @param int    $limit  Page size
	 * @param int    $offset First row
	 * @return array{total:int,rows:list<array<string,mixed>>}
	 */
	public function list(int $status, string $type, int $limit, int $offset): array
	{
		global $conf;
		$P = MAIN_DB_PREFIX;
		$where = "q.entity = ".((int) $conf->entity);
		if ($status >= 0) {
			$where .= " AND q.status = ".$status;
		}
		if ($type !== '' && in_array($type, self::types(), true)) {
			$where .= " AND q.object_type = '".$this->db->escape($type)."'";
		}
		$q = $this->db->query("SELECT COUNT(*) AS n FROM {$P}school_syncqueue AS q WHERE ".$where);
		$total = $q && ($o = $this->db->fetch_object($q)) ? (int) $o->n : 0;

		$rows = array();
		$q = $this->db->query("SELECT q.rowid, q.object_type, q.object_id, q.action, q.status, q.attempts, q.next_attempt, q.last_error, q.datec, UNIX_TIMESTAMP(q.tms) AS changed FROM {$P}school_syncqueue AS q WHERE ".$where
			." ORDER BY q.rowid DESC".$this->db->plimit(max(1, $limit), max(0, $offset)));
		while ($q && ($o = $this->db->fetch_object($q))) {
			$about = $this->about((string) $o->object_type, (int) $o->object_id);
			$rows[] = array(
				'id' => (int) $o->rowid,
				'type' => (string) $o->object_type,
				'object_id' => (int) $o->object_id,
				'action' => (string) $o->action,
				'status' => (int) $o->status,
				'attempts' => (int) $o->attempts,
				'next_attempt' => (int) $this->db->jdate($o->next_attempt),
				'error' => (string) $o->last_error,
				'created' => (int) $this->db->jdate($o->datec),
				'changed' => (int) $o->changed,
				'label' => $about['label'],
				'url' => $about['url'],
			);
		}
		return array('total' => $total, 'rows' => $rows);
	}

	/**
	 * What a job is about, in words, and a page that shows it. An object that no longer exists is said so.
	 *
	 * @param string $type Job type
	 * @param int    $id   Id in the School table
	 * @return array{label:string,url:string}
	 */
	public function about(string $type, int $id): array
	{
		$P = MAIN_DB_PREFIX;
		$base = static function (string $page, string $query = '') {
			return dol_buildpath('/school/'.$page, 1).$query;
		};
		$one = function (string $sql): ?object {
			$r = $this->db->query($sql);
			$o = $r ? $this->db->fetch_object($r) : null;
			return $o ?: null;
		};
		$gone = array('label' => '#'.$id, 'url' => '');
		switch ($type) {
			case 'session':
			case 'session_teachers':
				$o = $one("SELECT p.label FROM {$P}school_session AS s INNER JOIN {$P}product AS p ON p.rowid = s.fk_product WHERE s.rowid = ".$id);
				return $o ? array('label' => (string) $o->label, 'url' => $base('schedule.php', '?session='.$id)) : $gone;
			case 'course_teachers':
				$o = $one("SELECT p.label FROM {$P}school_course AS c INNER JOIN {$P}product AS p ON p.rowid = c.fk_product WHERE c.rowid = ".$id);
				return $o ? array('label' => (string) $o->label, 'url' => $base('schedule.php', '?course='.$id)) : $gone;
			case 'student':
				$o = $one("SELECT firstname, lastname FROM {$P}school_student WHERE rowid = ".$id);
				return $o ? array('label' => trim($o->firstname.' '.$o->lastname), 'url' => $base('student_card.php', '?id='.$id)) : $gone;
			case 'enrolment':
				$o = $one("SELECT st.rowid AS sid, st.firstname, st.lastname, ps.label AS session_label, pc.label AS course_label FROM {$P}school_enrolment AS e INNER JOIN {$P}school_student AS st ON st.rowid = e.fk_student"
					." LEFT JOIN {$P}school_session AS s ON s.rowid = e.fk_session LEFT JOIN {$P}product AS ps ON ps.rowid = s.fk_product"
					." LEFT JOIN {$P}school_course AS c ON c.rowid = e.fk_course LEFT JOIN {$P}product AS pc ON pc.rowid = c.fk_product WHERE e.rowid = ".$id);
				return $o ? array('label' => trim($o->firstname.' '.$o->lastname).' → '.($o->session_label ?: $o->course_label), 'url' => $base('student_card.php', '?id='.((int) $o->sid))) : $gone;
			case 'class':
				$o = $one("SELECT label FROM {$P}school_class WHERE rowid = ".$id);
				return $o ? array('label' => (string) $o->label, 'url' => $base('class_card.php', '?id='.$id)) : $gone;
			case 'teacher':
				$o = $one("SELECT u.firstname, u.lastname FROM {$P}school_teacher AS t INNER JOIN {$P}user AS u ON u.rowid = t.fk_user WHERE t.rowid = ".$id);
				return $o ? array('label' => trim($o->firstname.' '.$o->lastname), 'url' => $base('teacher_list.php')) : $gone;
			case 'room':
				$o = $one("SELECT x.ref FROM {$P}school_room AS r INNER JOIN {$P}resource AS x ON x.rowid = r.fk_resource WHERE r.rowid = ".$id);
				return $o ? array('label' => (string) $o->ref, 'url' => $base('room_list.php')) : $gone;
			case 'occurrence':
				$o = $one("SELECT o.fk_session, o.fk_course, o.date_start, pc.label AS course_label FROM {$P}school_occurrence AS o INNER JOIN {$P}school_course AS c ON c.rowid = o.fk_course INNER JOIN {$P}product AS pc ON pc.rowid = c.fk_product WHERE o.rowid = ".$id);
				if (!$o) {
					return $gone;
				}
				return array('label' => $o->course_label.' — '.dol_print_date($this->db->jdate($o->date_start), 'dayhour'), 'url' => $base('schedule.php', (int) $o->fk_session > 0 ? '?session='.((int) $o->fk_session) : '?course='.((int) $o->fk_course)));
		}
		return $gone;
	}
}
