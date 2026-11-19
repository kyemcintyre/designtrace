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
 * \file        designtrace/test/phpunit/ProofApprovalTest.php
 * \ingroup     designtrace
 * \brief       Customer approval of proof drawings
 */

class ProofApprovalTest extends DesignTraceTestCase
{
	/**
	 * Another proof drawing in review, shown to the customer through the scenario's quote
	 *
	 * @param	array<string,mixed>	$s	newCustomerScenario()
	 * @param	string				$label	Component label
	 * @return	ComponentRevision
	 */
	private function newProofInReview($s, $label)
	{
		$drawing = $this->newComponent($s['design'], Component::TYPE_DRAWING, $label, Component::DRAWING_PROOF);
		$proof = $this->newRevision($drawing);
		$this->addFile($proof, 'proof.pdf', '%PDF-1.4 '.$label.' '.bin2hex(random_bytes(8)), RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->assertOk($proof->validate($this->user), $proof, 'Proof in review');
		$proof->fetch($proof->id);
		$this->assertGreaterThan(0, $this->linkDoc($proof, 'propal', $s['quote']->id), (string) $proof->error);
		return $proof;
	}

	public function testDecisionsAndWhatAChangeRequestOpens()
	{
		$A = DesignTraceProofApproval::class;
		$s = $this->newCustomerScenario();
		$soc = (int) $s['customer']->id;
		$quote = (int) $s['quote']->id;
		$proof = (int) $s['proof']->id;
		$err = '';

		$proofs = $A::fetchProofs($this->db, $soc, 'propal', $quote);
		$this->assertCount(1, $proofs);
		$this->assertSame($proof, (int) reset($proofs)['rev_id']);
		$this->assertTrue(reset($proofs)['can_decide']);
		$this->assertCount(0, $A::fetchProofs($this->db, $soc, 'commande', $s['order']->id), 'A part is not a proof');

		$this->assertLessThan(0, $A::decide($this->db, $this->user, $soc, $s['rev']->id, 'propal', $quote, 1, '', 'webportal', 'x', $err), 'Parts cannot be approved');
		$this->assertLessThan(0, $A::decide($this->db, $this->user, $soc + 1, $proof, 'propal', $quote, 1, '', 'webportal', 'x', $err), 'Other customer');
		$this->assertLessThan(0, $A::decide($this->db, $this->user, $soc, $proof, 'propal', $quote, 2, '  ', 'webportal', 'x', $err), 'Changes need a comment');

		$this->setConst('DESIGNTRACE_PROOF_CHANGES_ACTION', 'ticket');
		$r = $A::decide($this->db, $this->user, $soc, $proof, 'propal', $quote, 2, 'Make the logo bigger', 'webportal', 'portal:zz', $err, '203.0.113.9');
		$this->assertIsArray($r, $err);
		$this->assertGreaterThan(0, $r['ticket']);
		$ticket = new Ticket($this->db);
		$ticket->fetch($r['ticket']);
		$this->assertSame($soc, (int) $ticket->fk_soc);
		$this->assertStringContainsString('Make the logo bigger', $ticket->message);
		$this->assertStringContainsString($s['proof']->ref, $ticket->subject);
		$this->assertSame(1, $this->countRows("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."element_element WHERE sourcetype = 'ticket' AND fk_source = ".((int) $r['ticket'])." AND targettype = 'designtrace_componentrevision' AND fk_target = ".$proof));
		$rev = new ComponentRevision($this->db);
		$rev->fetch($proof);
		$this->assertSame(DesignTraceProofApproval::DECISION_CHANGES, (int) $rev->customer_approval);
		$this->assertSame('portal:zz', $rev->customer_approval_by);
		$this->assertSame(1, $this->countRows("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."actioncomm WHERE code = 'AC_DESIGNTRACE_PROOF_CHANGES' AND fk_element = ".$proof));
		$this->assertSame(ComponentRevision::STATUS_DECLINED, (int) $rev->status, 'Changes requested close an In review proof');
		$this->assertLessThan(0, $A::decide($this->db, $this->user, $soc, $proof, 'propal', $quote, 1, 'Changed my mind', 'webportal', 'x', $err), 'A declined proof is final');
		$this->assertLessThan(0, $A::decide($this->db, $this->user, $soc, $proof, 'propal', $quote, 2, 'Again', 'webportal', 'x', $err));
		$proofs = $A::fetchProofs($this->db, $soc, 'propal', $quote);
		$this->assertFalse($proofs['propal_'.$quote.'_'.$proof]['can_decide']);
		$this->assertSame(0, $rev->release($this->user), 'A declined revision is never released');

		$p2 = (int) $this->newProofInReview($s, 'Proof two')->id;
		$this->setConst('DESIGNTRACE_PROOF_CHANGES_ACTION', 'eco');
		$r = $A::decide($this->db, $this->user, $soc, $p2, 'propal', $quote, 2, 'Also thicker', 'api', 'site:zz', $err);
		$eco = new ChangeOrder($this->db);
		$this->assertGreaterThan(0, $eco->fetch($r['eco']));
		$this->assertSame(ChangeOrder::STATUS_DRAFT, (int) $eco->status);
		$this->assertSame(1, $eco->countAffectedComponents());

		$p3 = (int) $this->newProofInReview($s, 'Proof three')->id;
		$this->setConst('DESIGNTRACE_PROOF_CHANGES_ACTION', 'none');
		$r = $A::decide($this->db, $this->user, $soc, $p3, 'propal', $quote, 2, 'One more', 'api', 'site:zz', $err);
		$this->assertEmpty($r['ticket']);
		$this->assertEmpty($r['eco']);
		$rev->fetch($p3);
		$this->assertSame(ComponentRevision::STATUS_DECLINED, (int) $rev->status);

		// An approval is final and leaves the revision In review for staff to release
		$proof = (int) $this->newProofInReview($s, 'Proof four')->id;
		$this->assertIsArray($A::decide($this->db, $this->user, $soc, $proof, 'propal', $quote, 1, 'Looks good', 'webportal', 'portal:zz', $err), $err);
		$rev->fetch($proof);
		$this->assertSame(DesignTraceProofApproval::DECISION_APPROVED, (int) $rev->customer_approval);
		$this->assertLessThan(0, $A::decide($this->db, $this->user, $soc, $proof, 'propal', $quote, 2, 'Wait', 'webportal', 'x', $err), 'Approval is final');
		$proofs = $A::fetchProofs($this->db, $soc, 'propal', $quote);
		$this->assertFalse(reset($proofs)['can_decide']);
		$this->assertCount(1, $A::fetchHistory($this->db, $proof));
		$this->assertSame(ComponentRevision::STATUS_INREVIEW, (int) $rev->status);

		$this->release($rev);
		$next = $rev->createFromClone($this->user, $proof);
		$this->assertSame(0, (int) $next->customer_approval, 'A new revision waits for the customer again');
	}

	public function testChangesOnAReleasedProofLeaveItReleased()
	{
		$A = DesignTraceProofApproval::class;
		$s = $this->newCustomerScenario();
		$soc = (int) $s['customer']->id;
		$quote = (int) $s['quote']->id;
		$proof = $this->newProofInReview($s, 'Released proof');
		// Released without the customer's approval (as an older proof may have been)
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_componentrevision SET needs_approval = 0 WHERE rowid = ".((int) $proof->id));
		$proof->fetch($proof->id);
		$this->release($proof);
		$this->setConst('DESIGNTRACE_PROOF_CHANGES_ACTION', 'none');
		$err = '';
		$this->assertIsArray($A::decide($this->db, $this->user, $soc, $proof->id, 'propal', $quote, 2, 'Too late but noted', 'api', 'site:zz', $err), $err);
		$proof->fetch($proof->id);
		$this->assertSame(ComponentRevision::STATUS_RELEASED, (int) $proof->status, 'A released revision stays released');
		$this->assertSame(DesignTraceProofApproval::DECISION_CHANGES, (int) $proof->customer_approval);
	}

	public function testAGrantOffersOnlyItsPinnedProofForADecision()
	{
		$A = DesignTraceProofApproval::class;
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$drawing = $this->newComponent($design, Component::TYPE_DRAWING, 'Proof', Component::DRAWING_PROOF);
		$revs = array();
		foreach (array('a', 'b') as $name) {
			$rev = $this->newRevision($drawing);
			$this->addFile($rev, $name.'.pdf', '%PDF '.$name, RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
			// Released without the customer's approval (as an older proof may have been)
			$this->db->query("UPDATE ".$this->db->prefix()."designtrace_componentrevision SET needs_approval = 0 WHERE rowid = ".((int) $rev->id));
			$rev->fetch($rev->id);
			$this->release($rev);
			$revs[$name] = $rev;
		}
		$gid = DesignTraceGrant::create($this->db, $this->user, $customer->id, $design->id);
		$this->assertGreaterThan(0, $gid);

		// Both releases' files are open to the customer, but the superseded one is not waiting on them
		$this->assertCount(2, DesignTraceClientAccess::fetchVisibleFiles($this->db, $customer->id, 'grant'));
		$proofs = $A::fetchProofs($this->db, $customer->id);
		$this->assertSame(array((int) $revs['b']->id), array_values(array_map(function ($p) {
			return $p['rev_id'];
		}, $proofs)));
		$err = '';
		$this->assertLessThan(0, $A::decide($this->db, $this->user, $customer->id, $revs['a']->id, 'grant', $gid, 1, '', 'api', 'x', $err), 'No decision on a superseded proof');
		$this->assertTrue($A::fetchProofs($this->db, $customer->id, 'grant', $gid)['grant_'.$gid.'_'.$revs['b']->id]['can_decide'], 'The pinned one can be decided');
	}

	public function testRules()
	{
		$A = DesignTraceProofApproval::class;
		$this->assertTrue($A::isProof(Component::TYPE_DRAWING, Component::DRAWING_PROOF));
		$this->assertFalse($A::isProof(Component::TYPE_DRAWING, Component::DRAWING_TDS));
		$this->assertFalse($A::isProof(Component::TYPE_PART, null));
		$this->assertFalse($A::statusAllowsDecision(ComponentRevision::STATUS_DRAFT));
		$this->assertTrue($A::statusAllowsDecision(ComponentRevision::STATUS_INREVIEW));
		$this->assertTrue($A::statusAllowsDecision(ComponentRevision::STATUS_RELEASED));
		$this->assertFalse($A::statusAllowsDecision(ComponentRevision::STATUS_DECLINED));
		$this->assertFalse($A::statusAllowsDecision(ComponentRevision::STATUS_OBSOLETE));
	}
}
