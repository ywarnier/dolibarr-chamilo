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
 * \file    admin/setup.php
 * \ingroup school
 * \brief   Chamilo connection settings.
 */

require_once __DIR__.'/../lib/school_bootstrap.inc.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../class/chamilosetup.class.php';
require_once __DIR__.'/../class/schoolcatalog.class.php';
schoolLoadLangs();
if (!isModEnabled('school')) {
	accessforbidden();
}
schoolRequireRight('setup');

$action = GETPOST('action', 'aZ09');

if ($action === 'save') {
	$url = schoolNormalizeBaseUrl(GETPOST('SCHOOL_CHAMILO_URL', 'alphanohtml'));
	if ($url === '') {
		setEventMessages($langs->trans('SchoolBadUrl'), null, 'errors');
	} else {
		dolibarr_set_const($db, 'SCHOOL_CHAMILO_URL', $url, 'chaine', 0, '', (int) $conf->entity);
		dolibarr_set_const($db, 'SCHOOL_CHAMILO_ALLOW_LOCAL', GETPOST('SCHOOL_CHAMILO_ALLOW_LOCAL', 'int') ? '1' : '0', 'chaine', 0, '', (int) $conf->entity);
		// An empty key field keeps the stored key.
		$key = trim(GETPOST('SCHOOL_CHAMILO_API_KEY', 'alphanohtml'));
		if ($key !== '') {
			schoolSetApiKey($db, $key);
		}
		setEventMessages($langs->trans('RecordSaved'), null);
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'test') {
	$client = schoolGetClient();
	if ($client === null) {
		setEventMessages($langs->trans('SchoolNotConfigured'), null, 'errors');
	} else {
		$res = $client->ping();
		if ($res['ok']) {
			setEventMessages($langs->trans('SchoolConnectionOk'), null);
		} elseif ($res['status'] === 401) {
			setEventMessages($langs->trans('SchoolConnectionKeyRefused', $res['error']), null, 'errors');
		} elseif ($res['status'] === 403) {
			setEventMessages($langs->trans('SchoolConnectionNotAdmin'), null, 'errors');
		} else {
			setEventMessages($langs->trans('SchoolConnectionFailed', $res['error']), null, 'errors');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'setupfields' || $action === 'pushidentity') {
	$client = schoolGetClient();
	if ($client === null) {
		setEventMessages($langs->trans('SchoolNotConfigured'), null, 'errors');
	} elseif ($action === 'setupfields') {
		$created = 0;
		$existing = 0;
		$errors = array();
		foreach ((new ChamiloSetup($client))->ensureAll($db) as $name => $r) {
			if (!$r['ok']) {
				$errors[] = $name.': '.$r['error'];
			} elseif ($r['created']) {
				$created++;
			} else {
				$existing++;
			}
		}
		setEventMessages($langs->trans('SchoolFieldsReport', $created, $existing), $errors ?: null, $errors ? 'errors' : 'mesgs');
	} else {
		$report = (new SchoolCatalog($db, $user))->pushIdentity($client);
		setEventMessages($langs->trans('SchoolPushReport', $report['courses']['created'] + $report['courses']['updated'], $report['sessions']['created'] + $report['sessions']['updated'], $report['courses']['unchanged'] + $report['sessions']['unchanged']), $report['errors'] ?: null, $report['errors'] ? 'errors' : 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

llxHeader('', $langs->trans('SchoolSetup'));
print load_fiche_titre($langs->trans('SchoolSetup'), '', 'title_setup');

print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('SchoolChamiloConnection').'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('SchoolChamiloUrl').'<br><span class="opacitymedium">'.$langs->trans('SchoolChamiloUrlHelp').'</span></td>';
print '<td><input type="text" class="minwidth400" name="SCHOOL_CHAMILO_URL" value="'.dol_escape_htmltag(getDolGlobalString('SCHOOL_CHAMILO_URL')).'" placeholder="https://campus.example.com"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('SchoolChamiloApiKey').'<br><span class="opacitymedium">'.$langs->trans('SchoolChamiloApiKeyHelp').'</span></td>';
print '<td><input type="password" class="minwidth400" name="SCHOOL_CHAMILO_API_KEY" value="" autocomplete="new-password" placeholder="'.(schoolGetApiKey() !== '' ? dol_escape_htmltag($langs->trans('SchoolKeyStored')) : 'chamilo_ext_...').'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('SchoolAllowLocal').'<br><span class="opacitymedium">'.$langs->trans('SchoolAllowLocalHelp').'</span></td>';
print '<td><input type="checkbox" name="SCHOOL_CHAMILO_ALLOW_LOCAL" value="1"'.(getDolGlobalInt('SCHOOL_CHAMILO_ALLOW_LOCAL') ? ' checked' : '').'></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
print '</form>';

print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('SchoolPrepareChamilo').'</td></tr>';
$steps = array(
	'test' => array('SchoolTestConnection', 'SchoolTestConnectionHelp'),
	'setupfields' => array('SchoolCreateExtraFields', 'SchoolCreateExtraFieldsHelp'),
	'pushidentity' => array('SchoolPushIdentity', 'SchoolPushIdentityHelp'),
);
foreach ($steps as $act => $labels) {
	print '<tr class="oddeven"><td>'.$langs->trans($labels[0]).'<br><span class="opacitymedium">'.$langs->trans($labels[1]).'</span></td><td class="right nowraponall">';
	print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="inline-block">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="'.$act.'">';
	print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans($labels[0])).'"></form></td></tr>';
}
print '<tr class="oddeven"><td>'.$langs->trans('SchoolExtraFieldIds').'</td><td class="right opacitymedium">';
$ids = array();
foreach (array_keys(ChamiloSetup::requiredFields()) as $const) {
	$ids[] = str_replace('SCHOOL_EF_', '', $const).'='.(getDolGlobalInt($const) > 0 ? getDolGlobalInt($const) : '-');
}
print dol_escape_htmltag(implode('  ', $ids)).'</td></tr>';
print '</table>';

llxFooter();
$db->close();
