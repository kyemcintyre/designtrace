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
 * \file        designtrace/test/phpunit/CreditsTest.php
 * \ingroup     designtrace
 * \brief       Revision credits: dormant until delivery, ledger balance, expiry and the decision on an ECO
 */

dol_include_once('/designtrace/class/credits.class.php');
dol_include_once('/designtrace/class/grant.class.php');
dol_include_once('/designtrace/class/changerequests.class.php');

class CreditsTest extends DesignTraceTestCase
{
	/**
	 * A validated design for a customer with a default grant of $credits, delivered through a grant
	 *
	 * @return array{0:object,1:object,2:object}	customer, design, delivered revision
	 */
	private function deliveredDesign($credits = 2, $delivered = true)
	{
		$this->setConst('DESIGNTRACE_CREDITS_DEFAULT_TIER', 1);
		$this->setConst('DESIGNTRACE_CREDITS_DEFAULT_HOBBY', $credits);
		$this->setConst('DESIGNTRACE_CREDITS_DEFAULT_MONTHS', 3);
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$part = $this->newComponent($design);
		$rev = $this->newRevision($part);
		$this->release($rev);
		if ($delivered) {
			DesignTraceGrant::create($this->db, $this->user, $customer->id, $design->id);
		}
		return array($customer, $design, $rev);
	}

	public function testDefaultGrantIsDormantUntilFirstDelivery()
	{
		list($customer, $design) = $this->deliveredDesign(2, false);
		$this->assertSame(0, DesignTraceCredits::getBalance($this->db, $design->id), 'Nothing to spend before delivery');
		$summary = DesignTraceCredits::fetchSummary($this->db, $design->id);
		$this->assertTrue($summary['waiting_for_delivery']);

		DesignTraceGrant::create($this->db, $this->user, $customer->id, $design->id);
		DesignTraceCredits::sync($this->db, $design->id);
		$this->assertSame(2, DesignTraceCredits::getBalance($this->db, $design->id));
		DesignTraceCredits::sync($this->db, $design->id);
		$this->assertSame(2, DesignTraceCredits::getBalance($this->db, $design->id), 'Syncing again grants nothing more');
	}

	public function testNoTierMeansOutsideTheScheme()
	{
		$this->setConst('DESIGNTRACE_CREDITS_DEFAULT_TIER', 0);
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$this->assertFalse(DesignTraceCredits::isEnabled($this->db, $design->id));
	}

	public function testAdjustNeedsAReasonAndKeepsBalanceAsTheLedgerSum()
	{
		list($customer, $design) = $this->deliveredDesign(1);
		DesignTraceCredits::sync($this->db, $design->id);
		$this->assertSame(-2, DesignTraceCredits::adjust($this->db, $design->id, 3, 0, '', 'internal', 'staff:test'), 'A public reason is required');
		$this->assertGreaterThan(0, DesignTraceCredits::adjust($this->db, $design->id, 3, 0, 'Goodwill', 'internal', 'staff:test'));
		$this->assertSame(4, DesignTraceCredits::getBalance($this->db, $design->id));
		$this->assertGreaterThan(0, DesignTraceCredits::adjust($this->db, $design->id, -1, 0, 'Correction', '', 'staff:test'));
		$this->assertSame(3, DesignTraceCredits::getBalance($this->db, $design->id));
	}

	public function testCustomerLedgerNeverShowsInternalNotes()
	{
		list($customer, $design) = $this->deliveredDesign(1);
		DesignTraceCredits::adjust($this->db, $design->id, 1, 0, 'Public reason', 'secret internal', 'staff:kye');
		$customerView = json_encode(DesignTraceCredits::fetchCredits($this->db, $design->id, false));
		$this->assertStringNotContainsString('secret internal', $customerView);
		$this->assertStringNotContainsString('staff:kye', $customerView);
		$this->assertStringContainsString('Public reason', $customerView);
		$staffView = json_encode(DesignTraceCredits::fetchCredits($this->db, $design->id, true));
		$this->assertStringContainsString('secret internal', $staffView);
	}

	public function testExpiredGrantsEndAndLeaveTheBalance()
	{
		list($customer, $design) = $this->deliveredDesign(2);
		DesignTraceCredits::sync($this->db, $design->id);
		$data = DesignTraceCredits::fetchCredits($this->db, $design->id, true);
		$grantid = $data['grants'][0]['id'];
		$this->assertGreaterThan(0, DesignTraceCredits::setExpiry($this->db, $grantid, dol_now() - 86400, 'staff:test', 'Ended early', ''));
		DesignTraceCredits::sync($this->db, $design->id);
		$this->assertSame(0, DesignTraceCredits::getBalance($this->db, $design->id));
		$this->assertSame(1, $this->countRows("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."designtrace_creditledger WHERE fk_design = ".((int) $design->id)." AND type = 'expire'"));
		DesignTraceCredits::sync($this->db, $design->id);
		$this->assertSame(1, $this->countRows("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."designtrace_creditledger WHERE fk_design = ".((int) $design->id)." AND type = 'expire'"), 'Expiry is written once');
	}

	public function testChangeRequestNeedsSizeAndRateAcknowledgementWhenBilled()
	{
		list($customer, $design, $rev) = $this->deliveredDesign(0);
		$error = '';
		$this->assertSame(-1, DesignTraceChangeRequests::report($this->db, $this->user, $customer->id, 'change', $rev->id, 'Move hole', 'Please', 0, 'site:c@example.com', $error));
		$this->assertSame('size_required', $error);
		$error = '';
		$this->assertSame(-1, DesignTraceChangeRequests::report($this->db, $this->user, $customer->id, 'change', $rev->id, 'Move hole', 'Please', 0, 'site:c@example.com', $error, null, 'huge'));
		$this->assertSame('bad_size', $error);
		$error = '';
		$this->assertSame(-1, DesignTraceChangeRequests::report($this->db, $this->user, $customer->id, 'change', $rev->id, 'Move hole', 'Please', 0, 'site:c@example.com', $error, null, 'minor', false));
		$this->assertSame('rate_ack_required', $error, 'No credits left, so it would be billed');
		$error = '';
		$result = DesignTraceChangeRequests::report($this->db, $this->user, $customer->id, 'change', $rev->id, 'Move hole', 'Please', 0, 'site:c@example.com', $error, null, 'minor', true);
		$this->assertIsArray($result, $error);
	}

	public function testOutcomeSuggestionFollowsBalanceAndSize()
	{
		list($customer, $design) = $this->deliveredDesign(1);
		DesignTraceCredits::sync($this->db, $design->id);
		$this->assertSame(DesignTraceCredits::OUTCOME_CREDIT, DesignTraceCredits::suggestOutcome($this->db, $design->id, DesignTraceCredits::SIZE_MINOR));
		$this->assertSame(DesignTraceCredits::OUTCOME_BILLED, DesignTraceCredits::suggestOutcome($this->db, $design->id, DesignTraceCredits::SIZE_MAJOR));
		DesignTraceCredits::adjust($this->db, $design->id, -1, 0, 'Used elsewhere', '', 'staff:test');
		$this->assertSame(DesignTraceCredits::OUTCOME_BILLED, DesignTraceCredits::suggestOutcome($this->db, $design->id, DesignTraceCredits::SIZE_MINOR), 'Minor at zero balance is billed');
	}
}
