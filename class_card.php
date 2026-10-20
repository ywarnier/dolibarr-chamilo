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
 * \file    class_card.php
 * \ingroup school
 * \brief   A class: its details, members, sessions, and the announcement to its parents.
 */

require_once __DIR__.'/lib/school_bootstrap.inc.php';
require_once __DIR__.'/class/schoolclass.class.php';

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
$classes = new SchoolClass($db);
$listUrl = dol_buildpath('/school/class_list.php', 1).'?mainmenu=school&leftmenu=schoolclasses';
$cardUrl = static function (int $classId): string {
	return dol_buildpath('/school/class_card.php', 1).'?id='.$classId.'&mainmenu=school&leftmenu=schoolclasses';
};
$say = static function (string $error) use ($langs): string {
	return strpos($error, 'School') === 0 ? $langs->trans($error) : $error;
};
$dateOrNull = static function (string $raw): ?string {
	$raw = trim($raw);
	return $raw === '' ? null : $raw;
};

$class = null;
if ($id > 0) {
	$resql = $db->query("SELECT * FROM {$P}school_class WHERE entity = {$entity} AND rowid = ".$id);
	$class = $resql ? ($db->fetch_object($resql) ?: null) : null;
	if (!$class) {
		accessforbidden();
	}
}

// Actions: each needs the class right and goes back to the card.
if (in_array($action, array('add', 'save', 'addstudent', 'removestudent', 'addsession', 'removesession', 'announce', 'resend'), true)) {
	schoolRequireRight('class');

	if ($action === 'add') {
		$res = $classes->create(GETPOST('label', 'alphanohtml'), GETPOST('level', 'alphanohtml'), $dateOrNull(GETPOST('date_start', 'alphanohtml')), $dateOrNull(GETPOST('date_end', 'alphanohtml')), GETPOSTINT('homeroom'));
		if ($res['error'] === '') {
			setEventMessages($langs->trans('SchoolClassCreated'), null);
			header('Location: '.$cardUrl($res['id']));
			exit;
		}
		setEventMessages($say($res['error']), null, 'errors');
		$action = 'create';
	} elseif ($class) {
		$error = '';
		if ($action === 'save') {
			$error = $classes->update($id, GETPOST('label', 'alphanohtml'), GETPOST('level', 'alphanohtml'), $dateOrNull(GETPOST('date_start', 'alphanohtml')), $dateOrNull(GETPOST('date_end', 'alphanohtml')), GETPOSTINT('homeroom'), GETPOSTINT('active') > 0);
		} elseif ($action === 'addstudent') {
			$error = $classes->addStudent($id, GETPOSTINT('studentid'), $dateOrNull(GETPOST('since', 'alphanohtml')));
		} elseif ($action === 'removestudent') {
			$error = $classes->removeStudent(GETPOSTINT('memberid'));
		} elseif ($action === 'addsession') {
			$error = $classes->addSession($id, GETPOSTINT('sessionid'));
		} elseif ($action === 'removesession') {
			$error = $classes->removeSession($id, GETPOSTINT('sessionid'));
		} elseif ($action === 'resend') {
			(new SyncQueue($db))->enqueue('class', $id, 'update');
		} elseif ($action === 'announce') {
			// Prepare a campaign in Dolibarr's Emailing module, with the parents of the class as recipients. It is sent from there.
			if (!isModEnabled('mailing')) {
				setEventMessages($langs->trans('SchoolMailingModuleOff'), null, 'errors');
			} elseif (!$user->hasRight('mailing', 'creer')) {
				setEventMessages($langs->trans('SchoolMailingNoRight'), null, 'errors');
			} else {
				require_once DOL_DOCUMENT_ROOT.'/comm/mailing/class/mailing.class.php';
				require_once __DIR__.'/core/modules/mailings/school_class_parents.modules.php';
				$subject = trim(GETPOST('subject', 'alphanohtml'));
				$message = trim(GETPOST('message', 'restricthtml'));
				$from = getDolGlobalString('MAILING_EMAIL_FROM') ?: (getDolGlobalString('MAIN_MAIL_EMAIL_FROM') ?: (string) $user->email);
				if ($subject === '' || $message === '') {
					setEventMessages($langs->trans('SchoolErrAnnouncementEmpty'), null, 'errors');
				} elseif ($from === '') {
					setEventMessages($langs->trans('SchoolErrNoSender'), null, 'errors');
				} else {
					$mailing = new Mailing($db);
					$mailing->title = dol_substr($langs->trans('SchoolAnnouncementTitle', $class->label).' - '.$subject, 0, 200);
					$mailing->sujet = $subject;
					$mailing->body = nl2br(dol_escape_htmltag($message));
					$mailing->email_from = $from;
					$newId = $mailing->create($user);
					if ($newId > 0) {
						$_POST['filter_school_class'] = (string) $id;
						$selector = new mailing_school_class_parents($db);
						$added = $selector->add_to_target($newId);
						setEventMessages($langs->trans('SchoolAnnouncementPrepared', max(0, (int) $added)), null);
						header('Location: '.DOL_URL_ROOT.'/comm/mailing/card.php?id='.((int) $newId));
						exit;
					}
					setEventMessages($mailing->error ?: $mailing->errors, null, 'errors');
				}
			}
		}
		if ($action !== 'announce') {
			setEventMessages($error === '' ? $langs->trans('SchoolQueued') : $say($error), null, $error === '' ? 'mesgs' : 'errors');
		}
		header('Location: '.$cardUrl($id));
		exit;
	}
}

$canWrite = $user->hasRight('school', 'class');
$formOpen = static function (string $act, array $hidden = array()) use ($id): string {
	$html = '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="'.$act.'"><input type="hidden" name="id" value="'.$id.'">';
	foreach ($hidden as $k => $v) {
		$html .= '<input type="hidden" name="'.$k.'" value="'.dol_escape_htmltag((string) $v).'">';
	}
	return $html;
};
$teacherOptions = static function (?int $selected) use ($db, $P, $entity): string {
	$html = '<option value="0">&nbsp;</option>';
	$q = $db->query("SELECT t.rowid, u.firstname, u.lastname, u.login FROM {$P}school_teacher AS t INNER JOIN {$P}user AS u ON u.rowid = t.fk_user WHERE t.entity = {$entity} ORDER BY u.lastname, u.firstname");
	while ($q && ($o = $db->fetch_object($q))) {
		$html .= '<option value="'.((int) $o->rowid).'"'.($selected === (int) $o->rowid ? ' selected' : '').'>'.dol_escape_htmltag(trim($o->firstname.' '.$o->lastname) ?: (string) $o->login).'</option>';
	}
	return $html;
};

// ---- Creation form
if (!$class) {
	schoolRequireRight('class');
	llxHeader('', $langs->trans('SchoolNewClass'));
	print load_fiche_titre($langs->trans('SchoolNewClass'), '', 'fa-users');
	print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="add"><table class="border centpercent">';
	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('Label').'</td><td><input type="text" class="minwidth300" name="label" value="'.dol_escape_htmltag(GETPOST('label', 'alphanohtml')).'" required></td></tr>';
	print '<tr><td>'.$langs->trans('SchoolLevel').'</td><td><input type="text" name="level" value="'.dol_escape_htmltag(GETPOST('level', 'alphanohtml')).'"></td></tr>';
	print '<tr><td>'.$langs->trans('SchoolCycleStart').'</td><td><input type="date" name="date_start" value="'.dol_escape_htmltag(GETPOST('date_start', 'alphanohtml')).'"></td></tr>';
	print '<tr><td>'.$langs->trans('SchoolCycleEnd').'</td><td><input type="date" name="date_end" value="'.dol_escape_htmltag(GETPOST('date_end', 'alphanohtml')).'"> <span class="opacitymedium">'.$langs->trans('SchoolCycleHelp').'</span></td></tr>';
	print '<tr><td>'.$langs->trans('SchoolHomeroomTeacher').'</td><td><select name="homeroom">'.$teacherOptions(null).'</select></td></tr>';
	print '</table><div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Create')).'"> <a class="button button-cancel" href="'.dol_escape_htmltag($listUrl).'">'.$langs->trans('Cancel').'</a></div></form>';
	llxFooter();
	$db->close();
	exit;
}

// ---- Card
llxHeader('', $class->ref.' '.$class->label);
print load_fiche_titre($class->ref.' &mdash; '.dol_escape_htmltag($class->label), '<a href="'.dol_escape_htmltag($listUrl).'">'.$langs->trans('BackToList').'</a>', 'fa-users');

$dis = $canWrite ? '' : ' disabled';
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="'.$id.'"><table class="border centpercent">';
print '<tr><td class="titlefield fieldrequired">'.$langs->trans('Label').'</td><td><input type="text" class="minwidth300" name="label" value="'.dol_escape_htmltag($class->label).'" required'.$dis.'></td></tr>';
print '<tr><td>'.$langs->trans('SchoolLevel').'</td><td><input type="text" name="level" value="'.dol_escape_htmltag((string) $class->level).'"'.$dis.'></td></tr>';
print '<tr><td>'.$langs->trans('SchoolCycleStart').'</td><td><input type="date" name="date_start" value="'.($class->date_start ? dol_escape_htmltag(substr((string) $class->date_start, 0, 10)) : '').'"'.$dis.'></td></tr>';
print '<tr><td>'.$langs->trans('SchoolCycleEnd').'</td><td><input type="date" name="date_end" value="'.($class->date_end ? dol_escape_htmltag(substr((string) $class->date_end, 0, 10)) : '').'"'.$dis.'></td></tr>';
print '<tr><td>'.$langs->trans('SchoolHomeroomTeacher').'</td><td><select name="homeroom"'.$dis.'>'.$teacherOptions($class->fk_homeroom_teacher ? (int) $class->fk_homeroom_teacher : null).'</select></td></tr>';
print '<tr><td>'.$langs->trans('SchoolActive').'</td><td><input type="checkbox" name="active" value="1"'.((int) $class->status === 1 ? ' checked' : '').$dis.'> <span class="opacitymedium">'.$langs->trans('SchoolClassActiveHelp').'</span></td></tr>';
print '<tr><td>'.$langs->trans('SchoolChamiloClass').'</td><td>'.($class->chamilo_id ? '#'.((int) $class->chamilo_id).' &mdash; ' : '');
if ((int) $class->sync_status === 1) {
	print $langs->trans('SchoolStatusSynced');
	if (strpos((string) $class->last_error, 'NOTE:') === 0) {
		print ' <span class="warning">'.img_picto('', 'warning').' '.dol_escape_htmltag(substr((string) $class->last_error, 5)).'</span>';
	}
} elseif ((int) $class->sync_status === 2) {
	print '<span class="error">'.$langs->trans('SchoolStatusError').': '.dol_escape_htmltag((string) $class->last_error).'</span>';
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

// Members
$today = dol_print_date(dol_now(), '%Y-%m-%d');
print '<br>'.load_fiche_titre($langs->trans('SchoolMembers'), '', '');
print '<div class="opacitymedium">'.$langs->trans('SchoolMembersHelp').'</div><br>';
print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('SchoolStudent').'</td><td>'.$langs->trans('SchoolMemberFrom').'</td><td>'.$langs->trans('SchoolMemberUntil').'</td><td>'.$langs->trans('SchoolChamiloAccount').'</td><td></td></tr>';
$mq = $db->query("SELECT m.rowid, m.date_start, m.date_end, s.rowid AS sid, s.ref, s.firstname, s.lastname, s.chamilo_user_id FROM {$P}school_class_student AS m INNER JOIN {$P}school_student AS s ON s.rowid = m.fk_student WHERE m.fk_class = {$id} ORDER BY (m.date_end IS NOT NULL), s.lastname, s.firstname, m.date_start");
$nb = 0;
$currentIds = array();
while ($mq && ($m = $db->fetch_object($mq))) {
	$nb++;
	$current = schoolIsCurrentMember($m->date_start ? (string) $m->date_start : null, $m->date_end ? (string) $m->date_end : null, $today);
	if ($current) {
		$currentIds[(int) $m->sid] = true;
	}
	print '<tr class="oddeven'.($current ? '' : ' opacitymedium').'"><td><a href="'.dol_buildpath('/school/student_card.php', 1).'?id='.((int) $m->sid).'&mainmenu=school&leftmenu=schoolstudents">'.dol_escape_htmltag($m->ref.' '.$m->firstname.' '.$m->lastname).'</a></td>';
	print '<td>'.($m->date_start ? dol_print_date($db->jdate($m->date_start), 'day') : '').'</td><td>'.($m->date_end ? dol_print_date($db->jdate($m->date_end), 'day') : '').'</td>';
	print '<td>'.($m->chamilo_user_id ? '#'.((int) $m->chamilo_user_id) : '<span class="opacitymedium">'.$langs->trans('SchoolNoAccountYet').'</span>').'</td><td class="right">';
	if ($canWrite && $current) {
		print $formOpen('removestudent', array('memberid' => (int) $m->rowid)).'<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('SchoolRemoveFromClass')).'"></form>';
	}
	print '</td></tr>';
}
if ($nb === 0) {
	print '<tr class="oddeven"><td colspan="5" class="opacitymedium">'.$langs->trans('SchoolNoMemberYet').'</td></tr>';
}
print '</table>';
if ($canWrite) {
	print '<br>'.$formOpen('addstudent').'<select name="studentid"><option value="0">&nbsp;</option>';
	$sq = $db->query("SELECT rowid, ref, firstname, lastname FROM {$P}school_student WHERE entity = {$entity} AND status = 1 ORDER BY lastname, firstname");
	while ($sq && ($o = $db->fetch_object($sq))) {
		if (!isset($currentIds[(int) $o->rowid])) {
			print '<option value="'.((int) $o->rowid).'">'.dol_escape_htmltag($o->ref.' '.$o->firstname.' '.$o->lastname).'</option>';
		}
	}
	print '</select> '.$langs->trans('SchoolMemberFrom').' <input type="date" name="since" value="'.dol_escape_htmltag($today).'"> <input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('SchoolAddToClass')).'"></form>';
}

// Sessions
print '<br><br>'.load_fiche_titre($langs->trans('SchoolClassSessions'), '', '');
print '<div class="opacitymedium">'.$langs->trans('SchoolClassSessionsHelp').'</div><br>';
print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('SchoolSession').'</td><td>'.$langs->trans('SchoolDisplayDates').'</td><td>'.$langs->trans('SchoolChamiloId').'</td><td></td></tr>';
$linked = array();
$cq = $db->query("SELECT s.rowid, s.chamilo_id, s.display_start, s.display_end, p.label FROM {$P}school_class_session AS cs INNER JOIN {$P}school_session AS s ON s.rowid = cs.fk_session INNER JOIN {$P}product AS p ON p.rowid = s.fk_product WHERE cs.fk_class = {$id} ORDER BY p.label");
$nbs = 0;
while ($cq && ($o = $db->fetch_object($cq))) {
	$nbs++;
	$linked[(int) $o->rowid] = true;
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($o->label).'</td><td>'.($o->display_start ? dol_print_date($db->jdate($o->display_start), 'day') : '').' &rarr; '.($o->display_end ? dol_print_date($db->jdate($o->display_end), 'day') : '').'</td>';
	print '<td>'.($o->chamilo_id ? '#'.((int) $o->chamilo_id) : '<span class="opacitymedium">'.$langs->trans('SchoolNotInChamilo').'</span>').'</td><td class="right">';
	if ($canWrite) {
		print $formOpen('removesession', array('sessionid' => (int) $o->rowid)).'<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('SchoolRemoveFromClass')).'"></form>';
	}
	print '</td></tr>';
}
if ($nbs === 0) {
	print '<tr class="oddeven"><td colspan="4" class="opacitymedium">'.$langs->trans('SchoolNoClassSessionYet').'</td></tr>';
}
print '</table>';
if ($canWrite) {
	print '<br>'.$formOpen('addsession').'<select name="sessionid"><option value="0">&nbsp;</option>';
	$sq = $db->query("SELECT s.rowid, p.label FROM {$P}school_session AS s INNER JOIN {$P}product AS p ON p.rowid = s.fk_product WHERE s.entity = {$entity} ORDER BY p.label");
	while ($sq && ($o = $db->fetch_object($sq))) {
		if (!isset($linked[(int) $o->rowid])) {
			print '<option value="'.((int) $o->rowid).'">'.dol_escape_htmltag($o->label).'</option>';
		}
	}
	print '</select> <input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('SchoolAddToClass')).'"></form>';
}

// Announcement
print '<br><br>'.load_fiche_titre($langs->trans('SchoolAnnouncement'), '', '');
if (!$canWrite) {
	print '<div class="opacitymedium">'.$langs->trans('SchoolAnnouncementNeedsRight').'</div>';
} elseif (!isModEnabled('mailing')) {
	print '<div class="warning">'.$langs->trans('SchoolMailingModuleOff').'</div>';
} else {
	$recipients = schoolDistinctRecipients($classes->parentLinksOfMembers($id));
	print '<div class="opacitymedium">'.$langs->trans('SchoolAnnouncementHelp', count($recipients)).'</div><br>';
	print $formOpen('announce').'<table class="border centpercent">';
	print '<tr><td class="titlefield fieldrequired">'.$langs->trans('SchoolAnnouncementSubject').'</td><td><input type="text" class="minwidth400" name="subject"></td></tr>';
	print '<tr><td class="tdtop fieldrequired">'.$langs->trans('SchoolAnnouncementMessage').'</td><td><textarea name="message" rows="8" class="quatrevingtpercent"></textarea></td></tr></table>';
	print '<div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('SchoolPrepareAnnouncement')).'"></div></form>';
}

llxFooter();
$db->close();
