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
 * \file    class/schoolclasssync.class.php
 * \ingroup school
 * \brief   Classes: what is sent to Chamilo.
 */

require_once __DIR__.'/schoolclass.class.php';
require_once __DIR__.'/chamiloclient.class.php';

/**
 * Makes the Chamilo class (a user group of the "normal class" type) match the Dolibarr class: its name, the sessions it
 * follows, and its current members. The lists are replaced as a whole, which is how Chamilo's class routes work, so a
 * student who left is taken out and, because Chamilo enrols the members of a class in the sessions linked to it, is
 * unsubscribed from them. A student enrolled on their own in one of those sessions is unsubscribed as well.
 */
class SchoolClassSync
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
	 * Create or update the Chamilo class. Safe to repeat.
	 *
	 * @param ChamiloClient $client  Client
	 * @param int           $classId School class id
	 * @return array{ok:bool,error:string,retryable:bool,hash:string}
	 */
	public function push(ChamiloClient $client, int $classId): array
	{
		$P = MAIN_DB_PREFIX;
		$fail = function (string $error, bool $retryable) use ($classId, $P): array {
			$this->db->query("UPDATE ".$P."school_class SET sync_status = 2, last_error = '".$this->db->escape(dol_substr($error, 0, 2000))."' WHERE rowid = ".$classId);
			return array('ok' => false, 'error' => $error, 'retryable' => $retryable, 'hash' => '');
		};

		$resql = $this->db->query("SELECT * FROM ".$P."school_class WHERE rowid = ".$classId);
		$c = $resql ? $this->db->fetch_object($resql) : null;
		if (!$c) {
			return array('ok' => false, 'error' => 'Class '.$classId.' not found', 'retryable' => false, 'hash' => '');
		}

		$payload = array('title' => (string) $c->label, 'description' => trim((string) $c->level));
		$chamiloId = (int) $c->chamilo_id;
		if ($chamiloId <= 0) {
			// Group type 0 is a class. The creator is not added as a member of a class.
			$res = $client->request('POST', '/api/usergroups', array(), $payload + array('groupType' => 0, 'visibility' => '1', 'allowMembersToLeaveGroup' => 0));
			if (!$res['ok']) {
				return $fail('Create class: '.$res['error'], $res['retryable']);
			}
			$chamiloId = (int) (is_array($res['data']) ? ($res['data']['id'] ?? schoolIriId((string) ($res['data']['@id'] ?? '')) ?? 0) : 0);
			if ($chamiloId <= 0) {
				return $fail('Create class: Chamilo did not return an id', false);
			}
			// Saved at once, so a retry carries on with the class that now exists instead of creating a second one.
			$this->db->query("UPDATE ".$P."school_class SET chamilo_id = ".$chamiloId." WHERE rowid = ".$classId);
		} else {
			$res = $client->request('PATCH', '/api/usergroups/'.$chamiloId, array(), $payload);
			if (!$res['ok']) {
				return $fail($res['status'] === 404 ? 'The Chamilo class no longer exists' : 'Update class: '.$res['error'], $res['retryable']);
			}
		}

		// Sessions the class follows.
		$sessionIds = array();
		$sq = $this->db->query("SELECT s.chamilo_id FROM ".$P."school_class_session AS cs INNER JOIN ".$P."school_session AS s ON s.rowid = cs.fk_session WHERE cs.fk_class = ".$classId);
		$missing = 0;
		while ($sq && ($o = $this->db->fetch_object($sq))) {
			if ((int) $o->chamilo_id > 0) {
				$sessionIds[] = (int) $o->chamilo_id;
			} else {
				$missing++;
			}
		}
		if ($missing > 0) {
			return $fail($missing.' session(s) of the class are not in Chamilo yet', true);
		}
		$res = $client->request('PUT', '/api/usergroups/'.$chamiloId.'/sessions', array(), array('sessionIds' => $sessionIds));
		if (!$res['ok']) {
			return $fail($this->routeError('Link the sessions', $res), $res['status'] !== 404 && $res['retryable']);
		}
		$ignored = array_map('intval', (array) ($res['data']['ignored'] ?? array()));
		if ($ignored) {
			return $fail('Chamilo does not know these sessions on its portal: '.implode(', ', $ignored), false);
		}

		// Current members. A student without a Chamilo account yet is left out and reported; the class is updated again
		// when that student's account is created.
		$userIds = array();
		$without = 0;
		$members = (new SchoolClass($this->db))->currentMemberIds($classId);
		if ($members) {
			$uq = $this->db->query("SELECT rowid, chamilo_user_id FROM ".$P."school_student WHERE rowid IN (".implode(',', array_map('intval', $members)).")");
			while ($uq && ($o = $this->db->fetch_object($uq))) {
				if ((int) $o->chamilo_user_id > 0) {
					$userIds[] = (int) $o->chamilo_user_id;
				} else {
					$without++;
				}
			}
		}
		$res = $client->request('PUT', '/api/usergroups/'.$chamiloId.'/users', array(), array('userIds' => $userIds));
		if (!$res['ok']) {
			return $fail($this->routeError('Update the members', $res), $res['status'] !== 404 && $res['retryable']);
		}

		$note = $without > 0 ? 'NOTE: '.$without.' member(s) have no Chamilo account yet and were left out; they join when their account is created' : '';
		$this->db->query("UPDATE ".$P."school_class SET sync_status = 1, last_sync = '".$this->db->idate(dol_now())."', last_error = ".($note === '' ? 'NULL' : "'".$this->db->escape($note)."'")." WHERE rowid = ".$classId);
		return array('ok' => true, 'error' => '', 'retryable' => false, 'hash' => schoolPayloadHash(array('class' => $classId, 'sessions' => $sessionIds, 'users' => $userIds)));
	}

	/**
	 * @param string                                                             $what What was being done
	 * @param array{ok:bool,status:int,data:mixed,error:string,retryable:bool}  $res  Response
	 * @return string
	 */
	private function routeError(string $what, array $res): string
	{
		if ($res['status'] === 404 && stripos($res['error'], 'No route found') !== false) {
			return $what.': this Chamilo has no /api/usergroups/{id}/... class routes: update Chamilo';
		}
		if ($res['status'] === 409) {
			return $what.': the Chamilo group is not a class';
		}
		return $what.': '.$res['error'];
	}
}
