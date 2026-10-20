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
 * \file    class/mcp/schoolscheduletool.class.php
 * \ingroup school
 * \brief   MCP tools for rooms and the dates of sessions and courses.
 */

require_once __DIR__.'/schoolmcptool.class.php';
require_once __DIR__.'/../schoolroom.class.php';
require_once __DIR__.'/../schoolschedule.class.php';

class SchoolScheduleTool extends SchoolMcpTool
{
	/**
	 * @return array<string,array<string,mixed>>
	 */
	protected function specs(): array
	{
		$target = array('session_id' => array('type' => 'integer', 'description' => 'School session id. Give it for a course inside a session.'), 'course_id' => array('type' => 'integer', 'description' => 'School course id (required with a session: the course of that session)'));
		return array(
			'list_school_rooms' => array(
				'desc' => 'List the rooms (resources of the Resources module used for lessons) with capacity, floor and Chamilo state. With available=true, list instead the resources that are not rooms yet.',
				'props' => array('available' => array('type' => 'boolean')),
				'right' => 'read',
			),
			'add_school_room' => array(
				'desc' => 'Use a Resources-module resource as a room. capacity is stored as the resource\'s max users. Queued for Chamilo.',
				'props' => array('resource_id' => array('type' => 'integer'), 'capacity' => array('type' => 'integer'), 'floor' => array('type' => 'integer')),
				'required' => array('resource_id'),
				'right' => 'write', 'write' => true,
				'preview' => 'Use the resource {resource_id} as a school room.',
			),
			'update_school_room' => array(
				'desc' => 'Change the capacity and floor of a room. Omitted fields are kept.',
				'props' => array('room_id' => array('type' => 'integer'), 'capacity' => array('type' => 'integer'), 'floor' => array('type' => 'integer')),
				'required' => array('room_id'),
				'right' => 'write', 'write' => true,
				'preview' => 'Change the room {room_id}.',
			),
			'update_school_rooms_from_chamilo' => array(
				'desc' => 'Read the rooms of Chamilo and create or link the matching resources in Dolibarr (matched by name).',
				'props' => array(),
				'right' => 'sync', 'write' => true,
				'preview' => 'Import the rooms of Chamilo.',
			),
			'list_school_dates' => array(
				'desc' => 'List the dates (lesson occurrences) of a session\'s courses (session_id) or of a course on its own (course_id), or, without either, the dates to come, optionally for one room. Includes the room, the synchronization state and the attendance totals read back from Chamilo.',
				'props' => array('session_id' => array('type' => 'integer'), 'course_id' => array('type' => 'integer'), 'room_id' => array('type' => 'integer'), 'limit' => array('type' => 'integer')),
				'right' => 'read',
			),
			'create_school_dates' => array(
				'desc' => 'Add one date or a series for a course (in a session, or on its own). Dates are generated from first_day to last_day on the given weekdays (1 Monday .. 7 Sunday; none means the weekday of first_day) every N weeks. Each date books the room and the teachers in the agenda, and becomes an attendance date in Chamilo. A room that is busy refuses the dates, unless skip_busy is true, which skips them and reports them.',
				'props' => $target + array(
					'first_day' => array('type' => 'string', 'description' => 'YYYY-MM-DD'),
					'last_day' => array('type' => 'string', 'description' => 'YYYY-MM-DD, empty for a single date'),
					'weekdays' => array('type' => 'array', 'items' => array('type' => 'integer')),
					'every_weeks' => array('type' => 'integer'),
					'start_time' => array('type' => 'string', 'description' => 'HH:MM'),
					'minutes' => array('type' => 'integer', 'description' => 'Duration, 5 to 720'),
					'room_id' => array('type' => 'integer', 'description' => 'School room id (optional)'),
					'skip_busy' => array('type' => 'boolean'),
				),
				'required' => array('course_id', 'first_day', 'start_time', 'minutes'),
				'right' => 'write', 'write' => true,
				'preview' => 'Add dates from {first_day} to {last_day} at {start_time} for the course {course_id}.',
			),
			'update_school_date' => array(
				'desc' => 'Move a date or change its length or room. A date whose attendance was already taken in Chamilo is not moved there: only its room changes.',
				'props' => array('date_id' => array('type' => 'integer'), 'day' => array('type' => 'string', 'description' => 'YYYY-MM-DD'), 'start_time' => array('type' => 'string', 'description' => 'HH:MM'), 'minutes' => array('type' => 'integer'), 'room_id' => array('type' => 'integer', 'description' => '0 for no room')),
				'required' => array('date_id', 'day', 'start_time', 'minutes'),
				'right' => 'write', 'write' => true,
				'preview' => 'Change the date {date_id} to {day} {start_time}.',
			),
			'delete_school_date' => array(
				'desc' => 'Cancel a date: it is removed from the agenda, frees the room and is removed from Chamilo unless its attendance was already taken there.',
				'props' => array('date_id' => array('type' => 'integer')),
				'required' => array('date_id'),
				'right' => 'write', 'write' => true,
				'preview' => 'Cancel the date {date_id}.',
			),
		);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_list_school_rooms(array $args): array
	{
		$P = MAIN_DB_PREFIX;
		$rows = array();
		if ($this->argBool($args, 'available')) {
			foreach ($this->rows("SELECT x.rowid, x.ref, x.max_users FROM {$P}resource AS x WHERE x.entity IN (".getEntity('resource').") AND x.rowid NOT IN (SELECT fk_resource FROM {$P}school_room WHERE entity = ".$this->entity().") ORDER BY x.ref LIMIT ".$this->limit($args)) as $o) {
				$rows[] = array('resource_id' => (int) $o->rowid, 'ref' => (string) $o->ref, 'max_users' => $o->max_users !== null ? (int) $o->max_users : null);
			}
			return $rows;
		}
		foreach ($this->rows("SELECT r.rowid, r.fk_resource, r.floor_number, r.chamilo_room_id, r.sync_status, r.last_error, x.ref, x.description, x.max_users FROM {$P}school_room AS r INNER JOIN {$P}resource AS x ON x.rowid = r.fk_resource WHERE r.entity = ".$this->entity()." ORDER BY x.ref LIMIT ".$this->limit($args)) as $o) {
			$rows[] = array('id' => (int) $o->rowid, 'resource_id' => (int) $o->fk_resource, 'ref' => (string) $o->ref, 'description' => (string) $o->description, 'capacity' => $o->max_users !== null ? (int) $o->max_users : null,
				'floor' => $o->floor_number !== null ? (int) $o->floor_number : null, 'chamilo_room_id' => $o->chamilo_room_id !== null ? (int) $o->chamilo_room_id : null, 'sync_status' => (int) $o->sync_status, 'last_error' => $o->last_error);
		}
		return $rows;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 */
	private function optionalInt(array $args, string $key): ?int
	{
		return array_key_exists($key, $args) && $args[$key] !== null && $args[$key] !== '' ? $this->argInt($args, $key) : null;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_add_school_room(array $args): array
	{
		$res = (new SchoolRoom($this->db))->adopt($this->argInt($args, 'resource_id'), $this->optionalInt($args, 'capacity'), $this->optionalInt($args, 'floor'));
		return $res['error'] === '' ? $this->done(array('room_id' => $res['id'], 'queued' => true)) : $this->fail($res['error']);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_update_school_room(array $args): array
	{
		$id = $this->argInt($args, 'room_id');
		$cur = $this->rows("SELECT r.floor_number, x.max_users FROM ".MAIN_DB_PREFIX."school_room AS r INNER JOIN ".MAIN_DB_PREFIX."resource AS x ON x.rowid = r.fk_resource WHERE r.rowid = ".$id." AND r.entity = ".$this->entity());
		if (!$cur) {
			return $this->fail('SchoolErrNotFound');
		}
		$capacity = array_key_exists('capacity', $args) ? $this->optionalInt($args, 'capacity') : ($cur[0]->max_users !== null ? (int) $cur[0]->max_users : null);
		$floor = array_key_exists('floor', $args) ? $this->optionalInt($args, 'floor') : ($cur[0]->floor_number !== null ? (int) $cur[0]->floor_number : null);
		$error = (new SchoolRoom($this->db))->update($id, $capacity, $floor);
		return $error === '' ? $this->done(array('room_id' => $id, 'queued' => true)) : $this->fail($error);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_update_school_rooms_from_chamilo(array $args): array
	{
		$client = schoolGetClient();
		if ($client === null) {
			return $this->fail('SchoolNotConfigured');
		}
		return $this->done((new SchoolRoom($this->db))->importFromChamilo($client));
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_list_school_dates(array $args): array
	{
		$P = MAIN_DB_PREFIX;
		$session = $this->argInt($args, 'session_id');
		$course = $this->argInt($args, 'course_id');
		$room = $this->argInt($args, 'room_id');
		if ($session > 0) {
			$where = "o.fk_session = ".$session;
		} elseif ($course > 0) {
			$where = "o.fk_session = 0 AND o.fk_course = ".$course;
		} else {
			$where = "o.status = ".SchoolSchedule::ACTIVE." AND o.date_start >= '".$this->db->idate(dol_now() - 86400)."'".($room > 0 ? " AND o.fk_room = ".$room : '');
		}
		$rows = array();
		foreach ($this->rows("SELECT o.rowid, o.fk_session, o.fk_course, o.fk_room, o.date_start, o.date_end, o.status, o.sync_status, o.last_error, o.attendance_done, pc.label AS course_label, ps.label AS session_label, x.ref AS room_ref,"
			." (SELECT COUNT(*) FROM {$P}school_presence AS pr WHERE pr.fk_occurrence = o.rowid AND pr.presence = 1) AS n_present,"
			." (SELECT COUNT(*) FROM {$P}school_presence AS pr WHERE pr.fk_occurrence = o.rowid AND pr.presence IN (2, 3)) AS n_late,"
			." (SELECT COUNT(*) FROM {$P}school_presence AS pr WHERE pr.fk_occurrence = o.rowid AND pr.presence IN (0, 4)) AS n_absent"
			." FROM {$P}school_occurrence AS o INNER JOIN {$P}school_course AS c ON c.rowid = o.fk_course LEFT JOIN {$P}product AS pc ON pc.rowid = c.fk_product"
			." LEFT JOIN {$P}school_session AS s ON s.rowid = o.fk_session AND o.fk_session > 0 LEFT JOIN {$P}product AS ps ON ps.rowid = s.fk_product"
			." LEFT JOIN {$P}school_room AS r ON r.rowid = o.fk_room LEFT JOIN {$P}resource AS x ON x.rowid = r.fk_resource"
			." WHERE o.entity = ".$this->entity()." AND ".$where." ORDER BY (o.status = ".SchoolSchedule::TO_REMOVE."), o.date_start LIMIT ".$this->limit($args)) as $o) {
			$rows[] = array('id' => (int) $o->rowid, 'session_id' => (int) $o->fk_session ?: null, 'session' => $o->session_label, 'course_id' => (int) $o->fk_course, 'course' => (string) $o->course_label,
				'room_id' => $o->fk_room ? (int) $o->fk_room : null, 'room' => $o->room_ref, 'start' => $this->iso($o->date_start), 'end' => $this->iso($o->date_end),
				'state' => (int) $o->status === SchoolSchedule::TO_REMOVE ? 'cancelled, being removed from Chamilo' : 'planned', 'sync_status' => (int) $o->sync_status, 'last_error' => $o->last_error,
				'attendance_final' => (int) $o->attendance_done === 1, 'present' => (int) $o->n_present, 'late' => (int) $o->n_late, 'absent' => (int) $o->n_absent);
		}
		return $rows;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_create_school_dates(array $args): array
	{
		$first = $this->argDay($args, 'first_day');
		if ($first === null) {
			return $this->fail('first_day must be YYYY-MM-DD');
		}
		$until = $this->argString($args, 'last_day') === '' ? $first : $this->argDay($args, 'last_day');
		if ($until === null) {
			return $this->fail('last_day must be YYYY-MM-DD');
		}
		$dates = schoolRecurringDates($first, $until, $this->argIds($args, 'weekdays'), max(1, $this->argInt($args, 'every_weeks')));
		$res = (new SchoolSchedule($this->db))->createSeries($this->argInt($args, 'session_id'), $this->argInt($args, 'course_id'), $dates, $this->argString($args, 'start_time'), $this->argInt($args, 'minutes'), $this->argInt($args, 'room_id'), $this->argBool($args, 'skip_busy'));
		if ($res['error'] !== '') {
			$out = array('error' => $this->fail($res['error'])['error']);
			if (!empty($res['skipped'])) {
				$out['conflicts'] = $this->skipped($res['skipped']);
			}
			return $out;
		}
		return $this->done(array('created_ids' => $res['created'], 'skipped' => $this->skipped($res['skipped']), 'teacher_warnings' => array_values(array_unique((array) ($res['warnings'] ?? array())))));
	}

	/**
	 * @param list<array{date:string,conflicts:list<array<string,mixed>>}> $skipped Skipped dates
	 * @return list<array<string,mixed>>
	 */
	private function skipped(array $skipped): array
	{
		$out = array();
		foreach ($skipped as $s) {
			$busy = array();
			foreach ($s['conflicts'] as $c) {
				$busy[] = (string) $c['label'].' '.$this->iso((int) $c['start']).' - '.$this->iso((int) $c['end']);
			}
			$out[] = array('date' => $s['date'], 'room_busy_with' => $busy);
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_update_school_date(array $args): array
	{
		$day = $this->argDay($args, 'day');
		if ($day === null) {
			return $this->fail('day must be YYYY-MM-DD');
		}
		$id = $this->argInt($args, 'date_id');
		if (!$this->rows("SELECT rowid FROM ".MAIN_DB_PREFIX."school_occurrence WHERE rowid = ".$id." AND entity = ".$this->entity())) {
			return $this->fail('SchoolErrNotFound');
		}
		$res = (new SchoolSchedule($this->db))->update($id, $day, $this->argString($args, 'start_time'), $this->argInt($args, 'minutes'), $this->argInt($args, 'room_id'));
		if ($res['error'] !== '') {
			$out = $this->fail($res['error']);
			if ($res['conflicts']) {
				$out['room_busy_with'] = array_map(function (array $c): string {
					return (string) $c['label'].' '.$this->iso((int) $c['start']).' - '.$this->iso((int) $c['end']);
				}, $res['conflicts']);
			}
			return $out;
		}
		return $this->done(array('date_id' => $id, 'queued' => true));
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_delete_school_date(array $args): array
	{
		$id = $this->argInt($args, 'date_id');
		if (!$this->rows("SELECT rowid FROM ".MAIN_DB_PREFIX."school_occurrence WHERE rowid = ".$id." AND entity = ".$this->entity())) {
			return $this->fail('SchoolErrNotFound');
		}
		$error = (new SchoolSchedule($this->db))->delete($id);
		return $error === '' ? $this->done(array('date_id' => $id, 'cancelled' => true)) : $this->fail($error);
	}
}
