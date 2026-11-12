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
 * \file        designtrace/class/proofapproval.class.php
 * \ingroup     designtrace
 * \brief       Customer approval of proof drawings, from the Web portal or the API
 *
 * A customer can decide on a revision of a proof drawing (drawing type "proof") when it is in
 * review or released and at least one of its files is visible to them through a quote, order or
 * ticket (DesignTraceClientAccess rules). They approve it, or request changes with a comment.
 * Approval stamps the revision "Customer approved" with who and when and is final for that
 * revision; a change request can be followed by an approval later. A change request opens a
 * ticket or a draft ECO, as set up (DESIGNTRACE_PROOF_CHANGES_ACTION). Every decision is logged
 * (llx_designtrace_approval) and recorded as an event on the revision.
 */

dol_include_once('/designtrace/class/clientaccess.class.php');
dol_include_once('/designtrace/class/component.class.php');
dol_include_once('/designtrace/class/componentrevision.class.php');

/**
 * Customer approval of proofs
 */
class DesignTraceProofApproval
{
	const DECISION_NONE = 0;
	const DECISION_APPROVED = 1;
	const DECISION_CHANGES = 2;

	const ACTION_NONE = 'none';
	const ACTION_TICKET = 'ticket';
	const ACTION_ECO = 'eco';

	/**
	 * Labels of the decisions
	 *
	 * @return	array<int,string>
	 */
	public static function getDecisionLabels()
	{
		global $langs;

		$langs->load('designtrace@designtrace');
		return array(
			self::DECISION_NONE => $langs->trans('CustomerApprovalNone'),
			self::DECISION_APPROVED => $langs->trans('CustomerApprovalApproved'),
			self::DECISION_CHANGES => $langs->trans('CustomerApprovalChanges'),
		);
	}

	/**
	 * Badge of a decision
	 *
	 * @param	int		$decision	self::DECISION_*
	 * @return	string				HTML
	 */
	public static function getDecisionBadge($decision)
	{
		$labels = self::getDecisionLabels();
		$types = array(self::DECISION_NONE => 'status0', self::DECISION_APPROVED => 'status4', self::DECISION_CHANGES => 'status1');
		$decision = isset($labels[(int) $decision]) ? (int) $decision : self::DECISION_NONE;
		return dolGetStatus($labels[$decision], $labels[$decision], '', $types[$decision], 1);
	}

	/**
	 * What a change request opens, from the setup (default: a ticket when tickets are enabled)
	 *
	 * @return	string	self::ACTION_*
	 */
	public static function getChangesAction()
	{
		$action = getDolGlobalString('DESIGNTRACE_PROOF_CHANGES_ACTION', self::ACTION_TICKET);
		if ($action === self::ACTION_TICKET && !isModEnabled('ticket')) {
			return self::ACTION_NONE;
		}
		return in_array($action, array(self::ACTION_NONE, self::ACTION_TICKET, self::ACTION_ECO), true) ? $action : self::ACTION_NONE;
	}

	/**
	 * Whether a component is a proof drawing
	 *
	 * @param	int		$type			Component type
	 * @param	int		$drawingtype	Drawing type
	 * @return	bool
	 */
	public static function isProof($type, $drawingtype)
	{
		return ((int) $type === Component::TYPE_DRAWING && (int) $drawingtype === Component::DRAWING_PROOF);
	}

	/**
	 * Proof revisions a customer can see through a document, with their decision
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer id
	 * @param	string	$element	Document type ('' = all)
	 * @param	int		$docid		Document id (0 = all)
	 * @return	array<string,array<string,mixed>>	By "element_docid_revid": element, doc_id, doc_ref, rev_id, rev_ref, comp_ref,
	 *												comp_label, rev_status, decision, date_decision, decided_by, can_decide
	 */
	public static function fetchProofs($db, $socid, $element = '', $docid = 0)
	{
		$proofs = array();
		$rows = DesignTraceClientAccess::fetchVisibleFiles($db, $socid, $element, $docid);
		if (!is_array($rows)) {
			return $proofs;
		}
		$revisions = array();
		foreach ($rows as $row) {
			if (!self::isProof((int) $row->comp_type, (int) $row->drawing_type)) {
				continue;
			}
			$key = $row->element.'_'.$row->doc_id.'_'.$row->rev_id;
			if (isset($proofs[$key])) {
				continue;
			}
			if (!isset($revisions[(int) $row->rev_id])) {
				$sql = "SELECT customer_approval, date_customer_approval, customer_approval_by FROM ".$db->prefix()."designtrace_componentrevision WHERE rowid = ".((int) $row->rev_id);
				$resql = $db->query($sql);
				$revisions[(int) $row->rev_id] = $resql ? $db->fetch_object($resql) : null;
			}
			$rev = $revisions[(int) $row->rev_id];
			$decision = $rev ? (int) $rev->customer_approval : self::DECISION_NONE;
			$proofs[$key] = array(
				'element' => $row->element,
				'doc_id' => (int) $row->doc_id,
				'doc_ref' => $row->doc_ref,
				'rev_id' => (int) $row->rev_id,
				'rev_ref' => $row->rev_ref,
				'comp_ref' => $row->comp_ref,
				'comp_label' => (string) $row->comp_label,
				'rev_status' => (int) $row->rev_status,
				'decision' => $decision,
				'date_decision' => ($rev ? $db->jdate($rev->date_customer_approval) : null),
				'decided_by' => ($rev ? (string) $rev->customer_approval_by : ''),
				'can_decide' => self::statusAllowsDecision((int) $row->rev_status) && $decision !== self::DECISION_APPROVED,
			);
		}
		return $proofs;
	}

	/**
	 * Whether a revision in this status can be decided on
	 *
	 * @param	int		$status		ComponentRevision::STATUS_*
	 * @return	bool
	 */
	public static function statusAllowsDecision($status)
	{
		return in_array((int) $status, array(ComponentRevision::STATUS_INREVIEW, ComponentRevision::STATUS_RELEASED), true);
	}

	/**
	 * Record the customer's decision on a proof
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	User	$user		User acting for the customer (portal user, API user): owner of the ticket or ECO
	 * @param	int		$socid		Customer id
	 * @param	int		$revid		Revision id
	 * @param	string	$element	Document the proof was shown through
	 * @param	int		$docid		Document id
	 * @param	int		$decision	self::DECISION_APPROVED or self::DECISION_CHANGES
	 * @param	string	$comment	Customer's comment (required for a change request)
	 * @param	string	$channel	DesignTraceClientAccess::CHANNEL_*
	 * @param	string	$actor		Who decided
	 * @param	string	$error		Error message if KO
	 * @return	array{id:int,ticket:int,eco:int}|int	Ids created, or -1 if KO
	 */
	public static function decide($db, $user, $socid, $revid, $element, $docid, $decision, $comment, $channel, $actor, &$error)
	{
		global $conf, $langs;

		$langs->load('designtrace@designtrace');
		$error = '';
		$decision = (int) $decision;
		$comment = trim((string) $comment);
		if (!in_array($decision, array(self::DECISION_APPROVED, self::DECISION_CHANGES), true)) {
			$error = $langs->trans('ErrorProofBadDecision');
			return -1;
		}
		if ($decision === self::DECISION_CHANGES && $comment === '') {
			$error = $langs->trans('ErrorProofCommentRequired');
			return -1;
		}
		$proofs = self::fetchProofs($db, $socid, $element, $docid);
		$proof = $proofs[$element.'_'.((int) $docid).'_'.((int) $revid)] ?? null;
		if (!$proof) {
			$error = $langs->trans('ErrorProofNotAvailable');
			return -1;
		}
		if (!$proof['can_decide']) {
			$error = $langs->trans($proof['decision'] === self::DECISION_APPROVED ? 'ErrorProofAlreadyApproved' : 'ErrorProofNotAvailable');
			return -1;
		}
		$revision = new ComponentRevision($db);
		if ($revision->fetch((int) $revid) <= 0) {
			$error = $langs->trans('ErrorProofNotAvailable');
			return -1;
		}
		$component = new Component($db);
		$component->fetch((int) $revision->fk_component);
		$now = dol_now();

		$db->begin();
		$result = array('id' => 0, 'ticket' => 0, 'eco' => 0);
		if ($decision === self::DECISION_CHANGES) {
			$action = self::getChangesAction();
			if ($action === self::ACTION_TICKET) {
				$result['ticket'] = self::openTicket($db, $user, $socid, $revision, $proof, $comment, $actor, $error);
				if ($result['ticket'] < 0) {
					$db->rollback();
					return -1;
				}
			} elseif ($action === self::ACTION_ECO) {
				$result['eco'] = self::openEco($db, $user, $socid, $revision, $component, $proof, $comment, $actor, $error);
				if ($result['eco'] < 0) {
					$db->rollback();
					return -1;
				}
			}
		}

		$sql = "INSERT INTO ".$db->prefix()."designtrace_approval";
		$sql .= " (entity, fk_revision, decision, comment, element, fk_element, fk_soc, channel, actor, ip, useragent, fk_ticket, fk_eco, date_decision)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $revid).", ".$decision.", ".($comment !== '' ? "'".$db->escape($comment)."'" : "NULL");
		$sql .= ", '".$db->escape($element)."', ".((int) $docid).", ".((int) $socid).", '".$db->escape($channel)."'";
		$sql .= ", '".$db->escape(dol_trunc((string) $actor, 128, 'right', 'UTF-8', 1))."', '".$db->escape(getUserRemoteIP())."'";
		$sql .= ", '".$db->escape(dol_trunc((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 255, 'right', 'UTF-8', 1))."'";
		$sql .= ", ".($result['ticket'] > 0 ? (int) $result['ticket'] : "NULL").", ".($result['eco'] > 0 ? (int) $result['eco'] : "NULL");
		$sql .= ", '".$db->idate($now)."')";
		$ok = $db->query($sql);
		if ($ok) {
			$result['id'] = (int) $db->last_insert_id($db->prefix().'designtrace_approval');
			// Released revisions are read-only to staff, but the customer's stamp is not design data
			$sql = "UPDATE ".$db->prefix()."designtrace_componentrevision SET customer_approval = ".$decision;
			$sql .= ", date_customer_approval = '".$db->idate($now)."', customer_approval_by = '".$db->escape(dol_trunc((string) $actor, 128, 'right', 'UTF-8', 1))."'";
			$sql .= " WHERE rowid = ".((int) $revid);
			$ok = $db->query($sql);
		}
		if ($ok) {
			$ok = (self::addEvent($db, $user, $revision, $proof, $decision, $comment, $actor, $result) > 0);
		}
		if (!$ok) {
			$error = $db->lasterror() ?: $langs->trans('Error');
			$db->rollback();
			return -1;
		}
		$db->commit();
		return $result;
	}

	/**
	 * History of a revision's decisions, newest first
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$revid	Revision id
	 * @return	stdClass[]
	 */
	public static function fetchHistory($db, $revid)
	{
		$rows = array();
		$sql = "SELECT rowid, decision, comment, element, fk_element, fk_soc, channel, actor, ip, fk_ticket, fk_eco, date_decision";
		$sql .= " FROM ".$db->prefix()."designtrace_approval WHERE fk_revision = ".((int) $revid);
		$sql .= " ORDER BY date_decision DESC, rowid DESC";
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$obj->date_decision = $db->jdate($obj->date_decision);
			$rows[] = $obj;
		}
		return $rows;
	}

	/**
	 * Ticket for a change request, linked to the revision (shown in its quality history)
	 *
	 * @param	DoliDB				$db			Database handler
	 * @param	User				$user		Author
	 * @param	int					$socid		Customer
	 * @param	ComponentRevision	$revision	Proof revision
	 * @param	array<string,mixed>	$proof		Entry of fetchProofs()
	 * @param	string				$comment	Customer's comment
	 * @param	string				$actor		Who asked
	 * @param	string				$error		Error message if KO
	 * @return	int								Ticket id, <0 if KO
	 */
	private static function openTicket($db, $user, $socid, $revision, $proof, $comment, $actor, &$error)
	{
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';
		$ticket = new Ticket($db);
		$ticket->ref = $ticket->getDefaultRef();
		$ticket->fk_soc = (int) $socid;
		$ticket->subject = $langs->transnoentities('ProofChangesTicketSubject', $revision->ref);
		$ticket->message = $langs->transnoentities('ProofChangesTicketMessage', $revision->ref, $proof['doc_ref'], $actor)."\n\n".$comment;
		$ticket->type_code = self::getDictionaryDefault($db, 'c_ticket_type');
		$ticket->category_code = self::getDictionaryDefault($db, 'c_ticket_category');
		$ticket->severity_code = self::getDictionaryDefault($db, 'c_ticket_severity');
		$ticket->fk_statut = Ticket::STATUS_NOT_READ;
		$ticket->ip = getUserRemoteIP();
		if ($ticket->create($user) <= 0) {
			$error = $ticket->error ?: implode(', ', $ticket->errors);
			return -1;
		}
		if (!$revision->add_object_linked('ticket', $ticket->id)) {
			$error = $db->lasterror();
			return -1;
		}
		return (int) $ticket->id;
	}

	/**
	 * Draft ECO for a change request, with the component affected
	 *
	 * @param	DoliDB				$db			Database handler
	 * @param	User				$user		Requester
	 * @param	int					$socid		Customer
	 * @param	ComponentRevision	$revision	Proof revision
	 * @param	Component			$component	Its component
	 * @param	array<string,mixed>	$proof		Entry of fetchProofs()
	 * @param	string				$comment	Customer's comment
	 * @param	string				$actor		Who asked
	 * @param	string				$error		Error message if KO
	 * @return	int								ECO id, <0 if KO
	 */
	private static function openEco($db, $user, $socid, $revision, $component, $proof, $comment, $actor, &$error)
	{
		global $langs;

		dol_include_once('/designtrace/class/changeorder.class.php');
		$eco = new ChangeOrder($db);
		$eco->label = dol_trunc($langs->transnoentities('ProofChangesTicketSubject', $revision->ref), 250, 'right', 'UTF-8', 1);
		$eco->reason = ChangeOrder::REASON_CUSTOMER;
		$eco->fk_soc = (int) $socid;
		$eco->fk_project = (int) $component->fk_project;
		$eco->description = $langs->transnoentities('ProofChangesTicketMessage', $revision->ref, $proof['doc_ref'], $actor)."\n\n".$comment;
		if ($eco->create($user) <= 0) {
			$error = $eco->error ?: implode(', ', $eco->errors);
			return -1;
		}
		if ($eco->addAffectedComponent($user, (int) $component->id, $langs->transnoentities('ProofChangesEcoNote', $revision->ref), 1) < 0) {
			$error = $eco->error ?: implode(', ', $eco->errors);
			return -1;
		}
		return (int) $eco->id;
	}

	/**
	 * Event on the revision for a decision
	 *
	 * @param	DoliDB				$db			Database handler
	 * @param	User				$user		Author
	 * @param	ComponentRevision	$revision	Revision
	 * @param	array<string,mixed>	$proof		Entry of fetchProofs()
	 * @param	int					$decision	Decision
	 * @param	string				$comment	Comment
	 * @param	string				$actor		Who
	 * @param	array{id:int,ticket:int,eco:int}	$result	Ticket or ECO opened
	 * @return	int								>0 if OK
	 */
	private static function addEvent($db, $user, $revision, $proof, $decision, $comment, $actor, $result)
	{
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		$now = dol_now();
		$event = new ActionComm($db);
		$event->type_code = 'AC_OTH_AUTO';
		$event->code = ($decision === self::DECISION_APPROVED ? 'AC_DESIGNTRACE_PROOF_APPROVED' : 'AC_DESIGNTRACE_PROOF_CHANGES');
		$event->label = $langs->transnoentities($decision === self::DECISION_APPROVED ? 'EventProofApproved' : 'EventProofChanges', $revision->ref, $actor);
		$note = $langs->transnoentities('EventProofNote', $proof['doc_ref'], $actor);
		if ($comment !== '') {
			$note .= "\n".$comment;
		}
		$event->note_private = $note;
		$event->datep = $now;
		$event->datef = $now;
		$event->percentage = -1;
		$event->userownerid = $user->id;
		$event->authorid = $user->id;
		$event->socid = (int) $proof['doc_id'] > 0 ? (int) self::getDocumentSoc($db, $proof) : 0;
		$event->elementtype = $revision->element.'@'.$revision->module;
		$event->fk_element = $revision->id;
		return $event->create($user);
	}

	/**
	 * Third party of the document of a proof entry
	 *
	 * @param	DoliDB				$db		Database handler
	 * @param	array<string,mixed>	$proof	Entry of fetchProofs()
	 * @return	int
	 */
	private static function getDocumentSoc($db, $proof)
	{
		$channels = DesignTraceClientAccess::getChannels();
		if (!isset($channels[$proof['element']])) {
			return 0;
		}
		$resql = $db->query("SELECT fk_soc FROM ".$db->prefix().$channels[$proof['element']]['table']." WHERE rowid = ".((int) $proof['doc_id']));
		$obj = $resql ? $db->fetch_object($resql) : null;
		return $obj ? (int) $obj->fk_soc : 0;
	}

	/**
	 * Default code of a ticket dictionary (the one marked default, else the first active one)
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	string	$table	c_ticket_type, c_ticket_category or c_ticket_severity
	 * @return	string
	 */
	private static function getDictionaryDefault($db, $table)
	{
		$sql = "SELECT code FROM ".$db->prefix().$table." WHERE active = 1 AND entity IN (".getEntity($table).")";
		$sql .= " ORDER BY use_default DESC, pos ASC, rowid ASC".$db->plimit(1);
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		return $obj ? (string) $obj->code : '';
	}
}
