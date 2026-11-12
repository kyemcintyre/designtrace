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
 * \file        designtrace/class/clientaccess.class.php
 * \ingroup     designtrace
 * \brief       What a customer can see and download, for both portals, and the download log
 *
 * One set of rules serves Dolibarr's Web portal and the REST API (My workshop), so they cannot
 * disagree. A customer sees a file when all of these hold:
 *  - the file's revision is linked to one of the customer's quotes, orders or tickets
 *    (not a draft or cancelled document),
 *  - that link is client-visible (llx_designtrace_docaccess, no row = visible),
 *  - the file is a presentation file, or a source file when the licence that counts for its
 *    design on that quote or order allows source files (DesignTraceLicensing); production never,
 *  - the file is "Visible now", or "Visible on completion" and the document is a delivered
 *    (closed) order.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
dol_include_once('/designtrace/class/componentrevision.class.php');
dol_include_once('/designtrace/class/revisionfile.class.php');

/**
 * Client visibility rules, signed download links and the download log
 */
class DesignTraceClientAccess
{
	const CHANNEL_WEBPORTAL = 'webportal';
	const CHANNEL_API = 'api';

	/**
	 * Default lifetime of a signed download link, in seconds
	 */
	const DEFAULT_LINK_TTL = 300;

	/**
	 * Documents through which customers get files
	 *
	 * @return	array<string,array{table:string,hidden:int[],completed:int[],label:string,picto:string,module:string}>
	 *			hidden: document statuses the customer never sees files through,
	 *			completed: statuses that release "Visible on completion" files
	 */
	public static function getChannels()
	{
		return array(
			'propal' => array('table' => 'propal', 'hidden' => array(-1, 0), 'completed' => array(), 'label' => 'Proposal', 'picto' => 'propal', 'module' => 'propal'),
			'commande' => array('table' => 'commande', 'hidden' => array(-1, 0), 'completed' => array(3), 'label' => 'Order', 'picto' => 'order', 'module' => 'order'),
			'ticket' => array('table' => 'ticket', 'hidden' => array(9), 'completed' => array(), 'label' => 'Ticket', 'picto' => 'ticket', 'module' => 'ticket'),
		);
	}

	/**
	 * Normalise a document type given by a caller (API: quote, proposal, order...)
	 *
	 * @param	string	$element	Element name
	 * @return	string				Key of getChannels(), or '' if unknown
	 */
	public static function normaliseElement($element)
	{
		$map = array('propal' => 'propal', 'proposal' => 'propal', 'quote' => 'propal', 'commande' => 'commande', 'order' => 'commande', 'ticket' => 'ticket');
		$element = strtolower(trim((string) $element));
		return isset($map[$element]) ? $map[$element] : '';
	}

	/**
	 * Whether files of this role can ever be shown to a customer
	 *
	 * @param	int		$role	RevisionFile::ROLE_*
	 * @return	bool
	 */
	public static function isRoleShareable($role)
	{
		// Production files never; source files only where the licence allows them (checked per
		// document in fetchVisibleFiles())
		return in_array((int) $role, array(RevisionFile::ROLE_PRESENTATION, RevisionFile::ROLE_SOURCE), true);
	}

	/**
	 * Files a customer can see, one row per file and document it is visible through
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer (third party) id
	 * @param	string	$element	Only through this kind of document (key of getChannels()), '' for all
	 * @param	int		$docid		Only through this document
	 * @param	int		$fileid		Only this file
	 * @return	stdClass[]|int		Rows (file_id, filename, role, format, filesize, sha256, visibility, rev_id, rev_ref, rev,
	 *								rev_status, date_release, comp_id, comp_ref, comp_label, comp_type, drawing_type, design_id, element, doc_id, doc_ref,
	 *								doc_status), or -1 on error
	 */
	public static function fetchVisibleFiles($db, $socid, $element = '', $docid = 0, $fileid = 0)
	{
		$rows = array();
		if ((int) $socid <= 0) {
			return $rows;
		}
		foreach (self::getChannels() as $channel => $def) {
			if (($element !== '' && $element !== $channel) || !isModEnabled($def['module'])) {
				continue;
			}
			$completed = empty($def['completed']) ? '0 = 1' : 'd.fk_statut IN ('.implode(',', array_map('intval', $def['completed'])).')';
			$sql = "SELECT f.rowid as file_id, f.filename, f.role, f.format, f.filesize, f.sha256, f.visibility,";
			$sql .= " r.rowid as rev_id, r.ref as rev_ref, r.rev, r.status as rev_status, r.date_release,";
			$sql .= " c.rowid as comp_id, c.ref as comp_ref, c.label as comp_label, c.type as comp_type, c.drawing_type, c.fk_design as design_id,";
			$sql .= " ee.sourcetype as element, d.rowid as doc_id, d.ref as doc_ref, d.fk_statut as doc_status";
			$sql .= " FROM ".$db->prefix()."element_element as ee";
			$sql .= " INNER JOIN ".$db->prefix().$def['table']." as d ON d.rowid = ee.fk_source";
			$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as r ON r.rowid = ee.fk_target";
			$sql .= " INNER JOIN ".$db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
			$sql .= " INNER JOIN ".$db->prefix()."designtrace_revisionfile as f ON f.fk_revision = r.rowid";
			$sql .= " LEFT JOIN ".$db->prefix()."designtrace_docaccess as a ON a.fk_revision = r.rowid AND a.element = ee.sourcetype AND a.fk_element = ee.fk_source";
			$sql .= " WHERE ee.sourcetype = '".$db->escape($channel)."' AND ee.targettype = 'designtrace_componentrevision'";
			$sql .= " AND d.fk_soc = ".((int) $socid);
			$sql .= " AND d.entity IN (".getEntity($channel == 'commande' ? 'order' : $channel).")";
			$sql .= " AND d.fk_statut NOT IN (".implode(',', array_map('intval', $def['hidden'])).")";
			$sql .= " AND (a.rowid IS NULL OR a.client_visible = 1)";
			$sql .= " AND f.role IN (".((int) RevisionFile::ROLE_PRESENTATION).", ".((int) RevisionFile::ROLE_SOURCE).")";
			$sql .= " AND (f.visibility = ".((int) RevisionFile::VISIBILITY_NOW)." OR (f.visibility = ".((int) RevisionFile::VISIBILITY_ON_COMPLETION)." AND ".$completed."))";
			if ((int) $docid > 0) {
				$sql .= " AND d.rowid = ".((int) $docid);
			}
			if ((int) $fileid > 0) {
				$sql .= " AND f.rowid = ".((int) $fileid);
			}
			$sql .= " ORDER BY d.rowid DESC, c.ref, r.rowid, f.filename";
			$resql = $db->query($sql);
			if (!$resql) {
				dol_syslog(__METHOD__.' '.$db->lasterror(), LOG_ERR);
				return -1;
			}
			while ($obj = $db->fetch_object($resql)) {
				if ((int) $obj->role === RevisionFile::ROLE_SOURCE) {
					dol_include_once('/designtrace/class/licensing.class.php');
					if (!DesignTraceLicensing::allowsSourceFiles($db, $channel, (int) $obj->doc_id, (int) $obj->design_id)) {
						continue;
					}
				}
				$rows[] = $obj;
			}
			$db->free($resql);
		}

		return $rows;
	}

	/**
	 * The file if this customer may download it now (rules checked again at download time)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$socid		Customer id
	 * @param	int		$fileid		RevisionFile id
	 * @param	string	$element	Preferred document type it is downloaded through ('' = any)
	 * @param	int		$docid		Preferred document id (0 = any)
	 * @return	stdClass|null		Row as in fetchVisibleFiles(), null if not allowed
	 */
	public static function getDownloadableFile($db, $socid, $fileid, $element = '', $docid = 0)
	{
		if ((int) $fileid <= 0) {
			return null;
		}
		$rows = self::fetchVisibleFiles($db, $socid, $element, $docid, $fileid);
		if (is_array($rows) && !empty($rows)) {
			return $rows[0];
		}
		return null;
	}

	/**
	 * Full path of a file on disk
	 *
	 * @param	DoliDB		$db		Database handler
	 * @param	stdClass	$row	Row from fetchVisibleFiles()
	 * @return	string				Path, '' if the revision cannot be loaded
	 */
	public static function getFilePath($db, $row)
	{
		$revision = new ComponentRevision($db);
		if ($revision->fetch((int) $row->rev_id) <= 0) {
			return '';
		}
		return $revision->getFilesDir().'/'.$row->filename;
	}

	/**
	 * Record a customer download
	 *
	 * @param	DoliDB		$db			Database handler
	 * @param	stdClass	$row		Row from fetchVisibleFiles()
	 * @param	int			$socid		Customer id
	 * @param	string		$channel	self::CHANNEL_*
	 * @param	string		$actor		Who downloaded (portal login, contact passed by the API...)
	 * @return	int						Id of the log row, <0 on error
	 */
	public static function logDownload($db, $row, $socid, $channel, $actor = '')
	{
		global $conf;

		$sql = "INSERT INTO ".$db->prefix()."designtrace_download";
		$sql .= " (entity, fk_revisionfile, fk_revision, filename, sha256, fk_soc, element, fk_element, channel, actor, ip, useragent, date_download)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $row->file_id).", ".((int) $row->rev_id);
		$sql .= ", '".$db->escape($row->filename)."', '".$db->escape((string) $row->sha256)."'";
		$sql .= ", ".((int) $socid).", '".$db->escape((string) $row->element)."', ".((int) $row->doc_id);
		$sql .= ", '".$db->escape($channel)."', '".$db->escape(dol_trunc((string) $actor, 128, 'right', 'UTF-8', 1))."'";
		$sql .= ", '".$db->escape(getUserRemoteIP())."', '".$db->escape(dol_trunc((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 255, 'right', 'UTF-8', 1))."'";
		$sql .= ", '".$db->idate(dol_now())."')";
		if (!$db->query($sql)) {
			dol_syslog(__METHOD__.' '.$db->lasterror(), LOG_ERR);
			return -1;
		}
		return (int) $db->last_insert_id($db->prefix().'designtrace_download');
	}

	/**
	 * Send a file to the browser and stop
	 *
	 * @param	string	$fullpath	Full path on disk
	 * @param	string	$filename	Name given to the browser
	 * @param	bool	$inline		Shown in place (images, viewers) rather than saved
	 * @return	void
	 */
	public static function sendFile($fullpath, $filename, $inline = false)
	{
		$osfile = dol_osencode($fullpath);
		if (!is_file($osfile)) {
			http_response_code(404);
			print 'File not found';
			exit;
		}
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		$filename = str_replace(array('"', "\r", "\n"), '', basename($filename));
		$type = dol_mimetype($filename);
		if (!dolIsAllowedForPreview($filename)) {
			$type = 'application/octet-stream';
		}
		header('Content-Type: '.$type);
		header('Content-Description: File Transfer');
		header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename="'.$filename.'"; filename*=UTF-8\'\''.rawurlencode($filename));
		header('Content-Length: '.dol_filesize($fullpath));
		header('X-Content-Type-Options: nosniff');
		header('Cache-Control: private, no-store');
		if ($inline) {
			header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
		}
		readfileLowMemory($osfile);
		exit;
	}

	/**
	 * Client visibility of the link between a revision and a document (no row = visible)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$revid		Revision id
	 * @param	string	$element	Document type
	 * @param	int		$docid		Document id
	 * @return	int					1 visible, 0 hidden
	 */
	public static function getLinkVisibility($db, $revid, $element, $docid)
	{
		$sql = "SELECT client_visible FROM ".$db->prefix()."designtrace_docaccess";
		$sql .= " WHERE fk_revision = ".((int) $revid)." AND element = '".$db->escape($element)."' AND fk_element = ".((int) $docid);
		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql))) {
			return (int) $obj->client_visible;
		}
		return 1;
	}

	/**
	 * Show or hide the files of a revision to the customer of a document
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	User	$user		User
	 * @param	int		$revid		Revision id
	 * @param	string	$element	Document type
	 * @param	int		$docid		Document id
	 * @param	int		$visible	1 or 0
	 * @return	int					>0 if OK, <0 if KO
	 */
	public static function setLinkVisibility($db, $user, $revid, $element, $docid, $visible)
	{
		global $conf;

		$visible = $visible ? 1 : 0;
		$sql = "SELECT rowid FROM ".$db->prefix()."designtrace_docaccess";
		$sql .= " WHERE fk_revision = ".((int) $revid)." AND element = '".$db->escape($element)."' AND fk_element = ".((int) $docid);
		$resql = $db->query($sql);
		if (!$resql) {
			return -1;
		}
		$obj = $db->fetch_object($resql);
		if ($obj) {
			$sql = "UPDATE ".$db->prefix()."designtrace_docaccess SET client_visible = ".$visible.", fk_user_modif = ".((int) $user->id);
			$sql .= " WHERE rowid = ".((int) $obj->rowid);
		} else {
			$sql = "INSERT INTO ".$db->prefix()."designtrace_docaccess (entity, fk_revision, element, fk_element, client_visible, date_creation, fk_user_creat)";
			$sql .= " VALUES (".((int) $conf->entity).", ".((int) $revid).", '".$db->escape($element)."', ".((int) $docid).", ".$visible.", '".$db->idate(dol_now())."', ".((int) $user->id).")";
		}
		return $db->query($sql) ? 1 : -1;
	}

	/**
	 * Quotes, orders and tickets a revision is linked to, with the customer and access state
	 *
	 * @param	DoliDB				$db			Database handler
	 * @param	ComponentRevision	$revision	Revision
	 * @return	stdClass[]						Rows: element, doc_id, doc_ref, doc_status, socid, socname, client_visible, delivered
	 */
	public static function fetchDocumentsOfRevision($db, $revision)
	{
		$rows = array();
		foreach (self::getChannels() as $channel => $def) {
			if (!isModEnabled($def['module'])) {
				continue;
			}
			$sql = "SELECT ee.sourcetype as element, d.rowid as doc_id, d.ref as doc_ref, d.fk_statut as doc_status, s.rowid as socid, s.nom as socname,";
			$sql .= " COALESCE(a.client_visible, 1) as client_visible";
			$sql .= " FROM ".$db->prefix()."element_element as ee";
			$sql .= " INNER JOIN ".$db->prefix().$def['table']." as d ON d.rowid = ee.fk_source";
			$sql .= " LEFT JOIN ".$db->prefix()."societe as s ON s.rowid = d.fk_soc";
			$sql .= " LEFT JOIN ".$db->prefix()."designtrace_docaccess as a ON a.fk_revision = ee.fk_target AND a.element = ee.sourcetype AND a.fk_element = ee.fk_source";
			$sql .= " WHERE ee.sourcetype = '".$db->escape($channel)."' AND ee.targettype = '".$db->escape($revision->getElementType())."'";
			$sql .= " AND ee.fk_target = ".((int) $revision->id);
			$sql .= " ORDER BY d.rowid DESC";
			$resql = $db->query($sql);
			while ($resql && ($obj = $db->fetch_object($resql))) {
				$obj->delivered = (int) in_array((int) $obj->doc_status, $def['completed'], true);
				$rows[] = $obj;
			}
		}
		return $rows;
	}

	/**
	 * Update delivered revision: point a delivered order at another released revision of the same
	 * component. Explicit and logged (event on the order: who, when, from, to, why); the previous
	 * revision stays linked as history, hidden from the customer unless $keepold.
	 *
	 * @param	DoliDB				$db			Database handler
	 * @param	User				$user		User
	 * @param	Commande			$order		Delivered order
	 * @param	ComponentRevision	$oldrev		Revision delivered so far
	 * @param	ComponentRevision	$newrev		Released revision to deliver
	 * @param	string				$reason		Why (required)
	 * @param	int					$keepold	1 to keep the previous revision visible to the customer
	 * @param	string[]			$errors		Errors, filled on failure
	 * @param	int					$ecoid		Change order (ECO) behind the update, 0 if none
	 * @return	int								>0 if OK, <0 if KO
	 */
	public static function updateDelivered($db, $user, $order, $oldrev, $newrev, $reason, $keepold, &$errors, $ecoid = 0)
	{
		global $langs;

		$langs->load('designtrace@designtrace');
		$errors = array();
		if (!$user->hasRight('designtrace', 'componentrevision', 'updatedelivered')) {
			$errors[] = $langs->trans('NotEnoughPermissions');
			return -1;
		}
		if (trim((string) $reason) === '') {
			$errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('UpdateDeliveredReason'));
			return -1;
		}
		if ($order->element != 'commande' || (int) $order->status !== Commande::STATUS_CLOSED) {
			$errors[] = $langs->trans('ErrorUpdateDeliveredNotDelivered', $order->ref);
			return -1;
		}
		if ((int) $newrev->fk_component !== (int) $oldrev->fk_component || (int) $newrev->status !== ComponentRevision::STATUS_RELEASED || (int) $newrev->id <= (int) $oldrev->id) {
			$errors[] = $langs->trans('ErrorUpdateDeliveredTarget', $newrev->ref, $oldrev->ref);
			return -1;
		}
		$type = $order->getElementType();
		$sql = "SELECT fk_target FROM ".$db->prefix()."element_element WHERE fk_source = ".((int) $order->id)." AND sourcetype = '".$db->escape($type)."'";
		$sql .= " AND targettype = '".$db->escape($oldrev->getElementType())."' AND fk_target IN (".((int) $oldrev->id).", ".((int) $newrev->id).")";
		$resql = $db->query($sql);
		$linked = array();
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$linked[(int) $obj->fk_target] = true;
		}
		if (empty($linked[(int) $oldrev->id])) {
			$errors[] = $langs->trans('ErrorUpdateDeliveredNotLinked', $oldrev->ref, $order->ref);
			return -1;
		}

		$db->begin();
		$error = 0;
		if (empty($linked[(int) $newrev->id]) && !$newrev->add_object_linked($type, $order->id)) {
			$error++;
		}
		if (!$error && (self::setLinkVisibility($db, $user, $newrev->id, $type, $order->id, 1) < 0 || self::setLinkVisibility($db, $user, $oldrev->id, $type, $order->id, $keepold ? 1 : 0) < 0)) {
			$error++;
		}
		if (!$error) {
			require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
			$now = dol_now();
			$event = new ActionComm($db);
			$event->type_code = 'AC_OTH_AUTO';
			$event->code = 'AC_DESIGNTRACE_DELIVERED_UPDATE';
			$event->label = $langs->transnoentities('EventDeliveredRevisionUpdated', $order->ref, $oldrev->ref, $newrev->rev);
			$event->note_private = $langs->transnoentities('EventDeliveredRevisionUpdatedNote', $oldrev->ref, $newrev->ref, $order->ref, trim($reason), $langs->transnoentities($keepold ? 'Yes' : 'No'));
			if ((int) $ecoid > 0) {
				dol_include_once('/designtrace/class/changeorder.class.php');
				$eco = new ChangeOrder($db);
				if ($eco->fetch((int) $ecoid) > 0) {
					$event->note_private .= "\n".$langs->transnoentities('EventDeliveredRevisionUpdatedEco', $eco->ref, $eco->label);
				}
			}
			$event->datep = $now;
			$event->datef = $now;
			$event->percentage = -1;
			$event->userownerid = $user->id;
			$event->authorid = $user->id;
			$event->socid = (int) $order->socid;
			$event->elementtype = $order->element;
			$event->fk_element = $order->id;
			if ($event->create($user) <= 0) {
				$errors = array_merge(array($event->error), (array) $event->errors);
				$error++;
			}
		}
		if ($error) {
			$db->rollback();
			if (empty($errors)) {
				$errors[] = $db->lasterror();
			}
			return -1;
		}
		$db->commit();
		return 1;
	}

	/**
	 * Download log of a revision, newest first
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$revid	Revision id
	 * @param	int		$limit	Max rows
	 * @return	stdClass[]		Rows with socname and doc_ref
	 */
	public static function fetchDownloads($db, $revid, $limit = 50)
	{
		$rows = array();
		$sql = "SELECT dl.rowid, dl.fk_revisionfile, dl.filename, dl.fk_soc, s.nom as socname, dl.element, dl.fk_element, dl.channel, dl.actor, dl.ip, dl.date_download";
		$sql .= " FROM ".$db->prefix()."designtrace_download as dl";
		$sql .= " LEFT JOIN ".$db->prefix()."societe as s ON s.rowid = dl.fk_soc";
		$sql .= " WHERE dl.fk_revision = ".((int) $revid);
		$sql .= " ORDER BY dl.date_download DESC, dl.rowid DESC";
		$sql .= $db->plimit((int) $limit);
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$obj->date_download = $db->jdate($obj->date_download);
			$rows[] = $obj;
		}
		return $rows;
	}

	/**
	 * Number of customer downloads per file of a revision
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$revid	Revision id
	 * @return	array<int,int>	File id => count
	 */
	public static function countDownloads($db, $revid)
	{
		$counts = array();
		$sql = "SELECT fk_revisionfile, COUNT(rowid) as nb FROM ".$db->prefix()."designtrace_download";
		$sql .= " WHERE fk_revision = ".((int) $revid)." GROUP BY fk_revisionfile";
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$counts[(int) $obj->fk_revisionfile] = (int) $obj->nb;
		}
		return $counts;
	}

	/**
	 * Secret used to sign download links, created on first use
	 *
	 * @return	string
	 */
	public static function getSecret()
	{
		global $db, $conf;

		$secret = getDolGlobalString('DESIGNTRACE_LINK_SECRET');
		if ($secret === '') {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
			$secret = bin2hex(random_bytes(32));
			dolibarr_set_const($db, 'DESIGNTRACE_LINK_SECRET', $secret, 'chaine', 0, 'Secret for signed download links of DesignTrace', $conf->entity);
		}
		return $secret;
	}

	/**
	 * Lifetime of signed links, in seconds
	 *
	 * @return	int
	 */
	public static function getLinkTtl()
	{
		$ttl = getDolGlobalInt('DESIGNTRACE_LINK_TTL');
		return ($ttl > 0 ? $ttl : self::DEFAULT_LINK_TTL);
	}

	/**
	 * Signature of a download link
	 *
	 * @param	array<string,int|string>	$params		f (file id), s (customer id), e (expiry), t (document type), d (document id), a (actor), n (entity)
	 * @return	string
	 */
	public static function sign($params)
	{
		$payload = implode('|', array((int) $params['f'], (int) $params['s'], (int) $params['e'], (string) $params['t'], (int) $params['d'], (string) $params['a'], (int) $params['n']));
		return hash_hmac('sha256', $payload, self::getSecret());
	}

	/**
	 * Short-lived signed link to download a file (served by public/download.php)
	 *
	 * @param	stdClass	$row		Row from fetchVisibleFiles()
	 * @param	int			$socid		Customer id
	 * @param	string		$actor		Who asks (logged with the download)
	 * @return	array{url:string,expires:int}
	 */
	public static function buildSignedUrl($row, $socid, $actor = '')
	{
		global $conf;

		$params = array(
			'f' => (int) $row->file_id,
			's' => (int) $socid,
			'e' => dol_now() + self::getLinkTtl(),
			't' => (string) $row->element,
			'd' => (int) $row->doc_id,
			'a' => (string) $actor,
			'n' => (int) $conf->entity,
		);
		$params['sig'] = self::sign($params);
		return array('url' => dol_buildpath('/designtrace/public/download.php', 2).'?'.http_build_query($params, '', '&'), 'expires' => (int) $params['e']);
	}

	/**
	 * Check a signed link
	 *
	 * @param	array<string,int|string>	$params		Parameters of the link, with sig
	 * @return	string							'' if valid, else the reason
	 */
	public static function checkSignedParams($params)
	{
		foreach (array('f', 's', 'e', 'n', 'sig') as $key) {
			if (!isset($params[$key]) || $params[$key] === '') {
				return 'Bad link';
			}
		}
		$params += array('t' => '', 'd' => 0, 'a' => '');
		if (!hash_equals(self::sign($params), (string) $params['sig'])) {
			return 'Bad signature';
		}
		if ((int) $params['e'] < dol_now()) {
			return 'Link expired';
		}
		return '';
	}

	/**
	 * Link to a quote, order or ticket (cached), or its id if it no longer exists
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	string	$element	Document type
	 * @param	int		$docid		Document id
	 * @return	string				HTML
	 */
	public static function getDocumentNomUrl($db, $element, $docid)
	{
		static $cache = array();

		$key = $element.'_'.$docid;
		if (!isset($cache[$key])) {
			dol_include_once('/designtrace/class/doclinks.class.php');
			$doc = ($docid > 0 ? DesignTraceDocLinks::fetchDocument($db, $element, $docid) : null);
			$cache[$key] = ($doc ? $doc->getNomUrl(1) : ($docid > 0 ? '#'.((int) $docid) : ''));
		}
		return $cache[$key];
	}

	/**
	 * Whether a file format can open in a 3D viewer
	 *
	 * @param	string	$format		Extension
	 * @return	bool
	 */
	public static function is3dFormat($format)
	{
		return in_array(strtolower((string) $format), array('step', 'stp', '3mf', 'stl', 'obj', 'iges', 'igs'), true);
	}
}
