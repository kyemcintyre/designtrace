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
 * \file        designtrace/class/credits.class.php
 * \ingroup     designtrace
 * \brief       Revision credits: a per-design allowance of small changes after first delivery
 *
 * Credits belong to a design and its customer. They are dormant until the design is first delivered
 * (see DesignTraceLibrary::getDeliveredRevisionIds()), then active until they expire. The change order
 * is the transaction: approving a post-delivery customer change request uses a credit, waives it or
 * leaves it to be billed. Everything that moves a balance is a row of the ledger
 * (llx_designtrace_creditledger), which is never edited; grants (llx_designtrace_creditgrant) say where
 * the credits came from and when they run out. Designs with no grant and no CAD tier are outside the
 * scheme: their change orders approve as they always did.
 */

dol_include_once('/designtrace/class/designlibrary.class.php');

/**
 * Revision credits
 */
class DesignTraceCredits
{
	const TIER_NONE = 0;
	const TIER_HOBBY = 1;
	const TIER_COMMERCIAL = 2;

	const SIZE_NONE = 0;
	const SIZE_MINOR = 1;
	const SIZE_MAJOR = 2;

	const SOURCE_DEFAULT = 1;
	const SOURCE_ORDER = 2;
	const SOURCE_MANUAL = 3;

	const STATUS_DORMANT = 0;
	const STATUS_ACTIVE = 1;
	const STATUS_ENDED = 2;

	const OUTCOME_NONE = 'none';
	const OUTCOME_CREDIT = 'credit';
	const OUTCOME_WAIVED = 'waived';
	const OUTCOME_BILLED = 'billed';

	/** @var array<int,string> Months an expiry can be set to (0 = never) */
	const EXPIRY_CHOICES = array(0, 1, 3, 6, 12);

	/**
	 * @param	string	$code	hobby or commercial
	 * @return	int				Tier constant, TIER_NONE when unknown
	 */
	public static function tierFromCode($code)
	{
		$map = array('hobby' => self::TIER_HOBBY, 'commercial' => self::TIER_COMMERCIAL);
		return $map[strtolower(trim((string) $code))] ?? self::TIER_NONE;
	}

	/**
	 * @param	int		$tier	Tier constant
	 * @return	string			hobby, commercial or ''
	 */
	public static function tierCode($tier)
	{
		return array(self::TIER_HOBBY => 'hobby', self::TIER_COMMERCIAL => 'commercial')[(int) $tier] ?? '';
	}

	/**
	 * The CAD rate of a tier: a Dolibarr service (reference or id) chosen in the setup (DESIGNTRACE_CREDIT_RATE_PRODUCT_1 / _2)
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$tier	Tier constant
	 * @return	array<string,mixed>|null	label, amount_excl_tax (null when the service has no price), unit, currency; null with no tier
	 */
	public static function getRate($db, $tier)
	{
		global $conf;

		$tier = (int) $tier;
		if ($tier !== self::TIER_HOBBY && $tier !== self::TIER_COMMERCIAL) {
			return null;
		}
		$label = ($tier === self::TIER_HOBBY ? 'CAD hobby rate' : 'CAD commercial rate');
		$amount = null;
		$product = trim(getDolGlobalString('DESIGNTRACE_CREDIT_RATE_PRODUCT_'.$tier));
		if ($product !== '') {
			// The service's reference (or its id)
			$resql = $db->query("SELECT label, price FROM ".$db->prefix()."product WHERE ref = '".$db->escape($product)."' OR rowid = ".((int) $product)." ORDER BY (ref = '".$db->escape($product)."') DESC");
			$obj = $resql ? $db->fetch_object($resql) : null;
			if ($obj) {
				$label = (string) $obj->label;
				$amount = (float) $obj->price;
			}
		}
		return array('label' => $label, 'amount_excl_tax' => $amount, 'unit' => 'hour', 'currency' => (string) $conf->currency);
	}

	/**
	 * Add a row to the ledger
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design
	 * @param	int		$socid		Customer
	 * @param	string	$type		grant, use, waive, refund, adjust, expire, reverse or overage
	 * @param	int		$amount		Signed whole credits (0 for waive and overage)
	 * @param	int		$grantid	Grant the movement is on (0 = none)
	 * @param	int		$ecoid		Change order (0 = none)
	 * @param	string	$public		Note the customer sees
	 * @param	string	$private	Staff-only note
	 * @param	string	$actor		Who did it
	 * @return	int					Row id, <0 on error
	 */
	private static function addLedger($db, $designid, $socid, $type, $amount, $grantid, $ecoid, $public, $private, $actor)
	{
		global $conf;

		$sql = "INSERT INTO ".$db->prefix()."designtrace_creditledger (entity, fk_design, fk_soc, type, amount, fk_grant, fk_eco, note_public, note_private, actor, date_creation) VALUES (";
		$sql .= ((int) $conf->entity).", ".((int) $designid).", ".((int) $socid).", '".$db->escape($type)."', ".((int) $amount).", ";
		$sql .= ((int) $grantid > 0 ? (int) $grantid : 'NULL').", ".((int) $ecoid > 0 ? (int) $ecoid : 'NULL').", ";
		$sql .= "'".$db->escape(dol_trunc((string) $public, 255, 'right', 'UTF-8', 1))."', '".$db->escape(dol_trunc((string) $private, 255, 'right', 'UTF-8', 1))."', ";
		$sql .= "'".$db->escape(dol_trunc((string) $actor, 128, 'right', 'UTF-8', 1))."', '".$db->idate(dol_now())."')";
		if (!$db->query($sql)) {
			return -1;
		}
		return (int) $db->last_insert_id($db->prefix().'designtrace_creditledger');
	}

	/**
	 * Name shown for who did something: staff are "Workshop", the customer's users keep their name
	 *
	 * @param	string	$actor	Stored actor
	 * @return	string
	 */
	private static function publicActor($actor)
	{
		return (strpos((string) $actor, 'portal:') === 0 || strpos((string) $actor, 'site:') === 0) ? (string) $actor : 'Workshop';
	}

	/**
	 * Credits left on a grant (its ledger rows added up)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$grantid	Grant
	 * @return	int
	 */
	public static function grantRemaining($db, $grantid)
	{
		$resql = $db->query("SELECT SUM(amount) as total FROM ".$db->prefix()."designtrace_creditledger WHERE fk_grant = ".((int) $grantid));
		$obj = $resql ? $db->fetch_object($resql) : null;
		return $obj ? (int) $obj->total : 0;
	}

	/**
	 * The balance of a design: every ledger row added up
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design
	 * @return	int
	 */
	public static function getBalance($db, $designid)
	{
		$resql = $db->query("SELECT SUM(amount) as total FROM ".$db->prefix()."designtrace_creditledger WHERE fk_design = ".((int) $designid));
		$obj = $resql ? $db->fetch_object($resql) : null;
		return $obj ? (int) $obj->total : 0;
	}

	/**
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design
	 * @return	stdClass|null		rowid, fk_soc, cad_tier, status
	 */
	private static function loadDesign($db, $designid)
	{
		$resql = $db->query("SELECT rowid, fk_soc, cad_tier, status FROM ".$db->prefix()."designtrace_design WHERE rowid = ".((int) $designid));
		$obj = $resql ? $db->fetch_object($resql) : null;
		return $obj ?: null;
	}

	/**
	 * Whether the design has been delivered to its customer (a revision of it through a closed order or a grant)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design
	 * @param	int		$socid		Customer
	 * @return	bool
	 */
	public static function isDelivered($db, $designid, $socid)
	{
		if ((int) $socid <= 0) {
			return false;
		}
		$ids = DesignTraceLibrary::getDeliveredRevisionIds($db, (int) $socid);
		if (empty($ids)) {
			return false;
		}
		$sql = "SELECT 1 FROM ".$db->prefix()."designtrace_componentrevision as r INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
		$sql .= " WHERE c.fk_design = ".((int) $designid)." AND r.rowid IN (".$db->sanitize(implode(',', array_map('intval', $ids))).")";
		$resql = $db->query($sql);
		return $resql && $db->num_rows($resql) > 0;
	}

	/**
	 * Whether credits apply to a design: it has a CAD tier or any grant
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design
	 * @return	bool
	 */
	public static function isEnabled($db, $designid)
	{
		$design = self::loadDesign($db, $designid);
		if (!$design || (int) $design->fk_soc <= 0) {
			return false;
		}
		if ((int) $design->cad_tier > 0) {
			return true;
		}
		$resql = $db->query("SELECT 1 FROM ".$db->prefix()."designtrace_creditgrant WHERE fk_design = ".((int) $designid)." AND fk_soc = ".((int) $design->fk_soc));
		return $resql && $db->num_rows($resql) > 0;
	}

	/**
	 * Bring a design's grants up to date: dormant ones become active once the design is delivered
	 * (their expiry counts from then), and active ones past their expiry write an expire row for what
	 * was left. Safe to repeat: run on every read and write.
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design
	 * @return	int					Number of changes made
	 */
	public static function sync($db, $designid)
	{
		$design = self::loadDesign($db, $designid);
		if (!$design || (int) $design->fk_soc <= 0) {
			return 0;
		}
		$socid = (int) $design->fk_soc;
		$now = dol_now();
		$changes = 0;
		$delivered = null;

		$resql = $db->query("SELECT rowid, credits, source, expiry_months, status, date_expiry, note_public, note_private FROM ".$db->prefix()."designtrace_creditgrant WHERE fk_design = ".((int) $designid)." AND fk_soc = ".$socid." ORDER BY rowid");
		$grants = array();
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$grants[] = $obj;
		}
		foreach ($grants as $grant) {
			if ((int) $grant->status === self::STATUS_DORMANT) {
				if ($delivered === null) {
					$delivered = self::isDelivered($db, $designid, $socid);
				}
				if (!$delivered) {
					continue;
				}
				$expiry = ($grant->date_expiry ? $db->jdate($grant->date_expiry) : ((int) $grant->expiry_months > 0 ? dol_time_plus_duree($now, (int) $grant->expiry_months, 'm') : null));
				$sql = "UPDATE ".$db->prefix()."designtrace_creditgrant SET status = ".self::STATUS_ACTIVE.", date_activated = '".$db->idate($now)."', date_expiry = ".($expiry ? "'".$db->idate($expiry)."'" : 'NULL')." WHERE rowid = ".((int) $grant->rowid)." AND status = ".self::STATUS_DORMANT;
				if ($db->query($sql)) {
					// One grant row in the ledger, however many times this runs
					$resql2 = $db->query("SELECT 1 FROM ".$db->prefix()."designtrace_creditledger WHERE fk_grant = ".((int) $grant->rowid)." AND type = 'grant'");
					if ($resql2 && $db->num_rows($resql2) === 0) {
						self::addLedger($db, $designid, $socid, 'grant', (int) $grant->credits, (int) $grant->rowid, 0, $grant->note_public, $grant->note_private, 'system');
						$changes++;
					}
				}
				$grant->status = self::STATUS_ACTIVE;
				$grant->date_expiry = ($expiry ? $db->idate($expiry) : null);
			}
			if ((int) $grant->status === self::STATUS_ACTIVE && $grant->date_expiry && $db->jdate($grant->date_expiry) < $now) {
				$left = self::grantRemaining($db, (int) $grant->rowid);
				if ($left > 0) {
					self::addLedger($db, $designid, $socid, 'expire', -$left, (int) $grant->rowid, 0, 'Expired', '', 'system');
				}
				$db->query("UPDATE ".$db->prefix()."designtrace_creditgrant SET status = ".self::STATUS_ENDED." WHERE rowid = ".((int) $grant->rowid));
				$changes++;
			}
		}
		return $changes;
	}

	/**
	 * Add a grant to a design. It is dormant until the design is first delivered, then runs from then.
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design (0 = an order's credits waiting for staff to pick the design)
	 * @param	int		$socid		Customer
	 * @param	int		$credits	Whole credits, > 0
	 * @param	int		$months		Expiry in months from activation (0 = never)
	 * @param	int		$source		SOURCE_*
	 * @param	string	$publicnote	Note the customer sees
	 * @param	string	$privatenote	Staff-only note
	 * @param	int		$userid		User making it
	 * @param	string	$originElement	order for an order line
	 * @param	int		$originLine	Order line id
	 * @param	int		$expiryDate	Fixed expiry timestamp (overrides months)
	 * @return	int					Grant id, <0 on error (-2 bad input, -3 this order line already granted)
	 */
	public static function addGrant($db, $designid, $socid, $credits, $months, $source, $publicnote = '', $privatenote = '', $userid = 0, $originElement = '', $originLine = 0, $expiryDate = 0)
	{
		global $conf;

		$credits = (int) $credits;
		$months = (int) $months;
		if ($credits <= 0 || (int) $socid <= 0 || $months < 0) {
			return -2;
		}
		if ($originElement !== '' && (int) $originLine > 0) {
			$resql = $db->query("SELECT 1 FROM ".$db->prefix()."designtrace_creditgrant WHERE origin_element = '".$db->escape($originElement)."' AND fk_origin_line = ".((int) $originLine));
			if ($resql && $db->num_rows($resql) > 0) {
				return -3;
			}
		}
		$sql = "INSERT INTO ".$db->prefix()."designtrace_creditgrant (entity, fk_design, fk_soc, credits, source, origin_element, fk_origin_line, expiry_months, status, date_expiry, note_public, note_private, date_creation, fk_user_creat) VALUES (";
		$sql .= ((int) $conf->entity).", ".((int) $designid).", ".((int) $socid).", ".$credits.", ".((int) $source).", ";
		$sql .= ($originElement !== '' ? "'".$db->escape($originElement)."'" : 'NULL').", ".((int) $originLine > 0 ? (int) $originLine : 'NULL').", ".$months.", ".self::STATUS_DORMANT.", ";
		$sql .= ($expiryDate ? "'".$db->idate((int) $expiryDate)."'" : 'NULL').", '".$db->escape(dol_trunc((string) $publicnote, 255, 'right', 'UTF-8', 1))."', '".$db->escape(dol_trunc((string) $privatenote, 255, 'right', 'UTF-8', 1))."', '".$db->idate(dol_now())."', ".((int) $userid > 0 ? (int) $userid : 'NULL').")";
		if (!$db->query($sql)) {
			return -1;
		}
		$id = (int) $db->last_insert_id($db->prefix().'designtrace_creditgrant');
		if ((int) $designid > 0) {
			self::sync($db, (int) $designid);
		}
		return $id;
	}

	/**
	 * Give a newly validated design its default grant (dormant), when a customer and a default are set
	 * in the setup: DESIGNTRACE_CREDITS_DEFAULT_TIER, then _HOBBY / _COMMERCIAL credits and _MONTHS expiry
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design
	 * @param	int		$userid		User
	 * @return	int					Grant id, 0 when nothing to give, <0 on error
	 */
	public static function createDefaultGrant($db, $designid, $userid = 0)
	{
		$design = self::loadDesign($db, $designid);
		if (!$design || (int) $design->fk_soc <= 0) {
			return 0;
		}
		$tier = (int) $design->cad_tier;
		if ($tier <= 0) {
			$tier = getDolGlobalInt('DESIGNTRACE_CREDITS_DEFAULT_TIER');
			if ($tier === self::TIER_HOBBY || $tier === self::TIER_COMMERCIAL) {
				$db->query("UPDATE ".$db->prefix()."designtrace_design SET cad_tier = ".$tier." WHERE rowid = ".((int) $designid));
			} else {
				return 0;
			}
		}
		$credits = getDolGlobalInt('DESIGNTRACE_CREDITS_DEFAULT_'.($tier === self::TIER_HOBBY ? 'HOBBY' : 'COMMERCIAL'));
		if ($credits <= 0) {
			return 0;
		}
		$resql = $db->query("SELECT 1 FROM ".$db->prefix()."designtrace_creditgrant WHERE fk_design = ".((int) $designid)." AND source = ".self::SOURCE_DEFAULT);
		if ($resql && $db->num_rows($resql) > 0) {
			return 0;
		}
		return self::addGrant($db, (int) $designid, (int) $design->fk_soc, $credits, getDolGlobalInt('DESIGNTRACE_CREDITS_DEFAULT_MONTHS'), self::SOURCE_DEFAULT, 'Included with the design', '', $userid);
	}

	/**
	 * Set the CAD tier of a design (which rate applies)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design
	 * @param	int		$tier		TIER_*
	 * @return	int					>0 OK, <0 error
	 */
	public static function setTier($db, $designid, $tier)
	{
		$tier = (int) $tier;
		if (!in_array($tier, array(self::TIER_NONE, self::TIER_HOBBY, self::TIER_COMMERCIAL), true) || !self::loadDesign($db, $designid)) {
			return -2;
		}
		return $db->query("UPDATE ".$db->prefix()."designtrace_design SET cad_tier = ".$tier." WHERE rowid = ".((int) $designid)) ? 1 : -1;
	}

	/**
	 * Change when a grant runs out (an exact date, or null for never). A date in the past ends it at once.
	 *
	 * @param	DoliDB		$db			Database handler
	 * @param	int			$grantid	Grant
	 * @param	int|null	$timestamp	New expiry, null = never
	 * @param	string		$actor		Who
	 * @param	string		$publicnote	Note the customer sees
	 * @param	string		$privatenote	Staff-only note
	 * @return	int						>0 OK, <0 error (-2 not found, -3 grant ended)
	 */
	public static function setExpiry($db, $grantid, $timestamp, $actor, $publicnote = '', $privatenote = '')
	{
		$resql = $db->query("SELECT fk_design, fk_soc, status FROM ".$db->prefix()."designtrace_creditgrant WHERE rowid = ".((int) $grantid));
		$grant = $resql ? $db->fetch_object($resql) : null;
		if (!$grant) {
			return -2;
		}
		if ((int) $grant->status === self::STATUS_ENDED) {
			return -3;
		}
		if (!$db->query("UPDATE ".$db->prefix()."designtrace_creditgrant SET date_expiry = ".($timestamp ? "'".$db->idate((int) $timestamp)."'" : 'NULL').", expiry_months = ".($timestamp ? 'expiry_months' : '0')." WHERE rowid = ".((int) $grantid))) {
			return -1;
		}
		if ((int) $grant->fk_design > 0) {
			$text = ($timestamp ? 'Expiry changed to '.dol_print_date((int) $timestamp, 'day') : 'Expiry removed');
			self::addLedger($db, (int) $grant->fk_design, (int) $grant->fk_soc, 'adjust', 0, (int) $grantid, 0, trim($text.($publicnote !== '' ? ': '.$publicnote : '')), $privatenote, $actor);
			self::sync($db, (int) $grant->fk_design);
		}
		return 1;
	}

	/**
	 * Staff adjustment: positive adds a manual grant (own expiry), negative takes credits from the grants
	 * that run out first. A reason the customer sees is required.
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design
	 * @param	int		$amount		Whole credits, not 0
	 * @param	int		$months		Expiry of the new grant in months (positive adjustments)
	 * @param	string	$publicnote	Reason the customer sees (required)
	 * @param	string	$privatenote	Staff-only note
	 * @param	string	$actor		Who
	 * @param	int		$userid		User
	 * @return	int					>0 OK, <0 error (-2 bad input, -3 not enough credits, -4 design has no customer)
	 */
	public static function adjust($db, $designid, $amount, $months, $publicnote, $privatenote, $actor, $userid = 0)
	{
		$amount = (int) $amount;
		$design = self::loadDesign($db, $designid);
		if ($amount === 0 || trim((string) $publicnote) === '') {
			return -2;
		}
		if (!$design || (int) $design->fk_soc <= 0) {
			return -4;
		}
		self::sync($db, (int) $designid);
		if ($amount > 0) {
			$id = self::addGrant($db, (int) $designid, (int) $design->fk_soc, $amount, (int) $months, self::SOURCE_MANUAL, $publicnote, $privatenote, $userid);
			if ($id > 0 && !self::isDelivered($db, (int) $designid, (int) $design->fk_soc)) {
				// Not delivered yet: stays dormant like the rest, and shows in the ledger on delivery
				return 1;
			}
			return $id > 0 ? 1 : $id;
		}
		$need = -$amount;
		if (self::getBalance($db, (int) $designid) < $need) {
			return -3;
		}
		$db->begin();
		foreach (self::activeGrantsBySoonest($db, (int) $designid) as $grant) {
			if ($need <= 0) {
				break;
			}
			$take = min($need, $grant['left']);
			if ($take <= 0) {
				continue;
			}
			if (self::addLedger($db, (int) $designid, (int) $design->fk_soc, 'adjust', -$take, $grant['id'], 0, $publicnote, $privatenote, $actor) < 0) {
				$db->rollback();
				return -1;
			}
			$need -= $take;
		}
		$db->commit();
		return 1;
	}

	/**
	 * Active grants with credits left, the ones running out first first (never-expiring last)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design
	 * @return	array<int,array{id:int,left:int,date_expiry:?int}>
	 */
	private static function activeGrantsBySoonest($db, $designid)
	{
		$list = array();
		$sql = "SELECT rowid, date_expiry FROM ".$db->prefix()."designtrace_creditgrant WHERE fk_design = ".((int) $designid)." AND status = ".self::STATUS_ACTIVE;
		$sql .= " ORDER BY (date_expiry IS NULL), date_expiry, rowid";
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$left = self::grantRemaining($db, (int) $obj->rowid);
			if ($left > 0) {
				$list[] = array('id' => (int) $obj->rowid, 'left' => $left, 'date_expiry' => $obj->date_expiry ? (int) $db->jdate($obj->date_expiry) : null);
			}
		}
		return $list;
	}

	/**
	 * What an ECO means for credits: null unless it is a customer request on a revision already delivered
	 * to the customer, for a design the scheme covers
	 *
	 * @param	ChangeOrder	$eco	Change order
	 * @return	array<string,mixed>|null	design_id (whose allowance), source_design_id, socid, type (problem|change), size_requested, outcome, round
	 */
	public static function getEcoContext($eco)
	{
		$db = $eco->db;
		$info = $eco->getRequestInfo();
		if (!$info || (int) $info->request_type <= 0 || (int) $info->fk_revision_source <= 0 || (int) $eco->fk_soc <= 0) {
			return null;
		}
		$resql = $db->query("SELECT c.fk_design FROM ".$db->prefix()."designtrace_componentrevision as r INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component WHERE r.rowid = ".((int) $info->fk_revision_source));
		$obj = $resql ? $db->fetch_object($resql) : null;
		if (!$obj) {
			return null;
		}
		$source = (int) $obj->fk_design;
		$design = (!empty($info->fk_credit_design) ? (int) $info->fk_credit_design : $source);
		$delivered = DesignTraceLibrary::getDeliveredRevisionIds($db, (int) $eco->fk_soc);
		if (!isset($delivered[(int) $info->fk_revision_source])) {
			return null;
		}
		if (!self::isEnabled($db, $design)) {
			return null;
		}
		return array(
			'design_id' => $design,
			'source_design_id' => $source,
			'socid' => (int) $eco->fk_soc,
			'type' => ((int) $info->request_type === ChangeOrder::REQUEST_PROBLEM ? 'problem' : 'change'),
			'size_requested' => (int) $info->credit_size_requested,
			'outcome' => (string) $info->credit_outcome,
			'round' => (int) $info->round,
		);
	}

	/**
	 * What staff need to decide an ECO's credit: the design whose allowance it would use (and the other designs of the
	 * same customer it touches, to pick one), balances, the CAD rate and what the customer chose
	 *
	 * @param	ChangeOrder	$eco	Change order
	 * @return	array<string,mixed>|null	null when credits do not apply to the ECO (see getEcoContext)
	 */
	public static function getDecisionContext($eco)
	{
		$db = $eco->db;
		$ctx = self::getEcoContext($eco);
		if (!$ctx) {
			return null;
		}
		$ids = array((int) $ctx['design_id'] => true, (int) $ctx['source_design_id'] => true);
		$resql = $db->query("SELECT DISTINCT c.fk_design FROM ".$db->prefix()."designtrace_changeorder_component as ec INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = ec.fk_component WHERE ec.fk_eco = ".((int) $eco->id));
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$ids[(int) $obj->fk_design] = true;
		}
		$sizeNames = array(self::SIZE_MINOR => 'minor', self::SIZE_MAJOR => 'major');
		$designs = array();
		foreach (array_keys($ids) as $id) {
			$res = $db->query("SELECT rowid, ref, label, fk_soc, cad_tier FROM ".$db->prefix()."designtrace_design WHERE rowid = ".((int) $id));
			$d = $res ? $db->fetch_object($res) : null;
			if (!$d || (int) $d->fk_soc !== (int) $ctx['socid'] || !self::isEnabled($db, (int) $id)) {
				continue;
			}
			self::sync($db, (int) $id);
			$designs[] = array('id' => (int) $d->rowid, 'ref' => (string) $d->ref, 'label' => (string) $d->label, 'balance' => self::getBalance($db, (int) $id),
				'tier' => self::tierCode($d->cad_tier), 'rate' => self::getRate($db, (int) $d->cad_tier));
		}
		return array(
			'design_id' => (int) $ctx['design_id'],
			'type' => $ctx['type'],
			'size_requested' => $sizeNames[(int) $ctx['size_requested']] ?? '',
			'outcome' => $ctx['outcome'],
			'designs' => $designs,
		);
	}

	/**
	 * What the form should offer: the outcome the rules lead to for a change of this size
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design
	 * @param	int		$size		SIZE_*
	 * @return	string				credit or billed
	 */
	public static function suggestOutcome($db, $designid, $size)
	{
		self::sync($db, (int) $designid);
		return ((int) $size === self::SIZE_MINOR && self::getBalance($db, (int) $designid) >= 1) ? self::OUTCOME_CREDIT : self::OUTCOME_BILLED;
	}

	/**
	 * Check the customer's side of a post-delivery change request before it is made: the size is needed
	 * for a change, and the rate has to be acknowledged when it would be billed (major, or no credit left)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer
	 * @param	int		$revid		Revision the request is about
	 * @param	string	$type		problem or change
	 * @param	string	$size		minor, major or ''
	 * @param	bool	$rateAck	The customer ticked the rate
	 * @param	string	$error		Set to a code on refusal: size_required, bad_size or rate_ack_required
	 * @return	array<string,mixed>|null|false	Null when credits do not apply, false when refused, else design_id, size, rate (array|null), billable (bool)
	 */
	public static function checkRequest($db, $socid, $revid, $type, $size, $rateAck, &$error)
	{
		$resql = $db->query("SELECT c.fk_design FROM ".$db->prefix()."designtrace_componentrevision as r INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component WHERE r.rowid = ".((int) $revid));
		$obj = $resql ? $db->fetch_object($resql) : null;
		$delivered = DesignTraceLibrary::getDeliveredRevisionIds($db, (int) $socid);
		if (!$obj || !isset($delivered[(int) $revid]) || !self::isEnabled($db, (int) $obj->fk_design)) {
			return null;
		}
		$designid = (int) $obj->fk_design;
		if ($type !== 'change') {
			return array('design_id' => $designid, 'size' => self::SIZE_NONE, 'rate' => null, 'billable' => false);
		}
		$size = strtolower(trim((string) $size));
		if ($size === '') {
			$error = 'size_required';
			return false;
		}
		if ($size !== 'minor' && $size !== 'major') {
			$error = 'bad_size';
			return false;
		}
		$sizeConst = ($size === 'minor' ? self::SIZE_MINOR : self::SIZE_MAJOR);
		$billable = (self::suggestOutcome($db, $designid, $sizeConst) === self::OUTCOME_BILLED);
		if ($billable && !$rateAck) {
			$error = 'rate_ack_required';
			return false;
		}
		$design = self::loadDesign($db, $designid);
		return array('design_id' => $designid, 'size' => $sizeConst, 'rate' => self::getRate($db, (int) $design->cad_tier), 'billable' => $billable);
	}

	/**
	 * Decide what approving a post-delivery change order does to credits (called by ChangeOrder::approve()).
	 * A change needs a decision (use, bill or waive); a problem is free unless staff say bill. Once an ECO
	 * has a decision, later rounds are free: use chargeRound() to charge one.
	 *
	 * @param	ChangeOrder				$eco		Change order
	 * @param	string					$actor		Who approves
	 * @param	array<string,mixed>|null	$credit	design_id, outcome (use|bill|waive|none), size (minor|major), public_note, internal_note
	 * @param	string					$error		Error code on failure
	 * @return	int							1 decision recorded, 0 credits do not apply or already decided, <0 refused
	 */
	public static function decide($eco, $actor, $credit, &$error)
	{
		$db = $eco->db;
		$credit = is_array($credit) ? $credit : array();
		$ctx = self::getEcoContext($eco);
		if (!$ctx) {
			return 0;
		}
		if ($ctx['outcome'] !== self::OUTCOME_NONE) {
			return 0;		// A later round of a change already decided
		}
		// Staff may charge another design's allowance (a request touching several designs)
		$designid = $ctx['design_id'];
		if (!empty($credit['design_id']) && (int) $credit['design_id'] !== $designid) {
			$resql = $db->query("SELECT fk_soc FROM ".$db->prefix()."designtrace_design WHERE rowid = ".((int) $credit['design_id']));
			$other = $resql ? $db->fetch_object($resql) : null;
			if (!$other || (int) $other->fk_soc !== (int) $ctx['socid'] || !self::isEnabled($db, (int) $credit['design_id'])) {
				$error = 'credit_design_invalid';
				return -1;
			}
			$designid = (int) $credit['design_id'];
		}
		self::sync($db, $designid);
		$public = trim((string) ($credit['public_note'] ?? ''));
		$private = trim((string) ($credit['internal_note'] ?? ''));
		$outcome = strtolower(trim((string) ($credit['outcome'] ?? '')));
		$socid = (int) $ctx['socid'];

		if ($ctx['type'] === 'problem') {
			// A fault in delivered work is free unless staff say otherwise
			$result = self::OUTCOME_NONE;
			if ($outcome === 'bill') {
				self::addLedger($db, $designid, $socid, 'overage', 0, 0, (int) $eco->id, $public !== '' ? $public : 'Chargeable: billed at the CAD rate', $private, $actor);
				$result = self::OUTCOME_BILLED;
			} elseif ($outcome === 'waive' && $public !== '') {
				self::addLedger($db, $designid, $socid, 'waive', 0, 0, (int) $eco->id, $public, $private, $actor);
				$result = self::OUTCOME_WAIVED;
			}
			return self::saveDecision($eco, $designid, self::SIZE_NONE, $result);
		}

		// A change: the size the customer chose, which staff may reclassify with a reason the customer sees
		$size = (int) $ctx['size_requested'];
		if (!empty($credit['size'])) {
			$wanted = ($credit['size'] === 'minor' ? self::SIZE_MINOR : ($credit['size'] === 'major' ? self::SIZE_MAJOR : -1));
			if ($wanted < 0) {
				$error = 'bad_size';
				return -1;
			}
			if ($wanted !== $size && $public === '') {
				$error = 'credit_reason_required';
				return -1;
			}
			if ($wanted !== $size) {
				self::addLedger($db, $designid, $socid, 'adjust', 0, 0, (int) $eco->id, 'Size changed to '.($wanted === self::SIZE_MINOR ? 'minor' : 'major').': '.$public, $private, $actor);
			}
			$size = $wanted;
		}
		if (!in_array($outcome, array('use', 'bill', 'waive'), true)) {
			$error = 'credit_decision_required';
			return -1;
		}
		if ($outcome === 'use') {
			if ($size !== self::SIZE_MINOR) {
				$error = 'credit_major_not_allowed';
				return -1;
			}
			if (self::spendOne($db, $designid, $socid, (int) $eco->id, $public !== '' ? $public : 'Revision credit used: '.$eco->ref, $private, $actor) < 0) {
				$error = 'credit_insufficient';
				return -1;
			}
			return self::saveDecision($eco, $designid, $size, self::OUTCOME_CREDIT);
		}
		if ($outcome === 'waive') {
			if ($public === '') {
				$error = 'credit_reason_required';
				return -1;
			}
			self::addLedger($db, $designid, $socid, 'waive', 0, 0, (int) $eco->id, $public, $private, $actor);
			return self::saveDecision($eco, $designid, $size, self::OUTCOME_WAIVED);
		}
		self::addLedger($db, $designid, $socid, 'overage', 0, 0, (int) $eco->id, $public !== '' ? $public : 'Billed at the CAD rate', $private, $actor);
		return self::saveDecision($eco, $designid, $size, self::OUTCOME_BILLED);
	}

	/**
	 * @param	ChangeOrder	$eco		Change order
	 * @param	int			$designid	Design whose allowance counts
	 * @param	int			$size		SIZE_*
	 * @param	string		$outcome	OUTCOME_*
	 * @return	int
	 */
	private static function saveDecision($eco, $designid, $size, $outcome)
	{
		$sql = "UPDATE ".$eco->db->prefix()."designtrace_changeorder SET credit_size = ".((int) $size).", credit_outcome = '".$eco->db->escape($outcome)."', fk_credit_design = ".((int) $designid)." WHERE rowid = ".((int) $eco->id);
		return $eco->db->query($sql) ? 1 : -1;
	}

	/**
	 * Take one credit from the grant that runs out first
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design
	 * @param	int		$socid		Customer
	 * @param	int		$ecoid		Change order
	 * @param	string	$public		Note the customer sees
	 * @param	string	$private	Staff-only note
	 * @param	string	$actor		Who
	 * @return	int					Ledger row id, -2 when there is no credit to take, <0 on error
	 */
	private static function spendOne($db, $designid, $socid, $ecoid, $public, $private, $actor)
	{
		$grants = self::activeGrantsBySoonest($db, $designid);
		if (empty($grants)) {
			return -2;
		}
		return self::addLedger($db, $designid, $socid, 'use', -1, $grants[0]['id'], $ecoid, $public, $private, $actor);
	}

	/**
	 * Charge another round of an ECO: one more credit if there is one, else it is billed
	 *
	 * @param	ChangeOrder	$eco			Change order
	 * @param	string		$actor			Who
	 * @param	string		$publicnote		Note the customer sees
	 * @param	string		$privatenote	Staff-only note
	 * @param	string		$error			Error code on failure
	 * @return	string|false				credit or billed, false on failure
	 */
	public static function chargeRound($eco, $actor, $publicnote, $privatenote, &$error)
	{
		$db = $eco->db;
		$ctx = self::getEcoContext($eco);
		if (!$ctx) {
			$error = 'credit_not_applicable';
			return false;
		}
		$designid = $ctx['design_id'];
		self::sync($db, $designid);
		$note = trim((string) $publicnote) !== '' ? trim((string) $publicnote) : 'Round '.$ctx['round'].' of '.$eco->ref;
		if (self::getBalance($db, $designid) >= 1 && self::spendOne($db, $designid, $ctx['socid'], (int) $eco->id, $note, $privatenote, $actor) > 0) {
			return self::OUTCOME_CREDIT;
		}
		self::addLedger($db, $designid, $ctx['socid'], 'overage', 0, 0, (int) $eco->id, $note, $privatenote, $actor);
		return self::OUTCOME_BILLED;
	}

	/**
	 * Give back what an ECO took when it is cancelled or approval is undone: credits used return to the
	 * grant they came from, or to a short manual grant when that one has expired. The ECO then needs a new
	 * decision to be approved again.
	 *
	 * @param	ChangeOrder	$eco		Change order
	 * @param	string		$actor		Who
	 * @return	int						Credits given back, <0 on error
	 */
	public static function refundForEco($eco, $actor)
	{
		$db = $eco->db;
		$resql = $db->query("SELECT fk_design, fk_soc, fk_grant, SUM(amount) as net FROM ".$db->prefix()."designtrace_creditledger WHERE fk_eco = ".((int) $eco->id)." AND type IN ('use', 'refund') GROUP BY fk_design, fk_soc, fk_grant");
		$rows = array();
		while ($resql && ($obj = $db->fetch_object($resql))) {
			if ((int) $obj->net < 0) {
				$rows[] = $obj;
			}
		}
		$given = 0;
		foreach ($rows as $row) {
			$count = -(int) $row->net;
			self::sync($db, (int) $row->fk_design);
			$resql2 = $db->query("SELECT status FROM ".$db->prefix()."designtrace_creditgrant WHERE rowid = ".((int) $row->fk_grant));
			$grant = $resql2 ? $db->fetch_object($resql2) : null;
			$target = (int) $row->fk_grant;
			if (!$grant || (int) $grant->status !== self::STATUS_ACTIVE) {
				$target = self::addGrant($db, (int) $row->fk_design, (int) $row->fk_soc, $count, 1, self::SOURCE_MANUAL, 'Credit returned: '.$eco->ref.' was cancelled', '', 0);
				if ($target < 0) {
					return -1;
				}
				// A grant added for the refund starts counting at once: the design is already delivered
				self::sync($db, (int) $row->fk_design);
				$given += $count;
				continue;
			}
			if (self::addLedger($db, (int) $row->fk_design, (int) $row->fk_soc, 'refund', $count, $target, (int) $eco->id, 'Credit returned: '.$eco->ref.' was cancelled', '', $actor) < 0) {
				return -1;
			}
			$given += $count;
		}
		$db->query("UPDATE ".$db->prefix()."designtrace_changeorder SET credit_outcome = 'none', credit_size = 0 WHERE rowid = ".((int) $eco->id));
		return $given;
	}

	/**
	 * Put the grants an order bought into effect (called when the order closes): each line whose product has
	 * the extrafield designtrace_credits grants that many per unit, once per line. With one design on the order
	 * they go to it; otherwise they wait (design 0) for staff to allocate them.
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	Commande|CommonObject	$order	Closed order (lines loaded or loadable)
	 * @param	int		$userid	User
	 * @return	int				Grants made, <0 on error
	 */
	public static function grantFromOrder($db, $order, $userid = 0)
	{
		if (empty($order->lines) && method_exists($order, 'fetch_lines')) {
			$order->fetch_lines();
		}
		$designs = self::designsOfOrder($db, (int) $order->id, (int) $order->socid);
		$made = 0;
		foreach ((array) $order->lines as $line) {
			if (empty($line->fk_product) || (int) $line->id <= 0) {
				continue;
			}
			$resql = $db->query("SELECT options_designtrace_credits as credits, options_designtrace_credit_months as months FROM ".$db->prefix()."product_extrafields WHERE fk_object = ".((int) $line->fk_product));
			$obj = $resql ? $db->fetch_object($resql) : null;
			$per = $obj ? (int) $obj->credits : 0;
			$qty = (int) floor((float) $line->qty);
			if ($per <= 0 || $qty <= 0) {
				continue;
			}
			$months = ($obj && $obj->months !== null && $obj->months !== '' ? (int) $obj->months : getDolGlobalInt('DESIGNTRACE_CREDITS_DEFAULT_MONTHS'));
			$designid = (count($designs) === 1 ? $designs[0] : 0);
			$id = self::addGrant($db, $designid, (int) $order->socid, $per * $qty, $months, self::SOURCE_ORDER, 'Bought on '.$order->ref, '', $userid, 'order', (int) $line->id);
			if ($id > 0) {
				$made++;
			} elseif ($id !== -3) {
				return -1;
			}
		}
		return $made;
	}

	/**
	 * Designs an order is about: designs linked to it, and the designs of the revisions linked to it
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$orderid	Order
	 * @param	int		$socid	Customer (only their designs count)
	 * @return	int[]
	 */
	public static function designsOfOrder($db, $orderid, $socid)
	{
		$ids = array();
		$sql = "SELECT d.rowid FROM ".$db->prefix()."designtrace_design as d INNER JOIN ".$db->prefix()."element_element as ee ON ee.fk_target = d.rowid AND ee.targettype = 'designtrace_design' AND ee.sourcetype = 'commande' AND ee.fk_source = ".((int) $orderid);
		$sql .= " WHERE d.fk_soc = ".((int) $socid);
		$sql .= " UNION SELECT d.rowid FROM ".$db->prefix()."designtrace_design as d INNER JOIN ".$db->prefix()."designtrace_component as c ON c.fk_design = d.rowid INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.fk_component = c.rowid";
		$sql .= " INNER JOIN ".$db->prefix()."element_element as ee ON ee.fk_target = r.rowid AND ee.targettype = 'designtrace_componentrevision' AND ee.sourcetype = 'commande' AND ee.fk_source = ".((int) $orderid)." WHERE d.fk_soc = ".((int) $socid);
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$ids[(int) $obj->rowid] = (int) $obj->rowid;
		}
		return array_values($ids);
	}

	/**
	 * Say which design an order's waiting credits are for
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$grantid	Grant waiting (design 0)
	 * @param	int		$designid	Design of the same customer
	 * @return	int					>0 OK, <0 error (-2 not waiting, -3 other customer)
	 */
	public static function allocate($db, $grantid, $designid)
	{
		$resql = $db->query("SELECT fk_soc, fk_design, status FROM ".$db->prefix()."designtrace_creditgrant WHERE rowid = ".((int) $grantid));
		$grant = $resql ? $db->fetch_object($resql) : null;
		$design = self::loadDesign($db, $designid);
		if (!$grant || (int) $grant->fk_design !== 0 || (int) $grant->status !== self::STATUS_DORMANT) {
			return -2;
		}
		if (!$design || (int) $design->fk_soc !== (int) $grant->fk_soc) {
			return -3;
		}
		if (!$db->query("UPDATE ".$db->prefix()."designtrace_creditgrant SET fk_design = ".((int) $designid)." WHERE rowid = ".((int) $grantid))) {
			return -1;
		}
		self::sync($db, (int) $designid);
		return 1;
	}

	/**
	 * Credits an order bought that are still waiting for a design
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$orderid	Order
	 * @return	array<int,array<string,mixed>>	id, credits, note
	 */
	public static function fetchPendingForOrder($db, $orderid)
	{
		$rows = array();
		$sql = "SELECT g.rowid, g.credits, g.note_public FROM ".$db->prefix()."designtrace_creditgrant as g INNER JOIN ".$db->prefix()."commandedet as l ON l.rowid = g.fk_origin_line";
		$sql .= " WHERE g.origin_element = 'order' AND g.fk_design = 0 AND g.status = ".self::STATUS_DORMANT." AND l.fk_commande = ".((int) $orderid);
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$rows[] = array('id' => (int) $obj->rowid, 'credits' => (int) $obj->credits, 'note' => (string) $obj->note_public);
		}
		return $rows;
	}

	/**
	 * An order was cancelled, or its invoice credited: take back what is unused of the credits it bought
	 * (credits already used stay used)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$orderid	Order
	 * @param	string	$actor		Who
	 * @return	int					Credits taken back, <0 on error
	 */
	public static function reverseForOrder($db, $orderid, $actor)
	{
		$sql = "SELECT g.rowid, g.fk_design, g.fk_soc, g.status FROM ".$db->prefix()."designtrace_creditgrant as g INNER JOIN ".$db->prefix()."commandedet as l ON l.rowid = g.fk_origin_line";
		$sql .= " WHERE g.origin_element = 'order' AND g.status <> ".self::STATUS_ENDED." AND l.fk_commande = ".((int) $orderid);
		$resql = $db->query($sql);
		$grants = array();
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$grants[] = $obj;
		}
		$taken = 0;
		foreach ($grants as $grant) {
			$left = self::grantRemaining($db, (int) $grant->rowid);
			if ($left > 0) {
				if (self::addLedger($db, (int) $grant->fk_design, (int) $grant->fk_soc, 'reverse', -$left, (int) $grant->rowid, 0, 'Credits taken back: the order was cancelled or refunded', '', $actor) < 0) {
					return -1;
				}
				$taken += $left;
			}
			$db->query("UPDATE ".$db->prefix()."designtrace_creditgrant SET status = ".self::STATUS_ENDED." WHERE rowid = ".((int) $grant->rowid));
		}
		return $taken;
	}

	/**
	 * Short summary for lists
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design
	 * @return	array<string,mixed>|null	balance, next_expiry (date, credits | null), waiting_for_delivery; null when credits do not apply
	 */
	public static function fetchSummary($db, $designid, $sync = true)
	{
		if (!self::isEnabled($db, (int) $designid)) {
			return null;
		}
		if ($sync) {
			self::sync($db, (int) $designid);
		}
		$next = null;
		foreach (self::activeGrantsBySoonest($db, (int) $designid) as $grant) {
			if ($grant['date_expiry'] !== null) {
				$next = array('date' => $grant['date_expiry'], 'credits' => $grant['left']);
				break;
			}
		}
		$resql = $db->query("SELECT 1 FROM ".$db->prefix()."designtrace_creditgrant WHERE fk_design = ".((int) $designid)." AND status = ".self::STATUS_DORMANT);
		return array('balance' => self::getBalance($db, (int) $designid), 'next_expiry' => $next, 'waiting_for_delivery' => ($resql && $db->num_rows($resql) > 0));
	}

	/**
	 * The credits of a design: tier, rate, balance, grants and the ledger
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design
	 * @param	bool	$staff		Include staff-only notes and the real actors
	 * @param	bool	$sync		Activate and expire grants first (a write); the staff read passes false so it only needs read rights
	 * @return	array<string,mixed>|null	null when the design has no customer
	 */
	public static function fetchCredits($db, $designid, $staff = false, $sync = true)
	{
		$design = self::loadDesign($db, $designid);
		if (!$design || (int) $design->fk_soc <= 0) {
			return null;
		}
		if ($sync) {
			self::sync($db, (int) $designid);
		}
		$summary = self::fetchSummary($db, (int) $designid, $sync);
		$statusNames = array(self::STATUS_DORMANT => 'dormant', self::STATUS_ACTIVE => 'active', self::STATUS_ENDED => 'ended');
		$sourceNames = array(self::SOURCE_DEFAULT => 'default', self::SOURCE_ORDER => 'order', self::SOURCE_MANUAL => 'manual');

		$grants = array();
		$resql = $db->query("SELECT * FROM ".$db->prefix()."designtrace_creditgrant WHERE fk_design = ".((int) $designid)." ORDER BY rowid");
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$row = array(
				'id' => (int) $obj->rowid,
				'credits' => (int) $obj->credits,
				'remaining' => ((int) $obj->status === self::STATUS_DORMANT ? (int) $obj->credits : self::grantRemaining($db, (int) $obj->rowid)),
				'source' => $sourceNames[(int) $obj->source] ?? 'manual',
				'status' => $statusNames[(int) $obj->status] ?? 'dormant',
				'expiry_months' => (int) $obj->expiry_months,
				'date_activated' => $obj->date_activated ? (int) $db->jdate($obj->date_activated) : null,
				'date_expiry' => $obj->date_expiry ? (int) $db->jdate($obj->date_expiry) : null,
				'note' => (string) $obj->note_public,
			);
			if ($staff) {
				$row['internal_note'] = (string) $obj->note_private;
				$row['origin_element'] = (string) $obj->origin_element;
				$row['origin_line'] = (int) $obj->fk_origin_line;
			}
			$grants[] = $row;
		}

		$ledger = array();
		$running = 0;
		$resql = $db->query("SELECT l.*, e.ref as eco_ref FROM ".$db->prefix()."designtrace_creditledger as l LEFT JOIN ".$db->prefix()."designtrace_changeorder as e ON e.rowid = l.fk_eco WHERE l.fk_design = ".((int) $designid)." ORDER BY l.rowid");
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$running += (int) $obj->amount;
			$row = array(
				'id' => (int) $obj->rowid,
				'date' => (int) $db->jdate($obj->date_creation),
				'type' => (string) $obj->type,
				'amount' => (int) $obj->amount,
				'balance_after' => $running,
				'eco_id' => ((int) $obj->fk_eco > 0 ? (int) $obj->fk_eco : null),
				'eco_ref' => (string) $obj->eco_ref,
				'note' => (string) $obj->note_public,
			);
			if ($staff) {
				$row['internal_note'] = (string) $obj->note_private;
				$row['actor'] = (string) $obj->actor;
				$row['grant_id'] = ((int) $obj->fk_grant > 0 ? (int) $obj->fk_grant : null);
			} else {
				$row['actor'] = self::publicActor($obj->actor);
			}
			$ledger[] = $row;
		}

		return array(
			'design_id' => (int) $designid,
			'tier' => self::tierCode($design->cad_tier),
			'rate' => self::getRate($db, (int) $design->cad_tier),
			'balance' => (int) ($summary['balance'] ?? 0),
			'waiting_for_delivery' => !empty($summary['waiting_for_delivery']),
			'next_expiry' => $summary['next_expiry'] ?? null,
			'grants' => $grants,
			'ledger' => array_reverse($ledger),
		);
	}

	/**
	 * What an ECO's credit decision looks like to the customer
	 *
	 * @param	ChangeOrder	$eco	Change order
	 * @return	array<string,mixed>|null	size_requested, size, outcome, public_note, rate; null when not a post-delivery request under credits
	 */
	public static function fetchEcoCredit($eco)
	{
		$db = $eco->db;
		$info = $eco->getRequestInfo();
		if (!$info || (int) $info->request_type <= 0) {
			return null;
		}
		$sizeNames = array(self::SIZE_MINOR => 'minor', self::SIZE_MAJOR => 'major');
		$outcome = (string) $info->credit_outcome;
		$resql = $db->query("SELECT note_public FROM ".$db->prefix()."designtrace_creditledger WHERE fk_eco = ".((int) $eco->id)." AND type IN ('use', 'waive', 'overage') ORDER BY rowid DESC");
		$obj = $resql ? $db->fetch_object($resql) : null;
		if ($outcome === self::OUTCOME_NONE && (int) $info->credit_size_requested === 0 && !$obj) {
			return null;
		}
		return array(
			'size_requested' => $sizeNames[(int) $info->credit_size_requested] ?? '',
			'size' => $sizeNames[(int) $info->credit_size] ?? '',
			'outcome' => $outcome,
			'public_note' => $obj ? (string) $obj->note_public : '',
			'rate' => ($info->credit_rate_label !== null && $info->credit_rate_label !== '' ? array('label' => (string) $info->credit_rate_label, 'amount_excl_tax' => ($info->credit_rate_amount !== null ? (float) $info->credit_rate_amount : null), 'unit' => 'hour') : null),
		);
	}
}
