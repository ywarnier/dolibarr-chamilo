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
 * \file    core/modules/modSchool.class.php
 * \ingroup school
 * \brief   Descriptor of the School external module.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * School management synchronized with Chamilo. The technical name is lowercase because Dolibarr derives paths from it.
 */
class modSchool extends DolibarrModules
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->numero = 104802;
		$this->rights_class = 'school';
		$this->family = 'crm';
		$this->module_position = '91';
		$this->name = 'school';
		$this->description = 'ModuleSchoolDesc';
		$this->descriptionlong = 'ModuleSchoolDesc';
		$this->editor_name = 'School';
		$this->editor_url = '';
		$this->version = '0.1.0';
		$this->const_name = 'MAIN_MODULE_'.dol_strtoupper($this->name);
		$this->picto = 'fa-graduation-cap';

		$this->module_parts = array(
			'triggers' => 1,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array(),
			'moduleforexternal' => 0,
		);

		$this->dirs = array();
		$this->config_page_url = array('setup.php@school');
		$this->hidden = 0;
		$this->depends = array('modSociete', 'modProduct', 'modService', 'modAgenda');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('school@school');
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(24, 0);
		$this->need_javascript_ajax = 0;
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();
		$this->const = array();
		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();

		$this->cronjobs = array(
			0 => array(
				'label' => 'SchoolSyncQueue:school@school',
				'jobtype' => 'method',
				'class' => '/school/class/schoolcron.class.php',
				'objectname' => 'SchoolCron',
				'method' => 'runSync',
				'parameters' => '',
				'comment' => 'SchoolCronSyncComment',
				'frequency' => 5,
				'unitfrequency' => 60,
				'status' => 1,
				'test' => 'isModEnabled("school")',
				'priority' => 50,
			),
		);

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = 10480201;
		$this->rights[$r][1] = 'SchoolRightRead';
		$this->rights[$r][4] = 'read';
		$r++;
		$this->rights[$r][0] = 10480202;
		$this->rights[$r][1] = 'SchoolRightWrite';
		$this->rights[$r][4] = 'write';
		$r++;
		$this->rights[$r][0] = 10480203;
		$this->rights[$r][1] = 'SchoolRightDelete';
		$this->rights[$r][4] = 'delete';
		$r++;
		$this->rights[$r][0] = 10480204;
		$this->rights[$r][1] = 'SchoolRightSync';
		$this->rights[$r][4] = 'sync';
		$r++;
		$this->rights[$r][0] = 10480205;
		$this->rights[$r][1] = 'SchoolRightSetup';
		$this->rights[$r][4] = 'setup';
		$r++;
		$this->rights[$r][0] = 10480206;
		$this->rights[$r][1] = 'SchoolRightTeacher';
		$this->rights[$r][4] = 'teacher';
		$r++;
		$this->rights[$r][0] = 10480207;
		$this->rights[$r][1] = 'SchoolRightClass';
		$this->rights[$r][4] = 'class';

		$canRead = '$user->hasRight("school", "read")';
		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => '',
			'type' => 'top',
			'titre' => 'School',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'school',
			'leftmenu' => '',
			'url' => '/school/index.php',
			'langs' => 'school@school',
			'position' => 1100 + $r,
			'enabled' => "isModEnabled('school')",
			'perms' => $canRead,
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=school',
			'type' => 'left',
			'titre' => 'SchoolHome',
			'mainmenu' => 'school',
			'leftmenu' => 'schoolhome',
			'url' => '/school/index.php',
			'langs' => 'school@school',
			'position' => 1100 + $r,
			'enabled' => "isModEnabled('school')",
			'perms' => $canRead,
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=school',
			'type' => 'left',
			'titre' => 'SchoolCourses',
			'mainmenu' => 'school',
			'leftmenu' => 'schoolcourses',
			'url' => '/school/course_list.php',
			'langs' => 'school@school',
			'position' => 1100 + $r,
			'enabled' => "isModEnabled('school')",
			'perms' => $canRead,
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=school',
			'type' => 'left',
			'titre' => 'SchoolTeachers',
			'mainmenu' => 'school',
			'leftmenu' => 'schoolteachers',
			'url' => '/school/teacher_list.php',
			'langs' => 'school@school',
			'position' => 1100 + $r,
			'enabled' => "isModEnabled('school')",
			'perms' => $canRead,
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=school',
			'type' => 'left',
			'titre' => 'SchoolSessions',
			'mainmenu' => 'school',
			'leftmenu' => 'schoolsessions',
			'url' => '/school/session_list.php',
			'langs' => 'school@school',
			'position' => 1100 + $r,
			'enabled' => "isModEnabled('school')",
			'perms' => $canRead,
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 * Enable the module. Tables stay on disable.
	 *
	 * @param string $options Options
	 * @return int 1 if OK, 0 or -1 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/school/sql/');
		if ($result < 0) {
			return -1;
		}
		$this->remove($options);
		return $this->_init(array(), $options);
	}

	/**
	 * Disable the module. Menus and rights go away. School data stays, and so does the cron job, which does nothing while the module is off.
	 *
	 * @param string $options Options
	 * @return int 1 if OK, 0 or -1 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
