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
 * \file        designtrace/class/api_designtrace.class.php
 * \ingroup     designtrace
 * \brief       REST API for customer portals (My workshop): visible files and signed download links
 *
 * The portal site calls the API as one service user (right "Customer files through the API") and
 * passes the logged-in customer's third party id. If the API user is an external user, it can
 * only ask for its own third party. The rules are the same as the Web portal's
 * (DesignTraceClientAccess). Files are not streamed through the API: it returns a short-lived
 * signed link to designtrace/public/download.php, which checks the rules again and logs the download.
 */

use Luracast\Restler\RestException;

dol_include_once('/designtrace/class/clientaccess.class.php');
dol_include_once('/designtrace/class/licensing.class.php');

/**
 * API class for DesignTrace customer files (named DesignTrace so the endpoints are /designtrace/...)
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class DesignTrace extends DolibarrApi
{
	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * List the design files a customer can see
	 *
	 * One entry per file and quote / order / ticket it is visible through. Production files are
	 * never listed, "Visible on completion" files only once the order is delivered.
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	string	$element	Only through this kind of document: quote, order or ticket
	 * @return	array				Files
	 * @phan-return array<int,array<string,mixed>>
	 * @phpstan-return array<int,array<string,mixed>>
	 *
	 * @url	GET customers/{socid}/files
	 *
	 * @throws RestException 400 Bad document type
	 * @throws RestException 403 Not allowed
	 * @throws RestException 503 System error
	 */
	public function listFiles($socid, $element = '')
	{
		$socid = $this->checkCustomer($socid);
		$type = '';
		if ($element !== '') {
			$type = DesignTraceClientAccess::normaliseElement($element);
			if ($type === '') {
				throw new RestException(400, 'Bad document type, use quote, order or ticket');
			}
		}
		return $this->formatRows(DesignTraceClientAccess::fetchVisibleFiles($this->db, $socid, $type));
	}

	/**
	 * List the design files a customer can see on one quote, order or ticket
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	string	$element	quote, order or ticket
	 * @param	int		$docid		Id of the quote, order or ticket
	 * @return	array				Files
	 * @phan-return array<int,array<string,mixed>>
	 * @phpstan-return array<int,array<string,mixed>>
	 *
	 * @url	GET customers/{socid}/documents/{element}/{docid}/files
	 *
	 * @throws RestException 400 Bad document type
	 * @throws RestException 403 Not allowed
	 * @throws RestException 503 System error
	 */
	public function listDocumentFiles($socid, $element, $docid)
	{
		$socid = $this->checkCustomer($socid);
		$type = DesignTraceClientAccess::normaliseElement($element);
		if ($type === '') {
			throw new RestException(400, 'Bad document type, use quote, order or ticket');
		}
		return $this->formatRows(DesignTraceClientAccess::fetchVisibleFiles($this->db, $socid, $type, (int) $docid));
	}

	/**
	 * Get a short-lived signed link to download a file
	 *
	 * The link works without login until it expires (setup: link lifetime, 5 minutes by default).
	 * The rules are checked again when it is used, and the download is logged with the actor.
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	int		$fileid		File id (file_id in the file lists)
	 * @param	string	$element	Document type the file is downloaded through (optional): quote, order or ticket
	 * @param	int		$docid		Document id (optional)
	 * @param	string	$actor		Who downloads, for the log (e.g. the portal login or contact id)
	 * @return	array				url, expires (timestamp), filename, filesize, sha256
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	GET customers/{socid}/files/{fileid}/link
	 *
	 * @throws RestException 400 Bad document type
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 File not visible to this customer
	 */
	public function getDownloadLink($socid, $fileid, $element = '', $docid = 0, $actor = '')
	{
		$socid = $this->checkCustomer($socid);
		$type = '';
		if ($element !== '') {
			$type = DesignTraceClientAccess::normaliseElement($element);
			if ($type === '') {
				throw new RestException(400, 'Bad document type, use quote, order or ticket');
			}
		}
		$row = DesignTraceClientAccess::getDownloadableFile($this->db, $socid, (int) $fileid, $type, (int) $docid);
		if (!$row) {
			throw new RestException(404, 'File not visible to this customer');
		}
		$actor = trim((string) $actor);
		if ($actor === '') {
			$actor = 'api:'.DolibarrApiAccess::$user->login;
		}
		$link = DesignTraceClientAccess::buildSignedUrl($row, $socid, $actor);

		return array(
			'url' => $link['url'],
			'expires' => $link['expires'],
			'filename' => $row->filename,
			'filesize' => (int) $row->filesize,
			'sha256' => $row->sha256,
		);
	}

	/**
	 * List the licences on a quote or order
	 *
	 * One entry per design on the document: the licence that applies (the document's override,
	 * else the design's), the version that counts (the accepted one, else the current one) with
	 * its wording (variables filled in), its permissions, and the acceptance if any.
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	string	$element	quote or order
	 * @param	int		$docid		Id of the quote or order
	 * @return	array				Licences
	 * @phan-return array<int,array<string,mixed>>
	 * @phpstan-return array<int,array<string,mixed>>
	 *
	 * @url	GET customers/{socid}/documents/{element}/{docid}/licences
	 *
	 * @throws RestException 400 Bad document type
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Document not found
	 */
	public function listLicences($socid, $element, $docid)
	{
		$socid = $this->checkCustomer($socid);
		$doc = $this->checkLicenceDocument($socid, $element, $docid);
		$result = array();
		foreach (DesignTraceLicensing::fetchDocumentLicences($this->db, $doc->element, (int) $doc->id) as $designid => $info) {
			if (!$info['licence'] || !$info['version']) {
				continue;
			}
			$version = $info['version'];
			$acc = $info['acceptance'];
			$date = $acc ? (int) $acc->date_accept : dol_now();
			$permissions = array();
			foreach (LicenceVersion::getPermissionKeys() as $key) {
				$permissions[substr($key, 5)] = ((int) $version->$key === 1);
			}
			$result[] = array(
				'design_id' => (int) $designid,
				'design_ref' => $info['design']['ref'],
				'design_label' => $info['design']['label'],
				'licence_code' => $info['licence']->ref,
				'licence_name' => $info['licence']->label,
				'version_id' => (int) $version->id,
				'version' => (int) $version->version,
				'version_ref' => $version->ref,
				'wording_html' => $version->renderWording(DesignTraceLicensing::getWordingValues($doc, $info['design'], $date)),
				'permissions' => $permissions,
				'accepted' => ($acc ? array('date' => (int) $acc->date_accept, 'actor' => $acc->actor, 'channel' => $acc->channel) : null),
			);
		}
		return $result;
	}

	/**
	 * Record that the customer accepts a licence on a quote or order
	 *
	 * Pass the version_id shown to the customer: if the licence changed since, the call is
	 * refused (409) so the customer can read the new wording first. The acceptance is logged
	 * (version, who, when, IP) and a PDF copy is attached to the document.
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	string	$element	quote or order
	 * @param	int		$docid		Id of the quote or order
	 * @param	int		$design_id	Design id (from the licence list)
	 * @param	int		$version_id	Licence version id shown to the customer
	 * @param	string	$actor		Who accepts, for the log (e.g. the portal login)
	 * @return	array				id of the acceptance, date
	 * @phan-return array<string,int>
	 * @phpstan-return array<string,int>
	 *
	 * @url	POST customers/{socid}/documents/{element}/{docid}/licences/accept
	 *
	 * @throws RestException 400 Bad document type
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Document not found
	 * @throws RestException 409 Licence changed or nothing to accept
	 */
	public function acceptLicence($socid, $element, $docid, $design_id, $version_id, $actor = '')
	{
		$socid = $this->checkCustomer($socid);
		$doc = $this->checkLicenceDocument($socid, $element, $docid);
		$actor = trim((string) $actor);
		if ($actor === '') {
			$actor = 'api:'.DolibarrApiAccess::$user->login;
		}
		$error = '';
		$id = DesignTraceLicensing::accept($this->db, $socid, $doc->element, (int) $doc->id, (int) $design_id, (int) $version_id, DesignTraceClientAccess::CHANNEL_API, $actor, $error);
		if ($id <= 0) {
			throw new RestException(409, $error);
		}
		return array('id' => (int) $id, 'date' => dol_now());
	}

	/**
	 * Quote or order of this customer, for licences
	 *
	 * @param	int		$socid		Customer id
	 * @param	string	$element	quote or order
	 * @param	int		$docid		Document id
	 * @return	CommonObject
	 *
	 * @throws RestException 400 Bad document type
	 * @throws RestException 404 Document not found
	 */
	private function checkLicenceDocument($socid, $element, $docid)
	{
		$type = DesignTraceClientAccess::normaliseElement($element);
		if (!in_array($type, array('propal', 'commande'), true)) {
			throw new RestException(400, 'Bad document type, use quote or order');
		}
		$doc = DesignTraceLicensing::fetchDocument($this->db, $type, (int) $docid);
		if (!$doc || (int) $doc->socid !== (int) $socid || (int) $doc->status <= 0) {
			throw new RestException(404, 'Document not found');
		}
		return $doc;
	}

	/**
	 * List the downloads of a customer, newest first
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	int		$limit		Max number of rows
	 * @return	array				Downloads
	 * @phan-return array<int,array<string,mixed>>
	 * @phpstan-return array<int,array<string,mixed>>
	 *
	 * @url	GET customers/{socid}/downloads
	 *
	 * @throws RestException 403 Not allowed
	 */
	public function listDownloads($socid, $limit = 100)
	{
		$socid = $this->checkCustomer($socid);
		$sql = "SELECT dl.rowid, dl.fk_revisionfile, dl.fk_revision, dl.filename, dl.sha256, dl.element, dl.fk_element, dl.channel, dl.actor, dl.date_download";
		$sql .= " FROM ".$this->db->prefix()."designtrace_download as dl";
		$sql .= " WHERE dl.fk_soc = ".((int) $socid)." AND dl.entity IN (".getEntity('designtrace_download').")";
		$sql .= " ORDER BY dl.date_download DESC, dl.rowid DESC";
		$sql .= $this->db->plimit(max(1, min(1000, (int) $limit)));
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RestException(503, $this->db->lasterror());
		}
		$result = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$result[] = array(
				'id' => (int) $obj->rowid,
				'file_id' => (int) $obj->fk_revisionfile,
				'revision_id' => (int) $obj->fk_revision,
				'filename' => $obj->filename,
				'sha256' => $obj->sha256,
				'document_type' => $this->publicElement((string) $obj->element),
				'document_id' => (int) $obj->fk_element,
				'channel' => $obj->channel,
				'actor' => $obj->actor,
				'date' => $this->db->jdate($obj->date_download),
			);
		}
		return $result;
	}

	/**
	 * Check the API user may read files of this customer
	 *
	 * @param	int		$socid	Customer id asked for
	 * @return	int				Customer id to use
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Customer not found
	 */
	private function checkCustomer($socid)
	{
		$apiuser = DolibarrApiAccess::$user;
		if (!isModEnabled('designtrace') || !$apiuser->hasRight('designtrace', 'clientfiles', 'api')) {
			throw new RestException(403, 'Needs the DesignTrace right "Customer files through the API"');
		}
		$socid = (int) $socid;
		if ($apiuser->socid > 0 && $apiuser->socid != $socid) {
			throw new RestException(403, 'An external user can only read its own third party');
		}
		if ($socid <= 0) {
			throw new RestException(404, 'Customer not found');
		}
		$sql = "SELECT rowid FROM ".$this->db->prefix()."societe WHERE rowid = ".$socid." AND entity IN (".getEntity('societe').")";
		$resql = $this->db->query($sql);
		if (!$resql || !$this->db->num_rows($resql)) {
			throw new RestException(404, 'Customer not found');
		}
		return $socid;
	}

	/**
	 * Name of a document type in the API
	 *
	 * @param	string	$element	Dolibarr element
	 * @return	string
	 */
	private function publicElement($element)
	{
		$map = array('propal' => 'quote', 'commande' => 'order', 'ticket' => 'ticket');
		return isset($map[$element]) ? $map[$element] : $element;
	}

	/**
	 * Rows for the API
	 *
	 * @param	stdClass[]|int	$rows	Rows from DesignTraceClientAccess::fetchVisibleFiles()
	 * @return	array<int,array<string,mixed>>
	 *
	 * @throws RestException 503 System error
	 */
	private function formatRows($rows)
	{
		if (!is_array($rows)) {
			throw new RestException(503, 'Error reading files');
		}
		$types = array(0 => 'part', 1 => 'assembly', 2 => 'drawing');
		$result = array();
		foreach ($rows as $row) {
			$result[] = array(
				'file_id' => (int) $row->file_id,
				'filename' => $row->filename,
				'format' => $row->format,
				'filesize' => (int) $row->filesize,
				'sha256' => $row->sha256,
				'viewer_3d' => DesignTraceClientAccess::is3dFormat((string) $row->format),
				'revision_id' => (int) $row->rev_id,
				'revision_ref' => $row->rev_ref,
				'revision' => $row->rev,
				'revision_released' => ((int) $row->rev_status === ComponentRevision::STATUS_RELEASED),
				'date_release' => $this->db->jdate($row->date_release),
				'component_id' => (int) $row->comp_id,
				'component_ref' => $row->comp_ref,
				'component_label' => $row->comp_label,
				'component_type' => isset($types[(int) $row->comp_type]) ? $types[(int) $row->comp_type] : '',
				'document_type' => $this->publicElement((string) $row->element),
				'document_id' => (int) $row->doc_id,
				'document_ref' => $row->doc_ref,
			);
		}
		return $result;
	}
}
