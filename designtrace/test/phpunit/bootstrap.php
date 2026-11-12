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
 * \file        designtrace/test/phpunit/bootstrap.php
 * \ingroup     designtrace
 * \brief       PHPUnit bootstrap: checks Dolibarr was loaded by run-tests.php, loads the classes
 */

if (!defined('DOL_DOCUMENT_ROOT') || empty($GLOBALS['db'])) {
	fwrite(STDERR, "Run the tests with designtrace/test/phpunit/run-tests.php, not vendor/bin/phpunit\n");
	exit(1);
}

foreach (array('design', 'component', 'componentrevision', 'revisionfile', 'componentlink', 'doclinks', 'clientaccess',
	'changeorder', 'licence', 'licenceversion', 'licensing', 'portfolio', 'proofapproval', 'reorder', 'cadtime',
	'customerportal', 'actions_designtrace') as $class) {
	dol_include_once('/designtrace/class/'.$class.'.class.php');
}
dol_include_once('/designtrace/lib/designtrace.lib.php');
dol_include_once('/designtrace/lib/designtrace_links.lib.php');
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';
require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';
require_once DOL_DOCUMENT_ROOT.'/bom/class/bom.class.php';

require_once __DIR__.'/DesignTraceTestCase.php';
