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
 * \file    room_list.php
 * \ingroup school
 * \brief   Rooms of the school: resources of the Resources module that are also rooms in Chamilo.
 */

require_once __DIR__.'/lib/school_bootstrap.inc.php';
require_once __DIR__.'/class/schoolroom.class.php';
require_once __DIR__.'/class/schoolcron.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

schoolStartPage();
if (!isModEnabled('resource')) {
	accessforbidden($langs->trans('SchoolResourceModuleOff'));
}

$P = MAIN_DB_PREFIX;
$entity = (int) $conf->entity;
$self = $_SERVER['PHP_SELF'].'?mainmenu=school&leftmenu=schoolrooms';
$action = GETPOST('action', 'aZ09');
$rooms = new SchoolRoom($db);
$canWrite = $user->hasRight('school', 'write');
$say = static function (string $error) use ($langs): string {
	return strpos($error, 'School') === 0 ? $langs->trans($error) : $error;
};
$intOrNull = static function (string $raw): ?int {
	$raw = trim($raw);
	return $raw === '' || !preg_match('/^-?\d+$/', $raw) ? null : (int) $raw;
};

if (in_array($action, array('adopt', 'saveroom', 'import', 'syncnow'), true)) {
	if ($action === 'import' || $action === 'syncnow') {
		schoolRequireRight('sync');
	} else {
		schoolRequireRight('write');
	}
	if ($action === 'adopt') {
		$res = $rooms->adopt(GETPOSTINT('resourceid'), $intOrNull(GETPOST('capacity', 'alphanohtml')), $intOrNull(GETPOST('floor', 'alphanohtml')));
		setEventMessages($res['error'] === '' ? $langs->trans('SchoolQueued') : $say($res['error']), null, $res['error'] === '' ? 'mesgs' : 'errors');
	} elseif ($action === 'saveroom') {
		$error = $rooms->update(GETPOSTINT('roomid'), $intOrNull(GETPOST('capacity', 'alphanohtml')), $intOrNull(GETPOST('floor', 'alphanohtml')));
		setEventMessages($error === '' ? $langs->trans('SchoolQueued') : $say($error), null, $error === '' ? 'mesgs' : 'errors');
	} elseif ($action === 'import') {
		$client = schoolGetClient();
		if ($client === null) {
			setEventMessages($langs->trans('SchoolNotConfigured'), null, 'errors');
		} else {
			$report = $rooms->importFromChamilo($client);
			setEventMessages($langs->trans('SchoolRoomsImported', $report['created'], $report['linked'], $report['known']), $report['errors'] ?: null, $report['errors'] ? 'warnings' : 'mesgs');
		}
	} else {
		$cron = new SchoolCron($db);
		$cron->runSync();
		setEventMessages($cron->output, null);
	}
	header('Location: '.$self);
	exit;
}

llxHeader('', $langs->trans('SchoolRooms'));
print load_fiche_titre($langs->trans('SchoolRooms'), '', 'fa-door-open');
print '<div class="opacitymedium">'.$langs->trans('SchoolRoomsHelp').'</div><br>';

if ($user->hasRight('school', 'sync')) {
	print '<form method="POST" action="'.dol_escape_htmltag($self).'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="import"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('SchoolImportRooms')).'"></form> ';
	print '<form method="POST" action="'.dol_escape_htmltag($self).'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="syncnow"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('SchoolSyncNow')).'"></form> ';
}
print '<a href="'.DOL_URL_ROOT.'/resource/card.php?action=create">'.$langs->trans('SchoolCreateResource').'</a><br><br>';

$sql = "SELECT r.rowid, r.floor_number, r.chamilo_room_id, r.sync_status, r.last_error, x.rowid AS xid, x.ref, x.description, x.max_users"
	." FROM {$P}school_room AS r INNER JOIN {$P}resource AS x ON x.rowid = r.fk_resource WHERE r.entity = {$entity} ORDER BY x.ref";
$resql = $db->query($sql);
print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('Description').'</td><td class="right">'.$langs->trans('SchoolCapacity').'</td><td class="right">'.$langs->trans('SchoolFloor').'</td><td>'.$langs->trans('SchoolChamiloId').'</td><td>'.$langs->trans('SchoolSyncStatus').'</td><td></td></tr>';
$nb = 0;
while ($resql && ($o = $db->fetch_object($resql))) {
	$nb++;
	$fid = 'roomform'.((int) $o->rowid);
	print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/resource/card.php?id='.((int) $o->xid).'">'.img_picto('', 'resource', 'class="pictofixedwidth"').dol_escape_htmltag($o->ref).'</a> <a href="'.DOL_URL_ROOT.'/resource/agenda.php?id='.((int) $o->xid).'" title="'.dol_escape_htmltag($langs->trans('SchoolRoomPlanning')).'">'.img_picto($langs->trans('SchoolRoomPlanning'), 'action').'</a></td>';
	print '<td>'.dol_escape_htmltag((string) $o->description).'</td>';
	if ($canWrite) {
		print '<td class="right"><input type="text" class="width50 right" name="capacity" form="'.$fid.'" value="'.($o->max_users !== null ? (int) $o->max_users : '').'"></td><td class="right"><input type="text" class="width50 right" name="floor" form="'.$fid.'" value="'.($o->floor_number !== null ? (int) $o->floor_number : '').'"></td>';
	} else {
		print '<td class="right">'.($o->max_users !== null ? (int) $o->max_users : '').'</td><td class="right">'.($o->floor_number !== null ? (int) $o->floor_number : '').'</td>';
	}
	print '<td>'.($o->chamilo_room_id ? '#'.((int) $o->chamilo_room_id) : '<span class="opacitymedium">'.$langs->trans('SchoolNotInChamilo').'</span>').'</td><td>';
	if ((int) $o->sync_status === 1) {
		print $langs->trans('SchoolStatusSynced');
	} elseif ((int) $o->sync_status === 2) {
		print '<span class="error" title="'.dol_escape_htmltag((string) $o->last_error).'">'.$langs->trans('SchoolStatusError').'</span>';
	} else {
		print $langs->trans('SchoolStatusPending');
	}
	print '</td><td class="right">';
	if ($canWrite) {
		print '<form id="'.$fid.'" method="POST" action="'.dol_escape_htmltag($self).'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="saveroom"><input type="hidden" name="roomid" value="'.((int) $o->rowid).'"><input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('Save')).'"></form>';
	}
	print '</td></tr>';
}
if ($nb === 0) {
	print '<tr class="oddeven"><td colspan="7" class="opacitymedium">'.$langs->trans('SchoolNoRoomYet').'</td></tr>';
}
print '</table>';

if ($canWrite) {
	print '<br>'.load_fiche_titre($langs->trans('SchoolUseResourceAsRoom'), '', '');
	$rq = $db->query("SELECT x.rowid, x.ref, x.fk_code_type_resource FROM {$P}resource AS x WHERE x.entity IN (".getEntity('resource').") AND x.rowid NOT IN (SELECT fk_resource FROM {$P}school_room WHERE entity = {$entity}) ORDER BY (x.fk_code_type_resource = '".SchoolRoom::RESOURCE_TYPE."') DESC, x.ref");
	print '<form method="POST" action="'.dol_escape_htmltag($self).'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="adopt"><select name="resourceid"><option value="0">&nbsp;</option>';
	while ($rq && ($o = $db->fetch_object($rq))) {
		print '<option value="'.((int) $o->rowid).'">'.dol_escape_htmltag($o->ref).'</option>';
	}
	print '</select> '.$langs->trans('SchoolCapacity').' <input type="text" class="width50 right" name="capacity"> '.$langs->trans('SchoolFloor').' <input type="text" class="width50 right" name="floor"> <input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('SchoolAddRoom')).'"></form>';
}

llxFooter();
$db->close();
