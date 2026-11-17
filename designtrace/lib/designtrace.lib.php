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

/**
 * Return a select of the customer quotes ('propal') or orders ('commande') a new design can be
 * linked to, newest first. Each option carries the customer id (data-socid) so the create form
 * can narrow the list to the chosen customer.
 *
 * @param	string	$element	'propal' or 'commande'
 * @param	string	$htmlname	Name and id of the select
 * @param	int		$selected	Preselected id
 * @return	string				HTML select (with select2), or '' if the element is not supported
 */
function designtraceSelectSalesDocument($element, $htmlname, $selected = 0)
{
	global $db, $langs;

	include_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';

	if ($element == 'propal') {
		$table = 'propal';
		$sqlstatus = '';
	} elseif ($element == 'commande') {
		$table = 'commande';
		$sqlstatus = ' AND t.fk_statut >= 0';		// Not cancelled
	} else {
		return '';
	}

	$sql = "SELECT t.rowid, t.ref, t.ref_client, t.fk_soc, s.nom as socname";
	$sql .= " FROM ".$db->prefix().$table." as t";
	$sql .= " LEFT JOIN ".$db->prefix()."societe as s ON s.rowid = t.fk_soc";
	$sql .= " WHERE t.entity IN (".getEntity($element).")".$sqlstatus;
	$sql .= " ORDER BY t.rowid DESC";		// Newest first (the creation date column differs between tables)
	$sql .= $db->plimit(1000);

	$out = '<select class="flat minwidth300 maxwidth500 widthcentpercentminusx" id="'.dol_escape_htmltag($htmlname).'" name="'.dol_escape_htmltag($htmlname).'">';
	$out .= '<option value="-1" data-socid="0">&nbsp;</option>';
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$label = $obj->ref.' - '.$obj->socname.($obj->ref_client ? ' ('.$obj->ref_client.')' : '');
			$out .= '<option value="'.((int) $obj->rowid).'" data-socid="'.((int) $obj->fk_soc).'"'.((int) $obj->rowid == (int) $selected ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
		}
		$db->free($resql);
	} else {
		dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
	}
	$out .= '</select>';
	$out .= ajax_combobox($htmlname);

	return $out;
}

/**
 * Sales documents a new design can be linked to on creation, among those the user can read.
 *
 * @return	string[]	Subset of array('propal', 'commande')
 */
function designtraceLinkableSalesElements()
{
	global $user;

	$elements = array();
	if (isModEnabled('propal') && $user->hasRight('propal', 'lire')) {
		$elements[] = 'propal';
	}
	if (isModEnabled('order') && $user->hasRight('commande', 'lire')) {
		$elements[] = 'commande';
	}

	return $elements;
}

/**
 * Cause of a revision for the revision history: "from ECO-0012: bracket cracking at bend" when
 * it was made under a change order, else its change reason
 *
 * @param	ComponentRevision	$revision	Revision
 * @return	string							HTML
 */
function designtraceRevisionCause($revision)
{
	global $langs;

	static $ecocache = array();

	$out = '';
	if (!empty($revision->fk_eco)) {
		$ecoid = (int) $revision->fk_eco;
		if (!array_key_exists($ecoid, $ecocache)) {
			$ecocache[$ecoid] = $revision->fetchEco();
		}
		$eco = $ecocache[$ecoid];
		if ($eco) {
			$out = $langs->trans('RevisionFromEco', $eco->getNomUrl(1), dol_escape_htmltag((string) $eco->label));
		}
	}
	if ($out === '' && !empty($revision->change_reason) && isset($revision->fields['change_reason']['arrayofkeyval'][$revision->change_reason])) {
		$out = dol_escape_htmltag($revision->fields['change_reason']['arrayofkeyval'][$revision->change_reason]);
	}
	return $out;
}

/**
 * Log an unlock (a locked revision reopened, an archived design reactivated) as an agenda event
 * on the object, so who reopened it and why stays on record.
 *
 * @param	DoliDB			$db			Database handler
 * @param	User			$user		User unlocking
 * @param	CommonObject	$object		Revision or design that was unlocked
 * @param	string			$code		Event code (AC_DESIGNTRACE_...)
 * @param	string			$label		Event label
 * @param	string			$note		Event note (holds the reason)
 * @return	int<-1,1>					>0 if OK, <0 if KO (error in $object->error)
 */
function designtraceLogUnlock($db, $user, $object, $code, $label, $note)
{
	require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';

	$now = dol_now();
	$event = new ActionComm($db);
	$event->type_code = 'AC_OTH_AUTO';
	$event->code = $code;
	$event->label = $label;
	$event->note_private = $note;
	$event->datep = $now;
	$event->datef = $now;
	$event->percentage = -1;
	$event->userownerid = $user->id;
	$event->authorid = $user->id;
	$event->elementtype = $object->element.'@'.$object->module;
	$event->fk_element = $object->id;
	if ($event->create($user) <= 0) {
		$object->error = $event->error;
		$object->errors = $event->errors;
		return -1;
	}
	return 1;
}

/**
 * Breadcrumb for the banner of a part/assembly/drawing or a revision: the way back up to the
 * owning design (and, for a revision, to its component). Each link only shows when the user
 * may read that object; the current object is the banner's own ref, so it is not repeated.
 *
 * @param	DoliDB					$db			Database handler
 * @param	User					$user		Current user
 * @param	Component|ComponentRevision	$object		Component or component revision
 * @return	string							HTML (empty when there is nothing above the object)
 */
function designtraceBreadcrumb($db, $user, $object)
{
	global $langs;

	dol_include_once('/designtrace/class/design.class.php');
	dol_include_once('/designtrace/class/component.class.php');

	$component = null;
	if ($object->element == 'componentrevision') {
		if (!empty($object->fk_component)) {
			$component = new Component($db);
			if ($component->fetch((int) $object->fk_component) <= 0) {
				$component = null;
			}
		}
	} elseif ($object->element == 'component') {
		$component = $object;
	}
	if (!is_object($component)) {
		return '';
	}

	$crumbs = array();
	if (!empty($component->fk_design) && $user->hasRight('designtrace', 'design', 'read')) {
		$design = new Design($db);
		if ($design->fetch((int) $component->fk_design) > 0) {
			$crumbs[] = $design->getNomUrl(1).' <span class="opacitymedium">'.dol_escape_htmltag($design->label).'</span>';
		}
	}
	if ($object->element == 'componentrevision' && $user->hasRight('designtrace', 'component', 'read')) {
		$crumbs[] = $component->getNomUrl(1).' <span class="opacitymedium">'.dol_escape_htmltag($component->label).'</span>';
	}
	if (empty($crumbs)) {
		return '';
	}

	return '<div class="refidno designtrace-breadcrumb">'.implode(' <span class="opacitymedium">&rsaquo;</span> ', $crumbs).'</div>';
}
