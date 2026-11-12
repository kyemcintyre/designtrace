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
 *	Hooks of DesignTrace: a "Create design" button on quote and order cards.
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
}
