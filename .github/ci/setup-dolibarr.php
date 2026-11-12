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
 * Prepare a fresh Dolibarr for the test suite: enable the core modules DesignTrace works with, then
 * DesignTrace itself (in a second run: in one process $conf still says what was enabled at start).
 * Run inside the Dolibarr container as www-data:
 *
 *     php /var/www/html/custom/.github/ci/setup-dolibarr.php core
 *     php /var/www/html/custom/.github/ci/setup-dolibarr.php designtrace
 */

define('NOSESSION', 1);
define('NOREQUIREHTML', 1);
$_SERVER['HTTP_HOST'] = 'localhost';
require '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

$step = $argv[1] ?? '';
if ($user->fetch(0, 'admin') <= 0) {
	fwrite(STDERR, "No admin user\n");
	exit(1);
}
$user->loadRights();

if ($step === 'core') {
	// Customer codes generated automatically (tests create customers with code 'auto')
	dolibarr_set_const($db, 'SOCIETE_CODECLIENT_ADDON', 'mod_codeclient_monkey', 'chaine', 0, '', $conf->entity);
	$modules = array('modSociete', 'modProduct', 'modService', 'modPropale', 'modCommande', 'modFacture', 'modProjet',
		'modTicket', 'modBom', 'modMrp', 'modAgenda', 'modApi');
} elseif ($step === 'designtrace') {
	$modules = array('modDesignTrace');
} else {
	fwrite(STDERR, "Usage: setup-dolibarr.php core|designtrace\n");
	exit(1);
}

$error = 0;
foreach ($modules as $module) {
	$result = activateModule($module);
	if (!empty($result['errors'])) {
		fwrite(STDERR, $module.': '.implode(', ', $result['errors'])."\n");
		$error++;
	} else {
		echo $module." enabled\n";
	}
}
exit($error ? 1 : 0);
