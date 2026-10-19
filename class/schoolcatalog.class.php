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
 * \file    class/schoolcatalog.class.php
 * \ingroup school
 * \brief   Import of Chamilo courses and sessions as Dolibarr service products. Read-only on the Chamilo side.
 */

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once __DIR__.'/chamiloclient.class.php';
require_once __DIR__.'/chamilosetup.class.php';
require_once __DIR__.'/../lib/schoollogic.lib.php';

/**
 * Creates or links one service product per Chamilo course and per session.
 *
 * A re-import refreshes what Chamilo owns (code, language, dates, session courses) and keeps what Dolibarr
 * owns (product label, prices, status), so edits made in Dolibarr after the first import are never lost.
 */
class SchoolCatalog
{
	public const SYNCED = 1;

	/** @var DoliDB */
	private $db;
	/** @var User */
	private $user;

	/**
	 * @param DoliDB $db   Database handler
	 * @param User   $user User creating the products
	 */
	public function __construct($db, $user)
	{
		$this->db = $db;
		$this->user = $user;
	}

	/**
	 * Import every course, then every session, from Chamilo.
	 *
	 * @param ChamiloClient $client Client
	 * @return array{courses:array<string,int>,sessions:array<string,int>,errors:array<int,string>}
	 */
	public function importAll(ChamiloClient $client): array
	{
		$report = array(
			'courses' => array('created' => 0, 'linked' => 0, 'updated' => 0),
			'sessions' => array('created' => 0, 'linked' => 0, 'updated' => 0),
			'errors' => array(),
		);

		foreach ($this->fetchAll($client, '/api/courses', $report['errors']) as $item) {
			$res = $this->importCourse(is_array($item) ? $item : array());
			if ($res['error'] !== '') {
				$report['errors'][] = $res['error'];
			} else {
				$report['courses'][$res['result']]++;
			}
		}

		foreach ($this->fetchAll($client, '/api/sessions', $report['errors']) as $row) {
			$id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
			if ($id <= 0) {
				continue;
			}
			// The collection leaves the dates out: read each session on its own.
			$one = $client->request('GET', '/api/sessions/'.$id);
			if (!$one['ok'] || !is_array($one['data'])) {
				$report['errors'][] = 'Session '.$id.': '.$one['error'];
				continue;
			}
			$res = $this->importSession($one['data']);
			if ($res['error'] !== '') {
				$report['errors'][] = $res['error'];
			} else {
				$report['sessions'][$res['result']]++;
			}
		}

		return $report;
	}

	/**
	 * @param array<string,mixed> $item Course from the API
	 * @return array{result:string,error:string} result is created, linked or updated
	 */
	public function importCourse(array $item): array
	{
		global $conf;
		$m = schoolMapCourse($item);
		if ($m === null) {
			return array('result' => '', 'error' => 'Course ignored: no id or code');
		}
		$entity = (int) $conf->entity;
		$T = MAIN_DB_PREFIX.'school_course';

		$row = $this->one("SELECT rowid FROM ".$T." WHERE entity = ".$entity." AND chamilo_id = ".$m['chamilo_id']);
		if ($row) {
			$this->db->query("UPDATE ".$T." SET code = '".$this->db->escape($m['code'])."', language = ".$this->nullable($m['language'])
				.", sync_status = ".self::SYNCED.", last_sync = '".$this->db->idate(dol_now())."', last_error = NULL WHERE rowid = ".((int) $row->rowid));
			return array('result' => 'updated', 'error' => '');
		}

		$made = $this->productFor(schoolProductRef('C', $m['code']), $m['title'], $m['code']);
		if ($made['error'] !== '') {
			return array('result' => '', 'error' => 'Course '.$m['code'].': '.$made['error']);
		}
		$sql = "INSERT INTO ".$T." (entity, fk_product, chamilo_id, code, language, sync_status, last_sync, datec, fk_user_creat) VALUES ("
			.$entity.", ".$made['id'].", ".$m['chamilo_id'].", '".$this->db->escape($m['code'])."', ".$this->nullable($m['language']).", "
			.self::SYNCED.", '".$this->db->idate(dol_now())."', '".$this->db->idate(dol_now())."', ".((int) $this->user->id).")";
		if (!$this->db->query($sql)) {
			return array('result' => '', 'error' => 'Course '.$m['code'].': '.$this->db->lasterror());
		}
		return array('result' => $made['created'] ? 'created' : 'linked', 'error' => '');
	}

	/**
	 * @param array<string,mixed> $item Session from /api/sessions/{id}
	 * @return array{result:string,error:string} result is created, linked or updated
	 */
	public function importSession(array $item): array
	{
		global $conf;
		$m = schoolMapSession($item);
		if ($m === null) {
			return array('result' => '', 'error' => 'Session ignored: no id');
		}
		$entity = (int) $conf->entity;
		$T = MAIN_DB_PREFIX.'school_session';
		$dates = array();
		foreach (array('display_start', 'display_end', 'access_start', 'access_end', 'coach_access_start', 'coach_access_end') as $k) {
			$dates[$k] = $m[$k] === null ? 'NULL' : "'".$this->db->idate($m[$k])."'";
		}

		$row = $this->one("SELECT rowid FROM ".$T." WHERE entity = ".$entity." AND chamilo_id = ".$m['chamilo_id']);
		if ($row) {
			$localId = (int) $row->rowid;
			$this->db->query("UPDATE ".$T." SET display_start = ".$dates['display_start'].", display_end = ".$dates['display_end']
				.", access_start = ".$dates['access_start'].", access_end = ".$dates['access_end']
				.", coach_access_start = ".$dates['coach_access_start'].", coach_access_end = ".$dates['coach_access_end']
				.", sync_status = ".self::SYNCED.", last_sync = '".$this->db->idate(dol_now())."', last_error = NULL WHERE rowid = ".$localId);
			$result = 'updated';
		} else {
			$made = $this->productFor(schoolProductRef('S', $m['chamilo_id']), $m['title'], 'Chamilo session #'.$m['chamilo_id']);
			if ($made['error'] !== '') {
				return array('result' => '', 'error' => 'Session '.$m['chamilo_id'].': '.$made['error']);
			}
			$sql = "INSERT INTO ".$T." (entity, fk_product, chamilo_id, display_start, display_end, access_start, access_end, coach_access_start, coach_access_end, sync_status, last_sync, datec, fk_user_creat) VALUES ("
				.$entity.", ".$made['id'].", ".$m['chamilo_id'].", ".$dates['display_start'].", ".$dates['display_end'].", ".$dates['access_start'].", ".$dates['access_end'].", "
				.$dates['coach_access_start'].", ".$dates['coach_access_end'].", ".self::SYNCED.", '".$this->db->idate(dol_now())."', '".$this->db->idate(dol_now())."', ".((int) $this->user->id).")";
			if (!$this->db->query($sql)) {
				return array('result' => '', 'error' => 'Session '.$m['chamilo_id'].': '.$this->db->lasterror());
			}
			$localId = (int) $this->db->last_insert_id($T);
			$result = $made['created'] ? 'created' : 'linked';
		}

		// Courses of the session. A course not imported yet is skipped: it is linked on the next import.
		foreach ($m['course_ids'] as $chamiloCourseId) {
			$c = $this->one("SELECT rowid FROM ".MAIN_DB_PREFIX."school_course WHERE entity = ".$entity." AND chamilo_id = ".((int) $chamiloCourseId));
			if ($c) {
				$link = $this->one("SELECT rowid FROM ".MAIN_DB_PREFIX."school_session_course WHERE fk_session = ".$localId." AND fk_course = ".((int) $c->rowid));
				if (!$link) {
					$this->db->query("INSERT INTO ".MAIN_DB_PREFIX."school_session_course (fk_session, fk_course) VALUES (".$localId.", ".((int) $c->rowid).")");
				}
			}
		}

		return array('result' => $result, 'error' => '');
	}

	/**
	 * Write the Dolibarr product id (and the price, when there is one) on every linked Chamilo course and session.
	 *
	 * @param ChamiloClient $client Client
	 * @return array{courses:array<string,int>,sessions:array<string,int>,errors:array<int,string>}
	 */
	public function pushIdentity(ChamiloClient $client): array
	{
		global $conf;
		$setup = new ChamiloSetup($client);
		$report = array('courses' => array('created' => 0, 'updated' => 0, 'unchanged' => 0), 'sessions' => array('created' => 0, 'updated' => 0, 'unchanged' => 0), 'errors' => array());
		$entity = (int) $conf->entity;

		$kinds = array(
			'courses' => array('school_course', 'SCHOOL_EF_COURSE_DOLIBARR_ID', 'SCHOOL_EF_COURSE_PRICE'),
			'sessions' => array('school_session', 'SCHOOL_EF_SESSION_DOLIBARR_ID', 'SCHOOL_EF_SESSION_PRICE'),
		);
		foreach ($kinds as $kind => $def) {
			$idField = getDolGlobalInt($def[1]);
			$priceField = getDolGlobalInt($def[2]);
			if ($idField <= 0) {
				$report['errors'][] = 'Extra field '.$def[1].' is not set up';
				continue;
			}
			$resql = $this->db->query("SELECT t.chamilo_id, t.fk_product, p.price FROM ".MAIN_DB_PREFIX.$def[0]." AS t INNER JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = t.fk_product WHERE t.entity = ".$entity." AND t.chamilo_id IS NOT NULL");
			while ($resql && ($o = $this->db->fetch_object($resql))) {
				$r = $setup->setValue($idField, (int) $o->chamilo_id, (string) ((int) $o->fk_product));
				if ($r['ok']) {
					$report[$kind][$r['action']]++;
				} else {
					$report['errors'][] = $kind.' #'.$o->chamilo_id.': '.$r['error'];
				}
				$price = schoolFormatPrice((float) $o->price);
				if ($price !== '' && $priceField > 0) {
					$r = $setup->setValue($priceField, (int) $o->chamilo_id, $price);
					if (!$r['ok']) {
						$report['errors'][] = $kind.' #'.$o->chamilo_id.' price: '.$r['error'];
					}
				}
			}
		}
		return $report;
	}

	/**
	 * Every item of a collection, page after page.
	 *
	 * @param ChamiloClient     $client Client
	 * @param string            $path   Collection path
	 * @param array<int,string> $errors Receives the errors
	 * @return array<int,mixed>
	 */
	private function fetchAll(ChamiloClient $client, string $path, array &$errors): array
	{
		$all = array();
		$perPage = 100;
		for ($page = 1; $page <= 200; $page++) {
			$res = $client->request('GET', $path, array('itemsPerPage' => $perPage, 'page' => $page));
			if (!$res['ok']) {
				$errors[] = $path.': '.$res['error'];
				break;
			}
			$items = ChamiloClient::members($res['data']);
			$all = array_merge($all, $items);
			if (count($items) < $perPage) {
				break;
			}
		}
		return $all;
	}

	/**
	 * The service product with this reference, created when it does not exist. An existing one is adopted, not changed.
	 *
	 * @param string $ref         Product reference
	 * @param string $label       Label for a new product
	 * @param string $description Description for a new product
	 * @return array{id:int,created:bool,error:string}
	 */
	public function productFor(string $ref, string $label, string $description): array
	{
		global $conf;
		$existing = $this->one("SELECT rowid FROM ".MAIN_DB_PREFIX."product WHERE entity IN (".getEntity('product').") AND ref = '".$this->db->escape($ref)."'");
		if ($existing) {
			return array('id' => (int) $existing->rowid, 'created' => false, 'error' => '');
		}

		$product = new Product($this->db);
		$product->ref = $ref;
		$product->label = dol_substr($label, 0, 255);
		$product->description = $description;
		$product->type = Product::TYPE_SERVICE;
		$product->price_base_type = 'HT';
		$product->price = 0;
		// Not for sale until someone sets a price and decides to sell it.
		$product->status = 0;
		$product->status_buy = 0;
		$id = $product->create($this->user);
		if ($id <= 0) {
			return array('id' => 0, 'created' => false, 'error' => $product->error !== '' ? $product->error : implode(', ', $product->errors));
		}
		return array('id' => (int) $id, 'created' => true, 'error' => '');
	}

	/**
	 * @param string $sql Query
	 * @return object|null First row
	 */
	private function one(string $sql)
	{
		$resql = $this->db->query($sql);
		return $resql ? ($this->db->fetch_object($resql) ?: null) : null;
	}

	/**
	 * @param string $value Value, '' for none
	 * @return string SQL literal
	 */
	private function nullable(string $value): string
	{
		return $value === '' ? 'NULL' : "'".$this->db->escape($value)."'";
	}
}
