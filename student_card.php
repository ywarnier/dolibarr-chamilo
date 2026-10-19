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
 * \file    student_card.php
 * \ingroup school
 * \brief   Create or edit a student, the parents (and who follows the child in Chamilo) and the enrolments.
 */

require_once __DIR__.'/lib/school_bootstrap.inc.php';
require_once __DIR__.'/class/schoolstudent.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

schoolStartPage();

$P = MAIN_DB_PREFIX;
$entity = (int) $conf->entity;
$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$students = new SchoolStudent($db);
$listUrl = dol_buildpath('/school/student_list.php', 1).'?mainmenu=school&leftmenu=schoolstudents';
$cardUrl = static function (int $studentId): string {
	return dol_buildpath('/school/student_card.php', 1).'?id='.$studentId.'&mainmenu=school&leftmenu=schoolstudents';
};
$say = static function (string $error) use ($langs): string {
	return (strpos($error, 'School') === 0 || $error === 'ErrorBadEMail') ? $langs->trans($error) : $error;
};
$birthdate = static function (string $raw): ?int {
	$raw = trim($raw);
	if ($raw === '') {
		return null;
	}
	$ts = strtotime($raw.' 12:00:00');
	return $ts === false ? null : $ts;
};

$student = null;
if ($id > 0) {
	$resql = $db->query("SELECT * FROM {$P}school_student WHERE entity = {$entity} AND rowid = ".$id);
	$student = $resql ? ($db->fetch_object($resql) ?: null) : null;
	if (!$student) {
		accessforbidden();
	}
}

// Actions that change something: each needs the write right and goes back to the card.
if ($action !== '' && in_array($action, array('add', 'save', 'addparent', 'saveparent', 'removeparent', 'enrol', 'unenrol', 'resend'), true)) {
	schoolRequireRight('write');

	if ($action === 'add') {
		$res = $students->create(GETPOST('firstname', 'alphanohtml'), GETPOST('lastname', 'alphanohtml'), $birthdate(GETPOST('birthdate', 'alphanohtml')), GETPOST('email', 'alphanohtml'));
		if ($res['error'] === '') {
			setEventMessages($langs->trans('SchoolStudentCreated'), null);
			header('Location: '.$cardUrl($res['id']));
			exit;
		}
		setEventMessages($say($res['error']), null, 'errors');
		$action = 'create';
	} elseif ($student) {
		$error = '';
		if ($action === 'save') {
			$error = $students->update($id, GETPOST('firstname', 'alphanohtml'), GETPOST('lastname', 'alphanohtml'), $birthdate(GETPOST('birthdate', 'alphanohtml')), GETPOST('email', 'alphanohtml'), GETPOSTINT('active') > 0);
		} elseif ($action === 'addparent') {
			$error = $students->addParent($id, GETPOSTINT('socid'), 0, 'parent', false, 0.0, false);
		} elseif ($action === 'saveparent') {
			$error = $students->updateParent(GETPOSTINT('linkid'), GETPOSTINT('contactid'), GETPOST('relation', 'aZ09'), GETPOSTINT('payer') > 0, (float) price2num(GETPOST('share', 'alphanohtml')), GETPOSTINT('follow') > 0);
		} elseif ($action === 'removeparent') {
			$error = $students->removeParent(GETPOSTINT('linkid'));
		} elseif ($action === 'enrol') {
			$target = GETPOST('target', 'alphanohtml');
			$error = $students->enrol($id, strpos($target, 's:') === 0 ? (int) substr($target, 2) : 0, strpos($target, 'c:') === 0 ? (int) substr($target, 2) : 0);
		} elseif ($action === 'unenrol') {
			$students->unenrol(GETPOSTINT('enrolmentid'));
		} elseif ($action === 'resend') {
			(new SyncQueue($db))->enqueue('student', $id, 'update');
		}
		setEventMessages($error === '' ? $langs->trans('SchoolQueued') : $say($error), null, $error === '' ? 'mesgs' : 'errors');
		header('Location: '.$cardUrl($id));
		exit;
	}
}

$form = new Form($db);
$canWrite = $user->hasRight('school', 'write');
$formOpen = static function (string $act, array $hidden = array()) use ($id): string {
	$html = '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="'.$act.'"><input type="hidden" name="id" value="'.$id.'">';
	foreach ($hidden as $k => $v) {
		$html .= '<input type="hidden" name="'.$k.'" value="'.dol_escape_htmltag((string) $v).'">';
	}
	return $html;
};

// ---- Creation form
if (!$student) {
	schoolRequireRight('write');
	llxHeader('', $langs->trans('SchoolNewStudent'));
	print load_fiche_titre($langs->trans('SchoolNewStudent'), '', 'fa-user-graduate');
	print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="add">';
	print '<table class="border centpercent">';
	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('Firstname').'</td><td><input type="text" name="firstname" value="'.dol_escape_htmltag(GETPOST('firstname', 'alphanohtml')).'" required></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Lastname').'</td><td><input type="text" name="lastname" value="'.dol_escape_htmltag(GETPOST('lastname', 'alphanohtml')).'" required></td></tr>';
	print '<tr><td>'.$langs->trans('SchoolBirthdate').'</td><td><input type="date" name="birthdate" value="'.dol_escape_htmltag(GETPOST('birthdate', 'alphanohtml')).'"></td></tr>';
	print '<tr><td>'.$langs->trans('Email').'</td><td><input type="text" class="minwidth300" name="email" value="'.dol_escape_htmltag(GETPOST('email', 'alphanohtml')).'"> <span class="opacitymedium">'.$langs->trans('SchoolStudentEmailHelp').'</span></td></tr>';
	print '</table><div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Create')).'"> <a class="button button-cancel" href="'.dol_escape_htmltag($listUrl).'">'.$langs->trans('Cancel').'</a></div></form>';
	llxFooter();
	$db->close();
	exit;
}

// ---- Card
llxHeader('', $student->ref.' '.$student->firstname.' '.$student->lastname);
print load_fiche_titre($student->ref.' &mdash; '.dol_escape_htmltag($student->firstname.' '.$student->lastname), '<a href="'.dol_escape_htmltag($listUrl).'">'.$langs->trans('BackToList').'</a>', 'fa-user-graduate');

// Student
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="'.$id.'">';
print '<table class="border centpercent">';
$dis = $canWrite ? '' : ' disabled';
print '<tr><td class="titlefield fieldrequired">'.$langs->trans('Firstname').'</td><td><input type="text" name="firstname" value="'.dol_escape_htmltag($student->firstname).'" required'.$dis.'></td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans('Lastname').'</td><td><input type="text" name="lastname" value="'.dol_escape_htmltag($student->lastname).'" required'.$dis.'></td></tr>';
print '<tr><td>'.$langs->trans('SchoolBirthdate').'</td><td><input type="date" name="birthdate" value="'.($student->birthdate ? dol_print_date($db->jdate($student->birthdate), '%Y-%m-%d') : '').'"'.$dis.'></td></tr>';
print '<tr><td>'.$langs->trans('Email').'</td><td><input type="text" class="minwidth300" name="email" value="'.dol_escape_htmltag((string) $student->email).'"'.$dis.'> <span class="opacitymedium">'.$langs->trans('SchoolStudentEmailHelp').'</span></td></tr>';
print '<tr><td>'.$langs->trans('SchoolActive').'</td><td><input type="checkbox" name="active" value="1"'.((int) $student->status === 1 ? ' checked' : '').$dis.'> <span class="opacitymedium">'.$langs->trans('SchoolActiveHelp').'</span></td></tr>';
print '<tr><td>'.$langs->trans('SchoolChamiloAccount').'</td><td>';
if ($student->chamilo_user_id) {
	print dol_escape_htmltag((string) $student->chamilo_username).' <span class="opacitymedium">#'.((int) $student->chamilo_user_id).'</span> &mdash; ';
}
if ((int) $student->sync_status === 1) {
	print $langs->trans('SchoolStatusSynced');
	if (strpos((string) $student->last_error, 'NOTE:') === 0) {
		print ' <span class="warning">'.img_picto('', 'warning').' '.dol_escape_htmltag(substr((string) $student->last_error, 5)).'</span>';
	}
} elseif ((int) $student->sync_status === 2) {
	print '<span class="error">'.$langs->trans('SchoolStatusError').': '.dol_escape_htmltag((string) $student->last_error).'</span>';
} else {
	print $langs->trans('SchoolStatusPending');
}
print '</td></tr></table>';
if ($canWrite) {
	print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
}
print '</form>';
if ($canWrite) {
	print '<div class="center">'.$formOpen('resend').'<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('SchoolResend')).'"></form></div>';
}

// Parents
print '<br>'.load_fiche_titre($langs->trans('SchoolParents'), '', '');
print '<div class="opacitymedium">'.$langs->trans('SchoolParentsHelp').'</div><br>';
print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('ThirdParty').'</td><td>'.$langs->trans('Contact').'</td><td>'.$langs->trans('SchoolRelation').'</td><td>'.$langs->trans('SchoolPayer').'</td><td>'.$langs->trans('SchoolPayerShare').'</td><td>'.$langs->trans('SchoolFollowsInChamilo').'</td><td></td></tr>';
$pq = $db->query("SELECT sp.*, s.nom FROM {$P}school_student_parent AS sp INNER JOIN {$P}societe AS s ON s.rowid = sp.fk_soc WHERE sp.fk_student = {$id} ORDER BY sp.is_payer DESC, s.nom");
$nbp = 0;
while ($pq && ($l = $db->fetch_object($pq))) {
	if ((int) $l->chamilo_follow === SchoolStudent::UNFOLLOW) {
		continue;
	}
	$nbp++;
	print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $l->fk_soc).'">'.dol_escape_htmltag($l->nom).'</a></td>';
	if ($canWrite) {
		// The fields of a row belong to one form through the form attribute: a form cannot wrap table cells.
		$fid = 'parentform'.((int) $l->rowid);
		print '<td><select name="contactid" form="'.$fid.'"><option value="0">&nbsp;</option>';
		$cq = $db->query("SELECT rowid, firstname, lastname, email FROM {$P}socpeople WHERE fk_soc = ".((int) $l->fk_soc)." AND statut = 1 ORDER BY lastname, firstname");
		while ($cq && ($c = $db->fetch_object($cq))) {
			print '<option value="'.((int) $c->rowid).'"'.((int) $l->fk_socpeople === (int) $c->rowid ? ' selected' : '').'>'.dol_escape_htmltag(trim($c->firstname.' '.$c->lastname).($c->email ? ' <'.$c->email.'>' : '')).'</option>';
		}
		print '</select></td><td><select name="relation" form="'.$fid.'">';
		foreach (array('parent' => 'SchoolRelParent', 'guardian' => 'SchoolRelGuardian', 'other' => 'SchoolRelOther') as $k => $label) {
			print '<option value="'.$k.'"'.($l->relation === $k ? ' selected' : '').'>'.$langs->trans($label).'</option>';
		}
		print '</select></td><td><input type="checkbox" name="payer" value="1" form="'.$fid.'"'.((int) $l->is_payer === 1 ? ' checked' : '').'></td>';
		print '<td><input type="text" class="width50 right" name="share" form="'.$fid.'" value="'.dol_escape_htmltag((string) price2num($l->payer_share)).'"> %</td>';
		print '<td><input type="checkbox" name="follow" value="1" form="'.$fid.'"'.((int) $l->chamilo_follow === SchoolStudent::FOLLOWING ? ' checked' : '').'></td>';
		print '<td class="right nowraponall"><form id="'.$fid.'" method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="saveparent"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="linkid" value="'.((int) $l->rowid).'">';
		print '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('Save')).'"></form> ';
		print $formOpen('removeparent', array('linkid' => (int) $l->rowid)).'<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('Delete')).'"></form></td></tr>';
	} else {
		print '<td colspan="5" class="opacitymedium">'.dol_escape_htmltag($l->relation).($l->is_payer ? ', '.$langs->trans('SchoolPayer').' '.price2num($l->payer_share).' %' : '').((int) $l->chamilo_follow === 1 ? ', '.$langs->trans('SchoolFollowsInChamilo') : '').'</td><td></td></tr>';
	}
}
if ($nbp === 0) {
	print '<tr class="oddeven"><td colspan="7" class="opacitymedium">'.$langs->trans('SchoolNoParentYet').'</td></tr>';
}
print '</table>';
if ($canWrite) {
	print '<br>'.$formOpen('addparent').$form->select_company('', 'socid', '', 'SelectThirdParty', 0, 0, array(), 0, 'minwidth250').' <input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('SchoolAddParent')).'"></form>';
	print ' <span class="opacitymedium">'.$langs->trans('SchoolAddParentHelp').'</span>';
}

// Enrolments
print '<br><br>'.load_fiche_titre($langs->trans('SchoolEnrolments'), '', '');
print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('SchoolEnrolledIn').'</td><td>'.$langs->trans('SchoolSyncStatus').'</td><td></td></tr>';
$eq = $db->query("SELECT e.rowid, e.status, e.sync_status, e.last_error, e.fk_session, e.fk_course, ps.label AS session_label, pc.label AS course_label"
	." FROM {$P}school_enrolment AS e LEFT JOIN {$P}school_session AS s ON s.rowid = e.fk_session LEFT JOIN {$P}product AS ps ON ps.rowid = s.fk_product"
	." LEFT JOIN {$P}school_course AS c ON c.rowid = e.fk_course LEFT JOIN {$P}product AS pc ON pc.rowid = c.fk_product WHERE e.fk_student = {$id} ORDER BY e.rowid");
$nbe = 0;
while ($eq && ($e = $db->fetch_object($eq))) {
	$nbe++;
	print '<tr class="oddeven"><td>'.($e->fk_session ? $langs->trans('SchoolSession').' : '.dol_escape_htmltag((string) $e->session_label) : $langs->trans('SchoolCourse').' : '.dol_escape_htmltag((string) $e->course_label)).'</td><td>';
	if ((int) $e->status === SchoolStudent::TO_REMOVE) {
		print $langs->trans('SchoolRemovalPending');
	} elseif ((int) $e->sync_status === 2) {
		print '<span class="error" title="'.dol_escape_htmltag((string) $e->last_error).'">'.$langs->trans('SchoolStatusError').'</span>';
	} elseif ((int) $e->status === SchoolStudent::ENROLLED) {
		print $langs->trans('SchoolEnrolled');
	} else {
		print $langs->trans('SchoolStatusPending');
	}
	print '</td><td class="right">';
	if ($canWrite && (int) $e->status !== SchoolStudent::TO_REMOVE) {
		print $formOpen('unenrol', array('enrolmentid' => (int) $e->rowid)).'<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('SchoolUnenrol')).'"></form>';
	}
	print '</td></tr>';
}
if ($nbe === 0) {
	print '<tr class="oddeven"><td colspan="3" class="opacitymedium">'.$langs->trans('SchoolNoEnrolmentYet').'</td></tr>';
}
print '</table>';
if ($canWrite) {
	print '<br>'.$formOpen('enrol').'<select name="target"><option value="">&nbsp;</option>';
	$sq = $db->query("SELECT s.rowid, p.label FROM {$P}school_session AS s INNER JOIN {$P}product AS p ON p.rowid = s.fk_product WHERE s.entity = {$entity} AND s.chamilo_id IS NOT NULL ORDER BY p.label");
	print '<optgroup label="'.dol_escape_htmltag($langs->trans('SchoolSessions')).'">';
	while ($sq && ($o = $db->fetch_object($sq))) {
		print '<option value="s:'.((int) $o->rowid).'">'.dol_escape_htmltag($o->label).'</option>';
	}
	print '</optgroup><optgroup label="'.dol_escape_htmltag($langs->trans('SchoolCourses')).'">';
	$cq = $db->query("SELECT c.rowid, p.label FROM {$P}school_course AS c INNER JOIN {$P}product AS p ON p.rowid = c.fk_product WHERE c.entity = {$entity} AND c.chamilo_id IS NOT NULL ORDER BY p.label");
	while ($cq && ($o = $db->fetch_object($cq))) {
		print '<option value="c:'.((int) $o->rowid).'">'.dol_escape_htmltag($o->label).'</option>';
	}
	print '</optgroup></select> <input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('SchoolEnrol')).'"></form>';
}

llxFooter();
$db->close();
