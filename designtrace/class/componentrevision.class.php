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
 * \file        designtrace/class/componentrevision.class.php
 * \ingroup     designtrace
 * \brief       This file is a CRUD class file for ComponentRevision (Create/Read/Update/Delete)
 */

// Put here all includes required by your class file
require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
//require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
//require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

/**
 * Class for ComponentRevision
 */
class ComponentRevision extends CommonObject
{
	/**
	 * @var string 		ID of module.
	 */
	public $module = 'designtrace';

	/**
	 * @var string 		ID to identify managed object.
	 */
	public $element = 'componentrevision';

	/**
	 * @var string		Prefix to check for any trigger code of any business class to prevent bad value for trigger code.
	 * @see CommonTrigger::call_trigger()
	 */
	public $TRIGGER_PREFIX = 'DESIGNTRACE_COMPONENTREVISION';	// Will be used to build trgiger keys 'DESIGNTRACE_COMPONENTREVISION_MODIFY', ...

	/**
	 * @var string 		Name of table without prefix where object is stored. This is also the key used for extrafields management (so extrafields know the link to the parent table).
	 */
	public $table_element = 'designtrace_componentrevision';

	/**
	 * @var string 		If permission must be checked with hasRight('designtrace', 'read') and not hasright('designtrace', 'componentrevision', 'read'), you can uncomment this line
	 */
	//public $element_for_permission = 'designtrace';

	/**
	 * @var string 		String with name of icon for componentrevision. Must be a 'fa-xxx' fontawesome code (or 'fa-xxx_fa_color_size') or 'componentrevision@designtrace' if picto is file 'img/object_componentrevision.png'.
	 */
	public $picto = 'fa-code-branch';

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
	const STATUS_INREVIEW = 1;
	const STATUS_RELEASED = 2;
	const STATUS_OBSOLETE = 9;
	// Names used by generated code
	const STATUS_VALIDATED = 1;
	const STATUS_CANCELED = 9;

	/**
	 * @var Component|null	Component of this revision, loaded by fetchComponentChecked()
	 */
	public $component;

	/**
	 *  'type' field format:
	 *  	'integer', 'integer:ObjectClass:PathToClass[:AddCreateButtonOrNot[:Filter[:Sortfield]]]',
	 *  	'select' (list of values are in 'options'. for integer list of values are in 'arrayofkeyval'),
	 *  	'sellist:TableName:LabelFieldName[:KeyFieldName[:KeyFieldParent[:Filter[:CategoryIdType[:CategoryIdList[:SortField]]]]]]',
	 *  	'chkbxlst:...',
	 *  	'varchar(x)',
	 *  	'text', 'text:none', 'html',
	 *   	'double(24,8)', 'real', 'price', 'stock',
	 *  	'date', 'datetime', 'timestamp', 'duration',
	 *  	'boolean', 'checkbox', 'radio', 'array',
	 *  	'email', 'phone', 'url', 'password', 'ip'
	 *		Note: Filter must be a Dolibarr Universal Filter syntax string. Example: "(t.ref:like:'SO-%') or (t.date_creation:>:'20160101') or (t.status:!=:0) or (t.nature:is:NULL)"
	 *  'length' the length of field. Example: 255, '24,8'
	 *  'label' the translation key.
	 *  'langfile' the key of the language file for translation.
	 *  'alias' the alias used into some old hard coded SQL requests
	 *  'picto' is code of a picto to show before value in forms
	 *  'enabled' is a condition when the field must be managed (Example: 1 or 'getDolGlobalInt("MY_SETUP_PARAM")' or 'isModEnabled("multicurrency")' ...)
	 *  'position' is the sort order of field.
	 *  'notnull' is set to 1 if not null in database. Set to -1 if we must set data to null if empty ('' or 0).
	 *  'visible' says if field is visible in list (Examples: 0=Not visible, 1=Visible on list and create/update/view forms, 2=Visible on list only, 3=Visible on create/update/view form only (not list), 4=Visible on list and update/view form (not create). 5=Visible on list and view form (not create/not update). 6=visible on list and update/view form (not update). Using a negative value means field is not shown by default on list but can be selected for viewing)
	 *  'noteditable' says if field is not editable (1 or 0)
	 *  'alwayseditable' says if field can be modified also when status is not draft ('1' or '0')
	 *  'default' is a default value for creation (can still be overwritten by the Setup of Default Values if the field is editable in creation form). Note: If default is set to '(PROV)' and field is 'ref', the default value will be set to '(PROVid)' where id is rowid when a new record is created.
	 *  'index' if we want an index in database.
	 *  'foreignkey'=>'tablename.field' if the field is a foreign key (it is recommended to name the field fk_...).
	 *  'searchall' is 1 if we want to search in this field when making a search from the quick search button.
	 *  'isameasure' must be set to 1 or 2 if field can be used for measure. Field type must be summable like integer or double(24,8). Use 1 in most cases, or 2 if you don't want to see the column total into list (for example for percentage)
	 *  'css' and 'cssview' and 'csslist' is the CSS style to use on field. 'css' is used in creation and update. 'cssview' is used in view mode. 'csslist' is used for columns in lists. For example: 'css'=>'minwidth300 maxwidth500 widthcentpercentminusx', 'cssview'=>'wordbreak', 'csslist'=>'tdoverflowmax200'
	 *  'placeholder' to set the placeholder of a varchar field.
	 *  'help' and 'helplist' is a 'TranslationString' to use to show a tooltip on field. You can also use 'TranslationString:keyfortooltiponlick' for a tooltip on click.
	 *  'showoncombobox' if value of the field must be visible into the label of the combobox that list record
	 *  'disabled' is 1 if we want to have the field locked by a 'disabled' attribute. In most cases, this is never set into the definition of $fields into class, but is set dynamically by some part of code like the constructor of the class.
	 *  'arrayofkeyval' to set a list of values if type is a list of predefined values. For example: array("0"=>"Draft","1"=>"Active","-1"=>"Cancel"). Note that type can be 'integer' or 'varchar'
	 *  'autofocusoncreate' to have field having the focus on a create form. Only 1 field should have this property set to 1.
	 *  'comment' is not used. You can store here any text of your choice. It is not used by application.
	 *	'validate' is 1 if you need to validate the field with $this->validateField(). Need MAIN_ACTIVATE_VALIDATION_RESULT.
	 *  'copytoclipboard' is 1 or 2 to allow to add a picto to copy value into clipboard (1=picto after label, 2=picto after value)
	 *  'description' is a description of the field. Can be used to help the MCP server.
	 *
	 *  Note: To have value dynamic, you can set value to 0 in definition and edit the value on the fly into the constructor.
	 */

	// BEGIN MODULEBUILDER PROPERTIES
	/**
	 * @inheritdoc
	 * Array with all fields and their property. Do not use it as a static var. It may be modified by constructor.
	 */
	public $fields = array(
		"rowid" => array("type" => "integer", "label" => "TechnicalID", "enabled" => "1", 'position' => 1, 'notnull' => 1, "visible" => "0", "noteditable" => "1", "index" => "1", "css" => "left", "comment" => "Id"),
		"ref" => array("type" => "varchar(128)", "label" => "Ref", "enabled" => "1", 'position' => 15, 'notnull' => 1, "visible" => "4", "noteditable" => "1", "index" => "1", "searchall" => "1", "showoncombobox" => "1", "csslist" => "nowraponall", "comment" => "Component number and rev, e.g. PRT-0001-A"),
		"entity" => array("type" => "integer", "label" => "Entity", "enabled" => "1", 'position' => 20, 'notnull' => 1, "visible" => "0", "default" => "1", "index" => "1",),
		"fk_component" => array("type" => "integer:Component:designtrace/class/component.class.php:0:(t.status:=:1)", "label" => "Component", "picto" => "fa-cube", "enabled" => "1", 'position' => 22, 'notnull' => 1, "visible" => "1", "index" => "1", "foreignkey" => "designtrace_component.rowid", "css" => "maxwidth500 widthcentpercentminusxx", "csslist" => "tdoverflowmax150", "validate" => "1",),
		"rev" => array("type" => "varchar(16)", "label" => "Revision", "enabled" => "1", 'position' => 24, 'notnull' => 0, "visible" => "1", "index" => "1", "searchall" => "1", "css" => "maxwidth75", "csslist" => "nowraponall", "help" => "RevisionHelp", "validate" => "1",),
		"description" => array("type" => "text", "label" => "RevisionNotes", "enabled" => "1", 'position' => 60, 'notnull' => 0, "visible" => "3", "help" => "RevisionNotesHelp", "validate" => "1",),
		"change_reason" => array("type" => "integer", "label" => "ChangeReason", "enabled" => "1", 'position' => 58, 'notnull' => 0, "visible" => "1", "arrayofkeyval" => array("1" => "ChangeReasonInitial", "2" => "ChangeReasonCustomer", "3" => "ChangeReasonFix", "4" => "ChangeReasonImprovement", "5" => "ChangeReasonProcess", "6" => "ChangeReasonOther"), "validate" => "1", "help" => "ChangeReasonHelp"),
		"material" => array("type" => "varchar(255)", "label" => "Material", "enabled" => "1", 'position' => 70, 'notnull' => 0, "visible" => "-1", "searchall" => "1", "css" => "minwidth200", "validate" => "1",),
		"process" => array("type" => "varchar(255)", "label" => "Process", "enabled" => "1", 'position' => 72, 'notnull' => 0, "visible" => "-1", "searchall" => "1", "css" => "minwidth200", "validate" => "1",),
		"machine" => array("type" => "varchar(255)", "label" => "Machine", "enabled" => "1", 'position' => 74, 'notnull' => 0, "visible" => "-1", "searchall" => "1", "css" => "minwidth200", "validate" => "1",),
		"layer_height" => array("type" => "double(24,8)", "label" => "LayerHeight", "enabled" => "1", 'position' => 75, 'notnull' => 0, "visible" => "-1", "css" => "maxwidth100", "validate" => "1", "help" => "PrintSettingsHelp"),
		"nozzle_size" => array("type" => "double(24,8)", "label" => "NozzleSize", "enabled" => "1", 'position' => 76, 'notnull' => 0, "visible" => "-1", "css" => "maxwidth100", "validate" => "1", "help" => "PrintSettingsHelp"),
		"infill" => array("type" => "integer", "label" => "InfillPercent", "enabled" => "1", 'position' => 77, 'notnull' => 0, "visible" => "-1", "css" => "maxwidth75", "validate" => "1", "help" => "PrintSettingsHelp"),
		"print_time" => array("type" => "duration", "label" => "PrintTime", "enabled" => "1", 'position' => 78, 'notnull' => 0, "visible" => "-1", "validate" => "1", "help" => "PrintSettingsHelp"),
		"filament_used" => array("type" => "double(24,8)", "label" => "FilamentUsed", "enabled" => "1", 'position' => 79, 'notnull' => 0, "visible" => "-1", "css" => "maxwidth100", "validate" => "1", "help" => "PrintSettingsHelp"),
		"date_release" => array("type" => "datetime", "label" => "DateRelease", "enabled" => "1", 'position' => 80, 'notnull' => 0, "visible" => "5", "noteditable" => "1", "index" => "1",),
		"fk_user_release" => array("type" => "integer:User:user/class/user.class.php", "label" => "ReleasedBy", "picto" => "user", "enabled" => "1", 'position' => 82, 'notnull' => 0, "visible" => "-5", "noteditable" => "1", "csslist" => "tdoverflowmax150",),
		"note_public" => array("type" => "html", "label" => "NotePublic", "enabled" => "1", 'position' => 61, 'notnull' => 0, "visible" => "0", "cssview" => "wordbreak", "validate" => "1",),
		"note_private" => array("type" => "html", "label" => "NotePrivate", "enabled" => "1", 'position' => 62, 'notnull' => 0, "visible" => "0", "cssview" => "wordbreak", "validate" => "1",),
		"date_creation" => array("type" => "datetime", "label" => "DateCreation", "enabled" => "1", 'position' => 500, 'notnull' => 1, "visible" => "-2",),
		"tms" => array("type" => "timestamp", "label" => "DateModification", "enabled" => "1", 'position' => 501, 'notnull' => 0, "visible" => "-2",),
		"fk_user_creat" => array("type" => "integer:User:user/class/user.class.php", "label" => "UserAuthor", "picto" => "user", "enabled" => "1", 'position' => 510, 'notnull' => 1, "visible" => "-2", "csslist" => "tdoverflowmax150",),
		"fk_user_modif" => array("type" => "integer:User:user/class/user.class.php", "label" => "UserModif", "picto" => "user", "enabled" => "1", 'position' => 511, 'notnull' => -1, "visible" => "-2", "csslist" => "tdoverflowmax150",),
		"last_main_doc" => array("type" => "varchar(255)", "label" => "LastMainDoc", "enabled" => "1", 'position' => 600, 'notnull' => 0, "visible" => "0",),
		"import_key" => array("type" => "varchar(14)", "label" => "ImportId", "enabled" => "1", 'position' => 1000, 'notnull' => -1, "visible" => "-2",),
		"model_pdf" => array("type" => "varchar(255)", "label" => "Model pdf", "enabled" => "1", 'position' => 1010, 'notnull' => -1, "visible" => "0",),
		"status" => array("type" => "integer", "label" => "Status", "enabled" => "1", 'position' => 2000, 'notnull' => 1, "visible" => "2", "default" => "0", "index" => "1", "arrayofkeyval" => array("0" => "Draft", "1" => "RevisionStatusInReview", "2" => "RevisionStatusReleased", "9" => "RevisionStatusObsolete"), "validate" => "1",),
	);
	public $rowid;
	public $ref;
	public $entity;
	public $fk_component;
	public $rev;
	public $description;
	public $material;
	public $process;
	public $machine;
	/**
	 * @var ?int Reason for the change (1 initial … 6 other); details go in the change notes
	 */
	public $change_reason;
	/**
	 * @var ?float Layer height in mm
	 */
	public $layer_height;
	/**
	 * @var ?float Nozzle size in mm
	 */
	public $nozzle_size;
	/**
	 * @var ?int Infill in %
	 */
	public $infill;
	/**
	 * @var ?int Print time in seconds
	 */
	public $print_time;
	/**
	 * @var ?float Filament used in grams
	 */
	public $filament_used;
	public $date_release;
	public $fk_user_release;
	/**
	 * @var string[] Non-blocking messages from the last addFile()
	 */
	public $warnings = array();
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
		/*if ($user->hasRight('designtrace', 'componentrevision', 'read')) {
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
	 * A new revision is always a draft of an active component. Its rev defaults to the next
	 * letter (A, B, ... Z, AA, ...) and its ref is the component number plus the rev
	 * (PRT-0001-A). Only one revision of a component can be open (draft or in review) at a time.
	 *
	 * @param	User		$user		User that creates
	 * @param	int<0,1> 	$notrigger	0=launch triggers after, 1=disable triggers
	 * @return	int<-1,max>				Return integer <0 if KO, Id of created object if OK
	 */
	public function create(User $user, $notrigger = 0)
	{
		global $langs;
		$langs->load("designtrace@designtrace");

		$component = $this->fetchComponentChecked();
		if (!is_object($component)) {
			return -1;
		}
		if ((int) $component->status !== Component::STATUS_ACTIVE) {
			return $this->setErrorAndFail($langs->trans('ErrorRevisionComponentNotActive', $component->ref));
		}

		$open = $this->countOpenRevisions((int) $this->fk_component);
		if ($open < 0) {
			return -1;
		}
		if ($open > 0) {
			return $this->setErrorAndFail($langs->trans('ErrorRevisionAlreadyOpen', $component->ref));
		}

		if ($this->rev === null || trim((string) $this->rev) === '') {
			$this->rev = $this->getNextRevision((int) $this->fk_component);
			if ($this->rev === '') {
				return -1;
			}
		}
		if ($this->checkRev() < 0 || $this->checkPrintSettings() < 0) {
			return -1;
		}

		$this->ref = $component->ref.'-'.$this->rev;
		$this->status = self::STATUS_DRAFT;
		$this->date_release = null;
		$this->fk_user_release = null;

		// createCommon() stores an empty date_creation back on the object as a DB string, which a
		// later updateCommon() on the same object shifts by the timezone offset: give it a timestamp
		if (empty($this->date_creation)) {
			$this->date_creation = dol_now();
		}
		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Create a new draft revision from an existing one ("new revision from this one").
	 *
	 * Copies notes and production settings (material, process, machine) to a new draft of the
	 * same component with the next rev. Release data is not copied.
	 *
	 * @param	User 	$user		User that creates
	 * @param	int 	$fromid		Id of object to clone
	 * @return	self|int<-1,-1>		New object created, <0 if KO
	 */
	public function createFromClone(User $user, $fromid)
	{
		global $extrafields;

		dol_syslog(__METHOD__, LOG_DEBUG);

		$object = new self($this->db);
		if ($object->fetchCommon($fromid) <= 0) {
			$this->setErrorsFromObject($object);
			return -1;
		}

		unset($object->id);
		unset($object->fk_user_creat);
		unset($object->import_key);
		$object->ref = '';
		$object->rev = '';
		$object->change_reason = null;		// Each revision states its own reason; the rest (material, print settings) carries over
		$object->status = self::STATUS_DRAFT;
		$object->date_release = null;
		$object->fk_user_release = null;
		$object->date_creation = dol_now();

		// Clear extrafields that are unique
		if (is_array($object->array_options) && count($object->array_options) > 0) {
			$extrafields->fetch_name_optionals_label($this->table_element);
			foreach ($object->array_options as $key => $option) {
				$shortkey = preg_replace('/options_/', '', $key);
				if (!empty($extrafields->attributes[$this->table_element]['unique'][$shortkey])) {
					unset($object->array_options[$key]);
				}
			}
		}

		$object->context['createfromclone'] = 'createfromclone';
		$result = $object->create($user);
		unset($object->context['createfromclone']);
		if ($result < 0) {
			$this->setErrorsFromObject($object);
			return -1;
		}

		// The new revision starts with the same links, pinned to the same child revisions (the
		// draft shows which ones have a newer revision). Files are not copied.
		dol_include_once('/designtrace/class/componentlink.class.php');
		$sql = "SELECT fk_child, link_type, qty, note FROM ".$this->db->prefix()."designtrace_componentlink WHERE fk_parent = ".((int) $fromid);
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$link = new ComponentLink($this->db);
			$link->fk_parent = $object->id;
			$link->fk_child = (int) $obj->fk_child;
			$link->link_type = (int) $obj->link_type;
			$link->qty = $obj->qty;
			$link->note = $obj->note;
			if ($link->create($user, 1, true) < 0) {
				$object->warnings[] = $link->error;
			}
		}

		return $object;
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
	 * Only draft and in-review revisions can be changed; released and obsolete revisions are
	 * read-only (a change means a new revision). A revision cannot move to another component.
	 * The rev can change while draft or in review, and the ref follows it.
	 *
	 * @param	User		$user		User that modifies
	 * @param	int<0,1>	$notrigger	0=launch triggers after, 1=disable triggers
	 * @return	int<-1,1>				Return integer <0 if KO, >0 if OK
	 */
	public function update(User $user, $notrigger = 0)
	{
		global $langs;
		$langs->load("designtrace@designtrace");

		$stored = new self($this->db);
		if ($stored->fetchCommon($this->id) <= 0) {
			$this->setErrorsFromObject($stored);
			return -1;
		}
		if ((int) $stored->status >= self::STATUS_RELEASED) {
			return $this->setErrorAndFail($langs->trans('ErrorRevisionIsReadOnly', $stored->ref));
		}
		if ((int) $stored->fk_component !== (int) $this->fk_component) {
			return $this->setErrorAndFail($langs->trans('ErrorRevisionComponentCannotChange'));
		}

		// Status and release data only change through the workflow methods
		$this->status = $stored->status;
		$this->date_release = $stored->date_release;
		$this->fk_user_release = $stored->fk_user_release;

		if ($this->checkRev() < 0 || $this->checkPrintSettings() < 0) {
			return -1;
		}
		$component = $this->fetchComponentChecked();
		if (!is_object($component)) {
			return -1;
		}
		$this->ref = $component->ref.'-'.$this->rev;

		$this->db->begin();
		$result = $this->updateCommon($user, $notrigger);
		if ($result > 0 && $stored->ref !== $this->ref && $this->renameFilesDir($stored->ref, $this->ref) < 0) {
			$result = -1;
		}
		if ($result > 0) {
			$this->db->commit();
		} else {
			$this->db->rollback();
		}

		return $result;
	}

	/**
	 * Delete object in database. Released and obsolete revisions cannot be deleted.
	 *
	 * @param	User		$user		User that deletes
	 * @param	int<0,1> 	$notrigger	0=launch triggers, 1=disable triggers
	 * @return	int<-1,1>				Return integer <0 if KO, >0 if OK
	 */
	public function delete(User $user, $notrigger = 0)
	{
		global $langs;
		$langs->load("designtrace@designtrace");

		if ((int) $this->status >= self::STATUS_RELEASED) {
			return $this->setErrorAndFail($langs->trans('ErrorRevisionReleasedCannotBeDeleted', $this->ref));
		}
		// A draft other revisions link to cannot disappear under them
		$parents = array();
		$sql = "SELECT p.ref FROM ".$this->db->prefix()."designtrace_componentlink as l";
		$sql .= " INNER JOIN ".$this->db->prefix().$this->table_element." as p ON p.rowid = l.fk_parent";
		$sql .= " WHERE l.fk_child = ".((int) $this->id)." ORDER BY p.ref";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$parents[] = $obj->ref;
		}
		if ($parents) {
			return $this->setErrorAndFail($langs->trans('ErrorRevisionUsedAsChild', $this->ref, implode(', ', $parents)));
		}

		$this->db->begin();
		$result = 1;
		foreach (array('designtrace_revisionfile' => 'fk_revision', 'designtrace_componentlink' => 'fk_parent', 'designtrace_docaccess' => 'fk_revision') as $table => $column) {
			$sql = "DELETE FROM ".$this->db->prefix().$table." WHERE ".$column." = ".((int) $this->id);
			if ($result > 0 && !$this->db->query($sql)) {
				$result = $this->setErrorAndFail($this->db->lasterror());
			}
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
	}

	/**
	 *	Submit a draft for review (Draft -> In review). Called by the card's Validate action.
	 *
	 *	@param	User		$user		User making status change
	 *  @param	int<0,1>	$notrigger	1=Does not execute triggers, 0= execute triggers
	 *	@return	int<-1,1>				Return integer <0 if KO, 0=Nothing done, >0 if OK
	 */
	public function validate($user, $notrigger = 0)
	{
		global $langs;

		if ((int) $this->status !== self::STATUS_DRAFT) {
			dol_syslog(get_class($this)."::validate action abandoned: not a draft", LOG_WARNING);
			return 0;
		}
		// Nothing goes to review while it depends on a draft
		$blocking = $this->getBlockingChildren(array(self::STATUS_DRAFT));
		if ($blocking) {
			$langs->load('designtrace@designtrace');
			return $this->setErrorAndFail($langs->trans('ErrorRevisionChildrenNotReviewed', $this->ref, implode(', ', $blocking)));
		}

		return $this->setStatusCommon($user, self::STATUS_INREVIEW, $notrigger, 'DESIGNTRACE_COMPONENTREVISION_SUBMIT');
	}

	/**
	 *	Send a revision in review back to draft (In review -> Draft).
	 *
	 *	@param	User		$user		Object user that modify
	 *  @param	int<0,1>	$notrigger	1=Does not execute triggers, 0=Execute triggers
	 *	@return	int<-1,1>				Return integer <0 if KO, 0=Nothing done, >0 if OK
	 */
	public function setDraft($user, $notrigger = 0)
	{
		global $langs;

		if ((int) $this->status !== self::STATUS_INREVIEW) {
			return 0;
		}
		// A parent in review must not end up depending on a draft
		$parents = $this->getParentsInStatus(array(self::STATUS_INREVIEW));
		if ($parents) {
			$langs->load('designtrace@designtrace');
			return $this->setErrorAndFail($langs->trans('ErrorRevisionParentsInReview', $this->ref, implode(', ', $parents)));
		}

		return $this->setStatusCommon($user, self::STATUS_DRAFT, $notrigger, 'DESIGNTRACE_COMPONENTREVISION_UNVALIDATE');
	}

	/**
	 *	Release a draft or in-review revision. It becomes read-only and gets its release date and
	 *	releasing user. The caller must check the release permission.
	 *
	 *	The DESIGNTRACE_COMPONENTREVISION_RELEASE trigger is what dependency flagging hooks into.
	 *
	 *	@param	User		$user		User releasing
	 *  @param	int<0,1>	$notrigger	1=Does not execute triggers, 0=Execute triggers
	 *	@return	int<-1,1>				Return integer <0 if KO, 0=Nothing done, >0 if OK
	 */
	public function release($user, $notrigger = 0)
	{
		global $langs;

		if (!in_array((int) $this->status, array(self::STATUS_DRAFT, self::STATUS_INREVIEW), true)) {
			dol_syslog(get_class($this)."::release action abandoned: status ".$this->status, LOG_WARNING);
			return 0;
		}
		// Nothing is released while it depends on something not released
		$blocking = $this->getBlockingChildren(array(self::STATUS_DRAFT, self::STATUS_INREVIEW));
		if ($blocking) {
			$langs->load('designtrace@designtrace');
			return $this->setErrorAndFail($langs->trans('ErrorRevisionChildrenNotReleased', $this->ref, implode(', ', $blocking)));
		}
		if (getDolGlobalInt('DESIGNTRACE_RELEASE_REQUIRES_SOURCE')) {
			dol_include_once('/designtrace/class/revisionfile.class.php');
			if ($this->countFiles(RevisionFile::ROLE_SOURCE) <= 0) {
				$langs->load('designtrace@designtrace');
				return $this->setErrorAndFail($langs->trans('ErrorRevisionNeedsSourceFile', $this->ref));
			}
		}

		$error = 0;
		$now = dol_now();

		$this->db->begin();

		$sql = "UPDATE ".$this->db->prefix().$this->table_element." SET";
		$sql .= " status = ".self::STATUS_RELEASED.",";
		$sql .= " date_release = '".$this->db->idate($now)."',";
		$sql .= " fk_user_release = ".((int) $user->id).",";
		$sql .= " fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND status IN (".self::STATUS_DRAFT.", ".self::STATUS_INREVIEW.")";

		dol_syslog(get_class($this)."::release", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			$error++;
		}

		if (!$error) {
			$this->oldcopy = clone $this;
			$this->status = self::STATUS_RELEASED;
			$this->date_release = $now;
			$this->fk_user_release = $user->id;

			if (!$notrigger) {
				$result = $this->call_trigger('DESIGNTRACE_COMPONENTREVISION_RELEASE', $user);
				if ($result < 0) {
					$error++;
				}
			}
		}

		if ($error) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();

		// A new current revision: links pinning older revisions of this component get flagged
		dol_include_once('/designtrace/class/componentlink.class.php');
		ComponentLink::recomputeFlags($this->db);

		return 1;
	}

	/**
	 *	Make a released revision obsolete (Released -> Obsolete).
	 *
	 *	@param	User		$user		Object user that modify
	 *  @param	int<0,1>	$notrigger	1=Does not execute triggers, 0=Execute triggers
	 *	@return	int<-1,1>				Return integer <0 if KO, 0=Nothing done, >0 if OK
	 */
	public function cancel($user, $notrigger = 0)
	{
		if ((int) $this->status !== self::STATUS_RELEASED) {
			return 0;
		}

		$result = $this->setStatusCommon($user, self::STATUS_OBSOLETE, $notrigger, 'DESIGNTRACE_COMPONENTREVISION_OBSOLETE');
		if ($result > 0) {
			// The component's current revision may change
			dol_include_once('/designtrace/class/componentlink.class.php');
			ComponentLink::recomputeFlags($this->db);
		}
		return $result;
	}

	/**
	 *	Undo obsolete (Obsolete -> Released). The original release date is kept.
	 *
	 *	@param	User		$user		Object user that modify
	 *  @param	int<0,1>	$notrigger	1=Does not execute triggers, 0=Execute triggers
	 *	@return	int<-1,1>				Return integer <0 if KO, 0=Nothing done, >0 if OK
	 */
	public function reopen($user, $notrigger = 0)
	{
		if ((int) $this->status !== self::STATUS_OBSOLETE) {
			return 0;
		}

		$result = $this->setStatusCommon($user, self::STATUS_RELEASED, $notrigger, 'DESIGNTRACE_COMPONENTREVISION_REOPEN');
		if ($result > 0) {
			dol_include_once('/designtrace/class/componentlink.class.php');
			ComponentLink::recomputeFlags($this->db);
		}
		return $result;
	}

	/**
	 *	Return the rev a new revision of a component gets: the letter after the highest
	 *	letter-only rev (A, B, ... Z, AA, AB, ...), or A for the first revision.
	 *
	 *	@param	int		$fk_component	Component id
	 *	@return	string					Next rev, or '' on error
	 */
	public function getNextRevision($fk_component)
	{
		$sql = "SELECT rev FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE fk_component = ".((int) $fk_component);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return '';
		}

		$max = 0;
		while ($obj = $this->db->fetch_object($resql)) {
			$rev = strtoupper((string) $obj->rev);
			if (!preg_match('/^[A-Z]{1,3}$/', $rev)) {
				continue;	// Numeric or custom revs do not take part in the letter sequence
			}
			$n = 0;
			for ($i = 0; $i < strlen($rev); $i++) {
				$n = $n * 26 + (ord($rev[$i]) - 64);
			}
			$max = max($max, $n);
		}
		$this->db->free($resql);

		$n = $max + 1;
		$next = '';
		while ($n > 0) {
			$n--;
			$next = chr(65 + ($n % 26)).$next;
			$n = intdiv($n, 26);
		}

		return $next;
	}

	/**
	 *	Count draft and in-review revisions of a component, ignoring this one.
	 *
	 *	@param	int		$fk_component	Component id
	 *	@return	int						Number found, <0 on error
	 */
	public function countOpenRevisions($fk_component)
	{
		$sql = "SELECT COUNT(rowid) as nb FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE fk_component = ".((int) $fk_component);
		$sql .= " AND status IN (".self::STATUS_DRAFT.", ".self::STATUS_INREVIEW.")";
		if (!empty($this->id)) {
			$sql .= " AND rowid <> ".((int) $this->id);
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return (int) $obj->nb;
	}

	/**
	 *	Check the print settings: no negative values, infill 0 to 100 %.
	 *
	 *	@return	int<-1,1>		Return integer <0 if KO, >0 if OK
	 */
	public function checkPrintSettings()
	{
		global $langs;

		$langs->load("designtrace@designtrace");

		foreach (array('layer_height', 'nozzle_size', 'infill', 'print_time', 'filament_used') as $key) {
			if ($this->$key === '' || $this->$key === null) {
				$this->$key = null;
				continue;
			}
			if ((float) $this->$key < 0) {
				return $this->setErrorAndFail($langs->trans('ErrorDesignTraceNegative', $langs->transnoentitiesnoconv($this->fields[$key]['label'])));
			}
		}
		if ($this->infill !== null && (int) $this->infill > 100) {
			return $this->setErrorAndFail($langs->trans('ErrorInfillOver100'));
		}

		return 1;
	}

	/**
	 *	Normalise and check the rev: 1 to 16 letters, digits, dots or dashes, upper case, unique
	 *	for the component.
	 *
	 *	@return	int<-1,1>		Return integer <0 if KO, >0 if OK
	 */
	public function checkRev()
	{
		global $langs;
		$langs->load("designtrace@designtrace");

		$this->rev = strtoupper(trim((string) $this->rev));
		if (!preg_match('/^[A-Z0-9][A-Z0-9.\-]{0,15}$/', $this->rev)) {
			return $this->setErrorAndFail($langs->trans('ErrorRevisionBadRev', $this->rev));
		}

		$sql = "SELECT rowid FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE fk_component = ".((int) $this->fk_component);
		$sql .= " AND rev = '".$this->db->escape($this->rev)."'";
		if (!empty($this->id)) {
			$sql .= " AND rowid <> ".((int) $this->id);
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}
		$exists = $this->db->num_rows($resql) > 0;
		$this->db->free($resql);
		if ($exists) {
			return $this->setErrorAndFail($langs->trans('ErrorRevisionAlreadyExists', $this->rev));
		}

		return 1;
	}

	/**
	 *	Load the component of this revision, setting an error if it is missing.
	 *
	 *	@return	Component|int<-1,-1>		Component, or -1 if not found
	 */
	public function fetchComponentChecked()
	{
		global $langs;

		dol_include_once('/designtrace/class/component.class.php');

		$component = new Component($this->db);
		if (empty($this->fk_component) || $component->fetch((int) $this->fk_component) <= 0) {
			$langs->load("errors");
			return $this->setErrorAndFail($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Component')));
		}
		$this->component = $component;

		return $component;
	}

	/**
	 *	Refs of the child revisions this revision links to that are in one of the given statuses
	 *	(used to refuse review while a child is a draft, and release while a child is not released)
	 *
	 *	@param	int[]	$statuses	Child statuses that block
	 *	@return	string[]			Child revision refs
	 */
	public function getBlockingChildren($statuses)
	{
		$refs = array();
		if (empty($this->id) || empty($statuses)) {
			return $refs;
		}
		$sql = "SELECT c.ref FROM ".$this->db->prefix()."designtrace_componentlink as l";
		$sql .= " INNER JOIN ".$this->db->prefix().$this->table_element." as c ON c.rowid = l.fk_child";
		$sql .= " WHERE l.fk_parent = ".((int) $this->id);
		$sql .= " AND c.status IN (".$this->db->sanitize(implode(',', array_map('intval', $statuses))).")";
		$sql .= " ORDER BY c.ref";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$refs[] = $obj->ref;
		}
		return $refs;
	}

	/**
	 *	Refs of the parent revisions linking to this revision that are in one of the given statuses
	 *
	 *	@param	int[]	$statuses	Parent statuses
	 *	@return	string[]			Parent revision refs
	 */
	public function getParentsInStatus($statuses)
	{
		$refs = array();
		if (empty($this->id) || empty($statuses)) {
			return $refs;
		}
		$sql = "SELECT p.ref FROM ".$this->db->prefix()."designtrace_componentlink as l";
		$sql .= " INNER JOIN ".$this->db->prefix().$this->table_element." as p ON p.rowid = l.fk_parent";
		$sql .= " WHERE l.fk_child = ".((int) $this->id);
		$sql .= " AND p.status IN (".$this->db->sanitize(implode(',', array_map('intval', $statuses))).")";
		$sql .= " ORDER BY p.ref";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$refs[] = $obj->ref;
		}
		return $refs;
	}

	/**
	 *	Tell if the files of this revision are locked: released and obsolete revisions are read-only.
	 *
	 *	@return	bool
	 */
	public function isLocked()
	{
		return ((int) $this->status >= self::STATUS_RELEASED);
	}

	/**
	 *	Directory of the revision's files (documents/designtrace/componentrevision/<ref>)
	 *
	 *	@param	string	$ref	Ref to use instead of the current one
	 *	@return	string			Full path, without trailing slash
	 */
	public function getFilesDir($ref = '')
	{
		global $conf;

		$entity = (empty($this->entity) ? $conf->entity : $this->entity);
		return $conf->designtrace->multidir_output[$entity].'/'.$this->element.'/'.dol_sanitizeFileName($ref !== '' ? $ref : $this->ref);
	}

	/**
	 *	Count the file records of the revision
	 *
	 *	@param	int		$role	Only this role (RevisionFile::ROLE_*), 0 for all
	 *	@return	int				Number of files, -1 if KO
	 */
	public function countFiles($role = 0)
	{
		$sql = "SELECT COUNT(rowid) as nb FROM ".$this->db->prefix()."designtrace_revisionfile";
		$sql .= " WHERE fk_revision = ".((int) $this->id);
		if ($role > 0) {
			$sql .= " AND role = ".((int) $role);
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			return $this->setErrorAndFail($this->db->lasterror());
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return (int) $obj->nb;
	}

	/**
	 *	Previous revision of the same component (the one created just before this one)
	 *
	 *	@return	ComponentRevision|null
	 */
	public function getPreviousRevision()
	{
		$sql = "SELECT rowid FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE fk_component = ".((int) $this->fk_component)." AND rowid < ".((int) $this->id);
		$sql .= " ORDER BY rowid DESC";
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if (!$resql || !($obj = $this->db->fetch_object($resql))) {
			return null;
		}
		$previous = new self($this->db);
		return ($previous->fetch((int) $obj->rowid) > 0 ? $previous : null);
	}

	/**
	 *	File name a new file gets in this revision: sanitized, lower case extension, and (unless setting
	 *	DESIGNTRACE_FILE_NO_PREFIX is on) prefixed with the component number unless it
	 *	already starts with it, so "bracket.FCStd" becomes "PRT-0001-bracket.fcstd".
	 *
	 *	@param	string	$origname		Name of the uploaded file
	 *	@param	string	$componentref	Component number
	 *	@return	string					File name, '' if not usable
	 */
	public static function buildFileName($origname, $componentref)
	{
		$origname = trim(basename(str_replace('\\', '/', $origname)));
		$info = pathinfo($origname);
		$ext = (isset($info['extension']) && $info['extension'] !== '' ? '.'.strtolower($info['extension']) : '');
		$name = dol_sanitizeFileName($info['filename'].$ext);
		if ($name === '' || $name[0] === '.') {
			return '';
		}
		if (!getDolGlobalInt('DESIGNTRACE_FILE_NO_PREFIX') && !self::fileNameHasRef($name, $componentref)) {
			$name = dol_sanitizeFileName($componentref.'-'.$name);
		}
		return dol_string_nohtmltag($name);
	}

	/**
	 *	Tell if a file name starts with the component number ("PRT-0001.fcstd", "PRT-0001-x.step")
	 *
	 *	@param	string	$filename		File name
	 *	@param	string	$componentref	Component number
	 *	@return	bool
	 */
	public static function fileNameHasRef($filename, $componentref)
	{
		return (bool) preg_match('/^'.preg_quote(dol_sanitizeFileName($componentref), '/').'([-_. ]|$)/i', $filename);
	}

	/**
	 *	Add (or, in an unlocked revision, replace) a file. Checks the lock, the size limit and the
	 *	forbidden extensions, names the file after the component, stores it in the revision's
	 *	directory, hashes it and records it.
	 *
	 *	@param	User		$user		User adding the file
	 *	@param	string		$srcpath	Path of the uploaded file ($_FILES[...]['tmp_name'])
	 *	@param	string		$origname	Original file name
	 *	@param	int			$role		RevisionFile::ROLE_*, 0 to guess from the file
	 *	@param	int<0,1>	$isupload	1 for an HTTP upload, 0 to move a local file (scripts, tests)
	 *	@return	RevisionFile|int<-1,-1>	The file record, or -1 if KO (see $this->error)
	 */
	public function addFile(User $user, $srcpath, $origname, $role = 0, $isupload = 1)
	{
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		dol_include_once('/designtrace/class/revisionfile.class.php');
		$langs->loadLangs(array('errors', 'designtrace@designtrace'));

		$this->warnings = array();
		if ($this->isLocked()) {
			return $this->setErrorAndFail($langs->trans('ErrorRevisionFilesLocked', $this->ref));
		}
		$component = $this->fetchComponentChecked();
		if (!is_object($component)) {
			return -1;
		}
		if ($role && !in_array((int) $role, array(RevisionFile::ROLE_SOURCE, RevisionFile::ROLE_PRESENTATION, RevisionFile::ROLE_PRODUCTION), true)) {
			return $this->setErrorAndFail($langs->trans('ErrorBadValueForParameter', (string) $role, $langs->transnoentitiesnoconv('FileRole')));
		}

		$size = (int) @filesize($srcpath);
		$max = RevisionFile::getMaxUploadBytes();
		if ($max > 0 && $size > $max) {
			return $this->setErrorAndFail($langs->trans('ErrorRevisionFileTooLarge', $origname, RevisionFile::formatSize($size), RevisionFile::formatSize($max)));
		}

		$filename = self::buildFileName($origname, $component->ref);
		if ($filename === '') {
			return $this->setErrorAndFail($langs->trans('ErrorFilenameCantStartWithDot', $origname));
		}
		if (!self::fileNameHasRef($filename, $component->ref)) {
			$this->warnings[] = $langs->trans('WarningRevisionFileNotNamedAfterComponent', $filename, $component->ref);
		}

		// Same check as Dolibarr's own uploads: no executable content
		$defaultexecutableextensions = function_exists('getExecutableContent') ? implode(',', getExecutableContent()) : 'htm,html,shtml,js,phar,php,php3,php4,php5,phtml,pht,pl,py,cgi,ksh,sh,bash,bat,cmd,wpk,exe';
		foreach (explode(',', getDolGlobalString('MAIN_FILE_EXTENSION_UPLOAD_RESTRICTION', $defaultexecutableextensions)) as $forbidden) {
			if (trim($forbidden) !== '' && preg_match('/\.'.preg_quote(trim($forbidden), '/').'$/i', $filename)) {
				return $this->setErrorAndFail($langs->trans('ErrorFilenameExtensionNotAllowed', $filename));
			}
		}

		$dir = $this->getFilesDir();
		if (dol_mkdir($dir) < 0) {
			return $this->setErrorAndFail($langs->trans('ErrorCanNotCreateDir', $dir));
		}

		$existing = null;
		$sql = "SELECT rowid FROM ".$this->db->prefix()."designtrace_revisionfile WHERE fk_revision = ".((int) $this->id)." AND filename = '".$this->db->escape($filename)."'";
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$existing = new RevisionFile($this->db);
			$existing->fetch((int) $obj->rowid);
		}

		$res = dol_move_uploaded_file($srcpath, $dir.'/'.$filename, 1, 0, 0, 0, 'userfile', $dir, $isupload ? 0 : 1);
		if (is_numeric($res) && (int) $res == 2) {
			$filename .= '.noexe';
		} elseif (!is_numeric($res) || (int) $res <= 0) {
			return $this->setErrorAndFail($langs->trans(is_numeric($res) ? 'ErrorFileNotUploaded' : (string) preg_replace('/:.*$/', '', (string) $res)).' ('.$origname.')');
		}
		$fullpath = $dir.'/'.$filename;

		$file = ($existing ? $existing : new RevisionFile($this->db));
		$file->fk_revision = $this->id;
		$file->filename = $filename;
		$file->role = ($role ? (int) $role : RevisionFile::guessRole($filename, $fullpath));
		$file->format = substr(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), 0, 16);
		$file->filesize = (int) filesize($fullpath);
		$file->sha256 = hash_file('sha256', $fullpath);
		$result = ($existing ? $file->update($user) : $file->create($user));
		if ($result <= 0) {
			dol_delete_file($fullpath, 0, 0, 0, $this);
			$this->setErrorsFromObject($file);
			return -1;
		}
		if ($existing) {
			$this->warnings[] = $langs->trans('RevisionFileReplaced', $filename);
		} else {
			addFileIntoDatabaseIndex($dir, $filename, $origname, 'uploaded', 0, $this);
		}

		return $file;
	}

	/**
	 *	Delete a file and its record. In a locked revision this needs $forcelocked (the caller
	 *	checks the "delete files of released revisions" right) and a reason; it is then logged
	 *	as an event on the revision.
	 *
	 *	@param	User			$user			User deleting
	 *	@param	RevisionFile	$file			File of this revision
	 *	@param	bool			$forcelocked	Allow deleting from a released or obsolete revision
	 *	@param	string			$reason			Why (required when locked)
	 *	@return	int<-1,1>						>0 if OK, <0 if KO
	 */
	public function deleteFile(User $user, $file, $forcelocked = false, $reason = '')
	{
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		$langs->loadLangs(array('errors', 'designtrace@designtrace'));

		if ((int) $file->fk_revision !== (int) $this->id) {
			return $this->setErrorAndFail($langs->trans('ErrorRecordNotFound'));
		}
		$locked = $this->isLocked();
		if ($locked && !$forcelocked) {
			return $this->setErrorAndFail($langs->trans('ErrorRevisionFilesLocked', $this->ref));
		}
		if ($locked && trim($reason) === '') {
			return $this->setErrorAndFail($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Reason')));
		}

		$this->db->begin();
		if ($file->delete($user) <= 0) {
			$this->db->rollback();
			$this->setErrorsFromObject($file);
			return -1;
		}
		if ($locked) {
			require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
			$now = dol_now();
			$event = new ActionComm($this->db);
			$event->type_code = 'AC_OTH_AUTO';
			$event->code = 'AC_DESIGNTRACE_FILE_DELETE';
			$event->label = $langs->transnoentities('EventRevisionFileDeleted', $file->filename, $this->ref);
			$event->note_private = $langs->transnoentities('EventRevisionFileDeletedNote', $file->filename, $this->ref, $file->sha256, RevisionFile::formatSize((int) $file->filesize), trim($reason));
			$event->datep = $now;
			$event->datef = $now;
			$event->percentage = -1;
			$event->userownerid = $user->id;
			$event->authorid = $user->id;
			$event->elementtype = $this->element.'@'.$this->module;
			$event->fk_element = $this->id;
			if ($event->create($user) <= 0) {
				$this->db->rollback();
				$this->setErrorsFromObject($event);
				return -1;
			}
		}
		$fullpath = $this->getFilesDir().'/'.$file->filename;
		if (file_exists($fullpath) && !dol_delete_file($fullpath, 0, 0, 0, $this)) {
			$this->db->rollback();
			return $this->setErrorAndFail($langs->trans('ErrorFailToDeleteFile', $file->filename));
		}
		$this->db->commit();

		return 1;
	}

	/**
	 *	Check the files on disk against their records
	 *
	 *	@return	array{files:array<int,string>,untracked:string[]}|int<-1,-1>	Per record id 'ok', 'changed' or 'missing', and files on disk without a record; -1 if KO
	 */
	public function verifyFiles()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		dol_include_once('/designtrace/class/revisionfile.class.php');

		$records = (new RevisionFile($this->db))->fetchAllForRevision($this->id);
		if (!is_array($records)) {
			return -1;
		}
		$dir = $this->getFilesDir();
		$result = array('files' => array(), 'untracked' => array());
		$known = array();
		foreach ($records as $file) {
			$known[] = $file->filename;
			$fullpath = $dir.'/'.$file->filename;
			if (!is_file($fullpath)) {
				$result['files'][$file->id] = 'missing';
			} elseif (hash_file('sha256', $fullpath) !== $file->sha256) {
				$result['files'][$file->id] = 'changed';
			} else {
				$result['files'][$file->id] = 'ok';
			}
		}
		foreach (dol_dir_list($dir, 'files', 0, '', '(\.meta|_preview.*\.png)$') as $ondisk) {
			if (!in_array($ondisk['name'], $known, true)) {
				$result['untracked'][] = $ondisk['name'];
			}
		}

		return $result;
	}

	/**
	 *	Move the files directory and its documents index entries when the ref changes (a draft
	 *	revision whose rev is edited)
	 *
	 *	@param	string	$oldref		Previous ref
	 *	@param	string	$newref		New ref
	 *	@return	int<-1,1>			>0 if OK, <0 if KO
	 */
	private function renameFilesDir($oldref, $newref)
	{
		global $langs;

		$olddir = $this->getFilesDir($oldref);
		$newdir = $this->getFilesDir($newref);
		if (!file_exists($olddir)) {
			return 1;
		}
		if (file_exists($newdir) || !@rename($olddir, $newdir)) {
			$langs->load('errors');
			return $this->setErrorAndFail($langs->trans('ErrorFailToRenameDir', $olddir, $newdir));
		}
		$oldrel = trim(preg_replace('/^'.preg_quote(DOL_DATA_ROOT, '/').'/', '', $olddir), '/');
		$newrel = trim(preg_replace('/^'.preg_quote(DOL_DATA_ROOT, '/').'/', '', $newdir), '/');
		$sql = "UPDATE ".$this->db->prefix()."ecm_files SET filepath = '".$this->db->escape($newrel)."'";
		$sql .= " WHERE filepath = '".$this->db->escape($oldrel)."'";
		if (!$this->db->query($sql)) {
			@rename($newdir, $olddir);
			return $this->setErrorAndFail($this->db->lasterror());
		}

		return 1;
	}

	/**
	 *	Set an error message and return -1.
	 *
	 *	@param	string	$message	Translated message
	 *	@return	int<-1,-1>			Always -1
	 */
	private function setErrorAndFail($message)
	{
		$this->error = $message;
		$this->errors[] = $message;
		return -1;
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
			return ['optimize' => $langs->trans("ShowComponentRevision")];
		}
		$datas['picto'] = img_picto('', $this->picto).' <u>'.$langs->trans("ComponentRevision").'</u>';
		if (isset($this->status)) {
			$datas['picto'] .= ' '.$this->getLibStatut(5);
		}
		if (property_exists($this, 'ref')) {
			$datas['ref'] = '<br><b>'.$langs->trans('Ref').':</b> '.$this->ref;
		}
		if (property_exists($this, 'label')) {
			$datas['label'] = '<br>'.$langs->trans('Label').':</b> '.$this->label;
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

		$baseurl = dol_buildpath('/designtrace/componentrevision_card.php', 1);
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
				$label = $langs->trans("ShowComponentRevision");
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
			$langs->load("designtrace@designtrace");
			foreach (array(
				self::STATUS_DRAFT => 'Draft',
				self::STATUS_INREVIEW => 'RevisionStatusInReview',
				self::STATUS_RELEASED => 'RevisionStatusReleased',
				self::STATUS_OBSOLETE => 'RevisionStatusObsolete',
			) as $code => $key) {
				$this->labelStatus[$code] = $langs->transnoentitiesnoconv($key);
				$this->labelStatusShort[$code] = $langs->transnoentitiesnoconv($key);
			}
		}

		$statustypes = array(self::STATUS_DRAFT => 'status0', self::STATUS_INREVIEW => 'status1', self::STATUS_RELEASED => 'status4', self::STATUS_OBSOLETE => 'status6');
		$statusType = $statustypes[$status] ?? 'status0';

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
				$modele = getDolGlobalString('COMPONENTREVISION_ADDON_PDF', 'standard_componentrevision');
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
