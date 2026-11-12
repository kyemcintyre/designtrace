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
 * \file        designtrace/class/reorder.class.php
 * \ingroup     designtrace
 * \brief       Reorder from a delivered order: a draft quote pinned to the same revisions
 *
 * A delivered (closed) order can be reordered by staff (button on the order), by the customer in
 * the Web portal or through the API. It makes a draft quote for the same customer and project with
 * the order's lines, linked to the order, to the order's designs and to the revisions that were
 * delivered (not the ones replaced by "Update delivered revision"), so the part is made from the
 * same revision, material, process and machine. The quote's private note lists them, with any
 * newer released revision, so staff can decide whether to offer it. The quote stays a draft until
 * staff check prices and validate it.
 */

dol_include_once('/designtrace/class/clientaccess.class.php');
dol_include_once('/designtrace/class/doclinks.class.php');
dol_include_once('/designtrace/class/componentrevision.class.php');
require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';

/**
 * Reorder from a delivered order
 */
class DesignTraceReorder
{
	/**
	 * Revisions delivered on an order (client-visible links), oldest component first
	 *
	 * @param	DoliDB		$db		Database handler
	 * @param	Commande	$order	Order
	 * @return	stdClass[]			Rows of DesignTraceDocLinks::fetchRevisionsOfDocument()
	 */
	public static function fetchDeliveredRevisions($db, $order)
	{
		$rows = array();
		foreach (DesignTraceDocLinks::fetchRevisionsOfDocument($db, $order) as $row) {
			if ((int) $row->rev_status === ComponentRevision::STATUS_RELEASED && DesignTraceClientAccess::getLinkVisibility($db, (int) $row->rev_id, 'commande', (int) $order->id)) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * Whether an order can be reordered: delivered, with delivered revisions
	 *
	 * @param	DoliDB		$db		Database handler
	 * @param	Commande	$order	Order
	 * @return	bool
	 */
	public static function canReorder($db, $order)
	{
		return ((int) $order->status === Commande::STATUS_CLOSED && count(self::fetchDeliveredRevisions($db, $order)) > 0);
	}

	/**
	 * Draft quote already made from this order (so repeated clicks in a portal do not make several
	 * quotes: the request is already with staff)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$orderid	Order id
	 * @return	int					Quote id, 0 if none
	 */
	public static function getPendingQuote($db, $orderid)
	{
		$sql = "SELECT p.rowid FROM ".$db->prefix()."element_element as ee";
		$sql .= " INNER JOIN ".$db->prefix()."propal as p ON p.rowid = ee.fk_target";
		$sql .= " WHERE ee.sourcetype = 'commande' AND ee.fk_source = ".((int) $orderid)." AND ee.targettype = 'propal'";
		$sql .= " AND p.fk_statut = ".Propal::STATUS_DRAFT;
		$sql .= " ORDER BY p.rowid DESC".$db->plimit(1);
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		return $obj ? (int) $obj->rowid : 0;
	}

	/**
	 * Make the reorder quote
	 *
	 * @param	DoliDB		$db			Database handler
	 * @param	User		$user		Author (staff, portal user or API user)
	 * @param	Commande	$order		Delivered order (lines fetched)
	 * @param	string		$channel	backoffice, webportal or api
	 * @param	string		$actor		Who asked (for the note)
	 * @param	string		$error		Error message if KO
	 * @return	int						Quote id, <0 if KO
	 */
	public static function createQuote($db, $user, $order, $channel, $actor, &$error)
	{
		global $langs;

		$langs->loadLangs(array('designtrace@designtrace', 'orders', 'propal'));
		$error = '';
		$revisions = self::fetchDeliveredRevisions($db, $order);
		if ((int) $order->status !== Commande::STATUS_CLOSED || empty($revisions)) {
			$error = $langs->trans('ErrorReorderNotPossible', $order->ref);
			return -1;
		}
		if ($channel !== 'backoffice') {
			$pending = self::getPendingQuote($db, (int) $order->id);
			if ($pending > 0) {
				return $pending;
			}
		}
		if (empty($order->lines)) {
			$order->fetch_lines();
		}

		$db->begin();
		$quote = new Propal($db);
		$quote->socid = (int) $order->socid;
		$quote->fk_project = (int) $order->fk_project;
		$quote->date = dol_now();
		$quote->duree_validite = getDolGlobalInt('PROPALE_VALIDITY_DURATION', 15);
		$quote->ref_client = $order->ref_client;
		$quote->cond_reglement_id = $order->cond_reglement_id;
		$quote->mode_reglement_id = $order->mode_reglement_id;
		$quote->contact_id = 0;
		$quote->note_private = self::buildNote($db, $order, $revisions, $channel, $actor);
		$quote->origin = 'commande';
		$quote->origin_type = 'commande';
		$quote->origin_id = (int) $order->id;
		$quote->linked_objects = array('commande' => (int) $order->id);
		if ($quote->create($user) <= 0) {
			$error = $quote->error ?: implode(', ', $quote->errors);
			$db->rollback();
			return -1;
		}
		foreach ($order->lines as $line) {
			if (!empty($line->special_code) || (int) $line->product_type === 9) {
				continue;	// Titles, subtotals and other special lines
			}
			$res = $quote->addline($line->desc, $line->subprice, $line->qty, $line->tva_tx, $line->localtax1_tx, $line->localtax2_tx, (int) $line->fk_product, $line->remise_percent, 'HT', 0, $line->info_bits, $line->product_type, -1, 0, 0, 0, 0, (string) $line->label, '', '', array(), $line->fk_unit);
			if ($res <= 0) {
				$error = $quote->error ?: implode(', ', $quote->errors);
				$db->rollback();
				return -1;
			}
		}
		// The delivered revisions, and the order's designs
		foreach ($revisions as $row) {
			$revision = new ComponentRevision($db);
			if ($revision->fetch((int) $row->rev_id) <= 0 || !$revision->add_object_linked('propal', $quote->id)) {
				$error = $db->lasterror() ?: $langs->trans('ErrorRecordNotFound');
				$db->rollback();
				return -1;
			}
		}
		$sql = "INSERT INTO ".$db->prefix()."element_element (fk_source, sourcetype, fk_target, targettype)";
		$sql .= " SELECT ".((int) $quote->id).", 'propal', ee.fk_target, ee.targettype FROM ".$db->prefix()."element_element as ee";
		$sql .= " WHERE ee.sourcetype = 'commande' AND ee.fk_source = ".((int) $order->id)." AND ee.targettype = 'designtrace_design'";
		if (!$db->query($sql)) {
			$error = $db->lasterror();
			$db->rollback();
			return -1;
		}
		$db->commit();
		return (int) $quote->id;
	}

	/**
	 * Private note of the quote: what was delivered, how it was made, newer revisions
	 *
	 * @param	DoliDB		$db			Database handler
	 * @param	Commande	$order		Order
	 * @param	stdClass[]	$revisions	Delivered revisions
	 * @param	string		$channel	Channel
	 * @param	string		$actor		Who asked
	 * @return	string
	 */
	private static function buildNote($db, $order, $revisions, $channel, $actor)
	{
		global $langs;

		$channels = array('backoffice' => 'ReorderByStaff', 'webportal' => 'ReorderByPortal', 'api' => 'ReorderByApi');
		$lines = array($langs->transnoentities('ReorderNoteTitle', $order->ref, $langs->transnoentities($channels[$channel] ?? 'ReorderByStaff'), $actor));
		foreach ($revisions as $row) {
			$revision = new ComponentRevision($db);
			$revision->fetch((int) $row->rev_id);
			$made = array();
			foreach (array('material' => 'Material', 'process' => 'Process', 'machine' => 'Machine') as $field => $label) {
				if (trim((string) $revision->$field) !== '') {
					$made[] = $langs->transnoentities($label).': '.$revision->$field;
				}
			}
			$line = '- '.$row->rev_ref.' '.$row->comp_label.($made ? ' ('.implode(', ', $made).')' : '');
			if (!empty($row->current_rev_id) && (int) $row->current_rev_id > (int) $row->rev_id) {
				$line .= ' - '.$langs->transnoentities('ReorderNewerRevision', $row->comp_ref.'-'.$row->current_rev);
			}
			$lines[] = $line;
		}
		$lines[] = $langs->transnoentities('ReorderNoteCheckPrices');
		return implode("\n", $lines);
	}
}
