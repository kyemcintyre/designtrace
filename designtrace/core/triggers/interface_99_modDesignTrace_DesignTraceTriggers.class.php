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
 *	\brief      Triggers of DesignTrace: carry revision and design links from quotes to orders to invoices
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
		$this->description = "DesignTrace triggers: carry linked design revisions from quotes to orders and invoices.";
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

		return $result > 0 ? 1 : 0;
	}
}
