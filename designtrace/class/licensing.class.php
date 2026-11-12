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
	 * Acceptance of a licence for a design on a document (the latest one)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	string	$element	propal or commande
	 * @param	int		$docid		Document id
	 * @param	int		$designid	Design id
	 * @return	stdClass|null		Row of llx_designtrace_licenceaccept
	 */
	public static function getAcceptance($db, $element, $docid, $designid)
	{
		$sql = "SELECT * FROM ".$db->prefix()."designtrace_licenceaccept WHERE element = '".$db->escape($element)."' AND fk_element = ".((int) $docid)." AND fk_design = ".((int) $designid);
		$sql .= " ORDER BY date_accept DESC, rowid DESC".$db->plimit(1);
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		if ($obj) {
			$obj->date_accept = $db->jdate($obj->date_accept);
		}
		return $obj ?: null;
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
		$cache = array();
		foreach (self::fetchDesignsOfDocument($db, $element, $docid) as $id => $design) {
			$licenceid = ($override > 0 ? $override : (int) $design['fk_licence']);
			$licence = null;
			if ($licenceid > 0) {
				if (!isset($cache[$licenceid])) {
					$cache[$licenceid] = new Licence($db);
					if ($cache[$licenceid]->fetch($licenceid) <= 0) {
						$cache[$licenceid] = null;
					}
				}
				$licence = $cache[$licenceid];
			}
			$acceptance = self::getAcceptance($db, $element, $docid, $id);
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
			$result[$id] = array(
				'design' => $design,
				'licence' => $licence,
				'source' => ($licence ? ($override > 0 ? 'document' : 'design') : ''),
				'version' => $version,
				'acceptance' => $acceptance,
			);
		}
		return $result;
	}

	/**
	 * Whether source files of a design can be given to the customer of a document: the design is
	 * not workshop-owned and the licence that counts there allows it (no licence = no)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	string	$element	Document type (propal, commande; others: no)
	 * @param	int		$docid		Document id
	 * @param	int		$designid	Design id
	 * @return	bool
	 */
	public static function allowsSourceFiles($db, $element, $docid, $designid)
	{
		// No cache: overrides and acceptances can change within a request, and only source files
		// come here
		if (!isset(self::getDocumentTypes()[$element])) {
			return false;
		}
		$licences = self::fetchDocumentLicences($db, $element, $docid);
		$info = isset($licences[(int) $designid]) ? $licences[(int) $designid] : null;
		return ($info && Design::ownershipAllowsSource($info['design']['ownership']) && $info['version'] && (int) $info['version']->perm_source === 1);
	}

	/**
	 * Values of the wording variables for a design on a document
	 *
	 * @param	CommonObject	$doc		Quote or order (thirdparty fetched or not)
	 * @param	array<string,mixed>	$design	Design row from fetchDesignsOfDocument()
	 * @param	int				$date		Date of acceptance (or now)
	 * @return	array<string,string>
	 */
	public static function getWordingValues($doc, $design, $date)
	{
		global $langs;

		$langs->loadLangs(array('designtrace@designtrace', 'propal', 'orders'));
		if (empty($doc->thirdparty) && method_exists($doc, 'fetch_thirdparty')) {
			$doc->fetch_thirdparty();
		}
		$types = self::getDocumentTypes();
		return array(
			'customer' => (!empty($doc->thirdparty) ? (string) $doc->thirdparty->name : ''),
			'design' => trim($design['ref'].' '.$design['label']),
			'revision' => implode(', ', $design['revisions']),
			'date' => dol_print_date($date, 'day'),
			'scope' => (isset($types[$doc->element]) ? $langs->transnoentitiesnoconv($types[$doc->element]['label']) : $doc->element).' '.$doc->ref,
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
	 * @return	int					Id of the acceptance, <0 if KO
	 */
	public static function accept($db, $socid, $element, $docid, $designid, $versionid, $channel, $actor, &$error)
	{
		global $conf, $langs;

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
		$sql .= ", '".$db->escape(dol_trunc((string) $actor, 128, 'right', 'UTF-8', 1))."', '".$db->escape(getUserRemoteIP())."'";
		$sql .= ", '".$db->escape(dol_trunc((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 255, 'right', 'UTF-8', 1))."', '".$db->escape($hash)."', '".$db->idate($now)."')";
		if (!$db->query($sql)) {
			$error = $db->lasterror();
			$db->rollback();
			return -1;
		}
		$id = (int) $db->last_insert_id($db->prefix().'designtrace_licenceaccept');
		$filename = self::writeAcceptancePdf($db, $doc, $info, $wording, $hash, $now, $channel, $actor);
		if ($filename === '') {
			$error = $langs->trans('ErrorLicencePdf');
			$db->rollback();
			return -1;
		}
		$db->query("UPDATE ".$db->prefix()."designtrace_licenceaccept SET filename = '".$db->escape($filename)."' WHERE rowid = ".$id);
		// A licence allowing portfolio use counts as consent when the customer said nothing yet
		dol_include_once('/designtrace/class/portfolio.class.php');
		if (DesignTracePortfolio::consentFromLicence($db, (int) $designid, $info['version'], $element, (int) $docid, $actor) < 0) {
			$error = $db->lasterror();
			$db->rollback();
			return -1;
		}
		$db->commit();
		return $id;
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
	 * @return	string						File name, '' if KO
	 */
	private static function writeAcceptancePdf($db, $doc, $info, $wording, $hash, $date, $channel, $actor)
	{
		global $conf, $langs, $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		$types = self::getDocumentTypes();
		$entity = (empty($doc->entity) ? $conf->entity : $doc->entity);
		$dirroot = (string) ($conf->{$types[$doc->element]['dir']}->multidir_output[$entity] ?? '');
		if ($dirroot === '') {
			return '';
		}
		$dir = $dirroot.'/'.dol_sanitizeFileName($doc->ref);
		if (dol_mkdir($dir) < 0) {
			return '';
		}
		$version = $info['version'];
		$filename = dol_sanitizeFileName($doc->ref.'-licence-'.$info['design']['ref'].'-'.$version->ref.'.pdf');

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
		$values = self::getWordingValues($doc, $info['design'], $date);
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
		$pdf->MultiCell(0, 4, $langs->transnoentities('LicencePdfAccepted', dol_print_date($date, 'dayhourtext'), $actor, $channel, getUserRemoteIP(), $hash).(is_object($mysoc) ? "\n".$mysoc->name : ''), 0, 'L');
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
		$sql .= " FROM ".$db->prefix()."designtrace_licenceaccept as a WHERE a.element = 'propal' AND a.fk_element = ".((int) $propalid);
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
			$resql = $db->query("SELECT DISTINCT filename FROM ".$db->prefix()."designtrace_licenceaccept WHERE element = 'propal' AND fk_element = ".((int) $propalid)." AND filename IS NOT NULL");
			while ($resql && ($obj = $db->fetch_object($resql))) {
				if (is_file($from.'/'.$obj->filename) && dol_mkdir($to) >= 0) {
					dol_copy($from.'/'.$obj->filename, $to.'/'.$obj->filename, '0', 1);
				}
			}
		}
		return 1;
	}
}
