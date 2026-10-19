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
 * \file    teacher_list.php
 * \ingroup school
 * \brief   Dolibarr employees, and which of them are teachers with a Chamilo account.
 */

require_once __DIR__.'/lib/school_bootstrap.inc.php';
require_once __DIR__.'/class/schoolteachersync.class.php';
require_once __DIR__.'/class/schoolcron.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

schoolStartPage();
$self = $_SERVER['PHP_SELF'].'?mainmenu=school&leftmenu=schoolteachers';
$action = GETPOST('action', 'aZ09');

if ($action === 'enable') {
	schoolRequireRight('teacher');
	$res = (new SchoolTeacherSync($db))->enable(GETPOSTINT('userid'));
	if ($res['error'] === '') {
		setEventMessages($langs->trans('SchoolTeacherQueued'), null);
	} else {
		$known = array('NoEmail' => 'SchoolErrNoEmail', 'NotAnEmployee' => 'SchoolErrNotEmployee');
		setEventMessages(isset($known[$res['error']]) ? $langs->trans($known[$res['error']]) : $res['error'], null, 'errors');
	}
	header('Location: '.$self);
	exit;
}

if ($action === 'resync') {
	schoolRequireRight('teacher');
	(new SyncQueue($db))->enqueue('teacher', GETPOSTINT('teacherid'), 'update');
	setEventMessages($langs->trans('SchoolTeacherQueued'), null);
	header('Location: '.$self);
	exit;
}

if ($action === 'syncnow') {
	schoolRequireRight('sync');
	$cron = new SchoolCron($db);
	$cron->runSync();
	setEventMessages($cron->output, null);
	header('Location: '.$self);
	exit;
}

llxHeader('', $langs->trans('SchoolTeachers'));
print load_fiche_titre($langs->trans('SchoolTeachers'), '', 'fa-chalkboard-teacher');
print '<div class="opacitymedium">'.$langs->trans('SchoolTeachersHelp').'</div><br>';

if ($user->hasRight('school', 'sync')) {
	print '<form method="POST" action="'.dol_escape_htmltag($self).'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="syncnow">';
	print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('SchoolSyncNow')).'"></form><br><br>';
}

$sql = "SELECT u.rowid, u.login, u.firstname, u.lastname, u.email, u.statut, t.rowid AS tid, t.chamilo_user_id, t.chamilo_username, t.sync_status, t.last_error"
	." FROM ".MAIN_DB_PREFIX."user AS u LEFT JOIN ".MAIN_DB_PREFIX."school_teacher AS t ON t.fk_user = u.rowid AND t.entity = ".((int) $conf->entity)
	." WHERE u.employee = 1 AND u.entity IN (".getEntity('user').") ORDER BY u.lastname, u.firstname";
$resql = $db->query($sql);

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Login').'</td><td>'.$langs->trans('Name').'</td><td>'.$langs->trans('Email').'</td><td>'.$langs->trans('SchoolChamiloAccount').'</td><td>'.$langs->trans('SchoolSyncStatus').'</td><td></td></tr>';
$nb = 0;
while ($resql && ($o = $db->fetch_object($resql))) {
	$nb++;
	$disabled = (int) $o->statut !== 1;
	print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/user/card.php?id='.((int) $o->rowid).'">'.img_picto('', 'user', 'class="pictofixedwidth"').dol_escape_htmltag($o->login).'</a>'.($disabled ? ' <span class="opacitymedium">('.$langs->trans('Disabled').')</span>' : '').'</td>';
	print '<td>'.dol_escape_htmltag(trim($o->firstname.' '.$o->lastname)).'</td><td>'.dol_escape_htmltag((string) $o->email).'</td>';
	print '<td>'.($o->chamilo_user_id ? dol_escape_htmltag((string) $o->chamilo_username).' <span class="opacitymedium">#'.((int) $o->chamilo_user_id).'</span>' : '').'</td>';

	print '<td>';
	if (!$o->tid) {
		print '<span class="opacitymedium">'.$langs->trans('SchoolNotATeacher').'</span>';
	} elseif ((int) $o->sync_status === 1) {
		$note = (string) $o->last_error;
		print $langs->trans('SchoolStatusSynced');
		if (strpos($note, 'NOTE:') === 0) {
			print ' <span class="warning" title="'.dol_escape_htmltag(substr($note, 5)).'">'.img_picto('', 'warning').'</span>';
		}
	} elseif ((int) $o->sync_status === 2) {
		print '<span class="error" title="'.dol_escape_htmltag((string) $o->last_error).'">'.$langs->trans('SchoolStatusError').'</span>';
	} else {
		print $langs->trans('SchoolStatusPending');
	}
	print '</td><td class="right nowraponall">';
	if ($user->hasRight('school', 'teacher')) {
		if (!$o->tid) {
			if (trim((string) $o->email) === '') {
				print '<span class="opacitymedium">'.$langs->trans('SchoolErrNoEmail').'</span>';
			} else {
				print '<form method="POST" action="'.dol_escape_htmltag($self).'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="enable"><input type="hidden" name="userid" value="'.((int) $o->rowid).'">';
				print '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('SchoolEnableOnChamilo')).'"></form>';
			}
		} else {
			print '<form method="POST" action="'.dol_escape_htmltag($self).'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="resync"><input type="hidden" name="teacherid" value="'.((int) $o->tid).'">';
			print '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('SchoolResend')).'"></form>';
		}
	}
	print '</td></tr>';
}
if ($nb === 0) {
	print '<tr class="oddeven"><td colspan="6" class="opacitymedium">'.$langs->trans('SchoolNoEmployee').'</td></tr>';
}
print '</table>';

llxFooter();
$db->close();
