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
 *	\file       designtrace/class/orderclosing.class.php
 *	\ingroup    designtrace
 *	\brief      Close design-only orders once they are paid, so their files count as delivered
 */

require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';


/**
 * Setting DESIGNTRACE_CLOSE_PAID_DESIGN_ORDERS: when an invoice is paid (BILL_PAYED), each order it was made from
 * is closed if it is a design-only order that has now been paid in full. Closed is what makes its revisions
 * delivered and its "Visible on completion" files visible. An order qualifies when:
 * - it is validated or in progress (not draft, not already closed or cancelled),
 * - a component revision is linked to it,
 * - every line is a service (titles and subtotals aside): anything to ship still closes through its shipments,
 * - every invoice made from it is paid in full, or closed with only a discount, bank charges or tax withheld written off (credit
 *   notes and abandoned invoices aside), and together they come to the order's total including tax (a deposit and
 *   its final invoice together do; an order changed since it was invoiced waits for the next invoice).
 */
class DesignTraceOrderClosing
{
	/**
	 * Close the design-only orders an invoice just paid in full
	 *
	 * Never fails the payment: an order that cannot be closed is logged and left as it is.
	 *
	 * @param	DoliDB		$db			Database handler
	 * @param	User		$user		User the payment is recorded by
	 * @param	Facture		$invoice	The invoice set to paid
	 * @return	int[]					Ids of the orders closed
	 */
	public static function closePaidOrders($db, $user, $invoice)
	{
		$closed = array();
		if (!getDolGlobalInt('DESIGNTRACE_CLOSE_PAID_DESIGN_ORDERS') || empty($invoice->id)) {
			return $closed;
		}
		$sql = "SELECT fk_source FROM ".$db->prefix()."element_element";
		$sql .= " WHERE sourcetype = 'commande' AND targettype = 'facture' AND fk_target = ".((int) $invoice->id);
		$resql = $db->query($sql);
		$orderids = array();
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$orderids[] = (int) $obj->fk_source;
		}
		foreach (array_unique($orderids) as $orderid) {
			if (!self::isPaidDesignOrder($db, $orderid)) {
				continue;
			}
			$order = new Commande($db);
			if ($order->fetch($orderid) <= 0) {
				continue;
			}
			// The payment's user may have no right to close orders: the setting is the admin's say-so
			$result = $order->cloture($user, 0, 0);
			if ($result > 0) {
				$closed[] = $orderid;
				dol_syslog(__METHOD__." order ".$order->ref." closed: paid in full by ".$invoice->ref, LOG_INFO);
				continue;
			}
			// Inside the payment's transaction a failed close cannot roll back: its status change may stand all the same
			$order->fetch($orderid);
			if ((int) $order->status === Commande::STATUS_CLOSED) {
				$closed[] = $orderid;
				dol_syslog(__METHOD__." order ".$order->ref." closed after ".$invoice->ref.", but a trigger on closing failed: ".$order->error, LOG_WARNING);
			} else {
				dol_syslog(__METHOD__." order ".$order->ref." paid in full by ".$invoice->ref." but not closed: ".$order->error, LOG_WARNING);
			}
		}
		return $closed;
	}

	/**
	 * Whether an order is a design-only order paid in full (see the class)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$orderid	Order id
	 * @return	bool
	 */
	public static function isPaidDesignOrder($db, $orderid)
	{
		$resql = $db->query("SELECT fk_statut, total_ttc FROM ".$db->prefix()."commande WHERE rowid = ".((int) $orderid));
		$order = $resql ? $db->fetch_object($resql) : null;
		if (!$order || !in_array((int) $order->fk_statut, array(Commande::STATUS_VALIDATED, Commande::STATUS_SHIPMENTONPROCESS), true)) {
			return false;
		}

		$sql = "SELECT COUNT(*) as nb FROM ".$db->prefix()."element_element";
		$sql .= " WHERE sourcetype = 'commande' AND fk_source = ".((int) $orderid)." AND targettype = 'designtrace_componentrevision'";
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		if (!$obj || (int) $obj->nb === 0) {
			return false;
		}

		// Product lines (type 0) are goods to ship; 9 are titles, subtotals and other special lines
		$sql = "SELECT COUNT(*) as nb FROM ".$db->prefix()."commandedet WHERE fk_commande = ".((int) $orderid)." AND product_type NOT IN (1, 9)";
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		if (!$obj || (int) $obj->nb > 0) {
			return false;
		}

		$sql = "SELECT f.fk_statut, f.type, f.paye, f.close_code, f.total_ttc FROM ".$db->prefix()."element_element as ee";
		$sql .= " INNER JOIN ".$db->prefix()."facture as f ON f.rowid = ee.fk_target";
		$sql .= " WHERE ee.sourcetype = 'commande' AND ee.fk_source = ".((int) $orderid)." AND ee.targettype = 'facture'";
		$resql = $db->query($sql);
		if (!$resql) {
			return false;
		}
		$paid = 0.0;
		$count = 0;
		while ($inv = $db->fetch_object($resql)) {
			if ((int) $inv->type === Facture::TYPE_CREDIT_NOTE || (int) $inv->fk_statut === Facture::STATUS_ABANDONED) {
				continue;
			}
			// Paid in full, or closed with only a small difference written off; a bad debt or "other" may leave much unpaid
			$writtenoff = in_array((string) $inv->close_code, array(Facture::CLOSECODE_DISCOUNTVAT, Facture::CLOSECODE_BANKCHARGE, Facture::CLOSECODE_WITHHOLDINGTAX), true);
			if ((int) $inv->fk_statut !== Facture::STATUS_CLOSED || (!$inv->paye && !$writtenoff)) {
				return false;
			}
			$paid += (float) $inv->total_ttc;
			$count++;
		}
		return $count > 0 && $paid >= (float) $order->total_ttc - 0.005;
	}
}
