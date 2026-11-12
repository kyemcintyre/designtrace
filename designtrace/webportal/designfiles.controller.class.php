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
 * Registered by the initController hook (ActionsDesignTrace). The REST API is the main customer
 * portal; this page shows the same thing from the same code (DesignTraceCustomerPortal). Lists the files the logged-in
 * customer can see, grouped by quote, order or ticket (DesignTraceClientAccess rules), and serves
 * downloads itself (?controller=designfiles&action=download&file=ID) so every download goes through
 * the same checks and is logged. Quotes and orders also show the licence of each design, which the
 * customer can read and accept (logged with the exact version, PDF copy on the document), and ask
 * whether renders of each (non-confidential) design may be shown in the workshop's gallery.
 * Proof drawings can be approved, or changes requested with a comment (DesignTraceProofApproval),
 * and delivered orders reordered (a draft quote for staff to check, DesignTraceReorder).
 */

dol_include_once('/designtrace/class/clientaccess.class.php');
dol_include_once('/designtrace/class/licensing.class.php');
dol_include_once('/designtrace/class/portfolio.class.php');
dol_include_once('/designtrace/class/proofapproval.class.php');
dol_include_once('/designtrace/class/reorder.class.php');
dol_include_once('/designtrace/class/customerportal.class.php');

/**
 * Controller for the Design files page of the Web portal
 */
class DesignFilesController extends Controller
{
	/**
	 * @var array<string,array<string,mixed>>	Documents shown, newest first (DesignTraceCustomerPortal::fetchDocuments())
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

		// The customer's copy of an accepted licence
		if ($context->action == 'licencepdf') {
			$pdf = DesignTraceCustomerPortal::getAcceptancePdf($this->db, $socid, $this->element, $this->docid, GETPOSTINT('design'));
			if (!$pdf) {
				$context->setEventMessage($langs->trans('WebPortalDesignFileNotAvailable'), 'errors');
			} else {
				DesignTraceClientAccess::sendFile($pdf['path'], $pdf['filename']);
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

		// Reorder a delivered order (POST)
		if ($context->action == 'reorder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
			global $user;
			$error = '';
			if (DesignTraceCustomerPortal::reorder($this->db, $user, $socid, GETPOSTINT('reorder_docid'), 'webportal', $this->getActor(), $error) > 0) {
				$order = new Commande($this->db);
				$order->fetch(GETPOSTINT('reorder_docid'));
				$context->setEventMessage($langs->trans('WebPortalReorderSent', $order->ref), 'mesgs');
			} else {
				$context->setEventMessage($error == 'notfound' ? $langs->trans('ErrorRecordNotFound') : $error, 'errors');
			}
		}

		// Proof decision (POST): approve, or request changes with a comment
		if ($context->action == 'proofdecision' && $_SERVER['REQUEST_METHOD'] === 'POST') {
			global $user;
			$error = '';
			$element = DesignTraceClientAccess::normaliseElement(GETPOST('proof_element', 'aZ09'));
			$decision = (GETPOST('decision', 'aZ09') === 'approve' ? DesignTraceProofApproval::DECISION_APPROVED : DesignTraceProofApproval::DECISION_CHANGES);
			$res = DesignTraceProofApproval::decide($this->db, $user, $socid, GETPOSTINT('proof_rev'), $element, GETPOSTINT('proof_docid'), $decision, GETPOST('comment', 'alphanohtml'), DesignTraceClientAccess::CHANNEL_WEBPORTAL, $this->getActor(), $error);
			if (is_array($res)) {
				$context->setEventMessage($langs->trans($decision === DesignTraceProofApproval::DECISION_APPROVED ? 'WebPortalProofApproved' : 'WebPortalProofChangesSent'), 'mesgs');
			} else {
				$context->setEventMessage($error, 'errors');
			}
		}

		// Portfolio consent (POST): the design must be on one of the customer's quotes or orders
		if ($context->action == 'setconsent' && $_SERVER['REQUEST_METHOD'] === 'POST') {
			$error = '';
			$element = DesignTraceClientAccess::normaliseElement(GETPOST('consent_element', 'aZ09'));
			if (DesignTraceCustomerPortal::setConsent($this->db, $socid, $element, GETPOSTINT('consent_docid'), GETPOSTINT('consent_design'), GETPOSTINT('consent'), DesignTracePortfolio::CHANNEL_WEBPORTAL, $this->getActor(), null, $error) < 0) {
				$context->setEventMessage($langs->trans(in_array($error, array('document', 'design'), true) ? 'ErrorRecordNotFound' : 'Error'), 'errors');
			} else {
				$context->setEventMessage($langs->trans('WebPortalPortfolioSaved'), 'mesgs');
			}
		}

		$this->docs = DesignTraceCustomerPortal::fetchDocuments($this->db, $socid, $this->element, $this->docid);

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
		foreach ($this->docs as $doc) {
			$label = isset($channels[$doc['element']]) ? $langs->trans($channels[$doc['element']]['label']) : $doc['element'];
			print '<article>';
			print '<header><strong>'.dol_escape_htmltag($label.' '.$doc['doc_ref']).'</strong></header>';
			if (!empty($doc['licences'])) {
				$this->printLicences($context, $doc, $doc['licences']);
			}
			if (!empty($doc['files'])) {
				$this->printFiles($context, $doc['files']);
			}
			if (!empty($doc['reorder'])) {
				$this->printReorder($context, $doc);
			}
			if (!empty($doc['proofs'])) {
				$this->printProofs($context, $doc['proofs']);
			}
			if (!empty($doc['designs'])) {
				$this->printConsent($context, $doc, $doc['designs']);
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
				print '<p>&#10003; '.$langs->trans('WebPortalLicenceAcceptedOn', dol_print_date($info['acceptance']->date_accept, 'dayhour'));
				if (!empty($info['acceptance']->filename)) {
					$url = $context->getControllerUrl('designfiles', array('action' => 'licencepdf', 'element' => $doc['element'], 'docid' => (int) $doc['doc_id'], 'design' => (int) $designid));
					print ' &middot; <a href="'.dolPrintHTMLForAttributeUrl($url).'">'.$langs->trans('WebPortalLicenceCopy').'</a>';
				}
				print '</p>';
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

	/**
	 * Proof drawings of one document: decision, or the approve / request changes form
	 *
	 * @param	Context						$context	Web portal context
	 * @param	array<int,array<string,mixed>>	$proofs	Entries of DesignTraceProofApproval::fetchProofs()
	 * @return	void
	 */
	private function printProofs($context, $proofs)
	{
		global $langs;

		$labels = DesignTraceProofApproval::getDecisionLabels();
		print '<section class="designtrace-proofs">';
		print '<p><strong>'.$langs->trans('WebPortalProofTitle').'</strong></p>';
		foreach ($proofs as $proof) {
			print '<p>'.dol_escape_htmltag($proof['rev_ref'].' '.$proof['comp_label']);
			if ($proof['decision'] !== DesignTraceProofApproval::DECISION_NONE) {
				print '<br><small>'.$langs->trans('WebPortalProofDecision', dol_escape_htmltag($labels[$proof['decision']]), dol_print_date($proof['date_decision'], 'dayhour'), dol_escape_htmltag($proof['decided_by'])).'</small>';
			}
			print '</p>';
			if (!$proof['can_decide']) {
				continue;
			}
			print '<form method="POST" action="'.dolPrintHTMLForAttributeUrl($context->getControllerUrl('designfiles', array('element' => $proof['element'], 'docid' => $proof['doc_id']), false)).'">';
			print $context->getFormToken();
			print '<input type="hidden" name="action" value="proofdecision">';
			print '<input type="hidden" name="proof_element" value="'.dol_escape_htmltag($proof['element']).'">';
			print '<input type="hidden" name="proof_docid" value="'.((int) $proof['doc_id']).'">';
			print '<input type="hidden" name="proof_rev" value="'.((int) $proof['rev_id']).'">';
			print '<textarea name="comment" rows="2" placeholder="'.dolPrintHTMLForAttribute($langs->trans('WebPortalProofCommentPlaceholder')).'"></textarea>';
			print '<button type="submit" name="decision" value="approve">'.$langs->trans('WebPortalProofApprove').'</button> ';
			print '<button type="submit" name="decision" value="changes" class="secondary">'.$langs->trans('WebPortalProofRequestChanges').'</button>';
			print '</form>';
		}
		print '</section>';
	}

	/**
	 * Reorder button of a delivered order (or the pending request)
	 *
	 * @param	Context	$context	Web portal context
	 * @param	array<string,mixed>	$doc	Order from DesignTraceCustomerPortal::fetchDocuments()
	 * @return	void
	 */
	private function printReorder($context, $doc)
	{
		global $langs;

		if (empty($doc['reorder']['possible'])) {
			return;
		}
		if (!empty($doc['reorder']['pending_quote_id'])) {
			print '<p><small>'.$langs->trans('WebPortalReorderPending').'</small></p>';
			return;
		}
		print '<form method="POST" action="'.dolPrintHTMLForAttributeUrl($context->getControllerUrl('designfiles', array('element' => 'commande', 'docid' => $doc['doc_id']), false)).'">';
		print $context->getFormToken();
		print '<input type="hidden" name="action" value="reorder">';
		print '<input type="hidden" name="reorder_docid" value="'.((int) $doc['doc_id']).'">';
		print '<button type="submit" class="secondary">'.$langs->trans('WebPortalReorder').'</button> <small>'.$langs->trans('WebPortalReorderHelp').'</small>';
		print '</form>';
	}
}
