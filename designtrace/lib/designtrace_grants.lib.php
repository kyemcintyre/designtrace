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
 * \file        designtrace/lib/designtrace_grants.lib.php
 * \ingroup     designtrace
 * \brief       Design card block: access grants given to customers
 */

dol_include_once('/designtrace/class/grant.class.php');

/**
 * Actions of the grants block (grant_add, grant_revoke, grant_expiry)
 *
 * @param	Design	$object		Design
 * @param	string	$action		Action
 * @return	void
 */
function designtraceDoGrantActions($object, $action)
{
	global $db, $langs, $user;

	if (empty($object->id) || !in_array($action, array('grant_add', 'grant_revoke', 'grant_expiry'), true)) {
		return;
	}
	$langs->load('designtrace@designtrace');
	if (!$user->hasRight('designtrace', 'design', 'write')) {
		setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
		return;
	}
	$grantid = GETPOSTINT('grantid');
	$expiry = GETPOST('expiry', 'alpha') !== '' ? dol_mktime(23, 59, 59, GETPOSTINT('expirymonth'), GETPOSTINT('expiryday'), GETPOSTINT('expiryyear')) : null;
	$grant = $grantid > 0 ? DesignTraceGrant::fetchOne($db, $grantid) : null;
	if ($action != 'grant_add' && (!$grant || (int) $grant->fk_design !== (int) $object->id)) {
		setEventMessages($langs->trans('ErrorRecordNotFound'), null, 'errors');
		return;
	}
	if ($action == 'grant_add') {
		$res = DesignTraceGrant::create($db, $user, GETPOSTINT('socid'), (int) $object->id, array(), array(), $expiry, GETPOST('note', 'alphanohtml'));
		$msg = $res > 0 ? $langs->trans('GrantCreated') : ($res == -2 ? $langs->trans('ErrorGrantNothingToGrant') : $langs->trans('Error'));
	} elseif ($action == 'grant_revoke') {
		$res = DesignTraceGrant::revoke($db, $user, $grantid);
		$msg = $res > 0 ? $langs->trans('GrantRevoked') : $langs->trans('Error');
	} else {
		$res = DesignTraceGrant::setExpiry($db, $grantid, $expiry);
		$msg = $res > 0 ? $langs->trans('GrantExpirySaved') : $langs->trans('Error');
	}
	setEventMessages($msg, null, $res > 0 ? 'mesgs' : 'errors');
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'#grants');
	exit;
}

/**
 * Grants of a design: customers it was granted to, pinned revisions, expiry, state, and the form to grant
 * the whole design to a customer
 *
 * @param	Design	$object		Design
 * @return	void
 */
function designtracePrintGrants($object)
{
	global $db, $langs, $user, $form;

	$langs->load('designtrace@designtrace');
	$canwrite = $user->hasRight('designtrace', 'design', 'write');
	$url = $_SERVER['PHP_SELF'].'?id='.((int) $object->id);

	$grants = array();
	$resql = $db->query("SELECT DISTINCT fk_soc FROM ".$db->prefix()."designtrace_grant WHERE fk_design = ".((int) $object->id));
	while ($resql && ($obj = $db->fetch_object($resql))) {
		foreach (DesignTraceGrant::fetchForCustomer($db, (int) $obj->fk_soc, (int) $object->id) as $grant) {
			$grants[] = $grant;
		}
	}
	usort($grants, function ($a, $b) {
		return (int) $b->rowid <=> (int) $a->rowid;
	});

	print '<a name="grants"></a>';
	print load_fiche_titre($langs->trans('DesignGrants'), '', 'fa-key');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Customer').'</td><td>'.$langs->trans('GrantPins').'</td><td>'.$langs->trans('GrantExpiry').'</td><td>'.$langs->trans('Status').'</td><td></td></tr>';
	foreach ($grants as $grant) {
		$soc = new Societe($db);
		$soc->fetch((int) $grant->fk_soc);
		print '<tr class="oddeven"><td class="tdoverflowmax150">'.$soc->getNomUrl(1).'</td>';
		print '<td>'.count($grant->pins).'</td>';
		print '<td class="nowraponall">';
		if ($canwrite && $grant->active) {
			print '<form method="POST" action="'.$url.'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="grant_expiry"><input type="hidden" name="grantid" value="'.((int) $grant->rowid).'">';
			print $form->selectDate($grant->date_expiry ?: -1, 'expiry', 0, 0, 1, '', 1, 0);
			print ' <input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('Save')).'"></form>';
		} else {
			print $grant->date_expiry ? dol_print_date($grant->date_expiry, 'day') : '<span class="opacitymedium">'.$langs->trans('GrantNoExpiry').'</span>';
		}
		print '</td><td>';
		if ($grant->date_revoked) {
			print '<span class="badge badge-status8">'.$langs->trans('GrantRevokedState').'</span> '.dol_print_date($grant->date_revoked, 'day');
		} elseif (!$grant->active) {
			print '<span class="badge badge-status8">'.$langs->trans('GrantExpiredState').'</span>';
		} else {
			print '<span class="badge badge-status4">'.$langs->trans('GrantActiveState').'</span>';
		}
		print '</td><td class="right">';
		if ($canwrite && $grant->active) {
			print '<a class="reposition" href="'.$url.'&action=grant_revoke&grantid='.((int) $grant->rowid).'&token='.newToken().'">'.img_picto($langs->trans('GrantRevoke'), 'delete').'</a>';
		}
		print '</td></tr>';
	}
	if (empty($grants)) {
		print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('GrantsNone').'</span></td></tr>';
	}
	print '</table></div>';

	if ($canwrite && (int) $object->status === Design::STATUS_VALIDATED) {
		print '<form method="POST" action="'.$url.'#grants" class="margintoponly"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="grant_add">';
		print $form->select_company(0, 'socid', '(s.client:IN:1,2,3)', $langs->trans('SelectThirdParty'), 0, 0, array(), 0, 'minwidth200');
		print ' '.$form->selectDate(-1, 'expiry', 0, 0, 1, '', 1, 0);
		print ' <input type="text" name="note" class="minwidth150" maxlength="255" placeholder="'.dol_escape_htmltag($langs->trans('GrantNote')).'">';
		print ' <input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('GrantAdd')).'">';
		print '</form>';
	}
	print '<br>';
}
