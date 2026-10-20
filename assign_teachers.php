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
 * \file    assign_teachers.php
 * \ingroup school
 * \brief   Choose the teachers of a course, or the coaches of the courses of a session.
 */

require_once __DIR__.'/lib/school_bootstrap.inc.php';
require_once __DIR__.'/class/schoolteachersync.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

schoolStartPage();
schoolRequireRight('teacher');

$P = MAIN_DB_PREFIX;
$entity = (int) $conf->entity;
$kind = GETPOST('type', 'aZ09') === 'session' ? 'session' : 'course';
$id = GETPOSTINT('id');

// The things to give teachers to: one course, or every course of one session.
$targets = array();
$title = '';
$backUrl = dol_buildpath($kind === 'session' ? '/school/session_list.php' : '/school/course_list.php', 1).'?mainmenu=school';
if ($kind === 'course') {
	$resql = $db->query("SELECT c.rowid, p.label FROM {$P}school_course AS c INNER JOIN {$P}product AS p ON p.rowid = c.fk_product WHERE c.entity = {$entity} AND c.rowid = ".$id);
	if ($resql && ($o = $db->fetch_object($resql))) {
		$title = (string) $o->label;
		$targets[(int) $o->rowid] = $title;
	}
} else {
	$resql = $db->query("SELECT s.rowid, p.label FROM {$P}school_session AS s INNER JOIN {$P}product AS p ON p.rowid = s.fk_product WHERE s.entity = {$entity} AND s.rowid = ".$id);
	if ($resql && ($o = $db->fetch_object($resql))) {
		$title = (string) $o->label;
		$resql = $db->query("SELECT c.rowid, p.label FROM {$P}school_session_course AS sc INNER JOIN {$P}school_course AS c ON c.rowid = sc.fk_course INNER JOIN {$P}product AS p ON p.rowid = c.fk_product WHERE sc.fk_session = ".$id." ORDER BY p.label");
		while ($resql && ($c = $db->fetch_object($resql))) {
			$targets[(int) $c->rowid] = (string) $c->label;
		}
	}
}
if ($title === '') {
	accessforbidden();
}

$teachers = array();
$resql = $db->query("SELECT t.rowid, u.firstname, u.lastname, u.login FROM {$P}school_teacher AS t INNER JOIN {$P}user AS u ON u.rowid = t.fk_user WHERE t.entity = {$entity} ORDER BY u.lastname, u.firstname");
while ($resql && ($o = $db->fetch_object($resql))) {
	$teachers[(int) $o->rowid] = trim($o->firstname.' '.$o->lastname) ?: (string) $o->login;
}

// Current assignments: [target course id][teacher id] => state. A row waiting to be removed no longer counts.
$current = array();
if ($kind === 'course') {
	$resql = $db->query("SELECT fk_course AS cid, fk_teacher, sync_status FROM {$P}school_course_teacher WHERE fk_course = ".$id);
} else {
	$resql = $db->query("SELECT fk_course AS cid, fk_teacher, sync_status FROM {$P}school_session_teacher WHERE fk_session = ".$id);
}
while ($resql && ($o = $db->fetch_object($resql))) {
	$current[(int) $o->cid][(int) $o->fk_teacher] = (int) $o->sync_status;
}

if (GETPOST('action', 'aZ09') === 'save') {
	$posted = (array) GETPOST('teachers', 'array');
	$wanted = array();
	foreach ($targets as $courseId => $label) {
		$wanted[$courseId] = array_map('intval', (array) ($posted[$courseId] ?? array()));
	}
	$res = (new SchoolTeacherSync($db))->assign($kind, $id, $wanted);
	if ($res['error'] !== '') {
		setEventMessages($langs->trans($res['error']), null, 'errors');
	} elseif ($res['changed']) {
		setEventMessages($langs->trans('SchoolAssignmentsQueued'), null);
	}
	header('Location: '.$backUrl);
	exit;
}

llxHeader('', $langs->trans($kind === 'course' ? 'SchoolCourseTeachers' : 'SchoolSessionCoaches'));
print load_fiche_titre($langs->trans($kind === 'course' ? 'SchoolCourseTeachers' : 'SchoolSessionCoaches').' : '.dol_escape_htmltag($title), '', 'fa-chalkboard-teacher');
print '<div class="opacitymedium">'.$langs->trans($kind === 'course' ? 'SchoolCourseTeachersHelp' : 'SchoolSessionCoachesHelp').'</div><br>';

if (!$teachers) {
	print '<div class="warning">'.$langs->trans('SchoolNoTeacherYet').' <a href="'.dol_buildpath('/school/teacher_list.php', 1).'?mainmenu=school&leftmenu=schoolteachers">'.$langs->trans('SchoolTeachers').'</a></div>';
} elseif (!$targets) {
	print '<div class="warning">'.$langs->trans('SchoolSessionHasNoCourse').'</div>';
} else {
	print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save">';
	print '<input type="hidden" name="type" value="'.dol_escape_htmltag($kind).'"><input type="hidden" name="id" value="'.$id.'">';
	print '<table class="border centpercent">';
	foreach ($targets as $courseId => $label) {
		print '<tr><td class="titlefield tdtop">'.($kind === 'session' ? dol_escape_htmltag($label) : $langs->trans('SchoolTeachers')).'</td><td>';
		foreach ($teachers as $teacherId => $name) {
			$state = $current[$courseId][$teacherId] ?? null;
			$checked = $state !== null && $state !== SchoolTeacherSync::TO_REMOVE;
			print '<label><input type="checkbox" name="teachers['.$courseId.'][]" value="'.$teacherId.'"'.($checked ? ' checked' : '').'> '.dol_escape_htmltag($name).'</label><br>';
		}
		print '</td></tr>';
	}
	print '</table>';
	print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"> <a class="button button-cancel" href="'.dol_escape_htmltag($backUrl).'">'.$langs->trans('Cancel').'</a></div></form>';
}

llxFooter();
$db->close();
