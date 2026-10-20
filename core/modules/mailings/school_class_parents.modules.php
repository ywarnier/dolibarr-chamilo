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
 * \file    core/modules/mailings/school_class_parents.modules.php
 * \ingroup school
 * \brief   Emailing recipients: the parents of the students of a class.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/mailings/modules_mailings.php';
require_once __DIR__.'/../../../class/schoolclass.class.php';

/**
 * Recipient selector of Dolibarr's Emailing module. It adds to a campaign one address per parent of the current members
 * of a class: a parent of two children in the class gets one message, the address of the chosen contact is used before
 * that of the third party, and addresses that unsubscribed are marked by Emailing itself. Sending, history and
 * unsubscription are those of the Emailing module.
 */
class mailing_school_class_parents extends MailingTargets
{
	public $name = 'SchoolParentsOfClass';
	public $desc = 'SchoolParentsOfClassDesc';
	public $require_admin = 0;
	public $require_module = array('school');
	public $enabled = 'isModEnabled("school")';
	public $picto = 'fa-graduation-cap';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs;
		$langs->load('school@school');
		$this->db = $db;
	}

	/**
	 * Add the parents of the chosen class to the campaign.
	 *
	 * @param int $mailing_id Id of the campaign
	 * @return int Number of recipients added, <0 on error
	 */
	public function add_to_target($mailing_id)
	{
		global $conf, $langs;
		$classId = GETPOSTINT('filter_school_class');
		if ($classId <= 0) {
			return 0;
		}
		$c = $this->db->query("SELECT label FROM ".MAIN_DB_PREFIX."school_class WHERE rowid = ".$classId." AND entity = ".((int) $conf->entity));
		$class = $c ? $this->db->fetch_object($c) : null;
		if (!$class) {
			return 0;
		}

		$links = (new SchoolClass($this->db))->parentLinksOfMembers($classId);
		$cibles = array();
		foreach (schoolDistinctRecipients($links) as $r) {
			// Keep the details of the first link that gave this address.
			$detail = array('contact_id' => 0, 'firstname' => '', 'lastname' => $r['name']);
			foreach ($links as $l) {
				$email = trim((string) ($l['contact_email'] ?? '')) !== '' ? trim((string) $l['contact_email']) : trim((string) ($l['soc_email'] ?? ''));
				if (strcasecmp($email, $r['email']) === 0) {
					if (trim((string) ($l['contact_email'] ?? '')) !== '' && (int) $l['contact_id'] > 0) {
						$detail = array('contact_id' => (int) $l['contact_id'], 'firstname' => (string) $l['contact_firstname'], 'lastname' => (string) ($l['contact_lastname'] ?: $r['name']));
					}
					break;
				}
			}
			$cibles[] = array(
				'email' => $r['email'],
				'fk_contact' => $detail['contact_id'],
				'lastname' => $detail['lastname'],
				'firstname' => $detail['firstname'],
				'other' => $langs->transnoentities('SchoolClass').'='.$class->label,
				'source_url' => $this->url($r['id']),
				'source_id' => $r['id'],
				'source_type' => 'thirdparty',
			);
		}
		if (!$cibles) {
			return 0;
		}
		return parent::addTargetsToDatabase($mailing_id, $cibles);
	}

	/**
	 * @return array<string,string> No statistics for this selector
	 */
	public function getSqlArrayForStats()
	{
		return array();
	}

	/**
	 * Number of distinct addresses over the classes that are active, to show on the list of selectors.
	 *
	 * @param string $sql Unused
	 * @return int
	 */
	public function getNbOfRecipients($sql = '')
	{
		global $conf;
		$all = array();
		$resql = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."school_class WHERE entity = ".((int) $conf->entity)." AND status = 1");
		$classes = new SchoolClass($this->db);
		while ($resql && ($o = $this->db->fetch_object($resql))) {
			foreach (schoolDistinctRecipients($classes->parentLinksOfMembers((int) $o->rowid)) as $r) {
				$all[strtolower($r['email'])] = true;
			}
		}
		return count($all);
	}

	/**
	 * @return string HTML of the filter: the class
	 */
	public function formFilter()
	{
		global $conf, $langs;
		$s = '<select id="filter_school_class" name="filter_school_class" class="flat maxwidth250">';
		$resql = $this->db->query("SELECT rowid, ref, label FROM ".MAIN_DB_PREFIX."school_class WHERE entity = ".((int) $conf->entity)." AND status = 1 ORDER BY label");
		$n = 0;
		while ($resql && ($o = $this->db->fetch_object($resql))) {
			$n++;
			$s .= '<option value="'.((int) $o->rowid).'">'.dol_escape_htmltag($o->label.' ('.$o->ref.')').'</option>';
		}
		if ($n === 0) {
			$s .= '<option value="0">'.dol_escape_htmltag($langs->trans('SchoolNoClassYet')).'</option>';
		}
		return $s.'</select>';
	}

	/**
	 * @param int $id Third party id
	 * @return string HTML link to the third party
	 */
	public function url($id)
	{
		return '<a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $id).'">'.img_object('', 'company').'</a>';
	}
}
