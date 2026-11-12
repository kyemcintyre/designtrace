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
 * \file        designtrace/test/phpunit/ChangeOrderTest.php
 * \ingroup     designtrace
 * \brief       Change orders: numbering, approval, affected components, workflow, release gate
 */

class ChangeOrderTest extends DesignTraceTestCase
{
	public function testEcoFromATicketWorkflowAndReleaseGate()
	{
		$customer = $this->newCustomer();
		$d = $this->newDesign();
		$part = $this->newComponent($d, Component::TYPE_PART, 'Bracket');
		$asm = $this->newComponent($d, Component::TYPE_ASSEMBLY, 'Frame');
		$pa = $this->newRevision($part, true);
		$aa = $this->newRevision($asm);
		$this->newLink($aa, $pa, ComponentLink::TYPE_USES, 2);
		$this->release($aa);
		$ticket = $this->newTicket($customer->id, 'Bracket cracking at bend');
		$this->linkDoc($pa, 'ticket', $ticket->id);

		$empty = new ChangeOrder($this->db);
		$empty->label = '';
		$this->assertLessThan(0, $empty->create($this->user), 'Summary required');

		$eco = new ChangeOrder($this->db);
		$eco->label = 'Bracket cracking at bend';
		$eco->reason = ChangeOrder::REASON_DEFECT;
		$eco->fk_soc = $customer->id;
		$eco->linked_objects = array('ticket' => $ticket->id);
		$this->assertOk($eco->create($this->user), $eco, 'Create ECO');
		$this->assertMatchesRegularExpression('/^ECO-\d{4}$/', $eco->ref);
		$eco->fetch($eco->id);
		$this->assertSame((int) $this->user->id, (int) $eco->fk_user_requester);
		$affected = $eco->fetchAffectedComponents();
		$this->assertCount(1, $affected, 'Component of the ticket\'s revision added');
		$this->assertSame($part->ref, $affected[0]->comp_ref);

		$noRight = $this->newUser(array(50020041, 50020042));
		$this->assertLessThan(0, $eco->approve($noRight), 'Approve needs the right');
		$eco->error = '';
		$eco->errors = array();
		$this->assertGreaterThan(0, $eco->approve($this->user));
		$this->assertSame(ChangeOrder::STATUS_APPROVED, (int) $eco->status);

		$pb = $pa->createFromClone($this->user, $pa->id);
		$pb->fetch($pb->id);
		$this->assertEmpty($pb->fk_eco, 'The ECO is not copied to a new revision');

		$this->setConst('DESIGNTRACE_RELEASE_REQUIRES_ECO', '1');
		$this->assertLessThan(0, $pb->release($this->user), 'Release needs an approved ECO');
		$pb->fetch($pb->id);
		$pb->fk_eco = $eco->id;
		$this->assertGreaterThan(0, $pb->update($this->user));
		$pb->error = '';
		$pb->errors = array();
		$this->assertGreaterThan(0, $pb->release($this->user), (string) $pb->error);
		$first = $this->newRevision($this->newComponent($d, Component::TYPE_PART, 'New part'));
		$this->assertGreaterThan(0, $first->release($this->user), 'The first revision needs no ECO');
		$this->setConst('DESIGNTRACE_RELEASE_REQUIRES_ECO', null);

		$suggested = $eco->fetchSuggestedComponents();
		$this->assertCount(1, $suggested, 'Flagged frame suggested');
		$this->assertSame($asm->ref, $suggested[0]->comp_ref);
		$ab = $aa->createFromClone($this->user, $aa->id);
		$ab->fetch($ab->id);
		$ab->fk_eco = $eco->id;
		$this->assertGreaterThan(0, $ab->update($this->user));
		$this->assertCount(2, $eco->fetchAffectedComponents(), 'Frame added automatically');
		$this->assertCount(2, $eco->fetchRevisions());

		$this->assertLessThan(0, $eco->setDraft($this->user), 'Not back to draft with a released revision');
		$this->assertLessThan(0, $eco->removeAffectedComponent($this->user, $affected[0]->rowid), 'Not with revisions');
		$this->assertLessThan(0, $eco->delete($this->user));

		$this->assertGreaterThan(0, $eco->setImplemented($this->user));
		$this->assertLessThan(0, $eco->addAffectedComponent($this->user, $first->fk_component), 'Locked once implemented');
		$this->assertGreaterThan(0, $eco->close($this->user));
		$this->assertSame(ChangeOrder::STATUS_CLOSED, (int) $eco->status);
		$ab->fetch($ab->id);
		$ab->fk_eco = null;
		$ab->update($this->user);
		$ab->fk_eco = $eco->id;
		$this->assertLessThan(0, $ab->update($this->user), 'A closed ECO cannot take revisions');
		$this->assertGreaterThan(0, $eco->reopen($this->user));
		$this->assertSame(ChangeOrder::STATUS_IMPLEMENTED, (int) $eco->status);

		$pb->fetch($pb->id);
		$this->assertStringContainsString('from '.$eco->ref.': Bracket cracking at bend', strip_tags(designtraceRevisionCause($pb)));
		$this->assertArrayHasKey($eco->id, ChangeOrder::getOpenForComponent($this->db, $part->id));
	}

	public function testDraftEcoCanBeDeleted()
	{
		$eco = new ChangeOrder($this->db);
		$eco->label = 'ZZ temporary';
		$this->assertOk($eco->create($this->user), $eco, 'Create ECO');
		$this->assertGreaterThan(0, $eco->delete($this->user));
	}

	public function testApprovalNeedsAnAffectedComponent()
	{
		$eco = new ChangeOrder($this->db);
		$eco->label = 'ZZ nothing affected';
		$eco->create($this->user);
		$this->assertLessThan(0, $eco->approve($this->user));
	}
}
