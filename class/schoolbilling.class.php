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
 * \file    class/schoolbilling.class.php
 * \ingroup school
 * \brief   Invoicing of enrolments and of the extra activities of a class to the paying parents.
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once __DIR__.'/schoolstudent.class.php';
require_once __DIR__.'/../lib/schoollogic.lib.php';

/**
 * Turns what is owed into draft invoices. A set of items (enrolments, or the participants of an activity) is planned first:
 * each item is split between the paying parents of the student by their share, and the lines are grouped by third party,
 * so a family with two children gets one invoice with one line per child. Invoices are only ever created as drafts: they
 * are reviewed and validated in Dolibarr.
 *
 * The share of each payer is decided when invoicing, then kept on the link between the student and the parent
 * (payer_share) and proposed again the next time.
 */
class SchoolBilling
{
	/** An item that cannot be invoiced yet has this reason. */
	public const KIND_ENROLMENT = 'enrolment';
	public const KIND_ACTIVITY = 'activity';

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
	 * The paying parents of a student.
	 *
	 * @param int $studentId School student id
	 * @return array<int,array{soc:int,name:string,share:float}> By third party id, payers first by the order of the links
	 */
	public function payers(int $studentId): array
	{
		$P = MAIN_DB_PREFIX;
		$out = array();
		$resql = $this->db->query("SELECT sp.fk_soc, sp.payer_share, s.nom FROM {$P}school_student_parent AS sp INNER JOIN {$P}societe AS s ON s.rowid = sp.fk_soc WHERE sp.fk_student = ".$studentId." AND sp.is_payer = 1 AND sp.chamilo_follow <> ".SchoolStudent::UNFOLLOW." ORDER BY sp.rowid");
		while ($resql && ($o = $this->db->fetch_object($resql))) {
			$out[(int) $o->fk_soc] = array('soc' => (int) $o->fk_soc, 'name' => (string) $o->nom, 'share' => (float) $o->payer_share);
		}
		return $out;
	}

	/**
	 * The shares to propose for a student: those kept from the last time when they still add up, else an even split.
	 *
	 * @param int $studentId School student id
	 * @return array<int,float> Percentage by third party id
	 */
	public function proposedShares(int $studentId): array
	{
		$stored = array();
		foreach ($this->payers($studentId) as $soc => $p) {
			$stored[$soc] = $p['share'];
		}
		return schoolProposeShares($stored);
	}

	/**
	 * Keep the shares decided at invoicing, to propose them again next time.
	 *
	 * @param int               $studentId School student id
	 * @param array<int,float>  $shares    Percentage by third party id; they must be the payers of the student and add up to 100
	 * @return string '' when saved, else a language key
	 */
	public function saveShares(int $studentId, array $shares): string
	{
		$wanted = array_keys($this->payers($studentId));
		$given = array_keys($shares);
		sort($wanted);
		sort($given);
		// The shares must be exactly those of the paying parents of the student: no one missing, no one else.
		if ($wanted !== $given) {
			return 'SchoolErrSharesNotPayers';
		}
		if (!schoolSharesValid($shares)) {
			return 'SchoolErrSharesTotal';
		}
		foreach ($shares as $soc => $share) {
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."school_student_parent SET payer_share = ".((float) $share)." WHERE fk_student = ".$studentId." AND fk_soc = ".((int) $soc)." AND is_payer = 1");
		}
		return '';
	}

	/**
	 * Enrolments that can be invoiced: not being removed, with a priced product, and not invoiced yet.
	 *
	 * @return list<array<string,mixed>> Items, see plan()
	 */
	public function billableEnrolments(): array
	{
		global $conf;
		$P = MAIN_DB_PREFIX;
		$sql = "SELECT e.rowid AS eid, e.fk_student, e.fk_session, e.fk_course, st.ref, st.firstname, st.lastname,"
			." COALESCE(ps.label, pc.label) AS label, COALESCE(ps.rowid, pc.rowid) AS pid, COALESCE(ps.price, pc.price) AS price, COALESCE(ps.price_base_type, pc.price_base_type) AS base, COALESCE(ps.tva_tx, pc.tva_tx) AS tva,"
			." s.display_start, s.display_end"
			." FROM {$P}school_enrolment AS e INNER JOIN {$P}school_student AS st ON st.rowid = e.fk_student"
			." LEFT JOIN {$P}school_session AS s ON s.rowid = e.fk_session LEFT JOIN {$P}product AS ps ON ps.rowid = s.fk_product"
			." LEFT JOIN {$P}school_course AS c ON c.rowid = e.fk_course LEFT JOIN {$P}product AS pc ON pc.rowid = c.fk_product"
			." WHERE e.entity = ".((int) $conf->entity)." AND e.status <> ".SchoolStudent::TO_REMOVE." ORDER BY st.lastname, st.firstname, e.rowid";
		$out = array();
		$resql = $this->db->query($sql);
		while ($resql && ($o = $this->db->fetch_object($resql))) {
			$price = (float) $o->price;
			if ($price <= 0 || $this->isEnrolmentInvoiced((int) $o->fk_student, (int) $o->fk_session, (int) $o->fk_course)) {
				continue;
			}
			// A price kept with tax is brought back to the price without tax.
			$amount = ($o->base === 'TTC') ? $price / (1 + ((float) $o->tva) / 100) : $price;
			$out[] = array(
				'kind' => self::KIND_ENROLMENT,
				'key' => 'e'.$o->eid,
				'student' => (int) $o->fk_student,
				'student_name' => trim($o->firstname.' '.$o->lastname),
				'student_ref' => (string) $o->ref,
				'label' => (string) $o->label,
				'amount' => round($amount, 2),
				'fk_product' => (int) $o->pid,
				'fk_session' => (int) $o->fk_session,
				'fk_course' => (int) $o->fk_course,
				'fk_activity' => 0,
				'date_start' => $o->display_start ? (int) $this->db->jdate($o->display_start) : null,
				'date_end' => $o->display_end ? (int) $this->db->jdate($o->display_end) : null,
			);
		}
		return $out;
	}

	/**
	 * The participants of an activity: the members of the class on the day of the activity, with whether each one was
	 * invoiced already.
	 *
	 * @param int $activityId Activity id
	 * @return array{activity:?object,participants:list<array{student:int,name:string,ref:string,invoiced:bool}>}
	 */
	public function activityParticipants(int $activityId): array
	{
		$P = MAIN_DB_PREFIX;
		$resql = $this->db->query("SELECT a.*, c.label AS class_label FROM {$P}school_class_activity AS a INNER JOIN {$P}school_class AS c ON c.rowid = a.fk_class WHERE a.rowid = ".$activityId);
		$a = $resql ? $this->db->fetch_object($resql) : null;
		if (!$a) {
			return array('activity' => null, 'participants' => array());
		}
		$day = $a->date_activity ? substr((string) $a->date_activity, 0, 10) : dol_print_date(dol_now(), '%Y-%m-%d');
		$out = array();
		$q = $this->db->query("SELECT m.fk_student, m.date_start, m.date_end, s.ref, s.firstname, s.lastname FROM {$P}school_class_student AS m INNER JOIN {$P}school_student AS s ON s.rowid = m.fk_student WHERE m.fk_class = ".((int) $a->fk_class)." ORDER BY s.lastname, s.firstname");
		while ($q && ($m = $this->db->fetch_object($q))) {
			if (!schoolIsCurrentMember($m->date_start ? (string) $m->date_start : null, $m->date_end ? (string) $m->date_end : null, $day)) {
				continue;
			}
			$out[(int) $m->fk_student] = array('student' => (int) $m->fk_student, 'name' => trim($m->firstname.' '.$m->lastname), 'ref' => (string) $m->ref, 'invoiced' => $this->isActivityInvoiced($activityId, (int) $m->fk_student));
		}
		return array('activity' => $a, 'participants' => array_values($out));
	}

	/**
	 * Items to invoice for some participants of an activity.
	 *
	 * @param int       $activityId Activity id
	 * @param list<int> $studentIds Chosen participants
	 * @return list<array<string,mixed>> Items, see plan()
	 */
	public function activityItems(int $activityId, array $studentIds): array
	{
		$P = MAIN_DB_PREFIX;
		$data = $this->activityParticipants($activityId);
		$a = $data['activity'];
		if (!$a) {
			return array();
		}
		$amount = (float) $a->unit_price;
		$productId = (int) $a->fk_product;
		if ($amount <= 0 && $productId > 0) {
			$p = $this->db->query("SELECT price, price_base_type, tva_tx FROM {$P}product WHERE rowid = ".$productId);
			$po = $p ? $this->db->fetch_object($p) : null;
			if ($po) {
				$amount = ($po->price_base_type === 'TTC') ? (float) $po->price / (1 + ((float) $po->tva_tx) / 100) : (float) $po->price;
			}
		}
		$ts = $a->date_activity ? (int) $this->db->jdate($a->date_activity) : null;
		$out = array();
		foreach ($data['participants'] as $p) {
			if (!in_array($p['student'], $studentIds, true) || $p['invoiced']) {
				continue;
			}
			$out[] = array(
				'kind' => self::KIND_ACTIVITY,
				'key' => 'a'.$activityId.'s'.$p['student'],
				'student' => $p['student'],
				'student_name' => $p['name'],
				'student_ref' => $p['ref'],
				'label' => (string) $a->label,
				'amount' => round($amount, 2),
				'fk_product' => $productId,
				'fk_session' => 0,
				'fk_course' => 0,
				'fk_activity' => $activityId,
				'date_start' => $ts,
				'date_end' => $ts,
			);
		}
		return $out;
	}

	/**
	 * Decide who pays what: each item is split between the paying parents of its student, and the parts are grouped by
	 * third party. Items that cannot be planned are reported and left out.
	 *
	 * @param list<array<string,mixed>>       $items     Items
	 * @param array<int,array<int,float>>     $overrides Shares typed by the user, by student then third party; the proposed shares are used otherwise
	 * @return array{invoices:array<int,array{soc:int,name:string,lines:list<array<string,mixed>>,total:float}>,errors:list<string>,shares:array<int,array<int,float>>}
	 */
	public function plan(array $items, array $overrides = array()): array
	{
		$invoices = array();
		$errors = array();
		$sharesUsed = array();
		foreach ($items as $item) {
			$student = (int) $item['student'];
			$payers = $this->payers($student);
			if (!$payers) {
				$errors[] = array('key' => 'SchoolErrNoPayer', 'student' => $item['student_name']);
				continue;
			}
			$shares = $overrides[$student] ?? $this->proposedShares($student);
			// Only the payers of the student count; a share typed for someone else is ignored.
			$shares = array_intersect_key($shares, $payers);
			if (!schoolSharesValid($shares) || count($shares) !== count($payers)) {
				$errors[] = array('key' => 'SchoolErrSharesTotal', 'student' => $item['student_name']);
				continue;
			}
			if ((float) $item['amount'] <= 0) {
				$errors[] = array('key' => 'SchoolErrNoPrice', 'student' => $item['student_name'], 'label' => $item['label']);
				continue;
			}
			$sharesUsed[$student] = $shares;
			$parts = schoolSplitAmount((float) $item['amount'], $shares);
			// A payer whose part is invoiced already is not invoiced again: only what is missing is planned.
			$done = $this->invoicedPayers((string) $item['kind'], $student, (int) $item['fk_session'], (int) $item['fk_course'], (int) $item['fk_activity']);
			foreach ($parts as $soc => $amount) {
				if ($amount <= 0 || isset($done[$soc])) {
					continue;
				}
				if (!isset($invoices[$soc])) {
					$invoices[$soc] = array('soc' => $soc, 'name' => $payers[$soc]['name'], 'lines' => array(), 'total' => 0.0);
				}
				$partial = count($payers) > 1 ? ' ('.rtrim(rtrim(number_format((float) $shares[$soc], 2, '.', ''), '0'), '.').' %)' : '';
				$invoices[$soc]['lines'][] = array(
					'desc' => $item['label'].' - '.$item['student_name'].$partial,
					'amount' => $amount,
					'share' => (float) $shares[$soc],
					'item' => $item,
				);
				$invoices[$soc]['total'] = round($invoices[$soc]['total'] + $amount, 2);
			}
		}
		return array('invoices' => $invoices, 'errors' => $errors, 'shares' => $sharesUsed);
	}

	/**
	 * Create one draft invoice per third party of the plan, with one line per child, and record what was invoiced. Each
	 * invoice is created or not at all. Items invoiced meanwhile are skipped.
	 *
	 * @param array{invoices:array<int,array<string,mixed>>,errors:list<mixed>,shares:array<int,array<int,float>>} $plan Plan
	 * @param User $user User creating the invoices
	 * @return array{created:array<int,int>,errors:list<string>} Invoice id by third party id
	 */
	public function createInvoices(array $plan, User $user): array
	{
		global $mysoc;
		$P = MAIN_DB_PREFIX;
		$created = array();
		$errors = array();

		// What is invoiced is read once, before the first invoice is created: an item split between two payers would
		// otherwise look invoiced as soon as the first payer's invoice is recorded, and the second would be skipped.
		$done = array();
		foreach ($plan['invoices'] as $inv) {
			foreach ($inv['lines'] as $line) {
				$i = $line['item'];
				if (!isset($done[$i['key']])) {
					$done[$i['key']] = $this->invoicedPayers((string) $i['kind'], (int) $i['student'], (int) $i['fk_session'], (int) $i['fk_course'], (int) $i['fk_activity']);
				}
			}
		}

		foreach ($plan['invoices'] as $socId => $inv) {
			$soc = new Societe($this->db);
			if ($soc->fetch((int) $socId) <= 0) {
				$errors[] = $inv['name'].': third party not found';
				continue;
			}
			// Keep the lines whose part of the item is not invoiced yet.
			$lines = array();
			foreach ($inv['lines'] as $line) {
				if (!isset($done[$line['item']['key']][(int) $socId])) {
					$lines[] = $line;
				}
			}
			if (!$lines) {
				continue;
			}

			$this->db->begin();
			$f = new Facture($this->db);
			$f->socid = (int) $socId;
			$f->type = Facture::TYPE_STANDARD;
			$f->date = dol_now();
			$f->cond_reglement_id = (int) $soc->cond_reglement_id;
			$f->mode_reglement_id = (int) $soc->mode_reglement_id;
			$f->note_private = 'Created by the School module.';
			$id = $f->create($user);
			if ($id <= 0) {
				$this->db->rollback();
				$errors[] = $inv['name'].': '.($f->error ?: implode(', ', $f->errors));
				continue;
			}
			$f->fetch($id);
			$f->fetch_thirdparty();

			$failed = '';
			foreach ($lines as $line) {
				$i = $line['item'];
				$tva = get_default_tva($mysoc, $soc, (int) $i['fk_product']);
				$res = $f->addline($line['desc'], (float) $line['amount'], 1, (float) $tva, 0, 0, (int) $i['fk_product'], 0, $i['date_start'] ?: '', $i['date_end'] ?: '', 0, 0, 0, 'HT', 0, 1);
				if ($res <= 0) {
					$failed = $f->error ?: implode(', ', $f->errors);
					break;
				}
			}
			if ($failed !== '') {
				$this->db->rollback();
				$errors[] = $inv['name'].': '.$failed;
				continue;
			}

			foreach ($lines as $line) {
				$i = $line['item'];
				if ($i['kind'] === self::KIND_ACTIVITY) {
					$sql = "INSERT INTO {$P}school_class_activity_invoice (fk_activity, fk_student, fk_soc, share, amount, fk_facture) VALUES ("
						.((int) $i['fk_activity']).", ".((int) $i['student']).", ".((int) $socId).", ".((float) $line['share']).", ".((float) $line['amount']).", ".((int) $id).")";
				} else {
					$sql = "INSERT INTO {$P}school_enrolment_invoice (fk_student, fk_session, fk_course, fk_soc, share, amount, fk_facture, datec) VALUES ("
						.((int) $i['student']).", ".($i['fk_session'] ? (int) $i['fk_session'] : 'NULL').", ".($i['fk_course'] ? (int) $i['fk_course'] : 'NULL').", ".((int) $socId).", ".((float) $line['share']).", ".((float) $line['amount']).", ".((int) $id).", '".$this->db->idate(dol_now())."')";
				}
				if (!$this->db->query($sql)) {
					$this->db->rollback();
					$errors[] = $inv['name'].': '.$this->db->lasterror();
					continue 2;
				}
			}
			$this->db->commit();
			$created[(int) $socId] = (int) $id;
		}

		// An activity is marked invoiced once every one of its participants is.
		foreach ($plan['invoices'] as $inv) {
			foreach ($inv['lines'] as $line) {
				if ($line['item']['kind'] === self::KIND_ACTIVITY) {
					$this->refreshActivityStatus((int) $line['item']['fk_activity']);
				}
			}
		}
		return array('created' => $created, 'errors' => $errors);
	}

	/**
	 * @param int    $classId  Class id
	 * @param string $label    What happened ("Museum visit")
	 * @param string $date     Day of the activity (YYYY-MM-DD)
	 * @param float  $price    Price per student without tax; 0 to use the product's price
	 * @param int    $product  Service product id, 0 for none
	 * @return array{id:int,error:string} error is a language key or a database message
	 */
	public function createActivity(int $classId, string $label, string $date, float $price, int $product): array
	{
		global $conf, $user;
		$P = MAIN_DB_PREFIX;
		if (trim($label) === '') {
			return array('id' => 0, 'error' => 'SchoolErrActivityLabel');
		}
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
			return array('id' => 0, 'error' => 'SchoolErrClassDates');
		}
		if ($price < 0) {
			return array('id' => 0, 'error' => 'SchoolErrActivityPrice');
		}
		if ($price <= 0 && $product <= 0) {
			return array('id' => 0, 'error' => 'SchoolErrActivityPrice');
		}
		if ($product > 0) {
			$p = $this->db->query("SELECT rowid FROM {$P}product WHERE rowid = ".$product." AND fk_product_type = 1");
			if (!$p || !$this->db->fetch_object($p)) {
				return array('id' => 0, 'error' => 'SchoolErrNotFound');
			}
		}
		$c = $this->db->query("SELECT rowid FROM {$P}school_class WHERE rowid = ".$classId);
		if (!$c || !$this->db->fetch_object($c)) {
			return array('id' => 0, 'error' => 'SchoolErrNotFound');
		}
		$sql = "INSERT INTO {$P}school_class_activity (entity, fk_class, label, date_activity, fk_product, unit_price, status, datec, fk_user_creat) VALUES ("
			.((int) $conf->entity).", ".$classId.", '".$this->db->escape(trim($label))."', '".$this->db->escape($date)."', ".($product > 0 ? $product : 'NULL').", ".$price.", 0, '".$this->db->idate(dol_now())."', ".((int) ($user->id ?? 0)).")";
		if (!$this->db->query($sql)) {
			return array('id' => 0, 'error' => $this->db->lasterror());
		}
		return array('id' => (int) $this->db->last_insert_id($P.'school_class_activity'), 'error' => '');
	}

	/**
	 * Delete an activity, unless something was invoiced for it.
	 *
	 * @param int $activityId Activity id
	 * @return string '' when deleted, else a language key
	 */
	public function deleteActivity(int $activityId): string
	{
		$P = MAIN_DB_PREFIX;
		$q = $this->db->query("SELECT COUNT(*) AS n FROM {$P}school_class_activity_invoice AS ai INNER JOIN {$P}facture AS f ON f.rowid = ai.fk_facture WHERE ai.fk_activity = ".$activityId." AND f.fk_statut <> 3");
		$o = $q ? $this->db->fetch_object($q) : null;
		if ($o && (int) $o->n > 0) {
			return 'SchoolErrActivityInvoiced';
		}
		$this->db->query("DELETE FROM {$P}school_class_activity_invoice WHERE fk_activity = ".$activityId);
		$this->db->query("DELETE FROM {$P}school_class_activity WHERE rowid = ".$activityId);
		return '';
	}

	/**
	 * Whether an enrolment is fully invoiced: the shares of the invoices that exist and are not abandoned add up to 100.
	 * An enrolment of which only one of two payers was invoiced (the other invoice failed or was deleted) is not.
	 *
	 * @param int $studentId School student id
	 * @param int $sessionId School session id, 0 when the enrolment is in a course
	 * @param int $courseId  School course id, 0 when the enrolment is in a session
	 * @return bool
	 */
	public function isEnrolmentInvoiced(int $studentId, int $sessionId, int $courseId): bool
	{
		return array_sum($this->invoicedPayers(self::KIND_ENROLMENT, $studentId, $sessionId, $courseId, 0)) >= 99.99;
	}

	/**
	 * @param int $activityId Activity id
	 * @param int $studentId  School student id
	 * @return bool True when the shares invoiced for this child add up to 100
	 */
	public function isActivityInvoiced(int $activityId, int $studentId): bool
	{
		return array_sum($this->invoicedPayers(self::KIND_ACTIVITY, $studentId, 0, 0, $activityId)) >= 99.99;
	}

	/**
	 * The payers who already have their part of an item invoiced, with the share that was invoiced: a recorded invoice that
	 * still exists and is not abandoned. A deleted or abandoned invoice does not count.
	 *
	 * @param string $kind       KIND_ENROLMENT or KIND_ACTIVITY
	 * @param int    $studentId  School student id
	 * @param int    $sessionId  School session id for an enrolment in a session
	 * @param int    $courseId   School course id for an enrolment in a course
	 * @param int    $activityId Activity id for an activity
	 * @return array<int,float> Share invoiced, by third party id
	 */
	public function invoicedPayers(string $kind, int $studentId, int $sessionId, int $courseId, int $activityId): array
	{
		$P = MAIN_DB_PREFIX;
		if ($kind === self::KIND_ACTIVITY) {
			$sql = "SELECT ai.fk_soc, SUM(ai.share) AS share FROM {$P}school_class_activity_invoice AS ai INNER JOIN {$P}facture AS f ON f.rowid = ai.fk_facture"
				." WHERE ai.fk_activity = ".$activityId." AND ai.fk_student = ".$studentId." AND f.fk_statut <> 3 GROUP BY ai.fk_soc";
		} else {
			$target = $sessionId > 0 ? "ei.fk_session = ".$sessionId : "ei.fk_course = ".$courseId;
			$sql = "SELECT ei.fk_soc, SUM(ei.share) AS share FROM {$P}school_enrolment_invoice AS ei INNER JOIN {$P}facture AS f ON f.rowid = ei.fk_facture"
				." WHERE ei.fk_student = ".$studentId." AND ".$target." AND f.fk_statut <> 3 GROUP BY ei.fk_soc";
		}
		$out = array();
		$q = $this->db->query($sql);
		while ($q && ($o = $this->db->fetch_object($q))) {
			$out[(int) $o->fk_soc] = (float) $o->share;
		}
		return $out;
	}

	/**
	 * An activity is invoiced when every participant is, and open otherwise (a late participant can still be invoiced).
	 *
	 * @param int $activityId Activity id
	 */
	private function refreshActivityStatus(int $activityId): void
	{
		$data = $this->activityParticipants($activityId);
		if (!$data['activity']) {
			return;
		}
		$all = $data['participants'] !== array();
		foreach ($data['participants'] as $p) {
			if (!$p['invoiced']) {
				$all = false;
				break;
			}
		}
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."school_class_activity SET status = ".($all ? 1 : 0)." WHERE rowid = ".$activityId);
	}
}
