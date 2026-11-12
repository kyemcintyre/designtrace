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
 * the same checks and is logged.
 */

dol_include_once('/designtrace/class/clientaccess.class.php');

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

		$rows = DesignTraceClientAccess::fetchVisibleFiles($this->db, $socid, $this->element, $this->docid);
		$this->rows = is_array($rows) ? $rows : array();

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

		if (empty($this->rows)) {
			print '<p>'.$langs->trans('WebPortalDesignFilesNone').'</p>';
		}

		$channels = DesignTraceClientAccess::getChannels();
		$groups = array();
		foreach ($this->rows as $row) {
			$groups[$row->element.'_'.$row->doc_id][] = $row;
		}
		foreach ($groups as $rows) {
			$first = $rows[0];
			$label = isset($channels[$first->element]) ? $langs->trans($channels[$first->element]['label']) : $first->element;
			print '<article>';
			print '<header><strong>'.dol_escape_htmltag($label.' '.$first->doc_ref).'</strong></header>';
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
			print '</article>';
		}

		print '</main>';

		$this->loadTemplate('footer');
	}
}
