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
 * \file        designtrace/class/approvals.class.php
 * \ingroup     designtrace
 * \brief       Customer approval of revisions flagged "needs customer approval", per ECO or per design
 *
 * A revision can be flagged (proof drawings are by default). While it is flagged it cannot be
 * released until the customer approves. One approval covers all flagged revisions waiting in the
 * same scope: the ECO they belong to, or the design when they have no ECO. "Request changes" names
 * the revisions and reopens the same ECO as a new round (another timeline entry), or opens a draft
 * ECO when there is none.
 *
 * Customers with several users: the setting "who may accept licences and approve" (thirdparty extra
 * field designtrace_approvers, one switch for both) decides; the caller passes the acting user's
 * role and the module refuses with a distinct error.
 */

dol_include_once('/designtrace/class/componentrevision.class.php');
dol_include_once('/designtrace/class/changeorder.class.php');
dol_include_once('/designtrace/class/clientaccess.class.php');
dol_include_once('/designtrace/class/designlibrary.class.php');
dol_include_once('/designtrace/class/proofapproval.class.php');

/**
 * Approvals by scope and the customer's approver policy
 */
class DesignTraceApprovals
{
	const SCOPE_ECO = 'eco';
	const SCOPE_DESIGN = 'design';

	const POLICY_ANY = 0;
	const POLICY_ADMINS = 1;

	const ROLE_ADMIN = 'admin';
	const ROLE_USER = 'user';

	/**
	 * Who may accept licences and approve revisions for a customer
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$socid	Customer id
	 * @return	int				self::POLICY_ANY or self::POLICY_ADMINS
	 */
	public static function getPolicy($db, $socid)
	{
		$resql = $db->query("SELECT designtrace_approvers FROM ".$db->prefix()."societe_extrafields WHERE fk_object = ".((int) $socid));
		$obj = $resql ? $db->fetch_object($resql) : null;
		return ($obj && (int) $obj->designtrace_approvers === self::POLICY_ADMINS) ? self::POLICY_ADMINS : self::POLICY_ANY;
	}

	/**
	 * Whether the acting user may accept licences and approve revisions for this customer
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$socid	Customer id
	 * @param	string	$role	Acting user's role as the caller sees it: admin or user ('' = unknown, refused when admins only)
	 * @param	string	$error	Error message, set when refused
	 * @return	bool
	 */
	public static function checkRole($db, $socid, $role, &$error = '')
	{
		global $langs;

		$error = '';
		if (self::getPolicy($db, $socid) === self::POLICY_ADMINS && strtolower(trim((string) $role)) !== self::ROLE_ADMIN) {
			$langs->load('designtrace@designtrace');
			$error = $langs->trans('ErrorApproverRole');
			return false;
		}
		return true;
	}

	/**
	 * Revisions waiting for the customer in a scope: flagged, In review, in a design the customer
	 * reaches, not approved yet
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$socid	Customer id
	 * @param	string	$scope	self::SCOPE_*
	 * @param	int		$id		ECO id or design id
	 * @return	array<int,array<string,mixed>>	By revision id: id, ref, rev, component_id, component_ref, label, design_id, eco_id, customer_approval, release_note
	 */
	public static function fetchScopeRevisions($db, $socid, $scope, $id)
	{
		$designs = array();
		foreach (DesignTraceLibrary::fetchAccess($db, $socid) as $designid => $info) {
			if (empty($info['ended'])) {
				$designs[] = (int) $designid;
			}
		}
		if (empty($designs)) {
			return array();
		}
		return self::queryScopeRevisions($db, $designs, $scope, $id);
	}

	/**
	 * Flagged, In review, not approved revisions of a scope, in the given designs (null = any design: staff)
	 *
	 * @param	DoliDB		$db			Database handler
	 * @param	?int[]		$designs	Design ids to look in, null for any
	 * @param	string		$scope		self::SCOPE_*
	 * @param	int			$id			ECO id or design id
	 * @return	array<int,array<string,mixed>>	As fetchScopeRevisions()
	 */
	private static function queryScopeRevisions($db, $designs, $scope, $id)
	{
		$rows = array();
		if (!in_array($scope, array(self::SCOPE_ECO, self::SCOPE_DESIGN), true)) {
			return $rows;
		}
		$sql = "SELECT r.rowid, r.ref, r.rev, r.fk_eco, r.customer_approval, r.customer_note, c.rowid as comp_id, c.ref as comp_ref, c.label, c.fk_design";
		$sql .= " FROM ".$db->prefix()."designtrace_componentrevision as r INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
		$sql .= " WHERE r.needs_approval = 1 AND r.status = ".((int) ComponentRevision::STATUS_INREVIEW)." AND r.customer_approval <> ".((int) DesignTraceProofApproval::DECISION_APPROVED);
		if ($designs !== null) {
			$sql .= " AND c.fk_design IN (".$db->sanitize(implode(',', array_map('intval', $designs))).")";
		}
		if ($scope === self::SCOPE_ECO) {
			$sql .= " AND r.fk_eco = ".((int) $id);
		} else {
			$sql .= " AND c.fk_design = ".((int) $id)." AND (r.fk_eco IS NULL OR r.fk_eco = 0)";
		}
		$sql .= " ORDER BY c.ref, r.rowid";
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$rows[(int) $obj->rowid] = array('id' => (int) $obj->rowid, 'ref' => $obj->ref, 'rev' => $obj->rev, 'component_id' => (int) $obj->comp_id, 'component_ref' => $obj->comp_ref,
				'label' => (string) $obj->label, 'design_id' => (int) $obj->fk_design, 'eco_id' => (int) $obj->fk_eco, 'customer_approval' => (int) $obj->customer_approval, 'release_note' => (string) $obj->customer_note);
		}
		return $rows;
	}

	/**
	 * Approvals waiting for the customer, one entry per ECO or design
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$socid	Customer id
	 * @return	array<int,array<string,mixed>>	scope, id, ref, label, round (ECOs), design_ids, revisions, awaiting (revisions not decided yet),
	 *											changes_requested (revisions the customer asked changes for, waiting for the workshop)
	 */
	public static function fetchPending($db, $socid)
	{
		$designs = array();
		foreach (DesignTraceLibrary::fetchAccess($db, $socid) as $designid => $info) {
			if (empty($info['ended'])) {
				$designs[] = (int) $designid;
			}
		}
		if (empty($designs)) {
			return array();
		}
		$sql = "SELECT DISTINCT r.fk_eco, c.fk_design FROM ".$db->prefix()."designtrace_componentrevision as r INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
		$sql .= " WHERE r.needs_approval = 1 AND r.status = ".((int) ComponentRevision::STATUS_INREVIEW)." AND r.customer_approval <> ".((int) DesignTraceProofApproval::DECISION_APPROVED);
		$sql .= " AND c.fk_design IN (".$db->sanitize(implode(',', $designs)).")";
		$resql = $db->query($sql);
		$scopes = array();
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$key = ((int) $obj->fk_eco > 0 ? self::SCOPE_ECO.':'.(int) $obj->fk_eco : self::SCOPE_DESIGN.':'.(int) $obj->fk_design);
			$scopes[$key] = true;
		}
		$entries = array();
		foreach (array_keys($scopes) as $key) {
			list($scope, $id) = explode(':', $key);
			$revisions = self::fetchScopeRevisions($db, $socid, $scope, (int) $id);
			if (empty($revisions)) {
				continue;
			}
			$entry = array('scope' => $scope, 'id' => (int) $id, 'ref' => '', 'label' => '', 'round' => null);
			if ($scope === self::SCOPE_ECO) {
				$eco = new ChangeOrder($db);
				if ($eco->fetch((int) $id) > 0) {
					$entry['ref'] = $eco->ref;
					$entry['label'] = (string) $eco->label;
					$entry['round'] = $eco->getRound();
				}
			} else {
				$resql2 = $db->query("SELECT ref, label FROM ".$db->prefix()."designtrace_design WHERE rowid = ".((int) $id));
				$d = $resql2 ? $db->fetch_object($resql2) : null;
				$entry['ref'] = $d ? $d->ref : '';
				$entry['label'] = $d ? (string) $d->label : '';
			}
			$entry['design_ids'] = array_values(array_unique(array_column($revisions, 'design_id')));
			$entry['awaiting'] = count(array_filter($revisions, function ($r) {
				return $r['customer_approval'] === DesignTraceProofApproval::DECISION_NONE;
			}));
			$entry['changes_requested'] = count($revisions) - $entry['awaiting'];
			$entry['revisions'] = array_values($revisions);
			$entries[] = $entry;
		}
		return $entries;
	}

	/**
	 * Approve: one approval covers all the flagged revisions waiting in the scope
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	User	$user		User acting for the customer (service user): author of the events
	 * @param	int		$socid		Customer id
	 * @param	string	$scope		self::SCOPE_*
	 * @param	int		$id			ECO id or design id
	 * @param	string	$role		Acting user's role (admin or user)
	 * @param	string	$comment	Optional comment
	 * @param	string	$channel	DesignTraceClientAccess::CHANNEL_*
	 * @param	string	$actor		Who approves
	 * @param	string	$error		Error message if KO
	 * @param	?string	$ip			Customer's IP as seen by a proxying backend (log only)
	 * @param	?string	$useragent	Customer's user agent as seen by a proxying backend
	 * @return	int[]|int			Ids of the revisions approved, -1 if KO, -2 if the role is refused
	 */
	public static function approve($db, $user, $socid, $scope, $id, $role, $comment, $channel, $actor, &$error, $ip = null, $useragent = null)
	{
		global $langs;

		$langs->load('designtrace@designtrace');
		if (!self::checkRole($db, $socid, $role, $error)) {
			return -2;
		}
		$revisions = self::fetchScopeRevisions($db, $socid, $scope, $id);
		if (empty($revisions)) {
			$error = $langs->trans('ErrorApprovalNothingPending');
			return -1;
		}
		return self::applyApproval($db, $user, $socid, $scope, $id, $revisions, trim((string) $comment), $channel, $actor, $ip, $useragent, $error);
	}

	/**
	 * Record an approval for revisions already checked to be waiting
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	User	$user		Author of the events
	 * @param	int		$socid		Customer id
	 * @param	string	$scope		self::SCOPE_*
	 * @param	int		$id			ECO id or design id
	 * @param	array<int,array<string,mixed>>	$revisions	Entries of fetchScopeRevisions()
	 * @param	string	$comment	Comment
	 * @param	string	$channel	Channel
	 * @param	string	$actor		Who approves
	 * @param	?string	$ip			IP (log only)
	 * @param	?string	$useragent	User agent (log only)
	 * @param	string	$error		Error message if KO
	 * @return	int[]|int			Ids of the revisions approved, -1 if KO
	 */
	private static function applyApproval($db, $user, $socid, $scope, $id, $revisions, $comment, $channel, $actor, $ip, $useragent, &$error)
	{
		$db->begin();
		foreach ($revisions as $rev) {
			if (self::record($db, $user, $socid, $scope, $id, $rev, DesignTraceProofApproval::DECISION_APPROVED, $comment, $channel, $actor, $ip, $useragent, 0, $error) < 0) {
				$db->rollback();
				return -1;
			}
		}
		$db->commit();
		return array_keys($revisions);
	}

	/**
	 * Request changes on named revisions of a scope. Reopens the ECO as a new round, or opens a
	 * draft ECO (reason customer, the components of those revisions) when there is none.
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	User	$user		User acting for the customer
	 * @param	int		$socid		Customer id
	 * @param	string	$scope		self::SCOPE_*
	 * @param	int		$id			ECO id or design id
	 * @param	int[]	$revids		Revisions the changes are for (empty = all waiting in the scope)
	 * @param	string	$comment	What to change (required)
	 * @param	string	$role		Acting user's role
	 * @param	string	$channel	Channel
	 * @param	string	$actor		Who asks
	 * @param	string	$error		Error message if KO
	 * @param	?string	$ip			Customer's IP as seen by a proxying backend
	 * @param	?string	$useragent	Customer's user agent as seen by a proxying backend
	 * @return	array{eco:int,round:int,revisions:int[]}|int	-1 if KO, -2 if the role is refused
	 */
	public static function requestChanges($db, $user, $socid, $scope, $id, $revids, $comment, $role, $channel, $actor, &$error, $ip = null, $useragent = null)
	{
		global $langs;

		$langs->load('designtrace@designtrace');
		if (!self::checkRole($db, $socid, $role, $error)) {
			return -2;
		}
		$comment = trim((string) $comment);
		if ($comment === '') {
			$error = $langs->trans('ErrorProofCommentRequired');
			return -1;
		}
		$revisions = self::fetchScopeRevisions($db, $socid, $scope, $id);
		if (empty($revisions)) {
			$error = $langs->trans('ErrorApprovalNothingPending');
			return -1;
		}
		$revids = array_values(array_unique(array_map('intval', (array) $revids)));
		if (!empty($revids)) {
			foreach ($revids as $revid) {
				if (!isset($revisions[$revid])) {
					$error = $langs->trans('ErrorApprovalRevisionsNotInScope');
					return -1;
				}
			}
			$revisions = array_intersect_key($revisions, array_flip($revids));
		}
		return self::applyChanges($db, $user, $socid, $scope, $id, $revisions, $comment, $channel, $actor, $ip, $useragent, $error);
	}

	/**
	 * Request changes on revisions already checked to be waiting: reopens the ECO as a new round, or
	 * opens a draft ECO, and declines the revisions
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	User	$user		Author
	 * @param	int		$socid		Customer id
	 * @param	string	$scope		self::SCOPE_*
	 * @param	int		$id			ECO id or design id
	 * @param	array<int,array<string,mixed>>	$revisions	Entries of fetchScopeRevisions() (not empty)
	 * @param	string	$comment	What to change (not empty)
	 * @param	string	$channel	Channel
	 * @param	string	$actor		Who asks
	 * @param	?string	$ip			IP (log only)
	 * @param	?string	$useragent	User agent (log only)
	 * @param	string	$error		Error message if KO
	 * @return	array{eco:int,round:int,revisions:int[]}|int	-1 if KO
	 */
	private static function applyChanges($db, $user, $socid, $scope, $id, $revisions, $comment, $channel, $actor, $ip, $useragent, &$error)
	{
		global $langs;

		$db->begin();
		$eco = new ChangeOrder($db);
		$ecoid = ($scope === self::SCOPE_ECO ? (int) $id : 0);
		if ($ecoid > 0) {
			if ($eco->fetch($ecoid) <= 0) {
				$db->rollback();
				$error = $langs->trans('ErrorRecordNotFound');
				return -1;
			}
			$round = $eco->reopenForNewRound($user, $actor, $comment);
			if ($round < 0) {
				$error = $eco->error ?: $langs->trans('Error');
				$db->rollback();
				return -1;
			}
		} else {
			$first = reset($revisions);
			$resql = $db->query("SELECT d.ref, d.fk_project FROM ".$db->prefix()."designtrace_design as d WHERE d.rowid = ".((int) $first['design_id']));
			$design = $resql ? $db->fetch_object($resql) : null;
			$eco->label = dol_trunc($langs->transnoentities('ApprovalChangesEcoLabel', $design ? $design->ref : ''), 250, 'right', 'UTF-8', 1);
			$eco->reason = ChangeOrder::REASON_CUSTOMER;
			$eco->fk_soc = (int) $socid;
			$eco->fk_project = $design ? (int) $design->fk_project : 0;
			$eco->description = $langs->transnoentities('ApprovalChangesEcoText', $actor)."\n\n".$comment;
			if ($eco->create($user) <= 0) {
				$error = $eco->error ?: implode(', ', $eco->errors);
				$db->rollback();
				return -1;
			}
			$ecoid = (int) $eco->id;
			$round = 1;
			$eco->markCustomerRequest(ChangeOrder::REQUEST_CHANGE, $actor, (int) $first['id']);
			foreach ($revisions as $rev) {
				if ($eco->addAffectedComponent($user, $rev['component_id'], $langs->transnoentities('ProofChangesEcoNote', $rev['ref']), 1) < 0) {
					$error = $eco->error ?: implode(', ', $eco->errors);
					$db->rollback();
					return -1;
				}
				if (!$db->query("UPDATE ".$db->prefix()."designtrace_componentrevision SET fk_eco = ".$ecoid." WHERE rowid = ".((int) $rev['id']))) {
					$error = $db->lasterror();
					$db->rollback();
					return -1;
				}
			}
			$eco->addTimelineEntry('changes_requested', $actor, $comment);
		}
		foreach ($revisions as $rev) {
			if (self::record($db, $user, $socid, $scope, $id, $rev, DesignTraceProofApproval::DECISION_CHANGES, $comment, $channel, $actor, $ip, $useragent, $ecoid, $error) < 0) {
				$db->rollback();
				return -1;
			}
		}
		$db->commit();
		return array('eco' => $ecoid, 'round' => (int) $round, 'revisions' => array_keys($revisions));
	}

	/**
	 * Staff records the customer's decision on one revision (answered by phone, email or in person).
	 * Same effects as the customer's own decision: the history row (channel "staff", the actor is the
	 * staff user), the stamp on the revision and an event; an approval unblocks release, changes
	 * requested decline the revision and reopen or open the ECO. Only for a flagged, In review
	 * revision not approved yet, of a design that has a customer.
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	User	$user		Staff user recording the decision (needs the release right)
	 * @param	int		$revid		Revision id
	 * @param	int		$decision	DesignTraceProofApproval::DECISION_APPROVED or DECISION_CHANGES
	 * @param	string	$received	How and from whom it was received (required), e.g. "phone, Ann"
	 * @param	string	$comment	Note; what should change when requesting changes (required then)
	 * @param	string	$error		Error message if KO
	 * @param	bool	$wholeScope	Approval only: cover every flagged revision waiting in the same ECO (or in the same design
	 *								when it has no ECO), as the customer's own approval does, not just this one
	 * @return	array{eco:int,round:int,revisions:int[]}|int[]|int	Result of the decision (ids of the revisions approved), -1 if KO
	 */
	public static function recordOnBehalf($db, $user, $revid, $decision, $received, $comment, &$error, $wholeScope = false)
	{
		global $langs;

		$langs->load('designtrace@designtrace');
		$error = '';
		if (!empty($user->socid) || !$user->hasRight('designtrace', 'componentrevision', 'release')) {
			$error = $langs->trans('NotEnoughPermissions');
			return -1;
		}
		$decision = (int) $decision;
		if (!in_array($decision, array(DesignTraceProofApproval::DECISION_APPROVED, DesignTraceProofApproval::DECISION_CHANGES), true)) {
			$error = $langs->trans('ErrorProofBadDecision');
			return -1;
		}
		$received = trim((string) $received);
		$comment = trim((string) $comment);
		if ($received === '') {
			$error = $langs->trans('ErrorStaffDecisionReceivedRequired');
			return -1;
		}
		if ($decision === DesignTraceProofApproval::DECISION_CHANGES && $comment === '') {
			$error = $langs->trans('ErrorProofCommentRequired');
			return -1;
		}

		$sql = "SELECT r.rowid, r.ref, r.rev, r.fk_eco, r.customer_approval, r.customer_note, c.rowid as comp_id, c.ref as comp_ref, c.label, c.fk_design, d.fk_soc";
		$sql .= " FROM ".$db->prefix()."designtrace_componentrevision as r INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_design as d ON d.rowid = c.fk_design";
		$sql .= " WHERE r.rowid = ".((int) $revid)." AND r.needs_approval = 1 AND r.status = ".((int) ComponentRevision::STATUS_INREVIEW);
		$sql .= " AND r.customer_approval <> ".((int) DesignTraceProofApproval::DECISION_APPROVED);
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		if (!$obj) {
			$error = $langs->trans('ErrorApprovalNothingPending');
			return -1;
		}
		if ((int) $obj->fk_soc <= 0) {
			$error = $langs->trans('ErrorStaffDecisionNoCustomer');
			return -1;
		}
		$socid = (int) $obj->fk_soc;
		$rev = array('id' => (int) $obj->rowid, 'ref' => $obj->ref, 'rev' => $obj->rev, 'component_id' => (int) $obj->comp_id, 'component_ref' => $obj->comp_ref,
			'label' => (string) $obj->label, 'design_id' => (int) $obj->fk_design, 'eco_id' => (int) $obj->fk_eco, 'customer_approval' => (int) $obj->customer_approval, 'release_note' => (string) $obj->customer_note);
		$scope = ((int) $obj->fk_eco > 0 ? self::SCOPE_ECO : self::SCOPE_DESIGN);
		$scopeid = ($scope === self::SCOPE_ECO ? (int) $obj->fk_eco : (int) $obj->fk_design);
		$note = $langs->transnoentities('StaffDecisionNote', $received).($comment !== '' ? ' '.$comment : '');
		$actor = 'staff:'.$user->login;

		if ($decision === DesignTraceProofApproval::DECISION_APPROVED) {
			$revisions = array((int) $rev['id'] => $rev);
			if ($wholeScope) {
				// Every design of this customer (an ECO can span several), whether or not the portal reaches it
				$designs = array();
				$resql = $db->query("SELECT rowid FROM ".$db->prefix()."designtrace_design WHERE fk_soc = ".$socid);
				while ($resql && ($d = $db->fetch_object($resql))) {
					$designs[] = (int) $d->rowid;
				}
				$revisions = self::queryScopeRevisions($db, $designs, $scope, $scopeid);
				$revisions[(int) $rev['id']] = $rev;
			}
			return self::applyApproval($db, $user, $socid, $scope, $scopeid, $revisions, $note, 'staff', $actor, null, null, $error);
		}
		return self::applyChanges($db, $user, $socid, $scope, $scopeid, array((int) $rev['id'] => $rev), $note, 'staff', $actor, null, null, $error);
	}

	/**
	 * Store one decision: history row, the stamp on the revision (by SQL: released revisions are
	 * read-only to staff) and an event on the revision
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	User	$user		Author of the event
	 * @param	int		$socid		Customer
	 * @param	string	$scope		Scope
	 * @param	int		$scopeid	Scope id
	 * @param	array<string,mixed>	$rev	Entry of fetchScopeRevisions()
	 * @param	int		$decision	Decision
	 * @param	string	$comment	Comment
	 * @param	string	$channel	Channel
	 * @param	string	$actor		Who
	 * @param	?string	$ip			IP
	 * @param	?string	$useragent	User agent
	 * @param	int		$ecoid		ECO opened or reopened
	 * @param	string	$error		Error
	 * @return	int					>0 if OK
	 */
	private static function record($db, $user, $socid, $scope, $scopeid, $rev, $decision, $comment, $channel, $actor, $ip, $useragent, $ecoid, &$error)
	{
		global $conf, $langs;

		$ip = DesignTraceClientAccess::resolveClientIp($ip);
		$useragent = DesignTraceClientAccess::resolveUserAgent($useragent);
		$now = dol_now();
		$actor = dol_trunc((string) $actor, 128, 'right', 'UTF-8', 1);
		$sql = "INSERT INTO ".$db->prefix()."designtrace_approval";
		$sql .= " (entity, fk_revision, decision, comment, element, fk_element, fk_soc, channel, actor, ip, useragent, fk_ticket, fk_eco, date_decision)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $rev['id']).", ".((int) $decision).", ".($comment !== '' ? "'".$db->escape($comment)."'" : "NULL");
		$sql .= ", '".$db->escape($scope)."', ".((int) $scopeid).", ".((int) $socid).", '".$db->escape($channel)."', '".$db->escape($actor)."'";
		$sql .= ", '".$db->escape($ip)."', '".$db->escape($useragent)."', NULL, ".($ecoid > 0 ? (int) $ecoid : ((int) $rev['eco_id'] > 0 ? (int) $rev['eco_id'] : 'NULL')).", '".$db->idate($now)."')";
		if (!$db->query($sql)) {
			$error = $db->lasterror();
			return -1;
		}
		$sql = "UPDATE ".$db->prefix()."designtrace_componentrevision SET customer_approval = ".((int) $decision).", date_customer_approval = '".$db->idate($now)."', customer_approval_by = '".$db->escape($actor)."'";
		$sql .= " WHERE rowid = ".((int) $rev['id']);
		if (!$db->query($sql)) {
			$error = $db->lasterror();
			return -1;
		}
		// Changes requested close the revision: it is never released, the fix is a new revision
		if ((int) $decision === DesignTraceProofApproval::DECISION_CHANGES && ComponentRevision::markDeclined($db, (int) $rev['id']) < 0) {
			$error = $db->lasterror();
			return -1;
		}
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		$langs->load('designtrace@designtrace');
		$event = new ActionComm($db);
		$event->type_code = 'AC_OTH_AUTO';
		$event->code = ((int) $decision === DesignTraceProofApproval::DECISION_APPROVED ? 'AC_DESIGNTRACE_PROOF_APPROVED' : 'AC_DESIGNTRACE_PROOF_CHANGES');
		$event->label = $langs->transnoentities((int) $decision === DesignTraceProofApproval::DECISION_APPROVED ? 'EventApprovalApproved' : 'EventApprovalChanges', $rev['ref'], $actor);
		$event->note_private = $langs->transnoentities('EventProofNote', strtoupper($scope).' '.$scopeid, $actor).($comment !== '' ? "\n".$comment : '');
		$event->datep = $now;
		$event->datef = $now;
		$event->percentage = -1;
		$event->userownerid = $user->id;
		$event->authorid = $user->id;
		$event->socid = (int) $socid;
		$event->elementtype = 'componentrevision@designtrace';
		$event->fk_element = (int) $rev['id'];
		if ($event->create($user) <= 0) {
			$error = $event->error;
			return -1;
		}
		return 1;
	}
}
