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
 *  \file       designtrace/core/modules/designtrace/mod_component_standard.php
 *  \ingroup    designtrace
 *  \brief      Standard numbering rule for Component: PRT-0001, ASM-0001, DRW-0001
 */
dol_include_once('/designtrace/core/modules/designtrace/modules_component.php');
dol_include_once('/designtrace/class/component.class.php');


/**
 *	Standard numbering rule for Component.
 *
 *	The prefix depends on the component type (part, assembly, drawing). Each type has one
 *	sequence per entity, shared by all designs, that never resets: numbers are globally
 *	unique because a component can be referenced from other designs. The counter has at
 *	least 4 digits and simply grows past 9999.
 */
class mod_component_standard extends ModeleNumRefComponent
{
	/**
	 * Dolibarr version of the loaded document
	 * @var string Version, possible values are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated' or a version string like 'x.y.z'''|'development'|'dolibarr'|'experimental'
	 */
	public $version = 'dolibarr';

	/**
	 * @var array<int,string> Prefix of every ref per component type, separator included
	 */
	public $prefixes = array(
		Component::TYPE_PART => 'PRT-',
		Component::TYPE_ASSEMBLY => 'ASM-',
		Component::TYPE_DRAWING => 'DRW-',
	);

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
		return $langs->trans("ComponentNumRefModelDesc", $this->getExample());
	}


	/**
	 *  Return an example of numbering
	 *
	 *  @return     string      Example
	 */
	public function getExample()
	{
		$examples = array();
		foreach ($this->prefixes as $prefix) {
			$examples[] = $prefix.sprintf('%0'.$this->padding.'u', 1);
		}
		return implode(', ', $examples);
	}


	/**
	 *  Checks if the numbers already in the database do not
	 *  cause conflicts that would prevent this numbering working.
	 *
	 *  Any existing ref with one of our prefixes must be followed by digits only.
	 *
	 *  @param  CommonObject	$object		Object we need next value for
	 *  @return bool						false if conflict, true if ok
	 */
	public function canBeActivated($object)
	{
		global $conf, $langs, $db;

		foreach ($this->prefixes as $prefix) {
			$sql = "SELECT t.ref FROM ".$db->prefix()."designtrace_component as t";
			$sql .= " WHERE t.ref LIKE '".$db->escape($prefix)."%'";
			$sql .= " AND t.entity = ".((int) $conf->entity);

			$resql = $db->query($sql);
			if (!$resql) {
				$this->error = $db->lasterror();
				return false;
			}
			while ($obj = $db->fetch_object($resql)) {
				if (!preg_match('/^'.preg_quote($prefix, '/').'[0-9]+$/', $obj->ref)) {
					$langs->load("errors");
					$this->error = $langs->trans('ErrorNumRefModel', $obj->ref);
					$db->free($resql);
					return false;
				}
			}
			$db->free($resql);
		}

		return true;
	}

	/**
	 * 	Return next free value
	 *
	 *  @param  Component		$object		Object we need next value for (its type sets the prefix)
	 *  @return string|int<-1,0>			Next value if OK, <=0 if KO
	 */
	public function getNextValue($object)
	{
		global $db, $conf;

		$type = (int) $object->type;
		if (!isset($this->prefixes[$type])) {
			$this->error = 'ErrorBadComponentType';
			dol_syslog(__METHOD__." unknown component type ".$type, LOG_ERR);
			return -1;
		}
		$prefix = $this->prefixes[$type];

		$posindice = strlen($prefix) + 1;
		$sql = "SELECT MAX(CAST(SUBSTRING(t.ref FROM ".((int) $posindice).") AS SIGNED)) as max";
		$sql .= " FROM ".$db->prefix()."designtrace_component as t";
		$sql .= " WHERE t.ref LIKE '".$db->escape($prefix)."%'";
		$sql .= " AND t.entity = ".((int) $conf->entity);

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__." ".$db->lasterror(), LOG_ERR);
			return -1;
		}
		$obj = $db->fetch_object($resql);
		$max = $obj ? (int) $obj->max : 0;
		$db->free($resql);

		$ref = $prefix.sprintf('%0'.$this->padding.'u', $max + 1);

		dol_syslog(__METHOD__." return ".$ref);
		return $ref;
	}
}
