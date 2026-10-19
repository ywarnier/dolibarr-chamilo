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
 * \file    course_list.php
 * \ingroup school
 * \brief   Courses (Chamilo courses as service products) and import from Chamilo.
 */

require_once __DIR__.'/lib/school_bootstrap.inc.php';
require_once __DIR__.'/class/schoolcatalog.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

schoolStartPage();

if (GETPOST('action', 'aZ09') === 'import') {
	schoolRequireRight('sync');
	$client = schoolGetClient();
	if ($client === null) {
		setEventMessages($langs->trans('SchoolNotConfigured'), null, 'errors');
	} else {
		$report = (new SchoolCatalog($db, $user))->importAll($client);
		setEventMessages($langs->trans('SchoolImportCourses', $report['courses']['created'], $report['courses']['linked'], $report['courses']['updated']), null);
		setEventMessages($langs->trans('SchoolImportSessions', $report['sessions']['created'], $report['sessions']['linked'], $report['sessions']['updated']), null);
		if ($report['errors']) {
			setEventMessages($langs->trans('SchoolImportErrors', count($report['errors'])), array_slice($report['errors'], 0, 10), 'errors');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

llxHeader('', $langs->trans('SchoolCourses'));
print load_fiche_titre($langs->trans('SchoolCourses'), '', 'fa-book');

if ($user->hasRight('school', 'sync')) {
	print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="inline-block">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="import">';
	print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('SchoolImportFromChamilo')).'">';
	print '</form> <span class="opacitymedium">'.$langs->trans('SchoolImportHelp').'</span><br><br>';
}

$sql = "SELECT c.rowid, c.code, c.language, c.chamilo_id, c.sync_status, c.last_sync, p.rowid AS pid, p.ref, p.label"
	." FROM ".MAIN_DB_PREFIX."school_course AS c INNER JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = c.fk_product"
	." WHERE c.entity = ".((int) $conf->entity)." ORDER BY p.label";
$resql = $db->query($sql);

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('Label').'</td><td>'.$langs->trans('SchoolLanguage').'</td><td>'.$langs->trans('SchoolChamiloId').'</td><td>'.$langs->trans('SchoolLastSync').'</td></tr>';
$nb = 0;
while ($resql && ($o = $db->fetch_object($resql))) {
	$nb++;
	$url = DOL_URL_ROOT.'/product/card.php?id='.((int) $o->pid);
	print '<tr class="oddeven"><td><a href="'.$url.'">'.img_picto('', 'service', 'class="pictofixedwidth"').dol_escape_htmltag($o->ref).'</a></td>';
	print '<td>'.dol_escape_htmltag($o->label).'</td><td>'.dol_escape_htmltag((string) $o->language).'</td>';
	print '<td>'.($o->chamilo_id ? '#'.((int) $o->chamilo_id).' '.dol_escape_htmltag($o->code) : '').'</td>';
	print '<td>'.($o->last_sync ? dol_print_date($db->jdate($o->last_sync), 'dayhour') : '').'</td></tr>';
}
if ($nb === 0) {
	print '<tr class="oddeven"><td colspan="5" class="opacitymedium">'.$langs->trans('SchoolNothingYet').'</td></tr>';
}
print '</table>';

llxFooter();
$db->close();
