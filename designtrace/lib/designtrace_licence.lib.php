<?php
/* Copyright (C) 2026		Kye McIntyre
 * Copyright (C) 2025       Frédéric France         <frederic.france@free.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    designtrace/lib/designtrace_licence.lib.php
 * \ingroup designtrace
 * \brief   Library files with common functions for Licence
 */

/**
 * Prepare array of tabs for Licence
 *
 * @param	Licence	$object					Licence
 * @return 	array<array{string,string,string}>	Array of tabs
 */
function licencePrepareHead($object)
{
	global $db, $langs, $conf;

	$langs->load("designtrace@designtrace");

$h = 0;
	$head = array();

	$head[$h][0] = dolBuildUrl(dol_buildpath("/designtrace/licence_card.php", 1), ['id' => $object->id]);
	$head[$h][1] = $langs->trans("Licence");
	$head[$h][2] = 'card';
	$h++;

// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@designtrace:/designtrace/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@designtrace:/designtrace/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, $object, $head, $h, 'licence@designtrace');

	complete_head_from_modules($conf, $langs, $object, $head, $h, 'licence@designtrace', 'remove');

	return $head;
}

/**
 * Handle the licence template card's own actions (redirects when it handles one)
 *
 * @param	string	$action		Action
 * @param	Licence	$object		Template shown
 * @param	User	$user		User
 * @return	string				Action to go on with
 */
function designtraceDoLicenceActions($action, $object, $user)
{
	global $langs;

	if (empty($object->id) || !in_array($action, array('licence_newversion', 'licence_disable', 'licence_enable'), true)) {
		return $action;
	}
	$selfurl = dol_buildpath('/designtrace/licence_card.php', 1).'?id='.((int) $object->id);
	if (!$user->hasRight('designtrace', 'licence', 'write')) {
		setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
	} elseif ($action == 'licence_newversion') {
		$version = $object->newVersion($user);
		if (is_object($version)) {
			setEventMessages($langs->trans('LicenceVersionCreated', $version->ref), null, 'mesgs');
			header('Location: '.dol_buildpath('/designtrace/licenceversion_card.php', 1).'?id='.((int) $version->id).'&action=edit&token='.newToken());
			exit;
		}
		setEventMessages($object->error, $object->errors, 'errors');
	} else {
		$result = ($action == 'licence_disable' ? $object->disable($user) : $object->enable($user));
		if ($result < 0) {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	}
	header('Location: '.$selfurl);
	exit;
}

/**
 * Wording and permissions of a licence version
 *
 * @param	LicenceVersion	$version	Version
 * @param	string			$wording	Wording to show (rendered), '' for the raw wording
 * @return	string						HTML
 */
function designtraceLicenceVersionBox($version, $wording = '')
{
	global $langs;

	$langs->load('designtrace@designtrace');
	$out = '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	$out .= '<tr class="liste_titre"><td colspan="2">'.$langs->trans('LicencePermissions').'</td></tr>';
	foreach ($version->getPermissions() as $label => $value) {
		$out .= '<tr class="oddeven"><td>'.dol_escape_htmltag($label).'</td><td class="right">'.($value ? '<span class="badge badge-status4 badge-status">'.$langs->trans('Yes').'</span>' : '<span class="badge badge-status8 badge-status">'.$langs->trans('No').'</span>').'</td></tr>';
	}
	$out .= '</table></div>';
	$out .= '<div class="licencewording paddingtop" style="max-height: 400px; overflow-y: auto;">'.($wording !== '' ? $wording : dol_htmlentitiesbr((string) $version->wording)).'</div>';
	return $out;
}

/**
 * Versions block of the licence template card: the current version (permissions and wording)
 * and the list of versions
 *
 * @param	Licence	$object		Template
 * @param	Form	$form		Form helper
 * @return	void
 */
function designtracePrintLicenceVersions($object, $form)
{
	global $langs, $user;

	$current = $object->getCurrentVersion();
	$draft = $object->getDraftVersion();
	$morehtmlright = '';
	if ($user->hasRight('designtrace', 'licence', 'write')) {
		$url = dol_buildpath('/designtrace/licence_card.php', 1).'?id='.((int) $object->id).'&action=licence_newversion&token='.newToken();
		$morehtmlright = dolGetButtonTitle($langs->trans('LicenceNewVersion'), $draft ? $langs->trans('ErrorLicenceDraftExists', $object->ref) : '', 'fa fa-plus-circle', $url, '', $draft ? -2 : 1);
	}

	print '<br>';
	print load_fiche_titre($form->textwithpicto($langs->trans('LicenceVersions'), $langs->trans('LicenceVersionsHelp')), $morehtmlright, 'fa-file-contract');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('LicenceChangeNote').'</td><td>'.$langs->trans('LicenceDateActivation').'</td><td class="right">'.$langs->trans('Status').'</td></tr>';
	$versions = $object->fetchVersions();
	foreach ($versions as $version) {
		print '<tr class="oddeven"><td class="nowraponall">'.$version->getNomUrl(1).'</td>';
		print '<td class="tdoverflowmax300">'.dol_escape_htmltag((string) $version->change_note).'</td>';
		print '<td class="nowraponall">'.dol_print_date($version->date_activation, 'day').'</td>';
		print '<td class="right">'.$version->getLibStatut(5).'</td></tr>';
	}
	if (empty($versions)) {
		print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
	}
	print '</table></div>';

	print '<br>';
	if ($current) {
		print load_fiche_titre($langs->trans('LicenceCurrentVersion', $current->ref), '', '');
		print designtraceLicenceVersionBox($current);
	} else {
		print '<div class="warning">'.$langs->trans('LicenceNoCurrentVersion').'</div>';
	}
}
