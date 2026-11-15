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
	 * X-Content-SHA256, X-DesignTrace-File-Id and X-DesignTrace-Revision. Every download is logged
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
	 * @return	void				The raw file (never returns: the file is streamed and the request ends)
	 *
	 * @url	GET customers/{socid}/files/{fileid}/content
	 *
	 * @throws RestException 400 Bad document type, or inline on a file that is not 3D
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 File not visible to this customer, or missing on disk
	 * @throws RestException 503 System error (download not logged, file not sent)
	 */
	public function getFileContent($socid, $fileid, $element = '', $docid = 0, $actor = '', $client_ip = '', $user_agent = '', $inline = 0)
	{
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
		if (DesignTraceClientAccess::logDownload($this->db, $row, $socid, DesignTraceClientAccess::CHANNEL_API_DIRECT, $actor, (trim((string) $client_ip) !== '' ? (string) $client_ip : null), ((string) $user_agent !== '' ? (string) $user_agent : null)) < 0) {
			throw new RestException(503, 'Error logging the download');
		}
		// Restler would encode a return value: send the file ourselves and end the request
		DesignTraceClientAccess::sendFile($fullpath, $row->filename, !empty($inline), array(
			'X-Content-SHA256' => (string) $row->sha256,
			'X-DesignTrace-File-Id' => (string) ((int) $row->file_id),
			'X-DesignTrace-Revision' => (string) $row->rev_ref,
		));
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
	 * @throws RestException 403 Not allowed or access ended
	 * @throws RestException 404 Design not found for this customer
	 * @throws RestException 409 Confidential designs are not asked
	 */
	public function setDesignConsent($socid, $designid, $consent, $actor = '', $client_ip = '')
	{
		$socid = $this->checkCustomer($socid);
		$this->checkDesignAccess($socid, $designid);
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
