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

		$this->setConst('DESIGNTRACE_PROOF_CHANGES_ACTION', 'eco');
		$r = $A::decide($this->db, $this->user, $soc, $proof, 'propal', $quote, 2, 'Also thicker', 'api', 'site:zz', $err);
		$eco = new ChangeOrder($this->db);
		$this->assertGreaterThan(0, $eco->fetch($r['eco']));
		$this->assertSame(ChangeOrder::STATUS_DRAFT, (int) $eco->status);
		$this->assertSame(1, $eco->countAffectedComponents());

		$this->setConst('DESIGNTRACE_PROOF_CHANGES_ACTION', 'none');
		$r = $A::decide($this->db, $this->user, $soc, $proof, 'propal', $quote, 2, 'One more', 'api', 'site:zz', $err);
		$this->assertEmpty($r['ticket']);
		$this->assertEmpty($r['eco']);

		$this->assertIsArray($A::decide($this->db, $this->user, $soc, $proof, 'propal', $quote, 1, 'Looks good', 'webportal', 'portal:zz', $err), $err);
		$rev->fetch($proof);
		$this->assertSame(DesignTraceProofApproval::DECISION_APPROVED, (int) $rev->customer_approval);
		$this->assertLessThan(0, $A::decide($this->db, $this->user, $soc, $proof, 'propal', $quote, 2, 'Wait', 'webportal', 'x', $err), 'Approval is final');
		$proofs = $A::fetchProofs($this->db, $soc, 'propal', $quote);
		$this->assertFalse(reset($proofs)['can_decide']);
		$this->assertCount(4, $A::fetchHistory($this->db, $proof));

		$this->release($rev);
		$next = $rev->createFromClone($this->user, $proof);
		$this->assertSame(0, (int) $next->customer_approval, 'A new revision waits for the customer again');
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
		$this->assertFalse($A::statusAllowsDecision(ComponentRevision::STATUS_OBSOLETE));
	}
}
