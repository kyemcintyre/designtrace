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
}
