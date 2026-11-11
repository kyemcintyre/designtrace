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
 *  \file       designtrace/core/modules/designtrace/mod_design_standard.php
 *  \ingroup    designtrace
 *  \brief      Standard numbering rule for Design: DSN-0001, DSN-0002, ...
 */
dol_include_once('/designtrace/core/modules/designtrace/modules_design.php');


/**
 *	Standard numbering rule for Design.
 *
 *	Refs are the prefix followed by a counter of at least 4 digits (DSN-0001). The counter is a
 *	single sequence per entity that never resets; past 9999 it simply grows (DSN-10000).
 *	Draft refs such as (PROV12) are ignored when looking for the last number.
 */
class mod_design_standard extends ModeleNumRefDesign
{
	/**
	 * Dolibarr version of the loaded document
	 * @var string Version, possible values are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated' or a version string like 'x.y.z'''|'development'|'dolibarr'|'experimental'
	 */
	public $version = 'dolibarr';

	/**
	 * @var string Prefix of every ref, separator included
	 */
	public $prefix = 'DSN-';

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
		return $langs->trans("DesignTraceNumRefModelDesc", $this->getExample());
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
	 *  Checks if the numbers already in the database do not
	 *  cause conflicts that would prevent this numbering working.
	 *
	 *  Any existing ref with our prefix must be followed by digits only, otherwise the counter
	 *  cannot be computed reliably.
	 *
	 *  @param  CommonObject	$object		Object we need next value for
	 *  @return bool						false if conflict, true if ok
	 */
	public function canBeActivated($object)
	{
		global $conf, $langs, $db;

		$sql = "SELECT t.ref FROM ".$db->prefix()."designtrace_design as t";
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
	 *  @param  Design		$object		Object we need next value for
	 *  @return string|int<-1,0>			Next value if OK, <=0 if KO
	 */
	public function getNextValue($object)
	{
		global $db, $conf;

		$posindice = strlen($this->prefix) + 1;
		$sql = "SELECT MAX(CAST(SUBSTRING(t.ref FROM ".((int) $posindice).") AS SIGNED)) as max";
		$sql .= " FROM ".$db->prefix()."designtrace_design as t";
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
