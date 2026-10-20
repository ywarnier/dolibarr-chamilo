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
 * \file    reconcile.php
 * \ingroup school
 * \brief   Reconciliation report: where Dolibarr and Chamilo disagree, and the jobs that would put it right.
 */

require_once __DIR__.'/lib/school_bootstrap.inc.php';
require_once __DIR__.'/class/schoolreconcile.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

schoolStartPage();
schoolRequireRight('sync');

$self = dol_buildpath('/school/reconcile.php', 1).'?mainmenu=school&leftmenu=schoolreconcile';
$action = GETPOST('action', 'aZ09');
$areas = array_values(array_intersect(SchoolReconcile::areas(), array_map('strval', (array) GETPOST('areas', 'array'))));
$client = schoolGetClient();
$report = null;

// Queue the jobs chosen in the report. What arrives is checked against the jobs this page can offer, and the object must exist.
if ($action === 'fix') {
	$queued = SchoolReconcile::queueFixes($db, array_map('strval', (array) GETPOST('fixes', 'array')))['queued'];
	setEventMessages($langs->trans('SchoolReconcileQueued', $queued), null, $queued > 0 ? 'mesgs' : 'warnings');
	header('Location: '.dol_buildpath('/school/sync_log.php', 1).'?mainmenu=school&leftmenu=schoolsynclog');
	exit;
}

if ($action === 'check') {
	if ($client === null) {
		setEventMessages($langs->trans('SchoolNotConfigured'), null, 'errors');
	} elseif (!$areas) {
		setEventMessages($langs->trans('SchoolReconcileChoose'), null, 'warnings');
	} else {
		$report = (new SchoolReconcile($db, $client))->run($areas);
	}
}

llxHeader('', $langs->trans('SchoolReconcile'));
print load_fiche_titre($langs->trans('SchoolReconcile'), '', 'fa-balance-scale');
print '<div class="opacitymedium">'.$langs->trans('SchoolReconcileHelp', SchoolReconcile::LIMIT).'</div><br>';
if ($client === null) {
	print '<div class="warning">'.$langs->trans('SchoolNotConfigured').'</div><br>';
}

print '<form method="POST" action="'.dol_escape_htmltag($self).'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="check">';
foreach (SchoolReconcile::areas() as $a) {
	$on = $report === null ? true : in_array($a, $areas, true);
	print '<label class="marginrightonly"><input type="checkbox" name="areas[]" value="'.$a.'"'.($on ? ' checked' : '').'> '.$langs->trans('SchoolReconcileArea_'.$a).'</label> ';
}
print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('SchoolReconcileRun')).'"></form><br>';

if ($report !== null) {
	$kinds = array('missing' => 'SchoolKindMissing', 'differs' => 'SchoolKindDiffers', 'extra' => 'SchoolKindExtra', 'unreadable' => 'SchoolKindUnreadable');
	print '<div>';
	foreach ($areas as $a) {
		$n = 0;
		foreach ($report['findings'] as $f) {
			$n += $f['area'] === $a ? 1 : 0;
		}
		print $langs->trans('SchoolReconcileArea_'.$a).' : '.$langs->trans('SchoolReconcileChecked', (int) ($report['checked'][$a] ?? 0), $n).'<br>';
	}
	print '</div>';
	foreach ($report['truncated'] as $a) {
		print '<div class="warning">'.$langs->trans('SchoolReconcileTruncated', $langs->trans('SchoolReconcileArea_'.$a), SchoolReconcile::LIMIT).'</div>';
	}
	if (!empty($report['unmanaged']['enrolments'])) {
		print '<div class="opacitymedium">'.$langs->trans('SchoolReconcileUnmanaged', (int) $report['unmanaged']['enrolments']).'</div>';
	}
	print '<br>';

	if (!$report['findings']) {
		print '<div class="ok">'.$langs->trans('SchoolReconcileNothing').'</div>';
	} else {
		$fixes = array();
		foreach ($report['findings'] as $f) {
			if ($f['fix']) {
				$fixes[implode(':', $f['fix'])] = true;
			}
		}
		print '<form method="POST" action="'.dol_escape_htmltag($self).'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="fix">';
		print '<table class="noborder centpercent"><tr class="liste_titre"><td></td><td>'.$langs->trans('SchoolReconcileWhat').'</td><td>'.$langs->trans('SchoolReconcileAbout').'</td><td>'.$langs->trans('SchoolReconcileKind').'</td><td>'.$langs->trans('SchoolJobMessage').'</td></tr>';
		foreach ($report['findings'] as $f) {
			$spec = $f['fix'] ? implode(':', $f['fix']) : '';
			print '<tr class="oddeven"><td>'.($spec !== '' ? '<input type="checkbox" name="fixes[]" value="'.dol_escape_htmltag($spec).'" checked>' : '').'</td>';
			print '<td>'.$langs->trans('SchoolReconcileArea_'.$f['area']).'</td>';
			print '<td>'.($f['url'] !== '' ? '<a href="'.dol_escape_htmltag($f['url']).'">'.dol_escape_htmltag($f['label']).'</a>' : dol_escape_htmltag($f['label'])).'</td>';
			print '<td>'.$langs->trans($kinds[$f['kind']]).'</td><td class="small">'.dol_escape_htmltag($f['detail']).'</td></tr>';
		}
		print '</table>';
		if ($fixes) {
			print '<div class="center"><br><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('SchoolReconcileQueue')).'"> <span class="opacitymedium">'.$langs->trans('SchoolReconcileQueueHelp').'</span></div>';
		}
		print '</form>';
	}
}

llxFooter();
$db->close();
