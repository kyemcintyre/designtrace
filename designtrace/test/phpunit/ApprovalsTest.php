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
 * \file        designtrace/test/phpunit/ApprovalsTest.php
 * \ingroup     designtrace
 * \brief       Needs-customer-approval flag, release block, approvals per ECO or design, request changes, approver role
 */

dol_include_once('/designtrace/class/approvals.class.php');

class ApprovalsTest extends DesignTraceTestCase
{
	private function inReview($revision)
	{
		$revision->fetch($revision->id);
		$this->assertGreaterThan(0, $revision->validate($this->user), (string) $revision->error);
		$revision->fetch($revision->id);
		return $revision;
	}

	private function newEco($socid)
	{
		$eco = new ChangeOrder($this->db);
		$eco->label = 'ZZ eco';
		$eco->reason = ChangeOrder::REASON_CUSTOMER;
		$eco->fk_soc = $socid;
		$this->assertOk($eco->create($this->user), $eco, 'Create ECO');
		return $eco;
	}

	/**
	 * A customer with a design, three parts in review flagged for approval and reachable through a quote
	 */
	private function scenario($flagged = true)
	{
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$quote = $this->newQuote($customer->id);
		$revs = array();
		foreach (array('a', 'b', 'c') as $name) {
			$part = $this->newComponent($design, Component::TYPE_PART, 'ZZ '.$name);
			$rev = $this->newRevision($part);
			if ($flagged) {
				$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_componentrevision SET needs_approval = 1 WHERE rowid = ".((int) $rev->id));
			}
			$rev = $this->inReview($rev);
			$this->assertGreaterThan(0, $this->linkDoc($rev, 'propal', $quote->id), $rev->error);
			$revs[$name] = $rev;
		}
		return array($customer, $design, $revs);
	}

	public function testFlagDefaultsOnForProofsAndBlocksRelease()
	{
		$design = $this->newDesign(array(), true);
		$proof = $this->newRevision($this->newComponent($design, Component::TYPE_DRAWING, 'ZZ proof', Component::DRAWING_PROOF));
		$tds = $this->newRevision($this->newComponent($design, Component::TYPE_DRAWING, 'ZZ tds', Component::DRAWING_TDS));
		$part = $this->newRevision($this->newComponent($design));
		$this->assertSame(1, (int) $proof->needs_approval, 'Proof drawings default on');
		$this->assertSame(0, (int) $tds->needs_approval);
		$this->assertSame(0, (int) $part->needs_approval);

		$proof = $this->inReview($proof);
		$this->assertLessThan(0, $proof->release($this->user), 'Blocked until the customer approves');
		$this->assertStringContainsString($proof->ref, $proof->error);
		$proof->needs_approval = 0;
		$this->assertGreaterThan(0, $proof->update($this->user), $proof->error);
		$this->assertGreaterThan(0, $proof->release($this->user), $proof->error);

		// A clone keeps the flag; any revision can be flagged
		$part->needs_approval = 1;
		$this->assertGreaterThan(0, $part->update($this->user));
		$part = $this->inReview($part);
		$this->assertLessThan(0, $part->release($this->user));
	}

	public function testOneApprovalCoversAllFlaggedRevisionsOfTheScope()
	{
		$A = DesignTraceApprovals::class;
		list($customer, $design, $revs) = $this->scenario();
		$other = $this->newDesign(array('fk_soc' => $customer->id), true);
		$rx = $this->inReview($this->newRevision($this->newComponent($other)));
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_componentrevision SET needs_approval = 1 WHERE rowid = ".((int) $rx->id));
		$quote2 = $this->newQuote($customer->id);
		$this->linkDoc($rx, 'propal', $quote2->id);
		$err = '';

		$pending = $A::fetchPending($this->db, $customer->id);
		$this->assertCount(2, $pending, 'One entry per design');
		$byid = array_column($pending, null, 'id');
		$this->assertCount(3, $byid[$design->id]['revisions']);
		$this->assertSame('design', $byid[$design->id]['scope']);
		$this->assertSame($design->ref, $byid[$design->id]['ref']);

		$this->assertSame(-1, $A::approve($this->db, $this->user, $this->newCustomer('ZZ Other')->id, 'design', $design->id, 'user', '', 'api', 'x', $err), 'Other customer reaches nothing');
		$this->assertSame(-1, $A::approve($this->db, $this->user, $customer->id, 'bogus', $design->id, 'user', '', 'api', 'x', $err));
		$done = $A::approve($this->db, $this->user, $customer->id, 'design', $design->id, 'user', 'All good', 'api', 'portal:ann', $err, '198.51.100.7');
		$this->assertIsArray($done, $err);
		$this->assertCount(3, $done);
		foreach ($revs as $rev) {
			$rev->fetch($rev->id);
			$this->assertSame(1, (int) $rev->customer_approval);
			$this->assertSame('portal:ann', $rev->customer_approval_by);
			$this->assertGreaterThan(0, $this->release($rev)->id, 'Release now allowed');
			$this->assertSame(ComponentRevision::STATUS_RELEASED, (int) $rev->status);
		}
		$this->assertSame(3, $this->countRows("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."designtrace_approval WHERE element = 'design' AND fk_element = ".((int) $design->id)." AND decision = 1 AND ip = '198.51.100.7'"));
		$rx->fetch($rx->id);
		$this->assertSame(0, (int) $rx->customer_approval, 'Another design is its own scope');
		$this->assertCount(1, $A::fetchPending($this->db, $customer->id));
	}

	public function testEcoScopeSeparatesRevisionsOfAnEco()
	{
		$A = DesignTraceApprovals::class;
		list($customer, $design, $revs) = $this->scenario();
		$eco = $this->newEco($customer->id);
		foreach (array('a', 'b') as $name) {
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_componentrevision SET fk_eco = ".((int) $eco->id)." WHERE rowid = ".((int) $revs[$name]->id));
		}
		$err = '';
		$pending = $A::fetchPending($this->db, $customer->id);
		$this->assertCount(2, $pending);
		$scopes = array_column($pending, null, 'scope');
		$this->assertCount(2, $scopes['eco']['revisions']);
		$this->assertSame($eco->ref, $scopes['eco']['ref']);
		$this->assertSame(1, $scopes['eco']['round']);
		$this->assertCount(1, $scopes['design']['revisions'], 'Revisions with an ECO leave the design scope');

		$this->assertCount(2, $A::approve($this->db, $this->user, $customer->id, 'eco', $eco->id, 'user', '', 'api', 'x', $err));
		$revs['c']->fetch($revs['c']->id);
		$this->assertSame(0, (int) $revs['c']->customer_approval);
	}

	public function testRequestChangesOpensOrReopensTheEcoAsANewRound()
	{
		$A = DesignTraceApprovals::class;
		list($customer, $design, $revs) = $this->scenario();
		$err = '';
		$this->assertSame(-1, $A::requestChanges($this->db, $this->user, $customer->id, 'design', $design->id, array(), '  ', 'user', 'api', 'x', $err), 'Comment required');
		$this->assertSame(-1, $A::requestChanges($this->db, $this->user, $customer->id, 'design', $design->id, array(999999), 'x', 'user', 'api', 'x', $err), 'Revision not in scope');

		// No ECO yet: a draft ECO is opened for the named revisions only
		$r = $A::requestChanges($this->db, $this->user, $customer->id, 'design', $design->id, array($revs['a']->id), 'Hole too small', 'user', 'api', 'portal:ann', $err);
		$this->assertIsArray($r, $err);
		$this->assertSame(1, $r['round']);
		$this->assertSame(array($revs['a']->id), $r['revisions']);
		$eco = new ChangeOrder($this->db);
		$this->assertGreaterThan(0, $eco->fetch($r['eco']));
		$this->assertSame(ChangeOrder::REASON_CUSTOMER, (int) $eco->reason);
		$this->assertSame(ChangeOrder::STATUS_DRAFT, (int) $eco->status);
		$this->assertSame(1, $eco->countAffectedComponents());
		$this->assertStringContainsString('Hole too small', $eco->description);
		$revs['a']->fetch($revs['a']->id);
		$this->assertSame(2, (int) $revs['a']->customer_approval);
		$this->assertSame((int) $eco->id, (int) $revs['a']->fk_eco);
		$revs['b']->fetch($revs['b']->id);
		$this->assertSame(0, (int) $revs['b']->customer_approval, 'Not named: untouched');

		// The same ECO again: a new round, one more timeline entry
		$r2 = $A::requestChanges($this->db, $this->user, $customer->id, 'eco', $eco->id, array(), 'Also the slot', 'user', 'api', 'portal:bob', $err);
		$this->assertIsArray($r2, $err);
		$this->assertSame($eco->id, $r2['eco']);
		$this->assertSame(2, $r2['round']);
		$eco->fetch($eco->id);
		$timeline = $eco->fetchTimeline();
		$this->assertSame(array('opened', 'changes_requested', 'changes_requested'), array_column($timeline, 'type'));
		$this->assertSame(array(1, 1, 2), array_map('intval', array_column($timeline, 'round')));
		$this->assertSame('portal:bob', $timeline[2]->actor);
		$this->assertSame('Also the slot', $timeline[2]->comment);
		$pending = $A::fetchPending($this->db, $customer->id);
		$this->assertSame(2, array_column($pending, null, 'scope')['eco']['round']);

		// An approved ECO goes back to Draft for the new round
		$eco->approve($this->user);
		$eco->fetch($eco->id);
		$this->assertSame(ChangeOrder::STATUS_APPROVED, (int) $eco->status);
		$this->assertIsArray($A::requestChanges($this->db, $this->user, $customer->id, 'eco', $eco->id, array(), 'And a third', 'user', 'api', 'portal:bob', $err), $err);
		$eco->fetch($eco->id);
		$this->assertSame(ChangeOrder::STATUS_DRAFT, (int) $eco->status);
		$this->assertSame(3, $eco->getRound());
	}

	public function testApproverRoleSetting()
	{
		$A = DesignTraceApprovals::class;
		list($customer, $design, $revs) = $this->scenario();
		$err = '';
		$this->assertSame(0, $A::getPolicy($this->db, $customer->id));
		$this->assertTrue($A::checkRole($this->db, $customer->id, 'user'));

		$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."societe_extrafields WHERE fk_object = ".((int) $customer->id));
		$this->db->query("INSERT INTO ".MAIN_DB_PREFIX."societe_extrafields (fk_object, designtrace_approvers) VALUES (".((int) $customer->id).", 1)");
		$this->assertSame(1, $A::getPolicy($this->db, $customer->id));
		$this->assertFalse($A::checkRole($this->db, $customer->id, 'user', $err));
		$this->assertNotEmpty($err);
		$this->assertFalse($A::checkRole($this->db, $customer->id, ''), 'Unknown role is refused');
		$this->assertTrue($A::checkRole($this->db, $customer->id, 'ADMIN'));

		$this->assertSame(-2, $A::approve($this->db, $this->user, $customer->id, 'design', $design->id, 'user', '', 'api', 'x', $err), 'Distinct code for the role');
		$this->assertSame(-2, $A::requestChanges($this->db, $this->user, $customer->id, 'design', $design->id, array(), 'x', 'user', 'api', 'x', $err));
		$this->assertIsArray($A::approve($this->db, $this->user, $customer->id, 'design', $design->id, 'admin', '', 'api', 'x', $err), $err);
	}
}
