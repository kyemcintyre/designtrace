<?php
/* Copyright (C) 2007-2017  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
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
 *  \file       designtrace/componentrevision_document.php
 *  \ingroup    designtrace
 *  \brief      Files tab of a component revision: upload with a role, SHA-256, lock once released
 */

// General defined Options
//if (! defined('CSRFCHECK_WITH_TOKEN'))     define('CSRFCHECK_WITH_TOKEN', '1');					// Force use of CSRF protection with tokens even for GET
//if (! defined('MAIN_AUTHENTICATION_MODE')) define('MAIN_AUTHENTICATION_MODE', 'aloginmodule');	// Force authentication handler
//if (! defined('MAIN_LANG_DEFAULT'))        define('MAIN_LANG_DEFAULT', 'auto');					// Force LANG (language) to a particular value
//if (! defined('MAIN_SECURITY_FORCECSP'))   define('MAIN_SECURITY_FORCECSP', 'none');				// Disable all Content Security Policies
//if (! defined('NOBROWSERNOTIF'))     		 define('NOBROWSERNOTIF', '1');					// Disable browser notification
//if (! defined('NOIPCHECK'))                define('NOIPCHECK', '1');						// Do not check IP defined into conf $dolibarr_main_restrict_ip
//if (! defined('NOLOGIN'))                  define('NOLOGIN', '1');						// Do not use login - if this page is public (can be called outside logged session). This includes the NOIPCHECK too.
//if (! defined('NOREQUIREAJAX'))            define('NOREQUIREAJAX', '1');       	  		// Do not load ajax.lib.php library
//if (! defined('NOREQUIREDB'))              define('NOREQUIREDB', '1');					// Do not create database handler $db
//if (! defined('NOREQUIREHTML'))            define('NOREQUIREHTML', '1');					// Do not load html.form.class.php
//if (! defined('NOREQUIREMENU'))            define('NOREQUIREMENU', '1');					// Do not load and show top and left menu
//if (! defined('NOREQUIRESOC'))             define('NOREQUIRESOC', '1');					// Do not load object $mysoc
//if (! defined('NOREQUIRETRAN'))            define('NOREQUIRETRAN', '1');					// Do not load object $langs
//if (! defined('NOREQUIREUSER'))            define('NOREQUIREUSER', '1');					// Do not load object $user
//if (! defined('NOSCANGETFORINJECTION'))    define('NOSCANGETFORINJECTION', '1');			// Do not check injection attack on GET parameters
//if (! defined('NOSCANPOSTFORINJECTION'))   define('NOSCANPOSTFORINJECTION', '1');			// Do not check injection attack on POST parameters
//if (! defined('NOSTYLECHECK'))             define('NOSTYLECHECK', '1');					// Do not check style html tag into posted data
//if (! defined('NOTOKENRENEWAL'))           define('NOTOKENRENEWAL', '1');					// Do not roll the Anti CSRF token (used if MAIN_SECURITY_CSRF_WITH_TOKEN is on)


// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
/**
 * The main.inc.php has been included so the following variable are now defined:
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */
include_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
dol_include_once('/designtrace/class/componentrevision.class.php');
dol_include_once('/designtrace/class/revisionfile.class.php');
dol_include_once('/designtrace/class/clientaccess.class.php');
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/designtrace/lib/designtrace_componentrevision.lib.php');

// Load translation files required by the page
$langs->loadLangs(array("designtrace@designtrace", "other", "errors"));

// Get parameters
$action  = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$fileid = GETPOSTINT('fileid');

// Initialize a technical objects
$object = new ComponentRevision($db);
$extrafields = new ExtraFields($db);
$hookmanager->initHooks(array($object->element.'document', 'globalcard')); // Note that conf->hooks_modules contains array

// Fetch optionals attributes and labels
$extrafields->fetch_name_optionals_label($object->table_element);

// Load object
include DOL_DOCUMENT_ROOT.'/core/actions_fetchobject.inc.php'; // Must be 'include', not 'include_once'. Include fetch and fetch_thirdparty but not fetch_optionals

$permissiontoread = $user->hasRight('designtrace', 'componentrevision', 'read');
$permissiontoadd = $user->hasRight('designtrace', 'componentrevision', 'write');
$permissiontodeletelocked = $user->hasRight('designtrace', 'componentrevision', 'deletereleasedfile');
$permissiontomanagevisibility = $user->hasRight('designtrace', 'componentrevision', 'managevisibility');

if ($user->socid > 0) {
	accessforbidden(); // External users only get files through the portals (phase 5)
}
if (!isModEnabled("designtrace")) {
	accessforbidden();
}
if (!$permissiontoread) {
	accessforbidden();
}
if (empty($object->id)) {
	accessforbidden();
}

$locked = $object->isLocked();
$selfurl = dol_buildpath('/designtrace/componentrevision_document.php', 1).'?id='.((int) $object->id);

$filerecord = null;
if ($fileid > 0) {
	$filerecord = new RevisionFile($db);
	if ($filerecord->fetch($fileid) <= 0 || (int) $filerecord->fk_revision !== (int) $object->id) {
		accessforbidden();
	}
}

$roles = array(
	RevisionFile::ROLE_SOURCE => $langs->trans('FileRoleSource'),
	RevisionFile::ROLE_PRESENTATION => $langs->trans('FileRolePresentation'),
	RevisionFile::ROLE_PRODUCTION => $langs->trans('FileRoleProduction'),
);
$visibilities = array(
	RevisionFile::VISIBILITY_HIDDEN => $langs->trans('FileVisibilityHidden'),
	RevisionFile::VISIBILITY_NOW => $langs->trans('FileVisibilityNow'),
	RevisionFile::VISIBILITY_ON_COMPLETION => $langs->trans('FileVisibilityOnCompletion'),
);
$maxbytes = RevisionFile::getMaxUploadBytes();
$verifyresult = null;


/*
 * Actions
 */

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	// Upload one or more files, with a role or "automatic"
	if ($action == 'upload' && $permissiontoadd) {
		if ($locked) {
			setEventMessages($langs->trans('ErrorRevisionFilesLocked', $object->ref), null, 'errors');
		} elseif (empty($_FILES['userfile']['name']) || !is_array($_FILES['userfile']['name']) || $_FILES['userfile']['name'][0] === '') {
			setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('File')), null, 'errors');
		} else {
			$role = GETPOSTINT('role');
			foreach ($_FILES['userfile']['name'] as $i => $origname) {
				if ($origname === '') {
					continue;
				}
				$uploaderror = (int) $_FILES['userfile']['error'][$i];
				if ($uploaderror == UPLOAD_ERR_INI_SIZE || $uploaderror == UPLOAD_ERR_FORM_SIZE) {
					setEventMessages($langs->trans('ErrorRevisionFileTooLarge', $origname, '?', RevisionFile::formatSize($maxbytes)), null, 'errors');
					continue;
				}
				if ($uploaderror != UPLOAD_ERR_OK) {
					setEventMessages($langs->trans('ErrorFileNotUploaded').' ('.$origname.')', null, 'errors');
					continue;
				}
				$result = $object->addFile($user, $_FILES['userfile']['tmp_name'][$i], $origname, $role, 1);
				if (is_object($result)) {
					if (!in_array($langs->trans('RevisionFileReplaced', $result->filename), $object->warnings, true)) {
						setEventMessages($langs->trans('RevisionFileAdded', $result->filename), null, 'mesgs');
					}
					if (!empty($object->warnings)) {
						setEventMessages(null, $object->warnings, 'warnings');
					}
				} else {
					setEventMessages($object->error, $object->errors, 'errors');
					$object->error = '';
					$object->errors = array();
				}
			}
		}
		header('Location: '.$selfurl);
		exit;
	}

	// Change the role of a file (unlocked revisions only)
	if ($action == 'setrole' && $permissiontoadd && is_object($filerecord)) {
		$role = GETPOSTINT('role');
		if ($locked) {
			setEventMessages($langs->trans('ErrorRevisionFilesLocked', $object->ref), null, 'errors');
		} elseif (!isset($roles[$role])) {
			setEventMessages($langs->trans('ErrorBadValueForParameter', (string) $role, $langs->transnoentitiesnoconv('FileRole')), null, 'errors');
		} else {
			$filerecord->role = $role;
			if ($filerecord->update($user) > 0) {
				setEventMessages($langs->trans('RevisionFileRoleChanged', $filerecord->filename), null, 'mesgs');
			} else {
				setEventMessages($filerecord->error, $filerecord->errors, 'errors');
			}
		}
		header('Location: '.$selfurl);
		exit;
	}

	// Client visibility: who may see a file is not part of its content, so it can change on
	// released revisions too
	if ($action == 'setvisibility' && $permissiontomanagevisibility && is_object($filerecord)) {
		$visibility = GETPOSTINT('visibility');
		if (!isset($visibilities[$visibility])) {
			setEventMessages($langs->trans('ErrorBadValueForParameter', (string) $visibility, $langs->transnoentitiesnoconv('FileVisibility')), null, 'errors');
		} elseif ($visibility != RevisionFile::VISIBILITY_HIDDEN && !DesignTraceClientAccess::isRoleShareable((int) $filerecord->role)) {
			setEventMessages($langs->trans('ErrorFileRoleNeverVisible', $filerecord->filename), null, 'errors');
		} else {
			$filerecord->visibility = $visibility;
			if ($filerecord->update($user) > 0) {
				setEventMessages($langs->trans('RevisionFileVisibilityChanged', $filerecord->filename, $visibilities[$visibility]), null, 'mesgs');
			} else {
				setEventMessages($filerecord->error, $filerecord->errors, 'errors');
			}
		}
		header('Location: '.$selfurl);
		exit;
	}

	// Delete a file: any file of an unlocked revision with the write right, a file of a released
	// revision only with the "delete files of released revisions" right and a reason (logged)
	if ($action == 'confirm_deletefile' && $confirm == 'yes' && is_object($filerecord)) {
		if ($locked ? !$permissiontodeletelocked : !$permissiontoadd) {
			setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
		} elseif ($object->deleteFile($user, $filerecord, $locked, GETPOST('reason', 'alphanohtml')) > 0) {
			setEventMessages($langs->trans('RevisionFileDeleted', $filerecord->filename), null, 'mesgs');
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
		}
		header('Location: '.$selfurl);
		exit;
	}

	// Check the files on disk against their SHA-256
	if ($action == 'verify') {
		$verifyresult = $object->verifyFiles();
		if (is_array($verifyresult)) {
			$problems = count(array_filter($verifyresult['files'], function ($status) {
				return $status !== 'ok';
			}));
			if ($problems) {
				setEventMessages($langs->trans('RevisionFilesProblems', $problems), null, 'errors');
			} else {
				setEventMessages($langs->trans('RevisionFilesAllOk', count($verifyresult['files'])), null, 'mesgs');
			}
			if (!empty($verifyresult['untracked'])) {
				setEventMessages($langs->trans('RevisionFilesUntracked', implode(', ', $verifyresult['untracked'])), null, 'warnings');
			}
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	}
}


/*
 * View
 */

$form = new Form($db);

$title = $langs->trans("ComponentRevision")." - ".$langs->trans("RevisionFiles");
$help_url = '';
llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-designtrace page-card_document');

$head = componentrevisionPrepareHead($object);

print dol_get_fiche_head($head, 'document', $langs->trans("ComponentRevision"), -1, $object->picto, 0, '', '', 0, '', 0);

$component = $object->fetchComponentChecked();
$linkback = '<a href="'.dol_buildpath('/designtrace/componentrevision_list.php', 1).'?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
$morehtmlref = '<div class="refidno">'.(is_object($component) ? $component->getNomUrl(1).' <span class="opacitymedium">'.dol_escape_htmltag($component->label).'</span>' : '').'</div>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);

print dol_get_fiche_end();

// Delete confirmation (a released revision needs a reason, and says what it means)
if ($action == 'deletefile' && is_object($filerecord)) {
	if ($locked) {
		$formquestion = array(
			array('type' => 'text', 'name' => 'reason', 'label' => $langs->trans('RevisionFileDeleteReason'), 'value' => '', 'morecss' => 'minwidth300'),
		);
		$question = img_warning().' '.$langs->trans('ConfirmDeleteLockedRevisionFile', $filerecord->filename);
		print $form->formconfirm($selfurl.'&fileid='.((int) $filerecord->id), $langs->trans('Delete'), $question, 'confirm_deletefile', $formquestion, 'no', 1, 250, 650);
	} else {
		print $form->formconfirm($selfurl.'&fileid='.((int) $filerecord->id), $langs->trans('Delete'), $langs->trans('ConfirmDeleteRevisionFile', $filerecord->filename), 'confirm_deletefile', '', 'no', 1);
	}
}

if ($locked) {
	print info_admin($langs->trans('RevisionFilesLocked', $object->ref), 0, 0, 'warning');
}

$records = (new RevisionFile($db))->fetchAllForRevision($object->id);
if (!is_array($records)) {
	$records = array();
}

// Previous revision, to show what changed
$previous = $object->getPreviousRevision();
$previoushashes = $previousnames = array();
if ($previous) {
	$previousrecords = (new RevisionFile($db))->fetchAllForRevision($previous->id);
	foreach ((is_array($previousrecords) ? $previousrecords : array()) as $prevfile) {
		$previoushashes[$prevfile->sha256] = $prevfile->filename;
		$previousnames[$prevfile->filename] = $prevfile->sha256;
	}
}

$downloadcounts = DesignTraceClientAccess::countDownloads($db, $object->id);
$usercache = array();
$canedit = (!$locked && $permissiontoadd);
$candelete = ($locked ? $permissiontodeletelocked : $permissiontoadd);
$downloadbase = DOL_URL_ROOT.'/document.php?modulepart=designtrace&entity='.((int) (empty($object->entity) ? $conf->entity : $object->entity)).'&file=';
$reldir = $object->element.'/'.dol_sanitizeFileName($object->ref).'/';

print load_fiche_titre($langs->trans('FilesOfRevision', $object->ref), '', '');

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Filename').'</td>';
print '<td>'.$form->textwithpicto($langs->trans('FileRole'), $langs->trans('FileRoleHelp')).'</td>';
print '<td>'.$form->textwithpicto($langs->trans('FileVisibility'), $langs->trans('FileVisibilityHelp')).'</td>';
print '<td class="center">'.$langs->trans('Format').'</td>';
print '<td class="right">'.$langs->trans('Size').'</td>';
print '<td>'.$langs->trans('SHA256').'</td>';
if ($previous) {
	print '<td>'.$langs->trans('FileComparedToPrevious', $previous->rev).'</td>';
}
print '<td>'.$langs->trans('DateCreation').'</td>';
print '<td class="center">'.$form->textwithpicto($langs->trans('Downloads'), $langs->trans('FileDownloadsHelp')).'</td>';
if (is_array($verifyresult)) {
	print '<td class="center">'.$langs->trans('Status').'</td>';
}
print '<td></td>';
print '</tr>';

$totalsize = 0;
foreach ($records as $file) {
	$totalsize += (int) $file->filesize;
	print '<tr class="oddeven">';

	print '<td class="tdoverflowmax300">';
	print '<a href="'.$downloadbase.urlencode($reldir.$file->filename).'" title="'.dol_escape_htmltag($file->filename).'">'.img_mime($file->filename, '', 'pictofixedwidth').dol_escape_htmltag($file->filename).'</a>';
	print '</td>';

	print '<td class="nowraponall">';
	if ($canedit) {
		print '<form method="POST" action="'.$selfurl.'" class="inline-block">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="setrole">';
		print '<input type="hidden" name="fileid" value="'.((int) $file->id).'">';
		print '<select class="flat minwidth125" name="role" id="role_'.((int) $file->id).'" onchange="this.form.submit()">';
		foreach ($roles as $rolekey => $rolelabel) {
			print '<option value="'.((int) $rolekey).'"'.((int) $file->role == $rolekey ? ' selected' : '').'>'.dol_escape_htmltag($rolelabel).'</option>';
		}
		print '</select>';
		print '</form>';
	} else {
		print RevisionFile::getRoleBadge($file->role);
	}
	print '</td>';

	print '<td class="nowraponall">';
	if (!DesignTraceClientAccess::isRoleShareable((int) $file->role)) {
		print '<span class="opacitymedium">'.$form->textwithpicto($langs->trans('FileVisibilityNever'), $langs->trans('FileVisibilityNeverProduction')).'</span>';
	} elseif ($permissiontomanagevisibility) {
		print '<form method="POST" action="'.$selfurl.'" class="inline-block">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="setvisibility">';
		print '<input type="hidden" name="fileid" value="'.((int) $file->id).'">';
		print '<select class="flat minwidth150" name="visibility" id="visibility_'.((int) $file->id).'" onchange="this.form.submit()">';
		foreach ($visibilities as $viskey => $vislabel) {
			print '<option value="'.((int) $viskey).'"'.((int) $file->visibility == $viskey ? ' selected' : '').'>'.dol_escape_htmltag($vislabel).'</option>';
		}
		print '</select>';
		print '</form>';
	} else {
		print dol_escape_htmltag(isset($visibilities[(int) $file->visibility]) ? $visibilities[(int) $file->visibility] : '');
	}
	if ((int) $file->role === RevisionFile::ROLE_SOURCE && (int) $file->visibility !== RevisionFile::VISIBILITY_HIDDEN) {
		print '<br><span class="opacitymedium small">'.$langs->trans('FileVisibilitySourceNote').'</span>';
	}
	print '</td>';

	print '<td class="center">'.dol_escape_htmltag(strtoupper((string) $file->format)).'</td>';
	print '<td class="right nowraponall">'.RevisionFile::formatSize((int) $file->filesize).'</td>';
	print '<td class="nowraponall"><span class="classfortooltip" title="'.dol_escape_htmltag($file->sha256).'">'.dol_escape_htmltag(substr($file->sha256, 0, 12)).'…</span></td>';

	if ($previous) {
		print '<td class="tdoverflowmax200">';
		if (isset($previoushashes[$file->sha256])) {
			$samename = ($previoushashes[$file->sha256] === $file->filename);
			print '<span class="opacitymedium">'.($samename ? $langs->trans('FileSameAsPrevious', $previous->rev) : $langs->trans('FileSameAsPreviousOtherName', dol_escape_htmltag($previoushashes[$file->sha256]), $previous->rev)).'</span>';
		} elseif (isset($previousnames[$file->filename])) {
			print $langs->trans('FileChangedSincePrevious', $previous->rev);
		} else {
			print $langs->trans('FileNewInRevision');
		}
		print '</td>';
	}

	print '<td class="nowraponall">'.dol_print_date($file->date_creation, 'dayhour');
	if (!isset($usercache[$file->fk_user_creat])) {
		$usercache[$file->fk_user_creat] = new User($db);
		$usercache[$file->fk_user_creat]->fetch((int) $file->fk_user_creat);
	}
	print ' '.$usercache[$file->fk_user_creat]->getNomUrl(-1, '', 0, 0, 16);
	print '</td>';

	print '<td class="center">'.(empty($downloadcounts[$file->id]) ? '<span class="opacitymedium">0</span>' : (int) $downloadcounts[$file->id]).'</td>';

	if (is_array($verifyresult)) {
		$status = isset($verifyresult['files'][$file->id]) ? $verifyresult['files'][$file->id] : 'missing';
		$statusmap = array('ok' => array('RevisionFileStatusOk', 'status4'), 'changed' => array('RevisionFileStatusChanged', 'status8'), 'missing' => array('RevisionFileStatusMissing', 'status8'));
		print '<td class="center">'.dolGetStatus($langs->trans($statusmap[$status][0]), $langs->trans($statusmap[$status][0]), '', $statusmap[$status][1], 5).'</td>';
	}

	print '<td class="right nowraponall">';
	if ($candelete) {
		print '<a class="reposition" href="'.$selfurl.'&action=deletefile&fileid='.((int) $file->id).'&token='.newToken().'">'.img_delete($langs->trans('Delete')).'</a>';
	}
	print '</td>';
	print '</tr>';
}
if (empty($records)) {
	print '<tr class="oddeven"><td colspan="12"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
} else {
	print '<tr class="liste_total"><td colspan="4">'.$langs->trans('Total').': '.count($records).'</td><td class="right nowraponall">'.RevisionFile::formatSize($totalsize).'</td><td colspan="'.(4 + ($previous ? 1 : 0) + (is_array($verifyresult) ? 1 : 0)).'"></td></tr>';
}
print '</table>';
print '</div>';

// Buttons
print '<div class="tabsAction">';
print dolGetButtonAction('', $langs->trans('RevisionFilesVerify'), 'default', $selfurl.'&action=verify&token='.newToken(), '', !empty($records));
print '</div>';

// Customer download log (Web portal and REST API)
$downloads = DesignTraceClientAccess::fetchDownloads($db, $object->id, 50);
if (!empty($downloads)) {
	$channels = DesignTraceClientAccess::getChannels();
	print load_fiche_titre($form->textwithpicto($langs->trans('DownloadLog'), $langs->trans('DownloadLogHelp')), '', 'fa-download');
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Filename').'</td><td>'.$langs->trans('Customer').'</td><td>'.$langs->trans('Document').'</td>';
	print '<td>'.$langs->trans('DownloadChannel').'</td><td>'.$langs->trans('DownloadActor').'</td><td>'.$langs->trans('IPAddress').'</td></tr>';
	$socstatic = new Societe($db);
	foreach ($downloads as $dl) {
		print '<tr class="oddeven">';
		print '<td class="nowraponall">'.dol_print_date($dl->date_download, 'dayhour').'</td>';
		print '<td class="tdoverflowmax250">'.dol_escape_htmltag($dl->filename).'</td>';
		$socstatic->id = (int) $dl->fk_soc;
		$socstatic->name = (string) $dl->socname;
		print '<td class="tdoverflowmax150">'.($dl->socname !== null ? $socstatic->getNomUrl(1) : '').'</td>';
		print '<td class="nowraponall">'.(isset($channels[$dl->element]) ? $langs->trans($channels[$dl->element]['label']).' ' : '');
		$docref = DesignTraceClientAccess::getDocumentNomUrl($db, (string) $dl->element, (int) $dl->fk_element);
		print $docref.'</td>';
		print '<td>'.$langs->trans($dl->channel == DesignTraceClientAccess::CHANNEL_API ? 'DownloadChannelApi' : 'DownloadChannelWebportal').'</td>';
		print '<td class="tdoverflowmax150">'.dol_escape_htmltag((string) $dl->actor).'</td>';
		print '<td class="nowraponall">'.dol_escape_htmltag((string) $dl->ip).'</td>';
		print '</tr>';
	}
	print '</table>';
	print '</div>';
}

// Upload form
if ($canedit) {
	print load_fiche_titre($langs->trans('AttachANewFile'), '', '');
	print '<form method="POST" action="'.$selfurl.'" enctype="multipart/form-data">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="upload">';
	if ($maxbytes > 0) {
		print '<input type="hidden" name="MAX_FILE_SIZE" value="'.((int) $maxbytes).'">';
	}
	print '<table class="border centpercent">';
	print '<tr><td class="titlefield">'.$langs->trans('File').'</td><td>';
	print '<input type="file" class="flat minwidth400" name="userfile[]" multiple>';
	print ' <span class="opacitymedium small">'.$langs->trans('RevisionFilesMaxSize', RevisionFile::formatSize($maxbytes)).'</span>';
	print '</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('FileRole'), $langs->trans('FileRoleHelp')).'</td><td>';
	print $form->selectarray('role', array(0 => $langs->trans('FileRoleAuto')) + $roles, 0, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
	print '</td></tr>';
	print '</table>';
	print '<div class="center"><span class="opacitymedium small">'.$langs->trans('RevisionFilesAddHelp').'</span><br>';
	print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('Upload')).'"></div>';
	print '</form>';
}

// End of page
llxFooter();
$db->close();
