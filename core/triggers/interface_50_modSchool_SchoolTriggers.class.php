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
 * \file    core/triggers/interface_50_modSchool_SchoolTriggers.class.php
 * \ingroup school
 * \brief   Sends changes of a teacher's Dolibarr user to Chamilo.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * When a Dolibarr user that is a teacher is modified, enabled or disabled, an update of its Chamilo account is queued.
 * Nothing is sent from here: the queue is drained by the scheduled job, so saving a user never waits for Chamilo.
 */
class InterfaceSchoolTriggers extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'school';
		$this->description = 'Sends the changes of a teacher to Chamilo';
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'fa-graduation-cap';
		$this->errors = array();
	}

	/**
	 * @param string       $action Event code
	 * @param CommonObject $object Object
	 * @param User         $user   User doing the action
	 * @param Translate    $langs  Translations
	 * @param Conf         $conf   Configuration
	 * @return int 0 when nothing was done, >0 when something was queued
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('school')) {
			return 0;
		}
		if (!in_array($action, array('USER_MODIFY', 'USER_ENABLEDISABLE'), true) || !($object instanceof User) || empty($object->id)) {
			return 0;
		}

		require_once __DIR__.'/../../class/schoolteachersync.class.php';
		return (new SchoolTeacherSync($this->db))->queueUpdateForUser((int) $object->id) ? 1 : 0;
	}
}
