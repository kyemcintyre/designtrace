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
 * \file        designtrace/class/staffapi.class.php
 * \ingroup     designtrace
 * \brief       What the staff endpoints of the REST API need: triage queue, customer view, metrics
 *
 * Staff endpoints are called with the staff user's own Dolibarr API key: their rights apply (checked
 * by the API class) and confidential designs they cannot see are left out here.
 */

dol_include_once('/designtrace/class/designlibrary.class.php');
dol_include_once('/designtrace/class/grant.class.php');
dol_include_once('/designtrace/class/licensing.class.php');
dol_include_once('/designtrace/class/componentlink.class.php');
dol_include_once('/designtrace/class/changerequests.class.php');
dol_include_once('/designtrace/class/design.class.php');

/**
 * Staff view of customer work
 */
class DesignTraceStaff
{
	/**
	 * What waits for the workshop
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	User	$user	Staff user (confidential designs they cannot see are left out)
	 * @return	array<string,mixed>	approvals (flagged revisions In review waiting for the customer), ecos (open ECOs, customer
	 *								requests first, with the next action), licences (designs with active work whose licence is not
	 *								accepted), needs_update (flagged components) and counts
	 */
	public static function fetchTriage($db, $user)
	{
		$hidden = Design::getHiddenDesignIds($db, $user);
		$hiddensql = (empty($hidden) ? '' : " AND d.rowid NOT IN (".$db->sanitize(implode(',', array_map('intval', $hidden))).")");

		// Revisions waiting for the customer's approval
		$approvals = array();
		$sql = "SELECT r.rowid, r.ref, r.fk_eco, r.customer_approval, r.date_review, c.rowid as comp_id, c.ref as comp_ref, d.rowid as design_id, d.ref as design_ref, d.fk_soc, s.nom as socname";
		$sql .= " FROM ".$db->prefix()."designtrace_componentrevision as r INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_design as d ON d.rowid = c.fk_design LEFT JOIN ".$db->prefix()."societe as s ON s.rowid = d.fk_soc";
		$sql .= " WHERE r.needs_approval = 1 AND r.status = ".((int) ComponentRevision::STATUS_INREVIEW)." AND r.customer_approval <> 1".$hiddensql;
		$sql .= " ORDER BY r.date_review ASC, r.rowid ASC";
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$approvals[] = array('revision_id' => (int) $obj->rowid, 'revision_ref' => $obj->ref, 'component_id' => (int) $obj->comp_id, 'component_ref' => $obj->comp_ref,
				'design_id' => (int) $obj->design_id, 'design_ref' => $obj->design_ref, 'socid' => (int) $obj->fk_soc, 'customer' => (string) $obj->socname, 'eco_id' => (int) $obj->fk_eco,
				'state' => ((int) $obj->customer_approval === 2 ? 'changes_requested' : 'awaiting_customer'), 'in_review_since' => $db->jdate($obj->date_review));
		}

		// Open ECOs: what to do next
		$ecos = array();
		$resql = $db->query("SELECT rowid FROM ".$db->prefix()."designtrace_changeorder WHERE status IN (".ChangeOrder::STATUS_DRAFT.", ".ChangeOrder::STATUS_APPROVED.", ".ChangeOrder::STATUS_IMPLEMENTED.") ORDER BY rowid DESC");
		$ids = array();
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$ids[] = (int) $obj->rowid;
		}
		$next = array(ChangeOrder::STATUS_DRAFT => 'approve', ChangeOrder::STATUS_APPROVED => 'implement', ChangeOrder::STATUS_IMPLEMENTED => 'close');
		foreach ($ids as $id) {
			$eco = new ChangeOrder($db);
			if ($eco->fetch($id) <= 0 || self::isEcoHidden($db, $eco, $hidden)) {
				continue;
			}
			$info = $eco->getRequestInfo();
			$ecos[] = array('id' => (int) $eco->id, 'ref' => $eco->ref, 'summary' => (string) $eco->label, 'status' => (int) $eco->status, 'next_action' => $next[(int) $eco->status],
				'customer_request' => ((int) $info->request_type > 0), 'type' => array(0 => '', 1 => 'problem', 2 => 'change')[(int) $info->request_type], 'socid' => (int) $eco->fk_soc,
				'requested_by' => (string) $info->requested_by, 'round' => (int) $info->round, 'shareable' => ((int) $info->shareable === 1), 'ticket_id' => (int) $info->fk_ticket,
				'quote_id' => (int) $info->fk_propal, 'waiting_for_quote' => !$eco->isQuoteSettled(), 'date_creation' => (int) $eco->date_creation);
		}
		usort($ecos, function ($a, $b) {
			return ($b['customer_request'] <=> $a['customer_request']) ?: ($a['date_creation'] <=> $b['date_creation']);
		});

		// Licences not accepted on designs with active work
		$licences = array();
		foreach (self::getCustomersWithAccess($db) as $socid) {
			$page = 0;
			do {
				$library = DesignTraceLibrary::fetchLibrary($db, $socid, array(), $page, 200);
				foreach ($library['items'] as $item) {
					if ($item['flags']['licence'] && !in_array($item['id'], $hidden, true) && !$item['archived']) {
						$licences[] = array('design_id' => $item['id'], 'design_ref' => $item['ref'], 'label' => $item['label'], 'socid' => (int) $socid);
					}
				}
				$page++;
			} while ($library['total'] > $page * 200);
		}

		$needsupdate = array();
		foreach (ComponentLink::fetchFlagged($db) as $row) {
			if (in_array((int) $row->design_id, $hidden, true) || in_array((int) $row->parent_design_id, $hidden, true)) {
				continue;
			}
			$needsupdate[] = array('link_id' => (int) $row->rowid, 'flag' => (int) $row->flag_status, 'note' => (string) $row->flag_note);
		}

		return array(
			'approvals' => $approvals,
			'ecos' => $ecos,
			'licences' => $licences,
			'needs_update' => $needsupdate,
			'counts' => array('approvals' => count($approvals), 'ecos' => count($ecos), 'licences' => count($licences), 'needs_update' => count($needsupdate)),
		);
	}

	/**
	 * Whether an ECO touches a design the user may not see (affected components, revision the request is about)
	 *
	 * @param	DoliDB		$db			Database handler
	 * @param	ChangeOrder	$eco		ECO
	 * @param	int[]		$hidden		Design ids the user cannot see (Design::getHiddenDesignIds())
	 * @return	bool
	 */
	public static function isEcoHidden($db, $eco, $hidden)
	{
		if (empty($hidden)) {
			return false;
		}
		$list = $db->sanitize(implode(',', array_map('intval', $hidden)));
		$sql = "SELECT 1 FROM ".$db->prefix()."designtrace_changeorder_component as ac INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = ac.fk_component";
		$sql .= " WHERE ac.fk_eco = ".((int) $eco->id)." AND c.fk_design IN (".$list.")";
		$sql .= " UNION SELECT 1 FROM ".$db->prefix()."designtrace_changeorder as e INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = e.fk_revision_source";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component WHERE e.rowid = ".((int) $eco->id)." AND c.fk_design IN (".$list.")";
		$resql = $db->query($sql);
		return $resql && $db->num_rows($resql) > 0;
	}

	/**
	 * Customers that reach at least one design, whatever the basis
	 *
	 * @param	DoliDB	$db		Database handler
	 * @return	int[]
	 */
	public static function getCustomersWithAccess($db)
	{
		$ids = array();
		foreach (DesignTraceClientAccess::getChannels() as $channel => $def) {
			if (!isModEnabled($def['module'])) {
				continue;
			}
			$sql = "SELECT DISTINCT d.fk_soc FROM ".$db->prefix()."element_element as ee INNER JOIN ".$db->prefix().$def['table']." as d ON d.rowid = ee.fk_source";
			$sql .= " WHERE ee.sourcetype = '".$db->escape($channel)."' AND ee.targettype = 'designtrace_componentrevision' AND d.fk_soc > 0";
			$sql .= " AND d.fk_statut NOT IN (".implode(',', array_map('intval', $def['hidden'])).")";
			$resql = $db->query($sql);
			while ($resql && ($obj = $db->fetch_object($resql))) {
				$ids[(int) $obj->fk_soc] = (int) $obj->fk_soc;
			}
		}
		$resql = $db->query("SELECT DISTINCT fk_soc FROM ".$db->prefix()."designtrace_grant WHERE date_revoked IS NULL AND (date_expiry IS NULL OR date_expiry > '".$db->idate(dol_now())."')");
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$ids[(int) $obj->fk_soc] = (int) $obj->fk_soc;
		}
		sort($ids);
		return array_values($ids);
	}

	/**
	 * Everything a customer sees of a design, plus the grants and why each file is shown or not
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer id
	 * @param	int		$designid	Design id
	 * @return	array<string,mixed>|null	The customer's design entry (as the customer API returns it) plus grants, files, licence; null if the customer has no access, current or ended
	 */
	public static function fetchCustomerView($db, $socid, $designid)
	{
		$view = DesignTraceLibrary::fetchDesign($db, $socid, $designid);
		if (!$view) {
			return null;
		}
		$view['grants'] = array();
		foreach (DesignTraceGrant::fetchForCustomer($db, $socid, $designid) as $grant) {
			$view['grants'][] = array('id' => (int) $grant->rowid, 'active' => (bool) $grant->active, 'whole_design' => ((int) $grant->whole_design === 1), 'date_expiry' => $grant->date_expiry ?: null,
				'date_revoked' => $grant->date_revoked ?: null, 'note' => (string) $grant->note, 'pins' => $grant->pins);
		}

		$access = DesignTraceLibrary::fetchAccess($db, $socid);
		$visible = array();
		foreach ((array) DesignTraceClientAccess::fetchVisibleFiles($db, $socid) as $row) {
			$visible[(int) $row->file_id] = true;
		}
		$revids = array();
		foreach (($access[(int) $designid]['revs'] ?? array()) as $ids) {
			$revids = array_merge($revids, $ids);
		}
		// A grant in force opens every release of its components, not only the pinned one: list those files too
		foreach (DesignTraceGrant::fetchOpenedRevisionIds($db, array_keys($access[(int) $designid]['granted'] ?? array())) as $ids) {
			$revids = array_merge($revids, $ids);
		}
		$revids = array_values(array_unique(array_map('intval', $revids)));
		$view['files'] = array();
		if (!empty($revids)) {
			$sql = "SELECT f.rowid, f.filename, f.role, f.visibility, f.sha256, r.rowid as rev_id, r.ref as rev_ref FROM ".$db->prefix()."designtrace_revisionfile as f";
			$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = f.fk_revision WHERE r.rowid IN (".$db->sanitize(implode(',', array_map('intval', $revids))).") ORDER BY r.rowid, f.filename";
			$resql = $db->query($sql);
			while ($resql && ($obj = $db->fetch_object($resql))) {
				$shown = isset($visible[(int) $obj->rowid]);
				$reason = '';
				if (!$shown) {
					if ((int) $obj->role === RevisionFile::ROLE_PRODUCTION) {
						$reason = 'production file';
					} elseif ((int) $obj->visibility === RevisionFile::VISIBILITY_HIDDEN) {
						$reason = 'hidden';
					} elseif ((int) $obj->role === RevisionFile::ROLE_SOURCE) {
						$reason = 'source not allowed by licence or ownership';
					} else {
						$reason = 'waits for completion';
					}
				}
				$view['files'][] = array('id' => (int) $obj->rowid, 'filename' => $obj->filename, 'revision_id' => (int) $obj->rev_id, 'revision_ref' => $obj->rev_ref, 'role' => (int) $obj->role,
					'visibility' => (int) $obj->visibility, 'sha256' => (string) $obj->sha256, 'shown_to_customer' => $shown, 'reason_not_shown' => $reason);
			}
		}
		$info = DesignTraceLicensing::fetchDesignLicence($db, $socid, $designid, DesignTraceLicensing::getForcedLicence($db, $socid, $designid));
		$view['licence'] = ($info && $info['licence'] && $info['version']) ? array('licence_ref' => $info['licence']->ref, 'version_ref' => $info['version']->ref, 'accepted' => !empty($info['acceptance']),
			'accepted_by' => (!empty($info['acceptance']) ? (string) $info['acceptance']->actor : ''), 'date_accept' => (!empty($info['acceptance']) ? (int) $info['acceptance']->date_accept : null)) : null;
		return $view;
	}

	/**
	 * Unlink a revision from a quote, order, ticket or project. A delivered order keeps its revisions
	 * (use "Update delivered revision").
	 *
	 * @param	DoliDB				$db			Database handler
	 * @param	ComponentRevision	$revision	Revision
	 * @param	string				$element	Key of DesignTraceDocLinks::getRules()
	 * @param	int					$docid		Document id
	 * @param	string				$error		Error message if KO
	 * @return	int							>0 if unlinked, 0 if there was no such link, <0 if refused or KO
	 */
	public static function unlinkRevision($db, $revision, $element, $docid, &$error)
	{
		global $langs;

		dol_include_once('/designtrace/class/doclinks.class.php');
		$langs->loadLangs(array('designtrace@designtrace', 'errors'));
		$rules = DesignTraceDocLinks::getRules();
		if (!isset($rules[$element])) {
			$error = $langs->trans('ErrorRecordNotFound');
			return -1;
		}
		$doc = DesignTraceDocLinks::fetchDocument($db, $element, (int) $docid);
		if (!$doc) {
			$error = $langs->trans('ErrorRecordNotFound');
			return -1;
		}
		if ($element == 'commande' && (int) $doc->status === 3) {
			$error = $langs->trans('ErrorDeliveredLinkLocked', $doc->ref);
			return -1;
		}
		$sql = "SELECT rowid FROM ".$db->prefix()."element_element WHERE sourcetype = '".$db->escape($doc->getElementType())."' AND fk_source = ".((int) $doc->id);
		$sql .= " AND targettype = '".$db->escape($revision->getElementType())."' AND fk_target = ".((int) $revision->id);
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		if (!$obj) {
			return 0;
		}
		return $revision->deleteObjectLinked(null, '', null, '', (int) $obj->rowid) > 0 ? 1 : -1;
	}

	/**
	 * Link a design to a quote or order of its customer (the design-level link: "Create design from a quote")
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	Design	$design		Design
	 * @param	string	$element	propal or commande
	 * @param	int		$docid		Document id
	 * @param	string	$error		Error message if KO
	 * @return	int					>0 if linked, <0 if refused or KO
	 */
	public static function linkDesign($db, $design, $element, $docid, &$error)
	{
		global $langs;

		$langs->loadLangs(array('designtrace@designtrace', 'errors'));
		$doc = DesignTraceLicensing::fetchDocument($db, $element, (int) $docid);
		if (!$doc) {
			$error = $langs->trans('ErrorRecordNotFound');
			return -1;
		}
		if ((int) $design->fk_soc > 0 && (int) $doc->socid !== (int) $design->fk_soc) {
			$error = $langs->trans('ErrorDesignOtherCustomer');
			return -1;
		}
		$resql = $db->query("SELECT rowid FROM ".$db->prefix()."element_element WHERE sourcetype = '".$db->escape($element)."' AND fk_source = ".((int) $doc->id)." AND targettype = 'designtrace_design' AND fk_target = ".((int) $design->id));
		if ($resql && $db->num_rows($resql) > 0) {
			return 1;
		}
		if (!$design->add_object_linked($element, (int) $doc->id)) {
			$error = $db->lasterror();
			return -1;
		}
		if ((int) $design->fk_soc <= 0) {
			$db->query("UPDATE ".$db->prefix()."designtrace_design SET fk_soc = ".((int) $doc->socid)." WHERE rowid = ".((int) $design->id));
		}
		return 1;
	}

	/**
	 * Metrics for a period
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	User	$user	Staff user (confidential designs they cannot see are left out)
	 * @param	int		$from	Start (timestamp)
	 * @param	int		$to		End (timestamp)
	 * @return	array<string,mixed>	approval_turnaround and eco_cycle_time ({count, average_seconds, average_days}), designs_delivered_per_month
	 *								and revisions_released_per_month ([{month, count}]), cad_hours_per_design ([{design_id, ref, label, hours}])
	 */
	public static function fetchMetrics($db, $user, $from, $to)
	{
		$hidden = Design::getHiddenDesignIds($db, $user);
		$hiddensql = (empty($hidden) ? '' : " AND c.fk_design NOT IN (".$db->sanitize(implode(',', array_map('intval', $hidden))).")");
		$fromsql = "'".$db->idate((int) $from)."'";
		$tosql = "'".$db->idate((int) $to)."'";
		$avg = function (array $seconds) {
			$n = count($seconds);
			$a = $n ? (int) round(array_sum($seconds) / $n) : null;
			return array('count' => $n, 'average_seconds' => $a, 'average_days' => ($a === null ? null : round($a / 86400, 1)));
		};
		$month = function ($ts) {
			return dol_print_date($ts, '%Y-%m', 'gmt');
		};

		// Customer approval turnaround: review start to the first approval
		$seconds = array();
		$sql = "SELECT r.date_review, MIN(a.date_decision) as date_decision FROM ".$db->prefix()."designtrace_approval as a";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = a.fk_revision INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
		$sql .= " WHERE a.decision = 1 AND r.date_review IS NOT NULL".$hiddensql." GROUP BY r.rowid, r.date_review HAVING MIN(a.date_decision) >= ".$fromsql." AND MIN(a.date_decision) <= ".$tosql;
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$seconds[] = max(0, $db->jdate($obj->date_decision) - $db->jdate($obj->date_review));
		}
		$turnaround = $avg($seconds);

		// ECO cycle time: raised to closed
		$seconds = array();
		$resql = $db->query("SELECT date_creation, date_closed FROM ".$db->prefix()."designtrace_changeorder WHERE status = ".ChangeOrder::STATUS_CLOSED." AND date_closed IS NOT NULL AND date_closed >= ".$fromsql." AND date_closed <= ".$tosql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$seconds[] = max(0, $db->jdate($obj->date_closed) - $db->jdate($obj->date_creation));
		}
		$cycle = $avg($seconds);

		// Revisions released per month
		$released = array();
		$sql = "SELECT r.date_release FROM ".$db->prefix()."designtrace_componentrevision as r INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
		$sql .= " WHERE r.status IN (".ComponentRevision::STATUS_RELEASED.", ".ComponentRevision::STATUS_OBSOLETE.") AND r.date_release >= ".$fromsql." AND r.date_release <= ".$tosql.$hiddensql;
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$m = $month($db->jdate($obj->date_release));
			$released[$m] = ($released[$m] ?? 0) + 1;
		}

		// Designs delivered per month: an order completed or a grant given for a revision of the design
		$delivered = array();
		$sql = "SELECT dv.date_delivered as dt, c.fk_design FROM ".$db->prefix()."designtrace_delivered as dv INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = dv.fk_revision";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component WHERE dv.date_delivered >= ".$fromsql." AND dv.date_delivered <= ".$tosql.$hiddensql;
		$sql .= " UNION SELECT o.date_cloture as dt, c.fk_design FROM ".$db->prefix()."element_element as ee INNER JOIN ".$db->prefix()."commande as o ON o.rowid = ee.fk_source AND o.fk_statut = 3";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = ee.fk_target INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
		$sql .= " WHERE ee.sourcetype = 'commande' AND ee.targettype = 'designtrace_componentrevision' AND o.date_cloture >= ".$fromsql." AND o.date_cloture <= ".$tosql.$hiddensql;
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$delivered[$month($db->jdate($obj->dt))][(int) $obj->fk_design] = true;
		}

		// CAD hours per design
		$hours = array();
		$sql = "SELECT t.fk_design, d.ref, d.label, SUM(et.element_duration) as secs FROM ".$db->prefix()."designtrace_time as t INNER JOIN ".$db->prefix()."element_time as et ON et.rowid = t.fk_element_time";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_design as d ON d.rowid = t.fk_design WHERE t.fk_design > 0 AND et.element_date >= ".$fromsql." AND et.element_date <= ".$tosql;
		$sql .= (empty($hidden) ? '' : " AND d.rowid NOT IN (".$db->sanitize(implode(',', array_map('intval', $hidden))).")")." GROUP BY t.fk_design, d.ref, d.label ORDER BY secs DESC";
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$hours[] = array('design_id' => (int) $obj->fk_design, 'ref' => $obj->ref, 'label' => (string) $obj->label, 'hours' => round(((float) $obj->secs) / 3600, 2));
		}

		ksort($released);
		ksort($delivered);
		$permonth = function (array $counts) {
			$rows = array();
			foreach ($counts as $m => $n) {
				$rows[] = array('month' => $m, 'count' => (is_array($n) ? count($n) : $n));
			}
			return $rows;
		};
		return array('from' => (int) $from, 'to' => (int) $to, 'approval_turnaround' => $turnaround, 'eco_cycle_time' => $cycle,
			'designs_delivered_per_month' => $permonth($delivered), 'revisions_released_per_month' => $permonth($released), 'cad_hours_per_design' => $hours);
	}

	/**
	 * A design as the workshop sees it, whoever it is shared with: every component with every revision
	 * (drafts and in review too) and every file of each, as the customer view's tree is shaped.
	 *
	 * Components of other designs that the design's components link to are included as external. Which
	 * revisions count as delivered is worked out for one customer: the given one, else the design's.
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	Design	$design		Design (the caller has checked the staff user may see it)
	 * @param	int		$socid		Customer the delivered flags are for (0 = the design's own customer)
	 * @return	array<string,mixed>	id, ref, label, tree (components, as GET customers/{socid}/designs/{designid}), roots
	 */
	public static function fetchDesignOverview($db, $design, $socid = 0)
	{
		$designid = (int) $design->id;
		$socid = ((int) $socid > 0 ? (int) $socid : (int) $design->fk_soc);
		$delivered = ($socid > 0 ? DesignTraceLibrary::getDeliveredRevisionIds($db, $socid) : array());

		// The design's components, then the other designs' components they link to, until nothing new turns up
		$ids = array();
		$resql = $db->query("SELECT rowid FROM ".$db->prefix()."designtrace_component WHERE fk_design = ".$designid." ORDER BY rowid");
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$ids[(int) $obj->rowid] = (int) $obj->rowid;
		}
		$childrows = array();
		// Capped: a design linked into a big shared library must not pull in the whole library
		for ($queue = array_values($ids); !empty($queue) && count($ids) < 500;) {
			// Every revision of the queued components, drafts and superseded ones too: a part linked only from a draft still shows
			$cur = array();
			$resql = $db->query("SELECT rowid FROM ".$db->prefix()."designtrace_componentrevision WHERE fk_component IN (".$db->sanitize(implode(',', array_map('intval', $queue))).")");
			while ($resql && ($o = $db->fetch_object($resql))) {
				$cur[] = (int) $o->rowid;
			}
			$queue = array();
			if (empty($cur)) {
				break;
			}
			$sql = "SELECT DISTINCT pr.fk_component as parent_comp, l.link_type, cr.fk_component as child_comp FROM ".$db->prefix()."designtrace_componentlink as l";
			$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as pr ON pr.rowid = l.fk_parent";
			$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as cr ON cr.rowid = l.fk_child";
			$sql .= " WHERE l.fk_parent IN (".$db->sanitize(implode(',', $cur)).")";
			$resql = $db->query($sql);
			while ($resql && ($obj = $db->fetch_object($resql))) {
				$childrows[] = $obj;
				if (!isset($ids[(int) $obj->child_comp])) {
					$ids[(int) $obj->child_comp] = (int) $obj->child_comp;
					$queue[] = (int) $obj->child_comp;
				}
			}
		}
		$current = ComponentLink::getCurrentRevisionIds($db, array_values($ids));

		$nodes = array();
		$typenames = array(0 => 'part', 1 => 'assembly', 2 => 'drawing');
		if (!empty($ids)) {
			$in = $db->sanitize(implode(',', array_map('intval', array_values($ids))));
			$sql = "SELECT c.rowid, c.ref, c.label, c.type, c.drawing_type, c.fk_design, d.ref as design_ref FROM ".$db->prefix()."designtrace_component as c";
			$sql .= " INNER JOIN ".$db->prefix()."designtrace_design as d ON d.rowid = c.fk_design WHERE c.rowid IN (".$in.") ORDER BY c.rowid";
			$resql = $db->query($sql);
			while ($resql && ($c = $db->fetch_object($resql))) {
				$nodes[(int) $c->rowid] = array('id' => (int) $c->rowid, 'ref' => $c->ref, 'label' => (string) $c->label, 'type' => $typenames[(int) $c->type] ?? 'part',
					'drawing_type' => ((int) $c->type === 2 ? (int) $c->drawing_type : null), 'design_id' => (int) $c->fk_design, 'design_ref' => $c->design_ref,
					'external' => ((int) $c->fk_design !== $designid), 'held_revision' => null, 'delivered_revision' => null,
					'current_revision' => (isset($current[(int) $c->rowid]) ? (int) $current[(int) $c->rowid] : null), 'newer_revision' => false, 'revisions' => array(), 'children' => array());
			}
			$files = array();
			$sql = "SELECT f.rowid, f.fk_revision, f.filename, f.role, f.format, f.filesize, f.sha256, f.visibility FROM ".$db->prefix()."designtrace_revisionfile as f";
			$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = f.fk_revision WHERE r.fk_component IN (".$in.") ORDER BY f.fk_revision, f.filename";
			$resql = $db->query($sql);
			while ($resql && ($f = $db->fetch_object($resql))) {
				$files[(int) $f->fk_revision][] = array('id' => (int) $f->rowid, 'filename' => $f->filename, 'role' => (int) $f->role, 'format' => (string) $f->format,
					'size' => (int) $f->filesize, 'sha256' => (string) $f->sha256, 'visibility' => (int) $f->visibility);
			}
			$sql = "SELECT rowid, fk_component, rev, ref, status, date_release, customer_note FROM ".$db->prefix()."designtrace_componentrevision WHERE fk_component IN (".$in.") ORDER BY rowid";
			$resql = $db->query($sql);
			while ($resql && ($r = $db->fetch_object($resql))) {
				$comp = (int) $r->fk_component;
				if (!isset($nodes[$comp])) {
					continue;
				}
				$isdelivered = isset($delivered[(int) $r->rowid]);
				$nodes[$comp]['revisions'][] = array('id' => (int) $r->rowid, 'rev' => (string) $r->rev, 'ref' => (string) $r->ref, 'status' => (int) $r->status,
					'date_release' => $db->jdate($r->date_release), 'release_note' => (string) $r->customer_note, 'is_current' => ($nodes[$comp]['current_revision'] === (int) $r->rowid),
					'is_delivered' => $isdelivered, 'files' => $files[(int) $r->rowid] ?? array());
				if ($isdelivered) {
					$nodes[$comp]['delivered_revision'] = (int) $r->rowid;
				}
			}
		}
		$childof = array();
		foreach ($childrows as $link) {
			$parent = (int) $link->parent_comp;
			$child = (int) $link->child_comp;
			if (!isset($nodes[$parent]) || !isset($nodes[$child]) || $parent === $child) {
				continue;
			}
			$nodes[$parent]['children'][] = array('component_id' => $child, 'link' => array(1 => 'uses', 2 => 'depicts', 3 => 'references')[(int) $link->link_type] ?? 'references');
			$childof[$child] = true;
		}
		$roots = array();
		foreach ($nodes as $compid => $node) {
			if (!isset($childof[$compid]) && !$node['external']) {
				$roots[] = $compid;
			}
		}
		return array('id' => $designid, 'ref' => (string) $design->ref, 'label' => (string) $design->label, 'tree' => array_values($nodes), 'roots' => $roots);
	}

	/**
	 * What happened on a design, for any customer: releases, approvals and change requests, licence
	 * acceptances, consent, downloads (staff previews left out) and grants, newest first.
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$designid	Design id
	 * @param	int		$limit		Max entries (1-500)
	 * @return	array<int,array<string,mixed>>	date, type, design_id, design_ref, text, actor, soc_id (0 when not about one customer)
	 */
	public static function fetchDesignHistory($db, $designid, $limit = 100)
	{
		$limit = max(1, min(500, (int) $limit));
		$designid = (int) $designid;
		$resql = $db->query("SELECT ref FROM ".$db->prefix()."designtrace_design WHERE rowid = ".$designid);
		$obj = $resql ? $db->fetch_object($resql) : null;
		$ref = $obj ? (string) $obj->ref : '';
		$events = array();
		$add = function ($date, $type, $text, $actor, $socid = 0) use (&$events, $designid, $ref) {
			$events[] = array('date' => (int) $date, 'type' => $type, 'design_id' => $designid, 'design_ref' => $ref, 'text' => (string) $text, 'actor' => (string) $actor, 'soc_id' => (int) $socid);
		};

		$sql = "SELECT r.ref, r.date_release FROM ".$db->prefix()."designtrace_componentrevision as r INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
		$sql .= " WHERE c.fk_design = ".$designid." AND r.status IN (".((int) ComponentRevision::STATUS_RELEASED).", ".((int) ComponentRevision::STATUS_OBSOLETE).") AND r.date_release IS NOT NULL";
		$resql = $db->query($sql);
		while ($resql && ($o = $db->fetch_object($resql))) {
			$add($db->jdate($o->date_release), 'release', $o->ref, '');
		}
		$sql = "SELECT a.decision, a.comment, a.actor, a.date_decision, a.fk_soc, r.ref FROM ".$db->prefix()."designtrace_approval as a";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = a.fk_revision INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
		$sql .= " WHERE c.fk_design = ".$designid;
		$resql = $db->query($sql);
		while ($resql && ($o = $db->fetch_object($resql))) {
			$add($db->jdate($o->date_decision), ((int) $o->decision === DesignTraceProofApproval::DECISION_APPROVED ? 'approval' : 'changes_requested'), $o->ref.($o->comment ? ': '.$o->comment : ''), $o->actor, $o->fk_soc);
		}
		$sql = "SELECT a.actor, a.date_accept, a.fk_soc, v.ref FROM ".$db->prefix()."designtrace_licenceaccept as a LEFT JOIN ".$db->prefix()."designtrace_licenceversion as v ON v.rowid = a.fk_licenceversion WHERE a.fk_design = ".$designid;
		$resql = $db->query($sql);
		while ($resql && ($o = $db->fetch_object($resql))) {
			$add($db->jdate($o->date_accept), 'licence_accepted', $o->ref, $o->actor, $o->fk_soc);
		}
		$resql = $db->query("SELECT consent, actor, date_consent FROM ".$db->prefix()."designtrace_consent WHERE fk_design = ".$designid);
		while ($resql && ($o = $db->fetch_object($resql))) {
			$add($db->jdate($o->date_consent), 'consent', ((int) $o->consent === DesignTracePortfolio::CONSENT_GRANTED ? 'granted' : 'refused'), $o->actor);
		}
		$sql = "SELECT dl.filename, dl.actor, dl.date_download, dl.fk_soc FROM ".$db->prefix()."designtrace_download as dl";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = dl.fk_revision INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
		$sql .= " WHERE c.fk_design = ".$designid." AND dl.channel <> '".$db->escape(DesignTraceClientAccess::CHANNEL_ADMIN_PREVIEW)."' ORDER BY dl.date_download DESC, dl.rowid DESC".$db->plimit(500);
		$resql = $db->query($sql);
		while ($resql && ($o = $db->fetch_object($resql))) {
			$add($db->jdate($o->date_download), 'download', $o->filename, $o->actor, $o->fk_soc);
		}
		$resql = $db->query("SELECT rowid, fk_soc, date_creation, date_revoked FROM ".$db->prefix()."designtrace_grant WHERE fk_design = ".$designid);
		while ($resql && ($o = $db->fetch_object($resql))) {
			$add($db->jdate($o->date_creation), 'grant_given', 'GRANT-'.$o->rowid, '', $o->fk_soc);
			if (!empty($o->date_revoked)) {
				$add($db->jdate($o->date_revoked), 'grant_revoked', 'GRANT-'.$o->rowid, '', $o->fk_soc);
			}
		}
		// Newest first; events at the same second keep the order they were gathered in
		foreach ($events as $i => $e) {
			$events[$i]['_i'] = $i;
		}
		usort($events, function ($a, $b) {
			return ($b['date'] <=> $a['date']) ?: ($a['_i'] <=> $b['_i']);
		});
		$events = array_slice($events, 0, $limit);
		foreach ($events as $i => $e) {
			unset($events[$i]['_i']);
		}
		return $events;
	}

	/**
	 * Differences between two revisions of a component, every file counted (hidden, source and
	 * production ones too). Files are matched by name without the component prefix, as in the customer's compare.
	 *
	 * @param	DoliDB	$db				Database handler
	 * @param	int		$componentid	Component id
	 * @param	int		$fromid			Older revision (0 = the one before the newer one)
	 * @param	int		$toid			Newer revision (0 = the current one, else the latest)
	 * @param	int		$socid			Customer the delivered flags are for (0 = the design's own customer)
	 * @return	array<string,mixed>|null	component_id, from, to, files; null if the component or a revision is not there, or there is nothing to compare
	 */
	public static function compareComponentRevisions($db, $componentid, $fromid = 0, $toid = 0, $socid = 0)
	{
		$componentid = (int) $componentid;
		$revs = array();
		$resql = $db->query("SELECT rowid, rev, ref, status, date_release, customer_note FROM ".$db->prefix()."designtrace_componentrevision WHERE fk_component = ".$componentid." ORDER BY rowid");
		while ($resql && ($o = $db->fetch_object($resql))) {
			$revs[(int) $o->rowid] = $o;
		}
		if (count($revs) < 1) {
			return null;
		}
		$currentids = ComponentLink::getCurrentRevisionIds($db, array($componentid));
		$current = (int) ($currentids[$componentid] ?? 0);
		$toid = (int) $toid ?: ($current ?: max(array_keys($revs)));
		if ((int) $fromid > 0) {
			$fromid = (int) $fromid;
		} else {
			$before = array_filter(array_keys($revs), function ($id) use ($toid) {
				return $id < $toid;
			});
			$fromid = $before ? max($before) : 0;
		}
		if (!isset($revs[$fromid]) || !isset($revs[$toid])) {
			return null;
		}
		$side = function ($revid) use ($db) {
			$out = array();
			$sql = "SELECT f.filename, f.sha256, f.filesize, c.ref as comp_ref FROM ".$db->prefix()."designtrace_revisionfile as f";
			$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = f.fk_revision";
			$sql .= " INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component WHERE f.fk_revision = ".((int) $revid);
			$resql = $db->query($sql);
			while ($resql && ($obj = $db->fetch_object($resql))) {
				$name = (strpos($obj->filename, $obj->comp_ref.'-') === 0 ? substr($obj->filename, strlen($obj->comp_ref) + 1) : $obj->filename);
				$out[$name] = array('sha256' => (string) $obj->sha256, 'size' => (int) $obj->filesize);
			}
			return $out;
		};
		$old = $side($fromid);
		$new = $side($toid);
		$files = array();
		foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $name) {
			$name = (string) $name;
			if (!isset($old[$name])) {
				$change = 'added';
			} elseif (!isset($new[$name])) {
				$change = 'removed';
			} else {
				$change = ($old[$name]['sha256'] === $new[$name]['sha256'] ? 'unchanged' : 'changed');
			}
			$files[] = array('name' => $name, 'change' => $change, 'from' => $old[$name] ?? null, 'to' => $new[$name] ?? null);
		}
		usort($files, function ($a, $b) {
			return strcmp($a['name'], $b['name']);
		});
		if ((int) $socid <= 0) {
			$resql = $db->query("SELECT d.fk_soc FROM ".$db->prefix()."designtrace_design as d INNER JOIN ".$db->prefix()."designtrace_component as c ON c.fk_design = d.rowid WHERE c.rowid = ".$componentid);
			$o = $resql ? $db->fetch_object($resql) : null;
			$socid = $o ? (int) $o->fk_soc : 0;
		}
		$delivered = ((int) $socid > 0 ? DesignTraceLibrary::getDeliveredRevisionIds($db, (int) $socid) : array());
		$describe = function ($revid) use ($db, $revs, $current, $delivered) {
			$o = $revs[$revid];
			return array('id' => (int) $revid, 'rev' => (string) $o->rev, 'ref' => (string) $o->ref, 'status' => (int) $o->status, 'date_release' => $db->jdate($o->date_release),
				'release_note' => (string) $o->customer_note, 'is_current' => ($current === (int) $revid), 'is_delivered' => isset($delivered[(int) $revid]), 'files' => array());
		};
		return array('component_id' => $componentid, 'from' => $describe($fromid), 'to' => $describe($toid), 'files' => $files);
	}

	/**
	 * A revision file as the staff download endpoint needs it, in the shape of the customer's file rows
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$fileid		File id
	 * @return	stdClass|null		file_id, rev_id, rev_ref, filename, format, sha256, design_id, element, doc_id; null if there is no such file
	 */
	public static function fetchFileRow($db, $fileid)
	{
		$sql = "SELECT f.rowid as file_id, f.filename, f.format, f.sha256, r.rowid as rev_id, r.ref as rev_ref, c.fk_design as design_id, d.fk_soc FROM ".$db->prefix()."designtrace_revisionfile as f";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = f.fk_revision";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_design as d ON d.rowid = c.fk_design WHERE f.rowid = ".((int) $fileid);
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		if (!$obj) {
			return null;
		}
		$obj->element = '';
		$obj->doc_id = 0;
		return $obj;
	}

	/**
	 * The folder where the files attached to a design are kept (the "Linked files" tab of its card)
	 *
	 * @param	Design	$design		Design
	 * @return	string				Directory path, '' if the module has no document folder
	 */
	public static function getDesignDocumentsDir($design)
	{
		global $conf;

		$entity = (empty($design->entity) ? $conf->entity : $design->entity);
		if (empty($design->ref) || empty($conf->designtrace->multidir_output[$entity])) {
			return '';
		}
		return $conf->designtrace->multidir_output[$entity].'/design/'.dol_sanitizeFileName($design->ref);
	}

	/**
	 * What is on the "Linked files" tab of a design in Dolibarr: the files attached to it and the links registered on it
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	Design	$design		Design (the caller has checked the staff user may see it)
	 * @return	array<string,mixed>	files (name, size, date), links (id, url, label, date)
	 */
	public static function fetchDesignDocuments($db, $design)
	{
		include_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		include_once DOL_DOCUMENT_ROOT.'/core/class/link.class.php';

		$files = array();
		$dir = self::getDesignDocumentsDir($design);
		if ($dir !== '' && is_dir($dir)) {
			foreach (dol_dir_list($dir, 'files', 0, '', '(\.meta|_preview.*\.png)$', 'name', SORT_ASC, 0) as $f) {
				$files[] = array('name' => (string) $f['name'], 'size' => (int) $f['size'], 'date' => (int) $f['date']);
			}
		}
		$links = array();
		$list = array();
		$link = new Link($db);
		$link->fetchAll($list, 'design', (int) $design->id);
		foreach ($list as $l) {
			$links[] = array('id' => (int) $l->id, 'url' => (string) $l->url, 'label' => (string) $l->label, 'date' => (int) $db->jdate($l->datea));
		}
		return array('design_id' => (int) $design->id, 'files' => $files, 'links' => $links);
	}
}
