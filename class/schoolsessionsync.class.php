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
 * \file    class/schoolsessionsync.class.php
 * \ingroup school
 * \brief   Creation of sessions in Dolibarr and push to Chamilo.
 */

require_once __DIR__.'/schoolcatalog.class.php';
require_once __DIR__.'/chamilosetup.class.php';
require_once __DIR__.'/syncqueue.class.php';

/**
 * A session created in Dolibarr is a service product plus a School row, then a queued job that creates the
 * Chamilo session, links its courses and writes the extra fields. The push can be repeated safely: the Chamilo
 * id is saved as soon as the session exists, and each later step only does what is still missing.
 */
class SchoolSessionSync
{
	/** @var DoliDB */
	private $db;
	/** @var User */
	private $user;

	/**
	 * @param DoliDB $db   Database handler
	 * @param User   $user User
	 */
	public function __construct($db, $user)
	{
		$this->db = $db;
		$this->user = $user;
	}

	/**
	 * Create the product and the School session in Dolibarr, then queue the push to Chamilo.
	 *
	 * @param array{title:string,display_start:int,display_end:int,access_start:?int,access_end:?int,price:float,course_ids:array<int,int>} $data Values; course_ids are School course ids
	 * @return array{id:int,error:string} School session id
	 */
	public function createLocal(array $data): array
	{
		global $conf;
		$entity = (int) $conf->entity;

		$next = 1;
		$resql = $this->db->query("SELECT MAX(rowid) AS m FROM ".MAIN_DB_PREFIX."school_session");
		if ($resql && ($o = $this->db->fetch_object($resql))) {
			$next = ((int) $o->m) + 1;
		}
		$catalog = new SchoolCatalog($this->db, $this->user);
		$made = $catalog->productFor('SCH-SD-'.$next, $data['title'], 'School session created in Dolibarr');
		if ($made['error'] !== '') {
			return array('id' => 0, 'error' => $made['error']);
		}
		if ($data['price'] > 0) {
			$product = new Product($this->db);
			if ($product->fetch($made['id']) > 0) {
				$product->updatePrice($data['price'], 'HT', $this->user);
			}
		}

		$ts = function (?int $t): string {
			return $t === null ? 'NULL' : "'".$this->db->idate($t)."'";
		};
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."school_session (entity, fk_product, display_start, display_end, access_start, access_end, sync_status, datec, fk_user_creat) VALUES ("
			.$entity.", ".$made['id'].", ".$ts($data['display_start']).", ".$ts($data['display_end']).", ".$ts($data['access_start'] ?? $data['display_start']).", ".$ts($data['access_end'] ?? $data['display_end']).", 0, '"
			.$this->db->idate(dol_now())."', ".((int) $this->user->id).")";
		if (!$this->db->query($sql)) {
			return array('id' => 0, 'error' => $this->db->lasterror());
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'school_session');
		foreach ($data['course_ids'] as $courseId) {
			$this->db->query("INSERT INTO ".MAIN_DB_PREFIX."school_session_course (fk_session, fk_course) VALUES (".$id.", ".((int) $courseId).")");
		}
		(new SyncQueue($this->db))->enqueue('session', $id, 'create');
		return array('id' => $id, 'error' => '');
	}

	/**
	 * Make Chamilo match the School session. Safe to repeat.
	 *
	 * @param ChamiloClient $client  Client
	 * @param int           $localId School session id
	 * @return array{ok:bool,error:string,retryable:bool,hash:string}
	 */
	public function push(ChamiloClient $client, int $localId): array
	{
		$fail = function (string $error, bool $retryable) use ($localId): array {
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."school_session SET sync_status = 2, last_error = '".$this->db->escape(dol_substr($error, 0, 2000))."' WHERE rowid = ".$localId);
			return array('ok' => false, 'error' => $error, 'retryable' => $retryable, 'hash' => '');
		};

		$resql = $this->db->query("SELECT s.*, p.label, p.price FROM ".MAIN_DB_PREFIX."school_session AS s INNER JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = s.fk_product WHERE s.rowid = ".$localId);
		$s = $resql ? $this->db->fetch_object($resql) : null;
		if (!$s) {
			return array('ok' => false, 'error' => 'School session '.$localId.' not found', 'retryable' => false, 'hash' => '');
		}

		$payload = array('title' => (string) $s->label);
		foreach (array('display_start' => 'displayStartDate', 'display_end' => 'displayEndDate', 'access_start' => 'accessStartDate', 'access_end' => 'accessEndDate', 'coach_access_start' => 'coachAccessStartDate', 'coach_access_end' => 'coachAccessEndDate') as $col => $key) {
			if (!empty($s->$col)) {
				$payload[$key] = date('c', $this->db->jdate($s->$col));
			}
		}

		// 1. The session itself. The Chamilo id is saved at once so a retry continues instead of creating a second session.
		$chamiloId = (int) $s->chamilo_id;
		if ($chamiloId <= 0) {
			$res = $client->request('POST', '/api/sessions', array(), $payload);
			if (!$res['ok']) {
				return $fail('Create session: '.$res['error'], $res['retryable']);
			}
			$chamiloId = (int) (is_array($res['data']) ? ($res['data']['id'] ?? schoolIriId((string) ($res['data']['@id'] ?? '')) ?? 0) : 0);
			if ($chamiloId <= 0) {
				return $fail('Create session: Chamilo did not return an id', false);
			}
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."school_session SET chamilo_id = ".$chamiloId." WHERE rowid = ".$localId);
		} else {
			$res = $client->request('PATCH', '/api/sessions/'.$chamiloId, array(), $payload);
			if (!$res['ok']) {
				return $fail('Update session: '.$res['error'], $res['retryable']);
			}
		}

		// 2. Courses: add the ones Chamilo does not have yet.
		$wanted = array();
		$cres = $this->db->query("SELECT c.chamilo_id FROM ".MAIN_DB_PREFIX."school_session_course AS sc INNER JOIN ".MAIN_DB_PREFIX."school_course AS c ON c.rowid = sc.fk_course WHERE sc.fk_session = ".$localId." AND c.chamilo_id IS NOT NULL");
		while ($cres && ($o = $this->db->fetch_object($cres))) {
			$wanted[(int) $o->chamilo_id] = (int) $o->chamilo_id;
		}
		$cur = $client->request('GET', '/api/sessions/'.$chamiloId);
		if (!$cur['ok'] || !is_array($cur['data'])) {
			return $fail('Read session: '.$cur['error'], $cur['retryable']);
		}
		$have = schoolMapSession($cur['data'])['course_ids'] ?? array();
		foreach (array_diff($wanted, $have) as $courseId) {
			$link = $client->request('POST', '/api/session_rel_courses', array(), array('session' => '/api/sessions/'.$chamiloId, 'course' => '/api/courses/'.$courseId));
			if (!$link['ok']) {
				return $fail('Add course '.$courseId.': '.$link['error'], $link['retryable']);
			}
		}

		// 3. Extra fields: Dolibarr product id, and the price when there is one.
		$setup = new ChamiloSetup($client);
		$idField = getDolGlobalInt('SCHOOL_EF_SESSION_DOLIBARR_ID');
		if ($idField > 0) {
			$r = $setup->setValue($idField, $chamiloId, (string) ((int) $s->fk_product));
			if (!$r['ok']) {
				return $fail('Extra field dolibarr_id: '.$r['error'], true);
			}
		}
		$price = schoolFormatPrice((float) $s->price);
		$priceField = getDolGlobalInt('SCHOOL_EF_SESSION_PRICE');
		if ($price !== '' && $priceField > 0) {
			$r = $setup->setValue($priceField, $chamiloId, $price);
			if (!$r['ok']) {
				return $fail('Extra field price: '.$r['error'], true);
			}
		}

		$this->db->query("UPDATE ".MAIN_DB_PREFIX."school_session SET sync_status = 1, last_sync = '".$this->db->idate(dol_now())."', last_error = NULL WHERE rowid = ".$localId);
		return array('ok' => true, 'error' => '', 'retryable' => false, 'hash' => schoolPayloadHash($payload + array('courses' => array_values($wanted))));
	}
}
