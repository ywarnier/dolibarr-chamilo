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
 * \file    class/chamilosetup.class.php
 * \ingroup school
 * \brief   Extra fields the module needs in Chamilo, and reading/writing their values.
 */

require_once __DIR__.'/chamiloclient.class.php';

/**
 * Chamilo extra fields: `dolibarr_id` on users (the Dolibarr user id), courses and sessions (the Dolibarr product id),
 * and `price` on courses and sessions.
 * `price` is the same field the WordPress storefront plugin reads, so one value serves both.
 */
class ChamiloSetup
{
	public const ITEM_USER = 1;
	public const ITEM_COURSE = 2;
	public const ITEM_SESSION = 3;
	public const TYPE_TEXT = 1;
	public const TYPE_FLOAT = 17;

	/** @var ChamiloClient */
	private $client;
	/** @var array<string,array<int,array{id:int,value:string}>> field id => item id => value row */
	private $values = array();

	/**
	 * @param ChamiloClient $client Client
	 */
	public function __construct(ChamiloClient $client)
	{
		$this->client = $client;
	}

	/**
	 * Field definitions the module needs. Key is the setting that remembers the Chamilo field id.
	 *
	 * @return array<string,array{item:int,type:int,variable:string,label:string}>
	 */
	public static function requiredFields(): array
	{
		return array(
			'SCHOOL_EF_USER_DOLIBARR_ID' => array('item' => self::ITEM_USER, 'type' => self::TYPE_TEXT, 'variable' => 'dolibarr_id', 'label' => 'Dolibarr user ID'),
			'SCHOOL_EF_COURSE_DOLIBARR_ID' => array('item' => self::ITEM_COURSE, 'type' => self::TYPE_TEXT, 'variable' => 'dolibarr_id', 'label' => 'Dolibarr product ID'),
			'SCHOOL_EF_COURSE_PRICE' => array('item' => self::ITEM_COURSE, 'type' => self::TYPE_FLOAT, 'variable' => 'price', 'label' => 'Price'),
			'SCHOOL_EF_SESSION_DOLIBARR_ID' => array('item' => self::ITEM_SESSION, 'type' => self::TYPE_TEXT, 'variable' => 'dolibarr_id', 'label' => 'Dolibarr product ID'),
			'SCHOOL_EF_SESSION_PRICE' => array('item' => self::ITEM_SESSION, 'type' => self::TYPE_FLOAT, 'variable' => 'price', 'label' => 'Price'),
		);
	}

	/**
	 * Create the field unless Chamilo already has one with this variable for this item type.
	 *
	 * @param int    $item     Item type (2 course, 3 session)
	 * @param int    $type     Value type
	 * @param string $variable Variable name
	 * @param string $label    Display text
	 * @return array{ok:bool,id:int,created:bool,error:string}
	 */
	public function ensureField(int $item, int $type, string $variable, string $label): array
	{
		$list = $this->client->request('GET', '/api/extra_fields', array('itemsPerPage' => 500));
		if (!$list['ok']) {
			return array('ok' => false, 'id' => 0, 'created' => false, 'error' => $list['error']);
		}
		foreach (ChamiloClient::members($list['data']) as $f) {
			if (is_array($f) && (int) ($f['itemType'] ?? 0) === $item && ($f['variable'] ?? '') === $variable) {
				return array('ok' => true, 'id' => (int) $f['id'], 'created' => false, 'error' => '');
			}
		}
		$res = $this->client->request('POST', '/api/extra_fields', array(), array(
			'itemType' => $item,
			'valueType' => $type,
			'variable' => $variable,
			'displayText' => $label,
		));
		if (!$res['ok'] || !is_array($res['data'])) {
			return array('ok' => false, 'id' => 0, 'created' => false, 'error' => $res['error']);
		}
		return array('ok' => true, 'id' => (int) ($res['data']['id'] ?? 0), 'created' => true, 'error' => '');
	}

	/**
	 * Create the four fields and remember their ids in the module settings.
	 *
	 * @param DoliDB $db Database handler
	 * @return array<string,array{ok:bool,id:int,created:bool,error:string}> by setting name
	 */
	public function ensureAll($db): array
	{
		global $conf;
		$out = array();
		foreach (self::requiredFields() as $const => $def) {
			$r = $this->ensureField($def['item'], $def['type'], $def['variable'], $def['label']);
			if ($r['ok'] && $r['id'] > 0) {
				dolibarr_set_const($db, $const, (string) $r['id'], 'chaine', 0, '', (int) $conf->entity);
			}
			$out[$const] = $r;
		}
		return $out;
	}

	/**
	 * Write the value of a field for one course or session: add it, or change it when it differs.
	 *
	 * @param int    $fieldId Chamilo extra field id
	 * @param int    $itemId  Course or session id in Chamilo
	 * @param string $value   Value
	 * @return array{ok:bool,action:string,error:string} action is created, updated or unchanged
	 */
	public function setValue(int $fieldId, int $itemId, string $value): array
	{
		$rows = $this->valuesOfField($fieldId);
		if ($rows === null) {
			return array('ok' => false, 'action' => '', 'error' => 'Could not read the values of field '.$fieldId);
		}
		if (isset($rows[$itemId])) {
			if ($rows[$itemId]['value'] === $value) {
				return array('ok' => true, 'action' => 'unchanged', 'error' => '');
			}
			$res = $this->client->request('PATCH', '/api/extra_field_values/'.$rows[$itemId]['id'], array(), array('fieldValue' => $value));
			if ($res['ok']) {
				$this->values[(string) $fieldId][$itemId]['value'] = $value;
			}
			return array('ok' => $res['ok'], 'action' => 'updated', 'error' => $res['error']);
		}
		$res = $this->client->request('POST', '/api/extra_field_values', array(), array(
			'field' => '/api/extra_fields/'.$fieldId,
			'itemId' => $itemId,
			'fieldValue' => $value,
		));
		if ($res['ok'] && is_array($res['data'])) {
			$this->values[(string) $fieldId][$itemId] = array('id' => (int) ($res['data']['id'] ?? 0), 'value' => $value);
		}
		return array('ok' => $res['ok'], 'action' => 'created', 'error' => $res['error']);
	}

	/**
	 * Every stored value of a field, keyed by item id. The API cannot filter by item, so the field is read once.
	 *
	 * @param int $fieldId Field id
	 * @return array<int,array{id:int,value:string}>|null null when Chamilo refused
	 */
	private function valuesOfField(int $fieldId): ?array
	{
		$key = (string) $fieldId;
		if (isset($this->values[$key])) {
			return $this->values[$key];
		}
		$rows = array();
		for ($page = 1; $page <= 200; $page++) {
			$res = $this->client->request('GET', '/api/extra_field_values', array('field' => $fieldId, 'itemsPerPage' => 100, 'page' => $page));
			if (!$res['ok']) {
				return null;
			}
			$items = ChamiloClient::members($res['data']);
			foreach ($items as $v) {
				if (is_array($v) && isset($v['itemId'])) {
					$rows[(int) $v['itemId']] = array('id' => (int) $v['id'], 'value' => (string) ($v['fieldValue'] ?? ''));
				}
			}
			if (count($items) < 100) {
				break;
			}
		}
		$this->values[$key] = $rows;
		return $rows;
	}
}
