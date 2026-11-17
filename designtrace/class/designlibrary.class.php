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
 * \file        designtrace/class/designlibrary.class.php
 * \ingroup     designtrace
 * \brief       The customer's design library: every design they can reach, whatever the basis
 *
 * A customer reaches a design through a quote, order, ticket or project link, or a design-level
 * grant (DesignTraceGrant). Files are decided by DesignTraceClientAccess, this class builds the
 * browsable view on top: library list, design tree, revisions, comparison, newer revisions.
 *
 *  - An ended grant (revoked or expired) with no other basis leaves the design as "access ended":
 *    name and history only.
 *  - Archived designs stay reachable, flagged archived.
 *  - Confidential is returned as a label; it does not restrict the customer, but keeps the design
 *    out of the portfolio and consent questions.
 *  - Components of another design (external dependencies) are returned only when the customer
 *    also has access to them.
 */

dol_include_once('/designtrace/class/clientaccess.class.php');
dol_include_once('/designtrace/class/grant.class.php');
dol_include_once('/designtrace/class/licensing.class.php');
dol_include_once('/designtrace/class/componentlink.class.php');
dol_include_once('/designtrace/class/proofapproval.class.php');
dol_include_once('/designtrace/class/portfolio.class.php');

/**
 * Customer design library
 */
class DesignTraceLibrary
{
	/**
	 * Every revision a customer reaches, grouped by design, with the basis it is reached through
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$socid	Customer id
	 * @return	array<int,array{bases:array<int,array<string,mixed>>,revs:array<int,array<int,int>>,granted:array<int,bool>,ended:bool}>
	 *			By design id: bases (type, id, ref, active), revs (component id => revision ids), granted (component id => true, reached
	 *			through an active grant), ended (only ended grants)
	 */
	public static function fetchAccess($db, $socid)
	{
		$access = array();
		if ((int) $socid <= 0) {
			return $access;
		}
		$add = function ($designid, $compid, $revid, $type, $id, $ref, $active) use (&$access) {
			if (!isset($access[$designid])) {
				$access[$designid] = array('bases' => array(), 'revs' => array(), 'granted' => array(), 'ended' => false);
			}
			$access[$designid]['bases'][$type.'_'.$id] = array('type' => $type, 'id' => (int) $id, 'ref' => (string) $ref, 'active' => $active);
			if ($active && $revid > 0) {
				$access[$designid]['revs'][$compid][$revid] = (int) $revid;
			}
		};

		foreach (DesignTraceClientAccess::getChannels() as $channel => $def) {
			if (!isModEnabled($def['module'])) {
				continue;
			}
			$sql = "SELECT c.fk_design, c.rowid as comp_id, r.rowid as rev_id, d.rowid as doc_id, d.ref as doc_ref";
			$sql .= " FROM ".$db->prefix()."element_element as ee";
			$sql .= " INNER JOIN ".$db->prefix().$def['table']." as d ON d.rowid = ee.fk_source";
			$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = ee.fk_target";
			$sql .= " INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
			$sql .= " LEFT JOIN ".$db->prefix()."designtrace_docaccess as a ON a.fk_revision = r.rowid AND a.element = ee.sourcetype AND a.fk_element = ee.fk_source";
			$sql .= " WHERE ee.sourcetype = '".$db->escape($channel)."' AND ee.targettype = 'designtrace_componentrevision'";
			$sql .= " AND d.fk_soc = ".((int) $socid)." AND d.entity IN (".getEntity($channel == 'commande' ? 'order' : $channel).")";
			$sql .= " AND d.fk_statut NOT IN (".implode(',', array_map('intval', $def['hidden'])).")";
			$sql .= " AND (a.rowid IS NULL OR a.client_visible = 1)";
			$resql = $db->query($sql);
			while ($resql && ($obj = $db->fetch_object($resql))) {
				$add((int) $obj->fk_design, (int) $obj->comp_id, (int) $obj->rev_id, $channel, (int) $obj->doc_id, $obj->doc_ref, true);
			}
		}

		foreach (DesignTraceGrant::fetchForCustomer($db, $socid) as $grant) {
			$ref = 'GRANT-'.$grant->rowid;
			if (empty($grant->pins)) {
				$add((int) $grant->fk_design, 0, 0, 'grant', (int) $grant->rowid, $ref, $grant->active);
			}
			foreach ($grant->pins as $compid => $revid) {
				if ($grant->active) {
					$access[(int) $grant->fk_design]['granted'][(int) $compid] = true;	// A grant opens every release of the component
				}
				$add((int) $grant->fk_design, (int) $compid, (int) $revid, 'grant', (int) $grant->rowid, $ref, $grant->active);
			}
			if (!isset($access[(int) $grant->fk_design]['bases']['grant_'.$grant->rowid])) {
				continue;
			}
			$access[(int) $grant->fk_design]['bases']['grant_'.$grant->rowid]['active'] = (bool) $grant->active;
		}
		foreach ($access as $designid => &$info) {
			$info['ended'] = empty(array_filter($info['bases'], function ($b) {
				return $b['active'];
			}));
		}
		unset($info);
		return $access;
	}

	/**
	 * Revisions delivered to a customer: linked to one of their completed orders, or given through a
	 * grant (llx_designtrace_delivered)
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$socid	Customer id
	 * @return	array<int,int>	Revision id => revision id
	 */
	public static function getDeliveredRevisionIds($db, $socid)
	{
		$ids = array();
		$sql = "SELECT fk_revision as id FROM ".$db->prefix()."designtrace_delivered WHERE fk_soc = ".((int) $socid);
		$sql .= " UNION SELECT ee.fk_target as id FROM ".$db->prefix()."element_element as ee";
		$sql .= " INNER JOIN ".$db->prefix()."commande as o ON o.rowid = ee.fk_source AND o.fk_soc = ".((int) $socid)." AND o.fk_statut = 3";
		$sql .= " WHERE ee.sourcetype = 'commande' AND ee.targettype = 'designtrace_componentrevision'";
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$ids[(int) $obj->id] = (int) $obj->id;
		}
		return $ids;
	}

	/**
	 * Components a customer reaches, with the revision they hold and the current one
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer id
	 * @param	array<int,array<string,mixed>>	$access	From fetchAccess()
	 * @return	array<int,array<string,mixed>>	By component id: design_id, held (revision ids), delivered (revision ids held and
	 *											delivered), delivered_id (latest delivered, 0 none), held_id (latest revision held),
	 *											current_id (latest released, 0 none), newer (bool)
	 */
	public static function fetchComponentStates($db, $socid, $access)
	{
		$delivered = self::getDeliveredRevisionIds($db, $socid);
		$comps = array();
		foreach ($access as $designid => $info) {
			foreach ($info['revs'] as $compid => $revids) {
				if ($compid > 0) {
					$comps[$compid] = array('design_id' => (int) $designid, 'held' => array_values($revids));
				}
			}
		}
		$current = ComponentLink::getCurrentRevisionIds($db, array_keys($comps));
		// A grant opens every release of its components (released or obsolete, never drafts or reviews),
		// delivered or not; a linked document opens only the revision linked to it. What may be opened is
		// still decided file by file
		$granted = array();
		foreach ($comps as $compid => $c) {
			if (!empty($access[$c['design_id']]['granted'][$compid])) {
				$granted[] = $compid;
			}
		}
		$released = array();
		if (!empty($granted)) {
			$resql = $db->query("SELECT rowid, fk_component FROM ".$db->prefix()."designtrace_componentrevision WHERE fk_component IN (".$db->sanitize(implode(',', array_map('intval', $granted))).") AND status IN (".((int) ComponentRevision::STATUS_RELEASED).", ".((int) ComponentRevision::STATUS_OBSOLETE).") AND date_release IS NOT NULL");
			while ($resql && ($obj = $db->fetch_object($resql))) {
				$released[(int) $obj->fk_component][] = (int) $obj->rowid;
			}
		}
		foreach ($comps as $compid => &$state) {
			$state['released'] = $released[$compid] ?? array();
			$held = array_map('intval', $state['held']);
			$state['held'] = $held;
			$state['held_id'] = max($held);
			$state['delivered'] = array_values(array_filter($held, function ($revid) use ($delivered) {
				return isset($delivered[$revid]);
			}));
			$state['delivered_id'] = $state['delivered'] ? max($state['delivered']) : 0;
			$state['current_id'] = isset($current[$compid]) ? (int) $current[$compid] : 0;
			$state['newer'] = ($state['current_id'] > $state['held_id']);
		}
		unset($state);
		return $comps;
	}

	/**
	 * Components with a newer released revision than the one the customer holds (also for pinned grants)
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$socid	Customer id
	 * @return	array<int,array<string,mixed>>	Rows: design_id, design_ref, component_id, component_ref, label, held_revision, held_rev,
	 *											current_revision, current_rev, current_note (customer release note), pinned (held through a grant)
	 */
	public static function fetchNewerRevisions($db, $socid)
	{
		$access = self::fetchAccess($db, $socid);
		$states = self::fetchComponentStates($db, $socid, $access);
		$rows = array();
		foreach ($states as $compid => $state) {
			if (!$state['newer'] || !empty($access[$state['design_id']]['ended'])) {
				continue;
			}
			$sql = "SELECT c.ref as comp_ref, c.label, d.ref as design_ref, h.rev as held_rev, n.rev as cur_rev, n.customer_note";
			$sql .= " FROM ".$db->prefix()."designtrace_component as c";
			$sql .= " INNER JOIN ".$db->prefix()."designtrace_design as d ON d.rowid = c.fk_design";
			$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as h ON h.rowid = ".((int) $state['held_id']);
			$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as n ON n.rowid = ".((int) $state['current_id']);
			$sql .= " WHERE c.rowid = ".((int) $compid);
			$resql = $db->query($sql);
			$obj = $resql ? $db->fetch_object($resql) : null;
			if (!$obj) {
				continue;
			}
			$pinned = false;
			foreach ($access[$state['design_id']]['bases'] as $base) {
				$pinned = $pinned || ($base['type'] === 'grant' && $base['active']);
			}
			$rows[] = array('design_id' => $state['design_id'], 'design_ref' => $obj->design_ref, 'component_id' => (int) $compid, 'component_ref' => $obj->comp_ref,
				'label' => (string) $obj->label, 'held_revision' => (int) $state['held_id'], 'held_rev' => $obj->held_rev, 'current_revision' => (int) $state['current_id'],
				'current_rev' => $obj->cur_rev, 'current_note' => (string) $obj->customer_note, 'pinned' => $pinned);
		}
		return $rows;
	}

	/**
	 * Files the customer can see, grouped by revision (a file reached through several documents once)
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$socid	Customer id
	 * @return	array<int,array<int,array<string,mixed>>>	Revision id => file id => file
	 */
	private static function fetchFilesByRevision($db, $socid)
	{
		$byrev = array();
		$rows = DesignTraceClientAccess::fetchVisibleFiles($db, $socid);
		foreach ((is_array($rows) ? $rows : array()) as $row) {
			if (!isset($byrev[(int) $row->rev_id][(int) $row->file_id])) {
				$byrev[(int) $row->rev_id][(int) $row->file_id] = array('id' => (int) $row->file_id, 'filename' => $row->filename, 'role' => (int) $row->role,
					'format' => (string) $row->format, 'size' => (int) $row->filesize, 'sha256' => (string) $row->sha256, 'via' => array());
			}
			$byrev[(int) $row->rev_id][(int) $row->file_id]['via'][] = array('element' => $row->element, 'doc_id' => (int) $row->doc_id, 'doc_ref' => $row->doc_ref);
		}
		return $byrev;
	}

	/**
	 * Designs of the library, with summary, flags and filters
	 *
	 * @param	DoliDB				$db			Database handler
	 * @param	int					$socid		Customer id
	 * @param	array<string,mixed>	$filters	q (name, DSN or part number), type (part|assembly|drawing), project (id),
	 *											element + docid (reached through that document), design (id), action (1 = needs my action)
	 * @param	int					$page		Page, from 0
	 * @param	int					$limit		Page size (1-200)
	 * @return	array{total:int,page:int,limit:int,items:array<int,array<string,mixed>>}
	 */
	public static function fetchLibrary($db, $socid, $filters = array(), $page = 0, $limit = 25)
	{
		$limit = max(1, min(200, (int) $limit));
		$page = max(0, (int) $page);
		$access = self::fetchAccess($db, $socid);
		if (empty($access)) {
			return array('total' => 0, 'page' => $page, 'limit' => $limit, 'items' => array());
		}
		$states = self::fetchComponentStates($db, $socid, $access);
		$proofs = DesignTraceProofApproval::fetchProofs($db, $socid);
		$proofdesigns = array();
		foreach ($proofs as $proof) {
			if (!empty($proof['can_decide'])) {
				$resql = $db->query("SELECT c.fk_design FROM ".$db->prefix()."designtrace_componentrevision as r INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component WHERE r.rowid = ".((int) $proof['rev_id']));
				$obj = $resql ? $db->fetch_object($resql) : null;
				if ($obj) {
					$proofdesigns[(int) $obj->fk_design] = true;
				}
			}
		}

		dol_include_once('/designtrace/class/approvals.class.php');
		foreach (DesignTraceApprovals::fetchPending($db, $socid) as $entry) {
			if ($entry['awaiting'] > 0) {
				foreach ($entry['design_ids'] as $designid) {
					$proofdesigns[(int) $designid] = true;
				}
			}
		}

		$sql = "SELECT d.rowid, d.ref, d.label, d.status, d.confidential, d.fk_project, d.portfolio_consent, d.ownership, p.ref as project_ref";
		$sql .= " FROM ".$db->prefix()."designtrace_design as d LEFT JOIN ".$db->prefix()."projet as p ON p.rowid = d.fk_project";
		$sql .= " WHERE d.rowid IN (".$db->sanitize(implode(',', array_map('intval', array_keys($access)))).")";
		$resql = $db->query($sql);
		$designs = array();
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$designs[(int) $obj->rowid] = $obj;
		}
		// Components, for search and type filters
		$comps = array();
		$resql = $db->query("SELECT rowid, fk_design, ref, label, type FROM ".$db->prefix()."designtrace_component WHERE fk_design IN (".$db->sanitize(implode(',', array_keys($designs))).")");
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$comps[(int) $obj->fk_design][] = $obj;
		}

		$q = mb_strtolower(trim((string) ($filters['q'] ?? '')));
		$typemap = array('part' => 0, 'assembly' => 1, 'drawing' => 2);
		$items = array();
		foreach ($designs as $id => $d) {
			$info = $access[$id];
			if (!empty($filters['design']) && (int) $filters['design'] !== $id) {
				continue;
			}
			if (!empty($filters['exclude']) && in_array($id, array_map('intval', $filters['exclude']), true)) {
				continue;	// Designs the caller may not see (staff with confidential designs): out before the total and the paging
			}
			if (!empty($filters['element']) && empty(array_filter($info['bases'], function ($b) use ($filters) {
				return $b['type'] === $filters['element'] && (empty($filters['docid']) || $b['id'] === (int) $filters['docid']);
			}))) {
				continue;
			}
			if (!empty($filters['project']) && (int) $d->fk_project !== (int) $filters['project']) {
				continue;
			}
			$reached = array();	// Components the customer reaches in this design
			foreach ($info['revs'] as $compid => $revids) {
				$reached[$compid] = true;
			}
			if (!empty($filters['type']) && isset($typemap[$filters['type']])) {
				$hit = false;
				foreach ($comps[$id] ?? array() as $c) {
					$hit = $hit || ((int) $c->type === $typemap[$filters['type']] && (!empty($info['ended']) || isset($reached[(int) $c->rowid])));
				}
				if (!$hit) {
					continue;
				}
			}
			if ($q !== '') {
				$hit = (strpos(mb_strtolower($d->ref.' '.$d->label), $q) !== false);
				foreach ($comps[$id] ?? array() as $c) {
					$hit = $hit || ((isset($reached[(int) $c->rowid])) && strpos(mb_strtolower($c->ref.' '.$c->label), $q) !== false);
				}
				if (!$hit) {
					continue;
				}
			}

			$ended = !empty($info['ended']);
			$flags = array('licence' => false, 'approval' => false, 'consent' => false, 'newer_revision' => false);
			$counts = array('part' => 0, 'assembly' => 0, 'drawing' => 0);
			if (!$ended) {
				$flags['licence'] = !DesignTraceLicensing::isAccepted($db, $socid, $id, self::firstDocument($info, 'propal', 'commande'), self::firstDocumentId($info, 'propal', 'commande'));
				$flags['approval'] = isset($proofdesigns[$id]);
				$flags['consent'] = ((int) $d->portfolio_consent === DesignTracePortfolio::CONSENT_NONE && empty($d->confidential) && (int) $d->status === Design::STATUS_VALIDATED);
				foreach ($reached as $compid => $unused) {
					if (!empty($states[$compid]['newer'])) {
						$flags['newer_revision'] = true;
					}
				}
				foreach ($comps[$id] ?? array() as $c) {
					if (isset($reached[(int) $c->rowid])) {
						$counts[array_search((int) $c->type, $typemap)]++;
					}
				}
			}
			$needs = $flags['licence'] || $flags['approval'] || $flags['consent'] || $flags['newer_revision'];
			if (!empty($filters['action']) && !$needs) {
				continue;
			}
			$items[] = array(
				'id' => $id,
				'ref' => $d->ref,
				'label' => (string) $d->label,
				'archived' => ((int) $d->status === Design::STATUS_CANCELED),
				'confidential' => !empty($d->confidential),
				'confidential_label' => (!empty($d->confidential) ? 'Confidential' : ''),
				'access_ended' => $ended,
				'project_id' => (int) $d->fk_project,
				'project_ref' => (string) $d->project_ref,
				'bases' => array_values(array_map(function ($b) {
					return array('type' => ($b['type'] == 'propal' ? 'quote' : ($b['type'] == 'commande' ? 'order' : $b['type'])), 'id' => $b['id'], 'ref' => $b['ref'], 'active' => $b['active']);
				}, $info['bases'])),
				'components' => $counts,
				'flags' => $flags,
				'needs_action' => $needs,
			);
		}
		usort($items, function ($a, $b) {
			return strcmp($a['ref'], $b['ref']);
		});
		return array('total' => count($items), 'page' => $page, 'limit' => $limit, 'items' => array_slice($items, $page * $limit, $limit));
	}

	/**
	 * Type of the first quote or order a design is reached through (for the order-level licence override)
	 *
	 * @param	array<string,mixed>	$info		Entry of fetchAccess()
	 * @param	string				...$types	Document types to look for
	 * @return	string				Type, '' if none
	 */
	private static function firstDocument($info, ...$types)
	{
		foreach ($info['bases'] as $b) {
			if ($b['active'] && in_array($b['type'], $types, true)) {
				return $b['type'];
			}
		}
		return '';
	}

	/**
	 * Id of the document found by firstDocument()
	 *
	 * @param	array<string,mixed>	$info		Entry of fetchAccess()
	 * @param	string				...$types	Document types to look for
	 * @return	int
	 */
	private static function firstDocumentId($info, ...$types)
	{
		foreach ($info['bases'] as $b) {
			if ($b['active'] && in_array($b['type'], $types, true)) {
				return (int) $b['id'];
			}
		}
		return 0;
	}

	/**
	 * Detail of a design for a customer: tree of components with current and delivered revisions and
	 * files; for an ended access only the name and the history
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer id
	 * @param	int		$designid	Design id
	 * @return	array<string,mixed>|null	null if the customer never had access
	 */
	public static function fetchDesign($db, $socid, $designid)
	{
		$access = self::fetchAccess($db, $socid);
		if (!isset($access[(int) $designid])) {
			return null;
		}
		$info = $access[(int) $designid];
		$summary = self::fetchLibrary($db, $socid, array('design' => (int) $designid), 0, 1);
		$entry = null;
		foreach ($summary['items'] as $item) {
			if ($item['id'] === (int) $designid) {
				$entry = $item;
			}
		}
		if (!$entry) {
			return null;
		}
		$entry['history'] = self::fetchDesignHistory($db, $socid, (int) $designid);
		if ($entry['access_ended']) {
			$entry['tree'] = array();
			return $entry;
		}

		$states = self::fetchComponentStates($db, $socid, $access);
		$files = self::fetchFilesByRevision($db, $socid);
		$nodes = array();
		$sql = "SELECT c.rowid, c.ref, c.label, c.type, c.drawing_type, c.fk_design, d.ref as design_ref FROM ".$db->prefix()."designtrace_component as c";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_design as d ON d.rowid = c.fk_design";
		$sql .= " WHERE c.rowid IN (".$db->sanitize(implode(',', array_map('intval', array_merge(array(0), array_keys($states))))).")";
		$resql = $db->query($sql);
		$all = array();
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$all[(int) $obj->rowid] = $obj;
		}
		$typenames = array(0 => 'part', 1 => 'assembly', 2 => 'drawing');
		foreach ($all as $compid => $c) {
			$state = $states[$compid];
			// Every revision the customer holds, every release of the component, and the current one, oldest first
			$revids = array_unique(array_filter(array_merge($state['held'], $state['released'], array($state['current_id']))));
			sort($revids);
			$revisions = array();
			foreach ($revids as $revid) {
				$revisions[$revid] = self::describeRevision($db, $revid, $files[$revid] ?? array(), $state);
			}
			$nodes[$compid] = array(
				'id' => $compid,
				'ref' => $c->ref,
				'label' => (string) $c->label,
				'type' => $typenames[(int) $c->type] ?? 'part',
				'drawing_type' => ((int) $c->type === 2 ? (int) $c->drawing_type : null),
				'design_id' => (int) $c->fk_design,
				'design_ref' => $c->design_ref,
				'external' => ((int) $c->fk_design !== (int) $designid),
				'held_revision' => $state['held_id'],
				'delivered_revision' => $state['delivered_id'] ?: null,
				'current_revision' => $state['current_id'] ?: null,
				'newer_revision' => $state['newer'],
				'revisions' => array_values($revisions),
				'children' => array(),
			);
		}
		// Links of the revisions held: assemblies hold parts, drawings depict, others reference. A component of
		// another design only when the customer reaches it too (it is in $nodes)
		$childof = array();
		foreach ($nodes as $compid => $node) {
			$sql = "SELECT l.link_type, cc.rowid as child_comp FROM ".$db->prefix()."designtrace_componentlink as l";
			$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as cr ON cr.rowid = l.fk_child";
			$sql .= " INNER JOIN ".$db->prefix()."designtrace_component as cc ON cc.rowid = cr.fk_component";
			$sql .= " WHERE l.fk_parent = ".((int) $node['held_revision']);
			$resql = $db->query($sql);
			while ($resql && ($obj = $db->fetch_object($resql))) {
				$child = (int) $obj->child_comp;
				if (isset($nodes[$child]) && $child !== $compid) {
					$nodes[$compid]['children'][] = array('component_id' => $child, 'link' => array(1 => 'uses', 2 => 'depicts', 3 => 'references')[(int) $obj->link_type] ?? 'references');
					$childof[$child] = true;
				}
			}
		}
		$roots = array();
		foreach ($nodes as $compid => $node) {
			if (!isset($childof[$compid]) && !$node['external']) {
				$roots[] = $compid;
			}
		}
		$entry['tree'] = array_values($nodes);
		$entry['roots'] = $roots;
		return $entry;
	}

	/**
	 * Revision as shown to a customer
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$revid	Revision id
	 * @param	array<int,array<string,mixed>>	$files	Files the customer sees (by file id)
	 * @param	array<string,mixed>	$state	Component state (fetchComponentStates())
	 * @return	array<string,mixed>
	 */
	private static function describeRevision($db, $revid, $files, $state)
	{
		$resql = $db->query("SELECT rev, ref, status, date_release, customer_note FROM ".$db->prefix()."designtrace_componentrevision WHERE rowid = ".((int) $revid));
		$obj = $resql ? $db->fetch_object($resql) : null;
		return array(
			'id' => (int) $revid,
			'rev' => $obj ? $obj->rev : '',
			'ref' => $obj ? $obj->ref : '',
			'status' => $obj ? (int) $obj->status : 0,
			'date_release' => $obj ? $db->jdate($obj->date_release) : null,
			'release_note' => $obj ? (string) $obj->customer_note : '',
			'is_current' => ((int) $revid === (int) $state['current_id']),
			'is_delivered' => in_array((int) $revid, $state['delivered'], true),
			'files' => array_values($files),
		);
	}

	/**
	 * A revision the customer reaches (or the current one of a component they reach)
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$socid	Customer id
	 * @param	int		$revid	Revision id
	 * @return	array<string,mixed>|null
	 */
	public static function fetchRevision($db, $socid, $revid)
	{
		$access = self::fetchAccess($db, $socid);
		$states = self::fetchComponentStates($db, $socid, $access);
		$resql = $db->query("SELECT fk_component FROM ".$db->prefix()."designtrace_componentrevision WHERE rowid = ".((int) $revid));
		$obj = $resql ? $db->fetch_object($resql) : null;
		if (!$obj || !isset($states[(int) $obj->fk_component]) || !empty($access[$states[(int) $obj->fk_component]['design_id']]['ended'])) {
			return null;
		}
		$state = $states[(int) $obj->fk_component];
		$isheld = in_array((int) $revid, $state['held'], true);
		if (!$isheld && (int) $revid !== (int) $state['current_id'] && !in_array((int) $revid, $state['released'], true)) {
			return null;
		}
		$files = self::fetchFilesByRevision($db, $socid);
		$rev = self::describeRevision($db, (int) $revid, $isheld ? ($files[(int) $revid] ?? array()) : array(), $state);
		$rev['component_id'] = (int) $obj->fk_component;
		$rev['design_id'] = $state['design_id'];
		return $rev;
	}

	/**
	 * Differences between two revisions of a component (default: the delivered one and the current one)
	 *
	 * Files are matched by name without the component prefix. Files of a revision the customer does
	 * not hold are compared by name and hash only when they are visible files (not hidden, not
	 * production), so unshared files are never revealed.
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer id
	 * @param	int		$componentid	Component id
	 * @param	int		$fromid		Older revision (0 = the delivered one, else the held one)
	 * @param	int		$toid		Newer revision (0 = the current one)
	 * @return	array<string,mixed>|null	null if not reachable or nothing to compare
	 */
	public static function compareRevisions($db, $socid, $componentid, $fromid = 0, $toid = 0)
	{
		$access = self::fetchAccess($db, $socid);
		$states = self::fetchComponentStates($db, $socid, $access);
		if (!isset($states[(int) $componentid]) || !empty($access[$states[(int) $componentid]['design_id']]['ended'])) {
			return null;
		}
		$state = $states[(int) $componentid];
		$fromid = (int) $fromid ?: ($state['delivered_id'] ?: $state['held_id']);
		$toid = (int) $toid ?: $state['current_id'];
		if ($fromid <= 0 || $toid <= 0) {
			return null;
		}
		foreach (array($fromid, $toid) as $revid) {
			$resql = $db->query("SELECT rowid FROM ".$db->prefix()."designtrace_componentrevision WHERE rowid = ".((int) $revid)." AND fk_component = ".((int) $componentid));
			if (!$resql || !$db->num_rows($resql) || (!in_array($revid, $state['held'], true) && $revid !== (int) $state['current_id'] && !in_array($revid, $state['released'], true))) {
				return null;
			}
		}
		// Metadata only for what the customer could download: source files need a licence that allows them
		// and a design that is not workshop-owned, "on completion" files need the revision to be delivered
		$sourceok = DesignTraceLicensing::allowsSourceFiles($db, '', 0, (int) $state['design_id'], (int) $socid);
		$delivered = self::getDeliveredRevisionIds($db, $socid);
		$side = function ($revid) use ($db, $state, $sourceok, $delivered) {
			$out = array();
			$sql = "SELECT f.filename, f.sha256, f.filesize, r.rev, c.ref as comp_ref FROM ".$db->prefix()."designtrace_revisionfile as f";
			$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = f.fk_revision";
			$sql .= " INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
			$sql .= " WHERE f.fk_revision = ".((int) $revid)." AND f.role IN (".((int) RevisionFile::ROLE_PRESENTATION).", ".((int) RevisionFile::ROLE_SOURCE).")";
			$sql .= " AND f.visibility <> ".((int) RevisionFile::VISIBILITY_HIDDEN);
			if (!$sourceok) {
				$sql .= " AND f.role = ".((int) RevisionFile::ROLE_PRESENTATION);
			}
			if (!isset($delivered[(int) $revid])) {
				$sql .= " AND f.visibility = ".((int) RevisionFile::VISIBILITY_NOW);
			}
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
		return array(
			'component_id' => (int) $componentid,
			'from' => self::describeRevision($db, $fromid, array(), $state),
			'to' => self::describeRevision($db, $toid, array(), $state),
			'files' => $files,
		);
	}

	/**
	 * Grants and downloads of a design for a customer (what an ended access still shows)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer id
	 * @param	int		$designid	Design id
	 * @return	array<int,array<string,mixed>>	Newest first: date, type (grant_given, grant_ended, download), text, actor
	 */
	public static function fetchDesignHistory($db, $socid, $designid)
	{
		$events = array();
		foreach (DesignTraceGrant::fetchForCustomer($db, $socid, $designid) as $grant) {
			$resql = $db->query("SELECT date_creation FROM ".$db->prefix()."designtrace_grant WHERE rowid = ".((int) $grant->rowid));
			$obj = $resql ? $db->fetch_object($resql) : null;
			$events[] = array('date' => $obj ? $db->jdate($obj->date_creation) : 0, 'type' => 'grant_given', 'text' => 'GRANT-'.$grant->rowid, 'actor' => '');
			if (!empty($grant->date_revoked)) {
				$events[] = array('date' => (int) $grant->date_revoked, 'type' => 'grant_revoked', 'text' => 'GRANT-'.$grant->rowid, 'actor' => '');
			} elseif (!$grant->active) {
				$events[] = array('date' => (int) $grant->date_expiry, 'type' => 'grant_expired', 'text' => 'GRANT-'.$grant->rowid, 'actor' => '');
			}
		}
		$sql = "SELECT dl.filename, dl.actor, dl.date_download FROM ".$db->prefix()."designtrace_download as dl";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = dl.fk_revision";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
		$sql .= " WHERE dl.fk_soc = ".((int) $socid)." AND c.fk_design = ".((int) $designid)." AND dl.channel <> '".$db->escape(DesignTraceClientAccess::CHANNEL_ADMIN_PREVIEW)."' ORDER BY dl.date_download DESC, dl.rowid DESC".$db->plimit(100);
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$events[] = array('date' => $db->jdate($obj->date_download), 'type' => 'download', 'text' => $obj->filename, 'actor' => (string) $obj->actor);
		}
		usort($events, function ($a, $b) {
			return $b['date'] <=> $a['date'];
		});
		return $events;
	}

	/**
	 * What waits for the customer: licences to accept, approvals, consent questions, newer revisions
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$socid	Customer id
	 * @return	array{licences:int,approvals:int,consent:int,newer_revisions:int,total:int,designs:array<string,int[]>}
	 *			Counts (licences, consent: designs; approvals: ECOs or designs awaiting a decision, and proofs shown through
	 *			documents that are not flagged; newer_revisions: components) and the design ids behind licences, approvals, consent
	 */
	public static function fetchAttention($db, $socid)
	{
		dol_include_once('/designtrace/class/approvals.class.php');
		$items = array();
		for ($page = 0; ; $page++) {
			$library = self::fetchLibrary($db, $socid, array(), $page, 200);
			$items = array_merge($items, $library['items']);
			if (count($items) >= $library['total'] || empty($library['items'])) {
				break;
			}
		}
		$designs = array('licences' => array(), 'approvals' => array(), 'consent' => array());
		foreach ($items as $item) {
			foreach (array('licences' => 'licence', 'consent' => 'consent') as $key => $flag) {
				if ($item['flags'][$flag]) {
					$designs[$key][] = $item['id'];
				}
			}
		}
		$approvals = 0;
		foreach (DesignTraceApprovals::fetchPending($db, $socid) as $entry) {
			if ($entry['awaiting'] > 0) {
				$approvals++;
				$designs['approvals'] = array_merge($designs['approvals'], $entry['design_ids']);
			}
		}
		// Proofs shown through a document that are not flagged still wait for a decision (the per-revision flow)
		foreach (DesignTraceProofApproval::fetchProofs($db, $socid) as $proof) {
			if (empty($proof['can_decide']) || (int) $proof['decision'] !== DesignTraceProofApproval::DECISION_NONE) {
				continue;
			}
			$resql = $db->query("SELECT r.needs_approval, c.fk_design FROM ".$db->prefix()."designtrace_componentrevision as r INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component WHERE r.rowid = ".((int) $proof['rev_id']));
			$obj = $resql ? $db->fetch_object($resql) : null;
			if ($obj && empty($obj->needs_approval)) {
				$approvals++;
				$designs['approvals'][] = (int) $obj->fk_design;
			}
		}
		$newer = count(self::fetchNewerRevisions($db, $socid));
		foreach ($designs as $key => $ids) {
			$designs[$key] = array_values(array_unique(array_map('intval', $ids)));
		}
		$counts = array('licences' => count($designs['licences']), 'approvals' => $approvals, 'consent' => count($designs['consent']), 'newer_revisions' => $newer);
		return $counts + array('total' => array_sum($counts), 'designs' => $designs);
	}

	/**
	 * History of a customer: releases, approvals, licence acceptances, consent and downloads, newest
	 * first, each naming the actor. For a customer with several users it covers every one of them.
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer id
	 * @param	int		$designid	Only this design (0 = all the customer reaches or reached)
	 * @param	int		$limit		Max entries (1-500)
	 * @return	array<int,array<string,mixed>>	date, type (release, approval, changes_requested, licence_accepted, consent, download),
	 *											design_id, design_ref, text, actor
	 */
	public static function fetchHistory($db, $socid, $designid = 0, $limit = 100)
	{
		$limit = max(1, min(500, (int) $limit));
		$access = self::fetchAccess($db, $socid);
		if ($designid > 0) {
			$access = isset($access[(int) $designid]) ? array((int) $designid => $access[(int) $designid]) : array();
		}
		if (empty($access)) {
			return array();
		}
		$in = $db->sanitize(implode(',', array_map('intval', array_keys($access))));
		$refs = array();
		$resql = $db->query("SELECT rowid, ref FROM ".$db->prefix()."designtrace_design WHERE rowid IN (".$in.")");
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$refs[(int) $obj->rowid] = $obj->ref;
		}
		$events = array();
		$add = function ($date, $type, $designid, $text, $actor) use (&$events, $refs) {
			$events[] = array('date' => (int) $date, 'type' => $type, 'design_id' => (int) $designid, 'design_ref' => $refs[(int) $designid] ?? '', 'text' => (string) $text, 'actor' => (string) $actor);
		};

		// Releases of the revisions the customer holds and of newer ones (they are told those exist), never
		// older revisions they were not given; no staff login
		$states = self::fetchComponentStates($db, $socid, $access);
		if (!empty($states)) {
			$resql = $db->query("SELECT r.rowid, r.ref, r.date_release, r.fk_component, c.fk_design FROM ".$db->prefix()."designtrace_componentrevision as r INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component WHERE r.fk_component IN (".$db->sanitize(implode(',', array_map('intval', array_keys($states)))).") AND r.status IN (".((int) ComponentRevision::STATUS_RELEASED).", ".((int) ComponentRevision::STATUS_OBSOLETE).") AND r.date_release IS NOT NULL");
			while ($resql && ($obj = $db->fetch_object($resql))) {
				$state = $states[(int) $obj->fk_component];
				if (in_array((int) $obj->rowid, array_map('intval', $state['held']), true) || (int) $obj->rowid > (int) $state['held_id']) {
					$add($db->jdate($obj->date_release), 'release', $obj->fk_design, $obj->ref, '');
				}
			}
		}
		$sql = "SELECT a.decision, a.comment, a.actor, a.date_decision, c.fk_design, r.ref FROM ".$db->prefix()."designtrace_approval as a";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = a.fk_revision INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
		$sql .= " WHERE a.fk_soc = ".((int) $socid)." AND c.fk_design IN (".$in.")";
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$add($db->jdate($obj->date_decision), ((int) $obj->decision === DesignTraceProofApproval::DECISION_APPROVED ? 'approval' : 'changes_requested'), $obj->fk_design, $obj->ref.($obj->comment ? ': '.$obj->comment : ''), $obj->actor);
		}
		$sql = "SELECT a.fk_design, a.actor, a.date_accept, v.ref FROM ".$db->prefix()."designtrace_licenceaccept as a LEFT JOIN ".$db->prefix()."designtrace_licenceversion as v ON v.rowid = a.fk_licenceversion";
		$sql .= " WHERE a.fk_soc = ".((int) $socid)." AND a.fk_design IN (".$in.")";
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$add($db->jdate($obj->date_accept), 'licence_accepted', $obj->fk_design, $obj->ref, $obj->actor);
		}
		// Consent is recorded per design, not per customer: only answers given for this customer (the design is
		// made for them, or the document it was given on is theirs) are shown, and the actor only on their own document
		$mine = "(cs.element = 'propal' AND cs.fk_element IN (SELECT rowid FROM ".$db->prefix()."propal WHERE fk_soc = ".((int) $socid).")) OR (cs.element = 'commande' AND cs.fk_element IN (SELECT rowid FROM ".$db->prefix()."commande WHERE fk_soc = ".((int) $socid)."))";
		$sql = "SELECT cs.fk_design, cs.consent, cs.actor, cs.channel, cs.date_consent, (CASE WHEN ".$mine." THEN 1 ELSE 0 END) as mine FROM ".$db->prefix()."designtrace_consent as cs";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_design as dd ON dd.rowid = cs.fk_design";
		$sql .= " WHERE cs.fk_design IN (".$in.") AND (dd.fk_soc = ".((int) $socid)." OR ".$mine.")";
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$add($db->jdate($obj->date_consent), 'consent', $obj->fk_design, ((int) $obj->consent === DesignTracePortfolio::CONSENT_GRANTED ? 'granted' : 'refused'), (!empty($obj->mine) && $obj->channel !== DesignTracePortfolio::CHANNEL_BACKOFFICE ? $obj->actor : ''));
		}
		$sql = "SELECT dl.filename, dl.actor, dl.date_download, c.fk_design FROM ".$db->prefix()."designtrace_download as dl";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = dl.fk_revision INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
		$sql .= " WHERE dl.fk_soc = ".((int) $socid)." AND c.fk_design IN (".$in.") AND dl.channel <> '".$db->escape(DesignTraceClientAccess::CHANNEL_ADMIN_PREVIEW)."'";	// Staff previews are not the customer's downloads
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$add($db->jdate($obj->date_download), 'download', $obj->fk_design, $obj->filename, $obj->actor);
		}
		usort($events, function ($a, $b) {
			return $b['date'] <=> $a['date'];
		});
		return array_slice($events, 0, $limit);
	}
}
