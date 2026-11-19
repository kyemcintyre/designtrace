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
		return array($customer, $design, $revs, $quote);
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
		list($customer, $design, $revs, $quote) = $this->scenario();
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

		$revs['a']->fetch($revs['a']->id);
		$this->assertSame(ComponentRevision::STATUS_DECLINED, (int) $revs['a']->status, 'Changes requested close the revision');
		$revs['b']->fetch($revs['b']->id);
		$this->assertSame(ComponentRevision::STATUS_INREVIEW, (int) $revs['b']->status, 'Not named: still in review');
		$this->assertSame(-1, $A::approve($this->db, $this->user, $customer->id, 'eco', $eco->id, 'user', '', 'api', 'x', $err), 'A declined revision is no longer waiting');
		$this->assertSame(0, $revs['a']->release($this->user), 'Never released');

		// The fix is a new revision of the declined one: it stays under the ECO, so the next round is decided on it
		$a2 = $revs['a']->createFromClone($this->user, $revs['a']->id);
		$this->assertIsObject($a2, (string) $revs['a']->error);
		$this->assertSame((int) $eco->id, (int) $a2->fk_eco, 'The follow-up stays under the ECO');
		$a2 = $this->inReview($a2);
		$this->assertSame(1, (int) $a2->needs_approval, 'The flag carries over');
		$this->assertGreaterThan(0, $this->linkDoc($a2, 'propal', $quote->id), $a2->error);
		$pending = $A::fetchPending($this->db, $customer->id);
		$this->assertSame(1, array_column($pending, null, 'scope')['eco']['round'], 'Round 1 until the customer asks again');
		$this->assertCount(1, array_column($pending, null, 'scope')['eco']['revisions'], 'Only the follow-up waits');

		// The same ECO again: a new round, one more timeline entry
		$r2 = $A::requestChanges($this->db, $this->user, $customer->id, 'eco', $eco->id, array(), 'Also the slot', 'user', 'api', 'portal:bob', $err);
		$this->assertIsArray($r2, $err);
		$this->assertSame($eco->id, $r2['eco']);
		$this->assertSame(2, $r2['round']);
		$a2->fetch($a2->id);
		$this->assertSame(ComponentRevision::STATUS_DECLINED, (int) $a2->status);
		$eco->fetch($eco->id);
		$timeline = $eco->fetchTimeline();
		$this->assertSame(array('opened', 'changes_requested', 'changes_requested'), array_column($timeline, 'type'));
		$this->assertSame(array(1, 1, 2), array_map('intval', array_column($timeline, 'round')));
		$this->assertSame('portal:bob', $timeline[2]->actor);
		$this->assertSame('Also the slot', $timeline[2]->comment);

		// An approved ECO goes back to Draft for the new round
		$eco->approve($this->user);
		$eco->fetch($eco->id);
		$this->assertSame(ChangeOrder::STATUS_APPROVED, (int) $eco->status);
		$a3 = $a2->createFromClone($this->user, $a2->id);
		$this->assertIsObject($a3, (string) $a2->error);
		$a3 = $this->inReview($a3);
		$this->assertGreaterThan(0, $this->linkDoc($a3, 'propal', $quote->id), $a3->error);
		$this->assertIsArray($A::requestChanges($this->db, $this->user, $customer->id, 'eco', $eco->id, array(), 'And a third', 'user', 'api', 'portal:bob', $err), $err);
		$eco->fetch($eco->id);
		$this->assertSame(ChangeOrder::STATUS_DRAFT, (int) $eco->status);
		$this->assertSame(3, $eco->getRound());
	}

	public function testTheFollowUpOfADeclinedRevisionKeepsAnOpenEcoOnly()
	{
		$A = DesignTraceApprovals::class;
		list($customer, $design, $revs) = $this->scenario();
		$err = '';
		$r = $A::requestChanges($this->db, $this->user, $customer->id, 'design', $design->id, array($revs['a']->id), 'Hole too small', 'user', 'api', 'portal:ann', $err);
		$this->assertIsArray($r, $err);
		$revs['a']->fetch($revs['a']->id);
		$this->assertSame(ComponentRevision::STATUS_DECLINED, (int) $revs['a']->status);
		$this->assertTrue($revs['a']->isLocked(), 'A declined revision is read-only');

		// The ECO the request opened is cancelled: the follow-up starts without an ECO instead of failing
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_changeorder SET status = ".ChangeOrder::STATUS_CANCELED." WHERE rowid = ".((int) $r['eco']));
		$a2 = $revs['a']->createFromClone($this->user, $revs['a']->id);
		$this->assertIsObject($a2, (string) $revs['a']->error);
		$this->assertEmpty($a2->fk_eco, 'A cancelled ECO is not carried over');
	}

	public function testStaffRecordsAnApprovalOnBehalfOfTheCustomer()
	{
		$A = DesignTraceApprovals::class;
		list($customer, $design, $revs) = $this->scenario();
		$a = $revs['a'];
		$err = '';

		$clerk = $this->newUser(array('componentrevision/read', 'componentrevision/write'));
		$this->assertSame(-1, $A::recordOnBehalf($this->db, $clerk, $a->id, 1, 'phone, Ann', '', $err), 'Needs the release right');
		$this->assertSame(-1, $A::recordOnBehalf($this->db, $this->user, $a->id, 1, '  ', '', $err), 'Says how it was received');
		$this->assertSame(-1, $A::recordOnBehalf($this->db, $this->user, $a->id, 0, 'phone', '', $err), 'Unknown decision');
		$this->assertSame(-1, $A::recordOnBehalf($this->db, $this->user, 999999, 1, 'phone', '', $err), 'Unknown revision');

		$done = $A::recordOnBehalf($this->db, $this->user, $a->id, 1, 'phone, Ann', 'Happy with it', $err);
		$this->assertSame(array((int) $a->id), $done, $err);
		$a->fetch($a->id);
		$this->assertSame(1, (int) $a->customer_approval);
		$this->assertSame('staff:'.$this->user->login, $a->customer_approval_by);
		$this->assertSame(ComponentRevision::STATUS_INREVIEW, (int) $a->status);
		$row = $this->db->fetch_object($this->db->query("SELECT channel, actor, comment, fk_soc, element, fk_element FROM ".MAIN_DB_PREFIX."designtrace_approval WHERE fk_revision = ".((int) $a->id)));
		$this->assertSame('staff', $row->channel);
		$this->assertSame((int) $customer->id, (int) $row->fk_soc);
		$this->assertSame('design', $row->element);
		$this->assertStringContainsString('phone, Ann', $row->comment);
		$this->assertStringContainsString('Happy with it', $row->comment);
		$this->assertGreaterThan(0, $this->release($a)->id, 'Release now allowed');

		// Only that revision was approved, and it cannot be decided again
		$revs['b']->fetch($revs['b']->id);
		$this->assertSame(0, (int) $revs['b']->customer_approval, 'One approval covers one revision when staff records it');
		$this->assertSame(-1, $A::recordOnBehalf($this->db, $this->user, $a->id, 2, 'phone', 'Wait', $err), 'Already approved');
	}

	public function testStaffApprovalCanCoverEverythingWaitingInTheScope()
	{
		$A = DesignTraceApprovals::class;
		list($customer, $design, $revs) = $this->scenario();
		$err = '';
		// a and b belong to an ECO, c to the design alone
		$eco = $this->newEco($customer->id);
		foreach (array('a', 'b') as $name) {
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_componentrevision SET fk_eco = ".((int) $eco->id)." WHERE rowid = ".((int) $revs[$name]->id));
		}

		$done = $A::recordOnBehalf($this->db, $this->user, $revs['a']->id, 1, 'phone, Ann', 'All fine', $err, true);
		$this->assertIsArray($done, $err);
		$this->assertEqualsCanonicalizing(array((int) $revs['a']->id, (int) $revs['b']->id), $done, 'The ECO scope, not the design alone');
		foreach (array('a', 'b') as $name) {
			$revs[$name]->fetch($revs[$name]->id);
			$this->assertSame(1, (int) $revs[$name]->customer_approval);
			$this->assertSame('staff:'.$this->user->login, $revs[$name]->customer_approval_by);
		}
		$revs['c']->fetch($revs['c']->id);
		$this->assertSame(0, (int) $revs['c']->customer_approval, 'Another scope is left alone');
		$this->assertSame(2, $this->countRows("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."designtrace_approval WHERE element = 'eco' AND fk_element = ".((int) $eco->id)." AND channel = 'staff' AND decision = 1"));

		// Without the option only the one revision is approved
		$this->assertSame(array((int) $revs['c']->id), $A::recordOnBehalf($this->db, $this->user, $revs['c']->id, 1, 'phone', '', $err, false), $err);

		// The option is for approvals: changes still name one revision
		list($customer2, $design2, $revs2) = $this->scenario();
		$r = $A::recordOnBehalf($this->db, $this->user, $revs2['a']->id, 2, 'phone', 'Thicker', $err, true);
		$this->assertIsArray($r, $err);
		$this->assertSame(array((int) $revs2['a']->id), $r['revisions']);
		$revs2['b']->fetch($revs2['b']->id);
		$this->assertSame(ComponentRevision::STATUS_INREVIEW, (int) $revs2['b']->status);
	}

	public function testStaffRecordsRequestedChangesOnBehalfOfTheCustomer()
	{
		$A = DesignTraceApprovals::class;
		list($customer, $design, $revs) = $this->scenario();
		$b = $revs['b'];
		$err = '';

		$this->assertSame(-1, $A::recordOnBehalf($this->db, $this->user, $b->id, 2, 'email, Ann', '  ', $err), 'Changes need a comment');
		$r = $A::recordOnBehalf($this->db, $this->user, $b->id, 2, 'email, Ann', 'Hole too small', $err);
		$this->assertIsArray($r, $err);
		$this->assertSame(array((int) $b->id), $r['revisions']);
		$this->assertSame(1, $r['round']);
		$b->fetch($b->id);
		$this->assertSame(ComponentRevision::STATUS_DECLINED, (int) $b->status, 'Declined like the customer\'s own request');
		$this->assertSame(2, (int) $b->customer_approval);
		$this->assertSame((int) $r['eco'], (int) $b->fk_eco);
		$eco = new ChangeOrder($this->db);
		$this->assertGreaterThan(0, $eco->fetch($r['eco']));
		$this->assertSame(ChangeOrder::REASON_CUSTOMER, (int) $eco->reason);
		$this->assertSame((int) $customer->id, (int) $eco->fk_soc);
		$this->assertStringContainsString('Hole too small', $eco->description);
		$this->assertSame(1, $this->countRows("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."designtrace_approval WHERE fk_revision = ".((int) $b->id)." AND channel = 'staff' AND decision = 2"));
		$this->assertSame(-1, $A::recordOnBehalf($this->db, $this->user, $b->id, 1, 'phone', '', $err), 'A declined revision cannot be decided again');
	}

	public function testStaffCanRecordForUnflaggedRevisionsButNotWithoutACustomer()
	{
		$A = DesignTraceApprovals::class;
		$err = '';
		list($customer, $design, $revs) = $this->scenario(false);

		// A part not flagged "needs customer approval" takes a decision and stays unflagged
		$a = $revs['a'];
		$this->assertSame(0, (int) $a->needs_approval);
		$this->assertSame(array((int) $a->id), $A::recordOnBehalf($this->db, $this->user, $a->id, 1, 'phone, Ann', '', $err), $err);
		$a->fetch($a->id);
		$this->assertSame(0, (int) $a->needs_approval);
		$this->assertSame(1, (int) $a->customer_approval);
		$this->assertSame(1, $this->countRows("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."designtrace_approval WHERE fk_revision = ".((int) $a->id)." AND channel = 'staff'"));
		$this->assertGreaterThan(0, $this->release($a)->id);

		// So its next revision is not held for the customer's approval
		$a2 = $a->createFromClone($this->user, $a->id);
		$this->assertIsObject($a2, (string) $a->error);
		$this->assertSame(0, (int) $a2->needs_approval);
		$a2 = $this->inReview($a2);
		$this->assertGreaterThan(0, $this->release($a2)->id, 'Released without an approval');
		$this->assertSame(array(), array_filter($A::fetchPending($this->db, $customer->id), function ($e) use ($a2) {
			return in_array((int) $a2->id, array_column($e['revisions'], 'id'), true);
		}), 'Never in the customer\'s pending approvals');

		// Changes on an unflagged one decline it the same way
		$b = $revs['b'];
		$this->assertIsArray($A::recordOnBehalf($this->db, $this->user, $b->id, 2, 'email', 'Thicker', $err), $err);
		$b->fetch($b->id);
		$this->assertSame(ComponentRevision::STATUS_DECLINED, (int) $b->status);
		$this->assertSame(0, (int) $b->needs_approval);

		// A refusal leaves an unflagged revision as it was
		$c = $revs['c'];
		$this->assertSame(-1, $A::recordOnBehalf($this->db, $this->user, $c->id, 2, 'email', '  ', $err));
		$c->fetch($c->id);
		$this->assertSame(0, (int) $c->needs_approval);

		$nobody = $this->newDesign(array(), true);
		$rev = $this->newRevision($this->newComponent($nobody));
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_componentrevision SET needs_approval = 1 WHERE rowid = ".((int) $rev->id));
		$rev = $this->inReview($rev);
		$this->assertSame(-1, $A::recordOnBehalf($this->db, $this->user, $rev->id, 1, 'phone', '', $err), 'No customer on the design');
		$this->assertNotEmpty($err);
	}

	public function testADeclinedRevisionIsFinal()
	{
		$A = DesignTraceApprovals::class;
		list($customer, $design, $revs) = $this->scenario();
		$err = '';
		$a = $revs['a'];
		// A draft assembly uses it before the customer declines it
		$asm = $this->newRevision($this->newComponent($design, Component::TYPE_ASSEMBLY, 'ZZ asm'));
		$link = $this->newLink($asm, $a, ComponentLink::TYPE_USES, 1);
		$this->assertGreaterThan(0, (int) $link->id, (string) $link->error);
		$this->assertIsArray($A::recordOnBehalf($this->db, $this->user, $a->id, 2, 'phone', 'Thicker', $err), $err);
		$a->fetch($a->id);
		$this->assertSame(ComponentRevision::STATUS_DECLINED, (int) $a->status);

		// Not reopened by unlock, even with the right and a reason
		$unlocker = $this->newUser(array('design/read', 'componentrevision/read', 'unlock/unlock'));
		$this->assertLessThan(0, $a->unlock($unlocker, 'Customer changed their mind'));
		$this->assertStringContainsString($a->ref, $a->error);
		$a->fetch($a->id);
		$this->assertSame(ComponentRevision::STATUS_DECLINED, (int) $a->status);

		// Not linkable to a document, with a message that says why
		$this->assertLessThan(0, $this->linkDoc($a, 'propal', $this->newQuote($customer->id)->id));
		$this->assertStringContainsString('declined', $a->error);

		// The draft parent pinned to it cannot be submitted for review
		$asm->fetch($asm->id);
		$this->assertLessThan(0, $asm->validate($this->user));
		$this->assertStringContainsString($a->ref, $asm->error);
	}

	public function testStaffWholeScopeLeavesOutConfidentialDesignsTheUserCannotSee()
	{
		$A = DesignTraceApprovals::class;
		$err = '';
		$customer = $this->newCustomer();
		$eco = $this->newEco($customer->id);
		$revs = array();
		$approver = $this->newUser(array('design/read', 'componentrevision/read', 'componentrevision/release'));
		foreach (array('open' => 0, 'secret' => 1) as $name => $confidential) {
			$d = $this->newDesign(array('fk_soc' => $customer->id, 'confidential' => $confidential), true);
			$rev = $this->newRevision($this->newComponent($d, Component::TYPE_PART, 'ZZ '.$name));
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_componentrevision SET needs_approval = 1, fk_eco = ".((int) $eco->id)." WHERE rowid = ".((int) $rev->id));
			$revs[$name] = $this->inReview($rev);
		}
		$done = $A::recordOnBehalf($this->db, $approver, $revs['open']->id, 1, 'phone', '', $err, true);
		$this->assertSame(array((int) $revs['open']->id), $done, $err);
		$revs['secret']->fetch($revs['secret']->id);
		$this->assertSame(0, (int) $revs['secret']->customer_approval, 'Not approved by someone who cannot see it');
		$this->assertSame(-1, $A::recordOnBehalf($this->db, $approver, $revs['secret']->id, 1, 'phone', '', $err), 'Nor directly');
	}

	/**
	 * A proof in review depicting: p (in review), flagged (in review, waiting on its own decision),
	 * released, shared (in review, also used by another assembly in review), and an assembly in review
	 * that uses part deep (in review), and external (in review, a part of another customer's design)
	 */
	private function proofTree()
	{
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$r = array();
		foreach (array('p', 'flagged', 'shared', 'deep') as $name) {
			$r[$name] = $this->newRevision($this->newComponent($design, Component::TYPE_PART, 'ZZ '.$name));
		}
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_componentrevision SET needs_approval = 1 WHERE rowid = ".((int) $r['flagged']->id));
		foreach (array('p', 'flagged', 'shared', 'deep') as $name) {
			$r[$name] = $this->inReview($r[$name]);
		}
		$r['released'] = $this->newRevision($this->newComponent($design, Component::TYPE_PART, 'ZZ released'), true);
		$r['asm'] = $this->newRevision($this->newComponent($design, Component::TYPE_ASSEMBLY, 'ZZ asm'));
		$this->assertGreaterThan(0, (int) $this->newLink($r['asm'], $r['deep'], ComponentLink::TYPE_USES, 1)->id);
		$r['asm'] = $this->inReview($r['asm']);
		$r['other'] = $this->newRevision($this->newComponent($design, Component::TYPE_ASSEMBLY, 'ZZ other'));
		$this->assertGreaterThan(0, (int) $this->newLink($r['other'], $r['shared'], ComponentLink::TYPE_USES, 1)->id);
		$r['other'] = $this->inReview($r['other']);
		$elsewhere = $this->newDesign(array('fk_soc' => $this->newCustomer('ZZ Someone else')->id), true);
		$r['external'] = $this->inReview($this->newRevision($this->newComponent($elsewhere, Component::TYPE_PART, 'ZZ external')));
		$r['proof'] = $this->newRevision($this->newComponent($design, Component::TYPE_DRAWING, 'ZZ proof', Component::DRAWING_PROOF));
		foreach (array('p', 'flagged', 'released', 'shared', 'asm', 'external') as $name) {
			$this->assertGreaterThan(0, (int) $this->newLink($r['proof'], $r[$name], ComponentLink::TYPE_DEPICTS)->id, $name);
		}
		$r['proof'] = $this->inReview($r['proof']);
		return array($customer, $r);
	}

	public function testDecliningARevisionDeclinesWhatItLinksToThatIsStillInReview()
	{
		$A = DesignTraceApprovals::class;
		list($customer, $r) = $this->proofTree();
		$err = '';
		$res = $A::recordOnBehalf($this->db, $this->user, $r['proof']->id, 2, 'phone, Ann', 'Wrong colour', $err);
		$this->assertIsArray($res, $err);
		foreach ($r as $name => $rev) {
			$rev->fetch($rev->id);
		}
		$this->assertSame(ComponentRevision::STATUS_DECLINED, (int) $r['proof']->status);
		$this->assertSame(ComponentRevision::STATUS_DECLINED, (int) $r['p']->status, 'In review child declined');
		$this->assertSame(ComponentRevision::STATUS_DECLINED, (int) $r['asm']->status, 'Sub-assembly declined');
		$this->assertSame(ComponentRevision::STATUS_DECLINED, (int) $r['deep']->status, 'Down the tree');
		$this->assertSame(ComponentRevision::STATUS_INREVIEW, (int) $r['flagged']->status, 'Waits on its own decision');
		$this->assertSame(ComponentRevision::STATUS_RELEASED, (int) $r['released']->status);
		$this->assertSame(ComponentRevision::STATUS_INREVIEW, (int) $r['shared']->status, 'Still pinned by another assembly in review');
		$this->assertSame(ComponentRevision::STATUS_INREVIEW, (int) $r['external']->status, 'Another design is not part of the decline');
		$this->assertEmpty($r['external']->fk_eco, 'Nor put under this customer\'s ECO');

		// Each declined child is under the request's ECO, with an event saying why
		$eco = new ChangeOrder($this->db);
		$eco->fetch($res['eco']);
		$affected = array_map('intval', array_column($eco->fetchAffectedComponents(), 'fk_component'));
		foreach (array('p', 'asm', 'deep') as $name) {
			$this->assertSame((int) $eco->id, (int) $r[$name]->fk_eco, $name);
			$this->assertContains((int) $r[$name]->fk_component, $affected, $name);
			$this->assertSame(1, $this->countRows("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."actioncomm WHERE code = 'AC_DESIGNTRACE_REVISION_DECLINED' AND fk_element = ".((int) $r[$name]->id)), $name);
		}
	}

	public function testTheCascadeCanBeRunAgainOnARevisionAlreadyDeclined()
	{
		list($customer, $r) = $this->proofTree();
		// Declined before the cascade existed: only the proof itself
		$this->assertSame(1, ComponentRevision::markDeclined($this->db, (int) $r['proof']->id));

		// Not on a revision that isn't declined
		$this->assertSame(array(), ComponentRevision::declineWithChildren($this->db, $this->user, (int) $r['other']->id, 0, true));

		// A dry run says what would be declined and changes nothing
		$preview = ComponentRevision::declineWithChildren($this->db, $this->user, (int) $r['proof']->id, 0, true);
		$this->assertEqualsCanonicalizing(array($r['p']->ref, $r['asm']->ref, $r['deep']->ref), array_values($preview));
		$r['p']->fetch($r['p']->id);
		$this->assertSame(ComponentRevision::STATUS_INREVIEW, (int) $r['p']->status);
		$this->assertSame(0, $this->countRows("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."actioncomm WHERE code = 'AC_DESIGNTRACE_REVISION_DECLINED' AND fk_element = ".((int) $r['p']->id)));

		// Running it declines them, the same ones as a decline would have
		$done = ComponentRevision::declineWithChildren($this->db, $this->user, (int) $r['proof']->id);
		$this->assertSame(array_keys($preview), array_keys($done));
		foreach (array('p', 'asm', 'deep') as $name) {
			$r[$name]->fetch($r[$name]->id);
			$this->assertSame(ComponentRevision::STATUS_DECLINED, (int) $r[$name]->status, $name);
		}
		foreach (array('flagged', 'shared', 'external') as $name) {
			$r[$name]->fetch($r[$name]->id);
			$this->assertSame(ComponentRevision::STATUS_INREVIEW, (int) $r[$name]->status, $name);
		}
		// Again: nothing left
		$this->assertSame(array(), ComponentRevision::declineWithChildren($this->db, $this->user, (int) $r['proof']->id));
	}

	public function testNewRevisionsAfterADeclineArePinnedWhicheverIsMadeFirst()
	{
		$A = DesignTraceApprovals::class;
		list($customer, $r) = $this->proofTree();
		$err = '';
		$this->assertIsArray($A::recordOnBehalf($this->db, $this->user, $r['proof']->id, 2, 'phone', 'Wrong colour', $err), $err);
		foreach ($r as $rev) {
			$rev->fetch($rev->id);
		}
		$pinOf = function ($parent, $child) {
			$res = $this->db->query("SELECT l.fk_child FROM ".MAIN_DB_PREFIX."designtrace_componentlink as l INNER JOIN ".MAIN_DB_PREFIX."designtrace_componentrevision as c ON c.rowid = l.fk_child WHERE l.fk_parent = ".((int) $parent->id)." AND c.fk_component = ".((int) $child->fk_component));
			$obj = $this->db->fetch_object($res);
			return $obj ? (int) $obj->fk_child : 0;
		};

		// The part's new revision first: the proof's new revision pins it straight away
		$p2 = $r['p']->createFromClone($this->user, $r['p']->id);
		$this->assertIsObject($p2, (string) $r['p']->error);
		$proof2 = $r['proof']->createFromClone($this->user, $r['proof']->id);
		$this->assertIsObject($proof2, (string) $r['proof']->error);
		$this->assertSame((int) $p2->id, $pinOf($proof2, $r['p']));

		// The proof's new revision first: it keeps the declined pin (nothing is lost) and moves to the
		// sub-assembly's new revision once that is made
		$this->assertSame((int) $r['asm']->id, $pinOf($proof2, $r['asm']), 'Declined pin kept');
		$this->assertSame(6, $this->countRows("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."designtrace_componentlink WHERE fk_parent = ".((int) $proof2->id)), 'Every link copied');
		$this->assertLessThan(0, $proof2->validate($this->user), 'Not to review while it pins a declined revision');
		$asm2 = $r['asm']->createFromClone($this->user, $r['asm']->id);
		$this->assertIsObject($asm2, (string) $r['asm']->error);
		$this->assertSame((int) $asm2->id, $pinOf($proof2, $r['asm']), 'Moved to the new revision');
		$this->assertSame((int) $r['deep']->id, $pinOf($asm2, $r['deep']), 'Its own declined child stays pinned');
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
