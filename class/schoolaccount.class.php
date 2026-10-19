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
 * \file    class/schoolaccount.class.php
 * \ingroup school
 * \brief   Chamilo user accounts: look-up by email, creation, update, and the link back to Dolibarr.
 */

require_once __DIR__.'/chamilosetup.class.php';

/**
 * What teachers, students and parents have in common on the Chamilo side. The status given at creation is the only
 * role this module ever grants: 1 for a teacher, 5 for a student, 4 for a human resources manager (the parent who
 * follows children). The role of an account that exists is never changed.
 */
class SchoolAccount
{
	public const STATUS_TEACHER = 1;
	public const STATUS_HR_MANAGER = 4;
	public const STATUS_STUDENT = 5;

	/** @var ChamiloClient */
	private $client;

	/**
	 * @param ChamiloClient $client Client
	 */
	public function __construct(ChamiloClient $client)
	{
		$this->client = $client;
	}

	/**
	 * @param string $email Email
	 * @return array{ok:bool,user:?array<string,mixed>,error:string,retryable:bool}
	 */
	public function findByEmail(string $email): array
	{
		$res = $this->client->request('GET', '/api/users', array('email' => $email, 'itemsPerPage' => 5));
		if (!$res['ok']) {
			return array('ok' => false, 'user' => null, 'error' => 'Look up the account: '.$res['error'], 'retryable' => $res['retryable']);
		}
		foreach (ChamiloClient::members($res['data']) as $u) {
			if (is_array($u) && isset($u['id']) && strcasecmp((string) ($u['email'] ?? $email), $email) === 0) {
				// A deleted account keeps its email, so no other account can use it, and it cannot be used itself.
				if ((int) ($u['active'] ?? 1) === -2) {
					return array('ok' => false, 'user' => null, 'error' => 'This email belongs to a deleted Chamilo account', 'retryable' => false);
				}
				return array('ok' => true, 'user' => $u, 'error' => '', 'retryable' => false);
			}
		}
		return array('ok' => true, 'user' => null, 'error' => '', 'retryable' => false);
	}

	/**
	 * Create the account, trying the candidate usernames in order: a name that is taken moves on to the next one.
	 *
	 * @param list<string> $usernames   Candidates, in order of preference
	 * @param string       $email       Email
	 * @param string       $firstname   First name
	 * @param string       $lastname    Last name
	 * @param int          $status      STATUS_TEACHER, STATUS_STUDENT or STATUS_HR_MANAGER
	 * @param bool         $sendEmail   Whether Chamilo sends the "choose your password" message
	 * @param string       $dolibarrRef Typed reference stored in the dolibarr_id extra field (see schoolDolibarrRef)
	 * @return array{ok:bool,id:int,username:string,error:string,retryable:bool,usernameTaken?:bool} usernameTaken is set when every name was refused as taken
	 */
	public function create(array $usernames, string $email, string $firstname, string $lastname, int $status, bool $sendEmail, string $dolibarrRef): array
	{
		if (!$usernames) {
			return $this->failure('No Chamilo username can be built', false);
		}
		$urlId = getDolGlobalInt('SCHOOL_CHAMILO_ACCESS_URL_ID') ?: $this->client->accessUrlId();
		if (!$urlId) {
			return $this->failure('The Chamilo portal could not be identified: set SCHOOL_CHAMILO_ACCESS_URL_ID', false);
		}

		foreach ($usernames as $username) {
			$res = $this->client->request('POST', '/api/access_urls/'.$urlId.'/user', array(), array(
				'username' => $username,
				'email' => $email,
				'firstname' => trim($firstname),
				'lastname' => trim($lastname),
				'status' => $status,
				'sendEmail' => $sendEmail,
				'extraFields' => array('dolibarr_id' => $dolibarrRef),
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
				return $this->failure('An account with this email appeared meanwhile', true);
			}
			return $this->failure('Create account: '.$res['error'], $res['retryable']);
		}
		$res = $this->failure('All the usernames tried are taken: '.implode(', ', $usernames), false);
		$res['usernameTaken'] = true;
		return $res;
	}

	/**
	 * @param int                 $chamiloId Chamilo user id
	 * @param array<string,mixed> $payload   Fields to change
	 * @return array{ok:bool,status:int,error:string,retryable:bool}
	 */
	public function update(int $chamiloId, array $payload): array
	{
		$res = $this->client->request('PATCH', '/api/users/'.$chamiloId, array(), $payload);
		return array('ok' => $res['ok'], 'status' => $res['status'], 'error' => $res['error'], 'retryable' => $res['retryable']);
	}

	/**
	 * Write the typed Dolibarr reference on the account, unless it is already there.
	 *
	 * @param int    $chamiloId   Chamilo user id
	 * @param string $dolibarrRef Typed reference
	 * @return array{ok:bool,error:string}
	 */
	public function setDolibarrRef(int $chamiloId, string $dolibarrRef): array
	{
		$fieldId = getDolGlobalInt('SCHOOL_EF_USER_DOLIBARR_ID');
		if ($fieldId <= 0) {
			return array('ok' => false, 'error' => 'The Chamilo extra fields are not set up: use "Create the extra fields" on the setup page');
		}
		$r = (new ChamiloSetup($this->client))->setValue($fieldId, $chamiloId, $dolibarrRef);
		return array('ok' => $r['ok'], 'error' => $r['ok'] ? '' : 'Extra field dolibarr_id: '.$r['error']);
	}

	/**
	 * @param string $error     Message
	 * @param bool   $retryable Whether another attempt can succeed
	 * @return array{ok:bool,id:int,username:string,error:string,retryable:bool}
	 */
	private function failure(string $error, bool $retryable): array
	{
		return array('ok' => false, 'id' => 0, 'username' => '', 'error' => $error, 'retryable' => $retryable);
	}
}
