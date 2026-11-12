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
 * \file        designtrace/class/portfolio.class.php
 * \ingroup     designtrace
 * \brief       Portfolio consent of designs and the gallery feed
 *
 * The customer says whether renders of a design may be shown in the workshop's website gallery:
 * staff record it from a quote, order or the design card, the customer gives it in the Web portal
 * or through the API, and accepting a licence that allows portfolio use grants it when nothing was
 * said yet. Every change is logged (llx_designtrace_consent); the design keeps the current state.
 *
 * A design is in the gallery when consent is granted, it is not confidential and it is active.
 * Gallery images are the images of the design's Documents tab.
 */

dol_include_once('/designtrace/class/design.class.php');

/**
 * Portfolio consent and gallery
 */
class DesignTracePortfolio
{
	const CONSENT_NONE = 0;
	const CONSENT_GRANTED = 1;
	const CONSENT_REFUSED = 2;

	const CHANNEL_BACKOFFICE = 'backoffice';
	const CHANNEL_WEBPORTAL = 'webportal';
	const CHANNEL_API = 'api';
	const CHANNEL_LICENCE = 'licence';

	/**
	 * Extensions of the images that can go to the gallery
	 */
	const IMAGE_EXTENSIONS = array('png', 'jpg', 'jpeg', 'webp', 'gif');

	/**
	 * Labels of the consent states
	 *
	 * @return	array<int,string>
	 */
	public static function getConsentLabels()
	{
		global $langs;

		$langs->load('designtrace@designtrace');
		return array(
			self::CONSENT_NONE => $langs->trans('PortfolioConsentNone'),
			self::CONSENT_GRANTED => $langs->trans('PortfolioConsentGranted'),
			self::CONSENT_REFUSED => $langs->trans('PortfolioConsentRefused'),
		);
	}

	/**
	 * Badge of a consent state
	 *
	 * @param	int		$consent	self::CONSENT_*
	 * @return	string				HTML
	 */
	public static function getConsentBadge($consent)
	{
		$labels = self::getConsentLabels();
		$types = array(self::CONSENT_NONE => 'status0', self::CONSENT_GRANTED => 'status4', self::CONSENT_REFUSED => 'status8');
		$consent = isset($labels[(int) $consent]) ? (int) $consent : self::CONSENT_NONE;
		return dolGetStatus($labels[$consent], $labels[$consent], '', $types[$consent], 1);
	}

	/**
	 * Record the customer's answer for a design
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design id
	 * @param	int		$consent	self::CONSENT_GRANTED or self::CONSENT_REFUSED
	 * @param	string	$channel	self::CHANNEL_*
	 * @param	string	$actor		Who answered (portal login, API actor, staff login...)
	 * @param	string	$element	Document it was given on (propal, commande, '' = none)
	 * @param	int		$docid		Document id
	 * @param	int		$fkuser		Staff user who recorded it (0 = the customer)
	 * @param	string	$note		Note (how it was given...)
	 * @return	int					>0 if OK, 0 if unchanged, <0 if KO
	 */
	public static function setConsent($db, $designid, $consent, $channel, $actor, $element = '', $docid = 0, $fkuser = 0, $note = '')
	{
		global $conf;

		$consent = (int) $consent;
		if (!in_array($consent, array(self::CONSENT_GRANTED, self::CONSENT_REFUSED), true) || (int) $designid <= 0) {
			return -1;
		}
		$current = self::getConsent($db, $designid);
		if ($current === null) {
			return -1;
		}
		if ($current === $consent) {
			return 0;
		}
		$now = dol_now();
		$db->begin();
		$sql = "UPDATE ".$db->prefix()."designtrace_design SET portfolio_consent = ".$consent.", date_portfolio_consent = '".$db->idate($now)."'";
		$sql .= " WHERE rowid = ".((int) $designid);
		$ok = $db->query($sql);
		if ($ok) {
			$sql = "INSERT INTO ".$db->prefix()."designtrace_consent";
			$sql .= " (entity, fk_design, consent, element, fk_element, channel, actor, fk_user, note, ip, date_consent)";
			$sql .= " VALUES (".((int) $conf->entity).", ".((int) $designid).", ".$consent;
			$sql .= ", ".($element !== '' ? "'".$db->escape($element)."'" : "NULL").", ".((int) $docid);
			$sql .= ", '".$db->escape($channel)."', '".$db->escape(dol_trunc((string) $actor, 128, 'right', 'UTF-8', 1))."'";
			$sql .= ", ".((int) $fkuser > 0 ? (int) $fkuser : "NULL").", ".($note !== '' ? "'".$db->escape($note)."'" : "NULL");
			$sql .= ", '".$db->escape(getUserRemoteIP())."', '".$db->idate($now)."')";
			$ok = $db->query($sql);
		}
		if (!$ok) {
			dol_syslog(__METHOD__.' '.$db->lasterror(), LOG_ERR);
			$db->rollback();
			return -1;
		}
		$db->commit();
		return 1;
	}

	/**
	 * Current consent of a design
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design id
	 * @return	int|null			self::CONSENT_*, null if the design does not exist
	 */
	public static function getConsent($db, $designid)
	{
		$sql = "SELECT portfolio_consent FROM ".$db->prefix()."designtrace_design WHERE rowid = ".((int) $designid)." AND entity IN (".getEntity('design').")";
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		return $obj ? (int) $obj->portfolio_consent : null;
	}

	/**
	 * History of a design's consent, newest first
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design id
	 * @return	stdClass[]
	 */
	public static function fetchHistory($db, $designid)
	{
		$rows = array();
		$sql = "SELECT rowid, consent, element, fk_element, channel, actor, fk_user, note, ip, date_consent";
		$sql .= " FROM ".$db->prefix()."designtrace_consent WHERE fk_design = ".((int) $designid);
		$sql .= " ORDER BY date_consent DESC, rowid DESC";
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$obj->date_consent = $db->jdate($obj->date_consent);
			$rows[] = $obj;
		}
		return $rows;
	}

	/**
	 * A customer accepted a licence: if its version allows portfolio use and nothing was said
	 * yet, that counts as consent
	 *
	 * @param	DoliDB			$db			Database handler
	 * @param	int				$designid	Design id
	 * @param	LicenceVersion	$version	Accepted version
	 * @param	string			$element	Document type
	 * @param	int				$docid		Document id
	 * @param	string			$actor		Who accepted
	 * @return	int							>0 if consent recorded, 0 if not, <0 if KO
	 */
	public static function consentFromLicence($db, $designid, $version, $element, $docid, $actor)
	{
		if (empty($version) || (int) $version->perm_portfolio !== 1 || self::getConsent($db, $designid) !== self::CONSENT_NONE) {
			return 0;
		}
		return self::setConsent($db, $designid, self::CONSENT_GRANTED, self::CHANNEL_LICENCE, $actor, $element, $docid, 0, $version->ref);
	}

	/**
	 * Whether a design can be in the gallery
	 *
	 * @param	Design|stdClass	$design		Design (portfolio_consent, confidential, status)
	 * @return	bool
	 */
	public static function isInGallery($design)
	{
		return ((int) $design->portfolio_consent === self::CONSENT_GRANTED && empty($design->confidential) && (int) $design->status === Design::STATUS_VALIDATED);
	}

	/**
	 * Designs of the gallery with their images
	 *
	 * @param	DoliDB	$db		Database handler
	 * @return	array<int,array<string,mixed>>	id, ref, label, date_consent, images
	 */
	public static function fetchGallery($db)
	{
		$result = array();
		$sql = "SELECT t.rowid, t.ref, t.label, t.date_portfolio_consent FROM ".$db->prefix()."designtrace_design as t";
		$sql .= " WHERE t.entity IN (".getEntity('design').") AND t.portfolio_consent = ".self::CONSENT_GRANTED;
		$sql .= " AND t.confidential = 0 AND t.status = ".Design::STATUS_VALIDATED;
		$sql .= " ORDER BY t.date_portfolio_consent DESC, t.rowid DESC";
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$result[] = array(
				'id' => (int) $obj->rowid,
				'ref' => $obj->ref,
				'label' => (string) $obj->label,
				'date_consent' => $db->jdate($obj->date_portfolio_consent),
				'images' => self::fetchImages($db, (int) $obj->rowid, $obj->ref),
			);
		}
		return $result;
	}

	/**
	 * Images of a design's Documents tab (indexed in ecm_files)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design id
	 * @param	string	$ref		Design ref
	 * @return	array<int,array{image_id:int,filename:string,filesize:int}>
	 */
	public static function fetchImages($db, $designid, $ref)
	{
		$images = array();
		$sql = "SELECT rowid, filename FROM ".$db->prefix()."ecm_files";
		$sql .= " WHERE filepath = '".$db->escape('designtrace/design/'.dol_sanitizeFileName($ref))."' AND entity IN (".getEntity('ecmfiles').")";
		$sql .= " ORDER BY position, filename";
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$ext = strtolower(pathinfo($obj->filename, PATHINFO_EXTENSION));
			if (!in_array($ext, self::IMAGE_EXTENSIONS, true)) {
				continue;
			}
			$path = self::getDesignDir($ref).'/'.$obj->filename;
			if (!is_file(dol_osencode($path))) {
				continue;
			}
			$images[] = array('image_id' => (int) $obj->rowid, 'filename' => $obj->filename, 'filesize' => (int) dol_filesize($path));
		}
		return $images;
	}

	/**
	 * Path of a gallery image if the design is still in the gallery
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design id
	 * @param	int		$imageid	ecm_files id
	 * @return	array{path:string,filename:string}|null
	 */
	public static function getImage($db, $designid, $imageid)
	{
		$design = new Design($db);
		if ((int) $designid <= 0 || $design->fetch((int) $designid) <= 0 || !self::isInGallery($design)) {
			return null;
		}
		foreach (self::fetchImages($db, $design->id, $design->ref) as $image) {
			if ($image['image_id'] === (int) $imageid) {
				return array('path' => self::getDesignDir($design->ref).'/'.$image['filename'], 'filename' => $image['filename']);
			}
		}
		return null;
	}

	/**
	 * Short-lived signed link to a gallery image (public/download.php, type "portfolio")
	 *
	 * @param	int		$designid	Design id
	 * @param	int		$imageid	ecm_files id
	 * @return	array{url:string,expires:int}
	 */
	public static function buildSignedUrl($designid, $imageid)
	{
		global $conf;

		dol_include_once('/designtrace/class/clientaccess.class.php');
		$params = array(
			'f' => (int) $imageid,
			's' => 0,
			'e' => dol_now() + DesignTraceClientAccess::getLinkTtl(),
			't' => 'portfolio',
			'd' => (int) $designid,
			'a' => '',
			'n' => (int) $conf->entity,
		);
		$params['sig'] = DesignTraceClientAccess::sign($params);
		return array('url' => dol_buildpath('/designtrace/public/download.php', 2).'?'.http_build_query($params, '', '&'), 'expires' => (int) $params['e']);
	}

	/**
	 * Documents folder of a design
	 *
	 * @param	string	$ref	Design ref
	 * @return	string
	 */
	private static function getDesignDir($ref)
	{
		global $conf;

		return $conf->designtrace->multidir_output[$conf->entity].'/design/'.dol_sanitizeFileName($ref);
	}
}
