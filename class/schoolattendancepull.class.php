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
 * \file    class/schoolattendancepull.class.php
 * \ingroup school
 * \brief   Reads back from Chamilo who was present at the dates of the schedule.
 */

require_once __DIR__.'/../lib/schoollogic.lib.php';
require_once __DIR__.'/chamiloclient.class.php';
require_once __DIR__.'/schoolschedule.class.php';

/**
 * Chamilo owns the attendance results: teachers record them there. The module only reads them, for the dates that took
 * place, until Chamilo says the attendance of a date was taken; after that the date is not read again. Each run looks at
 * a limited number of dates, the least recently read first, so that a date Chamilo cannot answer for does not hold up the
 * others.
 */
class SchoolAttendancePull
{
	/** Minutes between two readings of the same date, while its attendance is not taken. */
	public const REREAD_MINUTES = 30;

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
	 * Read the dates that are due.
	 *
	 * @param ChamiloClient $client Client
	 * @param int           $max    Dates per run
	 * @return array{checked:int,updated:int,errors:list<string>}
	 */
	public function run(ChamiloClient $client, int $max = 20): array
	{
		global $conf;
		$P = MAIN_DB_PREFIX;
		$now = dol_now();
		$report = array('checked' => 0, 'updated' => 0, 'errors' => array());
		$sql = "SELECT rowid FROM ".$P."school_occurrence WHERE entity = ".((int) $conf->entity)
			." AND status = ".SchoolSchedule::ACTIVE." AND chamilo_calendar_id IS NOT NULL AND attendance_done = 0"
			." AND date_end <= '".$this->db->idate($now)."'"
			." AND (last_pull IS NULL OR last_pull <= '".$this->db->idate($now - self::REREAD_MINUTES * 60)."')"
			." ORDER BY last_pull IS NOT NULL, last_pull, date_end LIMIT ".max(1, $max);
		$resql = $this->db->query($sql);
		$ids = array();
		while ($resql && ($o = $this->db->fetch_object($resql))) {
			$ids[] = (int) $o->rowid;
		}
		foreach ($ids as $id) {
			$report['checked']++;
			$error = $this->pullOccurrence($client, $id);
			if ($error === '') {
				$report['updated']++;
			} else {
				$report['errors'][] = '#'.$id.': '.$error;
			}
		}
		return $report;
	}

	/**
	 * Read one date and store its presences, replacing what was stored.
	 *
	 * @param ChamiloClient $client       Client
	 * @param int           $occurrenceId School occurrence id
	 * @return string Error message, empty when done
	 */
	public function pullOccurrence(ChamiloClient $client, int $occurrenceId): string
	{
		global $conf;
		$P = MAIN_DB_PREFIX;
		$entity = (int) $conf->entity;
		$resql = $this->db->query("SELECT chamilo_calendar_id FROM ".$P."school_occurrence WHERE rowid = ".$occurrenceId." AND entity = ".$entity);
		$o = $resql ? $this->db->fetch_object($resql) : null;
		if (!$o || !$o->chamilo_calendar_id) {
			return 'not sent to Chamilo yet';
		}
		// Whatever happens, the date goes to the back of the line.
		$this->db->query("UPDATE ".$P."school_occurrence SET last_pull = '".$this->db->idate(dol_now())."' WHERE rowid = ".$occurrenceId);

		$res = $client->request('GET', '/api/c_attendance_calendars/'.((int) $o->chamilo_calendar_id).'/sheet');
		if (!$res['ok']) {
			return $res['status'] === 404 ? 'the date does not exist in Chamilo' : 'Chamilo answered: '.$res['error'];
		}
		$parsed = schoolParsePresences($res['data']);
		if ($parsed === null) {
			return 'unexpected answer from Chamilo';
		}

		$students = array();
		$ids = array_map('intval', array_keys($parsed['presences']));
		if ($ids) {
			$q = $this->db->query("SELECT rowid, chamilo_user_id FROM ".$P."school_student WHERE entity = ".$entity." AND chamilo_user_id IN (".implode(',', $ids).")");
			while ($q && ($s = $this->db->fetch_object($q))) {
				$students[(int) $s->chamilo_user_id] = (int) $s->rowid;
			}
		}

		$this->db->begin();
		$this->db->query("DELETE FROM ".$P."school_presence WHERE fk_occurrence = ".$occurrenceId);
		foreach ($parsed['presences'] as $chamiloUser => $presence) {
			if (isset($students[$chamiloUser])) {
				$this->db->query("INSERT INTO ".$P."school_presence (entity, fk_occurrence, fk_student, presence) VALUES (".$entity.", ".$occurrenceId.", ".$students[$chamiloUser].", ".((int) $presence).")");
			}
		}
		$this->db->query("UPDATE ".$P."school_occurrence SET attendance_done = ".($parsed['done'] ? 1 : 0)." WHERE rowid = ".$occurrenceId);
		$this->db->commit();
		return '';
	}
}
