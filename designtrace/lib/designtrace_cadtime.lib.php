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
 * \file        designtrace/lib/designtrace_cadtime.lib.php
 * \ingroup     designtrace
 * \brief       CAD time block of the design, component and ECO cards
 */

dol_include_once('/designtrace/class/cadtime.class.php');


/**
 * Actions of the CAD time block (addcadtime, deletecadtime)
 *
 * @param	CommonObject	$object		Design, Component or ChangeOrder
 * @param	string			$action		Action
 * @return	void
 */
function designtraceDoCadTimeActions($object, $action)
{
	global $db, $langs, $user;

	if (!in_array($action, array('addcadtime', 'deletecadtime'), true) || empty($object->id)) {
		return;
	}
	$langs->load('designtrace@designtrace');
	if (!DesignTraceCadTime::userCanLog($user)) {
		setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
		return;
	}
	$error = '';
	if ($action == 'addcadtime') {
		$date = dol_mktime(12, 0, 0, GETPOSTINT('cadtimedatemonth'), GETPOSTINT('cadtimedateday'), GETPOSTINT('cadtimedateyear'));
		$seconds = GETPOSTINT('cadtimehour') * 3600 + GETPOSTINT('cadtimemin') * 60;
		$res = DesignTraceCadTime::logTime($db, $user, $object, GETPOSTINT('cadtime_task'), $date, $seconds, GETPOST('cadtime_note', 'alphanohtml'), $error);
		if ($res <= 0) {
			setEventMessages($error, null, 'errors');
			return;
		}
		setEventMessages($langs->trans('CadTimeLogged'), null, 'mesgs');
	} else {
		if (DesignTraceCadTime::deleteLine($db, $user, GETPOSTINT('lineid'), $error) <= 0) {
			setEventMessages($error, null, 'errors');
			return;
		}
		setEventMessages($langs->trans('CadTimeDeleted'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'#cadtime');
	exit;
}

/**
 * CAD time block: lines (for a design, also its components'), total and the form to log time
 *
 * @param	CommonObject	$object		Design, Component or ChangeOrder
 * @return	void
 */
function designtracePrintCadTime($object)
{
	global $db, $langs, $user, $form;

	if (!isModEnabled('project') || !$user->hasRight('projet', 'lire')) {
		return;
	}
	$langs->loadLangs(array('designtrace@designtrace', 'projects'));
	$lines = DesignTraceCadTime::fetchLines($db, $object);
	$total = 0;
	foreach ($lines as $line) {
		$total += (int) $line->duration;
	}
	$projectid = DesignTraceCadTime::getProjectId($db, $object);

	print '<a id="cadtime"></a>';
	print load_fiche_titre($form->textwithpicto($langs->trans('CadTime'), $langs->trans('CadTimeHelp')), '<span class="badge">'.convertSecondToTime($total, 'allhourmin').'</span>', 'fa-clock');

	// Form
	if (DesignTraceCadTime::userCanLog($user)) {
		if ($projectid <= 0) {
			print '<div class="opacitymedium marginbottomonly">'.$langs->trans('CadTimeNeedsProject').'</div>';
		} else {
			$tasks = DesignTraceCadTime::fetchTasks($db, $projectid);
			$default = DesignTraceCadTime::findDefaultTask($db, $object, $projectid);
			$options = $tasks;
			if ($default <= 0) {
				$options = array(0 => $langs->trans('CadTimeNewTask', DesignTraceCadTime::getDefaultTaskLabel($db, $object))) + $tasks;
			}
			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'#cadtime" class="marginbottomonly">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="addcadtime">';
			print '<div class="inline-block valignmiddle marginrightonly">'.$form->selectDate(dol_now(), 'cadtimedate', 0, 0, 0, '', 1, 0).'</div>';
			print '<div class="inline-block valignmiddle marginrightonly nowraponall">'.$form->select_duration('cadtime', 0, 0, 'text', 0, 1).'</div>';
			print '<div class="inline-block valignmiddle marginrightonly">'.$form->selectarray('cadtime_task', $options, ($default > 0 ? $default : 0), 0, 0, 0, '', 0, 0, 0, '', 'minwidth200 maxwidth300').'</div>';
			print '<div class="inline-block valignmiddle marginrightonly"><input type="text" name="cadtime_note" class="minwidth200" maxlength="255" placeholder="'.dol_escape_htmltag($langs->trans('CadTimeNotePlaceholder')).'"></div>';
			print '<input type="submit" class="button smallpaddingimp valignmiddle" value="'.dol_escape_htmltag($langs->trans('CadTimeLog')).'">';
			print '</form>';
		}
	}

	// Lines
	$userstatic = new User($db);
	$usercache = array();
	$elements = DesignTraceCadTime::getElements();
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('User').'</td>';
	if ($object->element == 'design') {
		print '<td>'.$langs->trans('CadTimeFor').'</td>';
	}
	print '<td>'.$langs->trans('Task').'</td><td>'.$langs->trans('Note').'</td><td class="right">'.$langs->trans('Duration').'</td><td></td></tr>';
	foreach ($lines as $line) {
		if (!isset($usercache[(int) $line->fk_user])) {
			$usercache[(int) $line->fk_user] = ($userstatic->fetch((int) $line->fk_user) > 0 ? $userstatic->getNomUrl(-1) : '');
		}
		print '<tr class="oddeven">';
		print '<td class="nowraponall">'.dol_print_date($line->date, 'day').'</td>';
		print '<td class="tdoverflowmax150">'.$usercache[(int) $line->fk_user].'</td>';
		if ($object->element == 'design') {
			print '<td class="nowraponall"><a href="'.dol_buildpath('/designtrace/'.$elements[$line->element]['card'], 1).'?id='.((int) $line->fk_element).'">'.dol_escape_htmltag((string) $line->element_ref).'</a></td>';
		}
		print '<td class="tdoverflowmax150"><a href="'.DOL_URL_ROOT.'/projet/tasks/time.php?id='.((int) $line->task_id).'">'.dol_escape_htmltag($line->task_ref).'</a> <span class="opacitymedium">'.dol_escape_htmltag((string) $line->task_label).'</span></td>';
		print '<td class="tdoverflowmax200">'.dol_escape_htmltag((string) $line->note).'</td>';
		print '<td class="right nowraponall">'.convertSecondToTime((int) $line->duration, 'allhourmin').'</td>';
		print '<td class="right nowraponall">';
		if (!empty($line->invoice_id)) {
			print '<span class="badge badge-status4" title="'.dol_escape_htmltag($langs->trans('CadTimeInvoiced')).'">'.$langs->trans('Billed').'</span>';
		} elseif (((int) $line->fk_user === (int) $user->id || $user->hasRight('projet', 'all', 'supprimer')) && DesignTraceCadTime::userCanLog($user)) {
			print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'&action=deletecadtime&lineid='.((int) $line->rowid).'&token='.newToken().'">'.img_delete().'</a>';
		}
		print '</td>';
		print '</tr>';
	}
	if (empty($lines)) {
		print '<tr class="oddeven"><td colspan="'.($object->element == 'design' ? 7 : 6).'"><span class="opacitymedium">'.$langs->trans('CadTimeNone').'</span></td></tr>';
	}
	print '</table></div><br>';
}
