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
 * \file        designtrace/class/customerportal.class.php
 * \ingroup     designtrace
 * \brief       What a customer sees and can do, for every customer portal
 *
 * The REST API (My workshop, the main portal) and Dolibarr's Web portal both go through this class,
 * so they show the same documents and allow the same actions. It builds the customer's view, one
 * entry per quote, order or ticket that has something for them: visible files, licences, proofs
 * to decide, designs to ask gallery consent for, whether the order can be reordered. And it runs
 * the customer actions that need a check of the customer first (consent, reorder, licence copy).
 * File visibility (DesignTraceClientAccess), licences (DesignTraceLicensing), proofs
 * (DesignTraceProofApproval), consent (DesignTracePortfolio) and reorders (DesignTraceReorder)
 * keep their own rules; this class only puts them together for one customer.
 */

dol_include_once('/designtrace/class/clientaccess.class.php');
dol_include_once('/designtrace/class/licensing.class.php');
dol_include_once('/designtrace/class/portfolio.class.php');
dol_include_once('/designtrace/class/proofapproval.class.php');
dol_include_once('/designtrace/class/reorder.class.php');

/**
 * Customer view and customer actions, shared by the portals
 */
class DesignTraceCustomerPortal
{
	/**
	 * The customer's documents, newest first
	 *
	 * A document is listed when it has visible files or proofs (quotes, orders, tickets), or designs
	 * (quotes and orders: licences and gallery consent). Draft and cancelled documents never are.
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer id
	 * @param	string	$element	Only this kind of document (propal, commande, ticket), '' = all
	 * @param	int		$docid		Only this document
	 * @return	array<string,array<string,mixed>>	By "element_id": element, doc_id, doc_ref, doc_status, date,
	 *						files (rows of fetchVisibleFiles()), licences (fetchDocumentLicences() entries with a
	 *						current or accepted version), proofs (fetchProofs() entries), designs (non-confidential
	 *						designs of the document, for consent), reorder (orders: possible, pending_quote_id)
	 */
	public static function fetchDocuments($db, $socid, $element = '', $docid = 0)
	{
		$docs = array();
		if ((int) $socid <= 0) {
			return $docs;
		}
		$channels = DesignTraceClientAccess::getChannels();
		$new = function ($el, $id, $ref) {
			return array('element' => $el, 'doc_id' => (int) $id, 'doc_ref' => (string) $ref, 'doc_status' => 0, 'date' => null,
				'files' => array(), 'licences' => array(), 'proofs' => array(), 'designs' => array(), 'reorder' => null);
		};

		$rows = DesignTraceClientAccess::fetchVisibleFiles($db, $socid, $element, $docid);
		foreach ((is_array($rows) ? $rows : array()) as $row) {
			$key = $row->element.'_'.$row->doc_id;
			if (!isset($docs[$key])) {
				$docs[$key] = $new($row->element, $row->doc_id, $row->doc_ref);
			}
			$docs[$key]['files'][] = $row;
		}
		foreach (DesignTraceProofApproval::fetchProofs($db, $socid, $element, $docid) as $proof) {
			$docs[$proof['element'].'_'.$proof['doc_id']]['proofs'][] = $proof;	// Proofs come from visible files: the entry exists
		}

		// Quotes and orders with designs: licences and gallery consent
		foreach (DesignTraceLicensing::getDocumentTypes() as $el => $def) {
			if (($element !== '' && $element !== $el) || !isModEnabled($channels[$el]['module'])) {
				continue;
			}
			$sql = "SELECT DISTINCT d.rowid, d.ref FROM ".$db->prefix()."element_element as ee";
			$sql .= " INNER JOIN ".$db->prefix().$def['table']." as d ON d.rowid = ee.fk_source";
			$sql .= " WHERE ee.sourcetype = '".$db->escape($el)."' AND ee.targettype IN ('designtrace_design', 'designtrace_componentrevision')";
			$sql .= " AND d.fk_soc = ".((int) $socid)." AND d.fk_statut NOT IN (".implode(',', array_map('intval', $channels[$el]['hidden'])).")";
			$sql .= " AND d.entity IN (".getEntity($el == 'commande' ? 'order' : $el).")";
			if ((int) $docid > 0) {
				$sql .= " AND d.rowid = ".((int) $docid);
			}
			$resql = $db->query($sql);
			while ($resql && ($obj = $db->fetch_object($resql))) {
				$licences = array_filter(DesignTraceLicensing::fetchDocumentLicences($db, $el, (int) $obj->rowid), function ($info) {
					return $info['licence'] && $info['version'];
				});
				$designs = array_filter(DesignTraceLicensing::fetchDesignsOfDocument($db, $el, (int) $obj->rowid), function ($design) {
					return empty($design['confidential']);
				});
				$key = $el.'_'.$obj->rowid;
				if (!$licences && !$designs && !isset($docs[$key])) {
					continue;
				}
				if (!isset($docs[$key])) {
					$docs[$key] = $new($el, $obj->rowid, $obj->ref);
				}
				$docs[$key]['licences'] = $licences;
				$docs[$key]['designs'] = $designs;
			}
		}

		// Status, date, reorder
		foreach ($docs as $key => $doc) {
			$table = $channels[$doc['element']]['table'];
			$datefield = ($doc['element'] == 'ticket' ? 'datec' : ($doc['element'] == 'propal' ? 'datep' : 'date_commande'));
			$resql = $db->query("SELECT fk_statut, ".$datefield." as docdate FROM ".$db->prefix().$table." WHERE rowid = ".((int) $doc['doc_id']));
			$obj = $resql ? $db->fetch_object($resql) : null;
			$docs[$key]['doc_status'] = $obj ? (int) $obj->fk_statut : 0;
			$docs[$key]['date'] = $obj ? $db->jdate($obj->docdate) : null;
			if ($doc['element'] == 'commande' && isModEnabled('propal')) {
				$order = new Commande($db);
				$order->fetch($doc['doc_id']);
				$docs[$key]['reorder'] = array(
					'possible' => DesignTraceReorder::canReorder($db, $order),
					'pending_quote_id' => DesignTraceReorder::getPendingQuote($db, (int) $doc['doc_id']),
				);
			}
		}
		uasort($docs, function ($a, $b) {
			return array((int) $b['date'], $b['doc_id']) <=> array((int) $a['date'], $a['doc_id']);
		});
		return $docs;
	}

	/**
	 * Quote or order of this customer that it can act on (not draft, not cancelled)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer id
	 * @param	string	$element	propal or commande
	 * @param	int		$docid		Document id
	 * @return	CommonObject|null
	 */
	public static function fetchCustomerDocument($db, $socid, $element, $docid)
	{
		$doc = DesignTraceLicensing::fetchDocument($db, $element, (int) $docid);
		return ($doc && (int) $doc->socid === (int) $socid && (int) $doc->status > 0) ? $doc : null;
	}

	/**
	 * Gallery consent given by the customer for a design of one of their quotes or orders
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer id
	 * @param	string	$element	propal or commande
	 * @param	int		$docid		Document id
	 * @param	int		$designid	Design id (must be on the document, not confidential)
	 * @param	int		$consent	DesignTracePortfolio::CONSENT_GRANTED or CONSENT_REFUSED
	 * @param	string	$channel	DesignTracePortfolio::CHANNEL_WEBPORTAL or CHANNEL_API
	 * @param	string	$actor		Who answered
	 * @param	?string	$ip			Customer's IP as seen by a proxying backend, null = this request's
	 * @param	string	$error		Error code if KO: 'document' (not found), 'design' (not on it), 'consent' (bad value), 'db'
	 * @return	int					>0 changed, 0 already the answer, <0 if KO
	 */
	public static function setConsent($db, $socid, $element, $docid, $designid, $consent, $channel, $actor, $ip, &$error)
	{
		$error = '';
		$doc = self::fetchCustomerDocument($db, $socid, $element, $docid);
		if (!$doc) {
			$error = 'document';
			return -1;
		}
		$designs = DesignTraceLicensing::fetchDesignsOfDocument($db, $element, (int) $docid);
		if (!isset($designs[(int) $designid]) || !empty($designs[(int) $designid]['confidential'])) {
			$error = 'design';
			return -1;
		}
		if (!in_array((int) $consent, array(DesignTracePortfolio::CONSENT_GRANTED, DesignTracePortfolio::CONSENT_REFUSED), true)) {
			$error = 'consent';
			return -1;
		}
		$res = DesignTracePortfolio::setConsent($db, (int) $designid, (int) $consent, $channel, $actor, $element, (int) $docid, 0, '', $ip);
		if ($res < 0) {
			$error = 'db';
		}
		return $res;
	}

	/**
	 * Reorder requested by the customer for one of their delivered orders
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	User	$user		User acting for the customer (portal user, API user)
	 * @param	int		$socid		Customer id
	 * @param	int		$orderid	Order id
	 * @param	string	$channel	webportal or api
	 * @param	string	$actor		Who asked
	 * @param	string	$error		Error message if KO; 'notfound' when the order is not the customer's
	 * @return	int					Quote id (new, or the pending one), <0 if KO
	 */
	public static function reorder($db, $user, $socid, $orderid, $channel, $actor, &$error)
	{
		$error = '';
		$order = new Commande($db);
		if (!isModEnabled('propal') || $order->fetch((int) $orderid) <= 0 || (int) $order->socid !== (int) $socid) {
			$error = 'notfound';
			return -1;
		}
		return DesignTraceReorder::createQuote($db, $user, $order, $channel, $actor, $error);
	}

	/**
	 * The customer's copy of an accepted licence (PDF written at acceptance)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer id
	 * @param	string	$element	propal or commande
	 * @param	int		$docid		Document id
	 * @param	int		$designid	Design id
	 * @return	array{path:string,filename:string,version_ref:string,date:int}|null	null if not accepted or file missing
	 */
	public static function getAcceptancePdf($db, $socid, $element, $docid, $designid)
	{
		global $conf;

		$doc = self::fetchCustomerDocument($db, $socid, $element, $docid);
		if (!$doc) {
			return null;
		}
		$licences = DesignTraceLicensing::fetchDocumentLicences($db, $element, (int) $docid);
		$info = $licences[(int) $designid] ?? null;
		if (!$info || empty($info['acceptance']) || empty($info['acceptance']->filename) || (int) $info['acceptance']->fk_soc !== (int) $socid) {
			return null;
		}
		$module = ($element == 'propal' ? 'propal' : 'commande');
		$entity = (empty($doc->entity) ? $conf->entity : $doc->entity);
		$path = $conf->$module->multidir_output[$entity].'/'.dol_sanitizeFileName($doc->ref).'/'.$info['acceptance']->filename;
		if (!is_file(dol_osencode($path))) {
			return null;
		}
		return array('path' => $path, 'filename' => $info['acceptance']->filename, 'version_ref' => $info['version'] ? $info['version']->ref : '', 'date' => (int) $info['acceptance']->date_accept);
	}
}
