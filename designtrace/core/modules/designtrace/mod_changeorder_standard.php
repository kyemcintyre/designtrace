<?php
/* Copyright (C) 2005-2010	Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2005-2009	Regis Houssin				<regis.houssin@inodbox.com>
 * Copyright (C) 2024		MDW							<mdeweerd@users.noreply.github.com>
 * Copyright (C) 2026		Kye McIntyre
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
 * or see https://www.gnu.org/
 */

/**
 *  \file       designtrace/core/modules/designtrace/mod_changeorder_standard.php
 *  \ingroup    designtrace
 *  \brief      Standard numbering rule for change orders: ECO-0001
 */
dol_include_once('/designtrace/core/modules/designtrace/modules_changeorder.php');


/**
 *	Standard numbering rule for change orders: ECO- and one sequence per entity that never
 *	resets. The counter has at least 4 digits and simply grows past 9999.
 */
class mod_changeorder_standard extends ModeleNumRefChangeOrder
{
	/**
	 * @var string Version
	 */
	public $version = 'dolibarr';

	/**
	 * @var string Prefix, separator included
	 */
	public $prefix = 'ECO-';

	/**
	 * @var int Minimum number of digits of the counter (zero padded)
	 */
	public $padding = 4;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var string name
	 */
	public $name = 'standard';

	/**
	 * @var int		Position
	 */
	public $position = 40;


	/**
	 *  Return description of numbering module
	 *
	 *  @param      Translate   $langs Translate Object
	 *  @return     string             Text with description
	 */
	public function info($langs)
	{
		$langs->load("designtrace@designtrace");
		return $langs->trans("ChangeOrderNumRefModelDesc", $this->getExample());
	}


	/**
	 *  Return an example of numbering
	 *
	 *  @return     string      Example
	 */
	public function getExample()
	{
		return $this->prefix.sprintf('%0'.$this->padding.'u', 1);
	}


	/**
	 *  Checks the numbers already in the database do not prevent this numbering working:
	 *  any existing ref with our prefix must be followed by digits only.
	 *
	 *  @param  CommonObject	$object		Object we need next value for
	 *  @return boolean						false if conflict, true if ok
	 */
	public function canBeActivated($object)
	{
		global $conf, $langs, $db;

		$sql = "SELECT t.ref FROM ".$db->prefix()."designtrace_changeorder as t";
		$sql .= " WHERE t.ref LIKE '".$db->escape($this->prefix)."%'";
		$sql .= " AND t.entity = ".((int) $conf->entity);
		$resql = $db->query($sql);
		if (!$resql) {
			$this->error = $db->lasterror();
			return false;
		}
		while ($obj = $db->fetch_object($resql)) {
			if (!preg_match('/^'.preg_quote($this->prefix, '/').'[0-9]+$/', $obj->ref)) {
				$langs->load("errors");
				$this->error = $langs->trans('ErrorNumRefModel', $obj->ref);
				$db->free($resql);
				return false;
			}
		}
		$db->free($resql);

		return true;
	}

	/**
	 * 	Return next free value
	 *
	 *  @param  CommonObject	$object		Object we need next value for
	 *  @return string|int<-1,0>			Next value if OK, <=0 if KO
	 */
	public function getNextValue($object)
	{
		global $db, $conf;

		$posindice = strlen($this->prefix) + 1;
		$sql = "SELECT MAX(CAST(SUBSTRING(t.ref FROM ".((int) $posindice).") AS SIGNED)) as max";
		$sql .= " FROM ".$db->prefix()."designtrace_changeorder as t";
		$sql .= " WHERE t.ref LIKE '".$db->escape($this->prefix)."%'";
		$sql .= " AND t.entity = ".((int) $conf->entity);

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__." ".$db->lasterror(), LOG_ERR);
			return -1;
		}
		$obj = $db->fetch_object($resql);
		$max = $obj ? (int) $obj->max : 0;
		$db->free($resql);

		$ref = $this->prefix.sprintf('%0'.$this->padding.'u', $max + 1);

		dol_syslog(__METHOD__." return ".$ref);
		return $ref;
	}
}
