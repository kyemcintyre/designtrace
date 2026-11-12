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
 *	\file       designtrace/download_list.php
 *	\ingroup    designtrace
 *	\brief      Customer download log: every file downloaded through the Web portal or a signed
 *	            REST API link (llx_designtrace_download), with filters and CSV export
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

dol_include_once('/designtrace/class/clientaccess.class.php');
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

$langs->loadLangs(array('designtrace@designtrace', 'other', 'companies'));

if ($user->socid > 0 || !isModEnabled('designtrace') || !$user->hasRight('designtrace', 'componentrevision', 'read')) {
	accessforbidden();
}

$search_soc = GETPOSTINT('search_soc');
$search_channel = GETPOST('search_channel', 'aZ09');
$search_file = GETPOST('search_file', 'alphanohtml');
$search_from = dol_mktime(0, 0, 0, GETPOSTINT('search_frommonth'), GETPOSTINT('search_fromday'), GETPOSTINT('search_fromyear'));
$search_to = dol_mktime(23, 59, 59, GETPOSTINT('search_tomonth'), GETPOSTINT('search_today'), GETPOSTINT('search_toyear'));
if (GETPOST('button_removefilter', 'alpha') || GETPOST('button_removefilter_x', 'alpha')) {
	$search_soc = 0;
	$search_channel = '';
	$search_file = '';
	$search_from = '';
	$search_to = '';
}
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$page = max(0, GETPOSTINT('page'));
$export = (GETPOST('action', 'aZ09') == 'exportcsv');

$where = " WHERE dl.entity IN (".getEntity('designtrace_download').")";
if ($search_soc > 0) {
	$where .= " AND dl.fk_soc = ".((int) $search_soc);
}
if (in_array($search_channel, array(DesignTraceClientAccess::CHANNEL_WEBPORTAL, DesignTraceClientAccess::CHANNEL_API), true)) {
	$where .= " AND dl.channel = '".$db->escape($search_channel)."'";
}
if ($search_file !== '') {
	$where .= natural_search(array('dl.filename', 'r.ref', 'dl.actor'), $search_file);
}
if ($search_from) {
	$where .= " AND dl.date_download >= '".$db->idate($search_from)."'";
}
if ($search_to) {
	$where .= " AND dl.date_download <= '".$db->idate($search_to)."'";
}
$from = " FROM ".$db->prefix()."designtrace_download as dl";
$from .= " LEFT JOIN ".$db->prefix()."societe as s ON s.rowid = dl.fk_soc";
$from .= " LEFT JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = dl.fk_revision";

$nbtotal = 0;
$resql = $db->query("SELECT COUNT(dl.rowid) as nb".$from.$where);
if ($resql && ($obj = $db->fetch_object($resql))) {
	$nbtotal = (int) $obj->nb;
}
$sql = "SELECT dl.rowid, dl.date_download, dl.filename, dl.sha256, dl.fk_revision, r.ref as rev_ref, dl.fk_soc, s.nom as socname,";
$sql .= " dl.element, dl.fk_element, dl.channel, dl.actor, dl.ip, dl.useragent".$from.$where;
$sql .= " ORDER BY dl.date_download DESC, dl.rowid DESC";
if (!$export) {
	$sql .= $db->plimit($limit + 1, $page * $limit);
}
$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$rows = array();
while ($obj = $db->fetch_object($resql)) {
	$obj->date_download = $db->jdate($obj->date_download);
	$rows[] = $obj;
}

$channels = DesignTraceClientAccess::getChannels();
$channellabels = array(DesignTraceClientAccess::CHANNEL_WEBPORTAL => $langs->trans('DownloadChannelWebportal'), DesignTraceClientAccess::CHANNEL_API => $langs->trans('DownloadChannelApi'));

// CSV export of the filtered log
if ($export) {
	top_httphead('text/csv');
	header('Content-Disposition: attachment; filename="designtrace-downloads-'.dol_print_date(dol_now(), 'dayrfc').'.csv"');
	$out = fopen('php://output', 'w');
	fputcsv($out, array('date', 'file', 'sha256', 'revision', 'customer', 'document_type', 'document_id', 'channel', 'actor', 'ip', 'user_agent'));
	foreach ($rows as $row) {
		fputcsv($out, array(dol_print_date($row->date_download, 'dayhourrfc'), $row->filename, $row->sha256, $row->rev_ref, $row->socname, $row->element, $row->fk_element, $row->channel, $row->actor, $row->ip, $row->useragent));
	}
	fclose($out);
	exit;
}


/*
 * View
 */

$form = new Form($db);

$title = $langs->trans('DownloadLog');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-designtrace page-list');

$param = '';
foreach (array('search_soc' => $search_soc, 'search_channel' => $search_channel, 'search_file' => $search_file) as $key => $value) {
	if (!empty($value)) {
		$param .= '&'.$key.'='.urlencode((string) $value);
	}
}
if ($search_from) {
	$param .= '&search_fromday='.dol_print_date($search_from, '%d').'&search_frommonth='.dol_print_date($search_from, '%m').'&search_fromyear='.dol_print_date($search_from, '%Y');
}
if ($search_to) {
	$param .= '&search_today='.dol_print_date($search_to, '%d').'&search_tomonth='.dol_print_date($search_to, '%m').'&search_toyear='.dol_print_date($search_to, '%Y');
}
$exportbutton = dolGetButtonTitle($langs->trans('ExportCSV'), '', 'fa fa-file-csv', $_SERVER['PHP_SELF'].'?action=exportcsv&token='.newToken().$param);

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print_barre_liste($title, $page, $_SERVER['PHP_SELF'], $param, '', '', '', count($rows), $nbtotal, 'fa-download', 0, $exportbutton, '', $limit, 0, 0, 1);
print '<div class="opacitymedium marginbottomonly">'.$langs->trans('DownloadLogHelpAll').'</div>';

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre_filter">';
print '<td class="nowraponall">'.$form->selectDate($search_from ? $search_from : -1, 'search_from', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From')).'<br>'.$form->selectDate($search_to ? $search_to : -1, 'search_to', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('to')).'</td>';
print '<td><input type="text" class="flat maxwidth200" name="search_file" value="'.dol_escape_htmltag($search_file).'" placeholder="'.dol_escape_htmltag($langs->trans('DownloadSearchFile')).'"></td>';
print '<td></td>';
print '<td>'.$form->select_company($search_soc, 'search_soc', '', 1, 0, 0, array(), 0, 'maxwidth200').'</td>';
print '<td></td>';
print '<td>'.$form->selectarray('search_channel', $channellabels, $search_channel, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</td>';
print '<td colspan="2"></td>';
print '<td class="right">'.$form->showFilterButtons().'</td>';
print '</tr>';

print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Filename').'</td><td>'.$langs->trans('Revision').'</td><td>'.$langs->trans('Customer').'</td>';
print '<td>'.$langs->trans('Document').'</td><td>'.$langs->trans('DownloadChannel').'</td><td>'.$langs->trans('DownloadActor').'</td><td>'.$langs->trans('IPAddress').'</td><td></td>';
print '</tr>';

$socstatic = new Societe($db);
$revstatic = new ComponentRevision($db);
$i = 0;
foreach ($rows as $row) {
	if ($i++ >= $limit) {
		break;
	}
	print '<tr class="oddeven">';
	print '<td class="nowraponall">'.dol_print_date($row->date_download, 'dayhour').'</td>';
	print '<td class="tdoverflowmax250" title="'.dol_escape_htmltag('SHA-256 '.$row->sha256).'">'.dol_escape_htmltag($row->filename).'</td>';
	print '<td class="nowraponall">';
	if ($row->rev_ref !== null) {
		$revstatic->id = (int) $row->fk_revision;
		$revstatic->ref = $row->rev_ref;
		print $revstatic->getNomUrl(1);
	} else {
		print '<span class="opacitymedium">'.$langs->trans('Deleted').'</span>';
	}
	print '</td>';
	print '<td class="tdoverflowmax150">';
	if ($row->socname !== null) {
		$socstatic->id = (int) $row->fk_soc;
		$socstatic->name = $row->socname;
		print $socstatic->getNomUrl(1);
	}
	print '</td>';
	print '<td class="nowraponall">'.(isset($channels[$row->element]) ? $langs->trans($channels[$row->element]['label']).' ' : '').DesignTraceClientAccess::getDocumentNomUrl($db, (string) $row->element, (int) $row->fk_element).'</td>';
	print '<td>'.dol_escape_htmltag(isset($channellabels[$row->channel]) ? $channellabels[$row->channel] : $row->channel).'</td>';
	print '<td class="tdoverflowmax150">'.dol_escape_htmltag((string) $row->actor).'</td>';
	print '<td class="nowraponall" title="'.dol_escape_htmltag((string) $row->useragent).'">'.dol_escape_htmltag((string) $row->ip).'</td>';
	print '<td></td>';
	print '</tr>';
}
if (empty($rows)) {
	print '<tr class="oddeven"><td colspan="9"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}
print '</table></div>';
print '</form>';

llxFooter();
$db->close();
