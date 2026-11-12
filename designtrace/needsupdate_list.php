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
 *	\file       designtrace/needsupdate_list.php
 *	\ingroup    designtrace
 *	\brief      Global list of current revisions flagged Needs update or Indirectly affected
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

dol_include_once('/designtrace/class/componentlink.class.php');
dol_include_once('/designtrace/class/design.class.php');

$langs->loadLangs(array('designtrace@designtrace', 'other'));

if ($user->socid > 0 || !isModEnabled('designtrace') || !$user->hasRight('designtrace', 'componentrevision', 'read')) {
	accessforbidden();
}

$search_design = GETPOSTINT('search_design');
$search_flag = GETPOSTINT('search_flag');
if (GETPOST('button_removefilter', 'alpha') || GETPOST('button_removefilter_x', 'alpha')) {
	$search_design = 0;
	$search_flag = 0;
}


/*
 * View
 */

$form = new Form($db);

$title = $langs->trans('NeedsUpdateListTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-designtrace page-list');

$filters = array();
if ($search_design > 0) {
	$filters[] = "pc.fk_design = ".((int) $search_design);
}
if (in_array($search_flag, array(ComponentLink::FLAG_NEEDS_UPDATE, ComponentLink::FLAG_INDIRECT), true)) {
	$filters[] = "l.flag_status = ".((int) $search_flag);
}
$rows = ComponentLink::fetchFlagged($db, implode(' AND ', $filters));

print load_fiche_titre($title.' <span class="badge marginleftonlyshort">'.count($rows).'</span>', '', 'fa-exclamation-triangle');
print '<div class="opacitymedium marginbottomonly">'.$langs->trans('NeedsUpdateListHelp').'</div>';

// Filters
$designs = array();
$resql = $db->query("SELECT rowid, ref, label FROM ".$db->prefix()."designtrace_design WHERE entity IN (".getEntity('design').") ORDER BY ref");
while ($resql && ($obj = $db->fetch_object($resql))) {
	$designs[(int) $obj->rowid] = $obj->ref.($obj->label ? ' - '.$obj->label : '');
}
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre_filter">';
print '<td>'.$form->selectarray('search_design', $designs, $search_design, $langs->trans('Design'), 0, 0, '', 0, 0, 0, '', 'maxwidth300').'</td>';
print '<td>'.$form->selectarray('search_flag', array(ComponentLink::FLAG_NEEDS_UPDATE => $langs->trans('FlagNeedsUpdate'), ComponentLink::FLAG_INDIRECT => $langs->trans('FlagIndirect')), $search_flag, $langs->trans('FlagStatus'), 0, 0, '', 0, 0, 0, '', 'maxwidth200').'</td>';
print '<td colspan="4"></td>';
print '<td class="right">'.$form->showFilterButtons().'</td>';
print '</tr>';

print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Component').'</td>';
print '<td>'.$langs->trans('Design').'</td>';
print '<td>'.$langs->trans('LinkCurrentRevision').'</td>';
print '<td>'.$langs->trans('LinkType').'</td>';
print '<td>'.$langs->trans('LinkChild').'</td>';
print '<td>'.$langs->trans('FlagNote').'</td>';
print '<td class="right">'.$langs->trans('FlagStatus').'</td>';
print '</tr>';

foreach ($rows as $row) {
	print '<tr class="oddeven">';
	print '<td class="tdoverflowmax200"><a href="'.dol_buildpath('/designtrace/component_card.php', 1).'?id='.((int) $row->parent_comp_id).'">'.img_picto('', 'fa-cube', 'class="pictofixedwidth"').dol_escape_htmltag($row->parent_comp_ref).'</a> <span class="opacitymedium">'.dol_escape_htmltag((string) $row->parent_comp_label).'</span></td>';
	print '<td class="nowraponall"><a href="'.dol_buildpath('/designtrace/design_card.php', 1).'?id='.((int) $row->parent_design_id).'">'.dol_escape_htmltag($row->parent_design_ref).'</a></td>';
	print '<td class="nowraponall"><a href="'.dol_buildpath('/designtrace/componentrevision_card.php', 1).'?id='.((int) $row->fk_parent).'">'.dol_escape_htmltag($row->parent_ref).'</a></td>';
	print '<td>'.ComponentLink::getTypeLabel((int) $row->link_type).'</td>';
	print '<td class="nowraponall"><a href="'.dol_buildpath('/designtrace/componentrevision_card.php', 1).'?id='.((int) $row->fk_child).'">'.dol_escape_htmltag($row->child_ref).'</a>';
	if ((int) $row->design_id !== (int) $row->parent_design_id) {
		print ' <span class="badge badge-secondary small">'.$langs->trans('ExternalComponent', dol_escape_htmltag($row->design_ref)).'</span>';
	}
	print '</td>';
	print '<td>'.dol_escape_htmltag((string) $row->flag_note).'</td>';
	print '<td class="right nowraponall">'.ComponentLink::getFlagBadge((int) $row->flag_status, (string) $row->flag_note).'</td>';
	print '</tr>';
}
if (empty($rows)) {
	print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
}
print '</table></div>';
print '</form>';

llxFooter();
$db->close();
