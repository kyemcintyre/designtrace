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
 *	\file       designtrace/componentrevision/tpl/linkedobjectblock.tpl.php
 *	\ingroup    designtrace
 *	\brief      Rows for component revisions in the "Linked objects" block of quotes, orders, invoices, BOMs, tickets
 *
 *	Links to revisions are stored with the type 'designtrace_componentrevision', for which Dolibarr
 *	loads <module>/<element>/tpl/linkedobjectblock.tpl.php. Each row says when the component has a
 *	newer revision than the one linked ("quoted against rev C, now rev D") or needs updating.
 */

// Protection to avoid direct call of template
if (empty($conf) || !is_object($conf)) {
	print "Error, template page can't be called as URL";
	exit(1);
}

'@phan-var-force CommonObject $object';
'@phan-var-force ComponentRevision[] $linkedObjectBlock';

print "<!-- BEGIN PHP TEMPLATE designtrace/componentrevision/tpl/linkedobjectblock.tpl.php -->\n";

$langs->load('designtrace@designtrace');
dol_include_once('/designtrace/class/componentlink.class.php');
dol_include_once('/designtrace/class/clientaccess.class.php');

$compids = array();
foreach ($linkedObjectBlock as $objectlink) {
	$compids[] = (int) $objectlink->fk_component;
}
$currentrevs = ComponentLink::getCurrentRevisionIds($db, $compids);
$compflags = ComponentLink::getComponentFlags($db, $compids);
$staticcomponent = new Component($db);

$ilink = 0;
foreach ($linkedObjectBlock as $key => $objectlink) {
	$ilink++;
	$trclass = 'oddeven';
	if ($ilink == count($linkedObjectBlock) && empty($noMoreLinkedObjectBlockAfter) && count($linkedObjectBlock) <= 1) {
		$trclass .= ' liste_sub_total';
	}
	$staticcomponent->fetch((int) $objectlink->fk_component);
	$compid = (int) $objectlink->fk_component;

	print '<tr class="'.$trclass.'" data-element="'.$objectlink->element.'" data-id="'.$objectlink->id.'">';
	print '<td class="linkedcol-element tdoverflowmax100">'.$langs->trans('ComponentRevision').'</td>';
	print '<td class="linkedcol-name nowraponall">'.$objectlink->getNomUrl(1).'</td>';
	print '<td class="linkedcol-ref tdoverflowmax150">'.dol_escape_htmltag((string) $staticcomponent->label);
	if (!empty($currentrevs[$compid]) && $currentrevs[$compid] > (int) $objectlink->id) {
		$current = new ComponentRevision($db);
		$current->fetch($currentrevs[$compid]);
		print '<br>'.img_warning().' <span class="small">'.$langs->trans('DocLinkNewerRevision', dol_escape_htmltag($current->rev)).'</span>';
	}
	if (!empty($compflags[$compid])) {
		print '<br>'.ComponentLink::getFlagBadge($compflags[$compid]);
	}
	if (in_array($object->element, array('propal', 'commande', 'ticket'), true) && !DesignTraceClientAccess::getLinkVisibility($db, (int) $objectlink->id, $object->element, (int) $object->id)) {
		print '<br><span class="opacitymedium small">'.img_picto('', 'eye-slash', 'class="pictofixedwidth"').$langs->trans('ClientAccessHiddenFromCustomer').'</span>';
	}
	print '</td>';
	print '<td class="linkedcol-date center">'.dol_print_date($objectlink->date_release, 'day').'</td>';
	print '<td class="linkedcol-amount right">-</td>';
	print '<td class="linkedcol-statut right">'.$objectlink->getLibStatut(3).'</td>';
	print '<td class="linkedcol-action right">';
	// A delivered order keeps the revisions it was delivered with ("Update delivered revision")
	if ($object->element == 'commande' && (int) $object->status === 3) {
		print img_picto($langs->transnoentitiesnoconv('DeliveredLinkLockedHelp'), 'lock', 'class="opacitymedium"');
	} else {
		print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=dellink&token='.newToken().'&dellinkid='.((int) $key).'">'.img_picto($langs->transnoentitiesnoconv("RemoveLink"), 'unlink').'</a>';
	}
	print '</td>';
	print "</tr>\n";
}

print "<!-- END PHP TEMPLATE -->\n";
