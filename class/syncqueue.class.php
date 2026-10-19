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
 * \file    class/syncqueue.class.php
 * \ingroup school
 * \brief   Outgoing sync jobs with retry and backoff.
 */

require_once __DIR__.'/../lib/schoollogic.lib.php';

/**
 * Queue of pushes to Chamilo. A job is identified by object type, object id and action, so enqueueing
 * the same change twice before it runs keeps one job.
 */
class SyncQueue
{
	public const PENDING = 0;
	public const DONE = 1;
	public const FAILED = 2;
	public const RUNNING = 3;

	/** Attempts before a retryable failure becomes final. */
	public const MAX_ATTEMPTS = 8;
	/** Minutes after which a job left running (crashed cron) goes back to pending. */
	public const STUCK_MINUTES = 15;

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
	 * Add a job unless the same one is already waiting.
	 *
	 * @param string $type   course, session, student, parent, enrolment, occurrence
	 * @param int    $id     Id in the School table
	 * @param string $action create, update or delete
	 * @return int Job id, <0 on error
	 */
	public function enqueue(string $type, int $id, string $action): int
	{
		global $conf;
		$entity = (int) $conf->entity;

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."school_syncqueue WHERE entity = ".$entity
			." AND object_type = '".$this->db->escape($type)."' AND object_id = ".$id
			." AND action = '".$this->db->escape($action)."' AND status = ".self::PENDING;
		$resql = $this->db->query($sql);
		if (!$resql) {
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		if ($obj) {
			return (int) $obj->rowid;
		}

		$now = $this->db->idate(dol_now());
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."school_syncqueue (entity, object_type, object_id, action, status, attempts, next_attempt, datec)"
			." VALUES (".$entity.", '".$this->db->escape($type)."', ".$id.", '".$this->db->escape($action)."', ".self::PENDING.", 0, '".$now."', '".$now."')";
		if (!$this->db->query($sql)) {
			return -1;
		}
		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'school_syncqueue');
	}

	/**
	 * Put jobs left running by a crashed run back in line.
	 *
	 * @return int Number of jobs released
	 */
	public function releaseStuck(): int
	{
		$limit = $this->db->idate(dol_now() - self::STUCK_MINUTES * 60);
		$sql = "UPDATE ".MAIN_DB_PREFIX."school_syncqueue SET status = ".self::PENDING
			." WHERE status = ".self::RUNNING." AND tms < '".$limit."'";
		$resql = $this->db->query($sql);
		return $resql ? (int) $this->db->affected_rows($resql) : 0;
	}

	/**
	 * Claim up to $limit due jobs. A job is claimed with a conditional update, so two overlapping runs never take the same one.
	 *
	 * @param int $limit Maximum number of jobs
	 * @return array<int,array<string,mixed>> Claimed jobs
	 */
	public function claimDue(int $limit): array
	{
		global $conf;
		$jobs = array();
		$now = $this->db->idate(dol_now());
		$sql = "SELECT rowid, object_type, object_id, action, attempts FROM ".MAIN_DB_PREFIX."school_syncqueue"
			." WHERE entity = ".((int) $conf->entity)." AND status = ".self::PENDING." AND next_attempt <= '".$now."'"
			." ORDER BY rowid ASC".$this->db->plimit($limit);
		$resql = $this->db->query($sql);
		if (!$resql) {
			return $jobs;
		}
		$candidates = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$candidates[] = $obj;
		}
		foreach ($candidates as $obj) {
			$upd = "UPDATE ".MAIN_DB_PREFIX."school_syncqueue SET status = ".self::RUNNING
				." WHERE rowid = ".((int) $obj->rowid)." AND status = ".self::PENDING;
			$res = $this->db->query($upd);
			if ($res && $this->db->affected_rows($res) > 0) {
				$jobs[] = array(
					'id' => (int) $obj->rowid,
					'type' => (string) $obj->object_type,
					'object_id' => (int) $obj->object_id,
					'action' => (string) $obj->action,
					'attempts' => (int) $obj->attempts,
				);
			}
		}
		return $jobs;
	}

	/**
	 * @param int    $jobId Job id
	 * @param string $hash  Hash of the payload that was pushed
	 */
	public function markDone(int $jobId, string $hash = ''): void
	{
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."school_syncqueue SET status = ".self::DONE.", last_error = NULL, payload_hash = '".$this->db->escape($hash)."' WHERE rowid = ".$jobId);
	}

	/**
	 * Record a failure. A retryable one is rescheduled with backoff until MAX_ATTEMPTS, any other is final.
	 *
	 * @param int    $jobId     Job id
	 * @param int    $attempts  Attempts made before this one
	 * @param string $error     Message kept for the sync log
	 * @param bool   $retryable Whether another attempt can succeed
	 * @return bool True when the job will be retried
	 */
	public function markFailed(int $jobId, int $attempts, string $error, bool $retryable): bool
	{
		$attempts++;
		$retry = $retryable && $attempts < self::MAX_ATTEMPTS;
		$status = $retry ? self::PENDING : self::FAILED;
		$next = $this->db->idate(dol_now() + schoolBackoffSeconds($attempts));
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."school_syncqueue SET status = ".$status.", attempts = ".$attempts
			.", next_attempt = '".$next."', last_error = '".$this->db->escape(dol_substr($error, 0, 2000))."' WHERE rowid = ".$jobId);
		return $retry;
	}

	/**
	 * Number of jobs per status, for the home page.
	 *
	 * @return array<int,int> status => count
	 */
	public function countByStatus(): array
	{
		global $conf;
		$counts = array(self::PENDING => 0, self::DONE => 0, self::FAILED => 0, self::RUNNING => 0);
		$sql = "SELECT status, COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."school_syncqueue WHERE entity = ".((int) $conf->entity)." GROUP BY status";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$counts[(int) $obj->status] = (int) $obj->nb;
			}
		}
		return $counts;
	}
}
