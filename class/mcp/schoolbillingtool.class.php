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
 * \file    class/mcp/schoolbillingtool.class.php
 * \ingroup school
 * \brief   MCP tools for billing: what can be invoiced, and draft invoices to the paying parents.
 */

require_once __DIR__.'/schoolmcptool.class.php';
require_once __DIR__.'/../schoolbilling.class.php';

class SchoolBillingTool extends SchoolMcpTool
{
	/**
	 * @return array<string,array<string,mixed>>
	 */
	protected function specs(): array
	{
		return array(
			'list_school_billable' => array(
				'desc' => 'List what can be invoiced: enrolments that are not invoiced yet, or, with activity_id, the participants of a class activity who are not invoiced yet. Each item has a key, the amount excl. tax, the paying parents with their share and the shares that would be proposed. Use the keys with create_school_invoices.',
				'props' => array('activity_id' => array('type' => 'integer')),
				'right' => 'invoice',
			),
			'create_school_invoices' => array(
				'desc' => 'Create DRAFT invoices (never validated) for the chosen items: one invoice per paying third party with a line per child. Each item is split between the paying parents; the shares, which must add up to 100 per student, are proposed from what was kept last time, or given in shares, and are kept for next time. Nothing is created if any item cannot be planned (no payer, no price, shares not 100).',
				'props' => array(
					'keys' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Item keys from list_school_billable'),
					'activity_id' => array('type' => 'integer', 'description' => 'Give it when the keys are those of a class activity'),
					'shares' => array('type' => 'array', 'description' => 'Optional split chosen now', 'items' => array(
						'type' => 'object',
						'properties' => array('student_id' => array('type' => 'integer'), 'third_party_id' => array('type' => 'integer'), 'share' => array('type' => 'number', 'description' => 'Percentage')),
						'required' => array('student_id', 'third_party_id', 'share'),
					)),
				),
				'required' => array('keys'),
				'right' => array('invoice', 'facture:creer'), 'write' => true,
				'preview' => 'Create draft invoices for the chosen school items. Nothing is validated or sent.',
			),
		);
	}

	/**
	 * The items that can be invoiced, by key.
	 *
	 * @param SchoolBilling $billing    Billing
	 * @param int           $activityId Activity id, 0 for the enrolments
	 * @return array<string,array<string,mixed>>|null null when the activity does not exist
	 */
	private function items(SchoolBilling $billing, int $activityId): ?array
	{
		if ($activityId > 0) {
			$data = $billing->activityParticipants($activityId);
			if (!$data['activity']) {
				return null;
			}
			$items = $billing->activityItems($activityId, array_column($data['participants'], 'student'));
		} else {
			$items = $billing->billableEnrolments();
		}
		$byKey = array();
		foreach ($items as $i) {
			$byKey[(string) $i['key']] = $i;
		}
		return $byKey;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_list_school_billable(array $args): array
	{
		$billing = new SchoolBilling($this->db);
		$items = $this->items($billing, $this->argInt($args, 'activity_id'));
		if ($items === null) {
			return $this->fail('SchoolErrNotFound');
		}
		$rows = array();
		foreach (array_slice($items, 0, self::MAX_ROWS) as $key => $i) {
			$student = (int) $i['student'];
			$payers = array();
			$proposed = $billing->proposedShares($student);
			foreach ($billing->payers($student) as $soc => $p) {
				$payers[] = array('third_party_id' => (int) $soc, 'name' => (string) $p['name'], 'kept_share' => (float) $p['share'], 'proposed_share' => (float) ($proposed[$soc] ?? 0));
			}
			$rows[] = array('key' => (string) $key, 'kind' => (string) $i['kind'], 'student_id' => $student, 'student' => (string) $i['student_name'], 'label' => (string) $i['label'], 'amount' => (float) $i['amount'],
				'date_start' => $i['date_start'] ? dol_print_date((int) $i['date_start'], '%Y-%m-%d') : null, 'payers' => $payers);
		}
		return $rows;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	protected function run_create_school_invoices(array $args): array
	{
		if (!isModEnabled('invoice')) {
			return $this->fail('SchoolInvoiceModuleOff');
		}
		$billing = new SchoolBilling($this->db);
		$byKey = $this->items($billing, $this->argInt($args, 'activity_id'));
		if ($byKey === null) {
			return $this->fail('SchoolErrNotFound');
		}
		$selected = array();
		foreach ((array) ($args['keys'] ?? array()) as $key) {
			if (!is_array($key) && isset($byKey[(string) $key])) {
				$selected[(string) $key] = $byKey[(string) $key];
			}
		}
		if (!$selected) {
			return $this->fail('No item matches the keys: read them with list_school_billable');
		}
		$overrides = array();
		foreach ((array) ($args['shares'] ?? array()) as $row) {
			$row = (array) $row;
			$overrides[$this->argInt($row, 'student_id')][$this->argInt($row, 'third_party_id')] = $this->argFloat($row, 'share');
		}
		$done = $billing->invoice(array_values($selected), $overrides, $this->user);
		if ($done['planErrors']) {
			global $langs;
			$lines = array();
			foreach ($done['planErrors'] as $e) {
				$lines[] = trim(($e['student'] ?? '').(($e['label'] ?? '') !== '' ? ' ('.$e['label'].')' : '').': '.$langs->trans((string) $e['key']), ': ');
			}
			return array('error' => 'Nothing was invoiced: '.implode(' | ', $lines));
		}
		$invoices = array();
		foreach ($done['created'] as $socId => $factureId) {
			$f = new Facture($this->db);
			$f->fetch((int) $factureId);
			$invoices[] = array('third_party_id' => (int) $socId, 'invoice_id' => (int) $factureId, 'ref' => (string) $f->ref, 'total_ht' => (float) $f->total_ht, 'status' => 'draft');
		}
		return array(array('invoices' => $invoices, 'errors' => $done['errors']));
	}
}
