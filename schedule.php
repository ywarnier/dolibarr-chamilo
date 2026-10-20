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
 * \file    schedule.php
 * \ingroup school
 * \brief   The dates of a session or of a course, with their rooms; without a parameter, the dates to come.
 */

require_once __DIR__.'/lib/school_bootstrap.inc.php';
require_once __DIR__.'/class/schoolschedule.class.php';
require_once __DIR__.'/class/schoolcron.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

schoolStartPage();

$P = MAIN_DB_PREFIX;
$entity = (int) $conf->entity;
$sessionId = GETPOSTINT('session');
$courseId = GETPOSTINT('course');
$action = GETPOST('action', 'aZ09');
$schedule = new SchoolSchedule($db);
$canWrite = $user->hasRight('school', 'write');
$base = dol_buildpath('/school/schedule.php', 1);
$self = $base.'?'.($sessionId > 0 ? 'session='.$sessionId : ($courseId > 0 ? 'course='.$courseId : '')).'&mainmenu=school&leftmenu=schoolschedule';
$say = static function (string $error) use ($langs): string {
	return strpos($error, 'School') === 0 ? $langs->trans($error) : $error;
};
$when = static function (int $ts) use ($langs): string {
	return dol_print_date($ts, 'dayhour');
};

// What the page is about: a session, a course on its own, or nothing (the dates to come).
$title = '';
$courses = array();
if ($sessionId > 0) {
	$q = $db->query("SELECT p.label FROM {$P}school_session AS s INNER JOIN {$P}product AS p ON p.rowid = s.fk_product WHERE s.entity = {$entity} AND s.rowid = ".$sessionId);
	$o = $q ? $db->fetch_object($q) : null;
	if (!$o) {
		accessforbidden();
	}
	$title = (string) $o->label;
	$q = $db->query("SELECT c.rowid, p.label FROM {$P}school_session_course AS sc INNER JOIN {$P}school_course AS c ON c.rowid = sc.fk_course INNER JOIN {$P}product AS p ON p.rowid = c.fk_product WHERE sc.fk_session = {$sessionId} ORDER BY p.label");
	while ($q && ($o = $db->fetch_object($q))) {
		$courses[(int) $o->rowid] = (string) $o->label;
	}
	$courseId = 0;
} elseif ($courseId > 0) {
	$q = $db->query("SELECT p.label FROM {$P}school_course AS c INNER JOIN {$P}product AS p ON p.rowid = c.fk_product WHERE c.entity = {$entity} AND c.rowid = ".$courseId);
	$o = $q ? $db->fetch_object($q) : null;
	if (!$o) {
		accessforbidden();
	}
	$title = (string) $o->label;
	$courses[$courseId] = $title;
}
$focused = $sessionId > 0 || $courseId > 0;

// Rooms, for the forms.
$roomOptions = array();
$q = $db->query("SELECT r.rowid, x.ref FROM {$P}school_room AS r INNER JOIN {$P}resource AS x ON x.rowid = r.fk_resource WHERE r.entity = {$entity} ORDER BY x.ref");
while ($q && ($o = $db->fetch_object($q))) {
	$roomOptions[(int) $o->rowid] = (string) $o->ref;
}
$roomSelect = static function (string $name, int $selected, string $form = '') use ($roomOptions, $langs): string {
	$html = '<select name="'.$name.'"'.($form !== '' ? ' form="'.$form.'"' : '').'><option value="0">'.$langs->trans('SchoolNoRoom').'</option>';
	foreach ($roomOptions as $rid => $ref) {
		$html .= '<option value="'.$rid.'"'.($rid === $selected ? ' selected' : '').'>'.dol_escape_htmltag($ref).'</option>';
	}
	return $html.'</select>';
};

$report = array();
if ($focused && in_array($action, array('add', 'update', 'cancel'), true)) {
	schoolRequireRight('write');
	if ($action === 'add') {
		$cid = $sessionId > 0 ? GETPOSTINT('courseid') : $courseId;
		$first = GETPOST('first', 'alphanohtml');
		$until = GETPOST('until', 'alphanohtml') ?: $first;
		$dates = schoolRecurringDates($first, $until, array_map('intval', (array) GETPOST('weekdays', 'array')), max(1, GETPOSTINT('every')));
		$res = $schedule->createSeries($sessionId, $cid, $dates, GETPOST('time', 'alphanohtml'), GETPOSTINT('minutes'), GETPOSTINT('room'), GETPOSTINT('skip') > 0);
		foreach ($res['skipped'] as $s) {
			foreach ($s['conflicts'] as $c) {
				$report[] = $s['date'].' : '.$langs->trans('SchoolRoomBusyWith', $c['label'], $when($c['start']).' - '.dol_print_date($c['end'], 'hour'));
			}
			if (!$s['conflicts']) {
				$report[] = $s['date'].' : '.$langs->trans('SchoolErrDuplicateDate');
			}
		}
		if ($res['error'] !== '') {
			setEventMessages($say($res['error']), $report, 'errors');
			$report = array();
			$action = 'redisplay';
		} else {
			setEventMessages($langs->trans('SchoolDatesCreated', count($res['created'])), array_merge($res['skipped'] ? array($langs->trans('SchoolDatesSkipped', count($res['skipped']))) : array(), $report), $res['skipped'] ? 'warnings' : 'mesgs');
			if ($res['warnings']) {
				setEventMessages($langs->trans('SchoolTeacherBusyOn', implode(', ', array_unique($res['warnings']))), null, 'warnings');
			}
			header('Location: '.$self);
			exit;
		}
	} elseif ($action === 'update') {
		$res = $schedule->update(GETPOSTINT('occurrence'), GETPOST('date', 'alphanohtml'), GETPOST('time', 'alphanohtml'), GETPOSTINT('minutes'), GETPOSTINT('room'));
		$details = array();
		foreach ($res['conflicts'] as $c) {
			$details[] = $langs->trans('SchoolRoomBusyWith', $c['label'], $when($c['start']).' - '.dol_print_date($c['end'], 'hour'));
		}
		setEventMessages($res['error'] === '' ? $langs->trans('SchoolQueued') : $say($res['error']), $details ?: null, $res['error'] === '' ? 'mesgs' : 'errors');
		header('Location: '.$self);
		exit;
	} else {
		$error = $schedule->delete(GETPOSTINT('occurrence'));
		setEventMessages($error === '' ? $langs->trans('SchoolDateCancelled') : $say($error), null, $error === '' ? 'mesgs' : 'errors');
		header('Location: '.$self);
		exit;
	}
}

if ($action === 'syncnow') {
	schoolRequireRight('sync');
	$cron = new SchoolCron($db);
	$cron->runSync();
	setEventMessages($cron->output, null);
	header('Location: '.$self);
	exit;
}

llxHeader('', $langs->trans('SchoolSchedule'));
print load_fiche_titre($langs->trans('SchoolSchedule').($focused ? ' : '.dol_escape_htmltag($title) : ''), '', 'fa-calendar-alt');
print '<div class="opacitymedium">'.$langs->trans($focused ? 'SchoolScheduleHelp' : 'SchoolUpcomingHelp').'</div><br>';
if ($user->hasRight('school', 'sync')) {
	print '<form method="POST" action="'.dol_escape_htmltag($self).'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="syncnow"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('SchoolSyncNow')).'"></form><br><br>';
}

// ---- The dates
$today = dol_mktime(0, 0, 0, (int) dol_print_date(dol_now(), '%m'), (int) dol_print_date(dol_now(), '%d'), (int) dol_print_date(dol_now(), '%Y'));
if ($focused) {
	$where = $sessionId > 0 ? "o.fk_session = ".$sessionId : "o.fk_session = 0 AND o.fk_course = ".$courseId;
} else {
	$where = "o.status = ".SchoolSchedule::ACTIVE." AND o.date_start >= '".$db->idate($today)."'".(GETPOSTINT('room') > 0 ? " AND o.fk_room = ".GETPOSTINT('room') : '');
}
$sql = "SELECT o.rowid, o.fk_session, o.fk_course, o.fk_room, o.date_start, o.date_end, o.status, o.sync_status, o.last_error, o.attendance_done,"
	." (SELECT COUNT(*) FROM {$P}school_presence AS pr WHERE pr.fk_occurrence = o.rowid AND pr.presence = 1) AS n_present,"
	." (SELECT COUNT(*) FROM {$P}school_presence AS pr WHERE pr.fk_occurrence = o.rowid AND pr.presence IN (2, 3)) AS n_late,"
	." (SELECT COUNT(*) FROM {$P}school_presence AS pr WHERE pr.fk_occurrence = o.rowid AND pr.presence IN (0, 4)) AS n_absent,"
	." pc.label AS course_label, ps.label AS session_label, x.ref AS room_ref"
	." FROM {$P}school_occurrence AS o INNER JOIN {$P}school_course AS c ON c.rowid = o.fk_course INNER JOIN {$P}product AS pc ON pc.rowid = c.fk_product"
	." LEFT JOIN {$P}school_session AS s ON s.rowid = o.fk_session AND o.fk_session > 0 LEFT JOIN {$P}product AS ps ON ps.rowid = s.fk_product"
	." LEFT JOIN {$P}school_room AS r ON r.rowid = o.fk_room LEFT JOIN {$P}resource AS x ON x.rowid = r.fk_resource"
	." WHERE o.entity = {$entity} AND ".$where." ORDER BY (o.status = ".SchoolSchedule::TO_REMOVE."), o.date_start LIMIT 300";
$resql = $db->query($sql);

if (!$focused) {
	print '<form method="GET" action="'.dol_escape_htmltag($base).'" class="inline-block"><input type="hidden" name="mainmenu" value="school"><input type="hidden" name="leftmenu" value="schoolschedule">'.$langs->trans('SchoolRoom').' ';
	$sel = GETPOSTINT('room');
	print '<select name="room"><option value="0">&nbsp;</option>';
	foreach ($roomOptions as $rid => $ref) {
		print '<option value="'.$rid.'"'.($rid === $sel ? ' selected' : '').'>'.dol_escape_htmltag($ref).'</option>';
	}
	print '</select> <input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('Search')).'"></form><br><br>';
}

print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('SchoolDateAndTime').'</td><td>'.$langs->trans('SchoolDuration').'</td><td>'.$langs->trans($focused && $sessionId <= 0 ? 'SchoolRoom' : 'SchoolCourse').($focused && $sessionId > 0 ? ' / '.$langs->trans('SchoolRoom') : '').'</td><td>'.$langs->trans('SchoolSyncStatus').'</td><td>'.$langs->trans('SchoolAttendance').'</td><td></td></tr>';
$nb = 0;
while ($resql && ($o = $db->fetch_object($resql))) {
	$nb++;
	$start = (int) $db->jdate($o->date_start);
	$end = (int) $db->jdate($o->date_end);
	$removing = (int) $o->status === SchoolSchedule::TO_REMOVE;
	$label = ($o->session_label ? dol_escape_htmltag($o->session_label).' - ' : '').dol_escape_htmltag($o->course_label);
	print '<tr class="oddeven'.($removing ? ' opacitymedium' : '').'">';
	if ($canWrite && !$removing && $focused) {
		$fid = 'occform'.((int) $o->rowid);
		print '<td><input type="date" name="date" form="'.$fid.'" value="'.dol_print_date($start, '%Y-%m-%d').'"> <input type="time" name="time" form="'.$fid.'" value="'.dol_print_date($start, '%H:%M').'"></td>';
		print '<td><input type="text" class="width50 right" name="minutes" form="'.$fid.'" value="'.(int) round(($end - $start) / 60).'"> min</td>';
		print '<td>'.($sessionId > 0 ? dol_escape_htmltag($o->course_label).' / ' : '').$roomSelect('room', (int) $o->fk_room, $fid).'</td>';
	} else {
		print '<td>'.$when($start).'</td><td>'.(int) round(($end - $start) / 60).' min</td><td>'.($focused ? ($sessionId > 0 ? dol_escape_htmltag($o->course_label).' / ' : '').dol_escape_htmltag((string) $o->room_ref) : $label.($o->room_ref ? ' / '.dol_escape_htmltag($o->room_ref) : '')).'</td>';
	}
	print '<td>';
	if ($removing) {
		print '<span class="warning" title="'.dol_escape_htmltag((string) $o->last_error).'">'.$langs->trans('SchoolCancelledWaiting').'</span>'.($o->last_error ? ' '.img_picto('', 'warning') : '');
	} elseif ((int) $o->sync_status === 1) {
		print $langs->trans('SchoolStatusSynced');
	} elseif ((int) $o->sync_status === 2) {
		print '<span class="error" title="'.dol_escape_htmltag((string) $o->last_error).'">'.$langs->trans('SchoolStatusError').'</span>';
	} else {
		print $langs->trans('SchoolStatusPending');
	}
	print '</td><td>';
	if ((int) $o->n_present + (int) $o->n_late + (int) $o->n_absent > 0) {
		print $langs->trans('SchoolPresenceSummary', (int) $o->n_present, (int) $o->n_late, (int) $o->n_absent).((int) $o->attendance_done === 1 ? '' : ' <span class="opacitymedium">'.$langs->trans('SchoolNotFinal').'</span>');
	} elseif ((int) $o->attendance_done === 1) {
		print $langs->trans('SchoolAttendanceTaken');
	}
	print '</td><td class="right nowraponall">';
	if ($canWrite && $focused && !$removing) {
		print '<form id="'.$fid.'" method="POST" action="'.dol_escape_htmltag($self).'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="update"><input type="hidden" name="occurrence" value="'.((int) $o->rowid).'"><input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('Save')).'"></form> ';
		print '<form method="POST" action="'.dol_escape_htmltag($self).'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="cancel"><input type="hidden" name="occurrence" value="'.((int) $o->rowid).'"><input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('SchoolCancelDate')).'"></form>';
	}
	print '</td></tr>';
}
if ($nb === 0) {
	print '<tr class="oddeven"><td colspan="6" class="opacitymedium">'.$langs->trans('SchoolNoDateYet').'</td></tr>';
}
print '</table>';

// ---- Add dates
if ($focused && $canWrite) {
	print '<br>'.load_fiche_titre($langs->trans('SchoolAddDates'), '', '');
	if (!$courses) {
		print '<div class="warning">'.$langs->trans('SchoolSessionHasNoCourse').'</div>';
	} else {
		$posted = $action === 'redisplay';
		$val = static function (string $k, string $default = '') use ($posted): string {
			return $posted ? GETPOST($k, 'alphanohtml') : $default;
		};
		$checked = $posted ? array_map('intval', (array) GETPOST('weekdays', 'array')) : array();
		print '<form method="POST" action="'.dol_escape_htmltag($self).'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="add"><table class="border centpercent">';
		if ($sessionId > 0) {
			print '<tr><td class="titlefield">'.$langs->trans('SchoolCourse').'</td><td><select name="courseid">';
			foreach ($courses as $cid => $label) {
				print '<option value="'.$cid.'"'.((int) $val('courseid') === $cid ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
			}
			print '</select></td></tr>';
		}
		print '<tr><td class="titlefield fieldrequired">'.$langs->trans('SchoolFirstDay').'</td><td><input type="date" name="first" value="'.dol_escape_htmltag($val('first')).'" required></td></tr>';
		print '<tr><td>'.$langs->trans('SchoolRepeatUntil').'</td><td><input type="date" name="until" value="'.dol_escape_htmltag($val('until')).'"> <span class="opacitymedium">'.$langs->trans('SchoolRepeatHelp').'</span></td></tr>';
		print '<tr><td>'.$langs->trans('SchoolOnDays').'</td><td>';
		foreach (array(1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday') as $n => $name) {
			print '<label><input type="checkbox" name="weekdays[]" value="'.$n.'"'.(in_array($n, $checked, true) ? ' checked' : '').'> '.$langs->trans($name).'</label> &nbsp; ';
		}
		print '<span class="opacitymedium">'.$langs->trans('SchoolOnDaysHelp').'</span></td></tr>';
		print '<tr><td>'.$langs->trans('SchoolEveryWeeks').'</td><td><input type="text" class="width50 right" name="every" value="'.dol_escape_htmltag($val('every', '1')).'"></td></tr>';
		print '<tr><td class="fieldrequired">'.$langs->trans('SchoolStartTime').'</td><td><input type="time" name="time" value="'.dol_escape_htmltag($val('time', '09:00')).'" required></td></tr>';
		print '<tr><td class="fieldrequired">'.$langs->trans('SchoolDuration').'</td><td><input type="text" class="width50 right" name="minutes" value="'.dol_escape_htmltag($val('minutes', '60')).'"> min</td></tr>';
		print '<tr><td>'.$langs->trans('SchoolRoom').'</td><td>'.$roomSelect('room', (int) $val('room', '0')).'</td></tr>';
		print '<tr><td></td><td><label><input type="checkbox" name="skip" value="1"'.($posted && GETPOSTINT('skip') > 0 ? ' checked' : '').'> '.$langs->trans('SchoolSkipBusyDates').'</label></td></tr>';
		print '</table><div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('SchoolAddTheDates')).'"></div></form>';
	}
}

llxFooter();
$db->close();
