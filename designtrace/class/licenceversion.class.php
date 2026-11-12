<?php
/* Copyright (C) 2017       Laurent Destailleur      <eldy@users.sourceforge.net>
 * Copyright (C) 2023-2025  Frédéric France          <frederic.france@free.fr>
 * Copyright (C) 2026		SuperAdmin
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
 * \file        designtrace/class/licenceversion.class.php
 * \ingroup     designtrace
 * \brief       This file is a CRUD class file for LicenceVersion (Create/Read/Update/Delete)
 */

// Put here all includes required by your class file
require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
dol_include_once('/designtrace/class/licence.class.php');

/**
 * Class for LicenceVersion
 */
class LicenceVersion extends CommonObject
{
	/**
	 * @var string 		ID of module.
	 */
	public $module = 'designtrace';

	/**
	 * @var string 		ID to identify managed object.
	 */
	public $element = 'licenceversion';

	/**
	 * @var string		Prefix to check for any trigger code of any business class to prevent bad value for trigger code.
	 * @see CommonTrigger::call_trigger()
	 */
	public $TRIGGER_PREFIX = 'DESIGNTRACE_LICENCEVERSION';	// Will be used to build trgiger keys 'DESIGNTRACE_LICENCEVERSION_MODIFY', ...

	/**
	 * @var string 		Name of table without prefix where object is stored. This is also the key used for extrafields management (so extrafields know the link to the parent table).
	 */
	public $table_element = 'designtrace_licenceversion';

	/**
	 * @var string 		If permission must be checked with hasRight('designtrace', 'read') and not hasright('designtrace', 'licenceversion', 'read'), you can uncomment this line
	 */
	//public $element_for_permission = 'designtrace';

	/**
	 * @var string 		String with name of icon for licenceversion. Must be a 'fa-xxx' fontawesome code (or 'fa-xxx_fa_color_size') or 'licenceversion@designtrace' if picto is file 'img/object_licenceversion.png'.
	 */
	public $picto = 'fa-file-contract';

	/**
	 * @var int<0,1>	Does object support extrafields ? 0=No, 1=Yes
	 */
	public $isextrafieldmanaged = 1;

	/**
	 * @var int<0,1>|string		Does this object support multicompany module ?
	 * 							0=No test on entity, 1=Test with field entity in local table, 'field@table'=Test entity into the field@table (example 'fk_soc@societe')
	 */
	public $ismultientitymanaged = 1;


	const STATUS_DRAFT = 0;			// Being written, editable
	const STATUS_CURRENT = 1;		// The version in use, read-only
	const STATUS_SUPERSEDED = 9;	// Replaced by a newer version, read-only (acceptances keep pointing to it)
	/**
	 * @deprecated Module Builder names
	 */
	const STATUS_VALIDATED = 1;
	const STATUS_CANCELED = 9;

	// BEGIN MODULEBUILDER PROPERTIES
	/**
	 * @inheritdoc
	 * Array with all fields and their property. Edit together with sql/llx_designtrace_licenceversion.sql.
	 */
	public $fields = array(
		"rowid" => array("type" => "integer", "label" => "TechnicalID", "enabled" => "1", 'position' => 1, 'notnull' => 1, "visible" => "0", "noteditable" => "1", "index" => "1", "css" => "left", "comment" => "Id"),
		"entity" => array("type" => "integer", "label" => "Entity", "enabled" => "1", 'position' => 5, 'notnull' => 1, "visible" => "0", "default" => "1", "index" => "1",),
		"ref" => array("type" => "varchar(64)", "label" => "Ref", "enabled" => "1", 'position' => 10, 'notnull' => 1, "visible" => "5", "noteditable" => "1", "index" => "1", "searchall" => "1", "showoncombobox" => "1",),
		"fk_licence" => array("type" => "integer:Licence:designtrace/class/licence.class.php", "label" => "LicenceTemplate", "picto" => "fa-file-signature", "enabled" => "1", 'position' => 15, 'notnull' => 1, "visible" => "5", "noteditable" => "1", "index" => "1",),
		"version" => array("type" => "integer", "label" => "LicenceVersionNumber", "enabled" => "1", 'position' => 20, 'notnull' => 1, "visible" => "5", "noteditable" => "1",),
		"wording" => array("type" => "html", "label" => "LicenceWording", "enabled" => "1", 'position' => 30, 'notnull' => 0, "visible" => "3", "help" => "LicenceWordingHelp", "cssview" => "wordbreak",),
		"perm_personal" => array("type" => "boolean", "label" => "LicencePermPersonal", "enabled" => "1", 'position' => 40, 'notnull' => 1, "visible" => "1", "default" => "1",),
		"perm_commercial" => array("type" => "boolean", "label" => "LicencePermCommercial", "enabled" => "1", 'position' => 41, 'notnull' => 1, "visible" => "1", "default" => "0",),
		"perm_resell" => array("type" => "boolean", "label" => "LicencePermResell", "enabled" => "1", 'position' => 42, 'notnull' => 1, "visible" => "1", "default" => "0",),
		"perm_modify" => array("type" => "boolean", "label" => "LicencePermModify", "enabled" => "1", 'position' => 43, 'notnull' => 1, "visible" => "1", "default" => "0",),
		"perm_source" => array("type" => "boolean", "label" => "LicencePermSource", "enabled" => "1", 'position' => 44, 'notnull' => 1, "visible" => "1", "default" => "0", "help" => "LicencePermSourceHelp",),
		"perm_exclusive" => array("type" => "boolean", "label" => "LicencePermExclusive", "enabled" => "1", 'position' => 45, 'notnull' => 1, "visible" => "1", "default" => "0",),
		"perm_attribution" => array("type" => "boolean", "label" => "LicencePermAttribution", "enabled" => "1", 'position' => 46, 'notnull' => 1, "visible" => "1", "default" => "0",),
		"perm_portfolio" => array("type" => "boolean", "label" => "LicencePermPortfolio", "enabled" => "1", 'position' => 47, 'notnull' => 1, "visible" => "1", "default" => "0",),
		"change_note" => array("type" => "varchar(255)", "label" => "LicenceChangeNote", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "1", "css" => "minwidth400",),
		"date_activation" => array("type" => "datetime", "label" => "LicenceDateActivation", "enabled" => "1", 'position' => 60, 'notnull' => 0, "visible" => "5", "noteditable" => "1",),
		"fk_user_activation" => array("type" => "integer:User:user/class/user.class.php", "label" => "LicenceUserActivation", "picto" => "user", "enabled" => "1", 'position' => 61, 'notnull' => -1, "visible" => "5", "noteditable" => "1",),
		"date_creation" => array("type" => "datetime", "label" => "DateCreation", "enabled" => "1", 'position' => 500, 'notnull' => 1, "visible" => "-2",),
		"tms" => array("type" => "timestamp", "label" => "DateModification", "enabled" => "1", 'position' => 501, 'notnull' => 0, "visible" => "-2",),
		"fk_user_creat" => array("type" => "integer:User:user/class/user.class.php", "label" => "UserAuthor", "picto" => "user", "enabled" => "1", 'position' => 510, 'notnull' => 1, "visible" => "-2",),
		"fk_user_modif" => array("type" => "integer:User:user/class/user.class.php", "label" => "UserModif", "picto" => "user", "enabled" => "1", 'position' => 511, 'notnull' => -1, "visible" => "-2",),
		"import_key" => array("type" => "varchar(14)", "label" => "ImportId", "enabled" => "1", 'position' => 1000, 'notnull' => -1, "visible" => "-2",),
		"status" => array("type" => "integer", "label" => "Status", "enabled" => "1", 'position' => 2000, 'notnull' => 1, "visible" => "2", "default" => "0", "index" => "1", "arrayofkeyval" => array("0" => "Draft", "1" => "LicenceCurrent", "9" => "LicenceSuperseded"),),
	);
	public $rowid;
	public $entity;
	public $ref;
	public $fk_licence;
	public $version;
	public $wording;
	public $perm_personal;
	public $perm_commercial;
	public $perm_resell;
	public $perm_modify;
	public $perm_source;
	public $perm_exclusive;
	public $perm_attribution;
	public $perm_portfolio;
	public $change_note;
	public $date_activation;
	public $fk_user_activation;
	public $date_creation;
	public $tms;
	public $fk_user_creat;
	public $fk_user_modif;
	public $import_key;
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
		/*if ($user->hasRight('designtrace', 'licenceversion', 'read')) {
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

		$langs->load('designtrace@designtrace');
		$licence = new Licence($this->db);
		if ((int) $this->fk_licence <= 0 || $licence->fetch((int) $this->fk_licence) <= 0) {
			return $this->setErrorAndFail($langs->trans('ErrorRecordNotFound'));
		}
		$sql = "SELECT MAX(version) as max FROM ".$this->db->prefix().$this->table_element." WHERE fk_licence = ".((int) $licence->id);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		$this->version = ($obj ? (int) $obj->max : 0) + 1;
		$this->ref = $licence->ref.'-v'.$this->version;
		$this->status = self::STATUS_DRAFT;
		$this->date_activation = null;
		$this->fk_user_activation = null;
		foreach (self::getPermissionKeys() as $key) {
			if ($this->$key === null || $this->$key === '') {
				$this->$key = (int) $this->fields[$key]['default'];
			}
			$this->$key = $this->$key ? 1 : 0;
		}

		// createCommon() stores an empty date_creation back on the object as a DB string, which a
		// later updateCommon() on the same object shifts by the timezone offset: give it a timestamp
		if (empty($this->date_creation)) {
			$this->date_creation = dol_now();
		}
		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Keys of the permission fields, in display order
	 *
	 * @return	string[]
	 */
	public static function getPermissionKeys()
	{
		return array('perm_personal', 'perm_commercial', 'perm_resell', 'perm_modify', 'perm_source', 'perm_exclusive', 'perm_attribution', 'perm_portfolio');
	}

	/**
	 * Version of a template with a given status (draft or current)
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$licenceid	Template id
	 * @param	int		$status		self::STATUS_*
	 * @return	LicenceVersion|null
	 */
	public static function fetchByStatus($db, $licenceid, $status)
	{
		$sql = "SELECT rowid FROM ".$db->prefix()."designtrace_licenceversion WHERE fk_licence = ".((int) $licenceid)." AND status = ".((int) $status);
		$sql .= " ORDER BY version DESC";
		$sql .= $db->plimit(1);
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		if (!$obj) {
			return null;
		}
		$version = new self($db);
		return ($version->fetch((int) $obj->rowid) > 0 ? $version : null);
	}

	/**
	 * Make this draft the current version; the previous current one is superseded. Needs some
	 * wording.
	 *
	 * @param	User		$user		User
	 * @param	int<0,1>	$notrigger	1=Does not execute triggers
	 * @return	int						<0 if KO, 0 if nothing done, >0 if OK
	 */
	public function activate($user, $notrigger = 0)
	{
		global $langs;

		$langs->load('designtrace@designtrace');
		if ((int) $this->status !== self::STATUS_DRAFT) {
			return 0;
		}
		if (trim(dol_string_nohtmltag((string) $this->wording)) === '') {
			return $this->setErrorAndFail($langs->trans('ErrorLicenceWordingEmpty', $this->ref));
		}
		$now = dol_now();
		$this->db->begin();
		$sql = "UPDATE ".$this->db->prefix().$this->table_element." SET status = ".self::STATUS_SUPERSEDED;
		$sql .= " WHERE fk_licence = ".((int) $this->fk_licence)." AND status = ".self::STATUS_CURRENT;
		$ok = $this->db->query($sql);
		if ($ok) {
			$sql = "UPDATE ".$this->db->prefix().$this->table_element." SET date_activation = '".$this->db->idate($now)."', fk_user_activation = ".((int) $user->id);
			$sql .= " WHERE rowid = ".((int) $this->id);
			$ok = $this->db->query($sql);
		}
		if (!$ok || $this->setStatusCommon($user, self::STATUS_CURRENT, $notrigger, 'DESIGNTRACE_LICENCEVERSION_ACTIVATE') <= 0) {
			$this->db->rollback();
			if (!$ok) {
				$this->setErrorAndFail($this->db->lasterror());
			}
			return -1;
		}
		$this->db->commit();
		$this->date_activation = $now;
		$this->fk_user_activation = $user->id;
		return 1;
	}

	/**
	 * Wording with its variables replaced: {customer}, {design}, {revision}, {date}, {scope}
	 *
	 * @param	array<string,string>	$values		Variable => value (plain text)
	 * @return	string							HTML
	 */
	public function renderWording($values)
	{
		$replace = array();
		foreach (array('customer', 'design', 'revision', 'date', 'scope') as $key) {
			$replace['{'.$key.'}'] = dol_escape_htmltag(isset($values[$key]) ? (string) $values[$key] : '');
		}
		return strtr((string) $this->wording, $replace);
	}

	/**
	 * Permissions as label => yes/no, in display order
	 *
	 * @return	array<string,int>
	 */
	public function getPermissions()
	{
		global $langs;

		$langs->load('designtrace@designtrace');
		$result = array();
		foreach (self::getPermissionKeys() as $key) {
			$result[$langs->transnoentitiesnoconv($this->fields[$key]['label'])] = (int) $this->$key;
		}
		return $result;
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
		global $langs;

		$stored = new self($this->db);
		if ($stored->fetchCommon($this->id) <= 0) {
			$this->setErrorsFromObject($stored);
			return -1;
		}
		// Only drafts change: past acceptances must keep the exact wording agreed to
		if ((int) $stored->status !== self::STATUS_DRAFT) {
			$langs->load('designtrace@designtrace');
			return $this->setErrorAndFail($langs->trans('ErrorLicenceVersionReadOnly', $stored->ref));
		}
		foreach (array('ref', 'fk_licence', 'version', 'status', 'date_activation', 'fk_user_activation') as $key) {
			$this->$key = $stored->$key;
		}
		foreach (self::getPermissionKeys() as $key) {
			$this->$key = $this->$key ? 1 : 0;
		}
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

		if ((int) $this->status !== self::STATUS_DRAFT) {
			$langs->load('designtrace@designtrace');
			return $this->setErrorAndFail($langs->trans('ErrorLicenceVersionReadOnly', $this->ref));
		}
		return $this->deleteCommon($user, $notrigger);
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
			return ['optimize' => $langs->trans("ShowLicenceVersion")];
		}
		$datas['picto'] = img_picto('', $this->picto).' <u>'.$langs->trans("LicenceVersion").'</u>';
		if (isset($this->status)) {
			$datas['picto'] .= ' '.$this->getLibStatut(5);
		}
		if (property_exists($this, 'ref')) {
			$datas['ref'] = '<br><b>'.$langs->trans('Ref').':</b> '.$this->ref;
		}
		if (property_exists($this, 'label')) {
			$datas['label'] = '<br><b>'.$langs->trans('LicenceVersionNumber').':</b> '.((int) $this->version);
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

		$baseurl = dol_buildpath('/designtrace/licenceversion_card.php', 1);
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
				$label = $langs->trans("ShowLicenceVersion");
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
			$langs->load('designtrace@designtrace');
			$this->labelStatus[self::STATUS_DRAFT] = $langs->transnoentitiesnoconv('Draft');
			$this->labelStatus[self::STATUS_CURRENT] = $langs->transnoentitiesnoconv('LicenceCurrent');
			$this->labelStatus[self::STATUS_SUPERSEDED] = $langs->transnoentitiesnoconv('LicenceSuperseded');
			$this->labelStatusShort = $this->labelStatus;
		}

		$statusType = ($status == self::STATUS_CURRENT ? 'status4' : ($status == self::STATUS_SUPERSEDED ? 'status6' : 'status0'));

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

		if (!getDolGlobalString('DESIGNTRACE_LICENCEVERSION_ADDON')) {
			$conf->global->DESIGNTRACE_LICENCEVERSION_ADDON = 'mod_licenceversion_standard';
		}

		if (getDolGlobalString('DESIGNTRACE_LICENCEVERSION_ADDON')) {
			$mybool = false;

			$file = getDolGlobalString('DESIGNTRACE_LICENCEVERSION_ADDON').".php";
			$classname = getDolGlobalString('DESIGNTRACE_LICENCEVERSION_ADDON');

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
				'@phan-var-force ModeleNumRefLicenceVersion $obj';
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
				$modele = getDolGlobalString('LICENCEVERSION_ADDON_PDF', 'standard_licenceversion');
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
