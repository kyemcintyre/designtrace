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
 *	\file       designtrace/changeorder/tpl/linkedobjectblock.tpl.php
 *	\ingroup    designtrace
 *	\brief      Rows for change orders (ECO) in the "Linked objects" block of other objects (tickets, ...)
 *
 *	Links to ECOs are stored with the type 'designtrace_changeorder' (CommonObject::getElementType()),
 *	for which Dolibarr loads <module>/<element>/tpl/linkedobjectblock.tpl.php.
 */

// Protection to avoid direct call of template
if (empty($conf) || !is_object($conf)) {
	print "Error, template page can't be called as URL";
	exit(1);
}

'@phan-var-force CommonObject $object';
'@phan-var-force ChangeOrder[] $linkedObjectBlock';

print "<!-- BEGIN PHP TEMPLATE designtrace/changeorder/tpl/linkedobjectblock.tpl.php -->\n";

$langs->load('designtrace@designtrace');

$linkedObjectBlock = dol_sort_array($linkedObjectBlock, 'date_creation', 'desc', 0, 0, 1);

$ilink = 0;
foreach ($linkedObjectBlock as $key => $objectlink) {
	$ilink++;

	$trclass = 'oddeven';
	if ($ilink == count($linkedObjectBlock) && empty($noMoreLinkedObjectBlockAfter) && count($linkedObjectBlock) <= 1) {
		$trclass .= ' liste_sub_total';
	}
	print '<tr class="'.$trclass.'" data-element="'.$objectlink->element.'" data-id="'.$objectlink->id.'">';
	print '<td class="linkedcol-element tdoverflowmax100">'.$langs->trans('ChangeOrder').'</td>';
	print '<td class="linkedcol-name tdoverflowmax150">'.$objectlink->getNomUrl(1).'</td>';
	print '<td class="linkedcol-ref tdoverflowmax150" title="'.dol_escape_htmltag($objectlink->label).'">'.dol_escape_htmltag($objectlink->label).'</td>';
	print '<td class="linkedcol-date center">'.dol_print_date($objectlink->date_creation, 'day').'</td>';
	print '<td class="linkedcol-amount right">-</td>';
	print '<td class="linkedcol-statut right">'.$objectlink->getLibStatut(3).'</td>';
	// Removing the link is checked by the page's dellink action, as for core objects
	print '<td class="linkedcol-action right"><a class="reposition" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=dellink&token='.newToken().'&dellinkid='.((int) $key).'">'.img_picto($langs->transnoentitiesnoconv("RemoveLink"), 'unlink').'</a></td>';
	print "</tr>\n";
}

print "<!-- END PHP TEMPLATE -->\n";
