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
 * \file    designtrace/lib/designtrace_componentrevision.lib.php
 * \ingroup designtrace
 * \brief   Library files with common functions for ComponentRevision
 */

/**
 * Prepare array of tabs for ComponentRevision
 *
 * @param	ComponentRevision	$object					ComponentRevision
 * @return 	array<array{string,string,string}>	Array of tabs
 */
function componentrevisionPrepareHead($object)
{
	global $db, $langs, $conf;

	$langs->load("designtrace@designtrace");

// BEGIN MODULEBUILDER TABFLAG NOTE
	$showtabofpagenote = 1;
	// END MODULEBUILDER TABFLAG NOTE
	// BEGIN MODULEBUILDER TABFLAG DOCUMENT
	$showtabofpagedocument = 1;
	// END MODULEBUILDER TABFLAG DOCUMENT
	// BEGIN MODULEBUILDER TABFLAG AGENDA
	$showtabofpageagenda = 1;
	// END MODULEBUILDER TABFLAG AGENDA

	$h = 0;
	$head = array();

	$head[$h][0] = dolBuildUrl(dol_buildpath("/designtrace/componentrevision_card.php", 1), ['id' => $object->id]);
	$head[$h][1] = $langs->trans("ComponentRevision");
	$head[$h][2] = 'card';
	$h++;

// BEGIN MODULEBUILDER TAB NOTE
	if ($showtabofpagenote) {
		if (isset($object->fields['note_public']) || isset($object->fields['note_private'])) {
			$nbNote = 0;
			if (!empty($object->note_private)) {
				$nbNote++;
			}
			if (!empty($object->note_public)) {
				$nbNote++;
			}
			$head[$h][0] = dolBuildUrl(dol_buildpath('/designtrace/componentrevision_note.php', 1), ['id' => $object->id]);
			$head[$h][1] = $langs->trans('Notes');
			if ($nbNote > 0) {
				$head[$h][1] .= (!getDolGlobalInt('MAIN_OPTIMIZEFORTEXTBROWSER') ? '<span class="badge marginleftonlyshort">'.$nbNote.'</span>' : '');
			}
			$head[$h][2] = 'note';
			$h++;
		}
	}
	// END MODULEBUILDER TAB NOTE

	// BEGIN MODULEBUILDER TAB DOCUMENT
	if ($showtabofpagedocument) {
		// Files of the revision, with their role and hash (not Dolibarr's generic documents tab)
		$nbFiles = $object->countFiles();
		$head[$h][0] = dolBuildUrl(dol_buildpath("/designtrace/componentrevision_document.php", 1), ['id' => $object->id]);
		$head[$h][1] = $langs->trans('RevisionFiles');
		if ($nbFiles > 0) {
			$head[$h][1] .= '<span class="badge marginleftonlyshort">'.$nbFiles.'</span>';
		}
		$head[$h][2] = 'document';
		$h++;
	}
	// END MODULEBUILDER TAB DOCUMENT

	// BEGIN MODULEBUILDER TAB AGENDA
	if ($showtabofpageagenda) {
		$head[$h][0] = dolBuildUrl(dol_buildpath("/designtrace/componentrevision_agenda.php", 1), ['id' => $object->id]);
		$head[$h][1] = $langs->trans("Events");
		$head[$h][2] = 'agenda';
		$h++;
	}
	// END MODULEBUILDER TAB AGENDA

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@designtrace:/designtrace/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@designtrace:/designtrace/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, $object, $head, $h, 'componentrevision@designtrace');

	complete_head_from_modules($conf, $langs, $object, $head, $h, 'componentrevision@designtrace', 'remove');

	return $head;
}
