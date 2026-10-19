<?php
declare(strict_types=1);

/**
 * Load Dolibarr. The module directory is outside htdocs and reached through a symlink,
 * so a relative include from the real path does not find main.inc.php.
 */

if (defined('DOL_DOCUMENT_ROOT')) {
	require_once __DIR__.'/school.lib.php';
	return;
}

$schoolCandidates = array();
if (!empty($_SERVER['DOCUMENT_ROOT'])) {
	$schoolCandidates[] = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/').'/main.inc.php';
}
if (!empty($_SERVER['SCRIPT_FILENAME'])) {
	$schoolWalk = (string) $_SERVER['SCRIPT_FILENAME'];
	for ($schoolI = 0; $schoolI < 8; $schoolI++) {
		$schoolDir = dirname($schoolWalk);
		if ($schoolDir === $schoolWalk) {
			break;
		}
		$schoolCandidates[] = $schoolDir.'/main.inc.php';
		$schoolWalk = $schoolDir;
	}
}
$schoolWalk = __DIR__;
for ($schoolI = 0; $schoolI < 8; $schoolI++) {
	$schoolCandidates[] = $schoolWalk.'/main.inc.php';
	$schoolParent = dirname($schoolWalk);
	if ($schoolParent === $schoolWalk) {
		break;
	}
	$schoolWalk = $schoolParent;
}

$schoolLoaded = false;
foreach ($schoolCandidates as $schoolCandidate) {
	if ($schoolCandidate !== '' && is_file($schoolCandidate)) {
		require $schoolCandidate;
		$schoolLoaded = true;
		break;
	}
}
if (!$schoolLoaded) {
	print 'Include of main fails';
	exit(1);
}
require_once __DIR__.'/school.lib.php';
