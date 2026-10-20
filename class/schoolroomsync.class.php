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
 * \file    class/schoolroomsync.class.php
 * \ingroup school
 * \brief   Rooms: what is sent to Chamilo.
 */

require_once __DIR__.'/schoolroom.class.php';

/**
 * Creates or updates the Chamilo room of a room of the school. A room in Chamilo belongs to a branch of the campus: the
 * one set in the module settings, else the only one Chamilo has. The module never deletes a room in Chamilo.
 */
class SchoolRoomSync
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
	 * @param ChamiloClient $client Client
	 * @param int           $roomId School room id
	 * @return array{ok:bool,error:string,retryable:bool,hash:string}
	 */
	public function push(ChamiloClient $client, int $roomId): array
	{
		$P = MAIN_DB_PREFIX;
		$fail = function (string $error, bool $retryable) use ($roomId, $P): array {
			$this->db->query("UPDATE ".$P."school_room SET sync_status = 2, last_error = '".$this->db->escape(dol_substr($error, 0, 2000))."' WHERE rowid = ".$roomId);
			return array('ok' => false, 'error' => $error, 'retryable' => $retryable, 'hash' => '');
		};

		$resql = $this->db->query("SELECT r.rowid, r.floor_number, r.chamilo_room_id, x.ref, x.description, x.max_users FROM ".$P."school_room AS r INNER JOIN ".$P."resource AS x ON x.rowid = r.fk_resource WHERE r.rowid = ".$roomId);
		$r = $resql ? $this->db->fetch_object($resql) : null;
		if (!$r) {
			return array('ok' => false, 'error' => 'Room '.$roomId.' not found', 'retryable' => false, 'hash' => '');
		}
		$payload = schoolRoomPayload((string) $r->ref, $r->description !== null ? (string) $r->description : null, $r->floor_number !== null ? (int) $r->floor_number : null, $r->max_users !== null ? (int) $r->max_users : null);

		$chamiloId = (int) $r->chamilo_room_id;
		if ($chamiloId <= 0) {
			$branch = $this->branchId($client);
			if (is_string($branch)) {
				return $fail($branch, false);
			}
			$res = $client->request('POST', '/api/rooms', array(), $payload + array('branch' => '/api/branches/'.$branch));
			if (!$res['ok']) {
				return $fail('Create room: '.$res['error'], $res['retryable']);
			}
			$chamiloId = (int) (is_array($res['data']) ? ($res['data']['id'] ?? schoolIriId((string) ($res['data']['@id'] ?? '')) ?? 0) : 0);
			if ($chamiloId <= 0) {
				return $fail('Create room: Chamilo did not return an id', false);
			}
			$this->db->query("UPDATE ".$P."school_room SET chamilo_room_id = ".$chamiloId." WHERE rowid = ".$roomId);
		} else {
			// An empty capacity or description is sent as such: clearing it in Dolibarr clears it in Chamilo.
			$payload += array('capacity' => null);
			$res = $client->request('PATCH', '/api/rooms/'.$chamiloId, array(), $payload);
			if (!$res['ok']) {
				return $fail($res['status'] === 404 ? 'The Chamilo room no longer exists' : 'Update room: '.$res['error'], $res['status'] !== 404 && $res['retryable']);
			}
		}

		$this->db->query("UPDATE ".$P."school_room SET sync_status = 1, last_sync = '".$this->db->idate(dol_now())."', last_error = NULL WHERE rowid = ".$roomId);
		return array('ok' => true, 'error' => '', 'retryable' => false, 'hash' => schoolPayloadHash($payload));
	}

	/**
	 * The branch of Chamilo that new rooms go to.
	 *
	 * @param ChamiloClient $client Client
	 * @return int|string The branch id, or the error message
	 */
	private function branchId(ChamiloClient $client)
	{
		$set = getDolGlobalInt('SCHOOL_CHAMILO_BRANCH_ID');
		if ($set > 0) {
			return $set;
		}
		$res = $client->request('GET', '/api/branches', array('itemsPerPage' => 50));
		if (!$res['ok']) {
			return 'Read the branches: '.$res['error'];
		}
		$branches = ChamiloClient::members($res['data']);
		if (count($branches) === 1 && !empty($branches[0]['id'])) {
			return (int) $branches[0]['id'];
		}
		return $branches ? 'Chamilo has several branches: choose the one for the rooms in the module setup' : 'Chamilo has no branch: a room needs one';
	}
}
