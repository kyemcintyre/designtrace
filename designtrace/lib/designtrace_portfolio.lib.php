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
 * \file        designtrace/lib/designtrace_portfolio.lib.php
 * \ingroup     designtrace
 * \brief       Portfolio consent block of the design card
 */

dol_include_once('/designtrace/class/portfolio.class.php');
dol_include_once('/designtrace/class/clientaccess.class.php');


/**
 * Actions of the consent block (setconsent)
 *
 * @param	Design	$object		Design
 * @param	string	$action		Action
 * @return	void
 */
function designtraceDoConsentActions($object, $action)
{
	global $db, $langs, $user;

	if ($action != 'setconsent' || empty($object->id)) {
		return;
	}
	if (!$user->hasRight('designtrace', 'design', 'write')) {
		setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
		return;
	}
	$res = DesignTracePortfolio::setConsent($db, (int) $object->id, GETPOSTINT('consent'), DesignTracePortfolio::CHANNEL_BACKOFFICE, $user->login, '', 0, (int) $user->id, GETPOST('consent_note', 'alphanohtml'));
	if ($res < 0) {
		setEventMessages($db->lasterror() ?: $langs->trans('Error'), null, 'errors');
		return;
	}
	if ($res > 0) {
		setEventMessages($langs->trans('PortfolioConsentSaved'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id));
	exit;
}

/**
 * Portfolio consent block: state, gallery status, history and the form to record an answer
 *
 * @param	Design	$object		Design
 * @return	void
 */
function designtracePrintConsentBox($object)
{
	global $db, $langs, $user, $form;

	$langs->load('designtrace@designtrace');
	$labels = DesignTracePortfolio::getConsentLabels();
	$channels = array(
		DesignTracePortfolio::CHANNEL_BACKOFFICE => $langs->trans('PortfolioChannelBackoffice'),
		DesignTracePortfolio::CHANNEL_WEBPORTAL => $langs->trans('PortfolioChannelWebportal'),
		DesignTracePortfolio::CHANNEL_API => $langs->trans('PortfolioChannelApi'),
		DesignTracePortfolio::CHANNEL_LICENCE => $langs->trans('PortfolioChannelLicence'),
	);

	print load_fiche_titre($langs->trans('PortfolioConsent'), '', 'fa-images');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td colspan="4">'.DesignTracePortfolio::getConsentBadge((int) $object->portfolio_consent).' ';
	if (DesignTracePortfolio::isInGallery($object)) {
		print '<span class="badge badge-status4 marginleftonlyshort">'.$langs->trans('PortfolioInGallery').'</span>';
	} elseif ((int) $object->portfolio_consent === DesignTracePortfolio::CONSENT_GRANTED) {
		print '<span class="opacitymedium">'.$langs->trans(!empty($object->confidential) ? 'PortfolioNotInGalleryConfidential' : 'PortfolioNotInGalleryNotActive').'</span>';
	}
	print '</td></tr>';
	$history = DesignTracePortfolio::fetchHistory($db, (int) $object->id);
	foreach ($history as $row) {
		print '<tr class="oddeven">';
		print '<td class="nowraponall">'.dol_print_date($row->date_consent, 'dayhour').'</td>';
		print '<td class="nowraponall">'.dol_escape_htmltag($labels[(int) $row->consent] ?? '').'</td>';
		print '<td class="tdoverflowmax200">'.dol_escape_htmltag($channels[$row->channel] ?? $row->channel).' &middot; '.dol_escape_htmltag((string) $row->actor);
		if (!empty($row->element) && $row->fk_element > 0) {
			print ' &middot; '.DesignTraceClientAccess::getDocumentNomUrl($db, $row->element, (int) $row->fk_element);
		}
		print '</td>';
		print '<td class="tdoverflowmax200">'.dol_escape_htmltag((string) $row->note).'</td>';
		print '</tr>';
	}
	if (empty($history)) {
		print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('PortfolioConsentNoHistory').'</span></td></tr>';
	}
	print '</table></div>';

	if ($user->hasRight('designtrace', 'design', 'write')) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'" class="margintoponly">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="setconsent">';
		print $form->selectarray('consent', array(DesignTracePortfolio::CONSENT_GRANTED => $labels[DesignTracePortfolio::CONSENT_GRANTED], DesignTracePortfolio::CONSENT_REFUSED => $labels[DesignTracePortfolio::CONSENT_REFUSED]), '', 1, 0, 0, '', 0, 0, 0, '', 'minwidth100');
		print ' <input type="text" name="consent_note" class="minwidth200" maxlength="255" placeholder="'.dol_escape_htmltag($langs->trans('PortfolioConsentNotePlaceholder')).'">';
		print ' <input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('PortfolioConsentRecord')).'">';
		print '</form>';
	}
}
