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
 * \file    class/schoolcron.class.php
 * \ingroup school
 * \brief   Scheduled job that drains the Chamilo sync queue.
 */

require_once __DIR__.'/../lib/school.lib.php';
require_once __DIR__.'/syncqueue.class.php';
require_once __DIR__.'/schoolsessionsync.class.php';
require_once __DIR__.'/schoolteachersync.class.php';

/**
 * Cron entry points. Dolibarr instantiates the class with $db, calls the method and reads $output and $error.
 */
class SchoolCron
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $output = '';
	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Push due jobs to Chamilo.
	 *
	 * @param int $maxJobs Jobs per run
	 * @return int 0 if OK, <0 if the run could not start
	 */
	public function runSync($maxJobs = 50): int
	{
		$client = schoolGetClient();
		if ($client === null) {
			$this->output = 'School sync skipped: Chamilo URL or API key not set';
			return 0;
		}

		$queue = new SyncQueue($this->db);
		$queue->releaseStuck();

		$done = 0;
		$retried = 0;
		$failed = 0;
		foreach ($queue->claimDue((int) $maxJobs) as $job) {
			$result = $this->dispatch($client, $job);
			if ($result['ok']) {
				$queue->markDone($job['id'], $result['hash']);
				$done++;
			} elseif ($queue->markFailed($job['id'], $job['attempts'], $result['error'], $result['retryable'])) {
				$retried++;
			} else {
				$failed++;
			}
		}

		$this->output = 'School sync: '.$done.' done, '.$retried.' to retry, '.$failed.' failed';
		return 0;
	}

	/**
	 * Run one job. Handlers for each object type are added with their phase; an unknown type fails for good
	 * so it shows in the log instead of looping.
	 *
	 * @param ChamiloClient       $client Client
	 * @param array<string,mixed> $job    Job from SyncQueue::claimDue
	 * @return array{ok:bool,error:string,retryable:bool,hash:string}
	 */
	private function dispatch(ChamiloClient $client, array $job): array
	{
		global $user;
		if ($job['type'] === 'session' && in_array($job['action'], array('create', 'update'), true)) {
			return (new SchoolSessionSync($this->db, $user))->push($client, (int) $job['object_id']);
		}
		if ($job['type'] === 'teacher' && in_array($job['action'], array('create', 'update'), true)) {
			return (new SchoolTeacherSync($this->db))->push($client, (int) $job['object_id']);
		}
		if ($job['type'] === 'course_teachers' || $job['type'] === 'session_teachers') {
			return (new SchoolTeacherSync($this->db))->pushAssignments($client, $job['type'] === 'course_teachers' ? 'course' : 'session', (int) $job['object_id']);
		}
		return array('ok' => false, 'error' => 'No handler for '.$job['type'].'/'.$job['action'], 'retryable' => false, 'hash' => '');
	}
}
