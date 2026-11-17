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
 * \file        designtrace/class/grant.class.php
 * \ingroup     designtrace
 * \brief       Design-level access grants and the delivered marker
 *
 * A grant gives a customer a whole design or single components without a quote, order or ticket.
 * It pins one revision per component (moved only by hand, never by a new release), may expire and
 * can be revoked. Ended grants are kept for the download log and licence record. A revision is
 * "delivered" to a customer as soon as a grant is given for it.
 */

dol_include_once('/designtrace/class/design.class.php');
dol_include_once('/designtrace/class/componentrevision.class.php');
dol_include_once('/designtrace/class/componentlink.class.php');

/**
 * Access grants
 */
class DesignTraceGrant
{
	/**
	 * SQL condition for a revision a grant opens besides its pin: released, or released and since made obsolete
	 * (never a draft or one in review)
	 *
	 * @param	string	$alias	Alias of the componentrevision table
	 * @return	string
	 */
	public static function sqlOpensRevision($alias)
	{
		return "(".$alias.".status IN (".((int) ComponentRevision::STATUS_RELEASED).", ".((int) ComponentRevision::STATUS_OBSOLETE).") AND ".$alias.".date_release IS NOT NULL)";
	}

	/**
	 * The revisions a grant opens on each of these components (see sqlOpensRevision())
	 *
	 * @param	DoliDB	$db				Database handler
	 * @param	int[]	$componentids	Components
	 * @return	array<int,int[]>		Component id => revision ids
	 */
	public static function fetchOpenedRevisionIds($db, $componentids)
	{
		$ids = array();
		if (empty($componentids)) {
			return $ids;
		}
		$sql = "SELECT r.rowid, r.fk_component FROM ".$db->prefix()."designtrace_componentrevision as r";
		$sql .= " WHERE r.fk_component IN (".$db->sanitize(implode(',', array_map('intval', $componentids))).") AND ".self::sqlOpensRevision('r');
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$ids[(int) $obj->fk_component][] = (int) $obj->rowid;
		}
		return $ids;
	}

	/**
	 * Give a customer access to components of a design
	 *
	 * @param	DoliDB		$db				Database handler
	 * @param	User		$user			Staff user
	 * @param	int			$socid			Customer
	 * @param	int			$designid		Design
	 * @param	int[]		$componentids	Components; empty = the whole design (every component with a released revision)
	 * @param	array<int,int>	$pins		Revision to pin per component id; default = its current (latest released) revision
	 * @param	?int		$expiry			Expiry timestamp, null = none
	 * @param	string		$note			Note
	 * @return	int						Grant id, <0 on error (-2 nothing to grant, -3 invalid pin, -4 design not active or customer unknown)
	 */
	public static function create($db, $user, $socid, $designid, $componentids = array(), $pins = array(), $expiry = null, $note = '')
	{
		global $conf;

		if ((int) $socid <= 0 || (int) $designid <= 0) {
			return -1;
		}
		// A design that is not active (draft, archived) is not given away; the customer must exist
		$resql = $db->query("SELECT 1 FROM ".$db->prefix()."designtrace_design WHERE rowid = ".((int) $designid)." AND status = ".((int) Design::STATUS_VALIDATED));
		$ok = $resql && $db->num_rows($resql) > 0;
		$resql = $db->query("SELECT 1 FROM ".$db->prefix()."societe WHERE rowid = ".((int) $socid));
		if (!$ok || !$resql || !$db->num_rows($resql)) {
			return -4;
		}
		$whole = empty($componentids);
		$sql = "SELECT c.rowid FROM ".$db->prefix()."designtrace_component as c WHERE c.fk_design = ".((int) $designid);
		if (!$whole) {
			$sql .= " AND c.rowid IN (".$db->sanitize(implode(',', array_map('intval', $componentids))).")";
		}
		$resql = $db->query($sql);
		if (!$resql) {
			return -1;
		}
		$components = array();
		while ($obj = $db->fetch_object($resql)) {
			$components[] = (int) $obj->rowid;
		}
		$current = ComponentLink::getCurrentRevisionIds($db, $components);
		$chosen = array();
		foreach ($components as $cid) {
			$revid = isset($pins[$cid]) ? (int) $pins[$cid] : (isset($current[$cid]) ? (int) $current[$cid] : 0);
			if ($revid <= 0) {
				continue;
			}
			if (!self::isPinnable($db, $cid, $revid)) {
				return -3;
			}
			$chosen[$cid] = $revid;
		}
		if (empty($chosen)) {
			return -2;
		}

		$db->begin();
		$sql = "INSERT INTO ".$db->prefix()."designtrace_grant (entity, fk_soc, fk_design, whole_design, date_expiry, note, date_creation, fk_user_creat)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $socid).", ".((int) $designid).", ".($whole ? 1 : 0).", ";
		$sql .= ($expiry ? "'".$db->idate((int) $expiry)."'" : 'NULL').", '".$db->escape(dol_trunc($note, 255, 'right', 'UTF-8', 1))."', '".$db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$db->query($sql)) {
			$db->rollback();
			return -1;
		}
		$id = (int) $db->last_insert_id($db->prefix().'designtrace_grant');
		foreach ($chosen as $cid => $revid) {
			if (!self::writePin($db, $id, $cid, $revid) || self::markDelivered($db, $socid, $revid, 'grant', $id) < 0) {
				$db->rollback();
				return -1;
			}
		}
		$db->commit();
		return $id;
	}

	/**
	 * A revision can be pinned when it belongs to the component and is released
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$componentid	Component
	 * @param	int		$revid		Revision
	 * @return	bool
	 */
	private static function isPinnable($db, $componentid, $revid)
	{
		$sql = "SELECT rowid FROM ".$db->prefix()."designtrace_componentrevision";
		$sql .= " WHERE rowid = ".((int) $revid)." AND fk_component = ".((int) $componentid)." AND status IN (".((int) ComponentRevision::STATUS_RELEASED).", ".((int) ComponentRevision::STATUS_OBSOLETE).")";
		$resql = $db->query($sql);
		return $resql && $db->num_rows($resql) > 0;
	}

	/**
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$grantid	Grant
	 * @param	int		$cid		Component
	 * @return	bool
	 */
	public static function hasPin($db, $grantid, $cid)
	{
		$resql = $db->query("SELECT 1 FROM ".$db->prefix()."designtrace_grant_pin WHERE fk_grant = ".((int) $grantid)." AND fk_component = ".((int) $cid));
		return $resql && $db->num_rows($resql) > 0;
	}

	/**
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$grantid	Grant
	 * @param	int		$cid		Component
	 * @param	int		$revid		Revision
	 * @return	bool
	 */
	private static function writePin($db, $grantid, $cid, $revid)
	{
		$sql = "INSERT INTO ".$db->prefix()."designtrace_grant_pin (fk_grant, fk_component, fk_revision) VALUES (".((int) $grantid).", ".((int) $cid).", ".((int) $revid).")";
		return (bool) $db->query($sql);
	}

	/**
	 * Revoke a grant. The record, pins, download log and licence acceptance stay.
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	User	$user		Staff user
	 * @param	int		$grantid	Grant
	 * @return	int					1 ok, 0 not found or already revoked, <0 error
	 */
	public static function revoke($db, $user, $grantid)
	{
		$sql = "UPDATE ".$db->prefix()."designtrace_grant SET date_revoked = '".$db->idate(dol_now())."', fk_user_revoke = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $grantid)." AND date_revoked IS NULL";
		$resql = $db->query($sql);
		if (!$resql) {
			return -1;
		}
		return $db->affected_rows($resql) > 0 ? 1 : 0;
	}

	/**
	 * Change or clear the expiry of a grant
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$grantid	Grant
	 * @param	?int	$expiry		Timestamp, null = never expires
	 * @return	int					1 ok, <0 error
	 */
	public static function setExpiry($db, $grantid, $expiry)
	{
		$sql = "UPDATE ".$db->prefix()."designtrace_grant SET date_expiry = ".($expiry ? "'".$db->idate((int) $expiry)."'" : 'NULL')." WHERE rowid = ".((int) $grantid);
		return $db->query($sql) ? 1 : -1;
	}

	/**
	 * Move the pin of a component to another released revision, by hand. Counts as delivered.
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$grantid	Grant
	 * @param	int		$cid		Component (must be pinned by the grant)
	 * @param	int		$revid		New revision
	 * @return	int					1 ok, -3 revision cannot be pinned, <0 error
	 */
	public static function movePin($db, $grantid, $cid, $revid)
	{
		$grant = self::fetchOne($db, $grantid);
		if (!$grant || !self::isActive($grant) || !self::isPinnable($db, $cid, $revid)) {
			return -3;
		}
		$sql = "UPDATE ".$db->prefix()."designtrace_grant_pin SET fk_revision = ".((int) $revid)." WHERE fk_grant = ".((int) $grantid)." AND fk_component = ".((int) $cid);
		$resql = $db->query($sql);
		if (!$resql) {
			return -1;
		}
		if (!self::hasPin($db, $grantid, $cid)) {
			return -3;	// The grant does not cover that component: nothing was moved, nothing is delivered
		}
		return self::markDelivered($db, (int) $grant->fk_soc, $revid, 'grant', $grantid) >= 0 ? 1 : -1;
	}

	/**
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$grantid	Grant
	 * @return	stdClass|null
	 */
	public static function fetchOne($db, $grantid)
	{
		$resql = $db->query("SELECT * FROM ".$db->prefix()."designtrace_grant WHERE rowid = ".((int) $grantid));
		$obj = $resql ? $db->fetch_object($resql) : null;
		return $obj ?: null;
	}

	/**
	 * Whether a grant row is in force now
	 *
	 * @param	stdClass	$grant	Row of fetchOne()/fetchForCustomer()
	 * @param	?int		$now	Timestamp, default now
	 * @return	bool
	 */
	public static function isActive($grant, $now = null)
	{
		$now = $now ?: dol_now();
		$expiry = empty($grant->date_expiry) ? 0 : (is_numeric($grant->date_expiry) ? (int) $grant->date_expiry : strtotime($grant->date_expiry.' UTC'));
		return empty($grant->date_revoked) && (!$expiry || $expiry > $now);
	}

	/**
	 * Grants of a customer, with their pins
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer
	 * @param	int		$designid	Only this design (0 = all)
	 * @return	stdClass[]			Grant rows plus ->active (bool) and ->pins (array component id => revision id), newest first
	 */
	public static function fetchForCustomer($db, $socid, $designid = 0)
	{
		global $conf;

		$sql = "SELECT g.* FROM ".$db->prefix()."designtrace_grant as g WHERE g.fk_soc = ".((int) $socid)." AND g.entity = ".((int) $conf->entity);
		if ((int) $designid > 0) {
			$sql .= " AND g.fk_design = ".((int) $designid);
		}
		return self::loadGrants($db, $sql);
	}

	/**
	 * Grants of a design, every customer, with their pins
	 *
	 * @param	DoliDB	$db				Database handler
	 * @param	int		$designid		Design
	 * @param	int		$componentid	Only the grants covering this component (0 = all)
	 * @return	stdClass[]				As fetchForCustomer(), newest first
	 */
	public static function fetchForDesign($db, $designid, $componentid = 0)
	{
		global $conf;

		$sql = "SELECT g.* FROM ".$db->prefix()."designtrace_grant as g WHERE g.fk_design = ".((int) $designid)." AND g.entity = ".((int) $conf->entity);
		if ((int) $componentid > 0) {
			$sql .= " AND EXISTS (SELECT 1 FROM ".$db->prefix()."designtrace_grant_pin as p WHERE p.fk_grant = g.rowid AND p.fk_component = ".((int) $componentid).")";
		}
		return self::loadGrants($db, $sql);
	}

	/**
	 * Grant rows of a query on designtrace_grant (alias g), with ->active and ->pins
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	string	$sql	SELECT g.* ... WHERE ...
	 * @return	stdClass[]		Newest first
	 */
	private static function loadGrants($db, $sql)
	{
		$grants = array();
		$resql = $db->query($sql." ORDER BY g.rowid DESC");
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$obj->date_expiry = $db->jdate($obj->date_expiry);
			$obj->date_revoked = $db->jdate($obj->date_revoked);
			$obj->active = self::isActive($obj);
			$obj->pins = array();
			$grants[(int) $obj->rowid] = $obj;
		}
		if ($grants) {
			$resql = $db->query("SELECT fk_grant, fk_component, fk_revision FROM ".$db->prefix()."designtrace_grant_pin WHERE fk_grant IN (".$db->sanitize(implode(',', array_keys($grants))).")");
			while ($resql && ($obj = $db->fetch_object($resql))) {
				$grants[(int) $obj->fk_grant]->pins[(int) $obj->fk_component] = (int) $obj->fk_revision;
			}
		}
		return array_values($grants);
	}

	/**
	 * Record that a revision has been delivered to a customer (once per customer, revision and source)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer
	 * @param	int		$revid		Revision
	 * @param	string	$element	grant, commande...
	 * @param	int		$fkelement	Id of the grant, order...
	 * @return	int					1 ok, <0 error
	 */
	public static function markDelivered($db, $socid, $revid, $element, $fkelement)
	{
		global $conf;

		$sql = "INSERT INTO ".$db->prefix()."designtrace_delivered (entity, fk_soc, fk_revision, element, fk_element, date_delivered)";
		$sql .= " SELECT ".((int) $conf->entity).", ".((int) $socid).", ".((int) $revid).", '".$db->escape($element)."', ".((int) $fkelement).", '".$db->idate(dol_now())."'";
		$sql .= " FROM (SELECT 1) as dummy WHERE NOT EXISTS (SELECT 1 FROM ".$db->prefix()."designtrace_delivered";
		$sql .= " WHERE fk_soc = ".((int) $socid)." AND fk_revision = ".((int) $revid)." AND element = '".$db->escape($element)."' AND fk_element = ".((int) $fkelement).")";
		return $db->query($sql) ? 1 : -1;
	}

	/**
	 * Whether a revision was delivered to a customer
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$socid	Customer
	 * @param	int		$revid	Revision
	 * @return	bool
	 */
	public static function isDelivered($db, $socid, $revid)
	{
		$resql = $db->query("SELECT rowid FROM ".$db->prefix()."designtrace_delivered WHERE fk_soc = ".((int) $socid)." AND fk_revision = ".((int) $revid));
		return $resql && $db->num_rows($resql) > 0;
	}

	/**
	 * Whether a linked order or project of the customer is complete for a design: releases the
	 * "Visible on completion" files of grants
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer
	 * @param	int		$designid	Design
	 * @return	bool
	 */
	public static function isDesignCompleted($db, $socid, $designid)
	{
		$sql = "SELECT 1 FROM ".$db->prefix()."element_element as ee";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = ee.fk_target AND ee.targettype = 'designtrace_componentrevision'";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component AND c.fk_design = ".((int) $designid);
		$sql .= " LEFT JOIN ".$db->prefix()."commande as o ON ee.sourcetype = 'commande' AND o.rowid = ee.fk_source AND o.fk_soc = ".((int) $socid)." AND o.fk_statut = 3";
		$sql .= " LEFT JOIN ".$db->prefix()."projet as p ON ee.sourcetype = 'project' AND p.rowid = ee.fk_source AND p.fk_soc = ".((int) $socid)." AND p.fk_statut = 2";
		$sql .= " WHERE o.rowid IS NOT NULL OR p.rowid IS NOT NULL";
		$resql = $db->query($sql." LIMIT 1");
		return $resql && $db->num_rows($resql) > 0;
	}
}
