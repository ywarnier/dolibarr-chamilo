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
 * \file    class/schoolstudentsync.class.php
 * \ingroup school
 * \brief   Students, the parents who follow them, and enrolments: what is sent to Chamilo.
 */

require_once __DIR__.'/schoolaccount.class.php';
require_once __DIR__.'/schoolstudent.class.php';
require_once __DIR__.'/schoolclass.class.php';

/**
 * A student gets a Chamilo account with the student status. A parent who is chosen to follow the child gets an account
 * with the human resources manager status, which Chamilo uses for "a user who follows other users", and a relation
 * that says so. Enrolments subscribe the student to a course or to a session. As for teachers, the role of an existing
 * account is never changed, and Chamilo is read before acting because it refuses to add what is already there.
 */
class SchoolStudentSync
{
	/** Relation type of "a human resources manager follows this user" in Chamilo's user_rel_user. */
	public const RELATION_FOLLOWS = 7;

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
	 * Create or update the Chamilo account of a student, then of the parents who follow the child.
	 *
	 * @param ChamiloClient $client    Client
	 * @param int           $studentId School student id
	 * @param bool|null     $sendEmail Whether Chamilo sends the new accounts their "choose your password" message. Null follows the SCHOOL_STUDENT_SEND_EMAIL and SCHOOL_PARENT_SEND_EMAIL settings (on by default).
	 * @return array{ok:bool,error:string,retryable:bool,hash:string}
	 */
	public function push(ChamiloClient $client, int $studentId, ?bool $sendEmail = null): array
	{
		$P = MAIN_DB_PREFIX;
		$fail = function (string $error, bool $retryable) use ($studentId, $P): array {
			$this->db->query("UPDATE ".$P."school_student SET sync_status = 2, last_error = '".$this->db->escape(dol_substr($error, 0, 2000))."' WHERE rowid = ".$studentId);
			return array('ok' => false, 'error' => $error, 'retryable' => $retryable, 'hash' => '');
		};

		$resql = $this->db->query("SELECT * FROM ".$P."school_student WHERE rowid = ".$studentId);
		$s = $resql ? $this->db->fetch_object($resql) : null;
		if (!$s) {
			return array('ok' => false, 'error' => 'Student '.$studentId.' not found', 'retryable' => false, 'hash' => '');
		}
		if (getDolGlobalInt('SCHOOL_EF_USER_DOLIBARR_ID') <= 0) {
			return $fail('The Chamilo extra fields are not set up: use "Create the extra fields" on the setup page', false);
		}

		$links = $this->parentLinks($studentId);
		$account = new SchoolAccount($client);
		$ref = schoolDolibarrRef('student', $studentId);
		$chamiloId = (int) $s->chamilo_user_id;
		$note = '';

		if ($chamiloId <= 0) {
			$own = trim((string) $s->email);
			$found = null;
			if ($own !== '') {
				$lookup = $account->findByEmail($own);
				if (!$lookup['ok']) {
					return $fail($lookup['error'], $lookup['retryable']);
				}
				$found = $lookup['user'];
			}
			if ($found !== null) {
				$chamiloId = (int) $found['id'];
				$this->saveStudentIds($studentId, $chamiloId, (string) ($found['username'] ?? ''));
				if (is_array($found['roles'] ?? null) && !in_array('ROLE_STUDENT', $found['roles'], true)) {
					$note = 'NOTE: linked to an existing Chamilo account that is not a student; its role was not changed';
				}
			} else {
				$send = $sendEmail ?? (getDolGlobalString('SCHOOL_STUDENT_SEND_EMAIL', '1') !== '0');
				$created = $this->createStudentAccount($account, $s, $own, $this->firstParentEmail($links), $send, $ref);
				if (!$created['ok']) {
					return $fail($created['error'], $created['retryable']);
				}
				$chamiloId = $created['id'];
				$this->saveStudentIds($studentId, $chamiloId, $created['username']);
			}
		} else {
			$payload = array('firstname' => trim((string) $s->firstname), 'lastname' => trim((string) $s->lastname), 'active' => (int) $s->status === 1 ? 1 : 0);
			if (trim((string) $s->email) !== '') {
				$payload['email'] = trim((string) $s->email);
			}
			$res = $account->update($chamiloId, $payload);
			if (!$res['ok']) {
				return $fail($res['status'] === 404 ? 'The Chamilo account no longer exists' : 'Update account: '.$res['error'], $res['retryable']);
			}
		}

		$set = $account->setDolibarrRef($chamiloId, $ref);
		if (!$set['ok']) {
			return $fail($set['error'], true);
		}

		$guardians = $this->syncGuardians($client, $account, $chamiloId, $links, $sendEmail);
		if ($guardians !== null) {
			return $fail($guardians['error'], $guardians['retryable']);
		}

		$this->db->query("UPDATE ".$P."school_student SET sync_status = 1, last_sync = '".$this->db->idate(dol_now())."', last_error = ".($note === '' ? 'NULL' : "'".$this->db->escape($note)."'")." WHERE rowid = ".$studentId);
		// The classes of the student can now include them, or must exclude them if the account was deactivated.
		(new SchoolClass($this->db))->queueUpdatesForStudent($studentId);
		return array('ok' => true, 'error' => '', 'retryable' => false, 'hash' => schoolPayloadHash(array('student' => $studentId, 'links' => count($links))));
	}

	/**
	 * Subscribe a student to a course or a session, or remove them. Chamilo is read first.
	 *
	 * @param ChamiloClient $client      Client
	 * @param int           $enrolmentId School enrolment id
	 * @return array{ok:bool,error:string,retryable:bool,hash:string}
	 */
	public function pushEnrolment(ChamiloClient $client, int $enrolmentId): array
	{
		$P = MAIN_DB_PREFIX;
		$fail = function (string $error, bool $retryable) use ($enrolmentId, $P): array {
			$this->db->query("UPDATE ".$P."school_enrolment SET sync_status = 2, last_error = '".$this->db->escape(dol_substr($error, 0, 2000))."' WHERE rowid = ".$enrolmentId);
			return array('ok' => false, 'error' => $error, 'retryable' => $retryable, 'hash' => '');
		};

		$sql = "SELECT e.rowid, e.status, e.fk_session, e.fk_course, st.chamilo_user_id, s.chamilo_id AS sid, c.chamilo_id AS cid"
			." FROM ".$P."school_enrolment AS e INNER JOIN ".$P."school_student AS st ON st.rowid = e.fk_student"
			." LEFT JOIN ".$P."school_session AS s ON s.rowid = e.fk_session LEFT JOIN ".$P."school_course AS c ON c.rowid = e.fk_course WHERE e.rowid = ".$enrolmentId;
		$resql = $this->db->query($sql);
		$e = $resql ? $this->db->fetch_object($resql) : null;
		if (!$e) {
			return array('ok' => false, 'error' => 'Enrolment '.$enrolmentId.' not found', 'retryable' => false, 'hash' => '');
		}
		$toRemove = (int) $e->status === SchoolStudent::TO_REMOVE;
		$userId = (int) $e->chamilo_user_id;
		if ($userId <= 0) {
			if ($toRemove) {
				// The student never had an account, so there is nothing to remove in Chamilo.
				$this->db->query("DELETE FROM ".$P."school_enrolment WHERE rowid = ".$enrolmentId);
				return array('ok' => true, 'error' => '', 'retryable' => false, 'hash' => '');
			}
			return $fail('The student has no Chamilo account yet', true);
		}

		if ((int) $e->fk_session > 0) {
			$sid = (int) $e->sid;
			if ($sid <= 0) {
				return $fail('The session is not in Chamilo yet', true);
			}
			$list = $client->request('GET', '/api/sessions/'.$sid.'/students');
			if (!$list['ok']) {
				if ($list['status'] === 404) {
					// Chamilo answers "No route found" when it lacks the route, and "Not found" when the route exists but
					// the session is not on this portal (a session only managed through another access URL).
					$noRoute = stripos($list['error'], 'No route found') !== false;
					return $fail($noRoute
						? 'This Chamilo has no /api/sessions/{id}/students route: update Chamilo'
						: 'The session was not found on this Chamilo portal: it may not be linked to the portal this module talks to', false);
				}
				return $fail('Read the session students: '.$list['error'], $list['retryable']);
			}
			$have = array_map('intval', (array) ($list['data']['userIds'] ?? array()));
			if (!$toRemove && !in_array($userId, $have, true)) {
				$res = $client->request('POST', '/api/sessions/'.$sid.'/students/subscribe', array(), array('userIds' => array($userId)));
				if (!$res['ok']) {
					return $fail('Enrol in the session: '.$res['error'], $res['retryable']);
				}
				if (in_array($userId, array_map('intval', (array) ($res['data']['ignored'] ?? array())), true)) {
					return $fail('Chamilo cannot enrol this account in a session (it is deleted, anonymous or a human resources manager)', false);
				}
			} elseif ($toRemove && in_array($userId, $have, true)) {
				$res = $client->request('POST', '/api/sessions/'.$sid.'/students/unsubscribe', array(), array('userIds' => array($userId)));
				if (!$res['ok']) {
					return $fail('Remove from the session: '.$res['error'], $res['retryable']);
				}
			}
		} else {
			$cid = (int) $e->cid;
			if ($cid <= 0) {
				return $fail('The course is not in Chamilo yet', true);
			}
			$have = $this->studentsOfCourse($client, $cid);
			if ($have === null) {
				return $fail('Could not read the students of the course in Chamilo', true);
			}
			if (!$toRemove && !in_array($userId, $have, true)) {
				$res = $client->request('POST', '/api/course-users/actions/subscribe', array('cid' => $cid, 'type' => 5), array('userIds' => array($userId)));
				if (!$res['ok']) {
					return $fail('Enrol in the course: '.$res['error'], $res['retryable']);
				}
			} elseif ($toRemove && in_array($userId, $have, true)) {
				$res = $client->request('POST', '/api/course-users/actions/unsubscribe', array('cid' => $cid, 'type' => 5), array('userIds' => array($userId)));
				if (!$res['ok']) {
					return $fail('Remove from the course: '.$res['error'], $res['retryable']);
				}
			}
		}

		if ($toRemove) {
			$this->db->query("DELETE FROM ".$P."school_enrolment WHERE rowid = ".$enrolmentId);
		} else {
			$this->db->query("UPDATE ".$P."school_enrolment SET status = ".SchoolStudent::ENROLLED.", sync_status = 1, last_sync = '".$this->db->idate(dol_now())."', last_error = NULL WHERE rowid = ".$enrolmentId);
		}
		return array('ok' => true, 'error' => '', 'retryable' => false, 'hash' => schoolPayloadHash(array('enrolment' => $enrolmentId, 'remove' => $toRemove)));
	}

	/**
	 * Create the account of a student. A student without an address of their own gets one built from the first
	 * parent's, so the parent receives the message to choose a password; the address contains the username, so each
	 * candidate username is tried with its own address.
	 *
	 * @param SchoolAccount $account     Accounts
	 * @param object        $s           Student row
	 * @param string        $own         Student's own email, may be empty
	 * @param string        $parentEmail Email of the first parent that has one
	 * @param bool          $send        Whether Chamilo sends the new-account message
	 * @param string        $ref         Typed Dolibarr reference
	 * @return array{ok:bool,id:int,username:string,error:string,retryable:bool}
	 */
	private function createStudentAccount(SchoolAccount $account, $s, string $own, string $parentEmail, bool $send, string $ref): array
	{
		$names = schoolTeacherUsernameCandidates('', (string) $s->firstname, (string) $s->lastname);
		if (!$names) {
			return array('ok' => false, 'id' => 0, 'username' => '', 'error' => 'No Chamilo username can be built from this name', 'retryable' => false);
		}
		if ($own !== '') {
			return $account->create($names, $own, (string) $s->firstname, (string) $s->lastname, SchoolAccount::STATUS_STUDENT, $send, $ref);
		}

		$domain = getDolGlobalString('SCHOOL_STUDENT_EMAIL_DOMAIN');
		foreach ($names as $username) {
			$email = schoolStudentEmail('', $parentEmail, $username, $domain);
			if ($email === '') {
				return array('ok' => false, 'id' => 0, 'username' => '', 'error' => 'No email address can be built for this student: give the student an email, link a parent that has one, or set SCHOOL_STUDENT_EMAIL_DOMAIN', 'retryable' => false);
			}
			$res = $account->create(array($username), $email, (string) $s->firstname, (string) $s->lastname, SchoolAccount::STATUS_STUDENT, $send, $ref);
			if ($res['ok'] || empty($res['usernameTaken'])) {
				return $res;
			}
		}
		return array('ok' => false, 'id' => 0, 'username' => '', 'error' => 'All the usernames tried are taken: '.implode(', ', $names), 'retryable' => false);
	}

	/**
	 * Make the parents who follow the child match Chamilo: create their accounts, add the "follows" relation for those
	 * who follow, remove it for those who do not, and delete the links that were marked as removed.
	 *
	 * @param ChamiloClient                  $client    Client
	 * @param SchoolAccount                  $account   Accounts
	 * @param int                            $studentId Chamilo id of the student
	 * @param array<int,array<string,mixed>> $links     Parent links
	 * @param bool|null                      $sendEmail See push()
	 * @return array{error:string,retryable:bool}|null null when everything is in place
	 */
	private function syncGuardians(ChamiloClient $client, SchoolAccount $account, int $studentId, array $links, ?bool $sendEmail): ?array
	{
		$P = MAIN_DB_PREFIX;
		foreach ($links as $l) {
			$follow = (int) $l['chamilo_follow'];
			$guardianId = 0;
			if ($follow === SchoolStudent::FOLLOWING) {
				if ((int) $l['fk_socpeople'] <= 0 || trim((string) $l['contact_email']) === '') {
					return array('error' => 'The parent '.$l['soc_name'].' has no contact with an email address: it is needed to follow the child in Chamilo', 'retryable' => false);
				}
				$g = $this->guardianAccount($account, $l, $sendEmail);
				if (!$g['ok']) {
					return array('error' => $g['error'], 'retryable' => $g['retryable']);
				}
				$guardianId = $g['id'];
				$rel = $this->relationId($client, $studentId, $guardianId);
				if (!$rel['ok']) {
					return array('error' => $rel['error'], 'retryable' => $rel['retryable']);
				}
				if ($rel['id'] === null) {
					$res = $client->request('POST', '/api/user_rel_users', array(), array('user' => '/api/users/'.$studentId, 'friend' => '/api/users/'.$guardianId, 'relationType' => self::RELATION_FOLLOWS));
					if (!$res['ok']) {
						return array('error' => 'Make the parent follow the child: '.$res['error'], 'retryable' => $res['retryable']);
					}
				}
				continue;
			}

			// Not following (or no longer): remove the relation if the parent has an account and Chamilo has the relation.
			if ((int) $l['fk_socpeople'] > 0) {
				$gid = $this->db->query("SELECT chamilo_user_id FROM ".$P."school_guardian WHERE fk_socpeople = ".((int) $l['fk_socpeople'])." AND chamilo_user_id IS NOT NULL");
				$grow = $gid ? $this->db->fetch_object($gid) : null;
				if ($grow) {
					$rel = $this->relationId($client, $studentId, (int) $grow->chamilo_user_id);
					if (!$rel['ok']) {
						return array('error' => $rel['error'], 'retryable' => $rel['retryable']);
					}
					if ($rel['id'] !== null) {
						$res = $client->request('DELETE', '/api/user_rel_users/'.$rel['id']);
						if (!$res['ok'] && $res['status'] !== 404) {
							return array('error' => 'Stop the parent following the child: '.$res['error'], 'retryable' => $res['retryable']);
						}
					}
				}
			}
			if ($follow === SchoolStudent::UNFOLLOW) {
				$this->db->query("DELETE FROM ".$P."school_student_parent WHERE rowid = ".((int) $l['rowid']));
			}
		}
		return null;
	}

	/**
	 * The Chamilo account of a parent, created or linked when it does not exist yet.
	 *
	 * @param SchoolAccount        $account   Accounts
	 * @param array<string,mixed>  $l         Parent link
	 * @param bool|null            $sendEmail See push()
	 * @return array{ok:bool,id:int,error:string,retryable:bool}
	 */
	private function guardianAccount(SchoolAccount $account, array $l, ?bool $sendEmail): array
	{
		global $conf;
		$P = MAIN_DB_PREFIX;
		$contactId = (int) $l['fk_socpeople'];
		$resql = $this->db->query("SELECT rowid, chamilo_user_id FROM ".$P."school_guardian WHERE entity = ".((int) $conf->entity)." AND fk_socpeople = ".$contactId);
		$g = $resql ? $this->db->fetch_object($resql) : null;
		if (!$g) {
			$this->db->query("INSERT INTO ".$P."school_guardian (entity, fk_socpeople, sync_status, datec) VALUES (".((int) $conf->entity).", ".$contactId.", 0, '".$this->db->idate(dol_now())."')");
			$g = (object) array('rowid' => (int) $this->db->last_insert_id($P.'school_guardian'), 'chamilo_user_id' => null);
		}
		$guardianRow = (int) $g->rowid;
		$fail = function (string $error, bool $retryable) use ($guardianRow, $P): array {
			$this->db->query("UPDATE ".$P."school_guardian SET sync_status = 2, last_error = '".$this->db->escape(dol_substr($error, 0, 2000))."' WHERE rowid = ".$guardianRow);
			return array('ok' => false, 'id' => 0, 'error' => $error, 'retryable' => $retryable);
		};
		if ((int) $g->chamilo_user_id > 0) {
			return array('ok' => true, 'id' => (int) $g->chamilo_user_id, 'error' => '', 'retryable' => false);
		}

		$email = trim((string) $l['contact_email']);
		$first = trim((string) $l['contact_firstname']);
		$last = trim((string) $l['contact_lastname']) ?: trim((string) $l['soc_name']);
		$lookup = $account->findByEmail($email);
		if (!$lookup['ok']) {
			return $fail($lookup['error'], $lookup['retryable']);
		}
		$note = '';
		if ($lookup['user'] !== null) {
			$chamiloId = (int) $lookup['user']['id'];
			$username = (string) ($lookup['user']['username'] ?? '');
			if (is_array($lookup['user']['roles'] ?? null) && !in_array('ROLE_HR', $lookup['user']['roles'], true)) {
				$note = 'NOTE: linked to an existing Chamilo account that is not a human resources manager; its role was not changed';
			}
		} else {
			$send = $sendEmail ?? (getDolGlobalString('SCHOOL_PARENT_SEND_EMAIL', '1') !== '0');
			$created = $account->create(schoolTeacherUsernameCandidates('', $first, $last), $email, $first, $last, SchoolAccount::STATUS_HR_MANAGER, $send, schoolDolibarrRef('contact', $contactId));
			if (!$created['ok']) {
				return $fail($created['error'], $created['retryable']);
			}
			$chamiloId = $created['id'];
			$username = $created['username'];
		}
		$this->db->query("UPDATE ".$P."school_guardian SET chamilo_user_id = ".$chamiloId.", chamilo_username = ".($username === '' ? 'NULL' : "'".$this->db->escape($username)."'")." WHERE rowid = ".$guardianRow);
		$set = $account->setDolibarrRef($chamiloId, schoolDolibarrRef('contact', $contactId));
		if (!$set['ok']) {
			return $fail($set['error'], true);
		}
		$this->db->query("UPDATE ".$P."school_guardian SET sync_status = 1, last_sync = '".$this->db->idate(dol_now())."', last_error = ".($note === '' ? 'NULL' : "'".$this->db->escape($note)."'")." WHERE rowid = ".$guardianRow);
		return array('ok' => true, 'id' => $chamiloId, 'error' => '', 'retryable' => false);
	}

	/**
	 * Id of the "follows" relation between a student and a parent in Chamilo, null when there is none.
	 *
	 * @param ChamiloClient $client     Client
	 * @param int           $studentId  Chamilo id of the student
	 * @param int           $guardianId Chamilo id of the parent
	 * @return array{ok:bool,id:?int,error:string,retryable:bool}
	 */
	private function relationId(ChamiloClient $client, int $studentId, int $guardianId): array
	{
		$res = $client->request('GET', '/api/user_rel_users', array('user' => '/api/users/'.$studentId, 'friend' => '/api/users/'.$guardianId, 'relationType' => self::RELATION_FOLLOWS, 'itemsPerPage' => 5));
		if (!$res['ok']) {
			return array('ok' => false, 'id' => null, 'error' => 'Read the parent relation: '.$res['error'], 'retryable' => $res['retryable']);
		}
		foreach (ChamiloClient::members($res['data']) as $row) {
			if (is_array($row)) {
				$id = isset($row['id']) ? (int) $row['id'] : schoolIriId((string) ($row['@id'] ?? ''));
				if ($id) {
					return array('ok' => true, 'id' => $id, 'error' => '', 'retryable' => false);
				}
			}
		}
		return array('ok' => true, 'id' => null, 'error' => '', 'retryable' => false);
	}

	/**
	 * @param ChamiloClient $client Client
	 * @param int           $cid    Chamilo course id
	 * @return list<int>|null Ids of the students of the course, null when Chamilo could not be read
	 */
	private function studentsOfCourse(ChamiloClient $client, int $cid): ?array
	{
		$res = $client->request('GET', '/api/course_rel_users', array('course' => '/api/courses/'.$cid, 'itemsPerPage' => 500));
		if (!$res['ok']) {
			return null;
		}
		$ids = array();
		foreach (ChamiloClient::members($res['data']) as $row) {
			if (!is_array($row) || (int) ($row['status'] ?? 0) !== 5) {
				continue;
			}
			$u = $row['user'] ?? null;
			$id = is_array($u) ? (int) ($u['id'] ?? schoolIriId((string) ($u['@id'] ?? '')) ?? 0) : (int) (schoolIriId((string) $u) ?? 0);
			if ($id > 0) {
				$ids[$id] = $id;
			}
		}
		return array_values($ids);
	}

	/**
	 * @param int $studentId School student id
	 * @return array<int,array<string,mixed>> Parent links, payers first
	 */
	private function parentLinks(int $studentId): array
	{
		$P = MAIN_DB_PREFIX;
		$sql = "SELECT sp.rowid, sp.fk_soc, sp.fk_socpeople, sp.chamilo_follow, c.email AS contact_email, c.firstname AS contact_firstname, c.lastname AS contact_lastname, s.email AS soc_email, s.nom AS soc_name"
			." FROM ".$P."school_student_parent AS sp LEFT JOIN ".$P."socpeople AS c ON c.rowid = sp.fk_socpeople LEFT JOIN ".$P."societe AS s ON s.rowid = sp.fk_soc"
			." WHERE sp.fk_student = ".$studentId." ORDER BY sp.is_payer DESC, sp.rowid";
		$out = array();
		$resql = $this->db->query($sql);
		while ($resql && ($o = $this->db->fetch_object($resql))) {
			$out[] = (array) $o;
		}
		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $links Parent links
	 * @return string Email of the first parent that has one (contact first, then third party), '' when none
	 */
	private function firstParentEmail(array $links): string
	{
		foreach ($links as $l) {
			if ((int) $l['chamilo_follow'] === SchoolStudent::UNFOLLOW) {
				continue;
			}
			foreach (array('contact_email', 'soc_email') as $k) {
				if (trim((string) ($l[$k] ?? '')) !== '') {
					return trim((string) $l[$k]);
				}
			}
		}
		return '';
	}

	/**
	 * @param int    $studentId School student id
	 * @param int    $chamiloId Chamilo user id
	 * @param string $username  Chamilo username
	 */
	private function saveStudentIds(int $studentId, int $chamiloId, string $username): void
	{
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."school_student SET chamilo_user_id = ".$chamiloId.", chamilo_username = ".($username === '' ? 'NULL' : "'".$this->db->escape($username)."'")." WHERE rowid = ".$studentId);
	}
}
