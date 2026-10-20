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
 * \file    class/schoolroom.class.php
 * \ingroup school
 * \brief   Rooms of the school: resources of Dolibarr's Resources module that are also rooms in Chamilo.
 */

require_once DOL_DOCUMENT_ROOT.'/resource/class/dolresource.class.php';
require_once __DIR__.'/syncqueue.class.php';
require_once __DIR__.'/chamiloclient.class.php';
require_once __DIR__.'/../lib/schoollogic.lib.php';

/**
 * A room is a Dolibarr resource, so its planning, its bookings and its conflicts are those of the Resources module. The
 * capacity is the resource's own maximum number of users. The room is mirrored in Chamilo, where the attendance dates
 * can name it.
 */
class SchoolRoom
{
	/** Type of the Resources dictionary for rooms. */
	public const RESOURCE_TYPE = 'RES_ROOMS';

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
	 * Use a Dolibarr resource as a room of the school.
	 *
	 * @param int      $resourceId Resource id
	 * @param int|null $capacity   Capacity, kept as the resource's maximum number of users
	 * @param int|null $floor      Floor
	 * @return array{id:int,error:string} error is a language key or a database message
	 */
	public function adopt(int $resourceId, ?int $capacity, ?int $floor): array
	{
		global $conf, $user;
		$resource = new Dolresource($this->db);
		if ($resource->fetch($resourceId) <= 0) {
			return array('id' => 0, 'error' => 'SchoolErrNotFound');
		}
		$exists = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."school_room WHERE entity = ".((int) $conf->entity)." AND fk_resource = ".$resourceId);
		if ($exists && $this->db->fetch_object($exists)) {
			return array('id' => 0, 'error' => 'SchoolErrRoomAlreadyAdopted');
		}
		if ($capacity !== null && $capacity < 0) {
			return array('id' => 0, 'error' => 'SchoolErrRoomCapacity');
		}
		if ($capacity !== null && (int) $resource->max_users !== $capacity) {
			$resource->max_users = $capacity;
			if ($resource->update($user) <= 0) {
				return array('id' => 0, 'error' => $resource->error ?: 'Error');
			}
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."school_room (entity, fk_resource, floor_number, sync_status, datec) VALUES ("
			.((int) $conf->entity).", ".$resourceId.", ".($floor === null ? 'NULL' : $floor).", 0, '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			return array('id' => 0, 'error' => $this->db->lasterror());
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'school_room');
		(new SyncQueue($this->db))->enqueue('room', $id, 'update');
		return array('id' => $id, 'error' => '');
	}

	/**
	 * @param int      $roomId   School room id
	 * @param int|null $capacity Capacity (the resource's maximum number of users)
	 * @param int|null $floor    Floor
	 * @return string '' when saved, else a language key or a database message
	 */
	public function update(int $roomId, ?int $capacity, ?int $floor): string
	{
		global $user;
		$resql = $this->db->query("SELECT fk_resource FROM ".MAIN_DB_PREFIX."school_room WHERE rowid = ".$roomId);
		$row = $resql ? $this->db->fetch_object($resql) : null;
		if (!$row) {
			return 'SchoolErrNotFound';
		}
		if ($capacity !== null && $capacity < 0) {
			return 'SchoolErrRoomCapacity';
		}
		$resource = new Dolresource($this->db);
		if ($resource->fetch((int) $row->fk_resource) > 0 && $capacity !== null && (int) $resource->max_users !== $capacity) {
			$resource->max_users = $capacity;
			if ($resource->update($user) <= 0) {
				return $resource->error ?: 'Error';
			}
		}
		if (!$this->db->query("UPDATE ".MAIN_DB_PREFIX."school_room SET floor_number = ".($floor === null ? 'NULL' : $floor)." WHERE rowid = ".$roomId)) {
			return $this->db->lasterror();
		}
		(new SyncQueue($this->db))->enqueue('room', $roomId, 'update');
		return '';
	}

	/**
	 * Bring in the rooms that Chamilo has and Dolibarr does not know: a Dolibarr resource with the same reference is used
	 * if there is one, else a resource of the type "Rooms" is created. Nothing is changed in Chamilo.
	 *
	 * @param ChamiloClient $client Client
	 * @return array{created:int,linked:int,known:int,errors:list<string>}
	 */
	public function importFromChamilo(ChamiloClient $client): array
	{
		global $conf, $user;
		$report = array('created' => 0, 'linked' => 0, 'known' => 0, 'errors' => array());
		$res = $client->request('GET', '/api/rooms', array('itemsPerPage' => 200));
		if (!$res['ok']) {
			$report['errors'][] = $res['error'];
			return $report;
		}
		foreach (ChamiloClient::members($res['data']) as $r) {
			if (!is_array($r) || empty($r['id']) || trim((string) ($r['title'] ?? '')) === '') {
				continue;
			}
			$chamiloId = (int) $r['id'];
			$known = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."school_room WHERE entity = ".((int) $conf->entity)." AND chamilo_room_id = ".$chamiloId);
			if ($known && $this->db->fetch_object($known)) {
				$report['known']++;
				continue;
			}
			$title = trim((string) $r['title']);
			$capacity = isset($r['capacity']) ? (int) $r['capacity'] : null;
			$floor = isset($r['floorNumber']) ? (int) $r['floorNumber'] : null;

			$byRef = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."resource WHERE entity IN (".getEntity('resource').") AND ref = '".$this->db->escape($title)."'");
			$existing = $byRef ? $this->db->fetch_object($byRef) : null;
			if ($existing) {
				$resourceId = (int) $existing->rowid;
				$taken = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."school_room WHERE entity = ".((int) $conf->entity)." AND fk_resource = ".$resourceId);
				if ($taken && $this->db->fetch_object($taken)) {
					$report['errors'][] = $title.': the Dolibarr resource of that name is already another room';
					continue;
				}
				$report['linked']++;
			} else {
				$resource = new Dolresource($this->db);
				$resource->ref = $title;
				$resource->fk_code_type_resource = self::RESOURCE_TYPE;
				$resource->max_users = $capacity ?: null;
				$resourceId = $resource->create($user);
				if ($resourceId <= 0) {
					$report['errors'][] = $title.': '.($resource->error ?: implode(', ', $resource->errors));
					continue;
				}
				$report['created']++;
			}
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."school_room (entity, fk_resource, floor_number, chamilo_room_id, sync_status, last_sync, datec) VALUES ("
				.((int) $conf->entity).", ".$resourceId.", ".($floor === null ? 'NULL' : $floor).", ".$chamiloId.", 1, '".$this->db->idate(dol_now())."', '".$this->db->idate(dol_now())."')";
			if (!$this->db->query($sql)) {
				$report['errors'][] = $title.': '.$this->db->lasterror();
			}
		}
		return $report;
	}
}
