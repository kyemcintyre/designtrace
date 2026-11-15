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
 * \file        designtrace/class/licensing.class.php
 * \ingroup     designtrace
 * \brief       Which licence applies to a design on a quote or order, and its acceptance
 *
 * A design has a licence template; a quote or order can override it for all its designs
 * (llx_designtrace_doclicence). The version that counts for a design on a document is the one
 * the customer accepted there (llx_designtrace_licenceaccept), else the template's current
 * version. Its "source files" permission decides whether source files can reach the customer.
 */

dol_include_once('/designtrace/class/licence.class.php');
dol_include_once('/designtrace/class/licenceversion.class.php');
dol_include_once('/designtrace/class/design.class.php');

/**
 * Licences of designs on documents, overrides and acceptances
 */
class DesignTraceLicensing
{
	/**
	 * Documents a licence can be set on and accepted for
	 *
	 * @return	array<string,array{table:string,class:string,file:string,dir:string,label:string}>
	 */
	public static function getDocumentTypes()
	{
		return array(
			'propal' => array('table' => 'propal', 'class' => 'Propal', 'file' => '/comm/propal/class/propal.class.php', 'dir' => 'propal', 'label' => 'Proposal'),
			'commande' => array('table' => 'commande', 'class' => 'Commande', 'file' => '/commande/class/commande.class.php', 'dir' => 'commande', 'label' => 'Order'),
		);
	}

	/**
	 * Licence template set on a document to override its designs' ones
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	string	$element	propal or commande
	 * @param	int		$docid		Document id
	 * @return	int					Licence id, 0 if none
	 */
	public static function getOverride($db, $element, $docid)
	{
		$sql = "SELECT fk_licence FROM ".$db->prefix()."designtrace_doclicence WHERE element = '".$db->escape($element)."' AND fk_element = ".((int) $docid);
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		return $obj ? (int) $obj->fk_licence : 0;
	}

	/**
	 * Set or remove (0) the licence override of a document
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	User	$user		User
	 * @param	string	$element	propal or commande
	 * @param	int		$docid		Document id
	 * @param	int		$licenceid	Licence template id, 0 to remove
	 * @return	int					>0 if OK, <0 if KO
	 */
	public static function setOverride($db, $user, $element, $docid, $licenceid)
	{
		global $conf;

		if (!$db->query("DELETE FROM ".$db->prefix()."designtrace_doclicence WHERE element = '".$db->escape($element)."' AND fk_element = ".((int) $docid))) {
			return -1;
		}
		if ((int) $licenceid <= 0) {
			return 1;
		}
		$sql = "INSERT INTO ".$db->prefix()."designtrace_doclicence (entity, element, fk_element, fk_licence, date_creation, fk_user_creat)";
		$sql .= " VALUES (".((int) $conf->entity).", '".$db->escape($element)."', ".((int) $docid).", ".((int) $licenceid).", '".$db->idate(dol_now())."', ".((int) $user->id).")";
		return $db->query($sql) ? 1 : -1;
	}

	/**
	 * Designs on a document: linked directly, or through linked revisions of their components
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	string	$element	propal or commande
	 * @param	int		$docid		Document id
	 * @return	array<int,array{id:int,ref:string,label:string,fk_licence:int,ownership:int,confidential:int,revisions:string[]}>	By design id
	 */
	public static function fetchDesignsOfDocument($db, $element, $docid)
	{
		$designs = array();
		$sql = "SELECT d.rowid, d.ref, d.label, d.fk_licence, d.ownership, d.confidential, NULL as rev_ref FROM ".$db->prefix()."element_element as ee";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_design as d ON d.rowid = ee.fk_target";
		$sql .= " WHERE ee.sourcetype = '".$db->escape($element)."' AND ee.fk_source = ".((int) $docid)." AND ee.targettype = 'designtrace_design'";
		$sql .= " UNION ALL";
		$sql .= " SELECT d.rowid, d.ref, d.label, d.fk_licence, d.ownership, d.confidential, r.ref as rev_ref FROM ".$db->prefix()."element_element as ee";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = ee.fk_target";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_design as d ON d.rowid = c.fk_design";
		$sql .= " WHERE ee.sourcetype = '".$db->escape($element)."' AND ee.fk_source = ".((int) $docid)." AND ee.targettype = 'designtrace_componentrevision'";
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$id = (int) $obj->rowid;
			if (!isset($designs[$id])) {
				$designs[$id] = array('id' => $id, 'ref' => $obj->ref, 'label' => (string) $obj->label, 'fk_licence' => (int) $obj->fk_licence, 'ownership' => (int) $obj->ownership, 'confidential' => (int) $obj->confidential, 'revisions' => array());
			}
			if ($obj->rev_ref !== null && !in_array($obj->rev_ref, $designs[$id]['revisions'], true)) {
				$designs[$id]['revisions'][] = $obj->rev_ref;
			}
		}
		ksort($designs);
		return $designs;
	}

	/**
	 * Customer (third party) of a quote or order
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	string	$element	propal or commande
	 * @param	int		$docid		Document id
	 * @return	int					Customer id, 0 if unknown
	 */
	public static function getDocumentSocid($db, $element, $docid)
	{
		$types = self::getDocumentTypes();
		if (!isset($types[$element])) {
			return 0;
		}
		$resql = $db->query("SELECT fk_soc FROM ".$db->prefix().$types[$element]['table']." WHERE rowid = ".((int) $docid));
		$obj = $resql ? $db->fetch_object($resql) : null;
		return $obj ? (int) $obj->fk_soc : 0;
	}

	/**
	 * Acceptance of a licence by a customer for a design (the latest one in force). Anchored to
	 * design + customer: it covers every quote, order, project and grant of that customer for the
	 * design, whichever of them it was given through. A forced re-acceptance ends it (kept as record).
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer id
	 * @param	int		$designid	Design id
	 * @param	int		$licenceid	Only an acceptance under this template (0 = any: the latest)
	 * @return	stdClass|null		Row of llx_designtrace_licenceaccept
	 */
	public static function getAcceptance($db, $socid, $designid, $licenceid = 0)
	{
		$sql = "SELECT * FROM ".$db->prefix()."designtrace_licenceaccept WHERE fk_soc = ".((int) $socid)." AND fk_design = ".((int) $designid)." AND date_revoked IS NULL";
		if ((int) $licenceid > 0) {
			$sql .= " AND fk_licence = ".((int) $licenceid);
		}
		$sql .= " ORDER BY date_accept DESC, rowid DESC".$db->plimit(1);
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		if ($obj) {
			$obj->date_accept = $db->jdate($obj->date_accept);
		}
		return $obj ?: null;
	}

	/**
	 * Licence of a design for a customer without a quote or order (project, grant): the design's
	 * template, the version that counts and the acceptance
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer id
	 * @param	int		$designid	Design id
	 * @param	int		$overridelicence	Template forced by a document (0 = none)
	 * @return	array<string,mixed>|null	As an entry of fetchDocumentLicences(); null if the design does not exist
	 */
	public static function fetchDesignLicence($db, $socid, $designid, $overridelicence = 0)
	{
		$resql = $db->query("SELECT rowid, ref, label, fk_licence, ownership, confidential FROM ".$db->prefix()."designtrace_design WHERE rowid = ".((int) $designid));
		$obj = $resql ? $db->fetch_object($resql) : null;
		if (!$obj) {
			return null;
		}
		$design = array('id' => (int) $obj->rowid, 'ref' => $obj->ref, 'label' => (string) $obj->label, 'fk_licence' => (int) $obj->fk_licence, 'ownership' => (int) $obj->ownership, 'confidential' => (int) $obj->confidential, 'revisions' => array());
		return self::buildInfo($db, $design, (int) $socid, $overridelicence);
	}

	/**
	 * @param	DoliDB	$db			Database handler
	 * @param	array<string,mixed>	$design	Design row
	 * @param	int		$socid		Customer
	 * @param	int		$override	Template forced by a document, 0 = none
	 * @return	array<string,mixed>
	 */
	private static function buildInfo($db, $design, $socid, $override)
	{
		$licenceid = ($override > 0 ? $override : (int) $design['fk_licence']);
		$licence = null;
		if ($licenceid > 0) {
			$licence = new Licence($db);
			if ($licence->fetch($licenceid) <= 0) {
				$licence = null;
			}
		}
		// Under the template that applies: acceptances under the design's own template and a document's override live side by side
		$acceptance = self::getAcceptance($db, $socid, (int) $design['id'], $licence ? (int) $licence->id : 0);
		$version = null;
		if ($acceptance && $licence && (int) $acceptance->fk_licence === (int) $licence->id) {
			$version = new LicenceVersion($db);
			if ($version->fetch((int) $acceptance->fk_licenceversion) <= 0) {
				$version = null;
			}
		} else {
			$acceptance = null;		// Accepted under another template: not valid for this one
		}
		if (!$version && $licence) {
			$version = $licence->getCurrentVersion();
		}
		return array(
			'design' => $design,
			'licence' => $licence,
			'source' => ($licence ? ($override > 0 ? 'document' : 'design') : ''),
			'version' => $version,
			'acceptance' => $acceptance,
		);
	}

	/**
	 * Whether the customer has nothing left to accept for a design: no licence applies, or the
	 * licence that applies is accepted. Required before any download.
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer id
	 * @param	int		$designid	Design id
	 * @param	string	$element	Document the file is reached through (propal, commande: its override applies)
	 * @param	int		$docid		Document id
	 * @return	bool
	 */
	public static function isAccepted($db, $socid, $designid, $element = '', $docid = 0)
	{
		$override = (isset(self::getDocumentTypes()[$element]) ? self::getOverride($db, $element, $docid) : 0);
		$info = self::fetchDesignLicence($db, $socid, $designid, $override);
		if (!$info || !$info['licence'] || !$info['version']) {
			return true;	// Nothing to accept
		}
		if (!empty($info['acceptance'])) {
			return true;
		}
		if ($override <= 0) {
			// Reached with no document licence (project, grant, ticket): what the customer accepted through the
			// design endpoint, under the template of their own quote or order, counts as well
			$forced = self::getForcedLicence($db, $socid, $designid);
			if ($forced > 0 && $forced !== (int) $info['licence']->id) {
				$other = self::fetchDesignLicence($db, $socid, $designid, $forced);
				return !empty($other['acceptance']);
			}
		}
		return false;
	}

	/**
	 * End the acceptances of a design (of one customer, or of all) so the licence is accepted again.
	 * The records, PDFs and download log stay.
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design id
	 * @param	int		$socid		Customer id, 0 = every customer
	 * @return	int					Number of acceptances ended, <0 if KO
	 */
	public static function forceReacceptance($db, $designid, $socid = 0)
	{
		$sql = "UPDATE ".$db->prefix()."designtrace_licenceaccept SET date_revoked = '".$db->idate(dol_now())."' WHERE fk_design = ".((int) $designid)." AND date_revoked IS NULL";
		if ((int) $socid > 0) {
			$sql .= " AND fk_soc = ".((int) $socid);
		}
		$resql = $db->query($sql);
		return $resql ? (int) $db->affected_rows($resql) : -1;
	}

	/**
	 * Full path of the PDF copy of an acceptance
	 *
	 * @param	DoliDB		$db		Database handler
	 * @param	stdClass	$acc	Row of llx_designtrace_licenceaccept
	 * @return	string				Path, '' if not found
	 */
	public static function getAcceptancePdfPath($db, $acc)
	{
		global $conf;

		if (empty($acc->filename)) {
			return '';
		}
		$entity = (empty($acc->entity) ? $conf->entity : (int) $acc->entity);
		$types = self::getDocumentTypes();
		if (isset($types[$acc->element])) {
			$doc = self::fetchDocument($db, $acc->element, (int) $acc->fk_element);
			$base = $conf->{$types[$acc->element]['dir']}->multidir_output[$entity] ?? '';
			$dir = ($doc && $base !== '') ? $base.'/'.dol_sanitizeFileName($doc->ref) : '';
		} else {
			$resql = $db->query("SELECT ref FROM ".$db->prefix()."designtrace_design WHERE rowid = ".((int) $acc->fk_design));
			$obj = $resql ? $db->fetch_object($resql) : null;
			$dir = ($obj && !empty($conf->designtrace->multidir_output[$entity])) ? $conf->designtrace->multidir_output[$entity].'/design/'.dol_sanitizeFileName($obj->ref) : '';
		}
		$path = $dir.'/'.$acc->filename;
		return ($dir !== '' && is_file(dol_osencode($path))) ? $path : '';
	}

	/**
	 * Licences of every design on a document: the template (override or design's), the version
	 * that counts and the acceptance
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	string	$element	propal or commande
	 * @param	int		$docid		Document id
	 * @return	array<int,array<string,mixed>>	By design id: design (array), licence (Licence|null), source ('document'|'design'|''),
	 *											version (LicenceVersion|null, the accepted one or the current one), acceptance (stdClass|null)
	 */
	public static function fetchDocumentLicences($db, $element, $docid)
	{
		$result = array();
		$override = self::getOverride($db, $element, $docid);
		$socid = self::getDocumentSocid($db, $element, $docid);
		foreach (self::fetchDesignsOfDocument($db, $element, $docid) as $id => $design) {
			$result[$id] = self::buildInfo($db, $design, $socid, $override);
		}
		return $result;
	}

	/**
	 * Whether source files of a design can be given to the customer of a document: the design is
	 * not workshop-owned and the licence that counts there allows it (no licence = no)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	string	$element	Document type (propal, commande; project and grant use the design's licence)
	 * @param	int		$docid		Document id
	 * @param	int		$designid	Design id
	 * @param	int		$socid		Customer (needed for projects and grants, which have no document licence)
	 * @return	bool
	 */
	public static function allowsSourceFiles($db, $element, $docid, $designid, $socid = 0)
	{
		// No cache: overrides and acceptances can change within a request, and only source files
		// come here
		if (isset(self::getDocumentTypes()[$element])) {
			$licences = self::fetchDocumentLicences($db, $element, $docid);
			$info = isset($licences[(int) $designid]) ? $licences[(int) $designid] : null;
		} elseif ((int) $socid > 0) {
			$info = self::fetchDesignLicence($db, $socid, $designid);	// Project or grant: the design's licence
		} else {
			return false;
		}
		return ($info && Design::ownershipAllowsSource($info['design']['ownership']) && $info['version'] && (int) $info['version']->perm_source === 1);
	}

	/**
	 * Values of the wording variables for a design on a document
	 *
	 * @param	CommonObject|null	$doc	Quote or order (thirdparty fetched or not), null = access to the design itself
	 * @param	array<string,mixed>	$design	Design row from fetchDesignsOfDocument()
	 * @param	int				$date		Date of acceptance (or now)
	 * @param	string			$customername	Customer name when there is no document
	 * @return	array<string,string>
	 */
	public static function getWordingValues($doc, $design, $date, $customername = '')
	{
		global $langs;

		$langs->loadLangs(array('designtrace@designtrace', 'propal', 'orders'));
		if ($doc && empty($doc->thirdparty) && method_exists($doc, 'fetch_thirdparty')) {
			$doc->fetch_thirdparty();
		}
		$types = self::getDocumentTypes();
		if ($doc) {
			$scope = (isset($types[$doc->element]) ? $langs->transnoentitiesnoconv($types[$doc->element]['label']) : $doc->element).' '.$doc->ref;
			$customername = (!empty($doc->thirdparty) ? (string) $doc->thirdparty->name : '');
		} else {
			$scope = $langs->transnoentitiesnoconv('LicenceScopeDesign');
		}
		return array(
			'customer' => $customername,
			'design' => trim($design['ref'].' '.$design['label']),
			'revision' => implode(', ', $design['revisions']),
			'date' => dol_print_date($date, 'day'),
			'scope' => $scope,
		);
	}

	/**
	 * Load a quote or order
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	string	$element	propal or commande
	 * @param	int		$docid		Document id
	 * @return	CommonObject|null
	 */
	public static function fetchDocument($db, $element, $docid)
	{
		$types = self::getDocumentTypes();
		if (!isset($types[$element]) || (int) $docid <= 0) {
			return null;
		}
		require_once DOL_DOCUMENT_ROOT.$types[$element]['file'];
		$doc = new $types[$element]['class']($db);
		return ($doc->fetch((int) $docid) > 0 ? $doc : null);
	}

	/**
	 * Record that the customer of a document accepts the licence version that counts for a
	 * design there, and attach a PDF copy (wording, permissions, who, when) to the document.
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer accepting (must be the document's customer)
	 * @param	string	$element	propal or commande
	 * @param	int		$docid		Document id
	 * @param	int		$designid	Design id
	 * @param	int		$versionid	Version shown to the customer (refused if it is no longer the one that counts)
	 * @param	string	$channel	DesignTraceClientAccess::CHANNEL_* or 'backoffice'
	 * @param	string	$actor		Who (portal login, API actor)
	 * @param	string	$error		Error, filled on failure
	 * @param	?string	$ip			Customer's IP as seen by a proxying backend (evidence only), null = this request's
	 * @param	?string	$useragent	Customer's user agent as seen by a proxying backend, null = this request's
	 * @return	int					Id of the acceptance, <0 if KO
	 */
	public static function accept($db, $socid, $element, $docid, $designid, $versionid, $channel, $actor, &$error, $ip = null, $useragent = null)
	{
		global $conf, $langs;

		dol_include_once('/designtrace/class/clientaccess.class.php');
		$ip = DesignTraceClientAccess::resolveClientIp($ip);
		$useragent = DesignTraceClientAccess::resolveUserAgent($useragent);

		$langs->load('designtrace@designtrace');
		$error = '';
		$doc = self::fetchDocument($db, $element, $docid);
		if (!$doc || (int) $doc->socid !== (int) $socid || (int) $doc->status <= 0) {
			$error = $langs->trans('ErrorRecordNotFound');
			return -1;
		}
		$licences = self::fetchDocumentLicences($db, $element, $docid);
		$info = isset($licences[(int) $designid]) ? $licences[(int) $designid] : null;
		if (!$info || !$info['licence'] || !$info['version']) {
			$error = $langs->trans('ErrorLicenceNothingToAccept');
			return -1;
		}
		if ((int) $info['version']->id !== (int) $versionid) {
			$error = $langs->trans('ErrorLicenceVersionChanged', $info['version']->ref);
			return -1;
		}
		if ($info['acceptance'] && (int) $info['acceptance']->fk_licenceversion === (int) $versionid) {
			return (int) $info['acceptance']->rowid;	// Already accepted
		}
		$now = dol_now();
		$wording = $info['version']->renderWording(self::getWordingValues($doc, $info['design'], $now));
		$hash = hash('sha256', $wording);

		$db->begin();
		$sql = "INSERT INTO ".$db->prefix()."designtrace_licenceaccept (entity, fk_licenceversion, fk_licence, fk_design, element, fk_element, fk_soc, channel, actor, ip, useragent, wording_sha256, date_accept)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $info['version']->id).", ".((int) $info['licence']->id).", ".((int) $designid);
		$sql .= ", '".$db->escape($element)."', ".((int) $docid).", ".((int) $socid).", '".$db->escape($channel)."'";
		$sql .= ", '".$db->escape(dol_trunc((string) $actor, 128, 'right', 'UTF-8', 1))."', '".$db->escape($ip)."'";
		$sql .= ", '".$db->escape($useragent)."', '".$db->escape($hash)."', '".$db->idate($now)."')";
		if (!$db->query($sql)) {
			$error = $db->lasterror();
			$db->rollback();
			return -1;
		}
		$id = (int) $db->last_insert_id($db->prefix().'designtrace_licenceaccept');
		$filename = self::writeAcceptancePdf($db, $doc, $info, $wording, $hash, $now, $channel, $actor, $ip, $socid);
		if ($filename === '') {
			$error = $langs->trans('ErrorLicencePdf');
			$db->rollback();
			return -1;
		}
		$db->query("UPDATE ".$db->prefix()."designtrace_licenceaccept SET filename = '".$db->escape($filename)."' WHERE rowid = ".$id);
		// A licence allowing portfolio use counts as consent when the customer said nothing yet
		dol_include_once('/designtrace/class/portfolio.class.php');
		if (DesignTracePortfolio::consentFromLicence($db, (int) $designid, $info['version'], $element, (int) $docid, $actor, $ip) < 0) {
			$error = $db->lasterror();
			$db->rollback();
			return -1;
		}
		$db->commit();
		return $id;
	}

	/**
	 * Record that a customer accepts the licence version that counts for a design, without a quote
	 * or order (access through a project or grant, or ahead of a quote acceptance). Covers every
	 * document and grant of that customer for the design. Writes a PDF copy.
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer accepting
	 * @param	int		$designid	Design id
	 * @param	int		$versionid	Version shown to the customer (refused if it is no longer the one that counts)
	 * @param	string	$channel	DesignTraceClientAccess::CHANNEL_* or 'backoffice'
	 * @param	string	$actor		Who
	 * @param	string	$error		Error, filled on failure
	 * @param	?string	$ip			Customer's IP as seen by a proxying backend (evidence only)
	 * @param	?string	$useragent	Customer's user agent as seen by a proxying backend
	 * @return	int					Id of the acceptance, <0 if KO
	 */
	public static function acceptDesign($db, $socid, $designid, $versionid, $channel, $actor, &$error, $ip = null, $useragent = null)
	{
		global $conf, $langs;

		dol_include_once('/designtrace/class/clientaccess.class.php');
		$ip = DesignTraceClientAccess::resolveClientIp($ip);
		$useragent = DesignTraceClientAccess::resolveUserAgent($useragent);
		$langs->load('designtrace@designtrace');
		$error = '';

		$info = self::fetchDesignLicence($db, $socid, $designid, self::getForcedLicence($db, $socid, $designid));
		if (!$info || !$info['licence'] || !$info['version']) {
			$error = $langs->trans('ErrorLicenceNothingToAccept');
			return -1;
		}
		if ((int) $info['version']->id !== (int) $versionid) {
			$error = $langs->trans('ErrorLicenceVersionChanged', $info['version']->ref);
			return -1;
		}
		if ($info['acceptance'] && (int) $info['acceptance']->fk_licenceversion === (int) $versionid) {
			return (int) $info['acceptance']->rowid;	// Already accepted
		}
		$soc = new Societe($db);
		$soc->fetch((int) $socid);
		$now = dol_now();
		$wording = $info['version']->renderWording(self::getWordingValues(null, $info['design'], $now, (string) $soc->name));
		$hash = hash('sha256', $wording);

		$db->begin();
		$sql = "INSERT INTO ".$db->prefix()."designtrace_licenceaccept (entity, fk_licenceversion, fk_licence, fk_design, element, fk_element, fk_soc, channel, actor, ip, useragent, wording_sha256, date_accept)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $info['version']->id).", ".((int) $info['licence']->id).", ".((int) $designid);
		$sql .= ", '', 0, ".((int) $socid).", '".$db->escape($channel)."'";
		$sql .= ", '".$db->escape(dol_trunc((string) $actor, 128, 'right', 'UTF-8', 1))."', '".$db->escape($ip)."'";
		$sql .= ", '".$db->escape($useragent)."', '".$db->escape($hash)."', '".$db->idate($now)."')";
		if (!$db->query($sql)) {
			$error = $db->lasterror();
			$db->rollback();
			return -1;
		}
		$id = (int) $db->last_insert_id($db->prefix().'designtrace_licenceaccept');
		$filename = self::writeAcceptancePdf($db, null, $info, $wording, $hash, $now, $channel, $actor, $ip, $socid, (string) $soc->name);
		if ($filename === '') {
			$error = $langs->trans('ErrorLicencePdf');
			$db->rollback();
			return -1;
		}
		$db->query("UPDATE ".$db->prefix()."designtrace_licenceaccept SET filename = '".$db->escape($filename)."' WHERE rowid = ".$id);
		// A licence allowing portfolio use counts as consent, only for the customer the design is made for
		$resql = $db->query("SELECT fk_soc FROM ".$db->prefix()."designtrace_design WHERE rowid = ".((int) $designid));
		$owner = $resql ? $db->fetch_object($resql) : null;
		if ($owner && (int) $owner->fk_soc === (int) $socid) {
			dol_include_once('/designtrace/class/portfolio.class.php');
			if (DesignTracePortfolio::consentFromLicence($db, (int) $designid, $info['version'], '', 0, $actor, $ip) < 0) {
				$error = $db->lasterror();
				$db->rollback();
				return -1;
			}
		}
		$db->commit();
		return $id;
	}

	/**
	 * Template forced on a design by one of the customer's quotes or orders (the order-level override)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer id
	 * @param	int		$designid	Design id
	 * @return	int					Licence id of the most recent override, 0 if none
	 */
	public static function getForcedLicence($db, $socid, $designid)
	{
		$best = 0;
		foreach (self::getDocumentTypes() as $element => $def) {
			$sql = "SELECT dl.fk_licence FROM ".$db->prefix()."designtrace_doclicence as dl";
			$sql .= " INNER JOIN ".$db->prefix().$def['table']." as d ON d.rowid = dl.fk_element AND d.fk_soc = ".((int) $socid);
			$sql .= " WHERE dl.element = '".$db->escape($element)."' AND EXISTS (SELECT 1 FROM ".$db->prefix()."element_element as ee WHERE ee.sourcetype = '".$db->escape($element)."' AND ee.fk_source = d.rowid AND ((ee.targettype = 'designtrace_design' AND ee.fk_target = ".((int) $designid).")";
			$sql .= " OR (ee.targettype = 'designtrace_componentrevision' AND ee.fk_target IN (SELECT r.rowid FROM ".$db->prefix()."designtrace_componentrevision as r INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component WHERE c.fk_design = ".((int) $designid)."))))";
			$sql .= " ORDER BY dl.rowid DESC".$db->plimit(1);
			$resql = $db->query($sql);
			$obj = $resql ? $db->fetch_object($resql) : null;
			if ($obj) {
				$best = max($best, (int) $obj->fk_licence);
			}
		}
		return $best;
	}

	/**
	 * PDF copy of an accepted licence, saved in the document's folder (shown in its Documents tab)
	 *
	 * @param	DoliDB				$db			Database handler
	 * @param	CommonObject		$doc		Quote or order
	 * @param	array<string,mixed>	$info		Entry of fetchDocumentLicences()
	 * @param	string				$wording	Rendered wording (HTML)
	 * @param	string				$hash		SHA-256 of the wording
	 * @param	int					$date		Acceptance date
	 * @param	string				$channel	Channel
	 * @param	string				$actor		Who
	 * @param	string				$ip			Customer's IP
	 * @return	string						File name, '' if KO
	 */
	private static function writeAcceptancePdf($db, $doc, $info, $wording, $hash, $date, $channel, $actor, $ip, $socid = 0, $customername = '')
	{
		global $conf, $langs, $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		$types = self::getDocumentTypes();
		if ($doc) {
			$entity = (empty($doc->entity) ? $conf->entity : $doc->entity);
			$dirroot = (string) ($conf->{$types[$doc->element]['dir']}->multidir_output[$entity] ?? '');
			$dir = $dirroot.'/'.dol_sanitizeFileName($doc->ref);
			$prefix = $doc->ref;
		} else {
			// Accepted for the design itself: copy kept in the design's documents folder
			$dirroot = (string) ($conf->designtrace->multidir_output[$conf->entity] ?? '');
			$dir = $dirroot.'/design/'.dol_sanitizeFileName($info['design']['ref']);
			$prefix = $info['design']['ref'].'-'.(int) $socid;
		}
		if ($dirroot === '' || dol_mkdir($dir) < 0) {
			return '';
		}
		$version = $info['version'];
		$filename = dol_sanitizeFileName($prefix.'-licence-'.($doc ? $info['design']['ref'].'-' : '').$version->ref.'.pdf');
		if (is_file($dir.'/'.$filename)) {
			// Same version accepted again after a forced re-acceptance: keep the earlier copy as evidence
			$filename = dol_sanitizeFileName(preg_replace('/\.pdf$/', '', $filename).'-'.dol_print_date($date, '%Y%m%d%H%M%S', 'gmt').'.pdf');
		}

		$pdf = pdf_getInstance('A4');
		$font = pdf_getPDFFont($langs);
		$pdf->SetAutoPageBreak(true, 15);
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(false);
		$pdf->SetMargins(15, 15, 15);
		$pdf->AddPage();
		$pdf->SetFont($font, 'B', 14);
		$pdf->MultiCell(0, 8, $info['licence']->label.' ('.$version->ref.')', 0, 'L');
		$pdf->SetFont($font, '', 9);
		$values = self::getWordingValues($doc, $info['design'], $date, $customername);
		$pdf->MultiCell(0, 5, $langs->transnoentities('LicencePdfFor', $values['customer'], $values['design'], $values['scope']), 0, 'L');
		$pdf->Ln(2);
		$perms = '';
		foreach ($version->getPermissions() as $label => $value) {
			$perms .= '<tr><td>'.dol_escape_htmltag($label).'</td><td>'.$langs->transnoentities($value ? 'Yes' : 'No').'</td></tr>';
		}
		$pdf->writeHTML('<table border="0.3" cellpadding="3">'.$perms.'</table>', true, false, true, false, '');
		$pdf->Ln(3);
		$pdf->SetFont($font, '', 10);
		$pdf->writeHTML($wording, true, false, true, false, '');
		$pdf->Ln(4);
		$pdf->SetFont($font, '', 8);
		$pdf->MultiCell(0, 4, $langs->transnoentities('LicencePdfAccepted', dol_print_date($date, 'dayhourtext'), $actor, $channel, $ip, $hash).(is_object($mysoc) ? "\n".$mysoc->name : ''), 0, 'L');
		$pdf->Output($dir.'/'.$filename, 'F');
		dolChmod($dir.'/'.$filename);

		return is_file($dir.'/'.$filename) ? $filename : '';
	}

	/**
	 * Copy the licence override and acceptances of a quote to an order made from it (trigger)
	 *
	 * @param	DoliDB			$db			Database handler
	 * @param	User			$user		User
	 * @param	CommonObject	$newdoc		New order
	 * @param	int				$propalid	Source quote
	 * @return	int							>=0 if OK, <0 if KO
	 */
	public static function carryOver($db, $user, $newdoc, $propalid)
	{
		global $conf;

		if ($newdoc->element != 'commande' || (int) $propalid <= 0) {
			return 0;
		}
		$override = self::getOverride($db, 'propal', $propalid);
		if ($override > 0 && self::getOverride($db, 'commande', $newdoc->id) <= 0 && self::setOverride($db, $user, 'commande', $newdoc->id, $override) < 0) {
			return -1;
		}
		$sql = "INSERT INTO ".$db->prefix()."designtrace_licenceaccept (entity, fk_licenceversion, fk_licence, fk_design, element, fk_element, fk_soc, channel, actor, ip, useragent, wording_sha256, filename, note, date_accept)";
		$sql .= " SELECT entity, fk_licenceversion, fk_licence, fk_design, 'commande', ".((int) $newdoc->id).", fk_soc, channel, actor, ip, useragent, wording_sha256, filename, 'propal:".((int) $propalid)."', date_accept";
		$sql .= " FROM ".$db->prefix()."designtrace_licenceaccept as a WHERE a.element = 'propal' AND a.fk_element = ".((int) $propalid)." AND a.date_revoked IS NULL";
		$sql .= " AND NOT EXISTS (SELECT 1 FROM ".$db->prefix()."designtrace_licenceaccept as b WHERE b.element = 'commande' AND b.fk_element = ".((int) $newdoc->id)." AND b.fk_design = a.fk_design AND b.fk_licenceversion = a.fk_licenceversion)";
		if (!$db->query($sql)) {
			return -1;
		}
		// The PDF copies go with the order too
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		$quote = self::fetchDocument($db, 'propal', $propalid);
		$entity = (empty($newdoc->entity) ? $conf->entity : $newdoc->entity);
		if ($quote && !empty($conf->propal->multidir_output[$entity]) && !empty($conf->commande->multidir_output[$entity])) {
			$from = $conf->propal->multidir_output[$entity].'/'.dol_sanitizeFileName($quote->ref);
			$to = $conf->commande->multidir_output[$entity].'/'.dol_sanitizeFileName($newdoc->ref);
			$resql = $db->query("SELECT DISTINCT filename FROM ".$db->prefix()."designtrace_licenceaccept WHERE element = 'propal' AND fk_element = ".((int) $propalid)." AND filename IS NOT NULL AND date_revoked IS NULL");
			while ($resql && ($obj = $db->fetch_object($resql))) {
				if (is_file($from.'/'.$obj->filename) && dol_mkdir($to) >= 0) {
					dol_copy($from.'/'.$obj->filename, $to.'/'.$obj->filename, '0', 1);
				}
			}
		}
		return 1;
	}
}
