<?php
/* Copyright (C) 2001-2005  Rodolphe Quiedeville    <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2015  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012  Regis Houssin           <regis.houssin@inodbox.com>
 * Copyright (C) 2015       Jean-François Ferry     <jfefe@aternatik.fr>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2026		Kye McIntyre
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
 *	\file       designtrace/designtraceindex.php
 *	\ingroup    designtrace
 *	\brief      Home page of designtrace top menu
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
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
 * The main.inc.php has been included so the following variable are now defined:
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */
include_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';

// Load translation files required by the page
$langs->loadLangs(array("designtrace@designtrace"));

$action = GETPOST('action', 'aZ09');

$now = dol_now();

$max = getDolUserInt('MAIN_SIZE_SHORTLIST_LIMIT', getDolGlobalInt('MAIN_SIZE_SHORTLIST_LIMIT', 5));

// Security check - Protection if external user
$socid = GETPOSTINT('socid');
if (!empty($user->socid) && $user->socid > 0) {
	$action = '';
	$socid = $user->socid;
}

// Initialize a technical object to manage hooks. Note that conf->hooks_modules contains array
//$hookmanager->initHooks(array($object->element.'index'));

// Security check (enable the most restrictive one)
if ($user->socid > 0) {
	accessforbidden(); // External users only get files through the portals (phase 5)
}
//if ($user->socid > 0) $socid = $user->socid;
if (!isModEnabled('designtrace')) {
	accessforbidden('Module not enabled');
}
//if (! $user->hasRight('designtrace', 'design', 'read')) {
//	accessforbidden();
//}
//restrictedArea($user, 'designtrace', 0, 'designtrace_design', 'design', '', 'rowid');
//if (empty($user->admin)) {
//	accessforbidden('Must be admin');
//}


/*
 * Actions
 */

// None


/*
 * View
 */

dol_include_once('/designtrace/class/design.class.php');
dol_include_once('/designtrace/class/componentrevision.class.php');
dol_include_once('/designtrace/class/componentlink.class.php');
dol_include_once('/designtrace/class/changeorder.class.php');
dol_include_once('/designtrace/class/clientaccess.class.php');
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

$form = new Form($db);
$formfile = new FormFile($db);

llxHeader("", $langs->trans("DesignTraceArea"), '', '', 0, 0, '', '', '', 'mod-designtrace page-index');

print load_fiche_titre($langs->trans("DesignTraceArea"), '', 'fa-drafting-compass');

$canrev = $user->hasRight('designtrace', 'componentrevision', 'read');
$candesign = $user->hasRight('designtrace', 'design', 'read');
$caneco = $user->hasRight('designtrace', 'changeorder', 'read');

/**
 * One integer from a COUNT query
 *
 * @param	DoliDB	$db		Database handler
 * @param	string	$sql	Query returning "nb"
 * @return	int
 */
function designtraceIndexCount($db, $sql)
{
	$resql = $db->query($sql);
	$obj = $resql ? $db->fetch_object($resql) : null;
	return $obj ? (int) $obj->nb : 0;
}

print '<div class="fichecenter"><div class="fichethirdleft">';

// At a glance
$stats = array();
if ($candesign) {
	$stats[] = array('DesignsActive', designtraceIndexCount($db, "SELECT COUNT(rowid) as nb FROM ".$db->prefix()."designtrace_design as t WHERE t.status = ".Design::STATUS_VALIDATED." AND t.entity IN (".getEntity('design').")".Design::sqlConfidentialFilter($user, 't')), dol_buildpath('/designtrace/design_list.php', 1).'?search_status='.Design::STATUS_VALIDATED);
}
if ($canrev) {
	$stats[] = array('RevisionsInDraft', designtraceIndexCount($db, "SELECT COUNT(rowid) as nb FROM ".$db->prefix()."designtrace_componentrevision WHERE status = ".ComponentRevision::STATUS_DRAFT." AND entity IN (".getEntity('componentrevision').")".Design::sqlConfidentialFilterOnComponent($user, 'fk_component')), dol_buildpath('/designtrace/componentrevision_list.php', 1).'?search_status='.ComponentRevision::STATUS_DRAFT);
	$stats[] = array('RevisionsInReview', designtraceIndexCount($db, "SELECT COUNT(rowid) as nb FROM ".$db->prefix()."designtrace_componentrevision WHERE status = ".ComponentRevision::STATUS_INREVIEW." AND entity IN (".getEntity('componentrevision').")".Design::sqlConfidentialFilterOnComponent($user, 'fk_component')), dol_buildpath('/designtrace/componentrevision_list.php', 1).'?search_status='.ComponentRevision::STATUS_INREVIEW);
	$stats[] = array('NeedsUpdateList', count(ComponentLink::fetchFlagged($db, 'l.flag_status = '.ComponentLink::FLAG_NEEDS_UPDATE.Design::sqlConfidentialFilterOn($user, 'pc.fk_design'))), dol_buildpath('/designtrace/needsupdate_list.php', 1).'?search_flag='.ComponentLink::FLAG_NEEDS_UPDATE);
	$stats[] = array('DownloadsLast30Days', designtraceIndexCount($db, "SELECT COUNT(rowid) as nb FROM ".$db->prefix()."designtrace_download WHERE date_download >= '".$db->idate(dol_time_plus_duree($now, -30, 'd'))."' AND entity IN (".getEntity('designtrace_download').")".Design::sqlConfidentialFilterOnRevision($user, 'fk_revision')), dol_buildpath('/designtrace/download_list.php', 1));
}
if ($caneco) {
	$stats[] = array('EcosOpen', designtraceIndexCount($db, "SELECT COUNT(rowid) as nb FROM ".$db->prefix()."designtrace_changeorder WHERE status IN (".ChangeOrder::STATUS_DRAFT.", ".ChangeOrder::STATUS_APPROVED.", ".ChangeOrder::STATUS_IMPLEMENTED.") AND entity IN (".getEntity('changeorder').")"), dol_buildpath('/designtrace/changeorder_list.php', 1));
}
if (!empty($stats)) {
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('AtAGlance').'</th></tr>';
	foreach ($stats as $stat) {
		print '<tr class="oddeven"><td><a href="'.$stat[2].'">'.$langs->trans($stat[0]).'</a></td><td class="right"><a href="'.$stat[2].'"><span class="badge">'.((int) $stat[1]).'</span></a></td></tr>';
	}
	print '</table></div><br>';
}

// Open change orders
if ($caneco) {
	$eco = new ChangeOrder($db);
	$ecos = $eco->fetchAll('DESC', 't.rowid', $max, 0, '(t.status:in:'.ChangeOrder::STATUS_DRAFT.','.ChangeOrder::STATUS_APPROVED.','.ChangeOrder::STATUS_IMPLEMENTED.')');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('EcosOpen').'</th><th class="right"><a href="'.dol_buildpath('/designtrace/changeorder_list.php', 1).'">'.$langs->trans('FullList').'</a></th></tr>';
	if (is_array($ecos) && count($ecos)) {
		foreach ($ecos as $row) {
			print '<tr class="oddeven"><td class="nowraponall">'.$row->getNomUrl(1).'</td><td class="tdoverflowmax200">'.dol_escape_htmltag((string) $row->label).'</td><td class="right nowraponall">'.$row->getLibStatut(5).'</td></tr>';
		}
	} else {
		print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
	}
	print '</table></div><br>';
}

print '</div><div class="fichetwothirdright">';

// Designs being worked on: draft designs and designs with revisions in draft or in review
if ($candesign && $canrev) {
	$sql = "SELECT d.rowid, d.ref, d.label, d.status, d.fk_soc, s.nom as socname, d.fk_user_designer, d.tms as design_tms,";
	$sql .= " r.rowid as rev_id, r.ref as rev_ref, r.status as rev_status, r.tms as rev_tms";
	$sql .= " FROM ".$db->prefix()."designtrace_design as d";
	$sql .= " LEFT JOIN ".$db->prefix()."societe as s ON s.rowid = d.fk_soc";
	$sql .= " LEFT JOIN ".$db->prefix()."designtrace_component as c ON c.fk_design = d.rowid";
	$sql .= " LEFT JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.fk_component = c.rowid AND r.status IN (".ComponentRevision::STATUS_DRAFT.", ".ComponentRevision::STATUS_INREVIEW.")";
	$sql .= " WHERE d.entity IN (".getEntity('design').")";
	$sql .= " AND (d.status = ".Design::STATUS_DRAFT." OR r.rowid IS NOT NULL)";
	$sql .= Design::sqlConfidentialFilter($user, 'd');
	$sql .= " ORDER BY d.rowid DESC, r.ref";
	$resql = $db->query($sql);
	$designs = array();
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$id = (int) $obj->rowid;
		if (!isset($designs[$id])) {
			$designs[$id] = array('row' => $obj, 'revs' => array(), 'last' => $db->jdate($obj->design_tms));
		}
		if ($obj->rev_id) {
			$designs[$id]['revs'][] = $obj;
			$designs[$id]['last'] = max($designs[$id]['last'], $db->jdate($obj->rev_tms));
		}
	}
	uasort($designs, function ($a, $b) {
		return $b['last'] <=> $a['last'];
	});
	$flagsbydesign = array();
	foreach (ComponentLink::fetchFlagged($db, '') as $flag) {
		// The flagged item is the parent revision: count it on the parent's design
		if (!empty($flag->parent_design_id)) {
			$flagsbydesign[(int) $flag->parent_design_id] = (isset($flagsbydesign[(int) $flag->parent_design_id]) ? $flagsbydesign[(int) $flag->parent_design_id] : 0) + 1;
		}
	}

	$designstatic = new Design($db);
	$revstatic = new ComponentRevision($db);
	$socstatic = new Societe($db);
	$userstatic = new User($db);
	$usercache = array();
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>'.$form->textwithpicto($langs->trans('DesignsInProgress'), $langs->trans('DesignsInProgressHelp')).' <span class="badge marginleftonlyshort">'.count($designs).'</span></th>';
	print '<th>'.$langs->trans('Customer').'</th><th>'.$langs->trans('Designer').'</th><th>'.$langs->trans('RevisionsInProgress').'</th><th class="right">'.$langs->trans('DateModificationShort').'</th></tr>';
	$shown = 0;
	foreach ($designs as $id => $data) {
		if ($shown++ >= max(10, $max)) {
			break;
		}
		$row = $data['row'];
		$designstatic->id = $id;
		$designstatic->ref = $row->ref;
		$designstatic->label = $row->label;
		$designstatic->status = $row->status;
		print '<tr class="oddeven">';
		print '<td class="nowraponall">'.$designstatic->getNomUrl(1).' <span class="opacitymedium">'.dol_escape_htmltag(dol_trunc((string) $row->label, 30)).'</span>';
		if ((int) $row->status === Design::STATUS_DRAFT) {
			print ' '.$designstatic->getLibStatut(3);
		}
		if (!empty($flagsbydesign[$id])) {
			print ' '.ComponentLink::getFlagBadge(ComponentLink::FLAG_NEEDS_UPDATE);
		}
		print '</td>';
		print '<td class="tdoverflowmax150">';
		if ($row->fk_soc > 0) {
			$socstatic->id = (int) $row->fk_soc;
			$socstatic->name = (string) $row->socname;
			print $socstatic->getNomUrl(1);
		}
		print '</td>';
		print '<td class="tdoverflowmax100">';
		if ($row->fk_user_designer > 0) {
			if (!isset($usercache[$row->fk_user_designer])) {
				$usercache[$row->fk_user_designer] = new User($db);
				$usercache[$row->fk_user_designer]->fetch((int) $row->fk_user_designer);
			}
			print $usercache[$row->fk_user_designer]->getNomUrl(-1);
		}
		print '</td>';
		print '<td>';
		$parts = array();
		foreach ($data['revs'] as $rev) {
			$revstatic->id = (int) $rev->rev_id;
			$revstatic->ref = $rev->rev_ref;
			$revstatic->status = (int) $rev->rev_status;
			$parts[] = $revstatic->getNomUrl(1).' '.$revstatic->getLibStatut(3);
		}
		print $parts ? implode('<br>', $parts) : '<span class="opacitymedium">'.$langs->trans('None').'</span>';
		print '</td>';
		print '<td class="right nowraponall">'.dol_print_date($data['last'], 'day').'</td>';
		print '</tr>';
	}
	if (empty($designs)) {
		print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('DesignsInProgressNone').'</span></td></tr>';
	}
	print '</table></div><br>';
}

// Latest customer downloads
if ($canrev) {
	$sql = "SELECT dl.date_download, dl.filename, dl.fk_soc, s.nom as socname, dl.channel, dl.fk_revision, r.ref as rev_ref";
	$sql .= " FROM ".$db->prefix()."designtrace_download as dl";
	$sql .= " LEFT JOIN ".$db->prefix()."societe as s ON s.rowid = dl.fk_soc";
	$sql .= " LEFT JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = dl.fk_revision";
	$sql .= " WHERE dl.entity IN (".getEntity('designtrace_download').")";
	$sql .= Design::sqlConfidentialFilterOnRevision($user, 'dl.fk_revision');
	$sql .= " ORDER BY dl.date_download DESC, dl.rowid DESC".$db->plimit($max);
	$resql = $db->query($sql);
	$socstatic = new Societe($db);
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><th colspan="3">'.$langs->trans('LatestDownloads').'</th><th class="right"><a href="'.dol_buildpath('/designtrace/download_list.php', 1).'">'.$langs->trans('FullList').'</a></th></tr>';
	$nb = 0;
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$nb++;
		print '<tr class="oddeven"><td class="tdoverflowmax200">'.dol_escape_htmltag($obj->filename).'</td>';
		print '<td class="tdoverflowmax150">';
		if ($obj->socname !== null) {
			$socstatic->id = (int) $obj->fk_soc;
			$socstatic->name = $obj->socname;
			print $socstatic->getNomUrl(1);
		}
		print '</td>';
		print '<td>'.$langs->trans($obj->channel == DesignTraceClientAccess::CHANNEL_API ? 'DownloadChannelApi' : 'DownloadChannelWebportal').'</td>';
		print '<td class="right nowraponall">'.dol_print_date($db->jdate($obj->date_download), 'dayhour').'</td></tr>';
	}
	if (!$nb) {
		print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
	}
	print '</table></div>';
}

print '</div></div>';

// End of page
llxFooter();
$db->close();
