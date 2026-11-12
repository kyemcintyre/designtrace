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
 * \file        designtrace/lib/designtrace_clientaccess.lib.php
 * \ingroup     designtrace
 * \brief       Revision card: what customers see through quotes, orders and tickets, and
 *              "Update delivered revision" on delivered orders
 */

dol_include_once('/designtrace/class/clientaccess.class.php');

/**
 * Handle the customer access actions of the revision card (redirects when it handles one).
 * Also refuses removing the link between a revision and a delivered order: that goes through
 * "Update delivered revision", so the order keeps its history.
 *
 * @param	string				$action		Action
 * @param	ComponentRevision	$object		Revision shown
 * @param	User				$user		User
 * @return	string							Action to go on with ('' when refused)
 */
function designtraceDoClientAccessActions($action, $object, $user)
{
	global $db, $langs;

	$selfurl = dol_buildpath('/designtrace/componentrevision_card.php', 1).'?id='.((int) $object->id);

	if ($action == 'dellink' && GETPOSTINT('dellinkid') > 0) {
		$sql = "SELECT ee.sourcetype, ee.fk_source, c.ref, c.fk_statut FROM ".$db->prefix()."element_element as ee";
		$sql .= " INNER JOIN ".$db->prefix()."commande as c ON c.rowid = ee.fk_source";
		$sql .= " WHERE ee.rowid = ".GETPOSTINT('dellinkid')." AND ee.sourcetype = 'commande'";
		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql)) && (int) $obj->fk_statut === 3) {
			setEventMessages($langs->trans('ErrorDeliveredLinkLocked', $obj->ref), null, 'errors');
			return '';
		}
		return $action;
	}

	if ($action == 'setclientvisible') {
		$element = GETPOST('element', 'aZ09');
		$docid = GETPOSTINT('docid');
		$channels = DesignTraceClientAccess::getChannels();
		if (!$user->hasRight('designtrace', 'componentrevision', 'managevisibility')) {
			setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
		} elseif (!isset($channels[$element]) || !designtraceRevisionIsLinkedTo($object, $element, $docid)) {
			setEventMessages($langs->trans('ErrorRecordNotFound'), null, 'errors');
		} elseif (DesignTraceClientAccess::setLinkVisibility($db, $user, $object->id, $element, $docid, GETPOSTINT('visible')) > 0) {
			setEventMessages($langs->trans(GETPOSTINT('visible') ? 'ClientAccessShown' : 'ClientAccessHidden', $object->ref), null, 'mesgs');
		} else {
			setEventMessages($db->lasterror(), null, 'errors');
		}
		header('Location: '.$selfurl);
		exit;
	}

	if ($action == 'confirm_updatedelivered' && GETPOST('confirm', 'alpha') == 'yes') {
		require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
		$order = new Commande($db);
		$newrev = new ComponentRevision($db);
		$errors = array();
		if ($order->fetch(GETPOSTINT('docid')) <= 0 || $newrev->fetch(GETPOSTINT('newrev')) <= 0) {
			setEventMessages($langs->trans('ErrorRecordNotFound'), null, 'errors');
		} elseif (DesignTraceClientAccess::updateDelivered($db, $user, $order, $object, $newrev, GETPOST('reason', 'alphanohtml'), GETPOST('keepold', 'alpha') ? 1 : 0, $errors, GETPOSTINT('eco')) > 0) {
			setEventMessages($langs->trans('DeliveredRevisionUpdated', $order->ref, $object->ref, $newrev->ref), null, 'mesgs');
		} else {
			setEventMessages(null, $errors, 'errors');
		}
		header('Location: '.$selfurl);
		exit;
	}

	return $action;
}

/**
 * Whether a revision is linked to a document
 *
 * @param	ComponentRevision	$object		Revision
 * @param	string				$element	Document type
 * @param	int					$docid		Document id
 * @return	bool
 */
function designtraceRevisionIsLinkedTo($object, $element, $docid)
{
	global $db;

	$sql = "SELECT rowid FROM ".$db->prefix()."element_element";
	$sql .= " WHERE fk_source = ".((int) $docid)." AND sourcetype = '".$db->escape($element)."'";
	$sql .= " AND fk_target = ".((int) $object->id)." AND targettype = '".$db->escape($object->getElementType())."'";
	$resql = $db->query($sql);
	return ($resql && $db->num_rows($resql) > 0);
}

/**
 * Released revisions of the same component newer than this one
 *
 * @param	ComponentRevision	$object		Revision
 * @return	array<int,string>				Id => ref, newest first
 */
function designtraceNewerReleasedRevisions($object)
{
	global $db;

	$result = array();
	$sql = "SELECT rowid, ref FROM ".$db->prefix()."designtrace_componentrevision";
	$sql .= " WHERE fk_component = ".((int) $object->fk_component)." AND status = ".((int) ComponentRevision::STATUS_RELEASED);
	$sql .= " AND rowid > ".((int) $object->id)." ORDER BY rowid DESC";
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$result[(int) $obj->rowid] = $obj->ref;
	}
	return $result;
}

/**
 * Confirmation popup of "Update delivered revision"
 *
 * @param	Form				$form		Form helper
 * @param	ComponentRevision	$object		Revision delivered so far
 * @return	string							HTML ('' if not asked)
 */
function designtraceClientAccessConfirm($form, $object)
{
	global $db, $langs;

	if (GETPOST('action', 'aZ09') != 'updatedelivered' || GETPOSTINT('docid') <= 0) {
		return '';
	}
	require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
	$order = new Commande($db);
	if ($order->fetch(GETPOSTINT('docid')) <= 0) {
		return '';
	}
	$newer = designtraceNewerReleasedRevisions($object);
	if (empty($newer)) {
		return '';
	}
	// Optional ECO: those listing the component (any status but cancelled), the newest revision's first
	dol_include_once('/designtrace/class/changeorder.class.php');
	$ecos = array();
	$sql = "SELECT e.rowid, e.ref, e.label FROM ".$db->prefix()."designtrace_changeorder as e";
	$sql .= " INNER JOIN ".$db->prefix()."designtrace_changeorder_component as ec ON ec.fk_eco = e.rowid AND ec.fk_component = ".((int) $object->fk_component);
	$sql .= " WHERE e.status <> ".ChangeOrder::STATUS_CANCELED." ORDER BY e.rowid DESC";
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$ecos[(int) $obj->rowid] = $obj->ref.' - '.dol_trunc((string) $obj->label, 50);
	}
	$newest = new ComponentRevision($db);
	$defaulteco = ($newest->fetch((int) key($newer)) > 0 && !empty($newest->fk_eco) ? (string) $newest->fk_eco : '');
	$formquestion = array(
		array('type' => 'select', 'name' => 'newrev', 'label' => $langs->trans('UpdateDeliveredTo'), 'values' => $newer, 'default' => (string) key($newer), 'select_show_empty' => 0),
		array('type' => 'text', 'name' => 'reason', 'label' => $langs->trans('UpdateDeliveredReason'), 'value' => '', 'morecss' => 'minwidth300'),
		array('type' => 'select', 'name' => 'eco', 'label' => $langs->trans('ChangeOrder'), 'values' => $ecos, 'default' => $defaulteco, 'select_show_empty' => 1),
		array('type' => 'checkbox', 'name' => 'keepold', 'label' => $langs->trans('UpdateDeliveredKeepOld', $object->ref), 'value' => 0),
	);
	$url = dol_buildpath('/designtrace/componentrevision_card.php', 1).'?id='.((int) $object->id).'&docid='.((int) $order->id);
	return $form->formconfirm($url, $langs->trans('UpdateDeliveredRevision'), $langs->trans('ConfirmUpdateDelivered', $order->ref, $object->ref), 'confirm_updatedelivered', $formquestion, 'yes', 1, 300, 700);
}

/**
 * "Customer access" block of the revision card: the quotes, orders and tickets the revision is
 * linked to, whether their customer can see it, which of its files they see through each, and
 * "Update delivered revision" on delivered orders when a newer revision is released
 *
 * @param	ComponentRevision	$object		Revision
 * @param	Form				$form		Form helper
 * @return	void
 */
function designtracePrintClientAccess($object, $form)
{
	global $db, $langs, $user;

	$docs = DesignTraceClientAccess::fetchDocumentsOfRevision($db, $object);
	if (empty($docs)) {
		return;
	}
	$channels = DesignTraceClientAccess::getChannels();
	$canmanage = $user->hasRight('designtrace', 'componentrevision', 'managevisibility');
	$canupdate = $user->hasRight('designtrace', 'componentrevision', 'updatedelivered');
	$newer = designtraceNewerReleasedRevisions($object);
	$selfurl = dol_buildpath('/designtrace/componentrevision_card.php', 1).'?id='.((int) $object->id);

	print '<br>';
	print load_fiche_titre($form->textwithpicto($langs->trans('ClientAccessTitle'), $langs->trans('ClientAccessHelp')), '', 'fa-user-check');
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Document').'</td><td>'.$langs->trans('Customer').'</td>';
	print '<td class="center">'.$langs->trans('ClientAccessVisible').'</td><td class="center">'.$form->textwithpicto($langs->trans('ClientAccessFiles'), $langs->trans('ClientAccessFilesHelp')).'</td><td></td></tr>';
	foreach ($docs as $doc) {
		$visiblefiles = 0;
		if ($doc->client_visible && $doc->socid > 0) {
			$rows = DesignTraceClientAccess::fetchVisibleFiles($db, (int) $doc->socid, $doc->element, (int) $doc->doc_id);
			foreach ((is_array($rows) ? $rows : array()) as $row) {
				if ((int) $row->rev_id === (int) $object->id) {
					$visiblefiles++;
				}
			}
		}
		print '<tr class="oddeven">';
		print '<td class="nowraponall">'.img_picto('', $channels[$doc->element]['picto'], 'class="pictofixedwidth"').DesignTraceClientAccess::getDocumentNomUrl($db, $doc->element, (int) $doc->doc_id);
		if (in_array((int) $doc->doc_status, $channels[$doc->element]['hidden'], true)) {
			print ' <span class="opacitymedium small">'.$langs->trans('ClientAccessDocNotShown').'</span>';
		} elseif ($doc->delivered) {
			print ' <span class="badge badge-status4 badge-status small">'.$langs->trans('ClientAccessDelivered').'</span>';
		}
		print '</td>';
		print '<td class="tdoverflowmax200">'.dol_escape_htmltag((string) $doc->socname).'</td>';
		print '<td class="center">';
		if ($canmanage) {
			$toggleurl = $selfurl.'&action=setclientvisible&token='.newToken().'&element='.urlencode($doc->element).'&docid='.((int) $doc->doc_id).'&visible='.($doc->client_visible ? 0 : 1);
			print '<a class="reposition" href="'.$toggleurl.'">'.img_picto($langs->trans($doc->client_visible ? 'ClientAccessHide' : 'ClientAccessShow'), $doc->client_visible ? 'switch_on' : 'switch_off').'</a>';
		} else {
			print $langs->trans($doc->client_visible ? 'Yes' : 'No');
		}
		print '</td>';
		print '<td class="center">'.($visiblefiles ? $visiblefiles : '<span class="opacitymedium">0</span>').'</td>';
		print '<td class="right nowraponall">';
		if ($doc->delivered && $doc->element == 'commande' && !empty($newer)) {
			$enabled = $canupdate;
			print dolGetButtonAction($enabled ? '' : $langs->trans('NotEnoughPermissions'), $langs->trans('UpdateDeliveredRevision'), 'default', $selfurl.'&action=updatedelivered&docid='.((int) $doc->doc_id).'&token='.newToken(), '', $enabled);
		}
		print '</td>';
		print '</tr>';
	}
	print '</table>';
	print '</div>';
}
