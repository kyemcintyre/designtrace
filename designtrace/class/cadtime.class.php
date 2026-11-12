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
 * \file        designtrace/class/cadtime.class.php
 * \ingroup     designtrace
 * \brief       CAD time logged against designs, components and ECOs, as Dolibarr timesheet lines
 *
 * Time is never stored here: each entry is a normal Dolibarr time spent line (llx_element_time) on
 * a task of the object's project, so it shows in timesheets and is invoiced from the project like
 * any other time (pick the service, e.g. the hobby or commercial hourly rate, when generating the
 * invoice, or use one task per rate). llx_designtrace_time only says which design, component or
 * ECO a line was for. By default the time goes to a task "CAD: <design ref>" made on first use.
 */

require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';
dol_include_once('/designtrace/class/design.class.php');

/**
 * CAD time
 */
class DesignTraceCadTime
{
	/**
	 * Objects time can be logged against
	 *
	 * @return	array<string,array{table:string,class:string,file:string,card:string}>
	 */
	public static function getElements()
	{
		return array(
			'design' => array('table' => 'designtrace_design', 'class' => 'Design', 'file' => 'design', 'card' => 'design_card.php'),
			'component' => array('table' => 'designtrace_component', 'class' => 'Component', 'file' => 'component', 'card' => 'component_card.php'),
			'changeorder' => array('table' => 'designtrace_changeorder', 'class' => 'ChangeOrder', 'file' => 'changeorder', 'card' => 'changeorder_card.php'),
		);
	}

	/**
	 * Whether the user can log time (Dolibarr's timesheet right)
	 *
	 * @param	User	$user	User
	 * @return	bool
	 */
	public static function userCanLog($user)
	{
		return isModEnabled('project') && ($user->hasRight('projet', 'time') || $user->hasRight('projet', 'all', 'creer'));
	}

	/**
	 * Project time goes to: the object's own, else (component) its design's
	 *
	 * @param	DoliDB			$db		Database handler
	 * @param	CommonObject	$object	Design, Component or ChangeOrder
	 * @return	int						Project id, 0 if none
	 */
	public static function getProjectId($db, $object)
	{
		if (!empty($object->fk_project)) {
			return (int) $object->fk_project;
		}
		if ($object->element == 'component' && !empty($object->fk_design)) {
			$resql = $db->query("SELECT fk_project FROM ".$db->prefix()."designtrace_design WHERE rowid = ".((int) $object->fk_design));
			$obj = $resql ? $db->fetch_object($resql) : null;
			return $obj ? (int) $obj->fk_project : 0;
		}
		return 0;
	}

	/**
	 * Design an object's time rolls up to (0 for an ECO)
	 *
	 * @param	CommonObject	$object		Design, Component or ChangeOrder
	 * @return	int
	 */
	public static function getDesignId($object)
	{
		if ($object->element == 'design') {
			return (int) $object->id;
		}
		if ($object->element == 'component') {
			return (int) $object->fk_design;
		}
		return 0;
	}

	/**
	 * Label of the default task of an object's time
	 *
	 * @param	DoliDB			$db		Database handler
	 * @param	CommonObject	$object	Design, Component or ChangeOrder
	 * @return	string
	 */
	public static function getDefaultTaskLabel($db, $object)
	{
		global $langs;

		$langs->load('designtrace@designtrace');
		$designid = self::getDesignId($object);
		if ($designid > 0) {
			$resql = $db->query("SELECT ref, label FROM ".$db->prefix()."designtrace_design WHERE rowid = ".((int) $designid));
			$obj = $resql ? $db->fetch_object($resql) : null;
			if ($obj) {
				return dol_trunc($langs->transnoentities('CadTimeTaskLabel', trim($obj->ref.' '.$obj->label)), 250, 'right', 'UTF-8', 1);
			}
		}
		return dol_trunc($langs->transnoentities('CadTimeTaskLabel', trim($object->ref.' '.$object->label)), 250, 'right', 'UTF-8', 1);
	}

	/**
	 * Open tasks of a project
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$projectid	Project id
	 * @return	array<int,string>	Id => "ref - label"
	 */
	public static function fetchTasks($db, $projectid)
	{
		$tasks = array();
		$sql = "SELECT rowid, ref, label FROM ".$db->prefix()."projet_task WHERE fk_projet = ".((int) $projectid);
		$sql .= " AND fk_statut <> ".Task::STATUS_CLOSED." AND entity IN (".getEntity('project').") ORDER BY rang, ref";
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$tasks[(int) $obj->rowid] = $obj->ref.' - '.$obj->label;
		}
		return $tasks;
	}

	/**
	 * Default task of an object's time if it exists
	 *
	 * @param	DoliDB			$db			Database handler
	 * @param	CommonObject	$object		Design, Component or ChangeOrder
	 * @param	int				$projectid	Project id
	 * @return	int							Task id, 0 if not made yet
	 */
	public static function findDefaultTask($db, $object, $projectid)
	{
		$sql = "SELECT rowid FROM ".$db->prefix()."projet_task WHERE fk_projet = ".((int) $projectid);
		$sql .= " AND label = '".$db->escape(self::getDefaultTaskLabel($db, $object))."' AND fk_statut <> ".Task::STATUS_CLOSED;
		$sql .= " ORDER BY rowid".$db->plimit(1);
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		return $obj ? (int) $obj->rowid : 0;
	}

	/**
	 * Log time
	 *
	 * @param	DoliDB			$db			Database handler
	 * @param	User			$user		Who worked (and logs it)
	 * @param	CommonObject	$object		Design, Component or ChangeOrder
	 * @param	int				$taskid		Task of the object's project, 0 = the default task (made if needed)
	 * @param	int				$date		Day worked (timestamp)
	 * @param	int				$seconds	Duration
	 * @param	string			$note		What was done
	 * @param	string			$error		Error message if KO
	 * @return	int							Id of the Dolibarr time line, <0 if KO
	 */
	public static function logTime($db, $user, $object, $taskid, $date, $seconds, $note, &$error)
	{
		global $conf, $langs;

		$langs->loadLangs(array('designtrace@designtrace', 'projects'));
		$error = '';
		if (!isset(self::getElements()[$object->element]) || empty($object->id)) {
			$error = $langs->trans('ErrorRecordNotFound');
			return -1;
		}
		if ((int) $seconds <= 0) {
			$error = $langs->trans('ErrorCadTimeDuration');
			return -1;
		}
		if (empty($date)) {
			$error = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Date'));
			return -1;
		}
		$projectid = self::getProjectId($db, $object);
		if ($projectid <= 0) {
			$error = $langs->trans('ErrorCadTimeNoProject');
			return -1;
		}
		$project = new Project($db);
		if ($project->fetch($projectid) <= 0 || (int) $project->status !== Project::STATUS_VALIDATED) {
			$error = $langs->trans('ErrorCadTimeProjectNotOpen');
			return -1;
		}

		$db->begin();
		$task = new Task($db);
		if ((int) $taskid > 0) {
			if ($task->fetch((int) $taskid) <= 0 || (int) $task->fk_project !== $projectid) {
				$db->rollback();
				$error = $langs->trans('ErrorCadTimeBadTask');
				return -1;
			}
		} else {
			$taskid = self::findDefaultTask($db, $object, $projectid);
			if ($taskid > 0) {
				$task->fetch($taskid);
			} else {
				$taskid = self::createDefaultTask($db, $user, $object, $project, $error);
				if ($taskid <= 0) {
					$db->rollback();
					return -1;
				}
				$task->fetch($taskid);
			}
		}
		// The worker must be a contact of the task to see it on their timesheet
		if (!in_array((int) $user->id, array_map('intval', $task->getListContactId('internal')), true)) {
			$task->add_contact((int) $user->id, 'TASKCONTRIBUTOR', 'internal');
		}

		$task->timespent_date = (int) $date;
		$task->timespent_withhour = 0;
		$task->timespent_duration = (int) $seconds;
		$task->timespent_fk_user = (int) $user->id;
		$task->timespent_note = trim($note) !== '' ? trim($note) : $langs->transnoentities('CadTimeNoteDefault', $object->ref);
		$timeid = $task->addTimeSpent($user);
		if ($timeid <= 0) {
			$error = $task->error ?: implode(', ', $task->errors);
			$db->rollback();
			return -1;
		}
		$sql = "INSERT INTO ".$db->prefix()."designtrace_time (entity, fk_element_time, element, fk_element, fk_design, datec, fk_user_creat)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $timeid).", '".$db->escape($object->element)."', ".((int) $object->id);
		$sql .= ", ".(self::getDesignId($object) > 0 ? self::getDesignId($object) : "NULL").", '".$db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$db->query($sql)) {
			$error = $db->lasterror();
			$db->rollback();
			return -1;
		}
		$db->commit();
		return (int) $timeid;
	}

	/**
	 * Make the default task of an object's time in its project
	 *
	 * @param	DoliDB			$db			Database handler
	 * @param	User			$user		User
	 * @param	CommonObject	$object		Design, Component or ChangeOrder
	 * @param	Project			$project	Project
	 * @param	string			$error		Error message if KO
	 * @return	int							Task id, <0 if KO
	 */
	private static function createDefaultTask($db, $user, $object, $project, &$error)
	{
		global $conf, $langs;

		$task = new Task($db);
		$task->fk_project = (int) $project->id;
		$task->fk_task_parent = 0;
		$task->label = self::getDefaultTaskLabel($db, $object);
		$task->description = $langs->transnoentities('CadTimeTaskDescription');
		$task->note_public = '';
		$task->note_private = '';
		$task->date_c = dol_now();
		$task->date_start = dol_now();
		$model = getDolGlobalString('PROJECT_TASK_ADDON', 'mod_task_simple');
		$file = DOL_DOCUMENT_ROOT.'/core/modules/project/task/'.$model.'.php';
		if (is_readable($file)) {
			require_once $file;
			$numbering = new $model();
			$task->ref = $numbering->getNextValue($project->thirdparty ?? null, $task);
		}
		if (empty($task->ref)) {
			$task->ref = 'CAD'.dol_print_date(dol_now(), '%y%m%d%H%M%S');
		}
		$id = $task->create($user);
		if ($id <= 0) {
			$error = $task->error ?: implode(', ', $task->errors);
			return -1;
		}
		return (int) $id;
	}

	/**
	 * Time lines of an object (for a design: also of its components), newest first
	 *
	 * @param	DoliDB			$db		Database handler
	 * @param	CommonObject	$object	Design, Component or ChangeOrder
	 * @return	stdClass[]		rowid, time_id, element, fk_element, element_ref, date, duration, fk_user, note,
	 *							task_id, task_ref, task_label, invoice_id
	 */
	public static function fetchLines($db, $object)
	{
		$where = "(dt.element = '".$db->escape($object->element)."' AND dt.fk_element = ".((int) $object->id).")";
		if ($object->element == 'design') {
			$where = "dt.fk_design = ".((int) $object->id);
		}
		$sql = "SELECT dt.rowid, dt.fk_element_time as time_id, dt.element, dt.fk_element,";
		$sql .= " COALESCE(d.ref, c.ref, e.ref) as element_ref,";
		$sql .= " t.element_date as date, t.element_duration as duration, t.fk_user, t.note, t.invoice_id,";
		$sql .= " pt.rowid as task_id, pt.ref as task_ref, pt.label as task_label";
		$sql .= " FROM ".$db->prefix()."designtrace_time as dt";
		$sql .= " INNER JOIN ".$db->prefix()."element_time as t ON t.rowid = dt.fk_element_time AND t.elementtype = 'task'";
		$sql .= " INNER JOIN ".$db->prefix()."projet_task as pt ON pt.rowid = t.fk_element";
		$sql .= " LEFT JOIN ".$db->prefix()."designtrace_design as d ON dt.element = 'design' AND d.rowid = dt.fk_element";
		$sql .= " LEFT JOIN ".$db->prefix()."designtrace_component as c ON dt.element = 'component' AND c.rowid = dt.fk_element";
		$sql .= " LEFT JOIN ".$db->prefix()."designtrace_changeorder as e ON dt.element = 'changeorder' AND e.rowid = dt.fk_element";
		$sql .= " WHERE ".$where." AND dt.entity IN (".getEntity('project').")";
		$sql .= " ORDER BY t.element_date DESC, dt.rowid DESC";
		$rows = array();
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$obj->date = $db->jdate($obj->date);
			$rows[] = $obj;
		}
		return $rows;
	}

	/**
	 * Delete a time line logged here (not once invoiced)
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	User	$user	User
	 * @param	int		$rowid	Row of llx_designtrace_time
	 * @param	string	$error	Error message if KO
	 * @return	int				>0 if OK, <0 if KO
	 */
	public static function deleteLine($db, $user, $rowid, &$error)
	{
		global $langs;

		$langs->load('designtrace@designtrace');
		$sql = "SELECT dt.fk_element_time, t.fk_element as task_id, t.fk_user, t.invoice_id FROM ".$db->prefix()."designtrace_time as dt";
		$sql .= " INNER JOIN ".$db->prefix()."element_time as t ON t.rowid = dt.fk_element_time";
		$sql .= " WHERE dt.rowid = ".((int) $rowid)." AND dt.entity IN (".getEntity('project').")";
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		if (!$obj) {
			$error = $langs->trans('ErrorRecordNotFound');
			return -1;
		}
		if (!empty($obj->invoice_id)) {
			$error = $langs->trans('ErrorCadTimeInvoiced');
			return -1;
		}
		if ((int) $obj->fk_user !== (int) $user->id && !$user->hasRight('projet', 'all', 'supprimer')) {
			$error = $langs->trans('NotEnoughPermissions');
			return -1;
		}
		$db->begin();
		$task = new Task($db);
		$task->fetch((int) $obj->task_id);
		$task->timespent_id = (int) $obj->fk_element_time;
		if ($task->delTimeSpent($user) <= 0 || !$db->query("DELETE FROM ".$db->prefix()."designtrace_time WHERE rowid = ".((int) $rowid))) {
			$error = $task->error ?: $db->lasterror();
			$db->rollback();
			return -1;
		}
		$db->commit();
		return 1;
	}
}
