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
 *	\file       designtrace/class/doclinks.class.php
 *	\ingroup    designtrace
 *	\brief      Links between component revisions and Dolibarr documents (quotes, orders, invoices, BOMs, tickets)
 */

dol_include_once('/designtrace/class/componentrevision.class.php');
dol_include_once('/designtrace/class/component.class.php');


/**
 *	Links between component revisions and Dolibarr documents.
 *
 *	They are stored in Dolibarr's own linked-objects table (llx_element_element, the document as
 *	source, the revision as target 'designtrace_componentrevision'), so they show on both sides
 *	and Dolibarr's unlink works. A link pins the exact revision, so each document keeps showing
 *	what it was based on.
 */
class DesignTraceDocLinks
{
	/**
	 * Revision status groups used by the rules
	 */
	const ANY_OPEN_OR_RELEASED = 'any';		// Draft, in review or released (e.g. a proof drawing on a quote)
	const RELEASED_ONLY = 'released';

	/**
	 * Which documents can link to which components, from the spec:
	 * quotes, orders and invoices: any component (quotes also before release); BOMs: assemblies; tickets: any.
	 *
	 * @return	array<string,array{class:string,file:string,module:string,right:array{0:string,1:string},label:string,picto:string,types:int[],statuses:string,table:string}>
	 */
	public static function getRules()
	{
		$all = array(Component::TYPE_PART, Component::TYPE_ASSEMBLY, Component::TYPE_DRAWING);
		return array(
			'propal' => array('class' => 'Propal', 'file' => '/comm/propal/class/propal.class.php', 'module' => 'propal', 'right' => array('propal', 'lire'), 'label' => 'Proposal', 'picto' => 'propal', 'types' => $all, 'statuses' => self::ANY_OPEN_OR_RELEASED, 'table' => 'propal'),
			'commande' => array('class' => 'Commande', 'file' => '/commande/class/commande.class.php', 'module' => 'order', 'right' => array('commande', 'lire'), 'label' => 'Order', 'picto' => 'order', 'types' => $all, 'statuses' => self::RELEASED_ONLY, 'table' => 'commande'),
			'facture' => array('class' => 'Facture', 'file' => '/compta/facture/class/facture.class.php', 'module' => 'invoice', 'right' => array('facture', 'lire'), 'label' => 'Invoice', 'picto' => 'bill', 'types' => $all, 'statuses' => self::RELEASED_ONLY, 'table' => 'facture'),
			'bom' => array('class' => 'BOM', 'file' => '/bom/class/bom.class.php', 'module' => 'bom', 'right' => array('bom', 'read'), 'label' => 'BOM', 'picto' => 'bom', 'types' => array(Component::TYPE_ASSEMBLY), 'statuses' => self::RELEASED_ONLY, 'table' => 'bom_bom'),
			'ticket' => array('class' => 'Ticket', 'file' => '/ticket/class/ticket.class.php', 'module' => 'ticket', 'right' => array('ticket', 'read'), 'label' => 'Ticket', 'picto' => 'ticket', 'types' => $all, 'statuses' => self::RELEASED_ONLY, 'table' => 'ticket'),
		);
	}

	/**
	 * Documents the user can link a revision of this component to
	 *
	 * @param	Component	$component	Component
	 * @param	User		$user		User
	 * @return	string[]				Keys of getRules()
	 */
	public static function getAllowedElements($component, $user)
	{
		$elements = array();
		foreach (self::getRules() as $element => $rule) {
			if (isModEnabled($rule['module']) && $user->hasRight($rule['right'][0], $rule['right'][1]) && in_array((int) $component->type, $rule['types'], true)) {
				$elements[] = $element;
			}
		}
		return $elements;
	}

	/**
	 * Load a document
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	string	$element	Key of getRules()
	 * @param	int		$id			Id
	 * @return	CommonObject|null	Null if not found or not visible in this entity
	 */
	public static function fetchDocument($db, $element, $id)
	{
		global $conf;

		$rules = self::getRules();
		if (!isset($rules[$element]) || (int) $id <= 0) {
			return null;
		}
		require_once DOL_DOCUMENT_ROOT.$rules[$element]['file'];
		$doc = new $rules[$element]['class']($db);
		if ($doc->fetch((int) $id) <= 0) {
			return null;
		}
		$entityelement = ($element == 'commande' ? 'order' : ($element == 'facture' ? 'invoice' : $element));
		// Objects whose entity field is disabled without multicompany (BOM, ...) do not load it
		$docentity = (empty($doc->entity) ? (int) $conf->entity : (int) $doc->entity);
		if (!in_array($docentity, array_map('intval', explode(',', getEntity($entityelement))), true)) {
			return null;
		}
		return $doc;
	}

	/**
	 * Link a revision to a document after checking the rules
	 *
	 * @param	User				$user		User
	 * @param	ComponentRevision	$revision	Revision
	 * @param	string				$element	Key of getRules()
	 * @param	int					$docid		Document id
	 * @return	int<-1,1>						>0 if OK, <0 if KO (see $revision->error)
	 */
	public static function link($user, $revision, $element, $docid)
	{
		global $db, $langs;

		$langs->loadLangs(array('errors', 'designtrace@designtrace'));
		$rules = self::getRules();
		$component = $revision->fetchComponentChecked();
		if (!is_object($component)) {
			return -1;
		}
		if (!isset($rules[$element]) || !in_array($element, self::getAllowedElements($component, $user), true)) {
			return self::fail($revision, $langs->trans('ErrorDocLinkNotAllowed', $component->ref));
		}
		$rule = $rules[$element];
		$status = (int) $revision->status;
		if ($status === ComponentRevision::STATUS_OBSOLETE || ($rule['statuses'] === self::RELEASED_ONLY && $status !== ComponentRevision::STATUS_RELEASED)) {
			return self::fail($revision, $langs->trans('ErrorDocLinkNeedsReleased', $revision->ref, $langs->transnoentitiesnoconv($rule['label'])));
		}
		$doc = self::fetchDocument($db, $element, $docid);
		if (!$doc) {
			return self::fail($revision, $langs->trans('ErrorRecordNotFound'));
		}
		$type = $doc->getElementType();
		// A delivered order keeps the revisions it was delivered with: changing one is the explicit,
		// logged "Update delivered revision" (DesignTraceClientAccess::updateDelivered())
		if ($element == 'commande' && (int) $doc->status === Commande::STATUS_CLOSED) {
			$sql = "SELECT r.ref FROM ".$db->prefix()."element_element as ee";
			$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = ee.fk_target";
			$sql .= " WHERE ee.fk_source = ".((int) $doc->id)." AND ee.sourcetype = '".$db->escape($type)."'";
			$sql .= " AND ee.targettype = '".$db->escape($revision->getElementType())."'";
			$sql .= " AND r.fk_component = ".((int) $component->id)." AND r.rowid <> ".((int) $revision->id);
			$resql = $db->query($sql);
			if ($resql && ($obj = $db->fetch_object($resql))) {
				return self::fail($revision, $langs->trans('ErrorDocLinkDeliveredUseUpdate', $doc->ref, $obj->ref));
			}
		}
		$sql = "SELECT rowid FROM ".$db->prefix()."element_element";
		$sql .= " WHERE fk_source = ".((int) $doc->id)." AND sourcetype = '".$db->escape($type)."'";
		$sql .= " AND fk_target = ".((int) $revision->id)." AND targettype = '".$db->escape($revision->getElementType())."'";
		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) > 0) {
			return self::fail($revision, $langs->trans('ErrorDocLinkExists', $revision->ref, $doc->ref));
		}
		if (!$revision->add_object_linked($type, $doc->id)) {
			return self::fail($revision, $db->lasterror());
		}
		return 1;
	}

	/**
	 * Copy the revision and design links of a document to a document made from it (quote to
	 * order, order to invoice), so they carry over like Dolibarr's own links. Called by triggers.
	 *
	 * @param	DoliDB			$db			Database handler
	 * @param	CommonObject	$newdoc		New document (order, invoice)
	 * @return	int							Number of links copied, -1 if KO
	 */
	public static function carryOver($db, $newdoc)
	{
		$sources = array();
		if (!empty($newdoc->linked_objects) && is_array($newdoc->linked_objects)) {
			foreach ($newdoc->linked_objects as $type => $ids) {
				foreach ((array) $ids as $id) {
					$sources[] = array($type, (int) $id);
				}
			}
		}
		$origintype = (!empty($newdoc->origin_type) ? $newdoc->origin_type : (!empty($newdoc->origin) ? $newdoc->origin : ''));
		if ($origintype && !empty($newdoc->origin_id)) {
			$sources[] = array($origintype, (int) $newdoc->origin_id);
		}

		$count = 0;
		$done = array();
		$newtype = $newdoc->getElementType();
		foreach ($sources as $source) {
			list($type, $id) = $source;
			$type = ($type == 'order' ? 'commande' : ($type == 'invoice' ? 'facture' : $type));
			if (!in_array($type, array('propal', 'commande'), true) || $id <= 0 || isset($done[$type.$id])) {
				continue;
			}
			$done[$type.$id] = true;
			$sql = "SELECT fk_target, targettype FROM ".$db->prefix()."element_element";
			$sql .= " WHERE fk_source = ".((int) $id)." AND sourcetype = '".$db->escape($type)."'";
			$sql .= " AND targettype IN ('designtrace_componentrevision', 'designtrace_design')";
			$resql = $db->query($sql);
			if (!$resql) {
				return -1;
			}
			while ($obj = $db->fetch_object($resql)) {
				$sqlexists = "SELECT rowid FROM ".$db->prefix()."element_element WHERE fk_source = ".((int) $newdoc->id)." AND sourcetype = '".$db->escape($newtype)."'";
				$sqlexists .= " AND fk_target = ".((int) $obj->fk_target)." AND targettype = '".$db->escape($obj->targettype)."'";
				$resexists = $db->query($sqlexists);
				if ($resexists && $db->num_rows($resexists) > 0) {
					continue;
				}
				$sqlinsert = "INSERT INTO ".$db->prefix()."element_element (fk_source, sourcetype, fk_target, targettype)";
				$sqlinsert .= " VALUES (".((int) $newdoc->id).", '".$db->escape($newtype)."', ".((int) $obj->fk_target).", '".$db->escape($obj->targettype)."')";
				if (!$db->query($sqlinsert)) {
					return -1;
				}
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Revisions linked to a document, with what is needed to show them
	 *
	 * @param	DoliDB			$db		Database handler
	 * @param	CommonObject	$doc	Document
	 * @return	stdClass[]				Rows: rev_id, rev_ref, rev, rev_status, comp_id, comp_ref, comp_label, comp_type, current_rev_id, current_rev, flag
	 */
	public static function fetchRevisionsOfDocument($db, $doc)
	{
		dol_include_once('/designtrace/class/componentlink.class.php');

		$sql = "SELECT r.rowid as rev_id, r.ref as rev_ref, r.rev, r.status as rev_status, c.rowid as comp_id, c.ref as comp_ref, c.label as comp_label, c.type as comp_type";
		$sql .= " FROM ".$db->prefix()."element_element as ee";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = ee.fk_target";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
		$sql .= " WHERE ee.fk_source = ".((int) $doc->id)." AND ee.sourcetype = '".$db->escape($doc->getElementType())."'";
		$sql .= " AND ee.targettype = 'designtrace_componentrevision'";
		$sql .= " ORDER BY c.ref";
		$rows = array();
		$compids = array();
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$rows[] = $obj;
			$compids[] = (int) $obj->comp_id;
		}
		$current = ComponentLink::getCurrentRevisionIds($db, $compids);
		$flags = ComponentLink::getComponentFlags($db, $compids);
		$revs = array();
		if ($current) {
			$resql = $db->query("SELECT rowid, rev FROM ".$db->prefix()."designtrace_componentrevision WHERE rowid IN (".$db->sanitize(implode(',', $current)).")");
			while ($resql && ($obj = $db->fetch_object($resql))) {
				$revs[(int) $obj->rowid] = $obj->rev;
			}
		}
		foreach ($rows as $row) {
			$row->current_rev_id = $current[(int) $row->comp_id] ?? 0;
			$row->current_rev = ($row->current_rev_id ? $revs[$row->current_rev_id] : '');
			$row->flag = $flags[(int) $row->comp_id] ?? 0;
		}
		return $rows;
	}

	/**
	 * Select of the documents of one kind, newest first, labelled with their customer
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	string	$element	Key of getRules()
	 * @param	string	$htmlname	Name and id of the select
	 * @return	string				HTML
	 */
	public static function selectDocument($db, $element, $htmlname)
	{
		global $langs;

		include_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';
		$rules = self::getRules();
		if (!isset($rules[$element])) {
			return '';
		}
		$entityelement = ($element == 'commande' ? 'order' : ($element == 'facture' ? 'invoice' : $element));
		if ($element == 'bom') {
			$sql = "SELECT t.rowid, t.ref, t.label as extra, '' as socname FROM ".$db->prefix()."bom_bom as t";
		} elseif ($element == 'ticket') {
			$sql = "SELECT t.rowid, t.ref, t.subject as extra, s.nom as socname FROM ".$db->prefix()."ticket as t LEFT JOIN ".$db->prefix()."societe as s ON s.rowid = t.fk_soc";
		} else {
			$sql = "SELECT t.rowid, t.ref, t.ref_client as extra, s.nom as socname FROM ".$db->prefix().$rules[$element]['table']." as t LEFT JOIN ".$db->prefix()."societe as s ON s.rowid = t.fk_soc";
		}
		$sql .= " WHERE t.entity IN (".getEntity($entityelement).")";
		$sql .= " ORDER BY t.rowid DESC";
		$sql .= $db->plimit(1000);

		$out = '<select class="flat minwidth300 maxwidth500" id="'.dol_escape_htmltag($htmlname).'" name="'.dol_escape_htmltag($htmlname).'">';
		$out .= '<option value="-1">&nbsp;</option>';
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$label = $obj->ref.($obj->socname ? ' - '.$obj->socname : '').($obj->extra ? ' ('.$obj->extra.')' : '');
			$out .= '<option value="'.((int) $obj->rowid).'">'.dol_escape_htmltag($label).'</option>';
		}
		$out .= '</select>';
		$out .= ajax_combobox($htmlname);
		return $out;
	}

	/**
	 * Set an error on the revision and return -1
	 *
	 * @param	ComponentRevision	$revision	Revision
	 * @param	string				$message	Message
	 * @return	int<-1,-1>
	 */
	private static function fail($revision, $message)
	{
		$revision->error = $message;
		$revision->errors[] = $message;
		return -1;
	}
}
