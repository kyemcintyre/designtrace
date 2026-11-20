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
 * \brief       REST API for customer portals (My workshop): visible files, signed links and direct downloads
 *
 * The portal site calls the API as one service user (right "Customer files through the API") and
 * passes the logged-in customer's third party id. If the API user is an external user, it can
 * only ask for its own third party. The rules are the same as the Web portal's
 * (DesignTraceClientAccess). A file is fetched in one of two ways, with the same rules and one
 * download log:
 *  - a short-lived signed link to designtrace/public/download.php (GET .../files/{fileid}/link),
 *    when the customer's browser can reach Dolibarr; it checks the rules again when used;
 *  - streamed back to the caller (GET .../files/{fileid}/content, logged as "API (direct)"), for a
 *    backend that proxies files because Dolibarr is not reachable from outside.
 * The same service user reads the portfolio gallery (designs whose customer consented), with links
 * or the images themselves (GET portfolio/{designid}/images/{imageid}/content). Customer actions
 * (licence acceptance, consent, proof decisions) take optional client_ip and user_agent: the
 * customer's as seen by such a backend, recorded as evidence instead of the backend's own.
 */

use Luracast\Restler\RestException;

dol_include_once('/designtrace/class/clientaccess.class.php');
dol_include_once('/designtrace/class/licensing.class.php');
dol_include_once('/designtrace/class/portfolio.class.php');
dol_include_once('/designtrace/class/proofapproval.class.php');
dol_include_once('/designtrace/class/reorder.class.php');
dol_include_once('/designtrace/class/customerportal.class.php');
dol_include_once('/designtrace/class/designlibrary.class.php');
dol_include_once('/designtrace/class/approvals.class.php');
dol_include_once('/designtrace/class/changerequests.class.php');
dol_include_once('/designtrace/class/staffapi.class.php');
dol_include_once('/designtrace/class/doclinks.class.php');

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
				throw new RestException(400, 'Bad document type, use quote, order, ticket, project or grant');
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
			throw new RestException(400, 'Bad document type, use quote, order, ticket, project or grant');
		}
		return $this->formatRows(DesignTraceClientAccess::fetchVisibleFiles($this->db, $socid, $type, (int) $docid));
	}

	/**
	 * The customer's documents with everything for them: the main call of a customer portal
	 *
	 * One entry per quote, order or ticket that has something for the customer, newest first: the
	 * files they can download, the licences of its designs (with wording, permissions and whether
	 * they accepted), the proofs they can approve, the designs they can give gallery consent for,
	 * and for orders whether they can be reordered. "todo" counts what waits for the customer.
	 * Draft and cancelled documents are never listed. Same rules as Dolibarr's Web portal.
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	string	$element	Only this kind of document: quote, order or ticket
	 * @return	array				Documents (see GET customers/{socid}/documents/{element}/{docid})
	 * @phan-return array<int,array<string,mixed>>
	 * @phpstan-return array<int,array<string,mixed>>
	 *
	 * @url	GET customers/{socid}/documents
	 *
	 * @throws RestException 400 Bad document type
	 * @throws RestException 403 Not allowed
	 */
	public function listDocuments($socid, $element = '')
	{
		$socid = $this->checkCustomer($socid);
		$type = '';
		if ($element !== '') {
			$type = DesignTraceClientAccess::normaliseElement($element);
			if ($type === '') {
				throw new RestException(400, 'Bad document type, use quote, order, ticket, project or grant');
			}
		}
		$result = array();
		foreach (DesignTraceCustomerPortal::fetchDocuments($this->db, $socid, $type) as $doc) {
			$result[] = $this->formatDocument($doc);
		}
		return $result;
	}

	/**
	 * One document of the customer with everything for them
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	string	$element	quote, order or ticket
	 * @param	int		$docid		Document id
	 * @return	array				type, id, ref, status, date, delivered (orders), files, licences, proofs, consent,
	 *								reorder (orders: possible, pending_quote_id), todo (licences, proofs, consent)
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	GET customers/{socid}/documents/{element}/{docid}
	 *
	 * @throws RestException 400 Bad document type
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Nothing for the customer on this document
	 */
	public function getDocument($socid, $element, $docid)
	{
		$socid = $this->checkCustomer($socid);
		$type = DesignTraceClientAccess::normaliseElement($element);
		if ($type === '') {
			throw new RestException(400, 'Bad document type, use quote, order, ticket, project or grant');
		}
		$docs = DesignTraceCustomerPortal::fetchDocuments($this->db, $socid, $type, (int) $docid);
		if (empty($docs)) {
			throw new RestException(404, 'Nothing for this customer on this document');
		}
		return $this->formatDocument(reset($docs));
	}

	/**
	 * The customer's copy of an accepted licence: the raw PDF is the response
	 *
	 * The PDF written when the customer accepted the licence of a design on a quote or order (an
	 * order made from a quote has the quote's copy). For a backend that proxies files: errors are
	 * JSON, the response is the PDF itself with X-Content-SHA256.
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	string	$element	quote or order
	 * @param	int		$docid		Document id
	 * @param	int		$designid	Design id
	 * @return	void				The raw PDF (never returns)
	 *
	 * @url	GET customers/{socid}/documents/{element}/{docid}/licences/{designid}/pdf
	 *
	 * @throws RestException 400 Bad document type
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Document not found, or no accepted licence for this design
	 */
	public function getLicencePdf($socid, $element, $docid, $designid)
	{
		$socid = $this->checkCustomer($socid);
		$doc = $this->checkLicenceDocument($socid, $element, $docid);
		$pdf = DesignTraceCustomerPortal::getAcceptancePdf($this->db, $socid, $doc->element, (int) $doc->id, (int) $designid);
		if (!$pdf) {
			throw new RestException(404, 'No accepted licence for this design on this document');
		}
		DesignTraceClientAccess::sendFile($pdf['path'], $pdf['filename'], false, array(
			'X-Content-SHA256' => (string) hash_file('sha256', dol_osencode($pdf['path'])),
		));
	}

	/**
	 * Get a short-lived signed link to download a file
	 *
	 * The link works without login until it expires (setup: link lifetime, 5 minutes by default).
	 * The rules are checked again when it is used, and the download is logged with the actor.
	 * With purpose=view (3D files only, viewer_3d in the file lists) the link is for the 3D viewer:
	 * the file is served inline, the browser may fetch it from the origins set up in DesignTrace
	 * (CORS), and it is logged as opened in the 3D viewer.
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	int		$fileid		File id (file_id in the file lists)
	 * @param	string	$element	Document type the file is downloaded through (optional): quote, order or ticket
	 * @param	int		$docid		Document id (optional)
	 * @param	string	$actor		Who downloads, for the log (e.g. the portal login or contact id)
	 * @param	string	$purpose	download (default) or view
	 * @return	array				url, expires (timestamp), filename, filesize, sha256, purpose
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	GET customers/{socid}/files/{fileid}/link
	 *
	 * @throws RestException 400 Bad document type or purpose, or not a 3D file
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 File not visible to this customer
	 */
	public function getDownloadLink($socid, $fileid, $element = '', $docid = 0, $actor = '', $purpose = 'download')
	{
		if (!in_array($purpose, array('download', 'view'), true)) {
			throw new RestException(400, 'Bad purpose, use download or view');
		}
		$socid = $this->checkCustomer($socid);
		$type = '';
		if ($element !== '') {
			$type = DesignTraceClientAccess::normaliseElement($element);
			if ($type === '') {
				throw new RestException(400, 'Bad document type, use quote, order, ticket, project or grant');
			}
		}
		$row = DesignTraceClientAccess::getDownloadableFile($this->db, $socid, (int) $fileid, $type, (int) $docid, $blocked);
		if (!$row && $blocked === 'licence') {
			throw new RestException(403, 'Licence not accepted for this design');
		}
		if (!$row) {
			throw new RestException(404, 'File not visible to this customer');
		}
		$actor = trim((string) $actor);
		if ($actor === '') {
			$actor = 'api:'.DolibarrApiAccess::$user->login;
		}
		if ($purpose == 'view' && !DesignTraceClientAccess::is3dFormat((string) $row->format)) {
			throw new RestException(400, 'Only 3D files (viewer_3d) can be opened in the viewer');
		}
		$link = DesignTraceClientAccess::buildSignedUrl($row, $socid, $actor, $purpose == 'view');

		return array(
			'url' => $link['url'],
			'expires' => $link['expires'],
			'filename' => $row->filename,
			'filesize' => (int) $row->filesize,
			'sha256' => $row->sha256,
			'purpose' => $purpose,
		);
	}

	/**
	 * Download a file directly: the raw file is the response
	 *
	 * For a backend that proxies files to customers (My workshop's server, when Dolibarr is not
	 * reachable from outside): the file is streamed back to the API caller instead of a link. Access
	 * is the same as for GET customers/{socid}/files/{fileid}/link: the file must be visible to the
	 * customer now (production files never; source files only where the licence and ownership allow;
	 * "Visible now", or "Visible on completion" on a delivered order). Errors are JSON, sent before
	 * any file data. The response is the file itself (not JSON) with Content-Type, Content-Length,
	 * Content-Disposition (attachment, or inline with inline=1 for 3D files, e.g. for a viewer),
	 * X-Content-SHA256, X-DesignTrace-File-Id, X-DesignTrace-Revision (its ref) and X-DesignTrace-Revision-Id. Every download is logged
	 * with the channel "API (direct)". client_ip and user_agent are the customer's as seen by the
	 * backend: they are only written to the log (trusted because the caller holds the service API
	 * key) and never affect access; when missing or invalid, the caller's own are logged.
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	int		$fileid		File id (file_id in the file lists)
	 * @param	string	$element	Document type the file is downloaded through (optional): quote, order or ticket
	 * @param	int		$docid		Document id (optional)
	 * @param	string	$actor		Who downloads, for the log (default api:<login of the API user>)
	 * @param	string	$client_ip	Customer's IP address, for the log only
	 * @param	string	$user_agent	Customer's browser, for the log only (truncated to 255 characters)
	 * @param	int		$inline		1 = Content-Disposition inline (3D formats only, viewer_3d in the file lists)
	 * @param	int		$admin_preview	1 = staff acting as this customer in the portal: the licence need not be accepted (the file must still be visible to the customer). Staff users only (not external users) with the right to read designs; logged on its own channel, never shown to the customer
	 * @return	void				The raw file (never returns: the file is streamed and the request ends)
	 *
	 * @url	GET customers/{socid}/files/{fileid}/content
	 *
	 * @throws RestException 400 Bad document type, or inline on a file that is not 3D
	 * @throws RestException 403 Not allowed (a preview is for staff users with the right to read designs)
	 * @throws RestException 404 File not visible to this customer, or missing on disk
	 * @throws RestException 503 System error (download not logged, file not sent)
	 */
	public function getFileContent($socid, $fileid, $element = '', $docid = 0, $actor = '', $client_ip = '', $user_agent = '', $inline = 0, $admin_preview = 0)
	{
		$socid = $this->checkCustomer($socid);
		$type = '';
		if ($element !== '') {
			$type = DesignTraceClientAccess::normaliseElement($element);
			if ($type === '') {
				throw new RestException(400, 'Bad document type, use quote, order, ticket, project or grant');
			}
		}
		$previewing = !empty($admin_preview);
		if ($previewing) {
			$this->checkStaff('design', 'read');	// Staff only: never an external (customer) user, whatever rights it holds
		}
		$row = DesignTraceClientAccess::getDownloadableFile($this->db, $socid, (int) $fileid, $type, (int) $docid, $blocked, $previewing);
		if (!$row && $blocked === 'licence') {
			throw new RestException(403, 'Licence not accepted for this design');
		}
		if (!$row) {
			throw new RestException(404, 'File not visible to this customer');
		}
		if (!empty($inline) && !DesignTraceClientAccess::is3dFormat((string) $row->format)) {
			throw new RestException(400, 'Only 3D files (viewer_3d) can be sent inline');
		}
		$fullpath = DesignTraceClientAccess::getFilePath($this->db, $row);
		if ($fullpath === '' || !is_file(dol_osencode($fullpath))) {
			throw new RestException(404, 'File missing on disk');
		}
		$actor = trim((string) $actor);
		if ($actor === '') {
			$actor = 'api:'.DolibarrApiAccess::$user->login;
		}
		if (DesignTraceClientAccess::logDownload($this->db, $row, $socid, ($previewing ? DesignTraceClientAccess::CHANNEL_ADMIN_PREVIEW : DesignTraceClientAccess::CHANNEL_API_DIRECT), $actor, (trim((string) $client_ip) !== '' ? (string) $client_ip : null), ((string) $user_agent !== '' ? (string) $user_agent : null)) < 0) {
			throw new RestException(503, 'Error logging the download');
		}
		// Restler would encode a return value: send the file ourselves and end the request
		DesignTraceClientAccess::sendFile($fullpath, $row->filename, !empty($inline), DesignTraceClientAccess::fileHeaders($row));
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
		$licences = array_filter(DesignTraceLicensing::fetchDocumentLicences($this->db, $doc->element, (int) $doc->id), function ($info) {
			return $info['licence'] && $info['version'];
		});
		return $this->formatLicences($doc, $licences);
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
	 * @param	string	$client_ip	Customer's IP address as seen by the calling backend, recorded only {@from body}
	 * @param	string	$user_agent	Customer's browser as seen by the calling backend, recorded only {@from body}
	 * @param	string	$actor_role	Acting user's role in the customer's account: admin or user (see the setting who may accept and approve) {@from body}
	 * @return	array				id of the acceptance, date
	 * @phan-return array<string,int>
	 * @phpstan-return array<string,int>
	 *
	 * @url	POST customers/{socid}/documents/{element}/{docid}/licences/accept
	 *
	 * @throws RestException 400 Bad document type
	 * @throws RestException 403 Not allowed, or role_not_allowed (the customer's setting reserves this to its admins)
	 * @throws RestException 404 Document not found
	 * @throws RestException 409 Licence changed or nothing to accept
	 */
	public function acceptLicence($socid, $element, $docid, $design_id, $version_id, $actor = '', $client_ip = '', $user_agent = '', $actor_role = '')
	{
		$socid = $this->checkCustomer($socid);
		$doc = $this->checkLicenceDocument($socid, $element, $docid);
		$this->checkApprover($socid, $actor_role);
		$actor = trim((string) $actor);
		if ($actor === '') {
			$actor = 'api:'.DolibarrApiAccess::$user->login;
		}
		$error = '';
		$id = DesignTraceLicensing::accept($this->db, $socid, $doc->element, (int) $doc->id, (int) $design_id, (int) $version_id, DesignTraceClientAccess::CHANNEL_API, $actor, $error, (string) $client_ip, (string) $user_agent);
		if ($id <= 0) {
			throw new RestException(409, $error);
		}
		return array('id' => (int) $id, 'date' => dol_now());
	}

	/**
	 * List the proof drawings a customer can decide on through a document, with their decision
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	string	$element	quote, order or ticket
	 * @param	int		$docid		Document id
	 * @return	array				revision_id, revision_ref, component_ref, component_label, decision (none, approved, changes),
	 *								date_decision, decided_by, can_decide
	 * @phan-return array<int,array<string,mixed>>
	 * @phpstan-return array<int,array<string,mixed>>
	 *
	 * @url	GET customers/{socid}/documents/{element}/{docid}/proofs
	 *
	 * @throws RestException 400 Bad document type
	 * @throws RestException 403 Not allowed
	 */
	public function listProofs($socid, $element, $docid)
	{
		$socid = $this->checkCustomer($socid);
		$type = DesignTraceClientAccess::normaliseElement($element);
		if ($type === '') {
			throw new RestException(400, 'Bad document type, use quote, order, ticket, project or grant');
		}
		return $this->formatProofs(DesignTraceProofApproval::fetchProofs($this->db, $socid, $type, (int) $docid));
	}

	/**
	 * Approve a proof drawing, or request changes
	 *
	 * A change request needs a comment and opens a ticket or a draft ECO, as set up in DesignTrace.
	 * An approval is final for that revision.
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	string	$element	quote, order or ticket the proof is shown through
	 * @param	int		$docid		Document id
	 * @param	int		$revid		Revision of the proof
	 * @param	string	$decision	approve or changes {@from body}
	 * @param	string	$comment	Customer's comment (required for changes) {@from body}
	 * @param	string	$actor		Who decided, for the log {@from body}
	 * @param	string	$client_ip	Customer's IP address as seen by the calling backend, recorded only {@from body}
	 * @param	string	$user_agent	Customer's browser as seen by the calling backend, recorded only {@from body}
	 * @param	string	$actor_role	Acting user's role: admin or user {@from body}
	 * @return	array				id of the decision, ticket_id, eco_id (0 if none)
	 * @phan-return array<string,int>
	 * @phpstan-return array<string,int>
	 *
	 * @url	POST customers/{socid}/documents/{element}/{docid}/proofs/{revid}/decision
	 *
	 * @throws RestException 400 Bad document type or decision
	 * @throws RestException 403 Not allowed
	 * @throws RestException 409 Not available or already approved
	 */
	public function decideProof($socid, $element, $docid, $revid, $decision, $comment = '', $actor = '', $client_ip = '', $user_agent = '', $actor_role = '')
	{
		$socid = $this->checkCustomer($socid);
		$this->checkApprover($socid, $actor_role);
		$type = DesignTraceClientAccess::normaliseElement($element);
		if ($type === '') {
			throw new RestException(400, 'Bad document type, use quote, order, ticket, project or grant');
		}
		$values = array('approve' => DesignTraceProofApproval::DECISION_APPROVED, 'changes' => DesignTraceProofApproval::DECISION_CHANGES);
		$decision = strtolower(trim((string) $decision));
		if (!isset($values[$decision])) {
			throw new RestException(400, 'Bad decision, use approve or changes');
		}
		$actor = trim((string) $actor);
		if ($actor === '') {
			$actor = 'api:'.DolibarrApiAccess::$user->login;
		}
		$error = '';
		$res = DesignTraceProofApproval::decide($this->db, DolibarrApiAccess::$user, $socid, (int) $revid, $type, (int) $docid, $values[$decision], (string) $comment, DesignTraceClientAccess::CHANNEL_API, $actor, $error, (string) $client_ip, (string) $user_agent);
		if (!is_array($res)) {
			throw new RestException(409, $error);
		}
		return array('id' => $res['id'], 'ticket_id' => $res['ticket'], 'eco_id' => $res['eco']);
	}

	/**
	 * Reorder a delivered order
	 *
	 * Makes a draft quote with the order's lines, pinned to the revisions that were delivered (same
	 * revision, material, process, machine), for staff to check and send. Asking again while that
	 * quote is still a draft returns the same quote.
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	int		$orderid	Delivered order
	 * @param	array	$request_data	Optional: actor (who asked, for the quote's note)
	 * @phan-param ?array<string,mixed> $request_data
	 * @phpstan-param ?array<string,mixed> $request_data
	 * @return	array				quote_id, quote_ref
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	POST customers/{socid}/orders/{orderid}/reorder
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Order not found
	 * @throws RestException 409 Order cannot be reordered
	 */
	public function reorder($socid, $orderid, $request_data = null)
	{
		$socid = $this->checkCustomer($socid);
		// A single optional body field would receive the whole body in Restler: read it from the array
		$actor = (is_array($request_data) && isset($request_data['actor']) && is_scalar($request_data['actor'])) ? (string) $request_data['actor'] : '';
		$error = '';
		$quoteid = DesignTraceCustomerPortal::reorder($this->db, DolibarrApiAccess::$user, $socid, (int) $orderid, 'api', $this->getActor($actor), $error);
		if ($quoteid <= 0) {
			throw new RestException($error == 'notfound' ? 404 : 409, $error == 'notfound' ? 'Order not found' : $error);
		}
		$quote = new Propal($this->db);
		$quote->fetch($quoteid);
		return array('quote_id' => (int) $quoteid, 'quote_ref' => $quote->ref);
	}

	/**
	 * Portfolio consent of the designs on a quote or order
	 *
	 * Confidential designs are not listed: they never go to the gallery.
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	string	$element	quote or order
	 * @param	int		$docid		Id of the quote or order
	 * @return	array				design_id, design_ref, design_label, consent (none, granted, refused), date_consent
	 * @phan-return array<int,array<string,mixed>>
	 * @phpstan-return array<int,array<string,mixed>>
	 *
	 * @url	GET customers/{socid}/documents/{element}/{docid}/consent
	 *
	 * @throws RestException 400 Bad document type
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Document not found
	 */
	public function listConsent($socid, $element, $docid)
	{
		$socid = $this->checkCustomer($socid);
		$doc = $this->checkLicenceDocument($socid, $element, $docid);
		$designs = array_filter(DesignTraceLicensing::fetchDesignsOfDocument($this->db, $doc->element, (int) $doc->id), function ($design) {
			return empty($design['confidential']);
		});
		return $this->formatConsent($designs);
	}

	/**
	 * Record the customer's portfolio consent for a design on a quote or order
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	string	$element	quote or order
	 * @param	int		$docid		Id of the quote or order
	 * @param	int		$design_id	Design on the document {@from body}
	 * @param	string	$consent	granted or refused {@from body}
	 * @param	string	$actor		Who answered, for the log (e.g. the site's user) {@from body}
	 * @param	string	$client_ip	Customer's IP address as seen by the calling backend, recorded only {@from body}
	 * @return	array				design_id, consent, changed (false when it was already the answer)
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	POST customers/{socid}/documents/{element}/{docid}/consent
	 *
	 * @throws RestException 400 Bad document type or consent
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Document or design not found
	 * @throws RestException 503 System error
	 */
	public function setConsent($socid, $element, $docid, $design_id, $consent, $actor = '', $client_ip = '')
	{
		$socid = $this->checkCustomer($socid);
		$doc = $this->checkLicenceDocument($socid, $element, $docid);
		$values = array('granted' => DesignTracePortfolio::CONSENT_GRANTED, 'refused' => DesignTracePortfolio::CONSENT_REFUSED);
		$consent = strtolower(trim((string) $consent));
		if (!isset($values[$consent])) {
			throw new RestException(400, 'Bad consent, use granted or refused');
		}
		$error = '';
		$res = DesignTraceCustomerPortal::setConsent($this->db, $socid, $doc->element, (int) $doc->id, (int) $design_id, $values[$consent], DesignTracePortfolio::CHANNEL_API, $this->getActor($actor), (string) $client_ip, $error);
		if ($res < 0) {
			throw new RestException($error == 'design' ? 404 : 503, $error == 'design' ? 'Design not found on this document' : 'Error saving consent');
		}
		return array('design_id' => (int) $design_id, 'consent' => $consent, 'changed' => ($res > 0));
	}

	/**
	 * Portfolio gallery: active, non-confidential designs whose customer consented, with the
	 * images of their Documents tab
	 *
	 * @return	array				id, ref, label, date_consent, images (image_id, filename, filesize)
	 * @phan-return array<int,array<string,mixed>>
	 * @phpstan-return array<int,array<string,mixed>>
	 *
	 * @url	GET portfolio
	 *
	 * @throws RestException 403 Not allowed
	 */
	public function listPortfolio()
	{
		$this->checkService();
		return DesignTracePortfolio::fetchGallery($this->db);
	}

	/**
	 * Short-lived signed link to a gallery image
	 *
	 * @param	int		$designid	Design id
	 * @param	int		$imageid	Image id (from GET portfolio)
	 * @return	array				url, expires, filename
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	GET portfolio/{designid}/images/{imageid}/link
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Image not in the gallery
	 */
	public function getPortfolioImageLink($designid, $imageid)
	{
		$this->checkService();
		$image = DesignTracePortfolio::getImage($this->db, (int) $designid, (int) $imageid);
		if (!$image) {
			throw new RestException(404, 'Image not in the gallery');
		}
		$link = DesignTracePortfolio::buildSignedUrl((int) $designid, (int) $imageid);
		return array('url' => $link['url'], 'expires' => $link['expires'], 'filename' => $image['filename']);
	}

	/**
	 * Gallery image directly: the raw image is the response
	 *
	 * For a backend that proxies the gallery because Dolibarr is not reachable from outside: the
	 * image is streamed back (inline) instead of a link, while the design is still in the gallery
	 * (active, not confidential, consent granted). Errors are JSON. Headers: Content-Type,
	 * Content-Length, X-Content-SHA256. Gallery images are public on the site, so they are not logged.
	 *
	 * @param	int		$designid	Design id
	 * @param	int		$imageid	Image id (from GET portfolio)
	 * @return	void				The raw image (never returns)
	 *
	 * @url	GET portfolio/{designid}/images/{imageid}/content
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Image not in the gallery, or missing on disk
	 */
	public function getPortfolioImageContent($designid, $imageid)
	{
		$this->checkService();
		$image = DesignTracePortfolio::getImage($this->db, (int) $designid, (int) $imageid);
		if (!$image || !is_file(dol_osencode($image['path']))) {
			throw new RestException(404, 'Image not in the gallery');
		}
		DesignTraceClientAccess::sendFile($image['path'], $image['filename'], true, array(
			'X-Content-SHA256' => (string) hash_file('sha256', dol_osencode($image['path'])),
		));
	}

	/**
	 * Check the API user is the internal service user
	 *
	 * @return	void
	 *
	 * @throws RestException 403 Not allowed
	 */
	private function checkService()
	{
		$apiuser = DolibarrApiAccess::$user;
		if (!isModEnabled('designtrace') || !$apiuser->hasRight('designtrace', 'clientfiles', 'api') || $apiuser->socid > 0) {
			throw new RestException(403, 'Needs an internal user with the DesignTrace right "Customer files through the API"');
		}
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
		$sql .= " AND dl.channel <> '".$this->db->escape(DesignTraceClientAccess::CHANNEL_ADMIN_PREVIEW)."'";	// Staff previews are not the customer's downloads
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
	 * Actor for logs: the one the backend passed, else api:<login of the API user>
	 *
	 * @param	string	$actor	Actor passed by the caller
	 * @return	string
	 */
	private function getActor($actor)
	{
		$actor = trim((string) $actor);
		return ($actor !== '' ? $actor : 'api:'.DolibarrApiAccess::$user->login);
	}

	/**
	 * A document of DesignTraceCustomerPortal::fetchDocuments() for the API
	 *
	 * @param	array<string,mixed>	$doc	Document
	 * @return	array<string,mixed>
	 */
	private function formatDocument($doc)
	{
		$licences = array();
		if (!empty($doc['licences'])) {
			$object = DesignTraceLicensing::fetchDocument($this->db, $doc['element'], $doc['doc_id']);
			$licences = ($object ? $this->formatLicences($object, $doc['licences']) : array());
		}
		$proofs = $this->formatProofs($doc['proofs']);
		$consent = $this->formatConsent($doc['designs']);
		$result = array(
			'type' => $this->publicElement($doc['element']),
			'id' => (int) $doc['doc_id'],
			'ref' => $doc['doc_ref'],
			'status' => (int) $doc['doc_status'],
			'date' => $doc['date'],
			'files' => $this->formatRows($doc['files']),
			'licences' => $licences,
			'proofs' => $proofs,
			'consent' => $consent,
			'todo' => array(
				'licences' => count(array_filter($licences, function ($l) {
					return $l['accepted'] === null;
				})),
				'proofs' => count(array_filter($proofs, function ($p) {
					return $p['can_decide'];
				})),
				'consent' => count(array_filter($consent, function ($c) {
					return $c['consent'] === 'none';
				})),
			),
		);
		if ($doc['element'] == 'commande') {
			$result['delivered'] = ((int) $doc['doc_status'] === Commande::STATUS_CLOSED);
			$result['reorder'] = $doc['reorder'] ?: array('possible' => false, 'pending_quote_id' => 0);
		}
		return $result;
	}

	/**
	 * Licences for the API
	 *
	 * @param	CommonObject|null					$doc		Quote or order, null = licence of the design itself
	 * @param	array<int,array<string,mixed>>		$licences	Entries of fetchDocumentLicences() with a licence and version
	 * @param	string								$customername	Customer name when there is no document
	 * @return	array<int,array<string,mixed>>
	 */
	private function formatLicences($doc, $licences, $customername = '')
	{
		$result = array();
		foreach ($licences as $designid => $info) {
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
				'wording_html' => $version->renderWording(DesignTraceLicensing::getWordingValues($doc, $info['design'], $date, $customername)),
				'permissions' => $permissions,
				'accepted' => ($acc ? array('date' => (int) $acc->date_accept, 'actor' => $acc->actor, 'channel' => $acc->channel, 'pdf' => !empty($acc->filename)) : null),
			);
		}
		return $result;
	}

	/**
	 * Proofs for the API
	 *
	 * @param	array<int,array<string,mixed>>	$proofs		Entries of DesignTraceProofApproval::fetchProofs()
	 * @return	array<int,array<string,mixed>>
	 */
	private function formatProofs($proofs)
	{
		$names = array(DesignTraceProofApproval::DECISION_NONE => 'none', DesignTraceProofApproval::DECISION_APPROVED => 'approved', DesignTraceProofApproval::DECISION_CHANGES => 'changes');
		$result = array();
		foreach ($proofs as $proof) {
			$result[] = array(
				'revision_id' => $proof['rev_id'],
				'revision_ref' => $proof['rev_ref'],
				'revision_released' => ($proof['rev_status'] === ComponentRevision::STATUS_RELEASED),
				'component_ref' => $proof['comp_ref'],
				'component_label' => $proof['comp_label'],
				'decision' => $names[$proof['decision']] ?? 'none',
				'date_decision' => $proof['date_decision'],
				'decided_by' => $proof['decided_by'],
				'can_decide' => $proof['can_decide'],
			);
		}
		return $result;
	}

	/**
	 * Gallery consent for the API
	 *
	 * @param	array<int,array<string,mixed>>	$designs	Non-confidential designs of a document (by id)
	 * @return	array<int,array<string,mixed>>
	 */
	private function formatConsent($designs)
	{
		$names = array(DesignTracePortfolio::CONSENT_NONE => 'none', DesignTracePortfolio::CONSENT_GRANTED => 'granted', DesignTracePortfolio::CONSENT_REFUSED => 'refused');
		$result = array();
		foreach ($designs as $id => $design) {
			$history = DesignTracePortfolio::fetchHistory($this->db, $id);
			$consent = (int) DesignTracePortfolio::getConsent($this->db, $id);
			$result[] = array(
				'design_id' => (int) $id,
				'design_ref' => $design['ref'],
				'design_label' => $design['label'],
				'consent' => $names[$consent] ?? 'none',
				'date_consent' => ($history ? $history[0]->date_consent : null),
			);
		}
		return $result;
	}

	/**
	 * The customer's design library: every design they reach, whatever the basis
	 *
	 * A customer reaches a design through a quote, order, ticket or project link, or a design-level
	 * grant. One entry per design: ref, label, archived, confidential (and its label: it does not
	 * restrict the customer), access_ended (a revoked or expired grant with no other basis: name and
	 * history only), bases (what gives access), component counts, flags (licence to accept, proof
	 * awaiting approval, consent not asked, newer revision) and needs_action.
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	string	$q			Search in design name, DSN and component numbers/names
	 * @param	string	$type		Only designs with a component of this type: part, assembly or drawing
	 * @param	int		$project	Only designs of this project id
	 * @param	string	$element	Only designs reached through this kind of document: quote, order, ticket, project or grant
	 * @param	int		$docid		With element: only this document
	 * @param	int		$action		1 = only designs needing the customer's action
	 * @param	int		$page		Page, from 0
	 * @param	int		$limit		Page size (1-200)
	 * @return	array				total, page, limit, items
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	GET customers/{socid}/library
	 *
	 * @throws RestException 400 Bad filter
	 * @throws RestException 403 Not allowed
	 */
	public function getLibrary($socid, $q = '', $type = '', $project = 0, $element = '', $docid = 0, $action = 0, $page = 0, $limit = 25)
	{
		$socid = $this->checkCustomer($socid);
		if ($type !== '' && !in_array($type, array('part', 'assembly', 'drawing'), true)) {
			throw new RestException(400, 'Bad type, use part, assembly or drawing');
		}
		$filters = array('q' => $q, 'type' => $type, 'project' => (int) $project, 'docid' => (int) $docid, 'action' => (int) $action);
		if ($element !== '') {
			$filters['element'] = DesignTraceClientAccess::normaliseElement($element);
			if ($filters['element'] === '') {
				throw new RestException(400, 'Bad document type, use quote, order, ticket, project or grant');
			}
		}
		return DesignTraceLibrary::fetchLibrary($this->db, $socid, $filters, (int) $page, (int) $limit);
	}

	/**
	 * Detail of a design: tree of components (assemblies, parts, drawings) with their current and
	 * delivered revisions, release notes and files (size, SHA-256), external dependencies the
	 * customer also has access to, and the history. Revision ids come from here for the file calls.
	 * With access ended only the name and history are returned (tree is empty).
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	int		$designid	Design id
	 * @return	array				The design entry (as in the library list) plus tree, roots, history
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	GET customers/{socid}/designs/{designid}
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 The customer never had access to this design
	 */
	public function getDesign($socid, $designid)
	{
		$socid = $this->checkCustomer($socid);
		$design = DesignTraceLibrary::fetchDesign($this->db, $socid, (int) $designid);
		if (!$design) {
			throw new RestException(404, 'Design not found for this customer');
		}
		return $design;
	}

	/**
	 * Detail of a revision the customer holds (or the current one of a component they reach):
	 * release note, status, files with size and SHA-256 (files only for a revision they hold)
	 *
	 * @param	int		$socid			Customer (third party) id
	 * @param	int		$revisionid		Revision id
	 * @return	array					Revision
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	GET customers/{socid}/revisions/{revisionid}
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Revision not reachable by this customer
	 */
	public function getRevision($socid, $revisionid)
	{
		$socid = $this->checkCustomer($socid);
		$revision = DesignTraceLibrary::fetchRevision($this->db, $socid, (int) $revisionid);
		if (!$revision) {
			throw new RestException(404, 'Revision not found for this customer');
		}
		return $revision;
	}

	/**
	 * Differences between the delivered and the current revision of a component: release notes and
	 * files (added, removed, changed by SHA-256, unchanged). Hidden and production files never appear.
	 *
	 * @param	int		$socid			Customer (third party) id
	 * @param	int		$componentid	Component id
	 * @param	int		$from			Older revision id (default: the delivered one, else the one held)
	 * @param	int		$to				Newer revision id (default: the current one)
	 * @return	array					from, to, files
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	GET customers/{socid}/components/{componentid}/compare
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Nothing to compare for this customer
	 */
	public function compareRevisions($socid, $componentid, $from = 0, $to = 0)
	{
		$socid = $this->checkCustomer($socid);
		$result = DesignTraceLibrary::compareRevisions($this->db, $socid, (int) $componentid, (int) $from, (int) $to);
		if (!$result) {
			throw new RestException(404, 'Nothing to compare for this customer');
		}
		return $result;
	}

	/**
	 * Components with a newer released revision than the one the customer holds, also for grants
	 * pinned to an older revision (the pin only moves by hand). Includes the customer release note.
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @return	array				One entry per component
	 * @phan-return array<int,array<string,mixed>>
	 * @phpstan-return array<int,array<string,mixed>>
	 *
	 * @url	GET customers/{socid}/newer-revisions
	 *
	 * @throws RestException 403 Not allowed
	 */
	public function getNewerRevisions($socid)
	{
		$socid = $this->checkCustomer($socid);
		return DesignTraceLibrary::fetchNewerRevisions($this->db, $socid);
	}

	/**
	 * The licence that applies to a design for this customer (the design's template; a document's
	 * override counts when the customer accepts through that quote or order), the version that
	 * counts, its wording and permissions, and the acceptance if any. One acceptance per design and
	 * customer covers every document and grant.
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	int		$designid	Design id
	 * @return	array				Licence entry (empty if the design has no licence)
	 * @phan-return array<int,array<string,mixed>>
	 * @phpstan-return array<int,array<string,mixed>>
	 *
	 * @url	GET customers/{socid}/designs/{designid}/licence
	 *
	 * @throws RestException 403 Not allowed or access ended
	 * @throws RestException 404 Design not found for this customer
	 */
	public function getDesignLicence($socid, $designid)
	{
		$socid = $this->checkCustomer($socid);
		$this->checkDesignAccess($socid, $designid);
		$info = DesignTraceLicensing::fetchDesignLicence($this->db, $socid, (int) $designid, DesignTraceLicensing::getForcedLicence($this->db, $socid, (int) $designid));
		if (!$info || !$info['licence'] || !$info['version']) {
			return array();
		}
		$soc = new Societe($this->db);
		$soc->fetch($socid);
		return $this->formatLicences(null, array((int) $designid => $info), (string) $soc->name);
	}

	/**
	 * Record that the customer accepts the licence of a design, without a quote or order (also
	 * what a quote acceptance calls first). Covers every document and grant of the customer for the
	 * design. Pass the version_id shown: if the licence changed since the call is refused (409).
	 * A PDF copy is written; fetch it with GET .../licence/pdf.
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	int		$designid	Design id
	 * @param	int		$version_id	Licence version id shown to the customer
	 * @param	string	$actor		Who accepts, for the log (e.g. the portal login)
	 * @param	string	$client_ip	Customer's IP address as seen by the calling backend, recorded only {@from body}
	 * @param	string	$user_agent	Customer's browser as seen by the calling backend, recorded only {@from body}
	 * @param	string	$actor_role	Acting user's role in the customer's account: admin or user (see the setting who may accept and approve) {@from body}
	 * @return	array				id of the acceptance, date
	 * @phan-return array<string,int>
	 * @phpstan-return array<string,int>
	 *
	 * @url	POST customers/{socid}/designs/{designid}/licence/accept
	 *
	 * @throws RestException 403 Not allowed or access ended
	 * @throws RestException 404 Design not found for this customer
	 * @throws RestException 409 Licence changed or nothing to accept
	 */
	public function acceptDesignLicence($socid, $designid, $version_id, $actor = '', $client_ip = '', $user_agent = '', $actor_role = '')
	{
		$socid = $this->checkCustomer($socid);
		$this->checkDesignAccess($socid, $designid);
		$this->checkApprover($socid, $actor_role);
		$actor = trim((string) $actor);
		if ($actor === '') {
			$actor = 'api:'.DolibarrApiAccess::$user->login;
		}
		$error = '';
		$id = DesignTraceLicensing::acceptDesign($this->db, $socid, (int) $designid, (int) $version_id, DesignTraceClientAccess::CHANNEL_API, $actor, $error, (string) $client_ip, (string) $user_agent);
		if ($id <= 0) {
			throw new RestException(409, $error);
		}
		return array('id' => (int) $id, 'date' => dol_now());
	}

	/**
	 * The customer's copy of the accepted licence of a design: the raw PDF is the response (errors
	 * are JSON), with X-Content-SHA256. Works for acceptances given through a document too.
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	int		$designid	Design id
	 * @return	void				The raw PDF (never returns)
	 *
	 * @url	GET customers/{socid}/designs/{designid}/licence/pdf
	 *
	 * @throws RestException 403 Not allowed or access ended
	 * @throws RestException 404 Design not found, or no accepted licence
	 */
	public function getDesignLicencePdf($socid, $designid)
	{
		$socid = $this->checkCustomer($socid);
		$this->checkDesignAccess($socid, $designid);
		$acc = DesignTraceLicensing::getAcceptance($this->db, $socid, (int) $designid);
		$path = $acc ? DesignTraceLicensing::getAcceptancePdfPath($this->db, $acc) : '';
		if ($path === '') {
			throw new RestException(404, 'No accepted licence for this design');
		}
		DesignTraceClientAccess::sendFile($path, $acc->filename, false, array(
			'X-Content-SHA256' => (string) hash_file('sha256', dol_osencode($path)),
		));
	}

	/**
	 * Approvals waiting for the customer: flagged revisions In review, one entry per ECO (or per
	 * design when they have no ECO). One approval covers all revisions of an entry. `awaiting`
	 * counts those not decided yet; `changes_requested` those the customer asked changes for
	 * (waiting for the workshop; they can still be approved later).
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @return	array				scope (eco or design), id, ref, label, round (ECOs), design_ids, awaiting, changes_requested, revisions
	 * @phan-return array<int,array<string,mixed>>
	 * @phpstan-return array<int,array<string,mixed>>
	 *
	 * @url	GET customers/{socid}/approvals
	 *
	 * @throws RestException 403 Not allowed
	 */
	public function listApprovals($socid)
	{
		$socid = $this->checkCustomer($socid);
		return DesignTraceApprovals::fetchPending($this->db, $socid);
	}

	/**
	 * Approve: one approval covers every flagged revision waiting in the ECO (scope eco) or in the
	 * design when it has no ECO (scope design). The actor and IP are recorded. Honours the
	 * customer's setting on who may approve: pass the acting user's role, refused with
	 * `role_not_allowed` (403) when the setting reserves it to the customer's admins.
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	string	$scope		eco or design
	 * @param	int		$scopeid	ECO id or design id (from GET approvals)
	 * @param	string	$comment	Optional comment {@from body}
	 * @param	string	$actor		Who approves, for the log {@from body}
	 * @param	string	$actor_role	Acting user's role: admin or user {@from body}
	 * @param	string	$client_ip	Customer's IP address as seen by the calling backend, recorded only {@from body}
	 * @param	string	$user_agent	Customer's browser as seen by the calling backend, recorded only {@from body}
	 * @return	array				revision_ids approved
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	POST customers/{socid}/approvals/{scope}/{scopeid}/approve
	 *
	 * @throws RestException 400 Bad scope
	 * @throws RestException 403 Not allowed, or role_not_allowed
	 * @throws RestException 409 Nothing to approve
	 */
	public function approveScope($socid, $scope, $scopeid, $comment = '', $actor = '', $actor_role = '', $client_ip = '', $user_agent = '')
	{
		$socid = $this->checkCustomer($socid);
		$scope = $this->checkScope($scope);
		$error = '';
		$res = DesignTraceApprovals::approve($this->db, DolibarrApiAccess::$user, $socid, $scope, (int) $scopeid, (string) $actor_role, (string) $comment, DesignTraceClientAccess::CHANNEL_API, $this->getActor($actor), $error, (string) $client_ip, (string) $user_agent);
		$this->checkApprovalResult($res, $error);
		return array('revision_ids' => $res);
	}

	/**
	 * Request changes on named revisions of an approval entry (all of them when revision_ids is
	 * empty). A comment is required. With an ECO the same ECO is reopened as a new round (another
	 * timeline entry); without one a draft ECO (reason customer) is opened.
	 *
	 * @param	int		$socid			Customer (third party) id
	 * @param	string	$scope			eco or design
	 * @param	int		$scopeid		ECO id or design id (from GET approvals)
	 * @param	string	$comment		What to change {@from body}
	 * @param	array	$revision_ids	Revisions the changes are for {@from body}
	 * @param	string	$actor			Who asks, for the log {@from body}
	 * @param	string	$actor_role		Acting user's role: admin or user {@from body}
	 * @param	string	$client_ip		Customer's IP address as seen by the calling backend, recorded only {@from body}
	 * @param	string	$user_agent		Customer's browser as seen by the calling backend, recorded only {@from body}
	 * @return	array					eco_id, round, revision_ids
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	POST customers/{socid}/approvals/{scope}/{scopeid}/request-changes
	 *
	 * @throws RestException 400 Bad scope
	 * @throws RestException 403 Not allowed, or role_not_allowed
	 * @throws RestException 409 Nothing to decide, comment missing or revisions not in this entry
	 */
	public function requestScopeChanges($socid, $scope, $scopeid, $comment, $revision_ids = array(), $actor = '', $actor_role = '', $client_ip = '', $user_agent = '')
	{
		$socid = $this->checkCustomer($socid);
		$scope = $this->checkScope($scope);
		$error = '';
		$res = DesignTraceApprovals::requestChanges($this->db, DolibarrApiAccess::$user, $socid, $scope, (int) $scopeid, is_array($revision_ids) ? $revision_ids : preg_split('/[\s,]+/', (string) $revision_ids, -1, PREG_SPLIT_NO_EMPTY), (string) $comment, (string) $actor_role, DesignTraceClientAccess::CHANNEL_API, $this->getActor($actor), $error, (string) $client_ip, (string) $user_agent);
		$this->checkApprovalResult($res, $error);
		return array('eco_id' => $res['eco'], 'round' => $res['round'], 'revision_ids' => $res['revisions']);
	}

	/**
	 * What waits for the customer: licences to accept, approvals, consent questions, newer revisions
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @return	array				licences, approvals, consent, newer_revisions, total, designs (design ids behind licences, approvals, consent)
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	GET customers/{socid}/attention
	 *
	 * @throws RestException 403 Not allowed
	 */
	public function getAttention($socid)
	{
		$socid = $this->checkCustomer($socid);
		return DesignTraceLibrary::fetchAttention($this->db, $socid);
	}

	/**
	 * History of the customer: releases, approvals and change requests, licence acceptances,
	 * portfolio consent and downloads, newest first, each naming the actor (every user of the
	 * customer's account, not only the caller's). Also for designs whose access ended.
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	int		$design_id	Only this design
	 * @param	int		$limit		Max entries (1-500)
	 * @return	array				date, type, design_id, design_ref, text, actor
	 * @phan-return array<int,array<string,mixed>>
	 * @phpstan-return array<int,array<string,mixed>>
	 *
	 * @url	GET customers/{socid}/history
	 *
	 * @throws RestException 403 Not allowed
	 */
	public function getHistory($socid, $design_id = 0, $limit = 100)
	{
		$socid = $this->checkCustomer($socid);
		return DesignTraceLibrary::fetchHistory($this->db, $socid, (int) $design_id, (int) $limit);
	}

	/**
	 * Portfolio consent of a design the customer reaches, whatever the basis. A confidential design
	 * is never asked (the answer is `confidential`, and it cannot be set).
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	int		$designid	Design id
	 * @return	array				design_id, consent (none, granted, refused), confidential, date_consent
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	GET customers/{socid}/designs/{designid}/consent
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Design not found for this customer
	 */
	public function getDesignConsent($socid, $designid)
	{
		$socid = $this->checkCustomer($socid);
		$this->checkDesignAccess($socid, $designid);
		return $this->formatDesignConsent((int) $designid);
	}

	/**
	 * Record the customer's portfolio consent for a design, without a quote or order
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	int		$designid	Design id
	 * @param	string	$consent	granted or refused {@from body}
	 * @param	string	$actor		Who answered, for the log {@from body}
	 * @param	string	$client_ip	Customer's IP address as seen by the calling backend, recorded only {@from body}
	 * @return	array				design_id, consent, changed (false when it was already the answer)
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	POST customers/{socid}/designs/{designid}/consent
	 *
	 * @throws RestException 400 Bad consent
	 * @throws RestException 403 Not allowed, access ended, or the design is not made for this customer
	 * @throws RestException 404 Design not found for this customer
	 * @throws RestException 409 Confidential designs are not asked
	 */
	public function setDesignConsent($socid, $designid, $consent, $actor = '', $client_ip = '')
	{
		$socid = $this->checkCustomer($socid);
		$this->checkDesignAccess($socid, $designid);
		// Consent is the design's, so only the customer it is made for answers; a customer who merely
		// reaches it (grant, ticket) cannot overwrite that answer
		$resql = $this->db->query("SELECT fk_soc FROM ".$this->db->prefix()."designtrace_design WHERE rowid = ".((int) $designid));
		$owner = $resql ? $this->db->fetch_object($resql) : null;
		if (!$owner || (int) $owner->fk_soc !== (int) $socid) {
			throw new RestException(403, 'Only the customer the design is made for can answer for its portfolio consent');
		}
		$values = array('granted' => DesignTracePortfolio::CONSENT_GRANTED, 'refused' => DesignTracePortfolio::CONSENT_REFUSED);
		$consent = strtolower(trim((string) $consent));
		if (!isset($values[$consent])) {
			throw new RestException(400, 'Bad consent, use granted or refused');
		}
		$current = $this->formatDesignConsent((int) $designid);
		if ($current['confidential']) {
			throw new RestException(409, 'A confidential design is never asked for portfolio consent');
		}
		$res = DesignTracePortfolio::setConsent($this->db, (int) $designid, $values[$consent], DesignTracePortfolio::CHANNEL_API, $this->getActor($actor), '', 0, 0, '', (string) $client_ip);
		if ($res < 0) {
			throw new RestException(503, 'Error saving consent');
		}
		return array('design_id' => (int) $designid, 'consent' => $consent, 'changed' => ($res > 0));
	}

	/**
	 * @param	int		$designid	Design id
	 * @return	array<string,mixed>
	 */
	private function formatDesignConsent($designid)
	{
		$names = array(DesignTracePortfolio::CONSENT_NONE => 'none', DesignTracePortfolio::CONSENT_GRANTED => 'granted', DesignTracePortfolio::CONSENT_REFUSED => 'refused');
		$resql = $this->db->query("SELECT confidential FROM ".$this->db->prefix()."designtrace_design WHERE rowid = ".((int) $designid));
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		$history = DesignTracePortfolio::fetchHistory($this->db, $designid);
		return array('design_id' => $designid, 'consent' => $names[(int) DesignTracePortfolio::getConsent($this->db, $designid)] ?? 'none',
			'confidential' => ($obj && !empty($obj->confidential)), 'date_consent' => ($history ? $history[0]->date_consent : null));
	}

	/**
	 * Refuse, with a distinct error, when the customer reserves licence acceptance and approval to its admins
	 *
	 * @param	int		$socid	Customer id
	 * @param	string	$role	Acting user's role (admin or user)
	 * @return	void
	 */
	private function checkApprover($socid, $role)
	{
		$error = '';
		if (!DesignTraceApprovals::checkRole($this->db, $socid, $role, $error)) {
			throw new RestException(403, 'role_not_allowed: '.$error);
		}
	}

	/**
	 * @param	string	$scope	eco or design
	 * @return	string
	 */
	private function checkScope($scope)
	{
		$scope = strtolower(trim((string) $scope));
		if (!in_array($scope, array(DesignTraceApprovals::SCOPE_ECO, DesignTraceApprovals::SCOPE_DESIGN), true)) {
			throw new RestException(400, 'Bad scope, use eco or design');
		}
		return $scope;
	}

	/**
	 * @param	mixed	$res	Result of an approval call
	 * @param	string	$error	Its error
	 * @return	void
	 */
	private function checkApprovalResult($res, $error)
	{
		if ($res === -2) {
			throw new RestException(403, 'role_not_allowed: '.$error);
		}
		if (!is_array($res)) {
			throw new RestException(409, $error);
		}
	}

	/**
	 * Report a problem or ask for a change on a revision the customer holds
	 *
	 * One flow, type `problem` (defect) or `change`. It creates a ticket (type Design problem / Design
	 * change) and a linked draft ECO (reason customer) shared with the customer. A problem may name
	 * one of the customer's orders that delivered the revision (`order_id`).
	 *
	 * @param	int		$socid			Customer (third party) id
	 * @param	int		$revision_id	Revision the request is about (one the customer holds) {@from body}
	 * @param	string	$type			problem or change {@from body}
	 * @param	string	$summary		Short summary {@from body}
	 * @param	string	$text			What is wrong, or what to change {@from body}
	 * @param	int		$order_id		Problems only: an order that delivered the revision {@from body}
	 * @param	string	$actor			Who asks, for the log {@from body}
	 * @param	string	$client_ip		Customer's IP address as seen by the calling backend, recorded on the ticket {@from body}
	 * @return	array					eco_id, eco_ref, ticket_id, ticket_ref
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	POST customers/{socid}/change-requests
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 409 Not possible (revision not held, bad type, order did not deliver it...)
	 */
	public function reportChangeRequest($socid, $revision_id, $type, $summary, $text, $order_id = 0, $actor = '', $client_ip = '')
	{
		$socid = $this->checkCustomer($socid);
		$error = '';
		$res = DesignTraceChangeRequests::report($this->db, DolibarrApiAccess::$user, $socid, (string) $type, (int) $revision_id, (string) $summary, (string) $text, (int) $order_id, $this->getActor($actor), $error, (string) $client_ip);
		if (!is_array($res)) {
			throw new RestException(409, $error);
		}
		return $res;
	}

	/**
	 * Change requests and ECOs shared with the customer: the ones they raised, and any other ECO whose
	 * "shareable" toggle is on. Newest first.
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @return	array				id, ref, summary, status (open, approved, implemented, closed, withdrawn, cancelled), type (problem, change or empty),
	 *								requested_by, round, date_creation, ticket_id, ticket_ref
	 * @phan-return array<int,array<string,mixed>>
	 * @phpstan-return array<int,array<string,mixed>>
	 *
	 * @url	GET customers/{socid}/ecos
	 *
	 * @throws RestException 403 Not allowed
	 */
	public function listEcos($socid)
	{
		$socid = $this->checkCustomer($socid);
		return DesignTraceChangeRequests::fetchList($this->db, $socid);
	}

	/**
	 * Detail of a shared ECO: summary and request text (never internal notes), status timeline with
	 * rounds, affected components and resulting released revisions (only components the customer
	 * reaches), linked ticket and quote (`waiting_for_quote`), reference files, ticket comments,
	 * and what the customer can still do (`can_withdraw`, `can_attach`).
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	int		$ecoid		ECO id
	 * @return	array				The detail
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	GET customers/{socid}/ecos/{ecoid}
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not shared with this customer
	 */
	public function getEco($socid, $ecoid)
	{
		$socid = $this->checkCustomer($socid);
		$detail = DesignTraceChangeRequests::fetchDetail($this->db, $socid, (int) $ecoid);
		if (!$detail) {
			throw new RestException(404, 'Change order not found for this customer');
		}
		return $detail;
	}

	/**
	 * Withdraw a request while its ECO is a draft: the ECO and the ticket are cancelled with a
	 * "withdrawn" message and both stay in history
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	int		$ecoid		ECO id
	 * @param	string	$comment	Optional reason {@from body}
	 * @param	string	$actor		Who withdraws, for the log {@from body}
	 * @return	array				status
	 * @phan-return array<string,string>
	 * @phpstan-return array<string,string>
	 *
	 * @url	POST customers/{socid}/ecos/{ecoid}/withdraw
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 409 Not a request of this customer, or no longer a draft
	 */
	public function withdrawEco($socid, $ecoid, $comment = '', $actor = '')
	{
		$socid = $this->checkCustomer($socid);
		$error = '';
		if (DesignTraceChangeRequests::withdraw($this->db, DolibarrApiAccess::$user, $socid, (int) $ecoid, $this->getActor($actor), (string) $comment, $error) <= 0) {
			throw new RestException(409, $error);
		}
		return array('status' => 'withdrawn');
	}

	/**
	 * Comment on a request, through its ticket (the workshop sees it there and answers there)
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	int		$ecoid		ECO id
	 * @param	string	$text		The comment {@from body}
	 * @param	string	$actor		Who comments, for the log {@from body}
	 * @return	array				ok
	 * @phan-return array<string,bool>
	 * @phpstan-return array<string,bool>
	 *
	 * @url	POST customers/{socid}/ecos/{ecoid}/comments
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 409 Not a request of this customer, empty comment, or closed
	 */
	public function commentOnEco($socid, $ecoid, $text, $actor = '')
	{
		$socid = $this->checkCustomer($socid);
		$error = '';
		if (DesignTraceChangeRequests::comment($this->db, DolibarrApiAccess::$user, $socid, (int) $ecoid, (string) $text, $this->getActor($actor), $error) <= 0) {
			throw new RestException(409, $error);
		}
		return array('ok' => true);
	}

	/**
	 * Attach a reference file to a request while it is open. The content is sent base64 encoded
	 * (reference files are small; design files are never uploaded through the API). Executable
	 * files are neutralised (`.noexe`), at most 20 files per request.
	 *
	 * @param	int		$socid			Customer (third party) id
	 * @param	int		$ecoid			ECO id
	 * @param	string	$filename		File name {@from body}
	 * @param	string	$filecontent	Content, base64 encoded {@from body}
	 * @param	string	$actor			Who attaches it, for the log {@from body}
	 * @return	array					filename as stored
	 * @phan-return array<string,string>
	 * @phpstan-return array<string,string>
	 *
	 * @url	POST customers/{socid}/ecos/{ecoid}/files
	 *
	 * @throws RestException 400 Content is not base64
	 * @throws RestException 403 Not allowed
	 * @throws RestException 409 Not a request of this customer, closed, too large or too many files
	 */
	public function attachEcoFile($socid, $ecoid, $filename, $filecontent, $actor = '')
	{
		$socid = $this->checkCustomer($socid);
		$content = base64_decode((string) $filecontent, true);
		if ($content === false) {
			throw new RestException(400, 'filecontent must be base64 encoded');
		}
		$error = '';
		$stored = DesignTraceChangeRequests::addReferenceFile($this->db, $socid, (int) $ecoid, (string) $filename, $content, $this->getActor($actor), $error);
		if ($stored === '') {
			throw new RestException(409, $error);
		}
		return array('filename' => $stored);
	}

	/**
	 * Ask for access again to a design whose access ended: opens a ticket for the workshop (asking
	 * again while it is open returns the same ticket)
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	int		$designid	Design id
	 * @param	string	$message	Optional message {@from body}
	 * @param	string	$actor		Who asks, for the log {@from body}
	 * @param	string	$client_ip	Customer's IP address as seen by the calling backend, recorded on the ticket {@from body}
	 * @return	array				ticket_id, ticket_ref, existing
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	POST customers/{socid}/designs/{designid}/request-access
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 409 The customer never had access, or still has it
	 */
	public function requestAccess($socid, $designid, $message = '', $actor = '', $client_ip = '')
	{
		$socid = $this->checkCustomer($socid);
		$error = '';
		$res = DesignTraceChangeRequests::askAccess($this->db, DolibarrApiAccess::$user, $socid, (int) $designid, (string) $message, $this->getActor($actor), $error, (string) $client_ip);
		if (!is_array($res)) {
			throw new RestException(409, $error);
		}
		return $res;
	}

	// ---------------------------------------------------------------------------------------------
	// Staff endpoints. Called with the staff user's own Dolibarr API key: their rights apply, and a
	// confidential design they cannot see is "not found". External users are refused.
	// ---------------------------------------------------------------------------------------------

	/**
	 * What waits for the workshop: flagged revisions waiting for the customer's approval, open ECOs
	 * (customer requests first, with the next action), designs with active work whose licence is not
	 * accepted yet, and components flagged "Needs update".
	 *
	 * @return	array				approvals, ecos, licences, needs_update, counts
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	GET staff/triage
	 *
	 * @throws RestException 403 Needs the right to read designs
	 */
	public function getTriage()
	{
		$this->checkStaff('design', 'read');
		return DesignTraceStaff::fetchTriage($this->db, DolibarrApiAccess::$user);
	}

	/**
	 * The customer's library, as the customer sees it
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	string	$q			Search
	 * @param	int		$action		1 = only designs needing the customer's action
	 * @param	int		$page		Page, from 0
	 * @param	int		$limit		Page size (1-200)
	 * @return	array				total, page, limit, items (see GET customers/{socid}/library)
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	GET staff/customers/{socid}/library
	 *
	 * @throws RestException 403 Needs the right to read designs
	 * @throws RestException 404 Customer not found
	 */
	public function getStaffLibrary($socid, $q = '', $action = 0, $page = 0, $limit = 25)
	{
		$this->checkStaff('design', 'read');
		$socid = $this->checkStaffCustomer($socid);
		$hidden = Design::getHiddenDesignIds($this->db, DolibarrApiAccess::$user);
		$result = DesignTraceLibrary::fetchLibrary($this->db, $socid, array('q' => $q, 'action' => (int) $action, 'exclude' => $hidden), (int) $page, (int) $limit);
		return $result;
	}

	/**
	 * Everything a customer can see of a design (tree, revisions, files, history), plus its grants,
	 * the licence state and, for each file, whether the customer sees it and why not.
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	int		$designid	Design id
	 * @return	array				As GET customers/{socid}/designs/{designid} plus grants, files, licence
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	GET staff/customers/{socid}/designs/{designid}
	 *
	 * @throws RestException 403 Needs the right to read designs
	 * @throws RestException 404 Customer or design not found, or the customer has no access to it
	 */
	public function getStaffCustomerDesign($socid, $designid)
	{
		$this->checkStaff('design', 'read');
		$socid = $this->checkStaffCustomer($socid);
		$this->getStaffDesign($designid);
		$view = DesignTraceStaff::fetchCustomerView($this->db, $socid, (int) $designid);
		if (!$view) {
			throw new RestException(404, 'This customer has no access to the design');
		}
		return $view;
	}

	/**
	 * A design whoever it is shared with: every component with every revision (drafts and in review
	 * too) and every file of each, shaped like the tree of GET staff/customers/{socid}/designs/{designid}
	 * (external components are those of other designs the design's components link to). Whether a
	 * revision is delivered (`is_delivered`) is for the given customer, else the design's own.
	 *
	 * @param	int		$designid	Design id
	 * @param	int		$socid		Customer the delivered flags are for (default: the design's own)
	 * @return	array				id, ref, label, tree, roots
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	GET staff/designs/{designid}/overview
	 *
	 * @throws RestException 403 Needs the right to read designs
	 * @throws RestException 404 Design or customer not found
	 */
	public function getStaffDesignOverview($designid, $socid = 0)
	{
		$this->checkStaff('design', 'read');
		$design = $this->getStaffDesign($designid);
		if ((int) $socid > 0) {
			$socid = $this->checkStaffCustomer($socid);
		}
		return DesignTraceStaff::fetchDesignOverview($this->db, $design, (int) $socid);
	}

	/**
	 * What happened on a design for any customer: releases, approvals and change requests, licence
	 * acceptances, consent, downloads (staff previews left out) and grants, newest first.
	 *
	 * @param	int		$designid	Design id
	 * @param	int		$limit		Max entries (1-500)
	 * @return	array				date, type, design_id, design_ref, text, actor, soc_id
	 * @phan-return array<int,array<string,mixed>>
	 * @phpstan-return array<int,array<string,mixed>>
	 *
	 * @url	GET staff/designs/{designid}/history
	 *
	 * @throws RestException 403 Needs the right to read designs
	 * @throws RestException 404 Design not found
	 */
	public function getStaffDesignHistory($designid, $limit = 100)
	{
		$this->checkStaff('design', 'read');
		$this->getStaffDesign($designid);
		return DesignTraceStaff::fetchDesignHistory($this->db, (int) $designid, (int) $limit);
	}

	/**
	 * What is on the "Linked files" tab of a design in Dolibarr: the files attached to it and the links registered on it.
	 * Staff only: customers never see these.
	 *
	 * @param	int		$designid	Design id
	 * @return	array				design_id, files (name, size, date), links (id, url, label, date)
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	GET staff/designs/{designid}/documents
	 *
	 * @throws RestException 403 Needs the right to read designs
	 * @throws RestException 404 Design not found
	 */
	public function getStaffDesignDocuments($designid)
	{
		$this->checkStaff('design', 'read');
		$design = $this->getStaffDesign($designid);
		return DesignTraceStaff::fetchDesignDocuments($this->db, $design);
	}

	/**
	 * One file attached to a design (see staff/designs/{designid}/documents), streamed
	 *
	 * @param	int		$designid	Design id
	 * @param	string	$name		File name as listed
	 * @return	void				The raw file (the request ends)
	 *
	 * @url	GET staff/designs/{designid}/documents/content
	 *
	 * @throws RestException 400 Name missing
	 * @throws RestException 403 Needs the right to read designs
	 * @throws RestException 404 Design or file not found
	 */
	public function getStaffDesignDocumentContent($designid, $name = '')
	{
		$this->checkStaff('design', 'read');
		$design = $this->getStaffDesign($designid);
		if ((string) $name === '') {
			throw new RestException(400, 'name is required');
		}
		// Only a file the listing would show: no path, no traversal
		$dir = DesignTraceStaff::getDesignDocumentsDir($design);
		$found = ((string) $name === basename((string) $name) && substr((string) $name, -5) !== '.meta' ? (string) $name : '');
		if ($dir === '' || $found === '' || $found === '.' || $found === '..' || !is_file(dol_osencode($dir.'/'.$found))) {
			throw new RestException(404, 'File not found');
		}
		DesignTraceClientAccess::sendFile($dir.'/'.$found, $found, false, array('X-DesignTrace-Design' => (string) ((int) $design->id)));
	}

	/**
	 * Compare two revisions of a component, with every file (hidden, source and production too)
	 *
	 * @param	int		$componentid	Component id
	 * @param	int		$from			Older revision (default: the one before the newer one)
	 * @param	int		$to				Newer revision (default: the current one, else the latest)
	 * @param	int		$socid			Customer the delivered flags are for (default: the design's own customer)
	 * @return	array				component_id, from, to, files (as GET customers/{socid}/components/{componentid}/compare)
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	GET staff/components/{componentid}/compare
	 *
	 * @throws RestException 403 Needs the right to read designs
	 * @throws RestException 404 Component not found, or nothing to compare
	 */
	public function compareStaffRevisions($componentid, $from = 0, $to = 0, $socid = 0)
	{
		$this->checkStaff('design', 'read');
		$component = new Component($this->db);
		if ($component->fetch((int) $componentid) <= 0) {
			throw new RestException(404, 'Component not found');
		}
		$this->getStaffDesign((int) $component->fk_design);
		if ((int) $socid > 0) {
			$socid = $this->checkStaffCustomer($socid);
		}
		$result = DesignTraceStaff::compareComponentRevisions($this->db, (int) $componentid, (int) $from, (int) $to, (int) $socid);
		if (!$result) {
			throw new RestException(404, 'Nothing to compare');
		}
		return $result;
	}

	/**
	 * Any file of a design, whoever it is shared with, streamed as GET customers/{socid}/files/{fileid}/content
	 * (headers X-Content-SHA256, X-DesignTrace-File-Id, X-DesignTrace-Revision). Production and source files
	 * too: the staff user's right to read designs is the only gate. Logged on the staff-preview channel, which
	 * the customer's download log and history never show.
	 *
	 * @param	int		$fileid		File id
	 * @param	int		$inline		1 = Content-Disposition inline (3D formats only)
	 * @return	void				The raw file (never returns: the file is streamed and the request ends)
	 *
	 * @url	GET staff/files/{fileid}/content
	 *
	 * @throws RestException 400 Inline on a file that is not 3D
	 * @throws RestException 403 Needs the right to read designs
	 * @throws RestException 404 File or design not found, or the file is missing on disk
	 * @throws RestException 503 System error (download not logged, file not sent)
	 */
	public function getStaffFileContent($fileid, $inline = 0)
	{
		$this->checkStaff('design', 'read');
		$row = DesignTraceStaff::fetchFileRow($this->db, (int) $fileid);
		if (!$row) {
			throw new RestException(404, 'File not found');
		}
		$this->getStaffDesign((int) $row->design_id);
		if (!empty($inline) && !DesignTraceClientAccess::is3dFormat((string) $row->format)) {
			throw new RestException(400, 'Only 3D files (viewer_3d) can be sent inline');
		}
		$fullpath = DesignTraceClientAccess::getFilePath($this->db, $row);
		if ($fullpath === '' || !is_file(dol_osencode($fullpath))) {
			throw new RestException(404, 'File missing on disk');
		}
		if (DesignTraceClientAccess::logDownload($this->db, $row, (int) $row->fk_soc, DesignTraceClientAccess::CHANNEL_ADMIN_PREVIEW, 'api:'.DolibarrApiAccess::$user->login) < 0) {
			throw new RestException(503, 'Error logging the download');
		}
		DesignTraceClientAccess::sendFile($fullpath, $row->filename, !empty($inline), DesignTraceClientAccess::fileHeaders($row));
	}

	/**
	 * Grants of a customer (whole designs or single components), with their pins, newest first
	 *
	 * @param	int		$socid		Customer (third party) id
	 * @param	int		$design_id	Only this design
	 * @return	array				id, design_id, active, whole_design, date_expiry, date_revoked, note, pins (component id => revision id)
	 * @phan-return array<int,array<string,mixed>>
	 * @phpstan-return array<int,array<string,mixed>>
	 *
	 * @url	GET staff/customers/{socid}/grants
	 *
	 * @throws RestException 403 Needs the right to read designs
	 */
	public function listStaffGrants($socid, $design_id = 0)
	{
		$this->checkStaff('design', 'read');
		$socid = $this->checkStaffCustomer($socid);
		$rows = array();
		foreach (DesignTraceGrant::fetchForCustomer($this->db, $socid, (int) $design_id) as $grant) {
			if (!Design::userCanSee(DolibarrApiAccess::$user, $this->loadDesign((int) $grant->fk_design))) {
				continue;
			}
			$rows[] = array('id' => (int) $grant->rowid, 'design_id' => (int) $grant->fk_design, 'active' => (bool) $grant->active, 'whole_design' => ((int) $grant->whole_design === 1),
				'date_expiry' => $grant->date_expiry ?: null, 'date_revoked' => $grant->date_revoked ?: null, 'note' => (string) $grant->note, 'pins' => $grant->pins);
		}
		return $rows;
	}

	/**
	 * Give a customer access to a design or to single components, without a quote or order. Each
	 * component is pinned to a released revision (default: its current one); the pin never moves
	 * when a newer one is released. The revisions count as delivered at once.
	 *
	 * @param	int		$socid			Customer (third party) id
	 * @param	int		$design_id		Design id {@from body}
	 * @param	array	$component_ids	Components (empty = the whole design) {@from body}
	 * @param	array	$pins			Revision to pin per component, {"componentid": revisionid} {@from body}
	 * @param	string	$expiry			Expiry (date or timestamp), empty = never {@from body}
	 * @param	string	$note			Note {@from body}
	 * @return	array				id of the grant
	 * @phan-return array<string,int>
	 * @phpstan-return array<string,int>
	 *
	 * @url	POST staff/customers/{socid}/grants
	 *
	 * @throws RestException 403 Needs the right to change designs
	 * @throws RestException 409 Nothing released to grant, or a pin that is not a released revision of its component
	 */
	public function createStaffGrant($socid, $design_id, $component_ids = array(), $pins = array(), $expiry = '', $note = '')
	{
		$this->checkStaff('design', 'write');
		$socid = $this->checkStaffCustomer($socid);
		$this->getStaffDesign($design_id);
		$id = DesignTraceGrant::create($this->db, DolibarrApiAccess::$user, $socid, (int) $design_id, array_map('intval', (array) $component_ids), array_map('intval', (array) $pins), $this->parseDate($expiry), (string) $note);
		if ($id < 0) {
			throw new RestException(409, $id === -2 ? 'Nothing released to grant' : ($id === -3 ? 'A pin must be a released revision of its component' : ($id === -4 ? 'Only an active design can be granted to an existing customer' : 'Error creating the grant')));
		}
		return array('id' => $id);
	}

	/**
	 * Change the expiry of a grant (empty = never expires)
	 *
	 * @param	int		$grantid	Grant id
	 * @param	string	$expiry		New expiry (date or timestamp), empty = never {@from body}
	 * @return	array				ok
	 * @phan-return array<string,bool>
	 * @phpstan-return array<string,bool>
	 *
	 * @url	POST staff/grants/{grantid}/expiry
	 *
	 * @throws RestException 403 Needs the right to change designs
	 * @throws RestException 404 Grant not found
	 */
	public function setStaffGrantExpiry($grantid, $expiry = '')
	{
		$this->checkStaff('design', 'write');
		$this->getStaffGrant($grantid);
		DesignTraceGrant::setExpiry($this->db, (int) $grantid, $this->parseDate($expiry));
		return array('ok' => true);
	}

	/**
	 * Revoke a grant. The record, the download log and the licence acceptance stay.
	 *
	 * @param	int		$grantid	Grant id
	 * @return	array				revoked (false if it already was)
	 * @phan-return array<string,bool>
	 * @phpstan-return array<string,bool>
	 *
	 * @url	POST staff/grants/{grantid}/revoke
	 *
	 * @throws RestException 403 Needs the right to change designs
	 * @throws RestException 404 Grant not found
	 */
	public function revokeStaffGrant($grantid)
	{
		$this->checkStaff('design', 'write');
		$this->getStaffGrant($grantid);
		return array('revoked' => DesignTraceGrant::revoke($this->db, DolibarrApiAccess::$user, (int) $grantid) > 0);
	}

	/**
	 * Move the pin of a component of a grant to another released revision, by hand. It counts as delivered.
	 *
	 * @param	int		$grantid		Grant id
	 * @param	int		$componentid	Component id (pinned by the grant)
	 * @param	int		$revision_id	New revision {@from body}
	 * @return	array				ok
	 * @phan-return array<string,bool>
	 * @phpstan-return array<string,bool>
	 *
	 * @url	POST staff/grants/{grantid}/pins/{componentid}
	 *
	 * @throws RestException 403 Needs the right to change designs
	 * @throws RestException 404 Grant not found
	 * @throws RestException 409 Not a released revision of that component
	 */
	public function moveStaffGrantPin($grantid, $componentid, $revision_id)
	{
		$this->checkStaff('design', 'write');
		$this->getStaffGrant($grantid);
		if (DesignTraceGrant::movePin($this->db, (int) $grantid, (int) $componentid, (int) $revision_id) < 0) {
			throw new RestException(409, 'The grant must be active, cover the component, and the pin must be a released revision of it');
		}
		return array('ok' => true);
	}

	/**
	 * Change what customers may see of a file: hidden, now, or on_completion. Production files can
	 * only be hidden. Allowed on released revisions too.
	 *
	 * @param	int		$fileid			File id
	 * @param	string	$visibility		hidden, now or on_completion {@from body}
	 * @return	array				visibility
	 * @phan-return array<string,string>
	 * @phpstan-return array<string,string>
	 *
	 * @url	POST staff/files/{fileid}/visibility
	 *
	 * @throws RestException 400 Bad visibility
	 * @throws RestException 403 Needs the right to manage client visibility
	 * @throws RestException 404 File not found
	 * @throws RestException 409 A production file is never shown
	 */
	public function setStaffFileVisibility($fileid, $visibility)
	{
		$this->checkStaff('componentrevision', 'managevisibility');
		$values = array('hidden' => RevisionFile::VISIBILITY_HIDDEN, 'now' => RevisionFile::VISIBILITY_NOW, 'on_completion' => RevisionFile::VISIBILITY_ON_COMPLETION);
		$visibility = strtolower(trim((string) $visibility));
		if (!isset($values[$visibility])) {
			throw new RestException(400, 'Bad visibility, use hidden, now or on_completion');
		}
		$file = new RevisionFile($this->db);
		if ($file->fetch((int) $fileid) <= 0) {
			throw new RestException(404, 'File not found');
		}
		$this->getStaffRevision((int) $file->fk_revision);
		if ($values[$visibility] !== RevisionFile::VISIBILITY_HIDDEN && !DesignTraceClientAccess::isRoleShareable((int) $file->role)) {
			throw new RestException(409, 'A production file is never shown to customers');
		}
		$file->visibility = $values[$visibility];
		if ($file->update(DolibarrApiAccess::$user) <= 0) {
			throw new RestException(503, $file->error ?: 'Error saving the visibility');
		}
		return array('visibility' => $visibility);
	}

	/**
	 * Link a design to a quote or order of its customer (the design-level link)
	 *
	 * @param	int		$designid	Design id
	 * @param	string	$element	quote or order {@from body}
	 * @param	int		$docid		Quote or order id {@from body}
	 * @return	array				ok
	 * @phan-return array<string,bool>
	 * @phpstan-return array<string,bool>
	 *
	 * @url	POST staff/designs/{designid}/links
	 *
	 * @throws RestException 400 Bad document type
	 * @throws RestException 403 Needs the right to change designs
	 * @throws RestException 409 Document not found or of another customer
	 */
	public function linkStaffDesign($designid, $element, $docid)
	{
		$this->checkStaff('design', 'write');
		$design = $this->getStaffDesign($designid);
		$type = DesignTraceClientAccess::normaliseElement($element);
		if (!in_array($type, array('propal', 'commande'), true)) {
			throw new RestException(400, 'Bad document type, use quote or order');
		}
		$error = '';
		if (DesignTraceStaff::linkDesign($this->db, $design, $type, (int) $docid, $error) < 0) {
			throw new RestException(409, $error);
		}
		return array('ok' => true);
	}

	/**
	 * Link a revision to a quote, order, invoice, BOM, ticket or project (same rules as the revision card)
	 *
	 * @param	int		$revid		Revision id
	 * @param	string	$element	quote, order, invoice, bom, ticket or project {@from body}
	 * @param	int		$docid		Document id {@from body}
	 * @return	array				ok
	 * @phan-return array<string,bool>
	 * @phpstan-return array<string,bool>
	 *
	 * @url	POST staff/revisions/{revid}/links
	 *
	 * @throws RestException 400 Bad document type
	 * @throws RestException 403 Needs the right to change revisions
	 * @throws RestException 409 Refused by the linking rules
	 */
	public function linkStaffRevision($revid, $element, $docid)
	{
		$this->checkStaff('componentrevision', 'write');
		$revision = $this->getStaffRevision((int) $revid);
		$type = $this->normaliseLinkElement($element);
		if (DesignTraceDocLinks::link(DolibarrApiAccess::$user, $revision, $type, (int) $docid) < 0) {
			throw new RestException(409, $revision->error ?: 'Link refused');
		}
		return array('ok' => true);
	}

	/**
	 * Unlink a revision from a document. A delivered order keeps its revisions.
	 *
	 * @param	int		$revid		Revision id
	 * @param	string	$element	quote, order, invoice, bom, ticket or project
	 * @param	int		$docid		Document id
	 * @return	array				unlinked (false if there was no such link)
	 * @phan-return array<string,bool>
	 * @phpstan-return array<string,bool>
	 *
	 * @url	DELETE staff/revisions/{revid}/links/{element}/{docid}
	 *
	 * @throws RestException 400 Bad document type
	 * @throws RestException 403 Needs the right to change revisions
	 * @throws RestException 409 Refused (delivered order, unknown document)
	 */
	public function unlinkStaffRevision($revid, $element, $docid)
	{
		$this->checkStaff('componentrevision', 'write');
		$revision = $this->getStaffRevision((int) $revid);
		$error = '';
		$res = DesignTraceStaff::unlinkRevision($this->db, $revision, $this->normaliseLinkElement($element), (int) $docid, $error);
		if ($res < 0) {
			throw new RestException(409, $error ?: 'Unlink refused');
		}
		return array('unlinked' => $res > 0);
	}

	/**
	 * Switch a revision's link to a quote, order or ticket on or off for the customer
	 *
	 * @param	int		$revid		Revision id
	 * @param	string	$element	quote, order, ticket or project
	 * @param	int		$docid		Document id
	 * @param	int		$visible	1 = shown to the customer, 0 = hidden {@from body}
	 * @return	array				visible
	 * @phan-return array<string,bool>
	 * @phpstan-return array<string,bool>
	 *
	 * @url	POST staff/revisions/{revid}/links/{element}/{docid}/client-visible
	 *
	 * @throws RestException 400 Bad document type
	 * @throws RestException 403 Needs the right to manage client visibility
	 * @throws RestException 404 The revision is not linked to that document
	 */
	public function setStaffLinkVisible($revid, $element, $docid, $visible)
	{
		$this->checkStaff('componentrevision', 'managevisibility');
		$revision = $this->getStaffRevision((int) $revid);
		$type = DesignTraceClientAccess::normaliseElement($element);
		$found = false;
		foreach (DesignTraceClientAccess::fetchDocumentsOfRevision($this->db, $revision) as $row) {
			$found = $found || ($row->element === $type && (int) $row->doc_id === (int) $docid);
		}
		if (!$found) {
			throw new RestException(404, 'The revision is not linked to that document');
		}
		DesignTraceClientAccess::setLinkVisibility($this->db, DolibarrApiAccess::$user, (int) $revid, $type, (int) $docid, (int) $visible);
		return array('visible' => (bool) $visible);
	}

	/**
	 * Edit a revision that is not released yet: customer release note (`customer_note`) and
	 * `needs_approval` (0 or 1). Released revisions are read-only.
	 *
	 * @param	int		$revid			Revision id
	 * @param	array	$request_data	customer_note and/or needs_approval
	 * @phan-param ?array<string,mixed> $request_data
	 * @phpstan-param ?array<string,mixed> $request_data
	 * @return	array				id, customer_note, needs_approval
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	PUT staff/revisions/{revid}
	 *
	 * @throws RestException 403 Needs the right to change revisions
	 * @throws RestException 404 Revision not found
	 * @throws RestException 409 Released revisions are read-only
	 */
	public function updateStaffRevision($revid, $request_data = null)
	{
		$this->checkStaff('componentrevision', 'write');
		$revision = $this->getStaffRevision((int) $revid);
		$request_data = is_array($request_data) ? $request_data : array();
		if (array_key_exists('customer_note', $request_data)) {
			$revision->customer_note = (string) $request_data['customer_note'];
		}
		if (array_key_exists('needs_approval', $request_data)) {
			$revision->needs_approval = empty($request_data['needs_approval']) ? 0 : 1;
		}
		if ($revision->update(DolibarrApiAccess::$user) <= 0) {
			throw new RestException(409, $revision->error ?: 'Not saved');
		}
		return array('id' => (int) $revision->id, 'customer_note' => (string) $revision->customer_note, 'needs_approval' => (int) $revision->needs_approval);
	}

	/**
	 * Release a revision (needs the right to release). Refused while it waits for the customer's
	 * approval, for a child that is not released, and under the module's other release rules.
	 *
	 * @param	int		$revid		Revision id
	 * @return	array				id, status
	 * @phan-return array<string,int>
	 * @phpstan-return array<string,int>
	 *
	 * @url	POST staff/revisions/{revid}/release
	 *
	 * @throws RestException 403 Needs the right to release revisions
	 * @throws RestException 404 Revision not found
	 * @throws RestException 409 Refused, with the reason
	 */
	public function releaseStaffRevision($revid)
	{
		$this->checkStaff('componentrevision', 'release');
		$revision = $this->getStaffRevision((int) $revid);
		$res = $revision->release(DolibarrApiAccess::$user);
		if ($res <= 0) {
			throw new RestException(409, $revision->error ?: 'Nothing to release');
		}
		return array('id' => (int) $revision->id, 'status' => (int) $revision->status);
	}

	/**
	 * Record the customer's decision on a revision for them: an answer received by phone, email or
	 * in person. Same effects as the customer's own decision (logged with channel `staff` and the
	 * staff user as actor): an approval unblocks release, changes requested decline the revision and
	 * reopen or open its ECO. Only for a revision flagged for approval, In review and not approved
	 * yet, of a design that has a customer. Needs the right to release revisions.
	 *
	 * @param	int		$revid			Revision id
	 * @param	array	$request_data	`decision` (`approve` or `changes`), `received` (required: how and from whom, e.g. "phone, Ann"),
	 *									`comment` (required for `changes`), `whole_scope` (approval only: cover every flagged revision
	 *									waiting in the same ECO, or in the same design when there is no ECO, as the customer's own
	 *									approval does; default only this revision)
	 * @phan-param ?array<string,mixed> $request_data
	 * @phpstan-param ?array<string,mixed> $request_data
	 * @return	array				decision, revision_ids; for `changes` also eco_id and round
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	POST staff/revisions/{revid}/customer-decision
	 *
	 * @throws RestException 400 Unknown decision, or `received` missing
	 * @throws RestException 403 Needs the right to release revisions
	 * @throws RestException 404 Revision not found
	 * @throws RestException 409 Nothing waiting, no customer on the design, or `comment` missing for `changes`
	 */
	public function recordStaffCustomerDecision($revid, $request_data = null)
	{
		$this->checkStaff('componentrevision', 'release');
		$revision = $this->getStaffRevision((int) $revid);
		$request_data = is_array($request_data) ? $request_data : array();
		$names = array('approve' => DesignTraceProofApproval::DECISION_APPROVED, 'changes' => DesignTraceProofApproval::DECISION_CHANGES);
		$name = (string) ($request_data['decision'] ?? '');
		if (!isset($names[$name])) {
			throw new RestException(400, 'decision must be "approve" or "changes"');
		}
		$received = trim((string) ($request_data['received'] ?? ''));
		if ($received === '') {
			throw new RestException(400, 'received is required: say how and from whom the decision was received');
		}
		$error = '';
		$res = DesignTraceApprovals::recordOnBehalf($this->db, DolibarrApiAccess::$user, (int) $revision->id, $names[$name], $received, (string) ($request_data['comment'] ?? ''), $error, !empty($request_data['whole_scope']));
		if ($res === -1 || (!is_array($res))) {
			throw new RestException(409, $error ?: 'Not recorded');
		}
		if ($name === 'approve') {
			return array('decision' => 'approved', 'revision_ids' => array_values(array_map('intval', $res)));
		}
		return array('decision' => 'changes', 'eco_id' => (int) $res['eco'], 'round' => (int) $res['round'], 'revision_ids' => array_values(array_map('intval', $res['revisions'])));
	}

	/**
	 * Decline what a declined revision links to that is still In review: the cascade a decline runs,
	 * run again (for a revision declined before it existed, or whose children went back in review).
	 * With `dry_run` it only says what would be declined. Needs the right to release revisions.
	 *
	 * @param	int		$revid			Revision id (must be Declined)
	 * @param	array	$request_data	`dry_run` (optional)
	 * @phan-param ?array<string,mixed> $request_data
	 * @phpstan-param ?array<string,mixed> $request_data
	 * @return	array				dry_run, declined: [{ id, ref }]
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	POST staff/revisions/{revid}/decline-linked
	 *
	 * @throws RestException 403 Needs the right to release revisions
	 * @throws RestException 404 Revision not found
	 * @throws RestException 409 The revision is not Declined
	 */
	public function declineStaffLinkedRevisions($revid, $request_data = null)
	{
		$this->checkStaff('componentrevision', 'release');
		$revision = $this->getStaffRevision((int) $revid);
		if ((int) $revision->status !== ComponentRevision::STATUS_DECLINED) {
			throw new RestException(409, 'Only a declined revision cascades');
		}
		$dryRun = is_array($request_data) && !empty($request_data['dry_run']);
		$this->db->begin();
		$done = ComponentRevision::declineWithChildren($this->db, DolibarrApiAccess::$user, (int) $revision->id, 0, $dryRun);
		if ($done === -1) {
			dol_syslog(__METHOD__.' revision '.((int) $revision->id).': '.$this->db->lasterror(), LOG_ERR);
			$this->db->rollback();
			throw new RestException(500, 'Not declined: a database error was logged');
		}
		$this->db->commit();
		$declined = array();
		foreach ($done as $id => $ref) {
			$declined[] = array('id' => (int) $id, 'ref' => $ref);
		}
		return array('dry_run' => $dryRun, 'declined' => $declined);
	}

	/**
	 * Approve an ECO (needs the right to approve change orders). Refused while its linked quote is not accepted.
	 *
	 * @param	int		$ecoid		ECO id
	 * @return	array				status
	 * @phan-return array<string,int>
	 * @phpstan-return array<string,int>
	 *
	 * @url	POST staff/ecos/{ecoid}/approve
	 *
	 * @throws RestException 403 Needs the right to approve change orders
	 * @throws RestException 404 ECO not found
	 * @throws RestException 409 Refused, with the reason
	 */
	public function approveStaffEco($ecoid)
	{
		$this->checkStaff('changeorder', 'approve');
		return $this->staffEcoAction($ecoid, 'approve');
	}

	/**
	 * Mark an approved ECO implemented
	 *
	 * @param	int		$ecoid		ECO id
	 * @return	array				status
	 * @phan-return array<string,int>
	 * @phpstan-return array<string,int>
	 *
	 * @url	POST staff/ecos/{ecoid}/implement
	 *
	 * @throws RestException 403 Needs the right to change change orders
	 * @throws RestException 404 ECO not found
	 * @throws RestException 409 Not approved
	 */
	public function implementStaffEco($ecoid)
	{
		$this->checkStaff('changeorder', 'write');
		return $this->staffEcoAction($ecoid, 'setImplemented');
	}

	/**
	 * Close an implemented ECO
	 *
	 * @param	int		$ecoid		ECO id
	 * @return	array				status
	 * @phan-return array<string,int>
	 * @phpstan-return array<string,int>
	 *
	 * @url	POST staff/ecos/{ecoid}/close
	 *
	 * @throws RestException 403 Needs the right to change change orders
	 * @throws RestException 404 ECO not found
	 * @throws RestException 409 Not implemented
	 */
	public function closeStaffEco($ecoid)
	{
		$this->checkStaff('changeorder', 'write');
		return $this->staffEcoAction($ecoid, 'close');
	}

	/**
	 * Share an ECO with its customer (summary and request text only, never internal notes), or stop.
	 * ECOs the customer raised are always shared.
	 *
	 * @param	int		$ecoid		ECO id
	 * @param	int		$shareable	1 = shared, 0 = not {@from body}
	 * @return	array				shareable
	 * @phan-return array<string,bool>
	 * @phpstan-return array<string,bool>
	 *
	 * @url	POST staff/ecos/{ecoid}/shareable
	 *
	 * @throws RestException 403 Needs the right to change change orders
	 * @throws RestException 404 ECO not found
	 * @throws RestException 409 A request raised by the customer stays shared
	 */
	public function setStaffEcoShareable($ecoid, $shareable)
	{
		$this->checkStaff('changeorder', 'write');
		$eco = $this->getStaffEco($ecoid);
		$info = $eco->getRequestInfo();
		if (!$shareable && (int) $info->request_type > 0) {
			throw new RestException(409, 'A request raised by the customer stays shared');
		}
		$eco->setShareable((bool) $shareable);
		return array('shareable' => (bool) $shareable);
	}

	/**
	 * Link a quote of the ECO's customer (0 removes the link). The ECO then waits on it: it cannot be
	 * approved before the quote is accepted.
	 *
	 * @param	int		$ecoid		ECO id
	 * @param	int		$quote_id	Quote id, 0 = none {@from body}
	 * @return	array				quote_id, settled
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	POST staff/ecos/{ecoid}/quote
	 *
	 * @throws RestException 403 Needs the right to change change orders
	 * @throws RestException 404 ECO not found
	 * @throws RestException 409 Quote of another customer
	 */
	public function linkStaffEcoQuote($ecoid, $quote_id)
	{
		$this->checkStaff('changeorder', 'write');
		$eco = $this->getStaffEco($ecoid);
		if ($eco->linkQuote((int) $quote_id) < 0) {
			throw new RestException(409, $eco->error);
		}
		return array('quote_id' => (int) $quote_id, 'settled' => $eco->isQuoteSettled());
	}

	/**
	 * Force re-acceptance of the licence of a design, for one customer or for all of them. The
	 * acceptance records, their PDFs and the download log stay; the next download needs a new acceptance.
	 *
	 * @param	int		$design_id	Design id {@from body}
	 * @param	int		$socid		Customer id, 0 = every customer {@from body}
	 * @return	array				ended (number of acceptances ended)
	 * @phan-return array<string,int>
	 * @phpstan-return array<string,int>
	 *
	 * @url	POST staff/licences/force-reacceptance
	 *
	 * @throws RestException 403 Needs the right to change licences
	 * @throws RestException 404 Design not found
	 */
	public function forceStaffReacceptance($design_id, $socid = 0)
	{
		$this->checkStaff('licence', 'write');
		$this->getStaffDesign($design_id);
		$n = DesignTraceLicensing::forceReacceptance($this->db, (int) $design_id, (int) $socid);
		if ($n < 0) {
			throw new RestException(503, 'Error ending the acceptances');
		}
		return array('ended' => $n);
	}

	/**
	 * Metrics: customer approval turnaround (review start to first approval), ECO cycle time (raised to
	 * closed), designs delivered and revisions released per month, CAD hours per design. Defaults to
	 * the last 12 months. Turnaround and cycle time only count items recorded since the module began
	 * stamping them.
	 *
	 * @param	string	$from	Start (date or timestamp)
	 * @param	string	$to		End (date or timestamp)
	 * @return	array			approval_turnaround, eco_cycle_time, designs_delivered_per_month, revisions_released_per_month, cad_hours_per_design
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url	GET staff/metrics
	 *
	 * @throws RestException 403 Needs the right to read designs and revisions
	 */
	public function getStaffMetrics($from = '', $to = '')
	{
		$this->checkStaff('design', 'read');
		$this->checkStaff('componentrevision', 'read');
		$to = $this->parseDate($to) ?: dol_now();
		$from = $this->parseDate($from) ?: dol_time_plus_duree($to, -12, 'm');
		return DesignTraceStaff::fetchMetrics($this->db, DolibarrApiAccess::$user, (int) $from, (int) $to);
	}

	/**
	 * The module is on, the caller is staff (not an external user) and has the right
	 *
	 * @param	string	$object		Right object, e.g. design
	 * @param	string	$perm		Permission, e.g. read
	 * @return	void
	 */
	private function checkStaff($object, $perm)
	{
		$user = DolibarrApiAccess::$user;
		if (!isModEnabled('designtrace') || !empty($user->socid) || !$user->hasRight('designtrace', $object, $perm)) {
			throw new RestException(403, 'Needs the DesignTrace right "'.$object.' '.$perm.'" (staff users only)');
		}
	}

	/**
	 * @param	int		$socid	Customer id
	 * @return	int
	 */
	private function checkStaffCustomer($socid)
	{
		$socid = (int) $socid;
		$resql = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."societe WHERE rowid = ".$socid." AND entity IN (".getEntity('societe').")");
		if ($socid <= 0 || !$resql || !$this->db->num_rows($resql)) {
			throw new RestException(404, 'Customer not found');
		}
		return $socid;
	}

	/**
	 * @param	int		$designid	Design id
	 * @return	Design|null
	 */
	private function loadDesign($designid)
	{
		$design = new Design($this->db);
		return ($design->fetch((int) $designid) > 0) ? $design : null;
	}

	/**
	 * A design the staff user may see ("not found" for confidential ones they cannot)
	 *
	 * @param	int		$designid	Design id
	 * @return	Design
	 */
	private function getStaffDesign($designid)
	{
		$design = $this->loadDesign($designid);
		if (!$design || !Design::userCanSee(DolibarrApiAccess::$user, $design)) {
			throw new RestException(404, 'Design not found');
		}
		return $design;
	}

	/**
	 * @param	int		$revid	Revision id
	 * @return	ComponentRevision
	 */
	private function getStaffRevision($revid)
	{
		$revision = new ComponentRevision($this->db);
		if ($revision->fetch((int) $revid) <= 0) {
			throw new RestException(404, 'Revision not found');
		}
		$component = new Component($this->db);
		if ($component->fetch((int) $revision->fk_component) <= 0) {
			throw new RestException(404, 'Revision not found');
		}
		$this->getStaffDesign((int) $component->fk_design);
		return $revision;
	}

	/**
	 * @param	int		$grantid	Grant id
	 * @return	stdClass
	 */
	private function getStaffGrant($grantid)
	{
		$grant = DesignTraceGrant::fetchOne($this->db, (int) $grantid);
		if (!$grant) {
			throw new RestException(404, 'Grant not found');
		}
		$this->getStaffDesign((int) $grant->fk_design);
		return $grant;
	}

	/**
	 * @param	int		$ecoid	ECO id
	 * @return	ChangeOrder
	 */
	private function getStaffEco($ecoid)
	{
		$eco = new ChangeOrder($this->db);
		if ($eco->fetch((int) $ecoid) <= 0 || DesignTraceStaff::isEcoHidden($this->db, $eco, Design::getHiddenDesignIds($this->db, DolibarrApiAccess::$user))) {
			throw new RestException(404, 'Change order not found');
		}
		return $eco;
	}

	/**
	 * Run a workflow step of an ECO
	 *
	 * @param	int		$ecoid	ECO id
	 * @param	string	$method	approve, setImplemented or close
	 * @return	array{status:int}
	 */
	private function staffEcoAction($ecoid, $method)
	{
		$eco = $this->getStaffEco($ecoid);
		$res = $eco->$method(DolibarrApiAccess::$user);
		if ($res <= 0) {
			throw new RestException(409, $eco->error ?: 'Not possible in the ECO\'s current status');
		}
		return array('status' => (int) $eco->status);
	}

	/**
	 * Document type of a revision link in the API (quote, order, invoice, bom, ticket, project)
	 *
	 * @param	string	$element	As sent
	 * @return	string				Key of DesignTraceDocLinks::getRules()
	 */
	private function normaliseLinkElement($element)
	{
		$map = array('quote' => 'propal', 'propal' => 'propal', 'order' => 'commande', 'commande' => 'commande', 'invoice' => 'facture', 'facture' => 'facture', 'bom' => 'bom', 'ticket' => 'ticket', 'project' => 'project');
		$type = $map[strtolower(trim((string) $element))] ?? '';
		if ($type === '') {
			throw new RestException(400, 'Bad document type, use quote, order, invoice, bom, ticket or project');
		}
		return $type;
	}

	/**
	 * Date or timestamp from a caller
	 *
	 * @param	mixed	$value	Timestamp, date string, or empty
	 * @return	int|null		Timestamp, null if empty
	 */
	private function parseDate($value)
	{
		$value = trim((string) $value);
		if ($value === '' || strtolower($value) === 'never') {
			return null;
		}
		$ts = (ctype_digit($value) ? (int) $value : strtotime($value));
		if (!$ts) {
			throw new RestException(400, 'Bad date: '.$value);
		}
		return $ts;
	}

	/**
	 * Check the customer has an active way to reach a design
	 *
	 * @param	int		$socid		Customer id
	 * @param	int		$designid	Design id
	 * @return	void
	 */
	private function checkDesignAccess($socid, $designid)
	{
		$access = DesignTraceLibrary::fetchAccess($this->db, $socid);
		if (!isset($access[(int) $designid])) {
			throw new RestException(404, 'Design not found for this customer');
		}
		if (!empty($access[(int) $designid]['ended'])) {
			throw new RestException(403, 'Access to this design has ended');
		}
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
				'is_proof' => DesignTraceProofApproval::isProof((int) $row->comp_type, (int) $row->drawing_type),
				'document_type' => $this->publicElement((string) $row->element),
				'document_id' => (int) $row->doc_id,
				'document_ref' => $row->doc_ref,
			);
		}
		return $result;
	}
}
