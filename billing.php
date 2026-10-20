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
 * \file    billing.php
 * \ingroup school
 * \brief   Invoice enrolments, or the participants of a class activity, to the paying parents.
 *          Three steps: choose, review (and set the shares of the parents), create the draft invoices.
 */

require_once __DIR__.'/lib/school_bootstrap.inc.php';
require_once __DIR__.'/class/schoolbilling.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

schoolStartPage();
schoolRequireRight('invoice');
if (!isModEnabled('invoice') || !$user->hasRight('facture', 'creer')) {
	accessforbidden($langs->trans('SchoolNeedInvoiceRight'));
}

$billing = new SchoolBilling($db);
$activityId = GETPOSTINT('activity');
$action = GETPOST('action', 'aZ09');
$self = dol_buildpath('/school/billing.php', 1);
$menu = 'mainmenu=school&leftmenu=schoolbilling';
$here = $self.'?'.($activityId > 0 ? 'activity='.$activityId.'&' : '').$menu;
$money = static function (float $amount) use ($langs, $conf): string {
	return price($amount, 0, $langs, 1, -1, -1, $conf->currency);
};
$translateError = static function (array $e) use ($langs): string {
	$text = $langs->trans($e['key']);
	return trim(($e['student'] ?? '').($e['label'] ?? '' ? ' ('.$e['label'].')' : '').': '.$text, ': ');
};

// What can be invoiced here: the enrolments, or the participants of one activity.
$activity = null;
$items = array();
if ($activityId > 0) {
	$data = $billing->activityParticipants($activityId);
	$activity = $data['activity'];
	if (!$activity) {
		accessforbidden();
	}
	$everyone = array_column($data['participants'], 'student');
	$items = $billing->activityItems($activityId, $everyone);
} else {
	$items = $billing->billableEnrolments();
}
$byKey = array();
foreach ($items as $i) {
	$byKey[$i['key']] = $i;
}

// The items chosen by the user: rebuilt from the database, never from what the form says.
$selected = array();
foreach ((array) GETPOST('sel', 'array') as $key) {
	if (isset($byKey[(string) $key])) {
		$selected[(string) $key] = $byKey[(string) $key];
	}
}

// The shares typed by the user, by student then third party.
$overrides = array();
$posted = GETPOST('share', 'array');
foreach ((array) $posted as $studentId => $row) {
	foreach ((array) $row as $socId => $value) {
		$overrides[(int) $studentId][(int) $socId] = (float) price2num((string) $value);
	}
}

// ---- Step 3: create the invoices
$result = null;
if ($action === 'invoice' && $selected) {
	$done = $billing->invoice(array_values($selected), $overrides, $user);
	if ($done['planErrors']) {
		setEventMessages('', array_map($translateError, $done['planErrors']), 'errors');
		$action = 'review';
	} else {
		$result = array('created' => $done['created'], 'errors' => $done['errors']);
	}
}

llxHeader('', $langs->trans('SchoolBilling'));
print load_fiche_titre($langs->trans($activity ? 'SchoolInvoiceActivity' : 'SchoolBilling').($activity ? ' : '.dol_escape_htmltag($activity->label) : ''), $activity ? '<a href="'.dol_buildpath('/school/class_card.php', 1).'?id='.((int) $activity->fk_class).'&mainmenu=school&leftmenu=schoolclasses">'.dol_escape_htmltag($activity->class_label).'</a>' : '', 'fa-file-invoice');

// ---- Result
if ($result !== null) {
	if ($result['created']) {
		print '<div class="ok">'.$langs->trans('SchoolInvoicesCreated', count($result['created'])).'</div><br><table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('ThirdParty').'</td><td>'.$langs->trans('Invoice').'</td><td class="right">'.$langs->trans('AmountHT').'</td></tr>';
		foreach ($result['created'] as $socId => $factureId) {
			$f = new Facture($db);
			$f->fetch($factureId);
			$so = new Societe($db);
			$so->fetch((int) $socId);
			print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $socId).'">'.dol_escape_htmltag($so->name).'</a></td><td><a href="'.DOL_URL_ROOT.'/compta/facture/card.php?id='.((int) $factureId).'">'.img_picto('', 'bill', 'class="pictofixedwidth"').dol_escape_htmltag($f->ref).'</a> <span class="opacitymedium">('.$langs->trans('BillShortStatusDraft').')</span></td><td class="right">'.$money((float) $f->total_ht).'</td></tr>';
		}
		print '</table><div class="opacitymedium">'.$langs->trans('SchoolInvoicesDraftHelp').'</div>';
	} else {
		print '<div class="warning">'.$langs->trans('SchoolNothingInvoiced').'</div>';
	}
	if ($result['errors']) {
		print '<br><div class="error">'.$langs->trans('SchoolInvoicesFailed').'<br>'.implode('<br>', array_map('dol_escape_htmltag', $result['errors'])).'</div>';
	}
	print '<br><a class="butAction" href="'.dol_escape_htmltag($here).'">'.$langs->trans('Continue').'</a>';
	llxFooter();
	$db->close();
	exit;
}

// ---- Step 2: review
if ($action === 'review' && $selected) {
	$plan = $billing->plan(array_values($selected), $overrides);
	print '<div class="opacitymedium">'.$langs->trans('SchoolReviewHelp').'</div><br>';
	if ($plan['errors']) {
		print '<div class="error">'.implode('<br>', array_map('dol_escape_htmltag', array_map($translateError, $plan['errors']))).'</div><br>';
	}

	print '<form method="POST" action="'.dol_escape_htmltag($self).'"><input type="hidden" name="token" value="'.newToken().'">';
	if ($activityId > 0) {
		print '<input type="hidden" name="activity" value="'.$activityId.'">';
	}
	foreach (array_keys($selected) as $key) {
		print '<input type="hidden" name="sel[]" value="'.dol_escape_htmltag($key).'">';
	}

	// Shares: only for the children with more than one paying parent.
	$multi = array();
	foreach ($selected as $it) {
		$payers = $billing->payers((int) $it['student']);
		if (count($payers) > 1) {
			$multi[(int) $it['student']] = array('name' => $it['student_name'], 'payers' => $payers);
		}
	}
	if ($multi) {
		print load_fiche_titre($langs->trans('SchoolSharesToSet'), '', '');
		print '<div class="opacitymedium">'.$langs->trans('SchoolSharesHelp').'</div><table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('SchoolStudent').'</td><td>'.$langs->trans('SchoolPayerShare').'</td></tr>';
		foreach ($multi as $studentId => $m) {
			$proposed = $overrides[$studentId] ?? $billing->proposedShares($studentId);
			print '<tr class="oddeven"><td>'.dol_escape_htmltag($m['name']).'</td><td>';
			foreach ($m['payers'] as $socId => $p) {
				print dol_escape_htmltag($p['name']).' <input type="text" class="width50 right" name="share['.$studentId.']['.$socId.']" value="'.dol_escape_htmltag((string) price2num($proposed[$socId] ?? 0)).'"> % &nbsp; ';
			}
			print '</td></tr>';
		}
		print '</table><div class="opacitymedium">'.$langs->trans('SchoolSharesRemembered').'</div><br>';
	}

	print load_fiche_titre($langs->trans('SchoolInvoicesToCreate'), '', '');
	print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('ThirdParty').'</td><td>'.$langs->trans('Description').'</td><td class="right">'.$langs->trans('AmountHT').'</td></tr>';
	$grand = 0.0;
	foreach ($plan['invoices'] as $inv) {
		foreach ($inv['lines'] as $n => $line) {
			print '<tr class="oddeven"><td>'.($n === 0 ? '<b>'.dol_escape_htmltag($inv['name']).'</b>' : '').'</td><td>'.dol_escape_htmltag($line['desc']).'</td><td class="right">'.$money((float) $line['amount']).'</td></tr>';
		}
		print '<tr class="liste_total"><td></td><td class="right">'.$langs->trans('Total').'</td><td class="right">'.$money((float) $inv['total']).'</td></tr>';
		$grand += $inv['total'];
	}
	if (!$plan['invoices']) {
		print '<tr class="oddeven"><td colspan="3" class="opacitymedium">'.$langs->trans('SchoolNothingToInvoice').'</td></tr>';
	}
	print '</table><br><div class="center">';
	if ($multi) {
		print '<button type="submit" class="button" name="action" value="review">'.$langs->trans('SchoolRecalculate').'</button> ';
	}
	if ($plan['invoices'] && !$plan['errors']) {
		print '<button type="submit" class="button button-save" name="action" value="invoice">'.$langs->trans('SchoolCreateDraftInvoices', count($plan['invoices'])).'</button> ';
	}
	print '<a class="button button-cancel" href="'.dol_escape_htmltag($here).'">'.$langs->trans('Cancel').'</a></div></form>';
	llxFooter();
	$db->close();
	exit;
}

// ---- Step 1: choose
print '<div class="opacitymedium">'.$langs->trans($activity ? 'SchoolChooseParticipantsHelp' : 'SchoolChooseEnrolmentsHelp').'</div><br>';
if ($activity) {
	print '<div>'.$langs->trans('SchoolActivityPrice').' : <b>'.$money((float) ($items[0]['amount'] ?? $activity->unit_price)).'</b> &mdash; '.dol_print_date($db->jdate($activity->date_activity), 'day').'</div><br>';
}

// Participants or enrolments, with whether each can be invoiced.
$rows = array();
if ($activity) {
	foreach ($billing->activityParticipants($activityId)['participants'] as $p) {
		$rows[] = array('key' => 'a'.$activityId.'s'.$p['student'], 'student' => $p['student'], 'name' => $p['name'], 'what' => (string) $activity->label, 'amount' => $byKey['a'.$activityId.'s'.$p['student']]['amount'] ?? null, 'invoiced' => $p['invoiced']);
	}
} else {
	foreach ($items as $it) {
		$rows[] = array('key' => $it['key'], 'student' => $it['student'], 'name' => $it['student_name'], 'what' => $it['label'], 'amount' => $it['amount'], 'invoiced' => false);
	}
}

print '<form method="POST" action="'.dol_escape_htmltag($self).'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="review">';
if ($activityId > 0) {
	print '<input type="hidden" name="activity" value="'.$activityId.'">';
}
print '<table class="noborder centpercent"><tr class="liste_titre"><td class="center"></td><td>'.$langs->trans('SchoolStudent').'</td><td>'.$langs->trans($activity ? 'SchoolActivity' : 'SchoolEnrolledIn').'</td><td class="right">'.$langs->trans('AmountHT').'</td><td>'.$langs->trans('SchoolPayers').'</td></tr>';
$nb = 0;
foreach ($rows as $r) {
	$payers = $billing->payers((int) $r['student']);
	$can = !$r['invoiced'] && $payers && $r['amount'] !== null && $r['amount'] > 0;
	$nb += $can ? 1 : 0;
	print '<tr class="oddeven'.($r['invoiced'] ? ' opacitymedium' : '').'"><td class="center"><input type="checkbox" name="sel[]" value="'.dol_escape_htmltag($r['key']).'"'.($can ? ' checked' : ' disabled').'></td>';
	print '<td><a href="'.dol_buildpath('/school/student_card.php', 1).'?id='.((int) $r['student']).'&mainmenu=school&leftmenu=schoolstudents">'.dol_escape_htmltag($r['name']).'</a></td><td>'.dol_escape_htmltag($r['what']).'</td>';
	print '<td class="right">'.($r['amount'] !== null ? $money((float) $r['amount']) : '').'</td><td>';
	if ($r['invoiced']) {
		print $langs->trans('SchoolAlreadyInvoiced');
	} elseif (!$payers) {
		print '<span class="warning">'.$langs->trans('SchoolNoPayer').'</span>';
	} else {
		$shares = $billing->proposedShares((int) $r['student']);
		$names = array();
		foreach ($payers as $socId => $p) {
			$names[] = dol_escape_htmltag($p['name']).(count($payers) > 1 ? ' ('.price2num($shares[$socId] ?? 0).' %)' : '');
		}
		print implode(', ', $names);
	}
	print '</td></tr>';
}
if (!$rows) {
	print '<tr class="oddeven"><td colspan="5" class="opacitymedium">'.$langs->trans($activity ? 'SchoolNoParticipant' : 'SchoolNothingToInvoice').'</td></tr>';
}
print '</table>';
if ($nb > 0) {
	print '<br><div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('SchoolReview')).'"></div>';
}
print '</form>';

llxFooter();
$db->close();
