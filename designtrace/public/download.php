<?php
/* Copyright (C) 2026		Kye McIntyre
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *	\file       designtrace/public/download.php
 *	\ingroup    designtrace
 *	\brief      Download a customer file through a short-lived signed link made by the REST API
 *
 *	No login: the link itself is the authorisation (HMAC over file, customer, document, actor,
 *	entity and expiry). The visibility rules are checked again here, so a file hidden after the
 *	link was made is refused. Every customer download is logged. Links of type "portfolio" serve
 *	gallery images of designs whose customer consented (not logged: they are public on the site).
 */

if (!defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}
if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}
// Entity of the link, before main.inc.php (GETPOST is not defined yet)
$entity = (!empty($_GET['n']) ? (int) $_GET['n'] : 1);
if ($entity > 0) {
	define('DOLENTITY', $entity);
}

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
/**
 * @var Conf $conf
 * @var DoliDB $db
 */

dol_include_once('/designtrace/class/clientaccess.class.php');

if (!isModEnabled('designtrace')) {
	httponly_accessforbidden('Module not enabled', 404);
}

$params = array(
	'f' => GETPOSTINT('f'),
	's' => GETPOSTINT('s'),
	'e' => GETPOSTINT('e'),
	't' => GETPOST('t', 'aZ09'),
	'd' => GETPOSTINT('d'),
	'a' => GETPOST('a', 'alphanohtml'),
	'n' => GETPOSTINT('n'),
	'sig' => GETPOST('sig', 'aZ09'),
);

$reason = DesignTraceClientAccess::checkSignedParams($params);
if ($reason !== '') {
	dol_syslog('designtrace download refused: '.$reason.' file='.$params['f'], LOG_NOTICE);
	httponly_accessforbidden($reason, 403);
}

// Gallery image (portfolio): the design must still be in the gallery
if ($params['t'] === 'portfolio') {
	dol_include_once('/designtrace/class/portfolio.class.php');
	$image = DesignTracePortfolio::getImage($db, (int) $params['d'], (int) $params['f']);
	if (!$image || !is_file(dol_osencode($image['path']))) {
		httponly_accessforbidden('Image not available', 404);
	}
	DesignTraceClientAccess::sendFile($image['path'], $image['filename'], true);
}

$row = DesignTraceClientAccess::getDownloadableFile($db, (int) $params['s'], (int) $params['f'], DesignTraceClientAccess::normaliseElement((string) $params['t']), (int) $params['d']);
if (!$row) {
	dol_syslog('designtrace download refused: file '.$params['f'].' no longer visible to customer '.$params['s'], LOG_NOTICE);
	httponly_accessforbidden('File not available', 403);
}
$fullpath = DesignTraceClientAccess::getFilePath($db, $row);
if ($fullpath === '' || !is_file(dol_osencode($fullpath))) {
	httponly_accessforbidden('File not found', 404);
}

DesignTraceClientAccess::logDownload($db, $row, (int) $params['s'], DesignTraceClientAccess::CHANNEL_API, (string) $params['a']);
DesignTraceClientAccess::sendFile($fullpath, $row->filename);
