<?php
declare(strict_types=1);

/**
 * \file    test/cli/mcp_tools_check.php
 * \ingroup school
 * \brief   The School tools are registered by the MCP handler of the core it runs against, and every one is consistent:
 *          declared rights, a handler method, a write verb exactly for the tools that write. Run it against every core the
 *          module supports (24.0.0 and later). Nothing is written: the tools that would call Chamilo or change data are
 *          not called. See school_cli_bootstrap.inc.php.
 */

require_once __DIR__.'/school_cli_bootstrap.inc.php';

/** @var DoliDB $db */
/** @var Conf $conf */
/** @var User $user */
require_once DOL_DOCUMENT_ROOT.'/ai/class/mcp.class.php';

print 'Dolibarr '.DOL_VERSION."\n";
$handler = new McpHandler($db, $user, $conf, McpHandler::CTX_MCP_SERVER);
// Newer cores load the tools when asked, older ones in the constructor.
if ((new ReflectionMethod($handler, 'loadTools'))->isPublic()) {
	$handler->loadTools();
}
$names = array();
foreach ($handler->getToolsSchemaUnfiltered() as $definition) {
	$names[] = (string) ($definition['name'] ?? '');
}
$mine = array_values(array_filter($names, static fn($name) => strpos($name, '_school_') !== false));

schoolCliCheck('the core tools are there', count($names) > count($mine));
schoolCliCheck('the School tools are registered ('.count($mine).')', count($mine) >= 42);
schoolCliCheck('a tool of each class is listed', count(array_intersect($mine, array('list_school_courses', 'list_school_students', 'list_school_classes', 'list_school_billable', 'list_school_rooms', 'list_school_sync_jobs'))) === 6);
schoolCliCheck('no tool name is registered twice', count($names) === count(array_unique($names)));

$loaded = new ReflectionProperty($handler, 'loadedTools');
$loaded->setAccessible(true);
$tools = array();
foreach ($loaded->getValue($handler) as $tool) {
	foreach ($tool->getDefinitions() as $definition) {
		if (strpos((string) $definition['name'], '_school_') !== false) {
			$tools[(string) $definition['name']] = array($tool, $definition);
		}
	}
}

// Dolibarr's tool access screen labels a tool "Modify" when its name starts with one of these verbs, and its "View only"
// preset allows every other name: a tool that writes must start with one, a read tool must not.
$writeVerbs = '/^(create|update|delete|add|remove|change|write|edit|validate|pay|send)/i';
$wrong = array();
$noRights = array();
$noHandler = array();
$noSchema = array();
foreach ($tools as $name => [$tool, $definition]) {
	$rights = $tool->getRequiredRights($name);
	if (!is_array($rights) || !$rights) {
		$noRights[] = $name;
	}
	if (!method_exists($tool, 'run_'.$name)) {
		$noHandler[] = $name;
	}
	if (($definition['inputSchema']['type'] ?? '') !== 'object' || trim((string) ($definition['description'] ?? '')) === '') {
		$noSchema[] = $name;
	}
	$writes = (string) $tool->writeConfirmationPreview($name, array()) !== '';
	if ((bool) preg_match($writeVerbs, $name) !== $writes) {
		$wrong[] = $name;
	}
}
schoolCliCheck('every tool declares rights'.($noRights ? ' ('.implode(', ', $noRights).')' : ''), !$noRights);
schoolCliCheck('every tool has a handler method'.($noHandler ? ' ('.implode(', ', $noHandler).')' : ''), !$noHandler);
schoolCliCheck('every tool has a description and an object schema'.($noSchema ? ' ('.implode(', ', $noSchema).')' : ''), !$noSchema);
schoolCliCheck('a tool that writes starts with a write verb and a read tool does not'.($wrong ? ' ('.implode(', ', $wrong).')' : ''), !$wrong);

// Called without arguments, a tool answers with its own result or error, never "Unknown tool". The tools that talk to
// Chamilo or that change data from nothing are left out; a transaction undoes the rest.
$skip = array('update_school_catalog_from_chamilo', 'update_school_rooms_from_chamilo', 'send_school_changes_to_chamilo', 'check_school_reconciliation');
$db->begin();
$unknown = array();
foreach ($tools as $name => [$tool, $definition]) {
	if (in_array($name, $skip, true)) {
		continue;
	}
	$result = $tool->execute($name, array());
	if (is_array($result) && ($result['error'] ?? '') === 'Unknown tool') {
		$unknown[] = $name;
	}
}
schoolCliCheck('every tool name is handled by its class'.($unknown ? ' (unknown: '.implode(', ', $unknown).')' : ''), !$unknown);

// A user without the module's rights is refused by every tool, whatever the core checks beforehand.
$nobody = new User($db);
$nobody->id = 0;
$nobody->rights = new stdClass();
$denied = 0;
$allowed = array();
foreach ($tools as $name => [$tool, $definition]) {
	$class = get_class($tool);
	$limited = new $class($db, $nobody, $conf);
	$result = $limited->execute($name, array());
	if (is_array($result) && strpos((string) ($result['error'] ?? ''), 'Permission denied') === 0) {
		$denied++;
	} else {
		$allowed[] = $name;
	}
}
schoolCliCheck('a user without rights is refused by every tool'.($allowed ? ' (not refused: '.implode(', ', $allowed).')' : ''), !$allowed && $denied === count($tools));

schoolCliFinish();
