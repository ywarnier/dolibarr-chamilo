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
 * \file    student_list.php
 * \ingroup school
 * \brief   Students, with their parents and the state of their Chamilo account.
 */

require_once __DIR__.'/lib/school_bootstrap.inc.php';
require_once __DIR__.'/class/schoolcron.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

schoolStartPage();
$self = $_SERVER['PHP_SELF'].'?mainmenu=school&leftmenu=schoolstudents';

if (GETPOST('action', 'aZ09') === 'syncnow') {
	schoolRequireRight('sync');
	$cron = new SchoolCron($db);
	$cron->runSync();
	setEventMessages($cron->output, null);
	header('Location: '.$self);
	exit;
}

$search = trim(GETPOST('search', 'alphanohtml'));

llxHeader('', $langs->trans('SchoolStudents'));
print load_fiche_titre($langs->trans('SchoolStudents'), '', 'fa-user-graduate');

print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="inline-block"><input type="hidden" name="mainmenu" value="school"><input type="hidden" name="leftmenu" value="schoolstudents">';
print '<input type="text" name="search" value="'.dol_escape_htmltag($search).'" placeholder="'.dol_escape_htmltag($langs->trans('Search')).'"> <input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('Search')).'"></form> ';
if ($user->hasRight('school', 'write')) {
	print '<a class="butAction" href="'.dol_buildpath('/school/student_card.php', 1).'?action=create&mainmenu=school&leftmenu=schoolstudents">'.$langs->trans('SchoolNewStudent').'</a> ';
}
if ($user->hasRight('school', 'sync')) {
	print '<form method="POST" action="'.dol_escape_htmltag($self).'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="syncnow"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('SchoolSyncNow')).'"></form>';
}
print '<br><br>';

$sql = "SELECT s.rowid, s.ref, s.firstname, s.lastname, s.birthdate, s.status, s.chamilo_user_id, s.chamilo_username, s.sync_status, s.last_error FROM ".MAIN_DB_PREFIX."school_student AS s WHERE s.entity = ".((int) $conf->entity);
if ($search !== '') {
	$like = "'%".$db->escape($db->escapeforlike($search))."%'";
	$sql .= " AND (s.firstname LIKE ".$like." OR s.lastname LIKE ".$like." OR s.ref LIKE ".$like.")";
}
$sql .= " ORDER BY s.lastname, s.firstname";
$resql = $db->query($sql);

$parents = array();
$pq = $db->query("SELECT sp.fk_student, sp.fk_soc, s.nom FROM ".MAIN_DB_PREFIX."school_student_parent AS sp INNER JOIN ".MAIN_DB_PREFIX."societe AS s ON s.rowid = sp.fk_soc WHERE sp.chamilo_follow <> 2 ORDER BY sp.is_payer DESC, s.nom");
while ($pq && ($x = $db->fetch_object($pq))) {
	$parents[(int) $x->fk_student][] = '<a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $x->fk_soc).'">'.dol_escape_htmltag($x->nom).'</a>';
}

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('Name').'</td><td>'.$langs->trans('SchoolBirthdate').'</td><td>'.$langs->trans('SchoolParents').'</td><td>'.$langs->trans('SchoolChamiloAccount').'</td><td>'.$langs->trans('SchoolSyncStatus').'</td></tr>';
$nb = 0;
while ($resql && ($o = $db->fetch_object($resql))) {
	$nb++;
	print '<tr class="oddeven"><td><a href="'.dol_buildpath('/school/student_card.php', 1).'?id='.((int) $o->rowid).'&mainmenu=school&leftmenu=schoolstudents">'.img_picto('', 'user', 'class="pictofixedwidth"').dol_escape_htmltag($o->ref).'</a></td>';
	print '<td>'.dol_escape_htmltag($o->firstname.' '.$o->lastname).((int) $o->status === 1 ? '' : ' <span class="opacitymedium">('.$langs->trans('SchoolInactive').')</span>').'</td>';
	print '<td>'.($o->birthdate ? dol_print_date($db->jdate($o->birthdate), 'day') : '').'</td>';
	print '<td>'.implode(', ', $parents[(int) $o->rowid] ?? array()).'</td>';
	print '<td>'.($o->chamilo_user_id ? dol_escape_htmltag((string) $o->chamilo_username).' <span class="opacitymedium">#'.((int) $o->chamilo_user_id).'</span>' : '').'</td><td>';
	if ((int) $o->sync_status === 1) {
		print $langs->trans('SchoolStatusSynced');
		if (strpos((string) $o->last_error, 'NOTE:') === 0) {
			print ' <span class="warning" title="'.dol_escape_htmltag(substr((string) $o->last_error, 5)).'">'.img_picto('', 'warning').'</span>';
		}
	} elseif ((int) $o->sync_status === 2) {
		print '<span class="error" title="'.dol_escape_htmltag((string) $o->last_error).'">'.$langs->trans('SchoolStatusError').'</span>';
	} else {
		print $langs->trans('SchoolStatusPending');
	}
	print '</td></tr>';
}
if ($nb === 0) {
	print '<tr class="oddeven"><td colspan="6" class="opacitymedium">'.$langs->trans('SchoolNoStudentYet').'</td></tr>';
}
print '</table>';

llxFooter();
$db->close();
