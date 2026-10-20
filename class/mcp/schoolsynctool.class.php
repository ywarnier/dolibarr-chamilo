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
 * \file    class/mcp/schoolsynctool.class.php
 * \ingroup school
 * \brief   MCP tools for the synchronization with Chamilo: the queue, the log and the reconciliation report.
 */

require_once __DIR__.'/schoolmcptool.class.php';
require_once __DIR__.'/../schoolsynclog.class.php';
require_once __DIR__.'/../schoolcron.class.php';
require_once __DIR__.'/../schoolreconcile.class.php';

class SchoolSyncTool extends SchoolMcpTool
{
	/**
	 * @return array<string,array<string,mixed>>
	 */
	protected function specs(): array
	{
		return array(
			'get_school_sync_status' => array(
				'desc' => 'How many synchronization jobs are waiting, running, failed or done, and whether the Chamilo connection is configured.',
				'props' => array(),
				'right' => 'read',
			),
			'list_school_sync_jobs' => array(
				'desc' => 'The synchronization log: jobs sent to Chamilo, newest first, with what each is about, status, attempts, next try and error message. Filter by status (waiting, running, failed, done) and by type.',
				'props' => array(
					'status' => array('type' => 'string', 'enum' => array('waiting', 'running', 'failed', 'done')),
					'type' => array('type' => 'string', 'enum' => SchoolSyncLog::types()),
					'limit' => array('type' => 'integer'),
					'offset' => array('type' => 'integer'),
				),
				'right' => 'read',
			),
			'change_school_sync_jobs_to_waiting' => array(
				'desc' => 'Put a failed (or already failed waiting) job back in line, or every failed job with all_failed=true. Running and done jobs are left alone.',
				'props' => array('job_id' => array('type' => 'integer'), 'all_failed' => array('type' => 'boolean')),
				'right' => 'sync', 'write' => true,
				'preview' => 'Try the failed synchronization job(s) again.',
			),
			'delete_school_sync_job' => array(
				'desc' => 'Forget a failed job. The object keeps its error and is sent again when it changes.',
				'props' => array('job_id' => array('type' => 'integer')),
				'required' => array('job_id'),
				'right' => 'sync', 'write' => true,
				'preview' => 'Forget the failed synchronization job {job_id}.',
			),
			'send_school_changes_to_chamilo' => array(
				'desc' => 'Run the synchronization now: send the due jobs to Chamilo and read back attendance. Jobs that wait for a retry are not forced.',
				'props' => array(),
				'right' => 'sync', 'write' => true,
				'preview' => 'Run the synchronization with Chamilo now.',
			),
			'check_school_reconciliation' => array(
				'desc' => 'Compare Dolibarr with Chamilo and list the differences, changing nothing. areas: courses, sessions, students, teachers, enrolments, classes, rooms, dates (default: all). Each finding says whether Chamilo is missing it, differs, or has it alone, and carries fix = "type:id:action" when sending it again would fix it. It asks Chamilo about each object, so choose the areas on a big school; each area is limited to 300 objects.',
				'props' => array('areas' => array('type' => 'array', 'items' => array('type' => 'string', 'enum' => SchoolReconcile::areas()))),
				'right' => 'sync',
			),
			'create_school_sync_jobs_from_reconciliation' => array(
				'desc' => 'Queue the jobs that check_school_reconciliation proposed, by their fix value ("type:id:action"). Only those fixes are accepted.',
				'props' => array('fixes' => array('type' => 'array', 'items' => array('type' => 'string'))),
				'required' => array('fixes'),
				'right' => 'sync', 'write' => true,
				'preview' => 'Queue the proposed reconciliation jobs.',
			),
		);
	}

	/**
	 * @return array<string,int> Status word => value
	 */
	private function statuses(): array
	{
		return array('waiting' => SyncQueue::PENDING, 'running' => SyncQueue::RUNNING, 'failed' => SyncQueue::FAILED, 'done' => SyncQueue::DONE);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_get_school_sync_status(array $args): array
	{
		$counts = (new SyncQueue($this->db))->countByStatus();
		return array(array('configured' => schoolGetClient() !== null, 'waiting' => $counts[SyncQueue::PENDING], 'running' => $counts[SyncQueue::RUNNING], 'failed' => $counts[SyncQueue::FAILED], 'done' => $counts[SyncQueue::DONE]));
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_list_school_sync_jobs(array $args): array
	{
		$word = $this->argString($args, 'status');
		$status = $word !== '' && isset($this->statuses()[$word]) ? $this->statuses()[$word] : -1;
		$log = (new SchoolSyncLog($this->db))->list($status, $this->argString($args, 'type'), $this->limit($args), max(0, $this->argInt($args, 'offset')));
		$names = array_flip($this->statuses());
		$rows = array();
		foreach ($log['rows'] as $r) {
			$rows[] = array('id' => $r['id'], 'type' => $r['type'], 'action' => $r['action'], 'object_id' => $r['object_id'], 'about' => $r['label'], 'status' => $names[$r['status']] ?? (string) $r['status'], 'attempts' => $r['attempts'],
				'next_attempt' => $r['status'] === SyncQueue::PENDING ? $this->iso($r['next_attempt']) : null, 'changed' => $this->iso($r['changed']), 'error' => $r['error'] !== '' ? $r['error'] : null);
		}
		return array(array('total' => $log['total'], 'jobs' => $rows));
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_change_school_sync_jobs_to_waiting(array $args): array
	{
		$queue = new SyncQueue($this->db);
		if ($this->argBool($args, 'all_failed')) {
			return $this->done(array('retried' => $queue->retryAllFailed()));
		}
		$id = $this->argInt($args, 'job_id');
		if ($id <= 0) {
			return $this->fail('Give job_id or all_failed=true');
		}
		return $queue->retry($id) ? $this->done(array('retried' => 1)) : $this->fail('SchoolJobNotRetried');
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_delete_school_sync_job(array $args): array
	{
		return (new SyncQueue($this->db))->dismiss($this->argInt($args, 'job_id')) ? $this->done(array('dismissed' => true)) : $this->fail('SchoolJobNotDismissed');
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_send_school_changes_to_chamilo(array $args): array
	{
		if (schoolGetClient() === null) {
			return $this->fail('SchoolNotConfigured');
		}
		$cron = new SchoolCron($this->db);
		$cron->runSync();
		return $this->done(array('result' => $cron->output));
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_check_school_reconciliation(array $args): array
	{
		$client = schoolGetClient();
		if ($client === null) {
			return $this->fail('SchoolNotConfigured');
		}
		$asked = array_map('strval', (array) ($args['areas'] ?? array()));
		$areas = $asked ? array_values(array_intersect(SchoolReconcile::areas(), $asked)) : SchoolReconcile::areas();
		if (!$areas) {
			return $this->fail('Unknown area: choose among '.implode(', ', SchoolReconcile::areas()));
		}
		$report = (new SchoolReconcile($this->db, $client))->run($areas);
		$findings = array();
		foreach ($report['findings'] as $f) {
			$findings[] = array('area' => $f['area'], 'about' => $f['label'], 'kind' => $f['kind'], 'detail' => $f['detail'], 'fix' => $f['fix'] ? implode(':', $f['fix']) : null);
		}
		return array(array('checked' => $report['checked'], 'truncated' => $report['truncated'], 'unmanaged' => $report['unmanaged'], 'findings' => $findings));
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_create_school_sync_jobs_from_reconciliation(array $args): array
	{
		$res = SchoolReconcile::queueFixes($this->db, array_values(array_filter((array) ($args['fixes'] ?? array()), 'is_scalar')));
		$queued = $res['queued'];
		$refused = $res['refused'];
		return $this->done(array('queued' => $queued, 'refused' => $refused));
	}
}
