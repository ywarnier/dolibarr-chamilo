<?php
declare(strict_types=1);

/**
 * \file    test/cli/school_cli_bootstrap.inc.php
 * \ingroup school
 * \brief   Shared start of the command-line checks: loads Dolibarr without a login, opens a transaction, counts results.
 *
 * Usage: php test/cli/<check>.php /path/to/dolibarr/htdocs
 * (or set DOLIBARR_HTDOCS). The module must be enabled and the database reachable. Every check runs inside a
 * transaction that is rolled back at the end, so nothing is kept; run it on a development instance anyway.
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}

$schoolCliHtdocs = (string) ($argv[1] ?? getenv('DOLIBARR_HTDOCS') ?: '');
if ($schoolCliHtdocs === '' || !is_file(rtrim($schoolCliHtdocs, '/').'/main.inc.php')) {
	fwrite(STDERR, "Usage: php ".basename((string) $argv[0])." /path/to/dolibarr/htdocs (or set DOLIBARR_HTDOCS)\n");
	exit(2);
}
$schoolCliHtdocs = rtrim($schoolCliHtdocs, '/');

foreach (array('NOLOGIN', 'NOSESSION', 'NOCSRFCHECK', 'NOREQUIREMENU', 'NOREQUIREHTML', 'NOREQUIREAJAX') as $schoolCliConst) {
	define($schoolCliConst, 1);
}
$_SERVER['DOCUMENT_ROOT'] = $schoolCliHtdocs;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = (string) (getenv('DOLIBARR_HOST') ?: 'localhost');
chdir($schoolCliHtdocs);
require $schoolCliHtdocs.'/main.inc.php';

require_once __DIR__.'/../../lib/school.lib.php';
schoolLoadLangs();

/** @var DoliDB $db */
$user = new User($db);
$user->fetch(1);
$user->loadRights();

$schoolCliFailures = 0;

/**
 * Print one result.
 */
function schoolCliCheck(string $name, bool $ok): void
{
	global $schoolCliFailures;
	print ($ok ? 'PASS ' : 'FAIL ').$name."\n";
	if (!$ok) {
		$schoolCliFailures++;
	}
}

/**
 * Undo everything the check did and exit with a status for the shell.
 */
function schoolCliFinish(): void
{
	global $db, $schoolCliFailures;
	$db->rollback();
	print ($schoolCliFailures === 0 ? 'All checks passed' : $schoolCliFailures.' check(s) failed')."\n";
	exit($schoolCliFailures === 0 ? 0 : 1);
}
