<?php
declare(strict_types=1);

/**
 * \file    test/cli/lang_check.php
 * \ingroup school
 * \brief   Checks the language files of the module against en_US, which is the source: the same keys, the same
 *          placeholders, no empty value, no duplicate key, no line that is neither a comment nor key=value. Also checks
 *          that every key the code uses is defined in en_US. It does not load Dolibarr.
 *
 * Usage: php test/cli/lang_check.php   (exit 1 when something is wrong)
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}

$root = dirname(__DIR__, 2);
$failures = 0;

/**
 * @return array{keys:array<string,string>,problems:list<string>}
 */
function schoolLangRead(string $file): array
{
	$keys = array();
	$problems = array();
	foreach (file($file, FILE_IGNORE_NEW_LINES) ?: array() as $n => $line) {
		$line = rtrim($line, "\r");
		if (trim($line) === '' || $line[0] === '#') {
			continue;
		}
		if (strpos($line, '=') === false) {
			$problems[] = 'line '.($n + 1).' has no "="';
			continue;
		}
		[$k, $v] = explode('=', $line, 2);
		if (!preg_match('/^[A-Za-z0-9_]+$/', $k)) {
			$problems[] = 'line '.($n + 1).' has a bad key "'.$k.'"';
		}
		if (array_key_exists($k, $keys)) {
			$problems[] = 'duplicate key '.$k;
		}
		if (trim($v) === '') {
			$problems[] = 'empty value for '.$k;
		}
		// Dolibarr runs a value through sprintf() when it has arguments: a stray % raises an error in PHP 8.
		if (preg_match('/%(?!(?:\d+\$)?[sd]|%)/', $v)) {
			$problems[] = 'stray % in '.$k;
		}
		$keys[$k] = $v;
	}
	return array('keys' => $keys, 'problems' => $problems);
}

/**
 * @return list<string> The placeholders of a text, sorted
 */
function schoolLangPlaceholders(string $text): array
{
	preg_match_all('/%(?:\d+\$)?[sd]/', $text, $m);
	$out = $m[0];
	sort($out);
	return $out;
}

$source = schoolLangRead($root.'/langs/en_US/school.lang');
foreach ($source['problems'] as $p) {
	print "FAIL en_US: $p\n";
	$failures++;
}

$locales = array();
foreach (glob($root.'/langs/*', GLOB_ONLYDIR) ?: array() as $dir) {
	$locale = basename($dir);
	if ($locale === 'en_US' || !is_file($dir.'/school.lang')) {
		continue;
	}
	$locales[] = $locale;
	$lang = schoolLangRead($dir.'/school.lang');
	$problems = $lang['problems'];
	$missing = array_diff(array_keys($source['keys']), array_keys($lang['keys']));
	$extra = array_diff(array_keys($lang['keys']), array_keys($source['keys']));
	if ($missing) {
		$problems[] = count($missing).' missing key(s): '.implode(', ', array_slice($missing, 0, 5));
	}
	if ($extra) {
		$problems[] = count($extra).' extra key(s): '.implode(', ', array_slice($extra, 0, 5));
	}
	foreach ($source['keys'] as $k => $v) {
		if (isset($lang['keys'][$k]) && schoolLangPlaceholders($v) !== schoolLangPlaceholders($lang['keys'][$k])) {
			$problems[] = 'placeholders of '.$k.' differ from en_US';
		}
	}
	if ($problems) {
		print "FAIL $locale: ".implode('; ', array_slice($problems, 0, 6)).(count($problems) > 6 ? ' (+'.(count($problems) - 6).')' : '')."\n";
		$failures++;
	}
}
if ($failures === 0) {
	print 'PASS '.count($locales).' locale(s) match en_US'."\n";
}

// Every key the code uses is defined in en_US (dynamic keys are checked through their prefix).
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$missingInCode = array();
$prefixes = array();
foreach ($iterator as $file) {
	$path = $file->getPathname();
	if (substr($path, -4) !== '.php' || strpos($path, '/test/') !== false || strpos($path, '/.git/') !== false) {
		continue;
	}
	$code = (string) file_get_contents($path);
	if (preg_match_all('/trans(?:noentities|noentitiesnoconv)?\(\s*\'([A-Za-z0-9_]+)\'(\s*\.)?/', $code, $m, PREG_SET_ORDER)) {
		foreach ($m as $hit) {
			if (!empty($hit[2])) {
				$prefixes[$hit[1]] = true;
			} elseif (strpos($hit[1], 'School') === 0 && !isset($source['keys'][$hit[1]])) {
				$missingInCode[$hit[1]] = basename($path);
			}
		}
	}
	// Language keys that a class or a tool returns as an error, or the descriptor names.
	if (preg_match_all('/\'(School(?:Err|Job|Mailing|Invoice|Not|Queue)[A-Za-z0-9]*)\'/', $code, $m)) {
		foreach ($m[1] as $k) {
			if (!isset($source['keys'][$k])) {
				$missingInCode[$k] = basename($path);
			}
		}
	}
}
foreach (array_keys($prefixes) as $prefix) {
	$n = 0;
	foreach ($source['keys'] as $k => $v) {
		$n += strpos($k, $prefix) === 0 ? 1 : 0;
	}
	if ($n === 0) {
		$missingInCode[$prefix.'*'] = 'dynamic key';
	}
}
foreach ($missingInCode as $k => $where) {
	print "FAIL the code uses $k ($where) and en_US does not define it\n";
	$failures++;
}
if (!$missingInCode) {
	print "PASS every key used by the code is defined in en_US\n";
}
print $failures === 0 ? "All checks passed\n" : $failures." check(s) failed\n";
exit($failures === 0 ? 0 : 1);
