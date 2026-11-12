<?php
/* Copyright (C) 2026		Kye McIntyre
 * Copyright (C) 2025       Frédéric France         <frederic.france@free.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    designtrace/lib/designtrace_changeorder.lib.php
 * \ingroup designtrace
 * \brief   Library files with common functions for ChangeOrder
 */

/**
 * Prepare array of tabs for ChangeOrder
 *
 * @param	ChangeOrder	$object					ChangeOrder
 * @return 	array<array{string,string,string}>	Array of tabs
 */
function changeorderPrepareHead($object)
{
	global $db, $langs, $conf;

	$langs->load("designtrace@designtrace");

// BEGIN MODULEBUILDER TABFLAG NOTE
	$showtabofpagenote = 1;
	// END MODULEBUILDER TABFLAG NOTE
	// BEGIN MODULEBUILDER TABFLAG DOCUMENT
	$showtabofpagedocument = 1;
	// END MODULEBUILDER TABFLAG DOCUMENT
	// BEGIN MODULEBUILDER TABFLAG AGENDA
	$showtabofpageagenda = 1;
	// END MODULEBUILDER TABFLAG AGENDA

	$h = 0;
	$head = array();

	$head[$h][0] = dolBuildUrl(dol_buildpath("/designtrace/changeorder_card.php", 1), ['id' => $object->id]);
	$head[$h][1] = $langs->trans("ChangeOrder");
	$head[$h][2] = 'card';
	$h++;

// BEGIN MODULEBUILDER TAB NOTE
	if ($showtabofpagenote) {
		if (isset($object->fields['note_public']) || isset($object->fields['note_private'])) {
			$nbNote = 0;
			if (!empty($object->note_private)) {
				$nbNote++;
			}
			if (!empty($object->note_public)) {
				$nbNote++;
			}
			$head[$h][0] = dolBuildUrl(dol_buildpath('/designtrace/changeorder_note.php', 1), ['id' => $object->id]);
			$head[$h][1] = $langs->trans('Notes');
			if ($nbNote > 0) {
				$head[$h][1] .= (!getDolGlobalInt('MAIN_OPTIMIZEFORTEXTBROWSER') ? '<span class="badge marginleftonlyshort">'.$nbNote.'</span>' : '');
			}
			$head[$h][2] = 'note';
			$h++;
		}
	}
	// END MODULEBUILDER TAB NOTE

	// BEGIN MODULEBUILDER TAB DOCUMENT
	if ($showtabofpagedocument) {
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/link.class.php';
		$upload_dir = $conf->designtrace->dir_output."/changeorder/".dol_sanitizeFileName($object->ref);
		$nbFiles = count(dol_dir_list($upload_dir, 'files', 0, '', '(\.meta|_preview.*\.png)$'));
		$nbLinks = Link::count($db, $object->element, $object->id);
		$head[$h][0] = dolBuildUrl(dol_buildpath("/designtrace/changeorder_document.php", 1), ['id' => $object->id]);
		$head[$h][1] = $langs->trans('Documents');
		if (($nbFiles + $nbLinks) > 0) {
			$head[$h][1] .= '<span class="badge marginleftonlyshort">'.($nbFiles + $nbLinks).'</span>';
		}
		$head[$h][2] = 'document';
		$h++;
	}
	// END MODULEBUILDER TAB DOCUMENT

	// BEGIN MODULEBUILDER TAB AGENDA
	if ($showtabofpageagenda) {
		$head[$h][0] = dolBuildUrl(dol_buildpath("/designtrace/changeorder_agenda.php", 1), ['id' => $object->id]);
		$head[$h][1] = $langs->trans("Events");
		$head[$h][2] = 'agenda';
		$h++;
	}
	// END MODULEBUILDER TAB AGENDA

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@designtrace:/designtrace/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@designtrace:/designtrace/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, $object, $head, $h, 'changeorder@designtrace');

	complete_head_from_modules($conf, $langs, $object, $head, $h, 'changeorder@designtrace', 'remove');

	return $head;
}

/**
 * Handle the ECO card's own actions (redirects when it handles one): workflow steps and the
 * affected components list
 *
 * @param	string		$action		Action
 * @param	ChangeOrder	$object		ECO shown
 * @param	User		$user		User
 * @return	string					Action to go on with
 */
function designtraceDoEcoActions($action, $object, $user)
{
	global $langs;

	if (empty($object->id)) {
		return $action;
	}
	$selfurl = dol_buildpath('/designtrace/changeorder_card.php', 1).'?id='.((int) $object->id);
	$canwrite = $user->hasRight('designtrace', 'changeorder', 'write');
	$steps = array(
		'eco_approve' => array('approve', $user->hasRight('designtrace', 'changeorder', 'approve'), 'EcoApprovedMessage'),
		'eco_setdraft' => array('setDraft', $canwrite, 'EcoSetDraftMessage'),
		'eco_implement' => array('setImplemented', $canwrite, 'EcoImplementedMessage'),
		'eco_close' => array('close', $canwrite, 'EcoClosedMessage'),
		'eco_cancel' => array('cancel', $canwrite, 'EcoCanceledMessage'),
		'eco_reopen' => array('reopen', $canwrite, 'EcoReopenedMessage'),
	);
	if (isset($steps[$action])) {
		list($method, $allowed, $message) = $steps[$action];
		if (!$allowed) {
			setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
		} elseif ($object->$method($user) > 0) {
			setEventMessages($langs->trans($message, $object->ref), null, 'mesgs');
		} elseif (!empty($object->error) || !empty($object->errors)) {
			setEventMessages($object->error, $object->errors, 'errors');
		}
		header('Location: '.$selfurl);
		exit;
	}

	if (in_array($action, array('eco_addcomponent', 'eco_removecomponent'), true)) {
		if (!$canwrite) {
			setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
		} elseif ($action == 'eco_addcomponent') {
			$result = $object->addAffectedComponent($user, GETPOSTINT('fk_component'), GETPOST('note', 'alphanohtml'));
			if ($result > 0) {
				setEventMessages($langs->trans('EcoComponentAdded'), null, 'mesgs');
			} elseif ($result == 0) {
				setEventMessages($langs->trans('EcoComponentAlreadyListed'), null, 'warnings');
			} else {
				setEventMessages($object->error, $object->errors, 'errors');
			}
		} elseif ($object->removeAffectedComponent($user, GETPOSTINT('lineid')) > 0) {
			setEventMessages($langs->trans('EcoComponentRemoved'), null, 'mesgs');
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
		}
		header('Location: '.$selfurl.'#affected');
		exit;
	}

	return $action;
}

/**
 * Workflow buttons of the ECO card
 *
 * @param	ChangeOrder	$object		ECO shown
 * @param	User		$user		User
 * @return	void
 */
function designtracePrintEcoButtons($object, $user)
{
	global $langs;

	$url = dol_buildpath('/designtrace/changeorder_card.php', 1).'?id='.((int) $object->id).'&token='.newToken().'&action=';
	$canwrite = $user->hasRight('designtrace', 'changeorder', 'write');
	$status = (int) $object->status;

	if ($status === ChangeOrder::STATUS_DRAFT) {
		$reason = '';
		if (!$user->hasRight('designtrace', 'changeorder', 'approve')) {
			$reason = $langs->trans('NotEnoughPermissions');
		} elseif ($object->countAffectedComponents() <= 0) {
			$reason = $langs->trans('ErrorEcoNoAffectedComponents', $object->ref);
		}
		print dolGetButtonAction($reason, $langs->trans('EcoApprove'), 'default', $url.'eco_approve', '', $reason === '');
	}
	if ($status === ChangeOrder::STATUS_APPROVED) {
		print dolGetButtonAction('', $langs->trans('EcoSetImplemented'), 'default', $url.'eco_implement', '', $canwrite);
		print dolGetButtonAction('', $langs->trans('SetToDraft'), 'default', $url.'eco_setdraft', '', $canwrite);
	}
	if ($status === ChangeOrder::STATUS_IMPLEMENTED) {
		print dolGetButtonAction('', $langs->trans('EcoClose'), 'default', $url.'eco_close', '', $canwrite);
	}
	if (in_array($status, array(ChangeOrder::STATUS_IMPLEMENTED, ChangeOrder::STATUS_CLOSED, ChangeOrder::STATUS_CANCELED), true)) {
		print dolGetButtonAction('', $langs->trans('ReOpen'), 'default', $url.'eco_reopen', '', $canwrite);
	}
	if (in_array($status, array(ChangeOrder::STATUS_DRAFT, ChangeOrder::STATUS_APPROVED), true)) {
		print dolGetButtonAction('', $langs->trans('EcoCancel'), 'default', $url.'eco_cancel', '', $canwrite);
	}
}

/**
 * Affected components of an ECO: the list, the revisions made under the ECO for each, the add
 * form and the components flagged "Needs update" as suggestions
 *
 * @param	ChangeOrder	$object		ECO shown
 * @param	Form		$form		Form helper
 * @return	void
 */
function designtracePrintEcoAffected($object, $form)
{
	global $db, $langs, $user;

	dol_include_once('/designtrace/class/component.class.php');
	dol_include_once('/designtrace/class/componentlink.class.php');

	$canedit = $object->canEditAffected() && $user->hasRight('designtrace', 'changeorder', 'write');
	$selfurl = dol_buildpath('/designtrace/changeorder_card.php', 1).'?id='.((int) $object->id);
	$rows = $object->fetchAffectedComponents();
	$revsbycomp = array();
	foreach ($object->fetchRevisions() as $rev) {
		$revsbycomp[(int) $rev->fk_component][] = $rev;
	}
	$compids = array_map(function ($r) {
		return (int) $r->fk_component;
	}, $rows);
	$flags = ComponentLink::getComponentFlags($db, $compids);
	$component = new Component($db);
	$revision = new ComponentRevision($db);

	print '<a name="affected"></a>';
	print load_fiche_titre($form->textwithpicto($langs->trans('EcoAffectedComponents'), $langs->trans('EcoAffectedComponentsHelp')), '', 'fa-cubes');
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Component').'</td><td>'.$langs->trans('Design').'</td><td>'.$langs->trans('EcoRevisionsMade').'</td><td>'.$langs->trans('Note').'</td><td></td></tr>';
	foreach ($rows as $row) {
		$component->id = (int) $row->fk_component;
		$component->ref = $row->comp_ref;
		$component->label = $row->comp_label;
		$component->type = $row->comp_type;
		$component->status = $row->comp_status;
		print '<tr class="oddeven">';
		print '<td class="nowraponall">'.$component->getNomUrl(1).' <span class="opacitymedium">'.dol_escape_htmltag((string) $row->comp_label).'</span>';
		if (!empty($flags[(int) $row->fk_component])) {
			print ' '.ComponentLink::getFlagBadge($flags[(int) $row->fk_component]);
		}
		print '</td>';
		print '<td class="nowraponall">'.($row->design_id ? '<a href="'.dol_buildpath('/designtrace/design_card.php', 1).'?id='.((int) $row->design_id).'">'.dol_escape_htmltag($row->design_ref).'</a>' : '').'</td>';
		print '<td>';
		if (empty($revsbycomp[(int) $row->fk_component])) {
			print '<span class="opacitymedium">'.$langs->trans('EcoNoRevisionYet').'</span>';
		} else {
			$parts = array();
			foreach ($revsbycomp[(int) $row->fk_component] as $rev) {
				$revision->id = (int) $rev->rowid;
				$revision->ref = $rev->ref;
				$revision->status = (int) $rev->status;
				$parts[] = $revision->getNomUrl(1).' '.$revision->getLibStatut(5);
			}
			print implode('<br>', $parts);
		}
		print '</td>';
		print '<td class="tdoverflowmax300">'.dol_escape_htmltag((string) $row->note).'</td>';
		print '<td class="right nowraponall">';
		if ($canedit && empty($revsbycomp[(int) $row->fk_component])) {
			print '<a class="reposition" href="'.$selfurl.'&action=eco_removecomponent&lineid='.((int) $row->rowid).'&token='.newToken().'">'.img_delete($langs->trans('Remove')).'</a>';
		}
		print '</td>';
		print '</tr>';
	}
	if (empty($rows)) {
		print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
	}
	if ($canedit) {
		print '<tr class="oddeven"><td colspan="5">';
		print '<form method="POST" action="'.$selfurl.'#affected" class="inline-block">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="eco_addcomponent">';
		print $form->selectForForms('Component:designtrace/class/component.class.php:0:(t.status:=:1)', 'fk_component', 0, 1, '', '', 'minwidth300');
		print ' <input type="text" class="flat minwidth200" name="note" placeholder="'.dol_escape_htmltag($langs->trans('Note')).'">';
		print ' <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('Add')).'">';
		print '</form>';
		print '</td></tr>';
	}
	print '</table>';
	print '</div>';

	// Needs update flags suggest the list
	if ($canedit) {
		$suggested = $object->fetchSuggestedComponents();
		if (!empty($suggested)) {
			print '<div class="opacitymedium small paddingtop">'.$langs->trans('EcoSuggestedComponents').'</div>';
			print '<table class="noborder centpercent">';
			foreach ($suggested as $sug) {
				print '<tr class="oddeven"><td class="nowraponall">'.ComponentLink::getFlagBadge(ComponentLink::FLAG_NEEDS_UPDATE).' <a href="'.dol_buildpath('/designtrace/component_card.php', 1).'?id='.((int) $sug->comp_id).'">'.dol_escape_htmltag($sug->comp_ref).'</a> <span class="opacitymedium">'.dol_escape_htmltag((string) $sug->comp_label).'</span></td>';
				print '<td class="tdoverflowmax300">'.dol_escape_htmltag((string) $sug->flag_note).'</td>';
				print '<td class="right"><form method="POST" action="'.$selfurl.'#affected" class="inline-block">';
				print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="eco_addcomponent">';
				print '<input type="hidden" name="fk_component" value="'.((int) $sug->comp_id).'"><input type="hidden" name="note" value="'.dol_escape_htmltag((string) $sug->flag_note).'">';
				print '<input type="submit" class="button smallpaddingimp small" value="'.dol_escape_htmltag($langs->trans('Add')).'">';
				print '</form></td></tr>';
			}
			print '</table>';
		}
	}
}

/**
 * Prefill the create form from a ticket (?origin=ticket&originid=ID): customer, summary,
 * description, reason "defect". The ticket is linked and the components of the revisions linked
 * to it are added when the ECO is created.
 *
 * @return	int		Ticket id, 0 if none
 */
function designtraceEcoPrefillFromTicket()
{
	global $db, $user;

	if (GETPOST('origin', 'aZ09') != 'ticket' || GETPOSTINT('originid') <= 0 || !isModEnabled('ticket') || !$user->hasRight('ticket', 'read')) {
		return 0;
	}
	require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';
	$ticket = new Ticket($db);
	if ($ticket->fetch(GETPOSTINT('originid')) <= 0) {
		return 0;
	}
	if (!GETPOSTISSET('label')) {
		$_GET['label'] = $ticket->subject;
	}
	if (!GETPOSTISSET('fk_soc') && $ticket->fk_soc > 0) {
		$_GET['fk_soc'] = $ticket->fk_soc;
	}
	if (!GETPOSTISSET('fk_project') && !empty($ticket->fk_project)) {
		$_GET['fk_project'] = $ticket->fk_project;
	}
	if (!GETPOSTISSET('reason')) {
		$_GET['reason'] = ChangeOrder::REASON_DEFECT;
	}
	if (!GETPOSTISSET('description')) {
		$_GET['description'] = $ticket->message;
	}
	return (int) $ticket->id;
}
