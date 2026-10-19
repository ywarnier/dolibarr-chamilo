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
 * \file    parent_children.php
 * \ingroup school
 * \brief   "Children" tab of a third party: the students it is a parent of.
 */

require_once __DIR__.'/lib/school_bootstrap.inc.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

schoolStartPage();
$socid = GETPOSTINT('socid');
if ($socid <= 0) {
	accessforbidden();
}
// A third party the user may not see (multicompany, sales representatives) is refused like on its own card.
$result = restrictedArea($user, 'societe', $socid, '&societe');

$object = new Societe($db);
if ($object->fetch($socid) <= 0) {
	accessforbidden();
}

llxHeader('', $object->name.' - '.$langs->trans('SchoolChildren'));
$head = societe_prepare_head($object);
print dol_get_fiche_head($head, 'schoolchildren', $langs->trans('ThirdParty'), -1, 'company');
print '<div class="opacitymedium">'.$langs->trans('SchoolChildrenHelp').'</div><br>';

$sql = "SELECT s.rowid, s.ref, s.firstname, s.lastname, s.birthdate, s.status, sp.relation, sp.is_payer, sp.payer_share, sp.chamilo_follow"
	." FROM ".MAIN_DB_PREFIX."school_student_parent AS sp INNER JOIN ".MAIN_DB_PREFIX."school_student AS s ON s.rowid = sp.fk_student"
	." WHERE sp.fk_soc = ".$socid." AND s.entity = ".((int) $conf->entity)." AND sp.chamilo_follow <> 2 ORDER BY s.lastname, s.firstname";
$resql = $db->query($sql);
print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('Name').'</td><td>'.$langs->trans('SchoolBirthdate').'</td><td>'.$langs->trans('SchoolRelation').'</td><td>'.$langs->trans('SchoolPayer').'</td><td>'.$langs->trans('SchoolFollowsInChamilo').'</td></tr>';
$nb = 0;
while ($resql && ($o = $db->fetch_object($resql))) {
	$nb++;
	print '<tr class="oddeven"><td><a href="'.dol_buildpath('/school/student_card.php', 1).'?id='.((int) $o->rowid).'&mainmenu=school&leftmenu=schoolstudents">'.dol_escape_htmltag($o->ref).'</a></td>';
	print '<td>'.dol_escape_htmltag($o->firstname.' '.$o->lastname).((int) $o->status === 1 ? '' : ' <span class="opacitymedium">('.$langs->trans('SchoolInactive').')</span>').'</td>';
	print '<td>'.($o->birthdate ? dol_print_date($db->jdate($o->birthdate), 'day') : '').'</td><td>'.dol_escape_htmltag((string) $o->relation).'</td>';
	print '<td>'.($o->is_payer ? $langs->trans('Yes').' ('.price2num($o->payer_share).' %)' : $langs->trans('No')).'</td><td>'.((int) $o->chamilo_follow === 1 ? $langs->trans('Yes') : $langs->trans('No')).'</td></tr>';
}
if ($nb === 0) {
	print '<tr class="oddeven"><td colspan="6" class="opacitymedium">'.$langs->trans('SchoolNoChildYet').'</td></tr>';
}
print '</table>';
print dol_get_fiche_end();

llxFooter();
$db->close();
