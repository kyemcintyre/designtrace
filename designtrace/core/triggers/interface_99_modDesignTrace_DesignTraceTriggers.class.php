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
 *	\file       designtrace/core/triggers/interface_99_modDesignTrace_DesignTraceTriggers.class.php
 *	\ingroup    designtrace
 *	\brief      Triggers of DesignTrace: carry revision and design links from quotes to orders to invoices, and close
 *				design-only orders once paid
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';


/**
 *	Triggers of DesignTrace
 */
class InterfaceDesignTraceTriggers extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = "products";
		$this->description = "DesignTrace triggers: carry linked design revisions from quotes to orders and invoices, and close paid design-only orders.";
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'fa-drafting-compass';
	}

	/**
	 * When an order is made from a quote, or an invoice from an order, link it to the same
	 * component revisions and designs, as Dolibarr does with its own links.
	 *
	 * @param	string			$action		Event action code
	 * @param	CommonObject	$object		Object
	 * @param	User			$user		Object user
	 * @param	Translate		$langs		Object langs
	 * @param	Conf			$conf		Object conf
	 * @return	int							<0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('designtrace')) {
			return 0;
		}
		// A paid invoice may complete a design-only order: closing it delivers its files. Never fails the payment
		if ($action === 'BILL_PAYED') {
			dol_include_once('/designtrace/class/orderclosing.class.php');
			return DesignTraceOrderClosing::closePaidOrders($this->db, $user, $object) ? 1 : 0;
		}
		// Revision credits: an order's credits start when it closes and are taken back, unused part only, when it is cancelled or its invoice credited
		if ($action === 'ORDER_CLOSE' || $action === 'ORDER_CANCEL' || $action === 'BILL_VALIDATE') {
			return $this->runCreditTrigger($action, $object, $user);
		}
		if ($action !== 'ORDER_CREATE' && $action !== 'BILL_CREATE') {
			return 0;
		}

		dol_include_once('/designtrace/class/doclinks.class.php');
		$result = DesignTraceDocLinks::carryOver($this->db, $object);
		if ($result < 0) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}
		dol_syslog("Trigger '".$this->name."' for action '".$action."' launched by ".__FILE__.". id=".$object->id." links carried over: ".$result);

		// An order made from a quote also keeps the quote's licence override and acceptances
		if ($action === 'ORDER_CREATE') {
			dol_include_once('/designtrace/class/licensing.class.php');
			$quotes = array();
			$origintype = (!empty($object->origin_type) ? $object->origin_type : (!empty($object->origin) ? $object->origin : ''));
			if ($origintype == 'propal' && !empty($object->origin_id)) {
				$quotes[] = (int) $object->origin_id;
			}
			if (!empty($object->linked_objects['propal'])) {
				foreach ((array) $object->linked_objects['propal'] as $id) {
					$quotes[] = (int) $id;
				}
			}
			foreach (array_unique($quotes) as $quoteid) {
				if (DesignTraceLicensing::carryOver($this->db, $user, $object, $quoteid) < 0) {
					$this->error = $this->db->lasterror();
					$this->errors[] = $this->error;
					return -1;
				}
			}
		}

		return $result > 0 ? 1 : 0;
	}

	/**
	 * Revision credits bought on orders (see DesignTraceCredits)
	 *
	 * @param	string			$action		ORDER_CLOSE, ORDER_CANCEL or BILL_VALIDATE
	 * @param	CommonObject	$object		Order, or invoice
	 * @param	User			$user		User
	 * @return	int						<0 if KO, 0 if nothing to do, >0 if OK
	 */
	private function runCreditTrigger($action, $object, User $user)
	{
		dol_include_once('/designtrace/class/credits.class.php');
		$actor = 'staff:'.$user->login;
		if ($action === 'ORDER_CLOSE') {
			$result = DesignTraceCredits::grantFromOrder($this->db, $object, (int) $user->id);
			return $result < 0 ? -1 : ($result > 0 ? 1 : 0);
		}
		$orderids = array();
		if ($action === 'ORDER_CANCEL') {
			$orderids[] = (int) $object->id;
		} elseif ((int) $object->type === 2 && !empty($object->fk_facture_source)) {
			// A credit note against an invoice made from orders
			$resql = $this->db->query("SELECT fk_source FROM ".$this->db->prefix()."element_element WHERE sourcetype = 'commande' AND targettype = 'facture' AND fk_target = ".((int) $object->fk_facture_source));
			while ($resql && ($obj = $this->db->fetch_object($resql))) {
				$orderids[] = (int) $obj->fk_source;
			}
		}
		foreach ($orderids as $orderid) {
			if (DesignTraceCredits::reverseForOrder($this->db, $orderid, $actor) < 0) {
				return -1;
			}
		}
		return empty($orderids) ? 0 : 1;
	}
}
