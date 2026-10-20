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
 * \file    class/actions_school.class.php
 * \ingroup school
 * \brief   Hook: the School tools of Dolibarr's MCP server.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

/**
 * Hook class. Dolibarr loads ActionsSchool from class/actions_school.class.php for the contexts the module lists.
 */
class ActionsSchool extends CommonHookActions
{
	/** @var DoliDB */
	public $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Give McpHandler one instance per tool class.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param object              $object     MCP handler
	 * @param string              $action     Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0
	 */
	public function addMcpTools($parameters, &$object, &$action, $hookmanager)
	{
		if (!is_array($parameters) || (($parameters['currentcontext'] ?? '') !== 'aimcp') || !isModEnabled('school')) {
			return 0;
		}
		$db = $parameters['db'] ?? $this->db;
		$user = $parameters['user'] ?? null;
		$conf = $parameters['conf'] ?? null;
		if (!is_object($user) || !is_object($conf)) {
			return 0;
		}

		require_once __DIR__.'/mcp/schoolcatalogtool.class.php';
		require_once __DIR__.'/mcp/schoolstudenttool.class.php';
		require_once __DIR__.'/mcp/schoolclasstool.class.php';
		require_once __DIR__.'/mcp/schoolbillingtool.class.php';
		require_once __DIR__.'/mcp/schoolscheduletool.class.php';
		require_once __DIR__.'/mcp/schoolsynctool.class.php';

		// One array of tools, itself inside the results array: the Dolibarr 24.0.0 loader skips a bare tool object in the
		// results, so a flat list would register nothing on 24.0.0.
		$this->results = array(array(
			new SchoolCatalogTool($db, $user, $conf),
			new SchoolStudentTool($db, $user, $conf),
			new SchoolClassTool($db, $user, $conf),
			new SchoolBillingTool($db, $user, $conf),
			new SchoolScheduleTool($db, $user, $conf),
			new SchoolSyncTool($db, $user, $conf),
		));
		return 0;
	}
}
