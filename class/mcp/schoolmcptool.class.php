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
 * \file    class/mcp/schoolmcptool.class.php
 * \ingroup school
 * \brief   Shared helpers for the School MCP tools.
 */

require_once DOL_DOCUMENT_ROOT.'/ai/class/mcptool.class.php';
require_once __DIR__.'/../../lib/school.lib.php';
require_once __DIR__.'/../../lib/schoollogic.lib.php';

/**
 * Base class of the School tools. The tools call the same classes as the pages, so the rules (what is queued, what is
 * refused, what is checked) are the ones of the screens. Names are verb first with "school" after the verb.
 *
 * The rights and the write confirmation are read by Dolibarr 25 before a tool runs; Dolibarr 24.0.0 reads neither, so each
 * tool also checks its own declared rights (denyMissingRights) and nothing here needs a 25-only method.
 */
abstract class SchoolMcpTool extends McpTool
{
	/** Returned by writeConfirmationPreview() for a tool that only reads. */
	protected const READ_ONLY = '';

	/** Rows returned by a list tool at most. */
	protected const MAX_ROWS = 200;

	/**
	 * @param DoliDB $db   Database
	 * @param User   $user Caller
	 * @param Conf   $conf Configuration
	 */
	public function __construct($db, $user, $conf)
	{
		parent::__construct($db, $user, $conf);
		schoolLoadLangs();
	}

	/**
	 * @return string[]
	 */
	public function getCategories(): array
	{
		return array('school');
	}

	/**
	 * @param array<string,mixed> $properties Schema properties
	 * @param string[]            $required   Required names
	 * @return array<string,mixed>
	 */
	protected function definition(string $name, string $description, array $properties, array $required = array()): array
	{
		$schema = array('type' => 'object', 'properties' => $properties);
		if (count($required) > 0) {
			$schema['required'] = $required;
		}
		return array('name' => $name, 'description' => $description, 'inputSchema' => $schema);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 */
	protected function argInt(array $args, string $key): int
	{
		return isset($args[$key]) && !is_array($args[$key]) ? (int) $args[$key] : 0;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 */
	protected function argFloat(array $args, string $key): float
	{
		return isset($args[$key]) && is_numeric($args[$key]) ? (float) $args[$key] : 0.0;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 */
	protected function argBool(array $args, string $key, bool $default = false): bool
	{
		return isset($args[$key]) ? filter_var($args[$key], FILTER_VALIDATE_BOOLEAN) : $default;
	}

	/**
	 * One line of text, no markup.
	 *
	 * @param array<string,mixed> $args Arguments
	 */
	protected function argString(array $args, string $key): string
	{
		if (!isset($args[$key]) || is_array($args[$key])) {
			return '';
		}
		return trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $args[$key])));
	}

	/**
	 * A text of several lines, no markup.
	 *
	 * @param array<string,mixed> $args Arguments
	 */
	protected function argText(array $args, string $key): string
	{
		if (!isset($args[$key]) || is_array($args[$key])) {
			return '';
		}
		$text = str_replace(array("\r\n", "\r"), "\n", strip_tags((string) $args[$key]));
		return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text));
	}

	/**
	 * A list of whole numbers.
	 *
	 * @param array<string,mixed> $args Arguments
	 * @return list<int>
	 */
	protected function argIds(array $args, string $key): array
	{
		$ids = array();
		foreach ((array) ($args[$key] ?? array()) as $v) {
			if (!is_array($v) && (int) $v > 0) {
				$ids[(int) $v] = (int) $v;
			}
		}
		return array_values($ids);
	}

	/**
	 * A date as YYYY-MM-DD, or null when it is not a real date.
	 *
	 * @param array<string,mixed> $args Arguments
	 */
	protected function argDay(array $args, string $key): ?string
	{
		$v = $this->argString($args, $key);
		return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
	}

	/**
	 * Rows to return at most.
	 *
	 * @param array<string,mixed> $args Arguments
	 */
	protected function limit(array $args): int
	{
		$n = $this->argInt($args, 'limit');
		return $n > 0 ? min($n, self::MAX_ROWS) : 100;
	}

	/**
	 * @return array{error:string}
	 */
	protected function fail(string $message): array
	{
		global $langs;
		return array('error' => strncmp($message, 'School', 6) === 0 ? $langs->transnoentitiesnoconv($message) : $message);
	}

	/**
	 * Check the rights this tool declares, from inside the tool.
	 *
	 * @return array{error:string}|null null when every declared right is held
	 */
	protected function denyMissingRights(string $toolName): ?array
	{
		$declared = $this->getRequiredRights($toolName);
		if (!is_array($declared)) {
			return $this->fail('Permission denied');
		}
		foreach ($declared as $right) {
			$right = (array) $right;
			$module = (string) ($right[0] ?? '');
			$perm = (string) ($right[1] ?? '');
			$subperm = (string) ($right[2] ?? '');
			if ($module === '' || !$this->user->hasRight($module, $perm, $subperm)) {
				return $this->fail('Permission denied: the right '.$module.'/'.$perm.($subperm !== '' ? '/'.$subperm : '').' is required');
			}
		}
		return null;
	}

	/**
	 * What the tools of the class are. One entry per tool name: description, props (JSON schema properties), required,
	 * right (a School right, or "module:right" for another module's right; a list for several), write (true when it
	 * changes something, so the caller is asked to confirm) and preview (the confirmation text, with {arg} replaced by the
	 * value of that argument). The tool runs in the method named run_ followed by its name.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	abstract protected function specs(): array;

	/**
	 * @return array<string,list<string>> Tool name => rights
	 */
	protected function rightsByTool(): array
	{
		$map = array();
		foreach ($this->specs() as $name => $spec) {
			$map[$name] = (array) $spec['right'];
		}
		return $map;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function getDefinitions(): array
	{
		$out = array();
		foreach ($this->specs() as $name => $spec) {
			$out[] = $this->definition($name, (string) $spec['desc'], (array) ($spec['props'] ?? array()), (array) ($spec['required'] ?? array()));
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 */
	public function writeConfirmationPreview(string $toolName, array $args)
	{
		$spec = $this->specs()[$toolName] ?? null;
		if ($spec === null || empty($spec['write'])) {
			return self::READ_ONLY;
		}
		$text = (string) ($spec['preview'] ?? $spec['desc']);
		return (string) preg_replace_callback('/\{(\w+)\}/', function (array $m) use ($args): string {
			return isset($args[$m[1]]) && is_array($args[$m[1]]) ? count($args[$m[1]]).' item(s)' : $this->argString($args, $m[1]);
		}, $text);
	}

	/**
	 * @param array<string,mixed> $args Arguments
	 * @return array<int|string,mixed>
	 */
	public function execute(string $toolName, array $args)
	{
		if (!isset($this->specs()[$toolName])) {
			return $this->fail('Unknown tool');
		}
		$denied = $this->denyMissingRights($toolName);
		if ($denied !== null) {
			return $denied;
		}
		return $this->{'run_'.$toolName}($args);
	}

	/**
	 * @return array<int,array<int,string>>
	 */
	public function getRequiredRights(string $toolName)
	{
		$map = $this->rightsByTool();
		if (!isset($map[$toolName])) {
			return defined('McpTool::RIGHTS_UNDECLARED') ? McpTool::RIGHTS_UNDECLARED : 'undeclared';
		}
		$out = array();
		foreach ($map[$toolName] as $right) {
			$out[] = strpos($right, ':') !== false ? explode(':', $right, 2) : array('school', $right);
		}
		return $out;
	}

	/**
	 * The result of a write that went through: one row, like the CRM tools.
	 *
	 * @param array<string,mixed> $row Values
	 * @return array<int,array<string,mixed>>
	 */
	protected function done(array $row): array
	{
		return array($row);
	}

	/**
	 * One query, as objects.
	 *
	 * @return list<object>
	 */
	protected function rows(string $sql): array
	{
		$rows = array();
		$q = $this->db->query($sql);
		while ($q && ($o = $this->db->fetch_object($q))) {
			$rows[] = $o;
		}
		return $rows;
	}

	/**
	 * @param int|string|null $ts Timestamp, or a database date
	 */
	protected function iso($ts): ?string
	{
		if ($ts === null || $ts === '') {
			return null;
		}
		$t = is_numeric($ts) ? (int) $ts : (int) $this->db->jdate($ts);
		return $t > 0 ? dol_print_date($t, '%Y-%m-%d %H:%M', 'gmt') : null;
	}

	/**
	 * Entity of the data.
	 */
	protected function entity(): int
	{
		return (int) $this->conf->entity;
	}
}
