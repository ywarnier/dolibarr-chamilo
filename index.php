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
 * \file    index.php
 * \ingroup school
 * \brief   Home: connection and sync queue status.
 */

require_once __DIR__.'/lib/school_bootstrap.inc.php';
require_once __DIR__.'/class/syncqueue.class.php';

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

schoolStartPage();

llxHeader('', $langs->trans('School'));
print load_fiche_titre($langs->trans('School'), '', 'fa-graduation-cap');

if (schoolGetClient() === null) {
	$msg = $langs->trans('SchoolNotConfigured');
	if ($user->hasRight('school', 'setup')) {
		$msg .= ' <a href="'.dol_buildpath('/school/admin/setup.php', 1).'">'.$langs->trans('SchoolSetup').'</a>';
	}
	print '<div class="warning">'.$msg.'</div>';
}

$counts = (new SyncQueue($db))->countByStatus();
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('SchoolSyncQueue').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('SchoolQueuePending').'</td><td class="right">'.$counts[SyncQueue::PENDING].'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('SchoolQueueRunning').'</td><td class="right">'.$counts[SyncQueue::RUNNING].'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('SchoolQueueFailed').'</td><td class="right">'.$counts[SyncQueue::FAILED].'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('SchoolQueueDone').'</td><td class="right">'.$counts[SyncQueue::DONE].'</td></tr>';
print '</table>';
print '<br><a href="'.dol_buildpath('/school/sync_log.php', 1).'?mainmenu=school&leftmenu=schoolsynclog">'.$langs->trans('SchoolSyncLog').'</a>';

llxFooter();
$db->close();
