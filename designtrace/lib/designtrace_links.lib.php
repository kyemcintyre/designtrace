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
 *	\file       designtrace/lib/designtrace_links.lib.php
 *	\ingroup    designtrace
 *	\brief      Display and actions of links between revisions and their flags, shared by the cards
 */

dol_include_once('/designtrace/class/componentlink.class.php');


/**
 * Handle the link actions of the revision card: addlink, deletelink, pinlink, pinalllinks,
 * confirm_acknowledge. Redirects after each one.
 *
 * @param	string				$action		Action
 * @param	ComponentRevision	$object		Revision shown (the parent)
 * @param	User				$user		Current user
 * @return	void
 */
function designtraceDoLinkActions($action, $object, $user)
{
	global $db, $langs;

	$canedit = $user->hasRight('designtrace', 'componentrevision', 'write') && !$object->isLocked();
	$canack = $user->hasRight('designtrace', 'componentrevision', 'acknowledgeflag');
	$url = dol_buildpath('/designtrace/componentrevision_card.php', 1).'?id='.((int) $object->id);
	$known = array('addlink', 'deletelink', 'pinlink', 'pinalllinks', 'confirm_acknowledge');
	if (!in_array($action, $known, true)) {
		return;
	}

	$link = null;
	if (GETPOSTINT('linkid') > 0) {
		$link = new ComponentLink($db);
		if ($link->fetch(GETPOSTINT('linkid')) <= 0 || (int) $link->fk_parent !== (int) $object->id) {
			accessforbidden();
		}
	}

	if ($action == 'addlink' && $canedit) {
		$link = new ComponentLink($db);
		$link->fk_parent = $object->id;
		$link->fk_child = GETPOSTINT('fk_child');
		$link->link_type = GETPOSTINT('link_type');
		$link->qty = (GETPOST('qty', 'alpha') === '' ? null : price2num(GETPOST('qty', 'alpha')));
		$link->note = GETPOST('note', 'alphanohtml');
		if ($link->create($user) > 0) {
			$child = new ComponentRevision($db);
			$child->fetch((int) $link->fk_child);
			setEventMessages($langs->trans('LinkAdded', $child->ref), null, 'mesgs');
		} else {
			setEventMessages($link->error, $link->errors, 'errors');
		}
	} elseif ($action == 'deletelink' && $canedit && $link) {
		$child = new ComponentRevision($db);
		$child->fetch((int) $link->fk_child);
		if ($link->delete($user) > 0) {
			setEventMessages($langs->trans('LinkDeleted', $child->ref), null, 'mesgs');
		} else {
			setEventMessages($link->error, $link->errors, 'errors');
		}
	} elseif (($action == 'pinlink' && $canedit && $link) || ($action == 'pinalllinks' && $canedit)) {
		// Pin the current revision of the child (one link, or all outdated ones)
		$count = 0;
		foreach (ComponentLink::fetchForParent($db, $object->id) as $row) {
			if (($action == 'pinlink' && (int) $row->rowid !== (int) $link->id) || !$row->current_rev_id || (int) $row->current_rev_id === (int) $row->fk_child) {
				continue;
			}
			$tmp = new ComponentLink($db);
			$tmp->fetch((int) $row->rowid);
			$tmp->fk_child = $row->current_rev_id;
			if ($tmp->update($user) > 0) {
				$count++;
				if ($action == 'pinlink') {
					setEventMessages($langs->trans('LinkUpdated', $row->comp_ref, $row->comp_ref.'-'.$row->current_rev), null, 'mesgs');
				}
			} else {
				setEventMessages($tmp->error, $tmp->errors, 'errors');
			}
		}
		if ($action == 'pinalllinks') {
			setEventMessages($langs->trans('LinksUpdated', $count), null, 'mesgs');
		}
	} elseif ($action == 'confirm_acknowledge' && GETPOST('confirm', 'alpha') == 'yes' && $canack && $link) {
		if ($link->acknowledge($user, GETPOST('acknote', 'alphanohtml')) > 0) {
			$child = new ComponentRevision($db);
			$child->fetch((int) $link->fk_child);
			setEventMessages($langs->trans('FlagAcknowledgedMsg', $child->ref), null, 'mesgs');
		} else {
			setEventMessages($link->error, $link->errors, 'errors');
		}
	} else {
		setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
	}

	header('Location: '.$url);
	exit;
}

/**
 * Confirmation popup of the revision card for acknowledging a flag
 *
 * @param	Form				$form		Form
 * @param	ComponentRevision	$object		Revision shown
 * @return	string						HTML ('' if not asked)
 */
function designtraceLinkConfirm($form, $object)
{
	global $db, $langs;

	if (GETPOST('action', 'aZ09') != 'acknowledge' || GETPOSTINT('linkid') <= 0) {
		return '';
	}
	$link = new ComponentLink($db);
	if ($link->fetch(GETPOSTINT('linkid')) <= 0 || (int) $link->fk_parent !== (int) $object->id) {
		return '';
	}
	$child = new ComponentRevision($db);
	$child->fetch((int) $link->fk_child);
	$formquestion = array(
		array('type' => 'text', 'name' => 'acknote', 'label' => $langs->trans('AcknowledgeNote'), 'value' => '', 'morecss' => 'minwidth300'),
	);
	$url = dol_buildpath('/designtrace/componentrevision_card.php', 1).'?id='.((int) $object->id).'&linkid='.((int) $link->id);
	return $form->formconfirm($url, $langs->trans('Acknowledge'), $langs->trans('ConfirmAcknowledge', $object->ref, (string) $link->flag_note), 'confirm_acknowledge', $formquestion, 'yes', 1, 230, 600);
}

/**
 * Number of links of a revision that pin an older revision than their child's current one
 *
 * @param	ComponentRevision	$object		Revision
 * @return	int
 */
function designtraceCountOutdatedLinks($object)
{
	global $db;

	$count = 0;
	foreach (ComponentLink::fetchForParent($db, $object->id) as $row) {
		if ($row->current_rev_id && (int) $row->current_rev_id !== (int) $row->fk_child) {
			$count++;
		}
	}
	return $count;
}

/**
 * Print the links block of a revision card: its children, flags, and the add form for drafts
 *
 * @param	ComponentRevision	$object		Revision (parent)
 * @param	Form				$form		Form
 * @return	void
 */
function designtracePrintRevisionLinks($object, $form)
{
	global $db, $langs, $user;

	$langs->load('designtrace@designtrace');
	$canedit = $user->hasRight('designtrace', 'componentrevision', 'write') && !$object->isLocked();
	$canack = $user->hasRight('designtrace', 'componentrevision', 'acknowledgeflag');
	$url = dol_buildpath('/designtrace/componentrevision_card.php', 1).'?id='.((int) $object->id);
	$component = $object->fetchComponentChecked();
	$rows = ComponentLink::fetchForParent($db, $object->id);

	$outdated = 0;
	foreach ($rows as $row) {
		if ($row->current_rev_id && (int) $row->current_rev_id !== (int) $row->fk_child) {
			$outdated++;
		}
	}
	$morehtmlright = '';
	if ($canedit && $outdated) {
		$morehtmlright = dolGetButtonTitle($langs->trans('LinkUpdateAllToCurrent'), '', 'fa fa-sync', $url.'&action=pinalllinks&token='.newToken());
	}

	print '<br>';
	print load_fiche_titre($langs->trans('LinksOfRevision', $object->ref), $morehtmlright, 'fa-link');
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$form->textwithpicto($langs->trans('LinkType'), $langs->trans('LinkTypeHelp')).'</td>';
	print '<td>'.$langs->trans('LinkChild').'</td>';
	print '<td>'.$langs->trans('LinkPinnedRevision').'</td>';
	print '<td class="right">'.$langs->trans('Qty').'</td>';
	print '<td>'.$langs->trans('FlagStatus').'</td>';
	print '<td></td>';
	print '</tr>';

	foreach ($rows as $row) {
		print '<tr class="oddeven">';
		print '<td class="nowraponall">'.ComponentLink::getTypeLabel((int) $row->link_type).'</td>';

		print '<td class="tdoverflowmax250">';
		print '<a href="'.dol_buildpath('/designtrace/component_card.php', 1).'?id='.((int) $row->comp_id).'">'.img_picto('', 'fa-cube', 'class="pictofixedwidth"').dol_escape_htmltag($row->comp_ref).'</a>';
		print ' <span class="opacitymedium">'.dol_escape_htmltag((string) $row->comp_label).'</span>';
		if (is_object($component) && (int) $row->design_id !== (int) $component->fk_design) {
			print ' <span class="badge badge-secondary small">'.$langs->trans('ExternalComponent', dol_escape_htmltag($row->design_ref)).'</span>';
		}
		if ($row->note) {
			print '<br><span class="small opacitymedium">'.dol_escape_htmltag($row->note).'</span>';
		}
		print '</td>';

		print '<td class="nowraponall">';
		print '<a href="'.dol_buildpath('/designtrace/componentrevision_card.php', 1).'?id='.((int) $row->fk_child).'">'.dol_escape_htmltag($row->child_ref).'</a>';
		if ($row->current_rev_id && (int) $row->current_rev_id !== (int) $row->fk_child) {
			print ' <span class="warning">'.img_warning('', '', 'paddingleft').' '.$langs->trans('LinkNewerAvailable', dol_escape_htmltag($row->current_rev)).'</span>';
			if ($canedit) {
				print ' <a class="button smallpaddingimp" href="'.$url.'&action=pinlink&linkid='.((int) $row->rowid).'&token='.newToken().'">'.$langs->trans('LinkUpdateToCurrent', dol_escape_htmltag($row->current_rev)).'</a>';
			}
		}
		print '</td>';

		print '<td class="right">'.((int) $row->link_type === ComponentLink::TYPE_USES ? designtraceFormatQty($row->qty) : '').'</td>';

		print '<td class="nowraponall">';
		if ((int) $row->flag_status !== ComponentLink::FLAG_NONE) {
			$title = (string) $row->flag_note;
			if ((int) $row->flag_status === ComponentLink::FLAG_ACKNOWLEDGED && $row->ack_note) {
				$title .= ' - '.$row->ack_note;
			}
			print ComponentLink::getFlagBadge((int) $row->flag_status, $title);
			print ' <span class="small opacitymedium">'.dol_escape_htmltag((string) $row->flag_note).'</span>';
		}
		print '</td>';

		print '<td class="right nowraponall">';
		if ((int) $row->flag_status === ComponentLink::FLAG_NEEDS_UPDATE && $canack) {
			print '<a class="reposition" href="'.$url.'&action=acknowledge&linkid='.((int) $row->rowid).'&token='.newToken().'" title="'.dol_escape_htmltag($langs->trans('Acknowledge')).'">'.img_picto($langs->trans('Acknowledge'), 'fa-check-circle').'</a> ';
		}
		if ($canedit) {
			print '<a class="reposition" href="'.$url.'&action=deletelink&linkid='.((int) $row->rowid).'&token='.newToken().'">'.img_delete($langs->trans('Delete')).'</a>';
		}
		print '</td>';
		print '</tr>';
	}
	if (empty($rows)) {
		print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
	}
	print '</table>';
	print '</div>';

	if ($canedit && is_object($component)) {
		designtracePrintAddLinkForm($object, $component, $form, $url);
	}
}

/**
 * Print the form to add a link to a draft revision. The child list holds the released
 * revisions of every other component, current revision first and preselected per component.
 *
 * @param	ComponentRevision	$object		Revision (parent)
 * @param	Component			$component	Its component
 * @param	Form				$form		Form
 * @param	string				$url		Card URL
 * @return	void
 */
function designtracePrintAddLinkForm($object, $component, $form, $url)
{
	global $db, $langs;

	$sql = "SELECT r.rowid, r.ref, r.rev, c.ref as comp_ref, c.label as comp_label, c.type as comp_type, d.ref as design_ref";
	$sql .= " FROM ".$db->prefix()."designtrace_componentrevision as r";
	$sql .= " INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
	$sql .= " INNER JOIN ".$db->prefix()."designtrace_design as d ON d.rowid = c.fk_design";
	$sql .= " WHERE r.status = ".ComponentRevision::STATUS_RELEASED;
	$sql .= " AND c.rowid <> ".((int) $component->id);
	$sql .= " AND c.entity IN (".getEntity('component').")";
	$sql .= " ORDER BY c.ref ASC, r.rowid DESC";
	$options = array();
	$seen = array();
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$iscurrent = !isset($seen[$obj->comp_ref]);
		$seen[$obj->comp_ref] = true;
		$label = $obj->ref.' - '.$obj->comp_label.' ['.$obj->design_ref.']'.($iscurrent ? '' : ' ('.$langs->trans('NotCurrentRevision').')');
		$options[(int) $obj->rowid] = array('label' => $label, 'data-html' => dol_escape_htmltag($label), 'class' => $iscurrent ? '' : 'opacitymedium');
	}

	$types = array(ComponentLink::TYPE_REFERENCES => $langs->trans('LinkTypeReferences'));
	if ((int) $component->type === Component::TYPE_ASSEMBLY) {
		$types = array(ComponentLink::TYPE_USES => $langs->trans('LinkTypeUses')) + $types;
	} elseif ((int) $component->type === Component::TYPE_DRAWING) {
		$types = array(ComponentLink::TYPE_DEPICTS => $langs->trans('LinkTypeDepicts')) + $types;
	}

	print '<form method="POST" action="'.$url.'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="addlink">';
	print '<table class="noborder centpercent"><tr class="oddeven">';
	print '<td class="nowraponall">'.$form->selectarray('link_type', $types, GETPOSTINT('link_type'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth100').'</td>';
	print '<td>'.$form->selectarray('fk_child', $options, GETPOSTINT('fk_child'), 1, 0, 0, '', 0, 0, 0, '', 'minwidth300 maxwidth500 widthcentpercentminusx').'</td>';
	print '<td class="nowraponall"><input type="text" class="flat width50 right" name="qty" placeholder="'.dol_escape_htmltag($langs->trans('Qty')).'" title="'.dol_escape_htmltag($langs->trans('Qty').' ('.$langs->trans('LinkTypeUses').')').'"></td>';
	print '<td><input type="text" class="flat minwidth150" name="note" maxlength="255" placeholder="'.dol_escape_htmltag($langs->trans('Note')).'"></td>';
	print '<td class="right"><input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('AddLink')).'"></td>';
	print '</tr></table>';
	print '</form>';
}

/**
 * Print the "Where used" block of a component card: revisions of other components linking to
 * any of its revisions, current ones with their flag
 *
 * @param	Component	$component	Component
 * @return	void
 */
function designtracePrintWhereUsed($component)
{
	global $db, $langs, $form;

	$langs->load('designtrace@designtrace');
	$rows = ComponentLink::fetchWhereUsed($db, $component->id);

	print '<br>';
	print load_fiche_titre($form->textwithpicto($langs->trans('WhereUsed'), $langs->trans('WhereUsedHelp')), '', 'fa-sitemap');
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('ParentRevision').'</td>';
	print '<td>'.$langs->trans('LinkType').'</td>';
	print '<td>'.$langs->trans('LinkPinnedRevision').'</td>';
	print '<td>'.$langs->trans('FlagStatus').'</td>';
	print '</tr>';
	foreach ($rows as $row) {
		print '<tr class="oddeven'.($row->is_current ? '' : ' opacitymedium').'">';
		print '<td class="tdoverflowmax250"><a href="'.dol_buildpath('/designtrace/componentrevision_card.php', 1).'?id='.((int) $row->fk_parent).'">'.img_picto('', 'fa-code-branch', 'class="pictofixedwidth"').dol_escape_htmltag($row->parent_ref).'</a>';
		print ' <span class="opacitymedium">'.dol_escape_htmltag((string) $row->parent_comp_label).'</span>';
		if ((int) $row->parent_design_id !== (int) $component->fk_design) {
			print ' <span class="badge badge-secondary small">'.$langs->trans('ExternalComponent', dol_escape_htmltag($row->parent_design_ref)).'</span>';
		}
		if (!$row->is_current) {
			print ' <span class="small">('.$langs->trans('NotCurrentRevision').')</span>';
		}
		print '</td>';
		print '<td>'.ComponentLink::getTypeLabel((int) $row->link_type).((int) $row->link_type === ComponentLink::TYPE_USES ? ' &times; '.designtraceFormatQty($row->qty) : '').'</td>';
		print '<td class="nowraponall">'.dol_escape_htmltag($row->child_ref).'</td>';
		print '<td class="nowraponall">'.ComponentLink::getFlagBadge((int) $row->flag_status, (string) $row->flag_note).'</td>';
		print '</tr>';
	}
	if (empty($rows)) {
		print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
	}
	print '</table>';
	print '</div>';
}

/**
 * Print a warning box when a component's current revision is flagged
 *
 * @param	Component	$component	Component
 * @return	void
 */
function designtracePrintComponentFlag($component)
{
	global $db, $langs;

	$flags = ComponentLink::getComponentFlags($db, array($component->id));
	if (empty($flags[$component->id])) {
		return;
	}
	$langs->load('designtrace@designtrace');
	$notes = array();
	foreach (ComponentLink::fetchFlagged($db, "pc.rowid = ".((int) $component->id)) as $row) {
		$notes[] = dol_escape_htmltag((string) $row->flag_note);
	}
	$key = ($flags[$component->id] === ComponentLink::FLAG_NEEDS_UPDATE ? 'FlagSummaryNeedsUpdate' : 'FlagSummaryIndirect');
	print info_admin($langs->trans($key, $component->ref).($notes ? ' ('.implode(', ', $notes).')' : ''), 0, 0, $flags[$component->id] === ComponentLink::FLAG_NEEDS_UPDATE ? 'warning' : '1');
}

/**
 * Print, on a design card, the flag roll-up and the external dependencies: components of other
 * designs that the current revisions of this design's components link to
 *
 * @param	Design	$design		Design
 * @return	void
 */
function designtracePrintDesignLinks($design)
{
	global $db, $langs, $form;

	$langs->load('designtrace@designtrace');

	$compids = array();
	$resql = $db->query("SELECT rowid FROM ".$db->prefix()."designtrace_component WHERE fk_design = ".((int) $design->id));
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$compids[] = (int) $obj->rowid;
	}
	$flags = ComponentLink::getComponentFlags($db, $compids);
	$direct = count(array_filter($flags, function ($f) {
		return $f === ComponentLink::FLAG_NEEDS_UPDATE;
	}));
	if ($flags) {
		print info_admin($langs->trans('DesignFlagSummary', $direct, count($flags) - $direct).' <a href="'.dol_buildpath('/designtrace/needsupdate_list.php', 1).'?search_design='.((int) $design->id).'">'.$langs->trans('NeedsUpdateList').'</a>', 0, 0, $direct ? 'warning' : '1');
	}

	// External dependencies: links of current revisions to components of other designs
	$rows = array();
	foreach (ComponentLink::getCurrentRevisionIds($db, $compids) as $currentrevid) {
		foreach (ComponentLink::fetchForParent($db, $currentrevid) as $row) {
			if ((int) $row->design_id !== (int) $design->id) {
				$rows[] = $row;
			}
		}
	}

	print '<br>';
	print load_fiche_titre($form->textwithpicto($langs->trans('ExternalDependencies'), $langs->trans('ExternalDependenciesHelp')), '', 'fa-external-link-alt');
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('Component').'</td>';
	print '<td>'.$langs->trans('Design').'</td>';
	print '<td>'.$langs->trans('LinkPinnedRevision').'</td>';
	print '<td>'.$langs->trans('ParentRevision').'</td>';
	print '<td>'.$langs->trans('FlagStatus').'</td>';
	print '</tr>';
	foreach ($rows as $row) {
		print '<tr class="oddeven">';
		print '<td class="tdoverflowmax200"><a href="'.dol_buildpath('/designtrace/component_card.php', 1).'?id='.((int) $row->comp_id).'">'.img_picto('', 'fa-cube', 'class="pictofixedwidth"').dol_escape_htmltag($row->comp_ref).'</a> <span class="opacitymedium">'.dol_escape_htmltag((string) $row->comp_label).'</span></td>';
		print '<td><a href="'.dol_buildpath('/designtrace/design_card.php', 1).'?id='.((int) $row->design_id).'">'.dol_escape_htmltag($row->design_ref).'</a></td>';
		print '<td class="nowraponall">'.dol_escape_htmltag($row->child_ref).($row->current_rev_id && (int) $row->current_rev_id !== (int) $row->fk_child ? ' '.img_warning($langs->trans('LinkNewerAvailable', $row->current_rev)) : '').'</td>';
		print '<td class="nowraponall"><a href="'.dol_buildpath('/designtrace/componentrevision_card.php', 1).'?id='.((int) $row->fk_parent).'">'.dol_escape_htmltag($row->parent_ref).'</a> <span class="opacitymedium">'.ComponentLink::getTypeLabel((int) $row->link_type).'</span></td>';
		print '<td class="nowraponall">'.ComponentLink::getFlagBadge((int) $row->flag_status, (string) $row->flag_note).'</td>';
		print '</tr>';
	}
	if (empty($rows)) {
		print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
	}
	print '</table>';
	print '</div>';
}

/**
 * Format a link quantity without trailing zeros (4, 2.5), with the user's decimal separator
 *
 * @param	float|string|null	$qty	Quantity
 * @return	string
 */
function designtraceFormatQty($qty)
{
	global $langs;

	$text = rtrim(rtrim(number_format((float) $qty, 8, '.', ''), '0'), '.');
	return str_replace('.', $langs->transnoentitiesnoconv('SeparatorDecimal'), $text);
}
