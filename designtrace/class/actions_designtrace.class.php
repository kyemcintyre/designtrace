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
 *	\file       designtrace/class/actions_designtrace.class.php
 *	\ingroup    designtrace
 *	\brief      Hooks of DesignTrace into Dolibarr pages
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

/**
 *	Hooks of DesignTrace: a "Create design" button on quote and order cards, and the design
 *	revision of a BOM on its card.
 */
class ActionsDesignTrace extends CommonHookActions
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var string[] Errors
	 */
	public $errors = array();

	/**
	 * @var array<string,mixed> Hook results, used by some hooks
	 */
	public $results = array();

	/**
	 * @var ?string String displayed by executeHook() immediately after return
	 */
	public $resprints;


	/**
	 *	Constructor
	 *
	 *	@param	DoliDB	$db		Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}


	/**
	 *	Add a "Create design" button to quote and order cards. The new design is linked to the
	 *	quote or order and takes its customer and project.
	 *
	 *	@param	array<string,mixed>	$parameters		Hook parameters
	 *	@param	CommonObject		$object			Quote or order shown
	 *	@param	string				$action			Current action
	 *	@param	HookManager			$hookmanager	Hook manager
	 *	@return	int									0 so the standard buttons are still shown
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		$contexts = explode(':', $parameters['context']);
		if (in_array('ticketcard', $contexts)) {
			return $this->addRaiseEcoButton($object);
		}
		if (!in_array('propalcard', $contexts) && !in_array('ordercard', $contexts)) {
			return 0;
		}
		if (!isModEnabled('designtrace') || empty($object->id) || !in_array($object->element, array('propal', 'commande'), true)) {
			return 0;
		}
		if (!$user->hasRight('designtrace', 'design', 'write')) {
			return 0;
		}
		if ($object->element == 'commande' && (int) $object->status == $object::STATUS_CANCELED) {
			return 0;
		}

		$langs->load('designtrace@designtrace');

		$url = dol_buildpath('/designtrace/design_card.php', 1).'?action=create&origin='.urlencode($object->element).'&originid='.((int) $object->id);
		print dolGetButtonAction('', $langs->trans('DesignCreateFromThis'), 'default', $url, '', true);

		return 0;
	}


	/**
	 *	"Raise ECO" on a ticket card: a new change order linked to the ticket, with its customer and
	 *	the components of the revisions linked to the ticket
	 *
	 *	@param	CommonObject	$object		Ticket shown
	 *	@return	int							0 so the standard buttons are still shown
	 */
	private function addRaiseEcoButton($object)
	{
		global $langs, $user;

		if (!isModEnabled('designtrace') || empty($object->id) || $object->element != 'ticket' || !$user->hasRight('designtrace', 'changeorder', 'write')) {
			return 0;
		}
		$langs->load('designtrace@designtrace');
		$url = dol_buildpath('/designtrace/changeorder_card.php', 1).'?action=create&origin=ticket&originid='.((int) $object->id);
		print dolGetButtonAction('', $langs->trans('EcoRaiseFromTicket'), 'default', $url, '', true);

		return 0;
	}


	/**
	 *	Quote and order cards: set the licence that overrides the designs' ones
	 *
	 *	@param	array<string,mixed>	$parameters		Hook parameters
	 *	@param	CommonObject		$object			Quote or order shown
	 *	@param	string				$action			Current action
	 *	@param	HookManager			$hookmanager	Hook manager
	 *	@return	int									0
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		$contexts = explode(':', $parameters['context']);
		if (!in_array('propalcard', $contexts) && !in_array('ordercard', $contexts)) {
			return 0;
		}
		if (!isModEnabled('designtrace') || empty($object->id) || !in_array($object->element, array('propal', 'commande'), true)) {
			return 0;
		}
		if ($action == 'designtrace_setconsent') {
			$this->doSetConsent($object);
			$action = '';
			return 0;
		}
		if ($action != 'designtrace_setlicence') {
			return 0;
		}
		dol_include_once('/designtrace/class/licensing.class.php');
		$langs->load('designtrace@designtrace');
		if (!$this->canSetDocumentLicence($object)) {
			setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
		} elseif (DesignTraceLicensing::setOverride($this->db, $user, $object->element, (int) $object->id, GETPOSTINT('designtrace_licence')) > 0) {
			setEventMessages($langs->trans('LicenceOverrideSaved'), null, 'mesgs');
		} else {
			setEventMessages($this->db->lasterror(), null, 'errors');
		}
		$action = '';
		return 0;
	}

	/**
	 *	Record the customer's portfolio consent for a design of a quote or order
	 *
	 *	@param	CommonObject	$object		Quote or order
	 *	@return	void
	 */
	private function doSetConsent($object)
	{
		global $langs, $user;

		dol_include_once('/designtrace/class/licensing.class.php');
		dol_include_once('/designtrace/class/portfolio.class.php');
		$langs->load('designtrace@designtrace');
		$designid = GETPOSTINT('designtrace_design');
		$designs = DesignTraceLicensing::fetchDesignsOfDocument($this->db, $object->element, (int) $object->id);
		if (!$user->hasRight('designtrace', 'design', 'write')) {
			setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
		} elseif (!isset($designs[$designid])) {
			setEventMessages($langs->trans('ErrorRecordNotFound'), null, 'errors');
		} else {
			$res = DesignTracePortfolio::setConsent($this->db, $designid, GETPOSTINT('designtrace_consent'), DesignTracePortfolio::CHANNEL_BACKOFFICE, $user->login, $object->element, (int) $object->id, (int) $user->id, GETPOST('designtrace_consent_note', 'alphanohtml'));
			if ($res < 0) {
				setEventMessages($this->db->lasterror() ?: $langs->trans('Error'), null, 'errors');
			} elseif ($res > 0) {
				setEventMessages($langs->trans('PortfolioConsentSaved'), null, 'mesgs');
			}
		}
	}

	/**
	 *	Rows for the quote and order cards: portfolio consent of each design, with buttons to
	 *	record the customer's answer
	 *
	 *	@param	CommonObject	$object		Quote or order
	 *	@return	string						HTML rows ('' if nothing to show)
	 */
	private function getDocumentConsentRows($object)
	{
		global $langs, $user, $form;

		if (!$user->hasRight('designtrace', 'design', 'read')) {
			return '';
		}
		dol_include_once('/designtrace/class/licensing.class.php');
		dol_include_once('/designtrace/class/portfolio.class.php');
		$langs->load('designtrace@designtrace');
		$designs = DesignTraceLicensing::fetchDesignsOfDocument($this->db, $object->element, (int) $object->id);
		if (empty($designs)) {
			return '';
		}
		$canset = $user->hasRight('designtrace', 'design', 'write');
		$lines = array();
		foreach ($designs as $id => $design) {
			$consent = (int) DesignTracePortfolio::getConsent($this->db, $id);
			$line = dol_escape_htmltag($design['ref']).': '.DesignTracePortfolio::getConsentBadge($consent);
			if (!empty($design['confidential'])) {
				$line .= ' <span class="opacitymedium">('.$langs->trans('PortfolioConfidentialNote').')</span>';
			}
			if ($canset) {
				$base = $_SERVER['PHP_SELF'].'?id='.((int) $object->id).'&action=designtrace_setconsent&designtrace_design='.((int) $id).'&token='.newToken();
				if ($consent !== DesignTracePortfolio::CONSENT_GRANTED) {
					$line .= ' <a class="marginleftonly" href="'.$base.'&designtrace_consent='.DesignTracePortfolio::CONSENT_GRANTED.'">'.$langs->trans('PortfolioConsentRecordGranted').'</a>';
				}
				if ($consent !== DesignTracePortfolio::CONSENT_REFUSED) {
					$line .= ' <a class="marginleftonly" href="'.$base.'&designtrace_consent='.DesignTracePortfolio::CONSENT_REFUSED.'">'.$langs->trans('PortfolioConsentRecordRefused').'</a>';
				}
			}
			$lines[] = $line;
		}
		$out = '<tr><td class="titlefield">'.$form->textwithpicto($langs->trans('PortfolioConsent'), $langs->trans('PortfolioConsentHelp')).'</td><td>';
		$out .= implode('<br>', $lines);
		$out .= '</td></tr>';
		return $out;
	}

	/**
	 *	Whether the user can set the licence of a quote or order
	 *
	 *	@param	CommonObject	$object		Quote or order
	 *	@return	bool
	 */
	private function canSetDocumentLicence($object)
	{
		global $user;

		$right = ($object->element == 'propal' ? $user->hasRight('propal', 'creer') : $user->hasRight('commande', 'creer'));
		return $right && $user->hasRight('designtrace', 'design', 'write') && $user->hasRight('designtrace', 'licence', 'read');
	}

	/**
	 *	Rows for the quote and order cards: the licence of each design on the document (its own or
	 *	the document's override), the version that counts and whether the customer accepted it
	 *
	 *	@param	CommonObject	$object		Quote or order
	 *	@param	string			$action		Current action
	 *	@return	string						HTML rows ('' if nothing to show)
	 */
	private function getDocumentLicenceRows($object, $action)
	{
		global $conf, $langs, $user, $form;

		if (!isModEnabled('designtrace') || empty($object->id) || !$user->hasRight('designtrace', 'licence', 'read')) {
			return '';
		}
		dol_include_once('/designtrace/class/licensing.class.php');
		$langs->load('designtrace@designtrace');
		$licences = DesignTraceLicensing::fetchDocumentLicences($this->db, $object->element, (int) $object->id);
		$override = DesignTraceLicensing::getOverride($this->db, $object->element, (int) $object->id);
		if (empty($licences) && $override <= 0) {
			return '';
		}
		$selfurl = $_SERVER['PHP_SELF'].'?id='.((int) $object->id);
		$canset = $this->canSetDocumentLicence($object);

		$out = '<tr><td class="titlefield">';
		$out .= '<table class="nobordernopadding centpercent"><tr><td>'.$form->textwithpicto($langs->trans('Licence'), $langs->trans('DocumentLicenceHelp')).'</td>';
		if ($canset && $action != 'designtrace_editlicence') {
			$out .= '<td class="right"><a class="editfielda reposition" href="'.$selfurl.'&action=designtrace_editlicence&token='.newToken().'">'.img_edit($langs->transnoentitiesnoconv('LicenceOverrideSet'), 1).'</a></td>';
		}
		$out .= '</tr></table></td><td>';
		if ($canset && $action == 'designtrace_editlicence') {
			$out .= '<form method="POST" action="'.$selfurl.'" class="inline-block">';
			$out .= '<input type="hidden" name="token" value="'.newToken().'">';
			$out .= '<input type="hidden" name="action" value="designtrace_setlicence">';
			$out .= $form->selectarray('designtrace_licence', Licence::getEnabledList($this->db), $override, $langs->trans('LicenceOverrideNone'), 0, 0, '', 0, 0, 0, '', 'minwidth200');
			$out .= ' <input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('Save')).'">';
			$out .= '</form><br>';
		}
		$entity = (empty($object->entity) ? $conf->entity : $object->entity);
		$modulepart = ($object->element == 'propal' ? 'propal' : 'commande');
		$lines = array();
		foreach ($licences as $info) {
			$line = dol_escape_htmltag($info['design']['ref']).': ';
			if (!$info['licence']) {
				$line .= '<span class="opacitymedium">'.$langs->trans('LicenceNone').'</span>';
			} else {
				$line .= $info['licence']->getNomUrl(1);
				$line .= ' <span class="opacitymedium">('.$langs->trans($info['source'] == 'document' ? 'LicenceFromDocument' : 'LicenceFromDesign').')</span>';
				if (!$info['version']) {
					$line .= ' '.img_warning().' <span class="warning">'.$langs->trans('LicenceNoCurrentVersion').'</span>';
				} elseif ($info['acceptance']) {
					$acc = $info['acceptance'];
					$line .= '<br>'.img_picto('', 'fa-check-circle', 'class="pictofixedwidth" style="color: green"').$langs->trans('LicenceAcceptedOn', $info['version']->ref, dol_print_date($acc->date_accept, 'dayhour'), dol_escape_htmltag((string) $acc->actor));
					if (!empty($acc->filename)) {
						$line .= ' <a href="'.DOL_URL_ROOT.'/document.php?modulepart='.$modulepart.'&entity='.((int) $entity).'&file='.urlencode(dol_sanitizeFileName($object->ref).'/'.$acc->filename).'">'.img_mime($acc->filename).'</a>';
					}
				} else {
					$line .= '<br><span class="opacitymedium">'.$langs->trans('LicenceNotAcceptedYet', $info['version']->ref).'</span>';
				}
				if ($info['version'] && (int) $info['version']->perm_source === 1 && !Design::ownershipAllowsSource($info['design']['ownership'])) {
					$line .= '<br>'.img_warning().' <span class="opacitymedium">'.$langs->trans('LicenceSourceBlockedByOwnership').'</span>';
				}
			}
			$lines[] = $line;
		}
		if (empty($lines) && $override > 0) {
			$licence = new Licence($this->db);
			$licence->fetch($override);
			$lines[] = $licence->getNomUrl(1).' <span class="opacitymedium">('.$langs->trans('LicenceFromDocument').')</span>';
		}
		$out .= implode('<br>', $lines);
		$out .= '</td></tr>';
		return $out;
	}


	/**
	 *	On a BOM card, show the design revision(s) of the assembly it is linked to, with a warning
	 *	when the assembly has a newer revision or needs updating.
	 *
	 *	@param	array<string,mixed>	$parameters		Hook parameters
	 *	@param	CommonObject		$object			BOM shown
	 *	@param	string				$action			Current action
	 *	@param	HookManager			$hookmanager	Hook manager
	 *	@return	int									0
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		$contexts = explode(':', $parameters['context']);
		if ((in_array('propalcard', $contexts) || in_array('ordercard', $contexts)) && in_array($object->element, array('propal', 'commande'), true)) {
			$this->resprints = $this->getDocumentLicenceRows($object, $action).$this->getDocumentConsentRows($object);
			return 0;
		}
		if (!in_array('bomcard', $contexts) || !isModEnabled('designtrace') || empty($object->id) || $object->element != 'bom') {
			return 0;
		}
		if (!$user->hasRight('designtrace', 'componentrevision', 'read')) {
			return 0;
		}

		dol_include_once('/designtrace/class/doclinks.class.php');
		dol_include_once('/designtrace/class/componentlink.class.php');
		$langs->load('designtrace@designtrace');

		$rows = DesignTraceDocLinks::fetchRevisionsOfDocument($this->db, $object);
		if (empty($rows)) {
			return 0;
		}
		$out = '';
		foreach ($rows as $row) {
			$out .= '<tr><td>'.$langs->trans('DesignRevision').'</td><td>';
			$out .= '<a href="'.dol_buildpath('/designtrace/componentrevision_card.php', 1).'?id='.((int) $row->rev_id).'">'.img_picto('', 'fa-code-branch', 'class="pictofixedwidth"').dol_escape_htmltag($row->rev_ref).'</a>';
			$out .= ' <span class="opacitymedium">'.dol_escape_htmltag((string) $row->comp_label).'</span>';
			if ($row->current_rev_id && $row->current_rev_id > (int) $row->rev_id) {
				$out .= '<br>'.img_warning().' <span class="warning">'.$langs->trans('BomNewerRevision', dol_escape_htmltag($row->comp_ref), dol_escape_htmltag($row->current_rev)).'</span>';
			}
			if ($row->flag) {
				$out .= '<br>'.ComponentLink::getFlagBadge((int) $row->flag).' <span class="warning">'.$langs->trans($row->flag == ComponentLink::FLAG_NEEDS_UPDATE ? 'BomAssemblyNeedsUpdate' : 'BomAssemblyIndirect', dol_escape_htmltag($row->comp_ref)).'</span>';
			}
			$out .= '</td></tr>';
		}
		$this->resprints = $out;

		return 0;
	}

	/**
	 * @var array<string,int>|null	Visible file counts per document of the portal's customer ("element_id" => count)
	 */
	private $portalCounts = null;

	/**
	 *	Web portal: register the "Design files" page
	 *
	 *	@param	array<string,mixed>	$parameters		Hook parameters
	 *	@param	Context				$object			Web portal context
	 *	@param	string				$action			Current action
	 *	@param	HookManager			$hookmanager	Hook manager
	 *	@return	int									0
	 */
	public function initController($parameters, &$object, &$action, $hookmanager)
	{
		if (!isModEnabled('designtrace') || !is_object($object) || !method_exists($object, 'addControllerDefinition')) {
			return 0;
		}
		$object->addControllerDefinition('designfiles', dol_buildpath('/designtrace/webportal/designfiles.controller.class.php', 0), 'DesignFilesController');
		return 0;
	}

	/**
	 *	Web portal: "Design files" menu entry
	 *
	 *	@param	array<string,mixed>	$parameters		Hook parameters
	 *	@param	Context				$object			Web portal context
	 *	@param	string				$action			Current action
	 *	@param	HookManager			$hookmanager	Hook manager
	 *	@return	int									0 so the standard menu is kept
	 */
	public function PrintTopMenu($parameters, &$object, &$action, $hookmanager)	// phpcs:ignore
	{
		global $langs;

		if (!$this->portalEnabled($object) || !$object->userIsLog()) {
			return 0;
		}
		$langs->load('designtrace@designtrace');
		$this->results['designtrace_files'] = array(
			'id' => 'designtrace_files',
			'rank' => 25,
			'url' => $object->getControllerUrl('designfiles'),
			'name' => $langs->trans('WebPortalDesignFiles'),
			'group' => 'administrative',
		);
		return 0;
	}

	/**
	 *	Web portal quote and order lists: "Design files" column title
	 *
	 *	@param	array<string,mixed>	$parameters		Hook parameters
	 *	@param	Context				$object			Web portal context
	 *	@param	string				$action			Current action
	 *	@param	HookManager			$hookmanager	Hook manager
	 *	@return	int									0
	 */
	public function printFieldListTitle($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		if ($this->portalListElement($object) === '') {
			return 0;
		}
		$langs->load('designtrace@designtrace');
		$this->resprints = '<th scope="col">'.$langs->trans('WebPortalDesignFiles').'</th>';
		return 0;
	}

	/**
	 *	Web portal quote and order lists: empty filter cell under the "Design files" column
	 *
	 *	@param	array<string,mixed>	$parameters		Hook parameters
	 *	@param	Context				$object			Web portal context
	 *	@param	string				$action			Current action
	 *	@param	HookManager			$hookmanager	Hook manager
	 *	@return	int									0
	 */
	public function printFieldListOption($parameters, &$object, &$action, $hookmanager)
	{
		if ($this->portalListElement($object) === '') {
			return 0;
		}
		$this->resprints = '<td></td>';
		return 0;
	}

	/**
	 *	Web portal quote and order lists: link to the document's design files
	 *
	 *	@param	array<string,mixed>	$parameters		Hook parameters
	 *	@param	Context				$object			Web portal context
	 *	@param	string				$action			Current action
	 *	@param	HookManager			$hookmanager	Hook manager
	 *	@return	int									0
	 */
	public function printFieldListValue($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$element = $this->portalListElement($object);
		if ($element === '' || empty($parameters['record'])) {
			return 0;
		}
		if ($this->portalCounts === null) {
			dol_include_once('/designtrace/class/clientaccess.class.php');
			$this->portalCounts = array();
			$socid = (int) (empty($object->logged_thirdparty) ? 0 : $object->logged_thirdparty->id);
			$rows = DesignTraceClientAccess::fetchVisibleFiles($this->db, $socid);
			foreach ((is_array($rows) ? $rows : array()) as $row) {
				$key = $row->element.'_'.$row->doc_id;
				$this->portalCounts[$key] = (isset($this->portalCounts[$key]) ? $this->portalCounts[$key] : 0) + 1;
			}
		}
		$docid = (int) (isset($parameters['record']->rowid) ? $parameters['record']->rowid : 0);
		$nb = isset($this->portalCounts[$element.'_'.$docid]) ? $this->portalCounts[$element.'_'.$docid] : 0;
		$langs->load('designtrace@designtrace');
		$out = '<td data-label="'.dolPrintHTMLForAttribute($langs->trans('WebPortalDesignFiles')).'">';
		if ($nb > 0) {
			$url = $object->getControllerUrl('designfiles', array('element' => $element, 'docid' => $docid));
			$out .= '<a href="'.dolPrintHTMLForAttributeUrl($url).'">'.$langs->trans('WebPortalDesignFilesCount', $nb).'</a>';
		}
		$out .= '</td>';
		$this->resprints = $out;
		return 0;
	}

	/**
	 *	Whether the portal's Design files are on for this hook call
	 *
	 *	@param	mixed	$object		Hook object
	 *	@return	bool
	 */
	private function portalEnabled($object)
	{
		return isModEnabled('designtrace') && !getDolGlobalInt('DESIGNTRACE_WEBPORTAL_HIDE') && is_object($object) && get_class($object) === 'Context';
	}

	/**
	 *	Element of the portal list being shown, if it gets a Design files column
	 *
	 *	@param	mixed	$object		Hook object
	 *	@return	string				'propal', 'commande' or ''
	 */
	private function portalListElement($object)
	{
		if (!$this->portalEnabled($object) || !$object->userIsLog()) {
			return '';
		}
		$map = array('propallist' => 'propal', 'orderlist' => 'commande');
		return isset($map[$object->controller]) ? $map[$object->controller] : '';
	}

	/**
	 * document.php: refuse files of confidential designs the user cannot see (design, component
	 * and revision folders of the module)
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters (modulepart, fullpath_original_file...)
	 * @param	object				$object			Not used
	 * @param	string				$action			Current action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int									0, or -1 to refuse the download
	 */
	public function downloadDocument($parameters, &$object, &$action, $hookmanager)
	{
		global $user;

		if (!isModEnabled('designtrace') || ($parameters['modulepart'] ?? '') !== 'designtrace' || empty($user->id)) {
			return 0;
		}
		dol_include_once('/designtrace/class/design.class.php');
		if (Design::canReadAllConfidential($user)) {
			return 0;
		}
		$path = str_replace('\\', '/', (string) ($parameters['original_file'] ?? ''));
		if (!preg_match('#(?:^|/)(design|component|componentrevision)/([^/]+)/[^/]+$#', $path, $m)) {
			return 0;
		}
		$tables = array('design' => 'designtrace_design', 'component' => 'designtrace_component', 'componentrevision' => 'designtrace_componentrevision');
		$sql = "SELECT t.rowid FROM ".$this->db->prefix().$tables[$m[1]]." as t WHERE t.ref = '".$this->db->escape($m[2])."' AND t.entity IN (".getEntity($m[1]).")";
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			return 0;
		}
		$classes = array('design' => 'Design', 'component' => 'Component', 'componentrevision' => 'ComponentRevision');
		dol_include_once('/designtrace/class/'.$m[1].'.class.php');
		$target = new $classes[$m[1]]($this->db);
		if ($target->fetch((int) $obj->rowid) <= 0) {
			return 0;
		}
		$designid = Design::getDesignIdOf($this->db, $target);
		if ($designid > 0 && in_array($designid, Design::getHiddenDesignIds($this->db, $user), true)) {
			$this->errors[] = 'DesignConfidentialNoAccess';
			return -1;
		}
		return 0;
	}
}
