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
 * \file    sync_log.php
 * \ingroup school
 * \brief   The synchronization log: what was sent to Chamilo, what waits, what failed and why.
 */

require_once __DIR__.'/lib/school_bootstrap.inc.php';
require_once __DIR__.'/class/schoolsynclog.class.php';
require_once __DIR__.'/class/schoolcron.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

schoolStartPage();

$queue = new SyncQueue($db);
$action = GETPOST('action', 'aZ09');
$status = GETPOST('status', 'alpha') === '' ? -1 : GETPOSTINT('status');
$type = GETPOST('type', 'aZ09');
$page = max(0, GETPOSTINT('page'));
$perPage = 50;
$filters = '&status='.($status >= 0 ? $status : '').'&type='.urlencode($type);
$self = dol_buildpath('/school/sync_log.php', 1).'?mainmenu=school&leftmenu=schoolsynclog';

if (in_array($action, array('retry', 'retryall', 'dismiss', 'syncnow'), true)) {
	schoolRequireRight('sync');
	if ($action === 'retry') {
		$ok = $queue->retry(GETPOSTINT('job'));
		setEventMessages($langs->trans($ok ? 'SchoolJobRetried' : 'SchoolJobNotRetried'), null, $ok ? 'mesgs' : 'warnings');
	} elseif ($action === 'retryall') {
		setEventMessages($langs->trans('SchoolJobsRetried', $queue->retryAllFailed()), null);
	} elseif ($action === 'dismiss') {
		$ok = $queue->dismiss(GETPOSTINT('job'));
		setEventMessages($langs->trans($ok ? 'SchoolJobDismissed' : 'SchoolJobNotDismissed'), null, $ok ? 'mesgs' : 'warnings');
	} else {
		$cron = new SchoolCron($db);
		$cron->runSync();
		setEventMessages($cron->output, null);
	}
	header('Location: '.$self.$filters.'&page='.$page);
	exit;
}

$log = (new SchoolSyncLog($db))->list($status, $type, $perPage, $page * $perPage);
$counts = $queue->countByStatus();
$canSync = $user->hasRight('school', 'sync');
$post = static function (string $act, string $label, array $extra = array(), string $class = 'button smallpaddingimp') use ($self, $filters, $page): string {
	$html = '<form method="POST" action="'.dol_escape_htmltag($self.$filters.'&page='.$page).'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="'.$act.'">';
	foreach ($extra as $k => $v) {
		$html .= '<input type="hidden" name="'.dol_escape_htmltag($k).'" value="'.dol_escape_htmltag((string) $v).'">';
	}
	return $html.'<input type="submit" class="'.$class.'" value="'.dol_escape_htmltag($label).'"></form>';
};

llxHeader('', $langs->trans('SchoolSyncLog'));
print load_fiche_titre($langs->trans('SchoolSyncLog'), '', 'fa-sync');
print '<div class="opacitymedium">'.$langs->trans('SchoolSyncLogHelp', getDolGlobalInt('SCHOOL_LOG_KEEP_DAYS', 30)).'</div><br>';
if (schoolGetClient() === null) {
	print '<div class="warning">'.$langs->trans('SchoolNotConfigured').'</div><br>';
}

// Totals, each a filter
$names = array(SyncQueue::PENDING => 'SchoolQueuePending', SyncQueue::RUNNING => 'SchoolQueueRunning', SyncQueue::FAILED => 'SchoolQueueFailed', SyncQueue::DONE => 'SchoolQueueDone');
print '<div>';
foreach ($names as $st => $key) {
	print '<a class="badge badge-status'.($st === SyncQueue::FAILED && $counts[$st] > 0 ? '8' : ($st === SyncQueue::DONE ? '4' : '1')).'" href="'.dol_escape_htmltag($self.'&status='.$st.'&type='.urlencode($type)).'">'.$langs->trans($key).' : '.$counts[$st].'</a> ';
}
print '<a href="'.dol_escape_htmltag($self).'">'.$langs->trans('SchoolAllJobs').'</a></div><br>';

print '<form method="GET" action="'.dol_escape_htmltag(dol_buildpath('/school/sync_log.php', 1)).'" class="inline-block"><input type="hidden" name="mainmenu" value="school"><input type="hidden" name="leftmenu" value="schoolsynclog">';
print '<select name="status"><option value="">'.$langs->trans('SchoolAllJobs').'</option>';
foreach ($names as $st => $key) {
	print '<option value="'.$st.'"'.($st === $status ? ' selected' : '').'>'.$langs->trans($key).'</option>';
}
print '</select> <select name="type"><option value="">'.$langs->trans('SchoolAllTypes').'</option>';
foreach (SchoolSyncLog::types() as $tp) {
	print '<option value="'.$tp.'"'.($tp === $type ? ' selected' : '').'>'.$langs->trans('SchoolJobType_'.$tp).'</option>';
}
print '</select> <input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('Search')).'"></form> ';
if ($canSync) {
	print $post('syncnow', $langs->trans('SchoolSyncNow'), array(), 'button').' ';
	if ($counts[SyncQueue::FAILED] > 0) {
		print $post('retryall', $langs->trans('SchoolRetryAllFailed', $counts[SyncQueue::FAILED]), array(), 'button').' ';
	}
}
print '<br><br>';

print '<table class="noborder centpercent"><tr class="liste_titre"><td>#</td><td>'.$langs->trans('SchoolJobWhen').'</td><td>'.$langs->trans('SchoolJobWhat').'</td><td>'.$langs->trans('SchoolJobObject').'</td><td>'.$langs->trans('Status').'</td><td class="right">'.$langs->trans('SchoolJobAttempts').'</td><td>'.$langs->trans('SchoolJobMessage').'</td><td></td></tr>';
foreach ($log['rows'] as $r) {
	$label = dol_escape_htmltag($r['label']);
	print '<tr class="oddeven"><td>'.$r['id'].'</td><td class="nowraponall">'.dol_print_date($r['changed'], 'dayhour').'</td>';
	print '<td>'.$langs->trans('SchoolJobType_'.$r['type']).' <span class="opacitymedium">'.dol_escape_htmltag($r['action']).'</span></td>';
	print '<td>'.($r['url'] !== '' ? '<a href="'.dol_escape_htmltag($r['url']).'">'.$label.'</a>' : $label.($r['label'] === '#'.$r['object_id'] ? ' <span class="opacitymedium">'.$langs->trans('SchoolJobObjectGone').'</span>' : '')).'</td><td>';
	if ($r['status'] === SyncQueue::DONE) {
		print $langs->trans('SchoolQueueDone');
	} elseif ($r['status'] === SyncQueue::FAILED) {
		print '<span class="error">'.$langs->trans('SchoolQueueFailed').'</span>';
	} elseif ($r['status'] === SyncQueue::RUNNING) {
		print $langs->trans('SchoolQueueRunning');
	} else {
		print $langs->trans('SchoolQueuePending').($r['next_attempt'] > dol_now() ? ' <span class="opacitymedium">'.$langs->trans('SchoolJobNextTry', dol_print_date($r['next_attempt'], 'dayhour')).'</span>' : '');
	}
	print '</td><td class="right">'.$r['attempts'].'</td><td class="small">'.dol_escape_htmltag(dol_trunc($r['error'], 300)).'</td><td class="right nowraponall">';
	if ($canSync && in_array($r['status'], array(SyncQueue::FAILED, SyncQueue::PENDING), true) && ($r['status'] === SyncQueue::FAILED || $r['attempts'] > 0)) {
		print $post('retry', $langs->trans('SchoolRetry'), array('job' => $r['id'])).' ';
	}
	if ($canSync && $r['status'] === SyncQueue::FAILED) {
		print $post('dismiss', $langs->trans('SchoolDismiss'), array('job' => $r['id']));
	}
	print '</td></tr>';
}
if (!$log['rows']) {
	print '<tr class="oddeven"><td colspan="8" class="opacitymedium">'.$langs->trans('SchoolNoJob').'</td></tr>';
}
print '</table>';

$pages = (int) ceil($log['total'] / $perPage);
if ($pages > 1) {
	print '<div class="center">';
	if ($page > 0) {
		print '<a href="'.dol_escape_htmltag($self.$filters.'&page='.($page - 1)).'">&laquo;</a> ';
	}
	print $langs->trans('SchoolPageOf', $page + 1, $pages);
	if ($page + 1 < $pages) {
		print ' <a href="'.dol_escape_htmltag($self.$filters.'&page='.($page + 1)).'">&raquo;</a>';
	}
	print '</div>';
}

llxFooter();
$db->close();
