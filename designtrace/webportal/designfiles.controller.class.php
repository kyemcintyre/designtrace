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
 * \file        designtrace/webportal/designfiles.controller.class.php
 * \ingroup     designtrace
 * \brief       "Design files" page of Dolibarr's Web portal
 *
 * Registered by the initController hook (ActionsDesignTrace). Lists the files the logged-in
 * customer can see, grouped by quote, order or ticket (DesignTraceClientAccess rules), and serves
 * downloads itself (?controller=designfiles&action=download&file=ID) so every download goes through
 * the same checks and is logged. Quotes and orders also show the licence of each design, which the
 * customer can read and accept (logged with the exact version, PDF copy on the document), and ask
 * whether renders of each (non-confidential) design may be shown in the workshop's gallery.
 */

dol_include_once('/designtrace/class/clientaccess.class.php');
dol_include_once('/designtrace/class/licensing.class.php');
dol_include_once('/designtrace/class/portfolio.class.php');

/**
 * Controller for the Design files page of the Web portal
 */
class DesignFilesController extends Controller
{
	/**
	 * @var stdClass[]	Visible files
	 */
	public $rows = array();

	/**
	 * @var array<string,array<int,array<string,mixed>>>	Licences per document ("element_id" => fetchDocumentLicences())
	 */
	public $licences = array();

	/**
	 * @var array<string,array<int,array<string,mixed>>>	Designs asked about for the gallery per document (not confidential)
	 */
	public $designs = array();

	/**
	 * @var array<string,array{element:string,doc_id:int,doc_ref:string}>	Documents shown, newest first
	 */
	public $docs = array();

	/**
	 * @var string	Document type filter
	 */
	public $element = '';

	/**
	 * @var int		Document id filter
	 */
	public $docid = 0;

	/**
	 * Check current access to controller
	 *
	 * @return  bool
	 */
	public function checkAccess()
	{
		$this->accessRight = isModEnabled('designtrace') && !getDolGlobalInt('DESIGNTRACE_WEBPORTAL_HIDE');

		return parent::checkAccess();
	}

	/**
	 * Action method is called before html output
	 *
	 * @return  int     Return integer < 0 on error, > 0 on success
	 */
	public function action()
	{
		global $langs;

		$context = Context::getInstance();
		if (!$context->controllerInstance->checkAccess()) {
			return -1;
		}
		$socid = (int) (empty($context->logged_thirdparty) ? 0 : $context->logged_thirdparty->id);
		if ($socid <= 0) {
			return -1;
		}

		$langs->loadLangs(array('designtrace@designtrace', 'propal', 'orders', 'ticket'));
		$context->title = $langs->trans('WebPortalDesignFiles');
		$context->desc = $langs->trans('WebPortalDesignFilesDesc');
		$context->menu_active[] = 'designtrace_files';

		$this->element = DesignTraceClientAccess::normaliseElement(GETPOST('element', 'aZ09'));
		$this->docid = ($this->element !== '' ? GETPOSTINT('docid') : 0);

		$hookRes = $this->hookDoAction();
		if (!empty($hookRes)) {
			return 1;
		}

		if ($context->action == 'download') {
			$row = DesignTraceClientAccess::getDownloadableFile($this->db, $socid, GETPOSTINT('file'), $this->element, $this->docid);
			if (!$row) {
				$context->setEventMessage($langs->trans('WebPortalDesignFileNotAvailable'), 'errors');
			} else {
				$fullpath = DesignTraceClientAccess::getFilePath($this->db, $row);
				if ($fullpath === '' || !is_file(dol_osencode($fullpath))) {
					$context->setEventMessage($langs->trans('ErrorFileNotFound', $row->filename), 'errors');
				} else {
					DesignTraceClientAccess::logDownload($this->db, $row, $socid, DesignTraceClientAccess::CHANNEL_WEBPORTAL, $this->getActor());
					DesignTraceClientAccess::sendFile($fullpath, $row->filename);
				}
			}
		}

		// Accept a licence (POST, with the box ticked)
		if ($context->action == 'acceptlicence' && $_SERVER['REQUEST_METHOD'] === 'POST') {
			if (!GETPOST('agree', 'alpha')) {
				$context->setEventMessage($langs->trans('WebPortalLicenceTickToAccept'), 'errors');
			} else {
				$error = '';
				$element = DesignTraceClientAccess::normaliseElement(GETPOST('licence_element', 'aZ09'));
				$res = DesignTraceLicensing::accept($this->db, $socid, $element, GETPOSTINT('licence_docid'), GETPOSTINT('licence_design'), GETPOSTINT('licence_version'), DesignTraceClientAccess::CHANNEL_WEBPORTAL, $this->getActor(), $error);
				if ($res > 0) {
					$context->setEventMessage($langs->trans('WebPortalLicenceAccepted'), 'mesgs');
				} else {
					$context->setEventMessage($error, 'errors');
				}
			}
		}

		// Portfolio consent (POST): the design must be on one of the customer's quotes or orders
		if ($context->action == 'setconsent' && $_SERVER['REQUEST_METHOD'] === 'POST') {
			$element = DesignTraceClientAccess::normaliseElement(GETPOST('consent_element', 'aZ09'));
			$docid = GETPOSTINT('consent_docid');
			$designid = GETPOSTINT('consent_design');
			$doc = DesignTraceLicensing::fetchDocument($this->db, $element, $docid);
			$designs = ($doc && (int) $doc->socid === $socid && (int) $doc->status > 0) ? DesignTraceLicensing::fetchDesignsOfDocument($this->db, $element, $docid) : array();
			if (!isset($designs[$designid]) || !empty($designs[$designid]['confidential'])) {
				$context->setEventMessage($langs->trans('ErrorRecordNotFound'), 'errors');
			} elseif (DesignTracePortfolio::setConsent($this->db, $designid, GETPOSTINT('consent'), DesignTracePortfolio::CHANNEL_WEBPORTAL, $this->getActor(), $element, $docid) < 0) {
				$context->setEventMessage($langs->trans('Error'), 'errors');
			} else {
				$context->setEventMessage($langs->trans('WebPortalPortfolioSaved'), 'mesgs');
			}
		}

		$rows = DesignTraceClientAccess::fetchVisibleFiles($this->db, $socid, $this->element, $this->docid);
		$this->rows = is_array($rows) ? $rows : array();

		// Documents to show: those with visible files, and quotes/orders with designs under a licence
		foreach ($this->rows as $row) {
			$this->docs[$row->element.'_'.$row->doc_id] = array('element' => $row->element, 'doc_id' => (int) $row->doc_id, 'doc_ref' => $row->doc_ref);
		}
		$channels = DesignTraceClientAccess::getChannels();
		foreach (DesignTraceLicensing::getDocumentTypes() as $element => $def) {
			if (($this->element !== '' && $this->element !== $element) || !isModEnabled($channels[$element]['module'])) {
				continue;
			}
			$sql = "SELECT DISTINCT d.rowid, d.ref FROM ".$this->db->prefix()."element_element as ee";
			$sql .= " INNER JOIN ".$this->db->prefix().$def['table']." as d ON d.rowid = ee.fk_source";
			$sql .= " WHERE ee.sourcetype = '".$this->db->escape($element)."' AND ee.targettype IN ('designtrace_design', 'designtrace_componentrevision')";
			$sql .= " AND d.fk_soc = ".((int) $socid)." AND d.fk_statut NOT IN (".implode(',', array_map('intval', $channels[$element]['hidden'])).")";
			$sql .= " AND d.entity IN (".getEntity($element == 'commande' ? 'order' : $element).")";
			if ($this->docid > 0) {
				$sql .= " AND d.rowid = ".((int) $this->docid);
			}
			$resql = $this->db->query($sql);
			while ($resql && ($obj = $this->db->fetch_object($resql))) {
				$licences = array_filter(DesignTraceLicensing::fetchDocumentLicences($this->db, $element, (int) $obj->rowid), function ($info) {
					return $info['licence'] && $info['version'];
				});
				$key = $element.'_'.$obj->rowid;
				if ($licences) {
					$this->licences[$key] = $licences;
				}
				$designs = array_filter(DesignTraceLicensing::fetchDesignsOfDocument($this->db, $element, (int) $obj->rowid), function ($design) {
					return empty($design['confidential']);
				});
				if ($designs) {
					$this->designs[$key] = $designs;
				}
				if ($licences || $designs) {
					$this->docs[$key] = array('element' => $element, 'doc_id' => (int) $obj->rowid, 'doc_ref' => $obj->ref);
				}
			}
		}
		uasort($this->docs, function ($a, $b) {
			return $b['doc_id'] <=> $a['doc_id'];
		});

		return 1;
	}

	/**
	 * Login of the portal account, for the download log
	 *
	 * @return	string
	 */
	private function getActor()
	{
		$accountid = (int) ($_SESSION['webportal_logged_thirdparty_account_id'] ?? 0);
		if ($accountid > 0) {
			$sql = "SELECT login FROM ".$this->db->prefix()."societe_account WHERE rowid = ".$accountid;
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				return 'portal:'.$obj->login;
			}
		}
		$memberid = (int) ($_SESSION['webportal_logged_member_account_id'] ?? 0);
		return $memberid > 0 ? 'portal:member '.$memberid : 'portal';
	}

	/**
	 * Display
	 *
	 * @return	void
	 */
	public function display()
	{
		global $langs;

		$context = Context::getInstance();
		if (!$context->controllerInstance->checkAccess()) {
			$this->display404();
			return;
		}

		$this->loadTemplate('header');
		$this->loadTemplate('menu');
		$this->loadTemplate('hero-header-banner');

		print '<main class="container">';

		if ($this->element !== '') {
			print '<p><a href="'.dolPrintHTMLForAttributeUrl($context->getControllerUrl('designfiles')).'">&larr; '.$langs->trans('WebPortalDesignFilesAll').'</a></p>';
		}

		if (empty($this->docs)) {
			print '<p>'.$langs->trans('WebPortalDesignFilesNone').'</p>';
		}

		$channels = DesignTraceClientAccess::getChannels();
		$files = array();
		foreach ($this->rows as $row) {
			$files[$row->element.'_'.$row->doc_id][] = $row;
		}
		foreach ($this->docs as $key => $doc) {
			$label = isset($channels[$doc['element']]) ? $langs->trans($channels[$doc['element']]['label']) : $doc['element'];
			print '<article>';
			print '<header><strong>'.dol_escape_htmltag($label.' '.$doc['doc_ref']).'</strong></header>';
			if (!empty($this->licences[$key])) {
				$this->printLicences($context, $doc, $this->licences[$key]);
			}
			if (!empty($files[$key])) {
				$this->printFiles($context, $files[$key]);
			}
			if (!empty($this->designs[$key])) {
				$this->printConsent($context, $doc, $this->designs[$key]);
			}
			print '</article>';
		}

		print '</main>';

		$this->loadTemplate('footer');
	}

	/**
	 * Files of one document
	 *
	 * @param	Context		$context	Web portal context
	 * @param	stdClass[]	$rows		Visible files of the document
	 * @return	void
	 */
	private function printFiles($context, $rows)
	{
		global $langs;

		print '<table class="table table-hover" width="100%">';
		print '<thead><tr>';
		print '<th>'.$langs->trans('Component').'</th>';
		print '<th>'.$langs->trans('Revision').'</th>';
		print '<th>'.$langs->trans('File').'</th>';
		print '<th style="text-align: right; white-space: nowrap;">'.$langs->trans('Size').'</th>';
		print '</tr></thead><tbody>';
		foreach ($rows as $row) {
			$url = $context->getControllerUrl('designfiles', array('action' => 'download', 'file' => (int) $row->file_id, 'element' => $row->element, 'docid' => (int) $row->doc_id));
			print '<tr>';
			print '<td data-label="'.dolPrintHTMLForAttribute($langs->trans('Component')).'">'.dol_escape_htmltag($row->comp_ref).' <small>'.dol_escape_htmltag((string) $row->comp_label).'</small></td>';
			print '<td data-label="'.dolPrintHTMLForAttribute($langs->trans('Revision')).'">'.dol_escape_htmltag($row->rev);
			if ((int) $row->rev_status !== ComponentRevision::STATUS_RELEASED) {
				print ' <small>('.$langs->trans('WebPortalDesignFilePreview').')</small>';
			}
			print '</td>';
			print '<td data-label="'.dolPrintHTMLForAttribute($langs->trans('File')).'"><a href="'.dolPrintHTMLForAttributeUrl($url).'" download>'.dol_escape_htmltag($row->filename).'</a></td>';
			print '<td data-label="'.dolPrintHTMLForAttribute($langs->trans('Size')).'" style="text-align: right; white-space: nowrap;">'.RevisionFile::formatSize((int) $row->filesize).'</td>';
			print '</tr>';
		}
		print '</tbody></table>';
	}

	/**
	 * Licences of the designs on one quote or order: accepted ones, and the wording with an
	 * accept form for the others
	 *
	 * @param	Context								$context	Web portal context
	 * @param	array{element:string,doc_id:int,doc_ref:string}	$doc	Document
	 * @param	array<int,array<string,mixed>>		$licences	From DesignTraceLicensing::fetchDocumentLicences()
	 * @return	void
	 */
	private function printLicences($context, $doc, $licences)
	{
		global $langs;

		$document = DesignTraceLicensing::fetchDocument($this->db, $doc['element'], $doc['doc_id']);
		if (!$document) {
			return;
		}
		foreach ($licences as $designid => $info) {
			$version = $info['version'];
			print '<section class="designtrace-licence">';
			print '<p><strong>'.$langs->trans('WebPortalLicenceFor', dol_escape_htmltag($info['licence']->label), dol_escape_htmltag($info['design']['ref'].' '.$info['design']['label'])).'</strong> <small>('.dol_escape_htmltag($version->ref).')</small></p>';
			if ($info['acceptance']) {
				print '<p>&#10003; '.$langs->trans('WebPortalLicenceAcceptedOn', dol_print_date($info['acceptance']->date_accept, 'dayhour')).'</p>';
			} else {
				$wording = $version->renderWording(DesignTraceLicensing::getWordingValues($document, $info['design'], dol_now()));
				$perms = array();
				foreach ($version->getPermissions() as $label => $value) {
					$perms[] = ($value ? '&#10003; ' : '&#10007; ').dol_escape_htmltag($label);
				}
				print '<p><small>'.implode(' &middot; ', $perms).'</small></p>';
				print '<div style="max-height: 300px; overflow-y: auto; border: 1px solid #ccc; padding: 0.5em; margin-bottom: 0.5em;">'.$wording.'</div>';
				print '<form method="POST" action="'.dolPrintHTMLForAttributeUrl($context->getControllerUrl('designfiles', array('element' => $doc['element'], 'docid' => $doc['doc_id']), false)).'">';
				print $context->getFormToken();
				print '<input type="hidden" name="action" value="acceptlicence">';
				print '<input type="hidden" name="licence_element" value="'.dol_escape_htmltag($doc['element']).'">';
				print '<input type="hidden" name="licence_docid" value="'.((int) $doc['doc_id']).'">';
				print '<input type="hidden" name="licence_design" value="'.((int) $designid).'">';
				print '<input type="hidden" name="licence_version" value="'.((int) $version->id).'">';
				print '<label><input type="checkbox" name="agree" value="1"> '.$langs->trans('WebPortalLicenceAgree').'</label> ';
				print '<button type="submit">'.$langs->trans('WebPortalLicenceAccept').'</button>';
				print '</form>';
			}
			print '</section>';
		}
	}

	/**
	 * Gallery question for the designs of one quote or order
	 *
	 * @param	Context								$context	Web portal context
	 * @param	array{element:string,doc_id:int,doc_ref:string}	$doc	Document
	 * @param	array<int,array<string,mixed>>		$designs	Designs (not confidential)
	 * @return	void
	 */
	private function printConsent($context, $doc, $designs)
	{
		global $langs;

		$labels = DesignTracePortfolio::getConsentLabels();
		print '<section class="designtrace-portfolio">';
		print '<p><strong>'.$langs->trans('WebPortalPortfolioTitle').'</strong></p>';
		foreach ($designs as $designid => $design) {
			$consent = (int) DesignTracePortfolio::getConsent($this->db, $designid);
			print '<form method="POST" action="'.dolPrintHTMLForAttributeUrl($context->getControllerUrl('designfiles', array('element' => $doc['element'], 'docid' => $doc['doc_id']), false)).'">';
			print $context->getFormToken();
			print '<input type="hidden" name="action" value="setconsent">';
			print '<input type="hidden" name="consent_element" value="'.dol_escape_htmltag($doc['element']).'">';
			print '<input type="hidden" name="consent_docid" value="'.((int) $doc['doc_id']).'">';
			print '<input type="hidden" name="consent_design" value="'.((int) $designid).'">';
			print '<p>'.$langs->trans('WebPortalPortfolioQuestion', dol_escape_htmltag(trim($design['ref'].' '.$design['label'])));
			if ($consent !== DesignTracePortfolio::CONSENT_NONE) {
				print '<br><small>'.$langs->trans('WebPortalPortfolioCurrent', dol_escape_htmltag($labels[$consent])).'</small>';
			}
			print '</p>';
			if ($consent !== DesignTracePortfolio::CONSENT_GRANTED) {
				print '<button type="submit" name="consent" value="'.DesignTracePortfolio::CONSENT_GRANTED.'">'.$langs->trans('WebPortalPortfolioYes').'</button> ';
			}
			if ($consent !== DesignTracePortfolio::CONSENT_REFUSED) {
				print '<button type="submit" name="consent" value="'.DesignTracePortfolio::CONSENT_REFUSED.'" class="secondary">'.$langs->trans('WebPortalPortfolioNo').'</button>';
			}
			print '</form>';
		}
		print '</section>';
	}
}
