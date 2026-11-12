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
 *	\file       designtrace/class/revisionfile.class.php
 *	\ingroup    designtrace
 *	\brief      File of a component revision: role, hash and visibility of one stored file
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';


/**
 *	One file of a component revision.
 *
 *	The file itself lives in the revision's documents directory; this record holds what the
 *	module needs to know about it: its role, format, size, SHA-256 and client visibility.
 *	Files are added, replaced and deleted through ComponentRevision (addFile(), deleteFile()),
 *	which enforces the lock on released revisions.
 */
class RevisionFile extends CommonObject
{
	/**
	 * @var string ID of module.
	 */
	public $module = 'designtrace';

	/**
	 * @var string ID to identify managed object.
	 */
	public $element = 'revisionfile';

	/**
	 * @var string Name of table without prefix where object is stored.
	 */
	public $table_element = 'designtrace_revisionfile';

	/**
	 * @var string String with name of icon for revisionfile.
	 */
	public $picto = 'file';

	/**
	 * @var int<0,2>	The entity comes from the revision
	 */
	public $ismultientitymanaged = 0;

	/**
	 * @var int<0,1>
	 */
	public $isextrafieldmanaged = 0;

	const ROLE_SOURCE = 1;			// Native CAD file (FCStd, ...). Never shared unless the licence allows it
	const ROLE_PRESENTATION = 2;	// STEP, STL, 3MF, PDF, ...
	const ROLE_PRODUCTION = 3;		// Slicer projects, G-code, resin files. Never client-visible

	const VISIBILITY_HIDDEN = 0;		// Internal only (default)
	const VISIBILITY_NOW = 1;			// Visible to the customer now (phase 5)
	const VISIBILITY_ON_COMPLETION = 2;	// Visible when the order is completed (phase 5)

	/**
	 * Default extensions per role, used while the setting is empty
	 */
	const DEFAULT_EXT_SOURCE = 'fcstd,f3d,f3z,sldprt,sldasm,slddrw,ipt,iam,idw,catpart,catproduct,prt,asm,x_t,x_b,scad,blend,skp,dwg';
	const DEFAULT_EXT_PRESENTATION = 'step,stp,iges,igs,stl,obj,3mf,pdf,png,jpg,jpeg,svg';
	const DEFAULT_EXT_PRODUCTION = 'gcode,bgcode,gco,nc,ngc,tap,ctb,cbddlp,goo,pwmx,pwmo,sl1,sl1s,lys,dxf';

	/**
	 * @var array<string,array{type:string,label:string,enabled:int<0,2>|string,position:int,notnull?:int,visible:int<-6,6>|string,noteditable?:int<0,1>,default?:string,index?:int,foreignkey?:string,searchall?:int<0,1>,isameasure?:int<0,1>,css?:string,csslist?:string,help?:string,showoncombobox?:int<0,4>,disabled?:int<0,1>,arrayofkeyval?:array<int|string,string>,autofocusoncreate?:int<0,1>,comment?:string,copytoclipboard?:int<1,2>,validate?:int<0,1>,showonheader?:int<0,1>}>  Array with all fields and their property.
	 */
	public $fields = array(
		"rowid" => array("type" => "integer", "label" => "TechnicalID", "enabled" => "1", 'position' => 1, 'notnull' => 1, "visible" => "0", "noteditable" => "1", "index" => "1"),
		"fk_revision" => array("type" => "integer:ComponentRevision:designtrace/class/componentrevision.class.php", "label" => "ComponentRevision", "enabled" => "1", 'position' => 10, 'notnull' => 1, "visible" => "0", "index" => "1", "foreignkey" => "designtrace_componentrevision.rowid"),
		"filename" => array("type" => "varchar(255)", "label" => "Filename", "enabled" => "1", 'position' => 20, 'notnull' => 1, "visible" => "1"),
		"role" => array("type" => "integer", "label" => "FileRole", "enabled" => "1", 'position' => 30, 'notnull' => 1, "visible" => "1", "index" => "1", "arrayofkeyval" => array("1" => "FileRoleSource", "2" => "FileRolePresentation", "3" => "FileRoleProduction")),
		"format" => array("type" => "varchar(16)", "label" => "Format", "enabled" => "1", 'position' => 40, 'notnull' => 0, "visible" => "1"),
		"filesize" => array("type" => "integer", "label" => "Size", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "1"),
		"sha256" => array("type" => "varchar(64)", "label" => "SHA256", "enabled" => "1", 'position' => 60, 'notnull' => 1, "visible" => "1", "index" => "1"),
		"visibility" => array("type" => "integer", "label" => "FileVisibility", "enabled" => "1", 'position' => 70, 'notnull' => 1, "visible" => "1", "default" => "0", "arrayofkeyval" => array("0" => "FileVisibilityHidden", "1" => "FileVisibilityNow", "2" => "FileVisibilityOnCompletion")),
		"date_creation" => array("type" => "datetime", "label" => "DateCreation", "enabled" => "1", 'position' => 500, 'notnull' => 1, "visible" => "-2"),
		"tms" => array("type" => "timestamp", "label" => "DateModification", "enabled" => "1", 'position' => 501, 'notnull' => 0, "visible" => "-2"),
		"fk_user_creat" => array("type" => "integer:User:user/class/user.class.php", "label" => "UserAuthor", "enabled" => "1", 'position' => 510, 'notnull' => 1, "visible" => "-2", "foreignkey" => "user.rowid"),
		"fk_user_modif" => array("type" => "integer:User:user/class/user.class.php", "label" => "UserModif", "enabled" => "1", 'position' => 511, 'notnull' => -1, "visible" => "-2"),
		"import_key" => array("type" => "varchar(14)", "label" => "ImportId", "enabled" => "1", 'position' => 1000, 'notnull' => -1, "visible" => "-2"),
	);
	public $rowid;
	public $fk_revision;
	public $filename;
	public $role;
	public $format;
	public $filesize;
	public $sha256;
	public $visibility;
	public $date_creation;
	public $tms;
	public $fk_user_creat;
	public $fk_user_modif;
	public $import_key;


	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Create the record. Use ComponentRevision::addFile() to add a file.
	 *
	 * @param	User		$user		User that creates
	 * @param	int<0,1>	$notrigger	0=launch triggers, 1=disable triggers
	 * @return	int<-1,max>				Id of created object if OK, <0 if KO
	 */
	public function create(User $user, $notrigger = 1)
	{
		if (empty($this->visibility)) {
			$this->visibility = self::VISIBILITY_HIDDEN;
		}
		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Load the record
	 *
	 * @param	int		$id		Id
	 * @return	int<-1,1>		<0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id)
	{
		return $this->fetchCommon($id);
	}

	/**
	 * Update the record
	 *
	 * @param	User		$user		User that modifies
	 * @param	int<0,1>	$notrigger	0=launch triggers, 1=disable triggers
	 * @return	int<-1,1>				<0 if KO, >0 if OK
	 */
	public function update(User $user, $notrigger = 1)
	{
		return $this->updateCommon($user, $notrigger);
	}

	/**
	 * Delete the record (not the file: see ComponentRevision::deleteFile())
	 *
	 * @param	User		$user		User that deletes
	 * @param	int<0,1>	$notrigger	0=launch triggers, 1=disable triggers
	 * @return	int<-1,1>				<0 if KO, >0 if OK
	 */
	public function delete(User $user, $notrigger = 1)
	{
		return $this->deleteCommon($user, $notrigger);
	}

	/**
	 * Load all file records of a revision, sorted by role then name
	 *
	 * @param	int		$fk_revision	Revision id
	 * @return	RevisionFile[]|int<-1,-1>	Records, or -1 if KO
	 */
	public function fetchAllForRevision($fk_revision)
	{
		$sql = "SELECT rowid FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE fk_revision = ".((int) $fk_revision);
		$sql .= " ORDER BY role ASC, filename ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}
		$records = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$file = new self($this->db);
			if ($file->fetch((int) $obj->rowid) > 0) {
				$records[] = $file;
			}
		}
		$this->db->free($resql);

		return $records;
	}

	/**
	 * Extensions (lower case, no dot) per role, from the module setup or the defaults
	 *
	 * @return	array<int,string[]>		Role => extensions
	 */
	public static function getRoleExtensions()
	{
		$lists = array(
			self::ROLE_SOURCE => getDolGlobalString('DESIGNTRACE_ROLE_EXT_SOURCE', self::DEFAULT_EXT_SOURCE),
			self::ROLE_PRESENTATION => getDolGlobalString('DESIGNTRACE_ROLE_EXT_PRESENTATION', self::DEFAULT_EXT_PRESENTATION),
			self::ROLE_PRODUCTION => getDolGlobalString('DESIGNTRACE_ROLE_EXT_PRODUCTION', self::DEFAULT_EXT_PRODUCTION),
		);
		$result = array();
		foreach ($lists as $role => $list) {
			$result[$role] = array_values(array_filter(array_map(function ($ext) {
				return strtolower(ltrim(trim($ext), '.'));
			}, explode(',', $list))));
		}
		return $result;
	}

	/**
	 * Guess the role of a file from its extension (module setup). A .3mf that contains slicer
	 * settings or G-code is a slicer project, so production (unless setting DESIGNTRACE_3MF_NO_DETECT is on).
	 * Unknown extensions are presentation files.
	 *
	 * @param	string	$filename	File name
	 * @param	string	$fullpath	Path of the file on disk, to look inside a .3mf ('' to skip)
	 * @return	int					ROLE_* constant
	 */
	public static function guessRole($filename, $fullpath = '')
	{
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

		if ($ext == '3mf' && $fullpath !== '' && !getDolGlobalInt('DESIGNTRACE_3MF_NO_DETECT') && self::is3mfSlicerProject($fullpath)) {
			return self::ROLE_PRODUCTION;
		}
		foreach (self::getRoleExtensions() as $role => $extensions) {
			if (in_array($ext, $extensions, true)) {
				return $role;
			}
		}
		return self::ROLE_PRESENTATION;
	}

	/**
	 * Tell if a .3mf file is a slicer project (Bambu Studio, Orca, PrusaSlicer, ...) rather than
	 * a plain model: slicers store their settings, and sliced plates their G-code, in the archive.
	 *
	 * @param	string	$fullpath	Path of the .3mf file
	 * @return	bool
	 */
	public static function is3mfSlicerProject($fullpath)
	{
		if (!class_exists('ZipArchive')) {
			return false;
		}
		$zip = new ZipArchive();
		if ($zip->open($fullpath) !== true) {
			return false;
		}
		$isproject = false;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string) $zip->getNameIndex($i);
			if (preg_match('/^Metadata\/(project_settings\.config|Slic3r_PE\.config|Slic3r_PE_model\.config|PrusaSlicer.*\.config)$/i', $name)
				|| preg_match('/\.(gcode|bgcode)$/i', $name)) {
				$isproject = true;
				break;
			}
		}
		$zip->close();

		return $isproject;
	}

	/**
	 * Largest file that can be uploaded, in bytes: the module setting (in MB) when set, never more
	 * than PHP allows (upload_max_filesize and post_max_size).
	 *
	 * @return	int		Bytes (0 if unknown)
	 */
	public static function getMaxUploadBytes()
	{
		$phpmax = 0;
		foreach (array('upload_max_filesize', 'post_max_size') as $key) {
			$bytes = self::iniSizeToBytes((string) ini_get($key));
			if ($bytes > 0) {
				$phpmax = ($phpmax > 0 ? min($phpmax, $bytes) : $bytes);
			}
		}
		$settingmb = getDolGlobalInt('DESIGNTRACE_MAX_UPLOAD_MB');
		if ($settingmb > 0) {
			$setting = $settingmb * 1024 * 1024;
			return ($phpmax > 0 ? min($phpmax, $setting) : $setting);
		}
		return $phpmax;
	}

	/**
	 * Convert a php.ini size ("20M", "512K", "1G") into bytes
	 *
	 * @param	string	$value	php.ini value
	 * @return	int				Bytes
	 */
	public static function iniSizeToBytes($value)
	{
		$value = trim($value);
		if ($value === '' || !preg_match('/^(\d+(?:\.\d+)?)\s*([KMGT]?)/i', $value, $reg)) {
			return 0;
		}
		$number = (float) $reg[1];
		$powers = array('' => 0, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4);
		return (int) ($number * pow(1024, $powers[strtoupper($reg[2])]));
	}

	/**
	 * Format a size in bytes for display (B, KB, MB, GB; dol_print_size() stops at KB)
	 *
	 * @param	int		$bytes	Size in bytes
	 * @return	string			For example "12.4 MB"
	 */
	public static function formatSize($bytes)
	{
		$units = array('B', 'KB', 'MB', 'GB', 'TB');
		$value = (float) $bytes;
		$i = 0;
		while ($value >= 1024 && $i < count($units) - 1) {
			$value /= 1024;
			$i++;
		}
		return ($i == 0 ? (string) ((int) $value) : number_format($value, 1)).' '.$units[$i];
	}

	/**
	 * Return the label of a role, as a badge
	 *
	 * @param	int		$role	ROLE_* constant
	 * @return	string			HTML
	 */
	public static function getRoleBadge($role)
	{
		global $langs;

		$langs->load('designtrace@designtrace');
		$map = array(
			self::ROLE_SOURCE => array('FileRoleSource', 'status4'),
			self::ROLE_PRESENTATION => array('FileRolePresentation', 'status1'),
			self::ROLE_PRODUCTION => array('FileRoleProduction', 'status6'),
		);
		if (!isset($map[(int) $role])) {
			return '';
		}
		return dolGetStatus($langs->trans($map[(int) $role][0]), $langs->trans($map[(int) $role][0]), '', $map[(int) $role][1], 5);
	}
}
