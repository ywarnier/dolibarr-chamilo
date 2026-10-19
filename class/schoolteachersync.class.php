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
 * \file    class/schoolteachersync.class.php
 * \ingroup school
 * \brief   Teachers: Dolibarr employees with a Chamilo account, and their assignment to courses and sessions.
 */

require_once __DIR__.'/chamilosetup.class.php';
require_once __DIR__.'/syncqueue.class.php';

/**
 * A teacher is a Dolibarr user flagged as an employee, linked to a Chamilo account. The account is created or linked
 * from Dolibarr, and kept up to date. The module only ever gives the account the teacher role (a status of 1 when it is
 * created): it never changes the role of an existing account, so a compromised synchronization cannot make anyone an
 * administrator.
 */
class SchoolTeacherSync
{
	/** Assignment states of llx_school_course_teacher and llx_school_session_teacher. */
	public const TO_ADD = 0;
	public const IN_CHAMILO = 1;
	public const TO_REMOVE = 3;

	/** @var DoliDB */
	private $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Make a Dolibarr employee a teacher and queue the creation or linking of the Chamilo account.
	 *
	 * @param int $userId Dolibarr user id
	 * @return array{id:int,error:string} School teacher id
	 */
	public function enable(int $userId): array
	{
		global $conf, $user;
		$entity = (int) $conf->entity;

		$resql = $this->db->query("SELECT rowid, email, employee FROM ".MAIN_DB_PREFIX."user WHERE rowid = ".$userId);
		$u = $resql ? $this->db->fetch_object($resql) : null;
		if (!$u) {
			return array('id' => 0, 'error' => 'User not found');
		}
		if ((int) $u->employee !== 1) {
			return array('id' => 0, 'error' => 'NotAnEmployee');
		}
		if (trim((string) $u->email) === '') {
			return array('id' => 0, 'error' => 'NoEmail');
		}

		$existing = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."school_teacher WHERE entity = ".$entity." AND fk_user = ".$userId);
		$row = $existing ? $this->db->fetch_object($existing) : null;
		if ($row) {
			$id = (int) $row->rowid;
		} else {
			$now = $this->db->idate(dol_now());
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."school_teacher (entity, fk_user, sync_status, datec, fk_user_creat) VALUES ("
				.$entity.", ".$userId.", 0, '".$now."', ".((int) ($user->id ?? 0)).")";
			if (!$this->db->query($sql)) {
				return array('id' => 0, 'error' => $this->db->lasterror());
			}
			$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'school_teacher');
		}
		(new SyncQueue($this->db))->enqueue('teacher', $id, 'create');
		return array('id' => $id, 'error' => '');
	}

	/**
	 * Queue an update of the Chamilo account of the teacher that is this Dolibarr user, if there is one.
	 *
	 * @param int $userId Dolibarr user id
	 * @return bool True when an update was queued
	 */
	public function queueUpdateForUser(int $userId): bool
	{
		global $conf;
		$resql = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."school_teacher WHERE entity = ".((int) $conf->entity)." AND fk_user = ".$userId);
		$row = $resql ? $this->db->fetch_object($resql) : null;
		if (!$row) {
			return false;
		}
		return (new SyncQueue($this->db))->enqueue('teacher', (int) $row->rowid, 'update') > 0;
	}

	/**
	 * Create, link or update the Chamilo account of a teacher. Safe to repeat.
	 *
	 * @param ChamiloClient $client    Client
	 * @param int           $teacherId School teacher id
	 * @param bool|null     $sendEmail Whether Chamilo sends the new account its "choose your password" message. Null follows the SCHOOL_TEACHER_SEND_EMAIL setting (on by default).
	 * @return array{ok:bool,error:string,retryable:bool,hash:string}
	 */
	public function push(ChamiloClient $client, int $teacherId, ?bool $sendEmail = null): array
	{
		$fail = function (string $error, bool $retryable) use ($teacherId): array {
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."school_teacher SET sync_status = 2, last_error = '".$this->db->escape(dol_substr($error, 0, 2000))."' WHERE rowid = ".$teacherId);
			return array('ok' => false, 'error' => $error, 'retryable' => $retryable, 'hash' => '');
		};

		$sql = "SELECT t.rowid, t.fk_user, t.chamilo_user_id, u.login, u.firstname, u.lastname, u.email, u.office_phone, u.statut"
			." FROM ".MAIN_DB_PREFIX."school_teacher AS t INNER JOIN ".MAIN_DB_PREFIX."user AS u ON u.rowid = t.fk_user WHERE t.rowid = ".$teacherId;
		$resql = $this->db->query($sql);
		$t = $resql ? $this->db->fetch_object($resql) : null;
		if (!$t) {
			return array('ok' => false, 'error' => 'Teacher '.$teacherId.' not found', 'retryable' => false, 'hash' => '');
		}

		$email = trim((string) $t->email);
		if ($email === '') {
			return $fail('The Dolibarr user has no email address', false);
		}
		$fieldId = getDolGlobalInt('SCHOOL_EF_USER_DOLIBARR_ID');
		if ($fieldId <= 0) {
			return $fail('The Chamilo extra fields are not set up: use "Create the extra fields" on the setup page', false);
		}

		$payload = schoolTeacherPayload(array('firstname' => (string) $t->firstname, 'lastname' => (string) $t->lastname, 'email' => $email, 'phone' => (string) $t->office_phone, 'statut' => (int) $t->statut));
		$chamiloId = (int) $t->chamilo_user_id;
		$note = '';

		if ($chamiloId <= 0) {
			// An account with this email may exist already (the person was a student, or was created by hand): link it
			// instead of creating a duplicate, and leave its data and role alone.
			$found = $this->findByEmail($client, $email);
			if (!$found['ok']) {
				return $fail($found['error'], $found['retryable']);
			}
			if ($found['user'] !== null) {
				$chamiloId = (int) $found['user']['id'];
				$this->saveChamiloIds($teacherId, $chamiloId, (string) ($found['user']['username'] ?? ''));
				$roles = $found['user']['roles'] ?? null;
				if (is_array($roles) && !in_array('ROLE_TEACHER', $roles, true)) {
					$note = 'NOTE: linked to an existing Chamilo account that is not a teacher; its role was not changed';
				}
			} else {
				$created = $this->createAccount($client, $t, $email, $sendEmail);
				if (!$created['ok']) {
					return $fail($created['error'], $created['retryable']);
				}
				$chamiloId = $created['id'];
				$this->saveChamiloIds($teacherId, $chamiloId, $created['username']);
			}
		} else {
			$res = $client->request('PATCH', '/api/users/'.$chamiloId, array(), $payload);
			if (!$res['ok']) {
				$why = $res['status'] === 404 ? 'The Chamilo account no longer exists' : 'Update account: '.$res['error'];
				return $fail($why, $res['retryable']);
			}
		}

		$set = (new ChamiloSetup($client))->setValue($fieldId, $chamiloId, (string) ((int) $t->fk_user));
		if (!$set['ok']) {
			return $fail('Extra field dolibarr_id: '.$set['error'], true);
		}

		$this->db->query("UPDATE ".MAIN_DB_PREFIX."school_teacher SET sync_status = 1, last_sync = '".$this->db->idate(dol_now())."', last_error = ".($note === '' ? 'NULL' : "'".$this->db->escape($note)."'")." WHERE rowid = ".$teacherId);
		return array('ok' => true, 'error' => '', 'retryable' => false, 'hash' => schoolPayloadHash($payload));
	}

	/**
	 * Replace what a course (or a course of a session) has as teachers by what Dolibarr says. Rows to add are
	 * subscribed, rows to remove are unsubscribed and deleted. Chamilo is read first because subscribing someone who
	 * is already there is refused rather than ignored.
	 *
	 * @param ChamiloClient $client  Client
	 * @param string        $kind    'course' or 'session'
	 * @param int           $localId School course id, or School session id
	 * @return array{ok:bool,error:string,retryable:bool,hash:string}
	 */
	public function pushAssignments(ChamiloClient $client, string $kind, int $localId): array
	{
		$P = MAIN_DB_PREFIX;
		if ($kind === 'course') {
			$course = $this->one("SELECT chamilo_id FROM ".$P."school_course WHERE rowid = ".$localId);
			if (!$course) {
				return $this->result(false, 'Course '.$localId.' not found', false);
			}
			if ((int) $course->chamilo_id <= 0) {
				return $this->result(false, 'The course is not in Chamilo yet', true);
			}
			$rows = $this->assignmentRows("SELECT ct.rowid, ct.sync_status, t.chamilo_user_id, ".(int) $course->chamilo_id." AS cid FROM ".$P."school_course_teacher AS ct INNER JOIN ".$P."school_teacher AS t ON t.rowid = ct.fk_teacher WHERE ct.fk_course = ".$localId);
			$sid = 0;
			$table = 'school_course_teacher';
		} else {
			$session = $this->one("SELECT chamilo_id FROM ".$P."school_session WHERE rowid = ".$localId);
			if (!$session) {
				return $this->result(false, 'Session '.$localId.' not found', false);
			}
			if ((int) $session->chamilo_id <= 0) {
				return $this->result(false, 'The session is not in Chamilo yet', true);
			}
			$sid = (int) $session->chamilo_id;
			$table = 'school_session_teacher';
			$rows = $this->assignmentRows("SELECT st.rowid, st.sync_status, t.chamilo_user_id, c.chamilo_id AS cid FROM ".$P."school_session_teacher AS st INNER JOIN ".$P."school_teacher AS t ON t.rowid = st.fk_teacher INNER JOIN ".$P."school_course AS c ON c.rowid = st.fk_course WHERE st.fk_session = ".$localId);
		}

		$byCourse = array();
		foreach ($rows as $r) {
			if ($r['cid'] <= 0) {
				return $this->result(false, 'A course of the assignment is not in Chamilo yet', true);
			}
			if ($r['chamilo_user_id'] <= 0 && $r['sync_status'] === self::TO_ADD) {
				return $this->result(false, 'A teacher has no Chamilo account yet', true);
			}
			$byCourse[$r['cid']][] = $r;
		}

		foreach ($byCourse as $cid => $list) {
			$have = $this->teachersInChamilo($client, (int) $cid, $sid);
			if ($have === null) {
				return $this->result(false, 'Could not read the teachers of the course in Chamilo', true);
			}
			$wantIds = array();
			$removeIds = array();
			foreach ($list as $r) {
				if ($r['sync_status'] === self::TO_REMOVE) {
					$removeIds[] = $r['chamilo_user_id'];
				} else {
					$wantIds[] = $r['chamilo_user_id'];
				}
			}
			$toAdd = array_values(array_diff($wantIds, $have));
			$toRemove = array_values(array_intersect($removeIds, $have));

			$query = array('cid' => $cid, 'type' => 1);
			if ($sid > 0) {
				$query['sid'] = $sid;
			}
			if ($toAdd) {
				$res = $client->request('POST', '/api/course-users/actions/subscribe', $query, array('userIds' => $toAdd));
				if (!$res['ok']) {
					return $this->result(false, 'Add teachers: '.$res['error'], $res['retryable']);
				}
			}
			if ($toRemove) {
				$res = $client->request('POST', '/api/course-users/actions/unsubscribe', $query, array('userIds' => $toRemove));
				if (!$res['ok']) {
					return $this->result(false, 'Remove teachers: '.$res['error'], $res['retryable']);
				}
			}
			foreach ($list as $r) {
				if ($r['sync_status'] === self::TO_REMOVE) {
					$this->db->query("DELETE FROM ".$P.$table." WHERE rowid = ".$r['rowid']);
				} else {
					$this->db->query("UPDATE ".$P.$table." SET sync_status = ".self::IN_CHAMILO." WHERE rowid = ".$r['rowid']);
				}
			}
		}

		return $this->result(true, '', false, schoolPayloadHash(array('kind' => $kind, 'id' => $localId, 'rows' => count($rows))));
	}

	/**
	 * @param ChamiloClient $client Client
	 * @param string        $email  Email
	 * @return array{ok:bool,user:?array<string,mixed>,error:string,retryable:bool}
	 */
	private function findByEmail(ChamiloClient $client, string $email): array
	{
		$res = $client->request('GET', '/api/users', array('email' => $email, 'itemsPerPage' => 5));
		if (!$res['ok']) {
			return array('ok' => false, 'user' => null, 'error' => 'Look up the account: '.$res['error'], 'retryable' => $res['retryable']);
		}
		foreach (ChamiloClient::members($res['data']) as $u) {
			if (is_array($u) && isset($u['id']) && strcasecmp((string) ($u['email'] ?? $email), $email) === 0) {
				return array('ok' => true, 'user' => $u, 'error' => '', 'retryable' => false);
			}
		}
		return array('ok' => true, 'user' => null, 'error' => '', 'retryable' => false);
	}

	/**
	 * Create the account, trying the Dolibarr login first and then numbered variants when a name is taken.
	 *
	 * @param ChamiloClient $client    Client
	 * @param object        $t         Teacher row joined with the Dolibarr user
	 * @param string        $email     Email
	 * @param bool|null     $sendEmail See push()
	 * @return array{ok:bool,id:int,username:string,error:string,retryable:bool}
	 */
	private function createAccount(ChamiloClient $client, $t, string $email, ?bool $sendEmail): array
	{
		$names = schoolTeacherUsernameCandidates((string) $t->login, (string) $t->firstname, (string) $t->lastname);
		if (!$names) {
			return array('ok' => false, 'id' => 0, 'username' => '', 'error' => 'No Chamilo username can be built from this user', 'retryable' => false);
		}
		$urlId = getDolGlobalInt('SCHOOL_CHAMILO_ACCESS_URL_ID') ?: $client->accessUrlId();
		if (!$urlId) {
			return array('ok' => false, 'id' => 0, 'username' => '', 'error' => 'The Chamilo portal could not be identified: set SCHOOL_CHAMILO_ACCESS_URL_ID', 'retryable' => false);
		}
		$send = $sendEmail ?? (getDolGlobalString('SCHOOL_TEACHER_SEND_EMAIL', '1') !== '0');

		foreach ($names as $username) {
			$res = $client->request('POST', '/api/access_urls/'.$urlId.'/user', array(), array(
				'username' => $username,
				'email' => $email,
				'firstname' => trim((string) $t->firstname),
				'lastname' => trim((string) $t->lastname),
				// Status 1 is the teacher status. It is the only role this module ever grants.
				'status' => 1,
				'sendEmail' => $send,
				'extraFields' => array('dolibarr_id' => (string) ((int) $t->fk_user)),
			));
			if ($res['ok'] && is_array($res['data'])) {
				return array('ok' => true, 'id' => (int) ($res['data']['id'] ?? 0), 'username' => (string) ($res['data']['username'] ?? $username), 'error' => '', 'retryable' => false);
			}
			$kind = $res['status'] === 409 ? schoolConflictKind($res['error']) : '';
			if ($kind === 'username') {
				continue;
			}
			if ($kind === 'email') {
				// Created by someone else between the look-up and now: link it on the next attempt.
				return array('ok' => false, 'id' => 0, 'username' => '', 'error' => 'An account with this email appeared meanwhile', 'retryable' => true);
			}
			return array('ok' => false, 'id' => 0, 'username' => '', 'error' => 'Create account: '.$res['error'], 'retryable' => $res['retryable']);
		}
		return array('ok' => false, 'id' => 0, 'username' => '', 'error' => 'All the usernames tried are taken: '.implode(', ', $names), 'retryable' => false);
	}

	/**
	 * Ids of the teachers a course has in Chamilo, or of the coaches of a course inside a session.
	 *
	 * @param ChamiloClient $client Client
	 * @param int           $cid    Chamilo course id
	 * @param int           $sid    Chamilo session id, 0 for the course itself
	 * @return list<int>|null null when Chamilo could not be read
	 */
	private function teachersInChamilo(ChamiloClient $client, int $cid, int $sid): ?array
	{
		if ($sid > 0) {
			$res = $client->request('GET', '/api/session_rel_course_rel_users', array('session' => '/api/sessions/'.$sid, 'course' => '/api/courses/'.$cid, 'itemsPerPage' => 200));
		} else {
			$res = $client->request('GET', '/api/course_rel_users', array('course' => '/api/courses/'.$cid, 'itemsPerPage' => 200));
		}
		if (!$res['ok']) {
			return null;
		}
		$ids = array();
		foreach (ChamiloClient::members($res['data']) as $row) {
			if (!is_array($row)) {
				continue;
			}
			// In a course, only the teacher status counts; a student of the course is not a teacher.
			if ($sid <= 0 && (int) ($row['status'] ?? 0) !== 1) {
				continue;
			}
			$u = $row['user'] ?? null;
			$id = is_array($u) ? (int) ($u['id'] ?? schoolIriId((string) ($u['@id'] ?? '')) ?? 0) : (is_int($u) ? $u : (int) (schoolIriId((string) $u) ?? 0));
			if ($id > 0) {
				$ids[$id] = $id;
			}
		}
		return array_values($ids);
	}

	/**
	 * @param string $sql Query returning rowid, sync_status, chamilo_user_id and cid
	 * @return list<array{rowid:int,sync_status:int,chamilo_user_id:int,cid:int}>
	 */
	private function assignmentRows(string $sql): array
	{
		$out = array();
		$resql = $this->db->query($sql);
		while ($resql && ($o = $this->db->fetch_object($resql))) {
			$out[] = array('rowid' => (int) $o->rowid, 'sync_status' => (int) $o->sync_status, 'chamilo_user_id' => (int) $o->chamilo_user_id, 'cid' => (int) $o->cid);
		}
		return $out;
	}

	/**
	 * @param int    $teacherId School teacher id
	 * @param int    $chamiloId Chamilo user id
	 * @param string $username  Chamilo username
	 */
	private function saveChamiloIds(int $teacherId, int $chamiloId, string $username): void
	{
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."school_teacher SET chamilo_user_id = ".$chamiloId.", chamilo_username = ".($username === '' ? 'NULL' : "'".$this->db->escape($username)."'")." WHERE rowid = ".$teacherId);
	}

	/**
	 * @param string $sql Query
	 * @return object|null First row
	 */
	private function one(string $sql)
	{
		$resql = $this->db->query($sql);
		return $resql ? ($this->db->fetch_object($resql) ?: null) : null;
	}

	/**
	 * @param bool   $ok        Success
	 * @param string $error     Message
	 * @param bool   $retryable Whether another attempt can succeed
	 * @param string $hash      Hash of what was sent
	 * @return array{ok:bool,error:string,retryable:bool,hash:string}
	 */
	private function result(bool $ok, string $error, bool $retryable, string $hash = ''): array
	{
		return array('ok' => $ok, 'error' => $error, 'retryable' => $retryable, 'hash' => $hash);
	}
}
