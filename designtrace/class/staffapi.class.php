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
			if ($eco->fetch($id) <= 0) {
				continue;
			}
			$info = $eco->getRequestInfo();
			$ecos[] = array('id' => (int) $eco->id, 'ref' => $eco->ref, 'summary' => (string) $eco->label, 'status' => (int) $eco->status, 'next_action' => $next[(int) $eco->status],
				'customer_request' => ((int) $info->request_type > 0), 'type' => array(0 => '', 1 => 'problem', 2 => 'change')[(int) $info->request_type], 'socid' => (int) $eco->fk_soc,
				'requested_by' => (string) $info->requested_by, 'round' => (int) $info->round, 'shareable' => ((int) $info->shareable === 1), 'ticket_id' => (int) $info->fk_ticket,
				'quote_id' => (int) $info->fk_propal, 'waiting_for_quote' => !$eco->isQuoteSettled(), 'date_creation' => (int) $db->jdate($eco->date_creation));
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
}
