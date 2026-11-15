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
 * \file        designtrace/class/changerequests.class.php
 * \ingroup     designtrace
 * \brief       Customer change requests: report a problem or ask for a change on a revision
 *
 * One flow, raised from a revision the customer holds, of type Problem (defect) or Change. It creates
 * a ticket (types Design problem / Design change) and a linked draft ECO (reason customer), shared
 * with that customer automatically. A problem may name one of their orders that delivered the
 * revision. The customer can attach reference files, comment through the ticket and withdraw while
 * the ECO is a draft: that cancels both ECO and ticket with a "withdrawn" message, both kept in
 * history. Customers see the summary and request text, never internal notes.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
dol_include_once('/designtrace/class/changeorder.class.php');
dol_include_once('/designtrace/class/componentrevision.class.php');
dol_include_once('/designtrace/class/designlibrary.class.php');
dol_include_once('/designtrace/class/proofapproval.class.php');
dol_include_once('/designtrace/class/revisionfile.class.php');

/**
 * Customer change requests
 */
class DesignTraceChangeRequests
{
	const MAX_FILES = 20;

	/**
	 * Customer-facing name of an ECO status
	 *
	 * @param	ChangeOrder		$eco	ECO
	 * @param	stdClass[]		$timeline	Its timeline
	 * @return	string			open, approved, implemented, closed, withdrawn or cancelled
	 */
	public static function getStatusCode($eco, $timeline = array())
	{
		switch ((int) $eco->status) {
			case ChangeOrder::STATUS_APPROVED:
				return 'approved';
			case ChangeOrder::STATUS_IMPLEMENTED:
				return 'implemented';
			case ChangeOrder::STATUS_CLOSED:
				return 'closed';
			case ChangeOrder::STATUS_CANCELED:
				foreach ($timeline as $entry) {
					if ($entry->type === 'withdrawn') {
						return 'withdrawn';
					}
				}
				return 'cancelled';
		}
		return 'open';
	}

	/**
	 * Report a problem or ask for a change on a revision the customer holds
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	User	$user		User acting for the customer (service user): author of the ticket and ECO
	 * @param	int		$socid		Customer id
	 * @param	string	$type		problem or change
	 * @param	int		$revid		Revision the request is about
	 * @param	string	$summary	Short summary
	 * @param	string	$text		What is wrong or what to change
	 * @param	int		$orderid	Order that delivered the revision (problems only, 0 = none)
	 * @param	string	$actor		Who asks
	 * @param	string	$error		Error message if KO
	 * @param	?string	$ip			Customer's IP as seen by a proxying backend (stored on the ticket)
	 * @return	array{eco_id:int,eco_ref:string,ticket_id:int,ticket_ref:string}|int	-1 if KO
	 */
	public static function report($db, $user, $socid, $type, $revid, $summary, $text, $orderid, $actor, &$error, $ip = null)
	{
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';
		$langs->loadLangs(array('designtrace@designtrace', 'errors'));
		$types = array('problem' => array(ChangeOrder::REQUEST_PROBLEM, 'DESIGN_PROBLEM', 'ChangeRequestProblem'), 'change' => array(ChangeOrder::REQUEST_CHANGE, 'DESIGN_CHANGE', 'ChangeRequestChange'));
		$type = strtolower(trim((string) $type));
		$summary = trim((string) $summary);
		$text = trim((string) $text);
		if (!isset($types[$type])) {
			$error = $langs->trans('ErrorRequestBadType');
			return -1;
		}
		if ($summary === '' || $text === '') {
			$error = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv($summary === '' ? 'EcoSummary' : 'Description'));
			return -1;
		}
		$revision = new ComponentRevision($db);
		if ((int) $revid <= 0 || $revision->fetch((int) $revid) <= 0 || !self::holdsRevision($db, $socid, $revision)) {
			$error = $langs->trans('ErrorRequestRevisionNotHeld');
			return -1;
		}
		$component = new Component($db);
		$component->fetch((int) $revision->fk_component);
		$orderid = (int) $orderid;
		if ($orderid > 0) {
			if ($type !== 'problem') {
				$error = $langs->trans('ErrorRequestOrderOnlyForProblems');
				return -1;
			}
			$sql = "SELECT o.rowid FROM ".$db->prefix()."commande as o INNER JOIN ".$db->prefix()."element_element as ee ON ee.fk_source = o.rowid AND ee.sourcetype = 'commande'";
			$sql .= " AND ee.targettype = 'designtrace_componentrevision' AND ee.fk_target = ".((int) $revision->id);
			$sql .= " WHERE o.rowid = ".$orderid." AND o.fk_soc = ".((int) $socid)." AND o.fk_statut = 3";
			$resql = $db->query($sql);
			if (!$resql || !$db->num_rows($resql)) {
				$error = $langs->trans('ErrorRequestOrderNotDelivered');
				return -1;
			}
		}

		$db->begin();
		$ticket = new Ticket($db);
		$ticket->ref = $ticket->getDefaultRef();
		$ticket->fk_soc = (int) $socid;
		$ticket->subject = dol_trunc($langs->transnoentities('ChangeRequestSubject', $langs->transnoentitiesnoconv($types[$type][2]), $summary), 250, 'right', 'UTF-8', 1);
		$ticket->message = $langs->transnoentities('ChangeRequestMessage', $langs->transnoentitiesnoconv($types[$type][2]), $actor, $revision->ref)."\n\n".$text;
		$ticket->type_code = $types[$type][1];
		$ticket->category_code = DesignTraceProofApproval::getDictionaryDefault($db, 'c_ticket_category');
		$ticket->severity_code = DesignTraceProofApproval::getDictionaryDefault($db, 'c_ticket_severity');
		$ticket->fk_statut = Ticket::STATUS_NOT_READ;
		$ticket->ip = DesignTraceClientAccess::resolveClientIp($ip);
		if ($ticket->create($user) <= 0) {
			$error = $ticket->error ?: implode(', ', $ticket->errors);
			$db->rollback();
			return -1;
		}
		if (!$revision->add_object_linked('ticket', $ticket->id) || ($orderid > 0 && !$ticket->add_object_linked('commande', $orderid))) {
			$error = $db->lasterror();
			$db->rollback();
			return -1;
		}

		$eco = new ChangeOrder($db);
		$eco->label = dol_trunc($summary, 250, 'right', 'UTF-8', 1);
		$eco->reason = ChangeOrder::REASON_CUSTOMER;
		$eco->fk_soc = (int) $socid;
		$eco->fk_project = (int) $component->fk_project;
		$eco->description = $text;
		$eco->linked_objects = array('ticket' => $ticket->id);		// Links the ticket and adds the component of the revision
		if ($eco->create($user) <= 0 || $eco->markCustomerRequest($types[$type][0], $actor, (int) $revision->id, (int) $ticket->id, $orderid) < 0) {
			$error = $eco->error ?: implode(', ', $eco->errors);
			$db->rollback();
			return -1;
		}
		$db->commit();
		return array('eco_id' => (int) $eco->id, 'eco_ref' => $eco->ref, 'ticket_id' => (int) $ticket->id, 'ticket_ref' => $ticket->ref);
	}

	/**
	 * Whether the customer holds this revision (the one they were given, linked or pinned) in a design they still reach
	 *
	 * @param	DoliDB				$db			Database handler
	 * @param	int					$socid		Customer id
	 * @param	ComponentRevision	$revision	Revision
	 * @return	bool
	 */
	private static function holdsRevision($db, $socid, $revision)
	{
		$access = DesignTraceLibrary::fetchAccess($db, $socid);
		foreach (DesignTraceLibrary::fetchComponentStates($db, $socid, $access) as $compid => $state) {
			if ((int) $compid === (int) $revision->fk_component) {
				return in_array((int) $revision->id, $state['held'], true) && empty($access[$state['design_id']]['ended']);
			}
		}
		return false;
	}

	/**
	 * ECO of this customer that is shared with them
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$socid	Customer id
	 * @param	int		$ecoid	ECO id
	 * @return	ChangeOrder|null
	 */
	public static function fetchShared($db, $socid, $ecoid)
	{
		$eco = new ChangeOrder($db);
		if ((int) $ecoid <= 0 || $eco->fetch((int) $ecoid) <= 0 || (int) $eco->fk_soc !== (int) $socid) {
			return null;
		}
		$info = $eco->getRequestInfo();
		return ($info && (int) $info->shareable === 1) ? $eco : null;
	}

	/**
	 * ECOs shared with the customer: the ones they raised, and any other with its "shareable" toggle on
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$socid	Customer id
	 * @return	array<int,array<string,mixed>>	Newest first: id, ref, summary, status, type, requested_by, round, date_creation, ticket_id, ticket_ref
	 */
	public static function fetchList($db, $socid)
	{
		global $conf;

		$rows = array();
		$sql = "SELECT rowid FROM ".$db->prefix()."designtrace_changeorder WHERE fk_soc = ".((int) $socid)." AND shareable = 1 AND entity = ".((int) $conf->entity)." ORDER BY rowid DESC";
		$resql = $db->query($sql);
		$ids = array();
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$ids[] = (int) $obj->rowid;
		}
		foreach ($ids as $id) {
			$eco = self::fetchShared($db, $socid, $id);
			if ($eco) {
				$rows[] = self::formatSummary($db, $eco);
			}
		}
		return $rows;
	}

	/**
	 * @param	DoliDB		$db		Database handler
	 * @param	ChangeOrder	$eco	ECO
	 * @return	array<string,mixed>
	 */
	private static function formatSummary($db, $eco)
	{
		$info = $eco->getRequestInfo();
		$timeline = $eco->fetchTimeline();
		$names = array(ChangeOrder::REQUEST_NONE => '', ChangeOrder::REQUEST_PROBLEM => 'problem', ChangeOrder::REQUEST_CHANGE => 'change');
		$ticket = self::getTicket($db, (int) $info->fk_ticket);
		return array(
			'id' => (int) $eco->id,
			'ref' => $eco->ref,
			'summary' => (string) $eco->label,
			'status' => self::getStatusCode($eco, $timeline),
			'type' => $names[(int) $info->request_type] ?? '',
			'requested_by' => (string) $info->requested_by,
			'round' => (int) $info->round,
			'date_creation' => (int) $db->jdate($eco->date_creation),
			'ticket_id' => $ticket ? $ticket['id'] : 0,
			'ticket_ref' => $ticket ? $ticket['ref'] : '',
		);
	}

	/**
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$ticketid	Ticket id
	 * @return	array{id:int,ref:string,status:int,closed:bool}|null
	 */
	private static function getTicket($db, $ticketid)
	{
		if ($ticketid <= 0) {
			return null;
		}
		$resql = $db->query("SELECT rowid, ref, fk_statut FROM ".$db->prefix()."ticket WHERE rowid = ".((int) $ticketid));
		$obj = $resql ? $db->fetch_object($resql) : null;
		return $obj ? array('id' => (int) $obj->rowid, 'ref' => $obj->ref, 'status' => (int) $obj->fk_statut, 'closed' => ((int) $obj->fk_statut >= 8)) : null;
	}

	/**
	 * Detail of a shared ECO: summary and request text (never internal notes), timeline with rounds,
	 * affected components and resulting revisions (only components the customer reaches), ticket,
	 * linked quote, reference files and ticket comments
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$socid	Customer id
	 * @param	int		$ecoid	ECO id
	 * @return	array<string,mixed>|null
	 */
	public static function fetchDetail($db, $socid, $ecoid)
	{
		$eco = self::fetchShared($db, $socid, $ecoid);
		if (!$eco) {
			return null;
		}
		$info = $eco->getRequestInfo();
		$detail = self::formatSummary($db, $eco);
		$detail['request_text'] = (string) $eco->description;

		$timeline = array();
		foreach ($eco->fetchTimeline() as $entry) {
			$timeline[] = array('round' => (int) $entry->round, 'type' => $entry->type, 'actor' => (string) $entry->actor, 'comment' => (string) $entry->comment, 'date' => (int) $entry->date_event);
		}
		$detail['timeline'] = $timeline;

		$access = DesignTraceLibrary::fetchAccess($db, $socid);
		$states = DesignTraceLibrary::fetchComponentStates($db, $socid, $access);
		$reached = array();
		foreach ($states as $compid => $state) {
			if (empty($access[$state['design_id']]['ended'])) {
				$reached[(int) $compid] = true;
			}
		}
		$detail['components'] = array();
		foreach ($eco->fetchAffectedComponents() as $row) {
			if (isset($reached[(int) $row->fk_component])) {
				$detail['components'][] = array('id' => (int) $row->fk_component, 'ref' => $row->comp_ref, 'label' => (string) $row->comp_label, 'design_id' => (int) $row->design_id, 'design_ref' => $row->design_ref);
			}
		}
		$detail['resulting_revisions'] = array();
		foreach ($eco->fetchRevisions(array(ComponentRevision::STATUS_RELEASED, ComponentRevision::STATUS_OBSOLETE)) as $rev) {
			if (isset($reached[(int) $rev->fk_component])) {
				$detail['resulting_revisions'][] = array('id' => (int) $rev->rowid, 'ref' => $rev->ref, 'rev' => $rev->rev, 'component_id' => (int) $rev->fk_component, 'date_release' => (int) $db->jdate($rev->date_release));
			}
		}

		$ticket = self::getTicket($db, (int) $info->fk_ticket);
		$detail['ticket'] = $ticket ? array('id' => $ticket['id'], 'ref' => $ticket['ref'], 'closed' => $ticket['closed']) : null;
		$detail['order_id'] = ((int) $info->fk_order_source > 0 ? (int) $info->fk_order_source : null);
		$detail['quote'] = null;
		if ((int) $info->fk_propal > 0) {
			$resql = $db->query("SELECT rowid, ref, fk_statut FROM ".$db->prefix()."propal WHERE rowid = ".((int) $info->fk_propal)." AND fk_soc = ".((int) $socid)." AND fk_statut > 0");
			$q = $resql ? $db->fetch_object($resql) : null;
			if ($q) {
				$detail['quote'] = array('id' => (int) $q->rowid, 'ref' => $q->ref, 'accepted' => in_array((int) $q->fk_statut, array(2, 4), true));
			}
		}
		$detail['waiting_for_quote'] = !$eco->isQuoteSettled();
		$detail['reference_files'] = self::listReferenceFiles($eco);
		$detail['comments'] = ($ticket ? self::listComments($db, $ticket['id']) : array());
		$detail['can_withdraw'] = ((int) $info->request_type > 0 && (int) $eco->status === ChangeOrder::STATUS_DRAFT);
		$detail['can_attach'] = ((int) $info->request_type > 0 && $eco->isOpen());
		return $detail;
	}

	/**
	 * Folder of the reference files of an ECO (its Documents tab)
	 *
	 * @param	ChangeOrder	$eco	ECO
	 * @return	string
	 */
	private static function getDir($eco)
	{
		global $conf;

		$entity = (empty($eco->entity) ? $conf->entity : $eco->entity);
		return $conf->designtrace->multidir_output[$entity].'/'.$eco->element.'/'.dol_sanitizeFileName($eco->ref);
	}

	/**
	 * Reference files the customer attached
	 *
	 * @param	ChangeOrder	$eco	ECO
	 * @return	array<int,array{filename:string,size:int,date:int}>
	 */
	private static function listReferenceFiles($eco)
	{
		$files = array();
		foreach (dol_dir_list(self::getDir($eco), 'files', 0, '^customer-', '', 'name', SORT_ASC, 0) as $file) {
			$files[] = array('filename' => $file['name'], 'size' => (int) $file['size'], 'date' => (int) $file['date']);
		}
		return $files;
	}

	/**
	 * Public messages of the ticket (the customer's comments and the workshop's answers; never private notes)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$ticketid	Ticket id
	 * @return	array<int,array{date:int,text:string}>
	 */
	private static function listComments($db, $ticketid)
	{
		$rows = array();
		$resql = $db->query("SELECT datec, note FROM ".$db->prefix()."actioncomm WHERE elementtype = 'ticket' AND fk_element = ".((int) $ticketid)." AND code = 'TICKET_MSG' ORDER BY datec ASC, id ASC");
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$rows[] = array('date' => (int) $db->jdate($obj->datec), 'text' => (string) $obj->note);
		}
		return $rows;
	}

	/**
	 * Withdraw a request while its ECO is a draft: cancels the ECO and the ticket with a "withdrawn"
	 * message; both stay in history
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	User	$user		User acting for the customer
	 * @param	int		$socid		Customer id
	 * @param	int		$ecoid		ECO id
	 * @param	string	$actor		Who withdraws
	 * @param	string	$comment	Optional reason
	 * @param	string	$error		Error message if KO
	 * @return	int					>0 if OK, <0 if KO
	 */
	public static function withdraw($db, $user, $socid, $ecoid, $actor, $comment, &$error)
	{
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';
		$langs->loadLangs(array('designtrace@designtrace', 'errors'));
		$eco = self::fetchShared($db, $socid, $ecoid);
		$info = $eco ? $eco->getRequestInfo() : null;
		if (!$eco || (int) $info->request_type <= 0) {
			$error = $langs->trans('ErrorRecordNotFound');
			return -1;
		}
		if ((int) $eco->status !== ChangeOrder::STATUS_DRAFT) {
			$error = $langs->trans('ErrorRequestCannotWithdraw');
			return -1;
		}
		$db->begin();
		if ($eco->cancel($user) <= 0 || $eco->addTimelineEntry('withdrawn', $actor, trim((string) $comment)) < 0) {
			$error = $eco->error ?: $langs->trans('Error');
			$db->rollback();
			return -1;
		}
		$ticket = new Ticket($db);
		if ((int) $info->fk_ticket > 0 && $ticket->fetch((int) $info->fk_ticket) > 0 && (int) $ticket->status < Ticket::STATUS_CLOSED) {
			$ticket->message = $langs->transnoentities('ChangeRequestWithdrawnTicket', $actor, $eco->ref).(trim((string) $comment) !== '' ? "\n\n".trim((string) $comment) : '');
			$ticket->private = 0;
			if ($ticket->createTicketMessage($user) < 0 || $ticket->close($user, 1) < 0) {
				$error = $ticket->error ?: $langs->trans('Error');
				$db->rollback();
				return -1;
			}
		}
		$db->commit();
		return 1;
	}

	/**
	 * Comment on a request, through its ticket
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	User	$user		User acting for the customer
	 * @param	int		$socid		Customer id
	 * @param	int		$ecoid		ECO id
	 * @param	string	$text		Comment
	 * @param	string	$actor		Who comments
	 * @param	string	$error		Error message if KO
	 * @return	int					>0 if OK, <0 if KO
	 */
	public static function comment($db, $user, $socid, $ecoid, $text, $actor, &$error)
	{
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';
		$langs->loadLangs(array('designtrace@designtrace', 'errors'));
		$eco = self::fetchShared($db, $socid, $ecoid);
		$info = $eco ? $eco->getRequestInfo() : null;
		$text = trim((string) $text);
		$ticket = new Ticket($db);
		if (!$eco || (int) $info->request_type <= 0 || (int) $info->fk_ticket <= 0 || $ticket->fetch((int) $info->fk_ticket) <= 0) {
			$error = $langs->trans('ErrorRecordNotFound');
			return -1;
		}
		if ($text === '' || (int) $ticket->status >= Ticket::STATUS_CLOSED) {
			$error = $langs->trans($text === '' ? 'ErrorProofCommentRequired' : 'ErrorRequestClosed');
			return -1;
		}
		$ticket->message = $langs->transnoentities('ChangeRequestComment', $actor)."\n\n".$text;
		$ticket->private = 0;
		if ($ticket->createTicketMessage($user) < 0) {
			$error = $ticket->error ?: $langs->trans('Error');
			return -1;
		}
		return 1;
	}

	/**
	 * Attach a reference file to a request (saved in the ECO's Documents tab as customer-<name>)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer id
	 * @param	int		$ecoid		ECO id
	 * @param	string	$filename	File name as sent
	 * @param	string	$content	File content (binary)
	 * @param	string	$actor		Who attaches it
	 * @param	string	$error		Error message if KO
	 * @return	string				Stored file name, '' if KO
	 */
	public static function addReferenceFile($db, $socid, $ecoid, $filename, $content, $actor, &$error)
	{
		global $langs;

		$langs->loadLangs(array('designtrace@designtrace', 'errors'));
		$eco = self::fetchShared($db, $socid, $ecoid);
		$info = $eco ? $eco->getRequestInfo() : null;
		if (!$eco || (int) $info->request_type <= 0) {
			$error = $langs->trans('ErrorRecordNotFound');
			return '';
		}
		if (!$eco->isOpen()) {
			$error = $langs->trans('ErrorRequestClosed');
			return '';
		}
		$name = dol_sanitizeFileName(basename(str_replace('\\', '/', (string) $filename)));
		if ($name === '' || $name[0] === '.' || (string) $content === '') {
			$error = $langs->trans('ErrorFileNotUploaded');
			return '';
		}
		$max = RevisionFile::getMaxUploadBytes();
		if ($max > 0 && strlen($content) > $max) {
			$error = $langs->trans('ErrorFileSizeTooLarge');
			return '';
		}
		$dir = self::getDir($eco);
		if (count(self::listReferenceFiles($eco)) >= self::MAX_FILES) {
			$error = $langs->trans('ErrorRequestTooManyFiles', self::MAX_FILES);
			return '';
		}
		if (dol_mkdir($dir) < 0) {
			$error = $langs->trans('ErrorCanNotCreateDir', $dir);
			return '';
		}
		$stored = 'customer-'.$name;
		$tmp = tempnam(sys_get_temp_dir(), 'dtref');
		file_put_contents($tmp, $content);
		$res = dol_move_uploaded_file($tmp, $dir.'/'.$stored, 1, 0, 0, 0, 'userfile', $dir, 1);
		@unlink($tmp);
		if (is_numeric($res) && (int) $res == 2) {
			$stored .= '.noexe';
		} elseif (!is_numeric($res) || (int) $res <= 0) {
			$error = $langs->trans(is_numeric($res) ? 'ErrorFileNotUploaded' : (string) preg_replace('/:.*$/', '', (string) $res));
			return '';
		}
		// Copy on the ticket's Documents tab too, so support sees it where the conversation is
		if (!empty($info->fk_ticket) && isModEnabled('ticket')) {
			require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';
			$ticket = new Ticket($db);
			if ($ticket->fetch((int) $info->fk_ticket) > 0) {
				global $conf;
				$ticketdir = $conf->ticket->dir_output.'/'.dol_sanitizeFileName($ticket->ref);
				if (dol_mkdir($ticketdir) >= 0) {
					dol_copy($dir.'/'.$stored, $ticketdir.'/'.$stored, '0', 1);
				}
			}
		}
		$eco->addTimelineEntry('reference_file', $actor, $stored);
		return $stored;
	}

	/**
	 * Ask for access again to a design whose access ended: opens a ticket (the same one while it is open)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	User	$user		User acting for the customer
	 * @param	int		$socid		Customer id
	 * @param	int		$designid	Design id
	 * @param	string	$message	Optional message
	 * @param	string	$actor		Who asks
	 * @param	string	$error		Error message if KO
	 * @param	?string	$ip			Customer's IP as seen by a proxying backend
	 * @return	array{ticket_id:int,ticket_ref:string,existing:bool}|int	-1 if KO (also when access has not ended)
	 */
	public static function askAccess($db, $user, $socid, $designid, $message, $actor, &$error, $ip = null)
	{
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';
		$langs->loadLangs(array('designtrace@designtrace', 'errors'));
		$access = DesignTraceLibrary::fetchAccess($db, $socid);
		if (!isset($access[(int) $designid])) {
			$error = $langs->trans('ErrorRecordNotFound');
			return -1;
		}
		if (empty($access[(int) $designid]['ended'])) {
			$error = $langs->trans('ErrorRequestAccessNotEnded');
			return -1;
		}
		$sql = "SELECT t.rowid, t.ref FROM ".$db->prefix()."ticket as t INNER JOIN ".$db->prefix()."element_element as ee ON ee.fk_target = t.rowid AND ee.targettype = 'ticket' AND ee.sourcetype = 'designtrace_design' AND ee.fk_source = ".((int) $designid);
		$sql .= " WHERE t.fk_soc = ".((int) $socid)." AND t.fk_statut < ".((int) Ticket::STATUS_CLOSED)." ORDER BY t.rowid DESC".$db->plimit(1);
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		if ($obj) {
			return array('ticket_id' => (int) $obj->rowid, 'ticket_ref' => $obj->ref, 'existing' => true);
		}
		$resql = $db->query("SELECT ref FROM ".$db->prefix()."designtrace_design WHERE rowid = ".((int) $designid));
		$design = $resql ? $db->fetch_object($resql) : null;
		$db->begin();
		$ticket = new Ticket($db);
		$ticket->ref = $ticket->getDefaultRef();
		$ticket->fk_soc = (int) $socid;
		$ticket->subject = $langs->transnoentities('AccessRequestSubject', $design->ref);
		$ticket->message = $langs->transnoentities('AccessRequestMessage', $actor, $design->ref).(trim((string) $message) !== '' ? "\n\n".trim((string) $message) : '');
		$ticket->type_code = DesignTraceProofApproval::getDictionaryDefault($db, 'c_ticket_type');
		$ticket->category_code = DesignTraceProofApproval::getDictionaryDefault($db, 'c_ticket_category');
		$ticket->severity_code = DesignTraceProofApproval::getDictionaryDefault($db, 'c_ticket_severity');
		$ticket->fk_statut = Ticket::STATUS_NOT_READ;
		$ticket->ip = DesignTraceClientAccess::resolveClientIp($ip);
		if ($ticket->create($user) <= 0 || !$ticket->add_object_linked('designtrace_design', (int) $designid)) {
			$error = $ticket->error ?: $db->lasterror();
			$db->rollback();
			return -1;
		}
		$db->commit();
		return array('ticket_id' => (int) $ticket->id, 'ticket_ref' => $ticket->ref, 'existing' => false);
	}
}
