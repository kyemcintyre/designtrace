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
 * \file        designtrace/lib/designtrace_credits.lib.php
 * \ingroup     designtrace
 * \brief       Design and ECO card blocks: revision credits (allowance, ledger, the decision when approving a change)
 */

dol_include_once('/designtrace/class/credits.class.php');

/**
 * The credit decision an approving form posted
 *
 * @return	array<string,string>	design_id, outcome, size, public_note, internal_note
 */
function designtraceCreditFromPost()
{
	return array(
		'design_id' => (string) GETPOSTINT('credit_design'),
		'outcome' => GETPOST('credit_outcome', 'aZ09'),
		'size' => GETPOST('credit_size', 'aZ09'),
		'public_note' => GETPOST('credit_public', 'alphanohtml'),
		'internal_note' => GETPOST('credit_internal', 'alphanohtml'),
	);
}

/**
 * Actions of the credits blocks: credit_grant, credit_adjust, credit_tier, credit_expiry (design card), eco_chargeround (ECO card).
 * All POST with the token; each redirects.
 *
 * @param	Design|ChangeOrder	$object		Design or ECO
 * @param	string				$action		Action
 * @return	void
 */
function designtraceDoCreditActions($object, $action)
{
	global $db, $langs, $user;

	if (empty($object->id) || !in_array($action, array('credit_grant', 'credit_adjust', 'credit_tier', 'credit_expiry', 'eco_chargeround'), true)) {
		return;
	}
	$langs->load('designtrace@designtrace');
	$actor = 'staff:'.$user->login;
	$self = $_SERVER['PHP_SELF'].'?id='.((int) $object->id);
	$need = ($action === 'credit_adjust' ? 'adjust' : ($action === 'eco_chargeround' ? '' : 'write'));
	$allowed = ($action === 'eco_chargeround' ? $user->hasRight('designtrace', 'changeorder', 'approve') : $user->hasRight('designtrace', 'credit', $need));
	if (!$allowed) {
		setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
		return;
	}
	if (GETPOST('token', 'alpha') === '' || GETPOST('token', 'alpha') !== currentToken()) {
		setEventMessages($langs->trans('ErrorGrantBadToken'), null, 'errors');
		return;
	}
	$public = GETPOST('credit_public', 'alphanohtml');
	$internal = GETPOST('credit_internal', 'alphanohtml');
	$months = GETPOSTINT('credit_months');
	if (!in_array($months, DesignTraceCredits::EXPIRY_CHOICES, true)) {
		$months = 0;
	}
	$result = 1;
	$message = '';
	if ($action === 'eco_chargeround') {
		$error = '';
		$outcome = DesignTraceCredits::chargeRound($object, $actor, $public, $internal, $error);
		$result = ($outcome === false ? -1 : 1);
		$message = ($outcome === false ? $langs->trans('ErrorEcoCredit_'.$error) : $langs->trans($outcome === DesignTraceCredits::OUTCOME_CREDIT ? 'CreditRoundCharged' : 'CreditRoundBilled'));
	} elseif ($action === 'credit_tier') {
		$result = DesignTraceCredits::setTier($db, (int) $object->id, GETPOSTINT('credit_tier'));
		$message = $langs->trans($result > 0 ? 'CreditTierSaved' : 'Error');
	} elseif ($action === 'credit_expiry') {
		$expiry = null;
		if (GETPOSTINT('expiryyear') > 0) {
			$expiry = dol_mktime(23, 59, 59, GETPOSTINT('expirymonth'), GETPOSTINT('expiryday'), GETPOSTINT('expiryyear'));
		}
		$result = DesignTraceCredits::setExpiry($db, GETPOSTINT('grantid'), $expiry ?: null, $actor, $public, $internal);
		$message = $langs->trans($result > 0 ? 'GrantExpirySaved' : 'Error');
	} elseif ($action === 'credit_grant') {
		$result = ((int) $object->fk_soc > 0 && GETPOSTINT('credit_amount') > 0) ? DesignTraceCredits::addGrant($db, (int) $object->id, (int) $object->fk_soc, GETPOSTINT('credit_amount'), $months, DesignTraceCredits::SOURCE_MANUAL, $public, $internal, (int) $user->id) : -2;
		$message = $langs->trans($result > 0 ? 'CreditGranted' : 'Error');
	} else {
		$result = DesignTraceCredits::adjust($db, (int) $object->id, GETPOSTINT('credit_amount'), $months, $public, $internal, $actor, (int) $user->id);
		$message = ($result > 0 ? $langs->trans('CreditAdjusted') : $langs->trans($result === -2 ? 'ErrorEcoCredit_credit_reason_required' : ($result === -3 ? 'ErrorEcoCredit_credit_insufficient' : 'Error')));
	}
	setEventMessages($message, null, $result > 0 ? 'mesgs' : 'errors');
	header('Location: '.$self.'#credits');
	exit;
}

/**
 * Select of the months a credit lasts
 *
 * @param	string	$name		Field name
 * @param	int		$selected	Selected months
 * @return	string
 */
function designtraceCreditMonthsSelect($name, $selected = 0)
{
	global $langs;

	$out = '<select name="'.dol_escape_htmltag($name).'" class="flat">';
	foreach (DesignTraceCredits::EXPIRY_CHOICES as $months) {
		$out .= '<option value="'.$months.'"'.($months === (int) $selected ? ' selected' : '').'>'.($months === 0 ? dol_escape_htmltag($langs->trans('CreditNeverExpires')) : dol_escape_htmltag($langs->trans('CreditMonthsN', $months))).'</option>';
	}
	return $out.'</select>';
}

/**
 * Credits block of a design card: CAD rate, balance, grants and the whole ledger (internal notes included)
 *
 * @param	Design	$design		Design
 * @return	void
 */
function designtracePrintCredits($design)
{
	global $db, $langs, $user, $form;

	$langs->load('designtrace@designtrace');
	if (!$user->hasRight('designtrace', 'credit', 'read') || (int) $design->fk_soc <= 0) {
		return;
	}
	$canwrite = $user->hasRight('designtrace', 'credit', 'write');
	$canadjust = $user->hasRight('designtrace', 'credit', 'adjust');
	$url = $_SERVER['PHP_SELF'].'?id='.((int) $design->id);
	$data = DesignTraceCredits::fetchCredits($db, (int) $design->id, true);
	if (!$data) {
		return;
	}

	print '<a name="credits"></a>';
	print load_fiche_titre($langs->trans('RevisionCredits'), '', 'fa-ticket-alt');
	print '<div class="fichecenter"><table class="border centpercent tableforfield">';
	print '<tr><td class="titlefield">'.$langs->trans('CadTier').'</td><td>';
	if ($canwrite) {
		print '<form method="POST" action="'.$url.'#credits" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="credit_tier">';
		print '<select name="credit_tier" class="flat">';
		foreach (array(0 => 'CreditTierNone', 1 => 'CreditTierHobby', 2 => 'CreditTierCommercial') as $value => $key) {
			print '<option value="'.$value.'"'.($data['tier'] === DesignTraceCredits::tierCode($value) ? ' selected' : '').'>'.dol_escape_htmltag($langs->trans($key)).'</option>';
		}
		print '</select> <input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('Save')).'"></form>';
	} else {
		print dol_escape_htmltag($data['tier'] !== '' ? $data['tier'] : $langs->trans('CreditTierNone'));
	}
	if (!empty($data['rate'])) {
		print ' <span class="opacitymedium">'.dol_escape_htmltag($data['rate']['label']).($data['rate']['amount_excl_tax'] !== null ? ' '.price($data['rate']['amount_excl_tax'], 0, $langs, 1, -1, -1, $data['rate']['currency'] ?? '').' / '.$langs->trans('Hour') : '').'</span>';
	}
	print '</td></tr>';
	print '<tr><td>'.$langs->trans('CreditBalance').'</td><td><b>'.((int) $data['balance']).'</b>';
	if ($data['waiting_for_delivery']) {
		print ' <span class="opacitymedium">'.$langs->trans('CreditWaitingForDelivery').'</span>';
	}
	if (!empty($data['next_expiry'])) {
		print ' <span class="opacitymedium">'.$langs->trans('CreditNextExpiry', $data['next_expiry']['credits'], dol_print_date($data['next_expiry']['date'], 'day')).'</span>';
	}
	print '</td></tr></table></div><br>';

	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Grant').'</td><td class="right">'.$langs->trans('CreditRemaining').'</td><td>'.$langs->trans('GrantExpiry').'</td><td>'.$langs->trans('Status').'</td><td>'.$langs->trans('Note').'</td></tr>';
	foreach ($data['grants'] as $grant) {
		print '<tr class="oddeven"><td>#'.$grant['id'].' · '.((int) $grant['credits']).' · '.dol_escape_htmltag($grant['source']).'</td><td class="right">'.((int) $grant['remaining']).'</td><td class="nowraponall">';
		if ($canwrite && $grant['status'] !== 'ended') {
			print '<form method="POST" action="'.$url.'#credits" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="credit_expiry"><input type="hidden" name="grantid" value="'.$grant['id'].'">';
			print $form->selectDate($grant['date_expiry'] ?: -1, 'expiry', 0, 0, 1, '', 1, 0);
			print ' <input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('Save')).'"></form>';
		} else {
			print $grant['date_expiry'] ? dol_print_date($grant['date_expiry'], 'day') : '<span class="opacitymedium">'.$langs->trans('GrantNoExpiry').'</span>';
		}
		print '</td><td>'.dol_escape_htmltag($grant['status']).'</td><td>'.dol_escape_htmltag($grant['note']).(!empty($grant['internal_note']) ? ' <i class="opacitymedium">('.dol_escape_htmltag($grant['internal_note']).')</i>' : '').'</td></tr>';
	}
	if (empty($data['grants'])) {
		print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('GrantsNone').'</span></td></tr>';
	}
	print '</table></div><br>';

	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Type').'</td><td class="right">'.$langs->trans('Amount').'</td><td class="right">'.$langs->trans('CreditBalance').'</td><td>ECO</td><td>'.$langs->trans('Note').'</td><td>'.$langs->trans('CreditInternalNote').'</td></tr>';
	foreach ($data['ledger'] as $row) {
		print '<tr class="oddeven"><td class="nowraponall">'.dol_print_date($row['date'], 'dayhour').'</td><td>'.dol_escape_htmltag($row['type']).'</td><td class="right">'.($row['amount'] > 0 ? '+' : '').((int) $row['amount']).'</td><td class="right">'.((int) $row['balance_after']).'</td>';
		print '<td>'.($row['eco_id'] ? '<a href="'.dol_buildpath('/designtrace/changeorder_card.php', 1).'?id='.((int) $row['eco_id']).'">'.dol_escape_htmltag($row['eco_ref']).'</a>' : '').'</td>';
		print '<td>'.dol_escape_htmltag($row['note']).'</td><td class="opacitymedium">'.dol_escape_htmltag($row['internal_note']).' <small>'.dol_escape_htmltag($row['actor']).'</small></td></tr>';
	}
	if (empty($data['ledger'])) {
		print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">'.$langs->trans('CreditLedgerEmpty').'</span></td></tr>';
	}
	print '</table></div>';

	foreach (array('credit_grant' => array($canwrite, 'CreditGrantAdd'), 'credit_adjust' => array($canadjust, 'CreditAdjust')) as $act => $conf) {
		if (!$conf[0]) {
			continue;
		}
		print '<form method="POST" action="'.$url.'#credits" class="margintoponly"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="'.$act.'">';
		print '<input type="number" name="credit_amount" class="width75" '.($act === 'credit_grant' ? 'min="1" ' : '').'step="1" placeholder="'.dol_escape_htmltag($langs->trans('Amount')).'"> '.designtraceCreditMonthsSelect('credit_months');
		print ' <input type="text" name="credit_public" class="minwidth200" maxlength="255" placeholder="'.dol_escape_htmltag($langs->trans('CreditPublicNote')).'">';
		print ' <input type="text" name="credit_internal" class="minwidth150" maxlength="255" placeholder="'.dol_escape_htmltag($langs->trans('CreditInternalNote')).'">';
		print ' <input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans($conf[1])).'"></form>';
	}
	print '<br>';
}

/**
 * Credits block of an ECO card: for a customer request on a delivered design, what was asked, the decision form
 * while the ECO is a draft, and the outcome after; "Charge a credit for this round" once decided
 *
 * @param	ChangeOrder	$eco	ECO
 * @return	void
 */
function designtracePrintEcoCredit($eco)
{
	global $db, $langs, $user;

	$langs->load('designtrace@designtrace');
	$ctx = DesignTraceCredits::getEcoContext($eco);
	if (!$ctx) {
		return;
	}
	$info = $eco->getRequestInfo();
	$sizenames = array(DesignTraceCredits::SIZE_MINOR => 'CreditSizeMinor', DesignTraceCredits::SIZE_MAJOR => 'CreditSizeMajor');
	$balance = DesignTraceCredits::getBalance($db, (int) $ctx['design_id']);
	DesignTraceCredits::sync($db, (int) $ctx['design_id']);
	$balance = DesignTraceCredits::getBalance($db, (int) $ctx['design_id']);

	print '<a name="credits"></a>';
	print load_fiche_titre($langs->trans('RevisionCredits'), '', 'fa-ticket-alt');
	print '<table class="border centpercent tableforfield">';
	if ($ctx['type'] === 'change') {
		print '<tr><td class="titlefield">'.$langs->trans('CreditSizeRequested').'</td><td>'.(isset($sizenames[(int) $info->credit_size_requested]) ? $langs->trans($sizenames[(int) $info->credit_size_requested]) : '').'</td></tr>';
	}
	print '<tr><td>'.$langs->trans('CreditBalance').'</td><td>'.((int) $balance).'</td></tr>';
	if (!empty($info->credit_rate_label)) {
		print '<tr><td>'.$langs->trans('CreditRateShown').'</td><td>'.dol_escape_htmltag($info->credit_rate_label).($info->credit_rate_amount !== null ? ' '.price((float) $info->credit_rate_amount).' / '.$langs->trans('Hour') : '').'</td></tr>';
	}
	print '<tr><td>'.$langs->trans('CreditOutcome').'</td><td>'.$langs->trans('CreditOutcome_'.$ctx['outcome']).'</td></tr>';
	print '</table><br>';

	$canapprove = $user->hasRight('designtrace', 'changeorder', 'approve');
	$self = $_SERVER['PHP_SELF'].'?id='.((int) $eco->id);
	if ((int) $eco->status === ChangeOrder::STATUS_DRAFT && $ctx['outcome'] === DesignTraceCredits::OUTCOME_NONE && $canapprove) {
		$suggest = ($ctx['type'] === 'change' ? DesignTraceCredits::suggestOutcome($db, (int) $ctx['design_id'], (int) ($info->credit_size_requested ?: DesignTraceCredits::SIZE_MAJOR)) : 'none');
		print '<form method="POST" action="'.$self.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="eco_approve">';
		print '<input type="hidden" name="credit_design" value="'.((int) $ctx['design_id']).'">';
		print $langs->trans('CreditDecision').' <select name="credit_outcome" class="flat">';
		$options = ($ctx['type'] === 'change' ? array('use' => 'CreditOutcomeUse', 'bill' => 'CreditOutcomeBill', 'waive' => 'CreditOutcomeWaive') : array('none' => 'CreditOutcomeFree', 'bill' => 'CreditOutcomeBill', 'waive' => 'CreditOutcomeWaive'));
		foreach ($options as $value => $key) {
			$selected = ($value === 'use' && $suggest === DesignTraceCredits::OUTCOME_CREDIT) || ($value === 'bill' && $suggest === DesignTraceCredits::OUTCOME_BILLED) || ($value === 'none' && $ctx['type'] === 'problem');
			print '<option value="'.$value.'"'.($selected ? ' selected' : '').'>'.dol_escape_htmltag($langs->trans($key)).'</option>';
		}
		print '</select>';
		if ($ctx['type'] === 'change') {
			print ' <select name="credit_size" class="flat"><option value="minor"'.((int) $info->credit_size_requested === DesignTraceCredits::SIZE_MINOR ? ' selected' : '').'>'.dol_escape_htmltag($langs->trans('CreditSizeMinor')).'</option>';
			print '<option value="major"'.((int) $info->credit_size_requested !== DesignTraceCredits::SIZE_MINOR ? ' selected' : '').'>'.dol_escape_htmltag($langs->trans('CreditSizeMajor')).'</option></select>';
		}
		print ' <input type="text" name="credit_public" class="minwidth250" maxlength="255" placeholder="'.dol_escape_htmltag($langs->trans('CreditPublicNote')).'">';
		print ' <input type="text" name="credit_internal" class="minwidth150" maxlength="255" placeholder="'.dol_escape_htmltag($langs->trans('CreditInternalNote')).'">';
		print ' <input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('EcoApprove')).'"></form><br>';
	} elseif ($ctx['outcome'] !== DesignTraceCredits::OUTCOME_NONE && $canapprove && $eco->isOpen()) {
		print '<form method="POST" action="'.$self.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="eco_chargeround">';
		print '<input type="text" name="credit_public" class="minwidth250" maxlength="255" placeholder="'.dol_escape_htmltag($langs->trans('CreditPublicNote')).'"> ';
		print '<input type="text" name="credit_internal" class="minwidth150" maxlength="255" placeholder="'.dol_escape_htmltag($langs->trans('CreditInternalNote')).'"> ';
		print '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('CreditChargeRound')).'"></form><br>';
	}
}
