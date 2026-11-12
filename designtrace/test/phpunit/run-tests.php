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
 * \file        designtrace/test/phpunit/run-tests.php
 * \ingroup     designtrace
 * \brief       Runs the PHPUnit suite inside a working Dolibarr
 *
 * Dolibarr has to be loaded in the global scope (its functions use `global $conf, $db...`), and
 * PHPUnit includes its bootstrap file from inside a method. So this script loads Dolibarr first
 * and then starts PHPUnit, passing the command line through:
 *
 *     su -s /bin/sh www-data -c "php designtrace/test/phpunit/run-tests.php [phpunit options]"
 *
 * Run it as the web server user so the files the tests write get the right owner. Environment:
 * DOLIBARR_ROOT (default /var/www/html, the folder holding master.inc.php) and
 * DOLIBARR_TEST_LOGIN (default admin, an administrator). The DesignTrace module must be enabled.
 * Every test runs in a transaction that is rolled back, so the database is left as it was.
 */

if (PHP_SAPI !== 'cli') {
	echo "Command line only\n";
	exit(1);
}

define('NOSESSION', 1);
define('NOREQUIREHTML', 1);
if (empty($_SERVER['HTTP_HOST'])) {
	$_SERVER['HTTP_HOST'] = 'localhost';
}

$repoRoot = dirname(__DIR__, 3);
$dolibarrRoot = getenv('DOLIBARR_ROOT') ?: '/var/www/html';
if (!is_file($dolibarrRoot.'/master.inc.php')) {
	fwrite(STDERR, "Dolibarr not found in ".$dolibarrRoot." (set DOLIBARR_ROOT)\n");
	exit(1);
}
if (!is_file($repoRoot.'/vendor/autoload.php')) {
	fwrite(STDERR, "PHPUnit not installed: run composer install in ".$repoRoot."\n");
	exit(1);
}

require $dolibarrRoot.'/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

if (!isModEnabled('designtrace')) {
	fwrite(STDERR, "The DesignTrace module is not enabled in this Dolibarr\n");
	exit(1);
}
$login = getenv('DOLIBARR_TEST_LOGIN') ?: 'admin';
if ($user->fetch(0, $login) <= 0 || empty($user->admin)) {
	fwrite(STDERR, "Test user ".$login." not found or not an administrator\n");
	exit(1);
}
$user->loadRights();
$langs->setDefaultLang('en_US');
$langs->loadLangs(array('main', 'errors', 'designtrace@designtrace'));

require $repoRoot.'/vendor/autoload.php';

chdir($repoRoot);	// phpunit.xml.dist is found from here
$argv = $_SERVER['argv'];
exit((new PHPUnit\TextUI\Application())->run($argv));
