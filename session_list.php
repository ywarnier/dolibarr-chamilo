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
 * \file    session_list.php
 * \ingroup school
 * \brief   Sessions (Chamilo sessions as service products) with their dates and courses.
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

if (GETPOST('action', 'aZ09') === 'syncnow') {
	schoolRequireRight('sync');
	$cron = new SchoolCron($db);
	$cron->runSync();
	setEventMessages($cron->output, null);
	header('Location: '.$_SERVER['PHP_SELF'].'?mainmenu=school&leftmenu=schoolsessions');
	exit;
}

llxHeader('', $langs->trans('SchoolSessions'));
print load_fiche_titre($langs->trans('SchoolSessions'), '', 'fa-calendar');
if ($user->hasRight('school', 'write')) {
	print '<a class="butAction" href="'.dol_buildpath('/school/session_card.php', 1).'?mainmenu=school&leftmenu=schoolsessions">'.$langs->trans('SchoolNewSession').'</a> ';
}
if ($user->hasRight('school', 'sync')) {
	print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="syncnow">';
	print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('SchoolSyncNow')).'"></form> ';
}
print '<br><br>';
print '<div class="opacitymedium">'.$langs->trans('SchoolSessionsImportHint').' <a href="'.dol_buildpath('/school/course_list.php', 1).'">'.$langs->trans('SchoolCourses').'</a></div><br>';

$sql = "SELECT s.rowid, s.chamilo_id, s.display_start, s.display_end, s.access_start, s.access_end, s.sync_status, s.last_error, p.rowid AS pid, p.ref, p.label,"
	." (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."school_session_course AS sc WHERE sc.fk_session = s.rowid) AS nbcourses"
	." FROM ".MAIN_DB_PREFIX."school_session AS s INNER JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = s.fk_product"
	." WHERE s.entity = ".((int) $conf->entity)." ORDER BY s.display_start DESC, p.label";
$resql = $db->query($sql);

// Coaches of each session (one name once, even when it coaches several of the session's courses).
$coaches = array();
$tq = $db->query("SELECT st.fk_session, u.firstname, u.lastname, u.login FROM ".MAIN_DB_PREFIX."school_session_teacher AS st INNER JOIN ".MAIN_DB_PREFIX."school_teacher AS t ON t.rowid = st.fk_teacher INNER JOIN ".MAIN_DB_PREFIX."user AS u ON u.rowid = t.fk_user WHERE st.sync_status <> 3 ORDER BY u.lastname");
while ($tq && ($x = $db->fetch_object($tq))) {
	$coaches[(int) $x->fk_session][dol_escape_htmltag(trim($x->firstname.' '.$x->lastname) ?: $x->login)] = true;
}

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('Label').'</td><td>'.$langs->trans('SchoolDisplayDates').'</td><td>'.$langs->trans('SchoolAccessDates').'</td><td class="right">'.$langs->trans('SchoolCourses').'</td><td>'.$langs->trans('SchoolCoaches').'</td><td>'.$langs->trans('SchoolChamiloId').'</td><td>'.$langs->trans('SchoolSyncStatus').'</td></tr>';
$range = static function ($db, $a, $b): string {
	$from = $a ? dol_print_date($db->jdate($a), 'dayhour') : '';
	$to = $b ? dol_print_date($db->jdate($b), 'dayhour') : '';
	return $from === '' && $to === '' ? '' : dol_escape_htmltag($from).' &rarr; '.dol_escape_htmltag($to);
};
$nb = 0;
while ($resql && ($o = $db->fetch_object($resql))) {
	$nb++;
	$url = DOL_URL_ROOT.'/product/card.php?id='.((int) $o->pid);
	print '<tr class="oddeven"><td><a href="'.$url.'">'.img_picto('', 'service', 'class="pictofixedwidth"').dol_escape_htmltag($o->ref).'</a></td>';
	print '<td>'.dol_escape_htmltag($o->label).'</td>';
	print '<td>'.$range($db, $o->display_start, $o->display_end).'</td><td>'.$range($db, $o->access_start, $o->access_end).'</td>';
	print '<td class="right">'.((int) $o->nbcourses).'</td>';
	print '<td>'.implode(', ', array_keys($coaches[(int) $o->rowid] ?? array()));
	if ($user->hasRight('school', 'teacher') && $o->chamilo_id && (int) $o->nbcourses > 0) {
		print ' <a href="'.dol_buildpath('/school/assign_teachers.php', 1).'?type=session&id='.((int) $o->rowid).'&mainmenu=school">'.img_picto($langs->trans('SchoolAssign'), 'edit').'</a>';
		print ' <a href="'.dol_buildpath('/school/schedule.php', 1).'?session='.((int) $o->rowid).'&mainmenu=school&leftmenu=schoolschedule">'.img_picto($langs->trans('SchoolSchedule'), 'calendar').'</a>';
	}
	print '</td><td>'.($o->chamilo_id ? '#'.((int) $o->chamilo_id) : '').'</td>';
	$st = (int) $o->sync_status;
	print '<td>'.($st === 1 ? $langs->trans('SchoolStatusSynced') : ($st === 2 ? '<span class="error" title="'.dol_escape_htmltag((string) $o->last_error).'">'.$langs->trans('SchoolStatusError').'</span>' : $langs->trans('SchoolStatusPending'))).'</td></tr>';
}
if ($nb === 0) {
	print '<tr class="oddeven"><td colspan="8" class="opacitymedium">'.$langs->trans('SchoolNothingYet').'</td></tr>';
}
print '</table>';

llxFooter();
$db->close();
