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
 * \file    class_list.php
 * \ingroup school
 * \brief   Classes of students.
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
$self = $_SERVER['PHP_SELF'].'?mainmenu=school&leftmenu=schoolclasses';

if (GETPOST('action', 'aZ09') === 'syncnow') {
	schoolRequireRight('sync');
	$cron = new SchoolCron($db);
	$cron->runSync();
	setEventMessages($cron->output, null);
	header('Location: '.$self);
	exit;
}

$showArchived = GETPOSTINT('archived') > 0;

llxHeader('', $langs->trans('SchoolClasses'));
print load_fiche_titre($langs->trans('SchoolClasses'), '', 'fa-users');
print '<div class="opacitymedium">'.$langs->trans('SchoolClassesHelp').'</div><br>';

if ($user->hasRight('school', 'class')) {
	print '<a class="butAction" href="'.dol_buildpath('/school/class_card.php', 1).'?action=create&mainmenu=school&leftmenu=schoolclasses">'.$langs->trans('SchoolNewClass').'</a> ';
}
if ($user->hasRight('school', 'sync')) {
	print '<form method="POST" action="'.dol_escape_htmltag($self).'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="syncnow"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('SchoolSyncNow')).'"></form> ';
}
print '<a href="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'?mainmenu=school&leftmenu=schoolclasses&archived='.($showArchived ? 0 : 1).'">'.$langs->trans($showArchived ? 'SchoolHideArchived' : 'SchoolShowArchived').'</a><br><br>';

$today = dol_print_date(dol_now(), '%Y-%m-%d');
$members = array();
$mq = $db->query("SELECT fk_class, date_start, date_end FROM ".MAIN_DB_PREFIX."school_class_student");
while ($mq && ($m = $db->fetch_object($mq))) {
	if (schoolIsCurrentMember($m->date_start ? (string) $m->date_start : null, $m->date_end ? (string) $m->date_end : null, $today)) {
		$members[(int) $m->fk_class] = ($members[(int) $m->fk_class] ?? 0) + 1;
	}
}

$sql = "SELECT c.rowid, c.ref, c.label, c.level, c.date_start, c.date_end, c.status, c.chamilo_id, c.sync_status, c.last_error, u.firstname, u.lastname, u.login"
	." FROM ".MAIN_DB_PREFIX."school_class AS c LEFT JOIN ".MAIN_DB_PREFIX."school_teacher AS t ON t.rowid = c.fk_homeroom_teacher LEFT JOIN ".MAIN_DB_PREFIX."user AS u ON u.rowid = t.fk_user"
	." WHERE c.entity = ".((int) $conf->entity).($showArchived ? '' : " AND c.status = 1")." ORDER BY c.label";
$resql = $db->query($sql);

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('Label').'</td><td>'.$langs->trans('SchoolLevel').'</td><td>'.$langs->trans('SchoolCycle').'</td><td>'.$langs->trans('SchoolHomeroomTeacher').'</td><td class="right">'.$langs->trans('SchoolMembers').'</td><td>'.$langs->trans('SchoolChamiloId').'</td><td>'.$langs->trans('SchoolSyncStatus').'</td></tr>';
$nb = 0;
while ($resql && ($o = $db->fetch_object($resql))) {
	$nb++;
	$cycle = ($o->date_start ? dol_print_date($db->jdate($o->date_start), 'day') : '').($o->date_start || $o->date_end ? ' &rarr; ' : '').($o->date_end ? dol_print_date($db->jdate($o->date_end), 'day') : '');
	print '<tr class="oddeven"><td><a href="'.dol_buildpath('/school/class_card.php', 1).'?id='.((int) $o->rowid).'&mainmenu=school&leftmenu=schoolclasses">'.img_picto('', 'group', 'class="pictofixedwidth"').dol_escape_htmltag($o->ref).'</a></td>';
	print '<td>'.dol_escape_htmltag($o->label).((int) $o->status === 1 ? '' : ' <span class="opacitymedium">('.$langs->trans('SchoolArchived').')</span>').'</td><td>'.dol_escape_htmltag((string) $o->level).'</td><td>'.$cycle.'</td>';
	print '<td>'.dol_escape_htmltag(trim($o->firstname.' '.$o->lastname) ?: (string) $o->login).'</td><td class="right">'.((int) ($members[(int) $o->rowid] ?? 0)).'</td>';
	print '<td>'.($o->chamilo_id ? '#'.((int) $o->chamilo_id) : '').'</td><td>';
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
	print '<tr class="oddeven"><td colspan="8" class="opacitymedium">'.$langs->trans('SchoolNoClassYet').'</td></tr>';
}
print '</table>';

llxFooter();
$db->close();
