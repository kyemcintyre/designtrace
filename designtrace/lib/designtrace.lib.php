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
 * \file    designtrace/lib/designtrace.lib.php
 * \ingroup designtrace
 * \brief   Library files with common functions for DesignTrace
 */

/**
 * Prepare admin pages header
 *
 * @return array<array{string,string,string}>
 */
function designtraceAdminPrepareHead()
{
	global $langs, $conf;

	// global $db;
	// $extrafields = new ExtraFields($db);
	// $extrafields->fetch_name_optionals_label('design');

	$langs->load("designtrace@designtrace");

	$h = 0;
	$head = array();

	$head[$h][0] = dolBuildUrl(dol_buildpath("/designtrace/admin/setup.php", 1));
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	/*
	$head[$h][0] = dolBuildUrl(dol_buildpath("/designtrace/admin/design_extrafields.php", 1));
	$head[$h][1] = $langs->trans("ExtraFields");
	$nbExtrafields = (isset($extrafields->attributes['design']['label']) && is_countable($extrafields->attributes['design']['label'])) ? count($extrafields->attributes['design']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'design_extrafields';
	$h++;

	$head[$h][0] = dolBuildUrl(dol_buildpath("/designtrace/admin/designline_extrafields.php", 1));
	$head[$h][1] = $langs->trans("ExtraFieldsLines");
	$nbExtrafields = (isset($extrafields->attributes['designline']['label']) && is_countable($extrafields->attributes['designline']['label'])) ? count($extrafields->attributes['design']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'design_extrafieldsline';
	$h++;
	*/

	$head[$h][0] = dolBuildUrl(dol_buildpath("/designtrace/admin/about.php", 1));
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@designtrace:/designtrace/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@designtrace:/designtrace/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, null, $head, $h, 'designtrace@designtrace');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'designtrace@designtrace', 'remove');

	return $head;
}

/**
 * Show the first image of an object's Documents tab in place of the picto in its card banner.
 *
 * dol_banner_tab() only shows photos for core objects, so the card captures its output and
 * swaps the "No photo" block for this thumbnail. The first image follows the order set in
 * the Documents tab (drag and drop), like product photos; the small thumbnail Dolibarr makes
 * on upload is used when it exists. Clicking it opens the full image.
 *
 * @param	CommonObject	$object		Design or Component
 * @param	string			$subdir		Documents sub-directory of the object type ('design', 'component')
 * @param	string			$bannerhtml	HTML printed by dol_banner_tab()
 * @return	string						Banner HTML, with the thumbnail if the object has an image
 */
function designtraceBannerWithThumbnail($object, $subdir, $bannerhtml)
{
	global $conf;

	include_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

	$entity = (empty($object->entity) ? $conf->entity : $object->entity);
	if (empty($object->ref) || empty($conf->designtrace->multidir_output[$entity])) {
		return $bannerhtml;
	}

	$relativedir = $subdir.'/'.dol_sanitizeFileName($object->ref);
	$dir = $conf->designtrace->multidir_output[$entity].'/'.$relativedir;
	$filearray = dol_dir_list($dir, 'files', 0, '\.(gif|jpe?g|png|webp)$', '(\.meta|_preview.*\.png)$', 'name', SORT_ASC, 1);
	if (empty($filearray)) {
		return $bannerhtml;
	}
	completeFileArrayWithDatabaseInfo($filearray, preg_replace('/^'.preg_quote(DOL_DATA_ROOT.'/', '/').'/', '', $dir), $object);
	$filearray = dol_sort_array($filearray, 'position_name', 'asc', 1);
	$file = $filearray[0]['name'];

	$thumb = getImageFileNameForSize($file, '_small');	// thumbs/name_small.ext
	$shown = (file_exists($dir.'/'.$thumb) ? $thumb : $file);
	$url = DOL_URL_ROOT.'/viewimage.php?modulepart=designtrace&entity='.((int) $entity).'&file=';

	$photo = '<div class="floatleft inline-block valignmiddle divphotoref">';
	$photo .= '<a href="'.$url.urlencode($relativedir.'/'.$file).'" target="_blank" rel="noopener noreferrer">';
	$photo .= '<img class="photoref photowithmargin" style="max-height: 80px; max-width: 120px;" alt="'.dol_escape_htmltag($file).'" src="'.$url.urlencode($relativedir.'/'.$shown).'">';
	$photo .= '</a></div>';

	// The picto block dol_banner_tab() prints when it has no photo of its own
	return preg_replace('/<!-- No photo to show -->\s*<div class="floatleft inline-block valignmiddle divphotoref"><div class="photoref">.*?<\/div><\/div>/s', $photo, $bannerhtml, 1);
}
