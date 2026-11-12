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
 *	\file       designtrace/class/componentlink.class.php
 *	\ingroup    designtrace
 *	\brief      Link from a parent revision to the exact child revision it uses, depicts or references
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
dol_include_once('/designtrace/class/componentrevision.class.php');
dol_include_once('/designtrace/class/component.class.php');


/**
 *	Link between two component revisions, and the "Needs update" flag it carries.
 *
 *	A link pins an exact child revision. Links belong to the parent revision: they are added
 *	and removed while it is a draft or in review, and locked once it is released.
 *
 *	Flags (see recomputeFlags()) are only meaningful on the links of each component's
 *	current revision, its latest released one:
 *	- Needs update: the link pins an older revision than the child's current one;
 *	- Indirectly affected: the link pins the child's current revision, but that child itself
 *	  needs updating (directly or indirectly), so the flag propagates upward;
 *	- Acknowledged: someone confirmed "no change needed" for the child's current revision.
 *	A flag clears when the parent releases a new revision that links to the new child
 *	revision (the old links are no longer current), or when it is acknowledged.
 */
class ComponentLink extends CommonObject
{
	/**
	 * @var string ID of module.
	 */
	public $module = 'designtrace';

	/**
	 * @var string ID to identify managed object.
	 */
	public $element = 'componentlink';

	/**
	 * @var string Name of table without prefix where object is stored.
	 */
	public $table_element = 'designtrace_componentlink';

	/**
	 * @var string String with name of icon
	 */
	public $picto = 'fa-link';

	/**
	 * @var int<0,2>	Entity comes from the revisions
	 */
	public $ismultientitymanaged = 0;

	/**
	 * @var int<0,1>
	 */
	public $isextrafieldmanaged = 0;

	const TYPE_USES = 1;		// Assembly contains a part or sub-assembly
	const TYPE_DEPICTS = 2;		// Drawing shows a part or assembly
	const TYPE_REFERENCES = 3;	// Designed against another component (e.g. a mating part)

	const FLAG_NONE = 0;
	const FLAG_NEEDS_UPDATE = 1;
	const FLAG_INDIRECT = 2;
	const FLAG_ACKNOWLEDGED = 3;

	/**
	 * @var array<string,array{type:string,label:string,enabled:int<0,2>|string,position:int,notnull?:int,visible:int<-6,6>|string,noteditable?:int<0,1>,default?:string,index?:int,foreignkey?:string,searchall?:int<0,1>,isameasure?:int<0,1>,css?:string,csslist?:string,help?:string,showoncombobox?:int<0,4>,disabled?:int<0,1>,arrayofkeyval?:array<int|string,string>,autofocusoncreate?:int<0,1>,comment?:string,copytoclipboard?:int<1,2>,validate?:int<0,1>,showonheader?:int<0,1>}>  Array with all fields and their property.
	 */
	public $fields = array(
		"rowid" => array("type" => "integer", "label" => "TechnicalID", "enabled" => "1", 'position' => 1, 'notnull' => 1, "visible" => "0", "noteditable" => "1", "index" => "1"),
		"fk_parent" => array("type" => "integer:ComponentRevision:designtrace/class/componentrevision.class.php", "label" => "ParentRevision", "enabled" => "1", 'position' => 10, 'notnull' => 1, "visible" => "1", "index" => "1", "foreignkey" => "designtrace_componentrevision.rowid"),
		"fk_child" => array("type" => "integer:ComponentRevision:designtrace/class/componentrevision.class.php", "label" => "ChildRevision", "enabled" => "1", 'position' => 20, 'notnull' => 1, "visible" => "1", "index" => "1", "foreignkey" => "designtrace_componentrevision.rowid"),
		"link_type" => array("type" => "integer", "label" => "LinkType", "enabled" => "1", 'position' => 30, 'notnull' => 1, "visible" => "1", "arrayofkeyval" => array("1" => "LinkTypeUses", "2" => "LinkTypeDepicts", "3" => "LinkTypeReferences")),
		"qty" => array("type" => "double(24,8)", "label" => "Qty", "enabled" => "1", 'position' => 40, 'notnull' => 0, "visible" => "1"),
		"note" => array("type" => "varchar(255)", "label" => "Note", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "1"),
		"flag_status" => array("type" => "integer", "label" => "FlagStatus", "enabled" => "1", 'position' => 60, 'notnull' => 1, "visible" => "1", "default" => "0", "index" => "1"),
		"flag_note" => array("type" => "varchar(255)", "label" => "FlagNote", "enabled" => "1", 'position' => 61, 'notnull' => 0, "visible" => "1"),
		"fk_ack_revision" => array("type" => "integer", "label" => "AcknowledgedRevision", "enabled" => "1", 'position' => 70, 'notnull' => 0, "visible" => "1"),
		"ack_note" => array("type" => "text", "label" => "AcknowledgeNote", "enabled" => "1", 'position' => 71, 'notnull' => 0, "visible" => "1"),
		"fk_user_ack" => array("type" => "integer:User:user/class/user.class.php", "label" => "AcknowledgedBy", "enabled" => "1", 'position' => 72, 'notnull' => 0, "visible" => "1"),
		"date_ack" => array("type" => "datetime", "label" => "DateAcknowledged", "enabled" => "1", 'position' => 73, 'notnull' => 0, "visible" => "1"),
		"date_creation" => array("type" => "datetime", "label" => "DateCreation", "enabled" => "1", 'position' => 500, 'notnull' => 1, "visible" => "-2"),
		"tms" => array("type" => "timestamp", "label" => "DateModification", "enabled" => "1", 'position' => 501, 'notnull' => 0, "visible" => "-2"),
		"fk_user_creat" => array("type" => "integer:User:user/class/user.class.php", "label" => "UserAuthor", "enabled" => "1", 'position' => 510, 'notnull' => 1, "visible" => "-2", "foreignkey" => "user.rowid"),
		"fk_user_modif" => array("type" => "integer:User:user/class/user.class.php", "label" => "UserModif", "enabled" => "1", 'position' => 511, 'notnull' => -1, "visible" => "-2"),
		"import_key" => array("type" => "varchar(14)", "label" => "ImportId", "enabled" => "1", 'position' => 1000, 'notnull' => -1, "visible" => "-2"),
	);
	public $rowid;
	public $fk_parent;
	public $fk_child;
	public $link_type;
	public $qty;
	public $note;
	public $flag_status;
	public $flag_note;
	public $fk_ack_revision;
	public $ack_note;
	public $fk_user_ack;
	public $date_ack;
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
	 * Create a link from a draft or in-review parent revision to a released child revision.
	 *
	 * @param	User		$user			User that creates
	 * @param	int<0,1>	$notrigger		0=launch triggers, 1=disable triggers
	 * @param	bool		$norecompute	Do not recompute flags (caller does it once for many links)
	 * @return	int<-1,max>					Id of the link if OK, <0 if KO
	 */
	public function create(User $user, $notrigger = 1, $norecompute = false)
	{
		if ($this->checkLink() < 0) {
			return -1;
		}
		$this->flag_status = self::FLAG_NONE;
		$this->flag_note = null;
		$this->fk_ack_revision = null;
		$this->ack_note = null;
		$this->fk_user_ack = null;
		$this->date_ack = null;

		$result = $this->createCommon($user, $notrigger);
		if ($result > 0 && !$norecompute) {
			self::recomputeFlags($this->db);
		}
		return $result;
	}

	/**
	 * Load a link
	 *
	 * @param	int		$id		Id
	 * @return	int<-1,1>		<0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id)
	{
		return $this->fetchCommon($id);
	}

	/**
	 * Change the quantity or note of a link of an unlocked parent revision
	 *
	 * @param	User		$user		User that modifies
	 * @param	int<0,1>	$notrigger	0=launch triggers, 1=disable triggers
	 * @return	int<-1,1>				<0 if KO, >0 if OK
	 */
	public function update(User $user, $notrigger = 1)
	{
		if ($this->checkLink() < 0) {
			return -1;
		}
		$result = $this->updateCommon($user, $notrigger);
		if ($result > 0) {
			self::recomputeFlags($this->db);
		}
		return $result;
	}

	/**
	 * Delete a link of an unlocked parent revision
	 *
	 * @param	User		$user		User that deletes
	 * @param	int<0,1>	$notrigger	0=launch triggers, 1=disable triggers
	 * @return	int<-1,1>				<0 if KO, >0 if OK
	 */
	public function delete(User $user, $notrigger = 1)
	{
		global $langs;

		$langs->load('designtrace@designtrace');
		$parent = new ComponentRevision($this->db);
		if ($parent->fetch((int) $this->fk_parent) > 0 && $parent->isLocked()) {
			return $this->setErrorAndFail($langs->trans('ErrorRevisionIsReadOnly', $parent->ref));
		}
		$result = $this->deleteCommon($user, $notrigger);
		if ($result > 0) {
			self::recomputeFlags($this->db);
		}
		return $result;
	}

	/**
	 * Check the link before saving it:
	 * - the parent revision is a draft or in review, the child revision is released;
	 * - parent and child are different components and the type fits them (Uses: an assembly
	 *   uses a part or an assembly; Depicts: a drawing shows a part or an assembly;
	 *   References: anything else);
	 * - the parent revision has no other link to the same child component;
	 * - the link does not create a cycle between components.
	 * Sets qty: required and positive for Uses (default 1), empty otherwise.
	 *
	 * @return	int<-1,1>	>0 if OK, <0 if KO
	 */
	public function checkLink()
	{
		global $langs;

		$langs->loadLangs(array('errors', 'designtrace@designtrace'));

		$parent = new ComponentRevision($this->db);
		$child = new ComponentRevision($this->db);
		if (empty($this->fk_parent) || $parent->fetch((int) $this->fk_parent) <= 0) {
			return $this->setErrorAndFail($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('ParentRevision')));
		}
		if (empty($this->fk_child) || $child->fetch((int) $this->fk_child) <= 0) {
			return $this->setErrorAndFail($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('ChildRevision')));
		}
		if ($parent->isLocked()) {
			return $this->setErrorAndFail($langs->trans('ErrorRevisionIsReadOnly', $parent->ref));
		}
		if ((int) $child->status !== ComponentRevision::STATUS_RELEASED) {
			return $this->setErrorAndFail($langs->trans('ErrorLinkChildNotReleased', $child->ref));
		}

		$parentcomp = new Component($this->db);
		$childcomp = new Component($this->db);
		$parentcomp->fetch((int) $parent->fk_component);
		$childcomp->fetch((int) $child->fk_component);
		if ((int) $parentcomp->id === (int) $childcomp->id) {
			return $this->setErrorAndFail($langs->trans('ErrorLinkToItself'));
		}

		$type = (int) $this->link_type;
		if ($type == self::TYPE_USES) {
			if ((int) $parentcomp->type !== Component::TYPE_ASSEMBLY || (int) $childcomp->type === Component::TYPE_DRAWING) {
				return $this->setErrorAndFail($langs->trans('ErrorLinkUsesTypes'));
			}
			if ($this->qty === null || $this->qty === '') {
				$this->qty = 1;
			}
			if ((float) $this->qty <= 0) {
				return $this->setErrorAndFail($langs->trans('ErrorLinkQtyPositive'));
			}
		} elseif ($type == self::TYPE_DEPICTS) {
			if ((int) $parentcomp->type !== Component::TYPE_DRAWING || (int) $childcomp->type === Component::TYPE_DRAWING) {
				return $this->setErrorAndFail($langs->trans('ErrorLinkDepictsTypes'));
			}
			$this->qty = null;
		} elseif ($type == self::TYPE_REFERENCES) {
			$this->qty = null;
		} else {
			return $this->setErrorAndFail($langs->trans('ErrorBadValueForParameter', (string) $this->link_type, $langs->transnoentitiesnoconv('LinkType')));
		}

		// One link per child component in a parent revision
		$sql = "SELECT l.rowid FROM ".$this->db->prefix().$this->table_element." as l";
		$sql .= " INNER JOIN ".$this->db->prefix()."designtrace_componentrevision as c ON c.rowid = l.fk_child";
		$sql .= " WHERE l.fk_parent = ".((int) $parent->id)." AND c.fk_component = ".((int) $childcomp->id);
		if (!empty($this->id)) {
			$sql .= " AND l.rowid <> ".((int) $this->id);
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			return $this->setErrorAndFail($this->db->lasterror());
		}
		if ($this->db->num_rows($resql) > 0) {
			return $this->setErrorAndFail($langs->trans('ErrorLinkAlreadyExists', $parent->ref, $childcomp->ref));
		}

		if (self::componentReaches($this->db, (int) $childcomp->id, (int) $parentcomp->id)) {
			return $this->setErrorAndFail($langs->trans('ErrorLinkCycle', $childcomp->ref, $parentcomp->ref));
		}

		$this->note = ($this->note === null ? null : dol_trunc(trim((string) $this->note), 255, 'right', 'UTF-8', 1));

		return 1;
	}

	/**
	 * Tell if a component reaches another one through links (of any of its revisions), to
	 * prevent cycles
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$fromcomp	Start component id
	 * @param	int		$tocomp		Component id we look for
	 * @return	bool
	 */
	public static function componentReaches($db, $fromcomp, $tocomp)
	{
		$edges = array();
		$sql = "SELECT DISTINCT p.fk_component as parentcomp, c.fk_component as childcomp";
		$sql .= " FROM ".$db->prefix()."designtrace_componentlink as l";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as p ON p.rowid = l.fk_parent";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as c ON c.rowid = l.fk_child";
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$edges[(int) $obj->parentcomp][] = (int) $obj->childcomp;
			}
		}
		$seen = array();
		$stack = array($fromcomp);
		while ($stack) {
			$comp = array_pop($stack);
			if ($comp === $tocomp) {
				return true;
			}
			if (isset($seen[$comp])) {
				continue;
			}
			$seen[$comp] = true;
			foreach ($edges[$comp] ?? array() as $next) {
				$stack[] = $next;
			}
		}
		return false;
	}

	/**
	 * Mark a "Needs update" link as fine with the child's current revision. The link keeps
	 * pinning the revision it was made with; a newer child revision flags it again.
	 *
	 * @param	User	$user	User acknowledging
	 * @param	string	$note	Why no change is needed (required)
	 * @return	int<-1,1>		>0 if OK, <0 if KO
	 */
	public function acknowledge(User $user, $note)
	{
		global $langs;

		$langs->loadLangs(array('errors', 'designtrace@designtrace'));
		if (trim((string) $note) === '') {
			return $this->setErrorAndFail($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('AcknowledgeNote')));
		}
		if ((int) $this->flag_status !== self::FLAG_NEEDS_UPDATE) {
			return $this->setErrorAndFail($langs->trans('ErrorLinkNotFlagged'));
		}
		$child = new ComponentRevision($this->db);
		$child->fetch((int) $this->fk_child);
		$current = self::getCurrentRevisionIds($this->db, array((int) $child->fk_component));
		if (empty($current[(int) $child->fk_component])) {
			return $this->setErrorAndFail($langs->trans('ErrorLinkNotFlagged'));
		}

		$sql = "UPDATE ".$this->db->prefix().$this->table_element." SET";
		$sql .= " fk_ack_revision = ".((int) $current[(int) $child->fk_component]).",";
		$sql .= " ack_note = '".$this->db->escape(trim((string) $note))."',";
		$sql .= " fk_user_ack = ".((int) $user->id).",";
		$sql .= " date_ack = '".$this->db->idate(dol_now())."'";
		$sql .= " WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			return $this->setErrorAndFail($this->db->lasterror());
		}
		self::recomputeFlags($this->db);
		$this->fetch($this->id);

		return 1;
	}

	/**
	 * Current revision of components: their latest released revision (obsolete ones do not count)
	 *
	 * @param	DoliDB		$db			Database handler
	 * @param	int[]|null	$compids	Components, null for all
	 * @return	array<int,int>			Component id => revision id
	 */
	public static function getCurrentRevisionIds($db, $compids = null)
	{
		$current = array();
		$sql = "SELECT fk_component, MAX(rowid) as revid FROM ".$db->prefix()."designtrace_componentrevision";
		$sql .= " WHERE status = ".ComponentRevision::STATUS_RELEASED;
		if (is_array($compids)) {
			if (empty($compids)) {
				return $current;
			}
			$sql .= " AND fk_component IN (".$db->sanitize(implode(',', array_map('intval', $compids))).")";
		}
		$sql .= " GROUP BY fk_component";
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$current[(int) $obj->fk_component] = (int) $obj->revid;
			}
		}
		return $current;
	}

	/**
	 * Recompute every flag. Only the links of each component's current revision are active;
	 * the others are cleared. Cheap enough to run after every change for a workshop-sized
	 * database, and it keeps the flags consistent whatever happened (release, obsolete, undo,
	 * link added or removed, acknowledgement).
	 *
	 * @param	DoliDB	$db		Database handler
	 * @return	int				Number of links whose flag changed, -1 if KO
	 */
	public static function recomputeFlags($db)
	{
		global $langs;

		$langs->load('designtrace@designtrace');

		$current = self::getCurrentRevisionIds($db);

		$sql = "SELECT l.rowid, l.fk_parent, l.fk_child, l.flag_status, l.flag_note, l.fk_ack_revision,";
		$sql .= " p.fk_component as parentcomp, c.fk_component as childcomp, c.rev as childrev, cc.ref as childref";
		$sql .= " FROM ".$db->prefix()."designtrace_componentlink as l";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as p ON p.rowid = l.fk_parent";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as c ON c.rowid = l.fk_child";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_component as cc ON cc.rowid = c.fk_component";
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$db->lasterror(), LOG_ERR);
			return -1;
		}
		$links = array();
		while ($obj = $db->fetch_object($resql)) {
			$links[(int) $obj->rowid] = $obj;
		}
		$db->free($resql);

		// Revision letters of the current revisions, for the flag notes
		$currentrev = array();
		if ($current) {
			$resql = $db->query("SELECT rowid, rev FROM ".$db->prefix()."designtrace_componentrevision WHERE rowid IN (".$db->sanitize(implode(',', $current)).")");
			while ($resql && ($obj = $db->fetch_object($resql))) {
				$currentrev[(int) $obj->rowid] = $obj->rev;
			}
		}

		// Direct flags, on active links only
		$new = array();
		$flaggedcomps = array();
		$childlinks = array();	// child component => active links pinning its current revision
		foreach ($links as $id => $link) {
			$new[$id] = array(self::FLAG_NONE, null);
			$parentcomp = (int) $link->parentcomp;
			$childcomp = (int) $link->childcomp;
			if (!isset($current[$parentcomp]) || $current[$parentcomp] !== (int) $link->fk_parent) {
				continue;	// Not the parent's current revision
			}
			if (!isset($current[$childcomp])) {
				continue;	// Child has no released revision any more
			}
			$childcurrent = $current[$childcomp];
			if ((int) $link->fk_child === $childcurrent) {
				$childlinks[$childcomp][] = $id;
				continue;
			}
			$note = $link->childref.' '.$link->childrev.' → '.$currentrev[$childcurrent];
			if ((int) $link->fk_ack_revision === $childcurrent) {
				$new[$id] = array(self::FLAG_ACKNOWLEDGED, $note);
			} else {
				$new[$id] = array(self::FLAG_NEEDS_UPDATE, $note);
				$flaggedcomps[$parentcomp] = $link->childref;
			}
		}

		// Propagate upward: links pinning the current revision of a flagged component
		$queue = array_keys($flaggedcomps);
		$visited = array();
		while ($queue) {
			$comp = array_shift($queue);
			if (isset($visited[$comp])) {
				continue;
			}
			$visited[$comp] = true;
			foreach ($childlinks[$comp] ?? array() as $id) {
				if ($new[$id][0] !== self::FLAG_NONE) {
					continue;
				}
				$compref = $links[$id]->childref;
				$new[$id] = array(self::FLAG_INDIRECT, $langs->transnoentities('FlagViaComponent', $compref));
				$parentcomp = (int) $links[$id]->parentcomp;
				if (!isset($visited[$parentcomp])) {
					$queue[] = $parentcomp;
				}
			}
		}

		$changed = 0;
		foreach ($new as $id => $flag) {
			$old = $links[$id];
			if ((int) $old->flag_status === $flag[0] && (string) $old->flag_note === (string) $flag[1]) {
				continue;
			}
			$sql = "UPDATE ".$db->prefix()."designtrace_componentlink SET flag_status = ".((int) $flag[0]).", flag_note = ";
			$sql .= ($flag[1] === null ? "NULL" : "'".$db->escape(dol_trunc($flag[1], 255, 'right', 'UTF-8', 1))."'");
			$sql .= " WHERE rowid = ".((int) $id);
			if (!$db->query($sql)) {
				dol_syslog(__METHOD__.' '.$db->lasterror(), LOG_ERR);
				return -1;
			}
			$changed++;
		}

		return $changed;
	}

	/**
	 * Links of a parent revision, with what is needed to show them
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$fk_parent	Parent revision id
	 * @return	stdClass[]			Rows: link fields plus child_ref, child_rev, child_status, comp_id, comp_ref, comp_label, comp_type, design_id, design_ref, current_rev_id, current_rev
	 */
	public static function fetchForParent($db, $fk_parent)
	{
		return self::fetchRows($db, "l.fk_parent = ".((int) $fk_parent), 'child');
	}

	/**
	 * Links whose child is a revision of a component ("where used"), with the parent side
	 *
	 * @param	DoliDB	$db				Database handler
	 * @param	int		$fk_component	Child component id
	 * @return	stdClass[]				Rows: link fields plus the parent's revision, component and design, and whether it is the parent's current revision (is_current)
	 */
	public static function fetchWhereUsed($db, $fk_component)
	{
		return self::fetchRows($db, "c.fk_component = ".((int) $fk_component), 'parent');
	}

	/**
	 * Active flagged links (Needs update or Indirectly affected), for the global list
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	string	$moresql	More SQL filter (already escaped)
	 * @return	stdClass[]			Rows as fetchWhereUsed() plus the child side
	 */
	public static function fetchFlagged($db, $moresql = '')
	{
		return self::fetchRows($db, "l.flag_status IN (".self::FLAG_NEEDS_UPDATE.", ".self::FLAG_INDIRECT.")".($moresql ? " AND ".$moresql : ''), 'parent');
	}

	/**
	 * Load links with both sides
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	string	$where	SQL filter
	 * @param	string	$order	'child' to sort by child ref, 'parent' by parent ref
	 * @return	stdClass[]		Rows
	 */
	private static function fetchRows($db, $where, $order)
	{
		$sql = "SELECT l.rowid, l.fk_parent, l.fk_child, l.link_type, l.qty, l.note, l.flag_status, l.flag_note,";
		$sql .= " l.fk_ack_revision, l.ack_note, l.fk_user_ack, l.date_ack,";
		$sql .= " c.ref as child_ref, c.rev as child_rev, c.status as child_status,";
		$sql .= " cc.rowid as comp_id, cc.ref as comp_ref, cc.label as comp_label, cc.type as comp_type, cc.fk_design as design_id, cd.ref as design_ref,";
		$sql .= " p.ref as parent_ref, p.rev as parent_rev, p.status as parent_status,";
		$sql .= " pc.rowid as parent_comp_id, pc.ref as parent_comp_ref, pc.label as parent_comp_label, pc.type as parent_comp_type, pc.fk_design as parent_design_id, pd.ref as parent_design_ref";
		$sql .= " FROM ".$db->prefix()."designtrace_componentlink as l";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as c ON c.rowid = l.fk_child";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_component as cc ON cc.rowid = c.fk_component";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_design as cd ON cd.rowid = cc.fk_design";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as p ON p.rowid = l.fk_parent";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_component as pc ON pc.rowid = p.fk_component";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_design as pd ON pd.rowid = pc.fk_design";
		$sql .= " WHERE ".$where;
		$sql .= " AND cc.entity IN (".getEntity('component').")";
		$sql .= ($order == 'child' ? " ORDER BY l.link_type, cc.ref" : " ORDER BY pc.ref, p.rowid DESC, cc.ref");

		$rows = array();
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$db->lasterror(), LOG_ERR);
			return $rows;
		}
		$compids = array();
		while ($obj = $db->fetch_object($resql)) {
			$rows[] = $obj;
			$compids[(int) $obj->comp_id] = (int) $obj->comp_id;
			$compids[(int) $obj->parent_comp_id] = (int) $obj->parent_comp_id;
		}
		$db->free($resql);

		$current = self::getCurrentRevisionIds($db, array_values($compids));
		$revs = array();
		if ($current) {
			$resql = $db->query("SELECT rowid, rev FROM ".$db->prefix()."designtrace_componentrevision WHERE rowid IN (".$db->sanitize(implode(',', $current)).")");
			while ($resql && ($obj = $db->fetch_object($resql))) {
				$revs[(int) $obj->rowid] = $obj->rev;
			}
		}
		foreach ($rows as $row) {
			$row->current_rev_id = $current[(int) $row->comp_id] ?? 0;
			$row->current_rev = ($row->current_rev_id ? $revs[$row->current_rev_id] : '');
			$row->is_current = (isset($current[(int) $row->parent_comp_id]) && $current[(int) $row->parent_comp_id] === (int) $row->fk_parent);
		}

		return $rows;
	}

	/**
	 * Flag state of components, from the active links of their current revision
	 *
	 * @param	DoliDB		$db			Database handler
	 * @param	int[]		$compids	Components
	 * @return	array<int,int>			Component id => FLAG_NEEDS_UPDATE or FLAG_INDIRECT (components without flag are absent)
	 */
	public static function getComponentFlags($db, $compids)
	{
		$flags = array();
		$compids = array_filter(array_map('intval', (array) $compids));
		if (empty($compids)) {
			return $flags;
		}
		$sql = "SELECT p.fk_component, MIN(l.flag_status) as flag";
		$sql .= " FROM ".$db->prefix()."designtrace_componentlink as l";
		$sql .= " INNER JOIN ".$db->prefix()."designtrace_componentrevision as p ON p.rowid = l.fk_parent";
		$sql .= " WHERE l.flag_status IN (".self::FLAG_NEEDS_UPDATE.", ".self::FLAG_INDIRECT.")";
		$sql .= " AND p.fk_component IN (".$db->sanitize(implode(',', $compids)).")";
		$sql .= " GROUP BY p.fk_component";
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$flags[(int) $obj->fk_component] = (int) $obj->flag;
		}
		return $flags;
	}

	/**
	 * Badge for a flag
	 *
	 * @param	int		$flag	FLAG_* constant
	 * @param	string	$title	Tooltip
	 * @return	string			HTML ('' for FLAG_NONE)
	 */
	public static function getFlagBadge($flag, $title = '')
	{
		global $langs;

		$langs->load('designtrace@designtrace');
		$map = array(
			self::FLAG_NEEDS_UPDATE => array('FlagNeedsUpdate', 'status8'),
			self::FLAG_INDIRECT => array('FlagIndirect', 'status1'),
			self::FLAG_ACKNOWLEDGED => array('FlagAcknowledged', 'status6'),
		);
		if (!isset($map[(int) $flag])) {
			return '';
		}
		$label = $langs->trans($map[(int) $flag][0]);
		return dolGetStatus($label, $label, '', $map[(int) $flag][1], 5, '', array('badgeParams' => array('attr' => array('title' => $title !== '' ? $title : $label))));
	}

	/**
	 * Label of a link type
	 *
	 * @param	int		$type	TYPE_* constant
	 * @return	string
	 */
	public static function getTypeLabel($type)
	{
		global $langs;

		$langs->load('designtrace@designtrace');
		$labels = array(self::TYPE_USES => 'LinkTypeUses', self::TYPE_DEPICTS => 'LinkTypeDepicts', self::TYPE_REFERENCES => 'LinkTypeReferences');
		return isset($labels[(int) $type]) ? $langs->trans($labels[(int) $type]) : '';
	}

	/**
	 * Set the error and return -1
	 *
	 * @param	string		$message	Translated error message
	 * @return	int<-1,-1>				-1
	 */
	private function setErrorAndFail($message)
	{
		$this->error = $message;
		$this->errors[] = $message;
		return -1;
	}
}
