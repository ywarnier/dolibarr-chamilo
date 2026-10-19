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
 * \file    lib/school.lib.php
 * \ingroup school
 * \brief   Helpers that need Dolibarr: pages, rights, settings, Chamilo client.
 */

require_once __DIR__.'/schoollogic.lib.php';

/**
 * Load the module language file.
 */
function schoolLoadLangs(): void
{
	global $langs;
	$langs->loadLangs(array('school@school'));
}

/**
 * First call of every page: languages, module enabled, read right.
 */
function schoolStartPage(): void
{
	schoolLoadLangs();
	if (!isModEnabled('school')) {
		accessforbidden();
	}
	schoolRequireRight('read');
}

/**
 * Refuse the page unless the user holds the right.
 *
 * @param string $perm read, write, delete, sync or setup
 */
function schoolRequireRight(string $perm): void
{
	global $user;
	if (!$user->hasRight('school', $perm)) {
		accessforbidden();
	}
}

/**
 * Chamilo API key, decrypted. The key is stored encrypted by dolEncrypt when the core provides it.
 *
 * @return string '' when none is set
 */
function schoolGetApiKey(): string
{
	$stored = getDolGlobalString('SCHOOL_CHAMILO_API_KEY');
	if ($stored === '') {
		return '';
	}
	if (function_exists('dolDecrypt')) {
		return (string) dolDecrypt($stored);
	}
	return $stored;
}

/**
 * Store the Chamilo API key, encrypted when the core provides it.
 *
 * @param DoliDB $db  Database handler
 * @param string $key Plain key
 * @return int >0 if OK
 */
function schoolSetApiKey($db, string $key): int
{
	global $conf;
	$value = function_exists('dolEncrypt') ? (string) dolEncrypt($key) : $key;
	return dolibarr_set_const($db, 'SCHOOL_CHAMILO_API_KEY', $value, 'chaine', 0, '', (int) $conf->entity);
}

/**
 * Client built from the setup page values.
 *
 * @return ChamiloClient|null null when the URL or the key is missing
 */
function schoolGetClient(): ?ChamiloClient
{
	require_once __DIR__.'/../class/chamiloclient.class.php';
	$url = schoolNormalizeBaseUrl(getDolGlobalString('SCHOOL_CHAMILO_URL'));
	$key = schoolGetApiKey();
	if ($url === '' || $key === '') {
		return null;
	}
	return new ChamiloClient($url, $key, getDolGlobalInt('SCHOOL_CHAMILO_ALLOW_LOCAL') > 0);
}
