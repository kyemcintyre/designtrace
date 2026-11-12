<?php
/* Copyright (C) 2017       Laurent Destailleur      <eldy@users.sourceforge.net>
 * Copyright (C) 2023-2025  Frédéric France          <frederic.france@free.fr>
 * Copyright (C) 2026		Kye McIntyre
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
 * \file        designtrace/class/changeorder.class.php
 * \ingroup     designtrace
 * \brief       This file is a CRUD class file for ChangeOrder (Create/Read/Update/Delete)
 */

// Put here all includes required by your class file
require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
dol_include_once('/designtrace/class/componentrevision.class.php');

/**
 * Class for ChangeOrder
 */
class ChangeOrder extends CommonObject
{
	/**
	 * @var string 		ID of module.
	 */
	public $module = 'designtrace';

	/**
	 * @var string 		ID to identify managed object.
	 */
	public $element = 'changeorder';

	/**
	 * @var string		Prefix to check for any trigger code of any business class to prevent bad value for trigger code.
	 * @see CommonTrigger::call_trigger()
	 */
	public $TRIGGER_PREFIX = 'DESIGNTRACE_CHANGEORDER';	// Will be used to build trgiger keys 'DESIGNTRACE_CHANGEORDER_MODIFY', ...

	/**
	 * @var string 		Name of table without prefix where object is stored. This is also the key used for extrafields management (so extrafields know the link to the parent table).
	 */
	public $table_element = 'designtrace_changeorder';

	/**
	 * @var string 		If permission must be checked with hasRight('designtrace', 'read') and not hasright('designtrace', 'changeorder', 'read'), you can uncomment this line
	 */
	//public $element_for_permission = 'designtrace';

	/**
	 * @var string 		String with name of icon for changeorder. Must be a 'fa-xxx' fontawesome code (or 'fa-xxx_fa_color_size') or 'changeorder@designtrace' if picto is file 'img/object_changeorder.png'.
	 */
	public $picto = 'fa-exchange-alt';

	/**
	 * @var int<0,1>	Does object support extrafields ? 0=No, 1=Yes
	 */
	public $isextrafieldmanaged = 1;

	/**
	 * @var int<0,1>|string		Does this object support multicompany module ?
	 * 							0=No test on entity, 1=Test with field entity in local table, 'field@table'=Test entity into the field@table (example 'fk_soc@societe')
	 */
	public $ismultientitymanaged = 1;


	const STATUS_DRAFT = 0;
	const STATUS_APPROVED = 1;
	const STATUS_IMPLEMENTED = 2;
	const STATUS_CLOSED = 3;
	const STATUS_CANCELED = 9;
	/**
	 * @deprecated Module Builder name of STATUS_APPROVED
	 */
	const STATUS_VALIDATED = 1;

	/**
	 * Reasons for a change
	 */
	const REASON_CUSTOMER = 1;
	const REASON_DEFECT = 2;
	const REASON_IMPROVEMENT = 3;
	const REASON_COST = 4;
	const REASON_MATERIAL = 5;
	const REASON_OTHER = 6;

	/**
	 *  'type' field format, 'label', 'enabled', 'position', 'notnull', 'visible', 'index', ... as in
	 *  other Module Builder objects. Edit together with sql/llx_designtrace_changeorder.sql.
	 */

	// BEGIN MODULEBUILDER PROPERTIES
	/**
	 * @inheritdoc
	 * Array with all fields and their property. Do not use it as a static var. It may be modified by constructor.
	 */
	public $fields = array(
		"rowid" => array("type" => "integer", "label" => "TechnicalID", "enabled" => "1", 'position' => 1, 'notnull' => 1, "visible" => "0", "noteditable" => "1", "index" => "1", "css" => "left", "comment" => "Id"),
		"entity" => array("type" => "integer", "label" => "Entity", "enabled" => "1", 'position' => 5, 'notnull' => 1, "visible" => "0", "default" => "1", "index" => "1",),
		"ref" => array("type" => "varchar(128)", "label" => "Ref", "enabled" => "1", 'position' => 10, 'notnull' => 1, "visible" => "4", "noteditable" => "1", "index" => "1", "searchall" => "1", "showoncombobox" => "1", "comment" => "ECO number, given at creation"),
		"label" => array("type" => "varchar(255)", "label" => "EcoSummary", "enabled" => "1", 'position' => 20, 'notnull' => 1, "visible" => "1", "alwayseditable" => "1", "searchall" => "1", "css" => "minwidth400", "cssview" => "wordbreak", "help" => "EcoSummaryHelp", "showoncombobox" => "2",),
		"reason" => array("type" => "integer", "label" => "EcoReason", "enabled" => "1", 'position' => 30, 'notnull' => 1, "visible" => "1", "default" => "1", "index" => "1", "arrayofkeyval" => array("1" => "EcoReasonCustomer", "2" => "EcoReasonDefect", "3" => "EcoReasonImprovement", "4" => "EcoReasonCost", "5" => "EcoReasonMaterial", "6" => "EcoReasonOther"),),
		"fk_soc" => array("type" => "integer:Societe:societe/class/societe.class.php:1:((status:=:1) AND (entity:IN:__SHARED_ENTITIES__))", "label" => "ThirdParty", "picto" => "company", "enabled" => "isModEnabled('societe')", 'position' => 40, 'notnull' => -1, "visible" => "1", "index" => "1", "css" => "maxwidth500 widthcentpercentminusxx", "csslist" => "tdoverflowmax150", "help" => "EcoCustomerHelp",),
		"fk_project" => array("type" => "integer:Project:projet/class/project.class.php:1", "label" => "Project", "picto" => "project", "enabled" => "isModEnabled('project')", 'position' => 45, 'notnull' => -1, "visible" => "-1", "index" => "1", "css" => "maxwidth500 widthcentpercentminusxx", "csslist" => "tdoverflowmax150",),
		"fk_user_requester" => array("type" => "integer:User:user/class/user.class.php", "label" => "EcoRequester", "picto" => "user", "enabled" => "1", 'position' => 50, 'notnull' => -1, "visible" => "1", "index" => "1", "css" => "maxwidth300", "csslist" => "tdoverflowmax150",),
		"description" => array("type" => "html", "label" => "Description", "enabled" => "1", 'position' => 60, 'notnull' => 0, "visible" => "3", "cssview" => "wordbreak",),
		"fk_user_approve" => array("type" => "integer:User:user/class/user.class.php", "label" => "EcoApprover", "picto" => "user", "enabled" => "1", 'position' => 70, 'notnull' => -1, "visible" => "5", "noteditable" => "1", "csslist" => "tdoverflowmax150",),
		"date_approve" => array("type" => "datetime", "label" => "EcoDateApprove", "enabled" => "1", 'position' => 71, 'notnull' => 0, "visible" => "5", "noteditable" => "1",),
		"note_public" => array("type" => "html", "label" => "NotePublic", "enabled" => "1", 'position' => 61, 'notnull' => 0, "visible" => "0", "cssview" => "wordbreak",),
		"note_private" => array("type" => "html", "label" => "NotePrivate", "enabled" => "1", 'position' => 62, 'notnull' => 0, "visible" => "0", "cssview" => "wordbreak",),
		"date_creation" => array("type" => "datetime", "label" => "DateCreation", "enabled" => "1", 'position' => 500, 'notnull' => 1, "visible" => "-2",),
		"tms" => array("type" => "timestamp", "label" => "DateModification", "enabled" => "1", 'position' => 501, 'notnull' => 0, "visible" => "-2",),
		"fk_user_creat" => array("type" => "integer:User:user/class/user.class.php", "label" => "UserAuthor", "picto" => "user", "enabled" => "1", 'position' => 510, 'notnull' => 1, "visible" => "-2", "csslist" => "tdoverflowmax150",),
		"fk_user_modif" => array("type" => "integer:User:user/class/user.class.php", "label" => "UserModif", "picto" => "user", "enabled" => "1", 'position' => 511, 'notnull' => -1, "visible" => "-2", "csslist" => "tdoverflowmax150",),
		"last_main_doc" => array("type" => "varchar(255)", "label" => "LastMainDoc", "enabled" => "1", 'position' => 600, 'notnull' => 0, "visible" => "0",),
		"import_key" => array("type" => "varchar(14)", "label" => "ImportId", "enabled" => "1", 'position' => 1000, 'notnull' => -1, "visible" => "-2",),
		"model_pdf" => array("type" => "varchar(255)", "label" => "Model pdf", "enabled" => "1", 'position' => 1010, 'notnull' => -1, "visible" => "0",),
		"status" => array("type" => "integer", "label" => "Status", "enabled" => "1", 'position' => 2000, 'notnull' => 1, "visible" => "2", "default" => "0", "index" => "1", "arrayofkeyval" => array("0" => "Draft", "1" => "EcoApproved", "2" => "EcoImplemented", "3" => "EcoClosed", "9" => "Canceled"),),
	);
	public $rowid;
	public $entity;
	public $ref;
	public $label;
	public $reason;
	public $fk_soc;
	public $fk_project;
	public $fk_user_requester;
	public $description;
	public $fk_user_approve;
	public $date_approve;
	public $note_public;
	public $note_private;
	public $date_creation;
	public $tms;
	public $fk_user_creat;
	public $fk_user_modif;
	public $last_main_doc;
	public $import_key;
	public $model_pdf;
	public $status;
	// END MODULEBUILDER PROPERTIES

	/**
	 * Constructor
	 *
	 * @param	DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		global $langs;

		$this->db = $db;

		if (!getDolGlobalInt('MAIN_SHOW_TECHNICAL_ID') && isset($this->fields['rowid']) && !empty($this->fields['ref'])) {
			$this->fields['rowid']['visible'] = 0;
		}
		if (!isModEnabled('multicompany') && isset($this->fields['entity'])) {
			$this->fields['entity']['enabled'] = 0;
		}

		// Example to show how to set values of fields definition dynamically
		/*if ($user->hasRight('designtrace', 'changeorder', 'read')) {
			$this->fields['myfield']['visible'] = 1;
			$this->fields['myfield']['noteditable'] = 0;
		}*/

		// Unset fields that are disabled
		foreach ($this->fields as $key => $val) {
			if (isset($val['enabled']) && empty($val['enabled'])) {
				unset($this->fields[$key]);
			}
		}

		// Translate some data of arrayofkeyval
		if (is_object($langs)) {
			foreach ($this->fields as $key => $val) {
				if (!empty($val['arrayofkeyval']) && is_array($val['arrayofkeyval'])) {
					foreach ($val['arrayofkeyval'] as $key2 => $val2) {
						$this->fields[$key]['arrayofkeyval'][$key2] = $langs->trans($val2);
					}
				}
			}
		}
	}

	/**
	 * Create object into database
	 *
	 * @param	User		$user		User that creates
	 * @param	int<0,1> 	$notrigger	0=launch triggers after, 1=disable triggers
	 * @return	int<-1,max>				Return integer <0 if KO, Id of created object if OK
	 */
	public function create(User $user, $notrigger = 0)
	{
		global $langs;

		if (empty($this->fk_user_requester)) {
			$this->fk_user_requester = $user->id;
		}
		if (trim((string) $this->label) === '') {
			$langs->load('designtrace@designtrace');
			$this->error = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('EcoSummary'));
			$this->errors[] = $this->error;
			return -1;
		}

		// Numbered at creation (like components), so an ECO raised from a ticket can be quoted
		// to the customer straight away
		$this->status = self::STATUS_DRAFT;
		$ref = $this->getNextNumRef();
		if (empty($ref) || !is_string($ref)) {
			if (empty($this->error)) {
				$this->error = 'ErrorChangeOrderNumberingFailed';
			}
			return -1;
		}
		$this->ref = $ref;

		$this->db->begin();

		// createCommon() stores an empty date_creation back on the object as a DB string, which a
		// later updateCommon() on the same object shifts by the timezone offset: give it a timestamp
		if (empty($this->date_creation)) {
			$this->date_creation = dol_now();
		}
		$result = $this->createCommon($user, $notrigger);

		// Origin (a ticket): link it, and add the components of the revisions linked to it
		if ($result > 0 && !empty($this->linked_objects) && is_array($this->linked_objects)) {
			foreach ($this->linked_objects as $type => $ids) {
				foreach ((array) $ids as $id) {
					if ((int) $id <= 0) {
						continue;
					}
					if (!$this->add_object_linked($type, (int) $id)) {
						$result = $this->setErrorAndFail($this->db->lasterror());
						break 2;
					}
					if ($type == 'ticket' && $this->addComponentsOfTicket($user, (int) $id) < 0) {
						$result = -1;
						break 2;
					}
				}
			}
		}

		if ($result > 0) {
			$this->db->commit();
		} else {
			$this->db->rollback();
			$this->id = 0;
		}

		return $result;
	}

	/**
	 * Add as affected the components whose revisions are linked to a ticket
	 *
	 * @param	User	$user		User
	 * @param	int		$ticketid	Ticket id
	 * @return	int					Number added, <0 if KO
	 */
	private function addComponentsOfTicket($user, $ticketid)
	{
		global $langs;

		$sql = "SELECT DISTINCT r.fk_component, r.ref FROM ".$this->db->prefix()."element_element as ee";
		$sql .= " INNER JOIN ".$this->db->prefix()."designtrace_componentrevision as r ON r.rowid = ee.fk_target";
		$sql .= " WHERE ee.sourcetype = 'ticket' AND ee.fk_source = ".((int) $ticketid)." AND ee.targettype = 'designtrace_componentrevision'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return $this->setErrorAndFail($this->db->lasterror());
		}
		$langs->load('designtrace@designtrace');
		$nb = 0;
		while ($obj = $this->db->fetch_object($resql)) {
			if ($this->addAffectedComponent($user, (int) $obj->fk_component, $langs->transnoentities('EcoAffectedFromTicket', $obj->ref), 1) < 0) {
				return -1;
			}
			$nb++;
		}
		return $nb;
	}

	/**
	 * Clone an object into another one
	 *
	 * @param	User 	$user		User that creates
	 * @param	int 	$fromid		Id of object to clone
	 * @return	self|int<-1,-1>		New object created, <0 if KO
	 */
	public function createFromClone(User $user, $fromid)
	{
		global $langs, $extrafields;
		$error = 0;

		dol_syslog(__METHOD__, LOG_DEBUG);

		$object = new self($this->db);

		$this->db->begin();

		// Load source object
		$result = $object->fetchCommon($fromid);
		// Reset some properties
		unset($object->id);
		unset($object->fk_user_creat);
		unset($object->import_key);

		// Clear fields
		if (property_exists($object, 'ref')) {
			$object->ref = empty($this->fields['ref']['default']) ? "Copy_Of_".$object->ref : $this->fields['ref']['default'];
		}
		if (property_exists($object, 'label')) {
			$object->label = empty($this->fields['label']['default']) ? $langs->trans("CopyOf")." ".$object->label : $this->fields['label']['default'];
		}
		if (property_exists($object, 'status')) {
			$object->status = self::STATUS_DRAFT;
		}
		$object->fk_user_approve = null;
		$object->date_approve = null;
		$object->fk_user_requester = $user->id;
		if (property_exists($object, 'date_creation')) {
			$object->date_creation = dol_now();
		}
		if (property_exists($object, 'date_modification')) {
			$object->date_modification = null;
		}
		// ...
		// Clear extrafields that are unique
		if (is_array($object->array_options) && count($object->array_options) > 0) {
			$extrafields->fetch_name_optionals_label($this->table_element);
			foreach ($object->array_options as $key => $option) {
				$shortkey = preg_replace('/options_/', '', $key);
				if (!empty($extrafields->attributes[$this->table_element]['unique'][$shortkey])) {
					//var_dump($key);
					//var_dump($clonedObj->array_options[$key]); exit;
					unset($object->array_options[$key]);
				}
			}
		}

		// Create clone
		$object->context['createfromclone'] = 'createfromclone';
		$result = $object->createCommon($user);
		if ($result < 0) {
			$error++;
			$this->setErrorsFromObject($object);
		}

		if (!$error) {
			// copy internal contacts
			if ($this->copy_linked_contact($object, 'internal') < 0) {
				$error++;
			}
		}

		if (!$error) {
			// copy external contacts if same company
			if (!empty($object->socid) && ((property_exists($this, 'fk_soc') && ($this->fk_soc == $object->socid)) || (property_exists($this, 'socid') && ($this->socid == $object->socid)))) {	// @phpstan-ignore-line
				if ($this->copy_linked_contact($object, 'external') < 0) {
					$error++;
				}
			}
		}

		unset($object->context['createfromclone']);

		// End
		if (!$error) {
			$this->db->commit();
			return $object;
		} else {
			$this->db->rollback();
			return -1;
		}
	}

	/**
	 * Load object in memory from the database
	 *
	 * @param	int    		$id   			Id object
	 * @param	string 		$ref  			Ref
	 * @param	int<0,1>	$noextrafields	0=Default to load extrafields, 1=No extrafields
	 * @param	int<0,1>	$nolines		0=Default to load lines, 1=No lines
	 * @return	int<-1,1>					Return integer <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id, $ref = null, $noextrafields = 0, $nolines = 0)
	{
		$result = $this->fetchCommon($id, $ref, '', $noextrafields);
		return $result;
	}

	/**
	 * Load object lines in memory from the database
	 *
	 * @param	int<0,1>	$noextrafields	0=Default to load extrafields, 1=No extrafields
	 * @return 	int<-1,1>					Return integer <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetchLines($noextrafields = 0)
	{
		$this->lines = array();

		$result = $this->fetchLinesCommon('', $noextrafields);
		return $result;
	}
	//END MODULEBUILDER LINES
	/**
	 * Load list of objects in memory from the database.
	 * Using a fetchAll() with limit = 0 is a very bad practice. Instead try to forge yourself an optimized SQL request with
	 * your own loop with start and stop pagination.
	 *
	 * @param	string		$sortorder	Sort Order
	 * @param	string		$sortfield	Sort field
	 * @param	int<0,max>	$limit		Limit the number of lines returned
	 * @param	int<0,max>	$offset		Offset
	 * @param	string		$filter		Filter as an Universal Search string.
	 *                                  Example: '((client:=:1) OR ((client:>=:2) AND (client:<=:3))) AND (client:!=:8) AND (nom:like:'a%')'
	 * @param	string		$filtermode	No longer used
	 * @return	array<int,self>|int<-1,-1>	 <0 if KO, array of pages if OK
	 */
	public function fetchAll($sortorder = '', $sortfield = '', $limit = 1000, $offset = 0, string $filter = '', $filtermode = 'AND')
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$records = array();

		$sql = "SELECT ";
		$sql .= $this->getFieldList('t');
		$sql .= " FROM ".$this->db->prefix().$this->table_element." as t";
		if (!empty($this->isextrafieldmanaged) && $this->isextrafieldmanaged == 1) {
			$sql .= " LEFT JOIN ".$this->db->prefix().$this->table_element."_extrafields as te ON te.fk_object = t.rowid";
		}
		if (!empty($this->ismultientitymanaged) && (int) $this->ismultientitymanaged == 1) {
			$sql .= " WHERE t.entity IN (".getEntity($this->element).")";
		} elseif (preg_match('/^\w+@\w+$/', (string) $this->ismultientitymanaged)) {
			$tmparray = explode('@', (string) $this->ismultientitymanaged);
			$sql .= " LEFT JOIN ".$this->db->prefix().$this->db->sanitize($tmparray[1])." as pt ON t.".$this->db->sanitize($tmparray[0])." = pt.rowid";
			$sql .= " WHERE pt.entity IN (".getEntity($this->element).")";
		} else {
			$sql .= " WHERE 1 = 1";
		}

		// Manage filter
		$errormessage = '';
		$sql .= forgeSQLFromUniversalSearchCriteria($filter, $errormessage);
		if ($errormessage) {
			$this->errors[] = $errormessage;
			dol_syslog(__METHOD__.' '.implode(',', $this->errors), LOG_ERR);
			return -1;
		}

		if (!empty($sortfield)) {
			$sql .= $this->db->order($sortfield, $sortorder);
		}
		if (!empty($limit)) {
			$sql .= $this->db->plimit($limit, $offset);
		}

		$resql = $this->db->query($sql);
		if ($resql) {
			$num = $this->db->num_rows($resql);
			$i = 0;
			while ($i < ($limit ? min($limit, $num) : $num)) {
				$obj = $this->db->fetch_object($resql);

				$record = new self($this->db);
				$record->setVarsFromFetchObj($obj);

				if (!empty($record->isextrafieldmanaged)) {
					$record->fetch_optionals();
				}

				$records[$record->id] = $record;

				$i++;
			}
			$this->db->free($resql);

			return $records;
		} else {
			$this->errors[] = 'Error '.$this->db->lasterror();
			dol_syslog(__METHOD__.' '.implode(',', $this->errors), LOG_ERR);

			return -1;
		}
	}

	/**
	 * Update object into database
	 *
	 * @param	User		$user		User that modifies
	 * @param	int<0,1>	$notrigger	0=launch triggers after, 1=disable triggers
	 * @return	int<-1,1>				Return integer <0 if KO, >0 if OK
	 */
	public function update(User $user, $notrigger = 0)
	{
		return $this->updateCommon($user, $notrigger);
	}

	/**
	 * Delete object in database
	 *
	 * @param	User		$user		User that deletes
	 * @param	int<0,1> 	$notrigger	0=launch triggers, 1=disable triggers
	 * @return	int<-1,1>				Return integer <0 if KO, >0 if OK
	 */
	public function delete(User $user, $notrigger = 0)
	{
		global $langs;

		$langs->load('designtrace@designtrace');
		if (!in_array((int) $this->status, array(self::STATUS_DRAFT, self::STATUS_CANCELED), true)) {
			return $this->setErrorAndFail($langs->trans('ErrorEcoDeleteNotDraft', $this->ref));
		}
		$sql = "SELECT ref FROM ".$this->db->prefix()."designtrace_componentrevision WHERE fk_eco = ".((int) $this->id);
		$resql = $this->db->query($sql);
		$revs = array();
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$revs[] = $obj->ref;
		}
		if ($revs) {
			return $this->setErrorAndFail($langs->trans('ErrorEcoUsedByRevisions', $this->ref, implode(', ', $revs)));
		}

		$this->db->begin();
		$result = 1;
		if (!$this->db->query("DELETE FROM ".$this->db->prefix()."designtrace_changeorder_component WHERE fk_eco = ".((int) $this->id))) {
			$result = $this->setErrorAndFail($this->db->lasterror());
		}
		if ($result > 0) {
			$result = $this->deleteCommon($user, $notrigger);
		}
		if ($result > 0) {
			$this->db->commit();
			$this->deleteDocumentsDir();
		} else {
			$this->db->rollback();
		}
		return $result;
	}

	/**
	 *	Delete the object's documents directory and its entries in the documents index, so a
	 *	later object that gets the same ref (numbers are reused after a delete) does not
	 *	inherit them. Called once the record is deleted; a failure is only logged.
	 *
	 *	@return	void
	 */
	private function deleteDocumentsDir()
	{
		global $conf;

		include_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		$entity = (empty($this->entity) ? $conf->entity : $this->entity);
		if (empty($this->ref) || empty($conf->designtrace->multidir_output[$entity])) {
			return;
		}
		$dir = $conf->designtrace->multidir_output[$entity].'/'.$this->element.'/'.dol_sanitizeFileName($this->ref);
		if (file_exists($dir) && !dol_delete_dir_recursive($dir)) {
			dol_syslog(__METHOD__.' could not delete '.$dir, LOG_WARNING);
		}
		$sql = "DELETE FROM ".$this->db->prefix()."ecm_files WHERE filepath = '".$this->db->escape('designtrace/'.$this->element.'/'.dol_sanitizeFileName($this->ref))."'";
		$this->db->query($sql);
	}

	/**
	 * Set an error and return -1
	 *
	 * @param	string	$message	Error
	 * @return	int<-1,-1>
	 */
	private function setErrorAndFail($message)
	{
		$this->error = $message;
		$this->errors[] = $message;
		return -1;
	}

	/**
	 * Approve: Draft -> Approved. Needs the "Approve change orders" right; records who and when.
	 *
	 * @param	User		$user		User approving
	 * @param	int<0,1>	$notrigger	1=Does not execute triggers
	 * @return	int						<0 if KO, 0 if nothing done, >0 if OK
	 */
	public function approve($user, $notrigger = 0)
	{
		global $langs;

		$langs->load('designtrace@designtrace');
		if ((int) $this->status !== self::STATUS_DRAFT) {
			return 0;
		}
		if (!$user->hasRight('designtrace', 'changeorder', 'approve')) {
			return $this->setErrorAndFail($langs->trans('NotEnoughPermissions'));
		}
		if ($this->countAffectedComponents() <= 0) {
			return $this->setErrorAndFail($langs->trans('ErrorEcoNoAffectedComponents', $this->ref));
		}
		$this->db->begin();
		$now = dol_now();
		$sql = "UPDATE ".$this->db->prefix().$this->table_element." SET fk_user_approve = ".((int) $user->id).", date_approve = '".$this->db->idate($now)."'";
		$sql .= " WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->db->rollback();
			return $this->setErrorAndFail($this->db->lasterror());
		}
		$result = $this->setStatusCommon($user, self::STATUS_APPROVED, $notrigger, 'DESIGNTRACE_CHANGEORDER_APPROVE');
		if ($result <= 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		$this->fk_user_approve = $user->id;
		$this->date_approve = $now;
		return 1;
	}

	/**
	 * Back to draft from Approved (clears the approval)
	 *
	 * @param	User		$user		User
	 * @param	int<0,1>	$notrigger	1=Does not execute triggers
	 * @return	int						<0 if KO, 0 if nothing done, >0 if OK
	 */
	public function setDraft($user, $notrigger = 0)
	{
		global $langs;

		$langs->load('designtrace@designtrace');
		if (!in_array((int) $this->status, array(self::STATUS_APPROVED, self::STATUS_CANCELED), true)) {
			return 0;
		}
		$released = $this->fetchRevisions(array(ComponentRevision::STATUS_RELEASED));
		if ((int) $this->status === self::STATUS_APPROVED && !empty($released)) {
			return $this->setErrorAndFail($langs->trans('ErrorEcoHasReleasedRevisions', $this->ref, implode(', ', array_map(function ($r) {
				return $r->ref;
			}, $released))));
		}
		$this->db->begin();
		$sql = "UPDATE ".$this->db->prefix().$this->table_element." SET fk_user_approve = NULL, date_approve = NULL WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->db->rollback();
			return $this->setErrorAndFail($this->db->lasterror());
		}
		$result = $this->setStatusCommon($user, self::STATUS_DRAFT, $notrigger, 'DESIGNTRACE_CHANGEORDER_UNVALIDATE');
		if ($result <= 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		$this->fk_user_approve = null;
		$this->date_approve = null;
		return 1;
	}

	/**
	 * Implemented: Approved -> Implemented (the new revisions are done)
	 *
	 * @param	User		$user		User
	 * @param	int<0,1>	$notrigger	1=Does not execute triggers
	 * @return	int						<0 if KO, 0 if nothing done, >0 if OK
	 */
	public function setImplemented($user, $notrigger = 0)
	{
		if ((int) $this->status !== self::STATUS_APPROVED) {
			return 0;
		}
		return $this->setStatusCommon($user, self::STATUS_IMPLEMENTED, $notrigger, 'DESIGNTRACE_CHANGEORDER_IMPLEMENT');
	}

	/**
	 * Close: Implemented -> Closed
	 *
	 * @param	User		$user		User
	 * @param	int<0,1>	$notrigger	1=Does not execute triggers
	 * @return	int						<0 if KO, 0 if nothing done, >0 if OK
	 */
	public function close($user, $notrigger = 0)
	{
		if ((int) $this->status !== self::STATUS_IMPLEMENTED) {
			return 0;
		}
		return $this->setStatusCommon($user, self::STATUS_CLOSED, $notrigger, 'DESIGNTRACE_CHANGEORDER_CLOSE');
	}

	/**
	 * Cancel (rejected or no longer needed): from Draft or Approved, when no revision made under
	 * it is released
	 *
	 * @param	User		$user		User
	 * @param	int<0,1>	$notrigger	1=Does not execute triggers
	 * @return	int						<0 if KO, 0 if nothing done, >0 if OK
	 */
	public function cancel($user, $notrigger = 0)
	{
		global $langs;

		$langs->load('designtrace@designtrace');
		if (!in_array((int) $this->status, array(self::STATUS_DRAFT, self::STATUS_APPROVED), true)) {
			return 0;
		}
		$released = $this->fetchRevisions(array(ComponentRevision::STATUS_RELEASED));
		if (!empty($released)) {
			return $this->setErrorAndFail($langs->trans('ErrorEcoHasReleasedRevisions', $this->ref, implode(', ', array_map(function ($r) {
				return $r->ref;
			}, $released))));
		}
		return $this->setStatusCommon($user, self::STATUS_CANCELED, $notrigger, 'DESIGNTRACE_CHANGEORDER_CANCEL');
	}

	/**
	 * Reopen one step back: Closed -> Implemented, Implemented -> Approved, Cancelled -> Draft
	 *
	 * @param	User		$user		User
	 * @param	int<0,1>	$notrigger	1=Does not execute triggers
	 * @return	int						<0 if KO, 0 if nothing done, >0 if OK
	 */
	public function reopen($user, $notrigger = 0)
	{
		$back = array(self::STATUS_CLOSED => self::STATUS_IMPLEMENTED, self::STATUS_IMPLEMENTED => self::STATUS_APPROVED);
		if ((int) $this->status === self::STATUS_CANCELED) {
			return $this->setDraft($user, $notrigger);
		}
		if (!isset($back[(int) $this->status])) {
			return 0;
		}
		return $this->setStatusCommon($user, $back[(int) $this->status], $notrigger, 'DESIGNTRACE_CHANGEORDER_REOPEN');
	}

	/**
	 * Whether the list of affected components can change (Draft or Approved)
	 *
	 * @return	bool
	 */
	public function canEditAffected()
	{
		return in_array((int) $this->status, array(self::STATUS_DRAFT, self::STATUS_APPROVED), true);
	}

	/**
	 * Whether revisions can be made under this ECO (not Closed or Cancelled)
	 *
	 * @return	bool
	 */
	public function isOpen()
	{
		return in_array((int) $this->status, array(self::STATUS_DRAFT, self::STATUS_APPROVED, self::STATUS_IMPLEMENTED), true);
	}

	/**
	 * Number of affected components
	 *
	 * @return	int
	 */
	public function countAffectedComponents()
	{
		$sql = "SELECT COUNT(rowid) as nb FROM ".$this->db->prefix()."designtrace_changeorder_component WHERE fk_eco = ".((int) $this->id);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? (int) $obj->nb : 0;
	}

	/**
	 * Affected components, with their design and current released revision
	 *
	 * @return	stdClass[]	Rows: rowid, fk_component, note, comp_ref, comp_label, comp_type, comp_status, design_id, design_ref
	 */
	public function fetchAffectedComponents()
	{
		$rows = array();
		$sql = "SELECT ec.rowid, ec.fk_component, ec.note, c.ref as comp_ref, c.label as comp_label, c.type as comp_type, c.status as comp_status,";
		$sql .= " d.rowid as design_id, d.ref as design_ref";
		$sql .= " FROM ".$this->db->prefix()."designtrace_changeorder_component as ec";
		$sql .= " INNER JOIN ".$this->db->prefix()."designtrace_component as c ON c.rowid = ec.fk_component";
		$sql .= " LEFT JOIN ".$this->db->prefix()."designtrace_design as d ON d.rowid = c.fk_design";
		$sql .= " WHERE ec.fk_eco = ".((int) $this->id);
		$sql .= " ORDER BY c.ref";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$rows[] = $obj;
		}
		return $rows;
	}

	/**
	 * Add an affected component (ignored if already there)
	 *
	 * @param	User	$user		User
	 * @param	int		$compid		Component id
	 * @param	string	$note		Note
	 * @param	int		$force		1 to add even when the list is locked (internal use)
	 * @return	int					>0 if added, 0 if already there, <0 if KO
	 */
	public function addAffectedComponent($user, $compid, $note = '', $force = 0)
	{
		global $conf, $langs;

		$langs->load('designtrace@designtrace');
		if (!$force && !$this->canEditAffected()) {
			return $this->setErrorAndFail($langs->trans('ErrorEcoAffectedLocked', $this->ref));
		}
		dol_include_once('/designtrace/class/component.class.php');
		$component = new Component($this->db);
		if ((int) $compid <= 0 || $component->fetch((int) $compid) <= 0) {
			return $this->setErrorAndFail($langs->trans('ErrorRecordNotFound'));
		}
		$sql = "SELECT rowid FROM ".$this->db->prefix()."designtrace_changeorder_component WHERE fk_eco = ".((int) $this->id)." AND fk_component = ".((int) $compid);
		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql) > 0) {
			return 0;
		}
		$sql = "INSERT INTO ".$this->db->prefix()."designtrace_changeorder_component (entity, fk_eco, fk_component, note, date_creation, fk_user_creat)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $this->id).", ".((int) $compid).", ".(trim((string) $note) === '' ? 'NULL' : "'".$this->db->escape(dol_trunc(trim($note), 255, 'right', 'UTF-8', 1))."'");
		$sql .= ", '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			return $this->setErrorAndFail($this->db->lasterror());
		}
		return 1;
	}

	/**
	 * Remove an affected component (not when revisions were made for it under this ECO)
	 *
	 * @param	User	$user		User
	 * @param	int		$rowid		Row id in llx_designtrace_changeorder_component
	 * @return	int					>0 if OK, <0 if KO
	 */
	public function removeAffectedComponent($user, $rowid)
	{
		global $langs;

		$langs->load('designtrace@designtrace');
		if (!$this->canEditAffected()) {
			return $this->setErrorAndFail($langs->trans('ErrorEcoAffectedLocked', $this->ref));
		}
		$sql = "SELECT ec.fk_component, c.ref FROM ".$this->db->prefix()."designtrace_changeorder_component as ec";
		$sql .= " INNER JOIN ".$this->db->prefix()."designtrace_component as c ON c.rowid = ec.fk_component";
		$sql .= " WHERE ec.rowid = ".((int) $rowid)." AND ec.fk_eco = ".((int) $this->id);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			return $this->setErrorAndFail($langs->trans('ErrorRecordNotFound'));
		}
		foreach ($this->fetchRevisions() as $rev) {
			if ((int) $rev->fk_component === (int) $obj->fk_component) {
				return $this->setErrorAndFail($langs->trans('ErrorEcoComponentHasRevisions', $obj->ref, $rev->ref));
			}
		}
		if (!$this->db->query("DELETE FROM ".$this->db->prefix()."designtrace_changeorder_component WHERE rowid = ".((int) $rowid))) {
			return $this->setErrorAndFail($this->db->lasterror());
		}
		return 1;
	}

	/**
	 * Revisions made under this ECO
	 *
	 * @param	int[]	$statuses	Only these revision statuses (empty = all)
	 * @return	stdClass[]			Rows: rowid, ref, rev, status, fk_component, date_release
	 */
	public function fetchRevisions($statuses = array())
	{
		$rows = array();
		if (empty($this->id)) {
			return $rows;
		}
		$sql = "SELECT rowid, ref, rev, status, fk_component, date_release FROM ".$this->db->prefix()."designtrace_componentrevision";
		$sql .= " WHERE fk_eco = ".((int) $this->id);
		if (!empty($statuses)) {
			$sql .= " AND status IN (".implode(',', array_map('intval', $statuses)).")";
		}
		$sql .= " ORDER BY ref";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$obj->date_release = $this->db->jdate($obj->date_release);
			$rows[] = $obj;
		}
		return $rows;
	}

	/**
	 * Components flagged "Needs update" that are not affected yet: suggestions for the list
	 *
	 * @return	stdClass[]	Rows: comp_id, comp_ref, comp_label, flag_note
	 */
	public function fetchSuggestedComponents()
	{
		$rows = array();
		$sql = "SELECT DISTINCT c.rowid as comp_id, c.ref as comp_ref, c.label as comp_label, l.flag_note";
		$sql .= " FROM ".$this->db->prefix()."designtrace_componentlink as l";
		$sql .= " INNER JOIN ".$this->db->prefix()."designtrace_componentrevision as r ON r.rowid = l.fk_parent";
		$sql .= " INNER JOIN ".$this->db->prefix()."designtrace_component as c ON c.rowid = r.fk_component";
		$sql .= " WHERE l.flag_status = 1 AND c.status = 1";
		$sql .= " AND c.rowid NOT IN (SELECT fk_component FROM ".$this->db->prefix()."designtrace_changeorder_component WHERE fk_eco = ".((int) $this->id).")";
		$sql .= " ORDER BY c.ref";
		$resql = $this->db->query($sql);
		$seen = array();
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			if (isset($seen[$obj->comp_id])) {
				$seen[$obj->comp_id]->flag_note .= ', '.$obj->flag_note;
				continue;
			}
			$seen[$obj->comp_id] = $obj;
			$rows[] = $obj;
		}
		return $rows;
	}

	/**
	 * ECOs a revision of this component can be made under: open ones (Draft, Approved,
	 * Implemented), those listing the component first
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$compid		Component id
	 * @return	array<int,string>	Id => "ECO-0001 - summary"
	 */
	public static function getOpenForComponent($db, $compid)
	{
		$result = array();
		$sql = "SELECT e.rowid, e.ref, e.label, (SELECT COUNT(ec.rowid) FROM ".$db->prefix()."designtrace_changeorder_component as ec WHERE ec.fk_eco = e.rowid AND ec.fk_component = ".((int) $compid).") as listed";
		$sql .= " FROM ".$db->prefix()."designtrace_changeorder as e";
		$sql .= " WHERE e.status IN (".self::STATUS_DRAFT.", ".self::STATUS_APPROVED.", ".self::STATUS_IMPLEMENTED.")";
		$sql .= " AND e.entity IN (".getEntity('changeorder').")";
		$sql .= " ORDER BY listed DESC, e.rowid DESC";
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$result[(int) $obj->rowid] = $obj->ref.' - '.dol_trunc((string) $obj->label, 60);
		}
		return $result;
	}

	/**
	 * getTooltipContentArray
	 *
	 * @param	array<string,string> 	$params 	Params to construct tooltip data
	 * @since 	v18
	 * @return	array{optimize?:string,picto?:string,ref?:string}
	 */
	public function getTooltipContentArray($params)
	{
		global $langs;

		$datas = [];

		if (getDolGlobalInt('MAIN_OPTIMIZEFORTEXTBROWSER')) {
			return ['optimize' => $langs->trans("ShowChangeOrder")];
		}
		$datas['picto'] = img_picto('', $this->picto).' <u>'.$langs->trans("ChangeOrder").'</u>';
		if (isset($this->status)) {
			$datas['picto'] .= ' '.$this->getLibStatut(5);
		}
		if (property_exists($this, 'ref')) {
			$datas['ref'] = '<br><b>'.$langs->trans('Ref').':</b> '.$this->ref;
		}
		if (property_exists($this, 'label')) {
			$datas['label'] = '<br><b>'.$langs->trans('EcoSummary').':</b> '.dol_escape_htmltag((string) $this->label);
		}

		return $datas;
	}

	/**
	 *  Return a link to the object card (with optionally the picto)
	 *
	 *  @param	int     $withpicto                  Include picto in link (0=No picto, 1=Include picto into link, 2=Only picto)
	 *  @param	string  $option                     On what the link point to ('nolink', ...)
	 *  @param	int     $notooltip                  1=Disable tooltip
	 *  @param	string  $morecss                    Add more css on link
	 *  @param	int     $save_lastsearch_value      -1=Auto, 0=No save of lastsearch_values when clicking, 1=Save lastsearch_values whenclicking
	 *  @return	string                              String with URL
	 */
	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
	{
		global $conf, $langs, $hookmanager;

		if (!empty($conf->dol_no_mouse_hover)) {
			$notooltip = 1; // Force disable tooltips
		}

		$result = '';
		$params = [
			'id' => (string) $this->id,
			'objecttype' => $this->element.($this->module ? '@'.$this->module : ''),
			'option' => $option,
		];
		$classfortooltip = 'classfortooltip';
		$dataparams = '';
		if (getDolGlobalInt('MAIN_ENABLE_AJAX_TOOLTIP')) {
			$classfortooltip = 'classforajaxtooltip';
			$dataparams = ' data-params="'.dol_escape_htmltag(json_encode($params)).'"';
			$label = '';
		} else {
			$label = implode($this->getTooltipContentArray($params));
		}

		$baseurl = dol_buildpath('/designtrace/changeorder_card.php', 1);
		$query = ['id' => $this->id];
		if ($option !== 'nolink') {
			// Add param to save lastsearch_values or not
			$add_save_lastsearch_values = ($save_lastsearch_value == 1 ? 1 : 0);
			if ($save_lastsearch_value == -1 && isset($_SERVER["PHP_SELF"]) && preg_match('/list\.php/', $_SERVER["PHP_SELF"])) {
				$add_save_lastsearch_values = 1;
			}
			if ($add_save_lastsearch_values) {
				$query = array_merge($query, ['save_lastsearch_values' => 1]);
			}
		}
		$url = dolBuildUrl($baseurl, $query);

		$linkclose = '';
		if (empty($notooltip)) {
			if (getDolGlobalInt('MAIN_OPTIMIZEFORTEXTBROWSER')) {
				$label = $langs->trans("ShowChangeOrder");
				$linkclose .= ' alt="'.dolPrintHTMLForAttribute($label).'"';
			}
			$linkclose .= ($label ? ' title="'.dolPrintHTMLForAttribute($label).'"' : ' title="tocomplete"');
			$linkclose .= $dataparams.' class="'.$classfortooltip.($morecss ? ' '.$morecss : '').'"';
		} else {
			$linkclose = ($morecss ? ' class="'.$morecss.'"' : '');
		}

		if ($option == 'nolink') {
			$linkstart = '<span';
		} else {
			$linkstart = '<a href="'.$url.'"';
		}
		$linkstart .= $linkclose.'>';
		if ($option == 'nolink') {
			$linkend = '</span>';
		} else {
			$linkend = '</a>';
		}

		$result .= $linkstart;

		if (empty($this->showphoto_on_popup)) {
			if ($withpicto) {
				$result .= img_object(($notooltip ? '' : $label), ($this->picto ? $this->picto : 'generic'), (($withpicto != 2) ? 'class="paddingright"' : ''), 0, 0, $notooltip ? 0 : 1);
			}
		} else {
			if ($withpicto) {
				require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

				list($class, $module) = explode('@', $this->picto);
				$upload_dir = $conf->$module->multidir_output[$conf->entity]."/$class/".dol_sanitizeFileName($this->ref);
				$filearray = dol_dir_list($upload_dir, "files");
				$filename = $filearray[0]['name'];
				if (!empty($filename)) {
					$pospoint = strpos($filearray[0]['name'], '.');

					$pathtophoto = $class.'/'.$this->ref.'/thumbs/'.substr($filename, 0, $pospoint).'_mini'.substr($filename, $pospoint);
					if (!getDolGlobalString(strtoupper($module.'_'.$class).'_FORMATLISTPHOTOSASUSERS')) {
						$result .= '<div class="floatleft inline-block valignmiddle divphotoref"><div class="photoref"><img class="photo'.$module.'" alt="No photo" border="0" src="'.DOL_URL_ROOT.'/viewimage.php?modulepart='.$module.'&entity='.$conf->entity.'&file='.urlencode($pathtophoto).'"></div></div>';
					} else {
						$result .= '<div class="floatleft inline-block valignmiddle divphotoref"><img class="photouserphoto userphoto" alt="No photo" border="0" src="'.DOL_URL_ROOT.'/viewimage.php?modulepart='.$module.'&entity='.$conf->entity.'&file='.urlencode($pathtophoto).'"></div>';
					}

					$result .= '</div>';
				} else {
					$result .= img_object(($notooltip ? '' : $label), ($this->picto ? $this->picto : 'generic'), ($notooltip ? (($withpicto != 2) ? 'class="paddingright"' : '') : 'class="'.(($withpicto != 2) ? 'paddingright ' : '').'"'), 0, 0, $notooltip ? 0 : 1);
				}
			}
		}

		if ($withpicto != 2) {
			$result .= $this->ref;
		}

		$result .= $linkend;
		//if ($withpicto != 2) $result.=(($addlabel && $this->label) ? $sep . dol_trunc($this->label, ($addlabel > 1 ? $addlabel : 0)) : '');

		global $action, $hookmanager;
		$hookmanager->initHooks(array($this->element.'dao'));
		$parameters = array('id' => $this->id, 'getnomurl' => &$result);
		$reshook = $hookmanager->executeHooks('getNomUrl', $parameters, $this, $action); // Note that $action and $object may have been modified by some hooks
		if ($reshook > 0) {
			$result = $hookmanager->resPrint;
		} else {
			$result .= $hookmanager->resPrint;
		}

		return $result;
	}

	/**
	 *	Return a thumb for kanban views
	 *
	 *	@param	string	    			$option		Where point the link (0=> main card, 1,2 => shipment, 'nolink'=>No link)
	 *  @param	?array<string,mixed>	$arraydata	Array of data
	 *  @return	string								HTML Code for Kanban thumb.
	 */
	public function getKanbanView($option = '', $arraydata = null)
	{
		global $conf, $langs;

		$selected = (empty($arraydata['selected']) ? 0 : $arraydata['selected']);

		$return = '<div class="box-flex-item box-flex-grow-zero">';
		$return .= '<div class="info-box info-box-sm">';
		$return .= '<span class="info-box-icon bg-infobox-action">';
		$return .= img_picto('', $this->picto);
		$return .= '</span>';
		$return .= '<div class="info-box-content">';
		$return .= '<span class="info-box-ref inline-block tdoverflowmax150 valignmiddle">'.(method_exists($this, 'getNomUrl') ? $this->getNomUrl() : $this->ref).'</span>';
		if ($selected >= 0) {
			$return .= '<input id="cb'.$this->id.'" class="flat checkforselect fright" type="checkbox" name="toselect[]" value="'.$this->id.'"'.($selected ? ' checked="checked"' : '').'>';
		}
		if (property_exists($this, 'label')) {
			$return .= ' <div class="inline-block opacitymedium valignmiddle tdoverflowmax100">'.$this->label.'</div>';
		}
		if (property_exists($this, 'thirdparty') && is_object($this->thirdparty)) {
			$return .= '<br><div class="info-box-ref tdoverflowmax150">'.$this->thirdparty->getNomUrl(1).'</div>';
		}
		if (property_exists($this, 'amount')) {
			$return .= '<br>';
			$return .= '<span class="info-box-label amount">'.price($this->amount, 0, $langs, 1, -1, -1, $conf->currency).'</span>';
		}
		if (method_exists($this, 'getLibStatut')) {
			$return .= '<br><div class="info-box-status">'.$this->getLibStatut(3).'</div>';
		}
		$return .= '</div>';
		$return .= '</div>';
		$return .= '</div>';

		return $return;
	}

	/**
	 *  Return the label of the status
	 *
	 *  @param	int<0,6>	$mode          0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return	string 			       Label of status
	 */
	public function getLabelStatus($mode = 0)
	{
		return $this->LibStatut($this->status, $mode);
	}

	/**
	 *  Return the label of the status
	 *
	 *  @param	int<0,6>	$mode	0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return	string				Label of status
	 */
	public function getLibStatut($mode = 0)
	{
		return $this->LibStatut($this->status, $mode);
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 *  Return the label of a given status
	 *
	 *  @param	int			$status		Id status
	 *  @param	int<0,6>	$mode		0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return	string					Label of status
	 */
	public function LibStatut($status, $mode = 0)
	{
		// phpcs:enable
		if (is_null($status)) {
			return '';
		}

		$paramsBadge = array('badgeParams' => array('attr' => array(
			'data-status-element' => $this->element,
			'data-status' => (int) $status
		)));


		if (empty($this->labelStatus) || empty($this->labelStatusShort)) {
			global $langs;
			//$langs->load("designtrace@designtrace");
			$langs->load("designtrace@designtrace");
			foreach (array(self::STATUS_DRAFT => 'Draft', self::STATUS_APPROVED => 'EcoApproved', self::STATUS_IMPLEMENTED => 'EcoImplemented', self::STATUS_CLOSED => 'EcoClosed', self::STATUS_CANCELED => 'Canceled') as $code => $key) {
				$this->labelStatus[$code] = $langs->transnoentitiesnoconv($key);
				$this->labelStatusShort[$code] = $langs->transnoentitiesnoconv($key);
			}
		}

		$types = array(self::STATUS_DRAFT => 'status0', self::STATUS_APPROVED => 'status1', self::STATUS_IMPLEMENTED => 'status4', self::STATUS_CLOSED => 'status6', self::STATUS_CANCELED => 'status9');
		$statusType = isset($types[$status]) ? $types[$status] : 'status0';

		return dolGetStatus($this->labelStatus[$status], $this->labelStatusShort[$status], '', $statusType, $mode, '', $paramsBadge);
	}

	/**
	 *	Load the info information in the object
	 *
	 *	@param	int		$id       Id of object
	 *	@return	void
	 */
	public function info($id)
	{
		$sql = "SELECT t.rowid, t.date_creation as datec";
		if (!empty($this->isextrafieldmanaged) && $this->isextrafieldmanaged == 1) {
			$sql .= ", GREATEST(t.tms, COALESCE(te.tms, t.tms)) as datem";
		} else {
			$sql .= ", t.tms as datem";
		}
		if (!empty($this->fields['date_validation'])) {
			$sql .= ", t.date_validation as datev";
		}
		if (!empty($this->fields['fk_user_creat'])) {
			$sql .= ", t.fk_user_creat";
		}
		if (!empty($this->fields['fk_user_modif'])) {
			$sql .= ", t.fk_user_modif";
		}
		if (!empty($this->fields['fk_user_valid'])) {
			$sql .= ", t.fk_user_valid";
		}
		$sql .= " FROM ".$this->db->prefix().$this->table_element." as t";
		if (!empty($this->isextrafieldmanaged) && $this->isextrafieldmanaged == 1) {
			$sql .= " LEFT JOIN ".$this->db->prefix().$this->table_element."_extrafields as te ON te.fk_object = t.rowid";
		}
		$sql .= " WHERE t.rowid = ".((int) $id);

		$result = $this->db->query($sql);
		if ($result) {
			if ($this->db->num_rows($result)) {
				$obj = $this->db->fetch_object($result);

				$this->id = $obj->rowid;

				if (!empty($this->fields['fk_user_creat'])) {
					$this->user_creation_id = $obj->fk_user_creat;
				}
				if (!empty($this->fields['fk_user_modif'])) {
					$this->user_modification_id = $obj->fk_user_modif;
				}
				if (!empty($this->fields['fk_user_valid'])) {
					$this->user_validation_id = $obj->fk_user_valid;
				}
				$this->date_creation = $this->db->jdate($obj->datec);
				$this->date_modification = empty($obj->datem) ? '' : $this->db->jdate($obj->datem);
				if (!empty($obj->datev)) {
					$this->date_validation = empty($obj->datev) ? '' : $this->db->jdate($obj->datev);
				}
			}

			$this->db->free($result);
		} else {
			dol_print_error($this->db);
		}
	}

	/**
	 * Initialize object with example values
	 * Id must be 0 if object instance is a specimen
	 *
	 * @return	int
	 */
	public function initAsSpecimen()
	{
		// Set here init that are not commonf fields
		// $this->property1 = ...
		// $this->property2 = ...

		return $this->initAsSpecimenCommon();
	}

	/**
	 *  Returns the reference to the following non used object depending on the active numbering module.
	 *
	 *  @return	string      		Object free reference
	 */
	public function getNextNumRef()
	{
		global $langs, $conf;
		$langs->load("designtrace@designtrace");

		if (!getDolGlobalString('DESIGNTRACE_CHANGEORDER_ADDON')) {
			$conf->global->DESIGNTRACE_CHANGEORDER_ADDON = 'mod_changeorder_standard';
		}

		if (getDolGlobalString('DESIGNTRACE_CHANGEORDER_ADDON')) {
			$mybool = false;

			$file = getDolGlobalString('DESIGNTRACE_CHANGEORDER_ADDON').".php";
			$classname = getDolGlobalString('DESIGNTRACE_CHANGEORDER_ADDON');

			// Include file with class
			$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);
			foreach ($dirmodels as $reldir) {
				$dir = dol_buildpath($reldir."core/modules/designtrace/");

				// Load file with numbering class (if found)
				$mybool = $mybool || @include_once $dir.$file;
			}

			if (!$mybool) {
				dol_print_error(null, "Failed to include file ".$file);
				return '';
			}

			if (class_exists($classname)) {
				$obj = new $classname();
				'@phan-var-force ModeleNumRefChangeOrder $obj';
				$numref = $obj->getNextValue($this);

				if ($numref != '' && $numref != '-1') {
					return $numref;
				} else {
					$this->error = $obj->error;
					//dol_print_error($this->db,get_class($this)."::getNextNumRef ".$obj->error);
					return "";
				}
			} else {
				print $langs->trans("Error")." ".$langs->trans("ClassNotFound").' '.$classname;
				return "";
			}
		} else {
			print $langs->trans("ErrorNumberingModuleNotSetup", $this->element);
			return "";
		}
	}

	/**
	 *  Create a document onto disk according to template module.
	 *
	 *  @param	string		$modele			Force template to use ('' to not force)
	 *  @param	Translate	$outputlangs	object lang a utiliser pour traduction
	 *  @param	int<0,1>	$hidedetails    Hide details of lines
	 *  @param	int<0,1>	$hidedesc       Hide description
	 *  @param	int<0,1>	$hideref        Hide ref
	 *  @param	?array<string,string>  $moreparams     Array to provide more information
	 *  @return	int         				0 if KO, 1 if OK
	 */
	public function generateDocument($modele, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0, $moreparams = null)
	{
		global $langs;

		$result = 0;
		$includedocgeneration = 0;

		$langs->load("designtrace@designtrace");

		if (!dol_strlen($modele)) {
			if (!empty($this->model_pdf)) {
				$modele = $this->model_pdf;
			} else {
				$modele = getDolGlobalString('CHANGEORDER_ADDON_PDF', 'standard_changeorder');
			}
		}

		$modelpath = "core/modules/designtrace/doc/";

		if ($includedocgeneration && !empty($modele)) {
			$result = $this->commonGenerateDocument($modelpath, $modele, $outputlangs, $hidedetails, $hidedesc, $hideref, $moreparams);
		}

		return $result;
	}

	/**
	 * Return validation test result for a field.
	 * Need MAIN_ACTIVATE_VALIDATION_RESULT to be called.
	 *
	 * @param   array<string,array{type:string,label:string,enabled:int<0,2>|string,position:int,notnull?:int,visible:int<-2,5>|string,noteditable?:int<0,1>,default?:int<0,1>|string,index?:int,foreignkey?:string,searchall?:int<0,1>,isameasure?:int<0,1>,css?:string,csslist?:string,help?:string,showoncombobox?:int<0,2>,disabled?:int<0,1>,arrayofkeyval?:array<int|string,string>,comment?:string,validate?:int<0,1>}>  $fields Array of properties of field to show
	 * @param	string  $fieldKey            Key of attribute
	 * @param	string  $fieldValue          value of attribute
	 * @return	bool 						Return false if fail, true on success, set $this->error for error message
	 */
	public function validateField($fields, $fieldKey, $fieldValue)
	{
		// Add your own validation rules here.
		// ...

		return parent::validateField($fields, $fieldKey, $fieldValue);
	}

	/**
	 * Action executed by scheduler
	 * CAN BE A CRON TASK. In such a case, parameters come from the schedule job setup field 'Parameters'
	 * Use public function doScheduledJob($param1, $param2, ...) to get parameters
	 *
	 * @return	int			0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
	 */
	public function doScheduledJob()
	{
		//global $conf, $langs;

		//$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_mydedicatedlogfile.log';

		$error = 0;
		$this->output = '';
		$this->error = '';

		dol_syslog(__METHOD__." start", LOG_INFO);

		//$now = dol_now();

		$this->db->begin();

		// ...

		$this->db->commit();

		dol_syslog(__METHOD__." end", LOG_INFO);

		return $error;
	}
}


require_once DOL_DOCUMENT_ROOT.'/core/class/commonobjectline.class.php';
