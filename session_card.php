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
 * \file    session_card.php
 * \ingroup school
 * \brief   Create a session in Dolibarr; it is then created in Chamilo by the sync queue.
 */

require_once __DIR__.'/lib/school_bootstrap.inc.php';
require_once __DIR__.'/class/schoolsessionsync.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

schoolStartPage();
schoolRequireRight('write');

$errors = array();
$title = trim(GETPOST('title', 'alphanohtml'));
$startRaw = GETPOST('display_start', 'alphanohtml');
$endRaw = GETPOST('display_end', 'alphanohtml');
$price = price2num(GETPOST('price', 'alphanohtml'));
$courseIds = array_map('intval', (array) GETPOST('course_ids', 'array:int'));

if (GETPOST('action', 'aZ09') === 'add') {
	$start = schoolParseDate($startRaw);
	$end = schoolParseDate($endRaw);
	if ($title === '') {
		$errors[] = $langs->trans('SchoolErrTitle');
	}
	if ($start === null || $end === null) {
		$errors[] = $langs->trans('SchoolErrDates');
	} elseif ($end < $start) {
		$errors[] = $langs->trans('SchoolErrDateOrder');
	}
	if (!$errors) {
		$res = (new SchoolSessionSync($db, $user))->createLocal(array(
			'title' => $title,
			'display_start' => (int) $start,
			'display_end' => (int) $end,
			'access_start' => $start,
			'access_end' => $end,
			'price' => (float) $price,
			'course_ids' => $courseIds,
		));
		if ($res['error'] === '') {
			setEventMessages($langs->trans('SchoolSessionQueued'), null);
			header('Location: '.dol_buildpath('/school/session_list.php', 1).'?mainmenu=school&leftmenu=schoolsessions');
			exit;
		}
		$errors[] = $res['error'];
	}
	setEventMessages('', $errors, 'errors');
}

llxHeader('', $langs->trans('SchoolNewSession'));
print load_fiche_titre($langs->trans('SchoolNewSession'), '', 'fa-calendar');

print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="add">';
print '<table class="border centpercent">';
print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('Label').'</td><td><input type="text" class="minwidth400" name="title" value="'.dol_escape_htmltag($title).'" required></td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans('SchoolDisplayStart').'</td><td><input type="datetime-local" name="display_start" value="'.dol_escape_htmltag($startRaw).'" required></td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans('SchoolDisplayEnd').'</td><td><input type="datetime-local" name="display_end" value="'.dol_escape_htmltag($endRaw).'" required></td></tr>';
print '<tr><td>'.$langs->trans('SchoolPriceHT').'</td><td><input type="text" class="width100" name="price" value="'.dol_escape_htmltag((string) GETPOST('price', 'alphanohtml')).'"> <span class="opacitymedium">'.$langs->trans('SchoolPriceHelp').'</span></td></tr>';
print '<tr><td class="tdtop">'.$langs->trans('SchoolCourses').'</td><td>';
$resql = $db->query("SELECT c.rowid, c.code, c.language, p.label FROM ".MAIN_DB_PREFIX."school_course AS c INNER JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = c.fk_product WHERE c.entity = ".((int) $conf->entity)." AND c.chamilo_id IS NOT NULL ORDER BY p.label");
$nb = 0;
while ($resql && ($o = $db->fetch_object($resql))) {
	$nb++;
	print '<label><input type="checkbox" name="course_ids[]" value="'.((int) $o->rowid).'"'.(in_array((int) $o->rowid, $courseIds, true) ? ' checked' : '').'> '.dol_escape_htmltag($o->label).' <span class="opacitymedium">'.dol_escape_htmltag($o->code.($o->language ? ' · '.$o->language : '')).'</span></label><br>';
}
if ($nb === 0) {
	print '<span class="opacitymedium">'.$langs->trans('SchoolNoCourseToLink').'</span>';
}
print '</td></tr></table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Create')).'"> ';
print '<a class="button button-cancel" href="'.dol_buildpath('/school/session_list.php', 1).'?mainmenu=school&leftmenu=schoolsessions">'.$langs->trans('Cancel').'</a></div>';
print '</form>';

llxFooter();
$db->close();
