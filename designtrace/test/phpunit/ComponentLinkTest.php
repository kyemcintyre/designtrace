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
 * \file        designtrace/test/phpunit/ComponentLinkTest.php
 * \ingroup     designtrace
 * \brief       Links between revisions: rules, workflow order, Needs update flagging
 */

class ComponentLinkTest extends DesignTraceTestCase
{
	private function flag($link)
	{
		$l = new ComponentLink($this->db);
		$l->fetch($link->id);
		return array((int) $l->flag_status, (string) $l->flag_note);
	}

	public function testLinkTypeRules()
	{
		$d = $this->newDesign();
		$part = $this->newComponent($d, Component::TYPE_PART, 'Plate');
		$other = $this->newComponent($d, Component::TYPE_PART, 'Nut');
		$asm = $this->newComponent($d, Component::TYPE_ASSEMBLY, 'Frame');
		$dwg = $this->newComponent($d, Component::TYPE_DRAWING, 'Drawing', Component::DRAWING_TDS);
		$pa = $this->newRevision($part, true);
		$oa = $this->newRevision($other, true);
		$wa = $this->newRevision($dwg, true);
		$aa = $this->newRevision($asm);

		$pb = $this->newRevision($part);
		$this->assertEmpty($this->newLink($pb, $oa, ComponentLink::TYPE_USES)->id, "A part cannot Use");
		$pb->delete($this->user);

		$this->assertEmpty($this->newLink($aa, $wa, ComponentLink::TYPE_USES)->id, 'An assembly cannot Use a drawing');
		$this->assertEmpty($this->newLink($aa, $pa, ComponentLink::TYPE_DEPICTS)->id, 'An assembly cannot Depict');
		$this->assertEmpty($this->newLink($aa, $pa, ComponentLink::TYPE_USES, 0)->id, 'Quantity 0 refused');

		$uses = $this->newLink($aa, $pa, ComponentLink::TYPE_USES, 4);
		$this->assertNotEmpty($uses->id, (string) $uses->error);
		$this->assertSame(4.0, (float) $uses->qty);
		$this->assertEmpty($this->newLink($aa, $pa, ComponentLink::TYPE_REFERENCES)->id, 'One link per child component');

		$this->release($aa);
		$this->assertEmpty($this->newLink($aa, $oa, ComponentLink::TYPE_REFERENCES)->id, 'Released parents are locked');
	}

	public function testNoCycles()
	{
		$d = $this->newDesign();
		$part = $this->newComponent($d);
		$asm = $this->newComponent($d, Component::TYPE_ASSEMBLY, 'Frame');
		$pa = $this->newRevision($part, true);
		$aa = $this->newRevision($asm);
		$this->newLink($aa, $pa, ComponentLink::TYPE_USES, 1);
		$this->release($aa);
		$pb = $this->newRevision($part);
		$this->assertEmpty($this->newLink($pb, $aa, ComponentLink::TYPE_REFERENCES)->id);
	}

	public function testLinksAcrossDesignsAndToDrafts()
	{
		$asm = $this->newComponent($this->newDesign(), Component::TYPE_ASSEMBLY, 'Frame');
		$mating = $this->newComponent($this->newDesign(array('label' => 'ZZ other design')));
		$aa = $this->newRevision($asm);
		$ma = $this->newRevision($mating);
		$link = $this->newLink($aa, $ma, ComponentLink::TYPE_REFERENCES);
		$this->assertNotEmpty($link->id, (string) $link->error);
	}

	public function testParentWaitsForItsChildren()
	{
		$d = $this->newDesign();
		$bolt = $this->newComponent($d, Component::TYPE_PART, 'Bolt');
		$asm = $this->newComponent($d, Component::TYPE_ASSEMBLY, 'Frame');
		$ba = $this->newRevision($bolt);
		$aa = $this->newRevision($asm);
		$this->assertNotEmpty($this->newLink($aa, $ba, ComponentLink::TYPE_USES, 2)->id);

		$this->assertLessThan(0, $aa->validate($this->user), 'Review refused while a child is a draft');
		$this->assertLessThan(0, $ba->delete($this->user), 'A linked draft cannot be deleted');

		$ba->fetch($ba->id);
		$ba->validate($this->user);
		$aa->fetch($aa->id);
		$aa->error = '';
		$this->assertGreaterThan(0, $aa->validate($this->user));
		$aa->fetch($aa->id);
		$this->assertLessThan(0, $aa->release($this->user), 'Release refused while a child is in review');
		$ba->fetch($ba->id);
		$this->assertLessThan(0, $ba->setDraft($this->user), 'A child cannot go back to draft while its parent is in review');

		$this->release($ba);
		$this->release($aa);
	}

	public function testObsoleteChildRefused()
	{
		$d = $this->newDesign();
		$part = $this->newComponent($d);
		$asm = $this->newComponent($d, Component::TYPE_ASSEMBLY, 'Frame');
		$pa = $this->newRevision($part, true);
		$pa->cancel($this->user);
		$aa = $this->newRevision($asm);
		$this->assertEmpty($this->newLink($aa, $pa, ComponentLink::TYPE_USES, 1)->id);
	}

	public function testNewChildRevisionFlagsParentsAndPropagates()
	{
		$d = $this->newDesign();
		$part = $this->newComponent($d, Component::TYPE_PART, 'Plate');
		$asm = $this->newComponent($d, Component::TYPE_ASSEMBLY, 'Frame');
		$asmDwg = $this->newComponent($d, Component::TYPE_DRAWING, 'Frame drawing', Component::DRAWING_TDS);
		$partDwg = $this->newComponent($d, Component::TYPE_DRAWING, 'Plate drawing', Component::DRAWING_TDS);
		$unrelated = $this->newComponent($this->newDesign(), Component::TYPE_PART, 'Mating part');

		$pa = $this->newRevision($part, true);
		$xa = $this->newRevision($unrelated, true);
		$aa = $this->newRevision($asm);
		$lAP = $this->newLink($aa, $pa, ComponentLink::TYPE_USES, 4);
		$lAX = $this->newLink($aa, $xa, ComponentLink::TYPE_REFERENCES);
		$this->release($aa);
		$w1 = $this->newRevision($asmDwg);
		$lW1 = $this->newLink($w1, $aa, ComponentLink::TYPE_DEPICTS);
		$this->release($w1);
		$w2 = $this->newRevision($partDwg);
		$lW2 = $this->newLink($w2, $pa, ComponentLink::TYPE_DEPICTS);
		$this->release($w2);
		foreach (array($lAP, $lW1, $lW2, $lAX) as $l) {
			$this->assertSame(ComponentLink::FLAG_NONE, $this->flag($l)[0]);
		}

		$pb = $this->newRevision($part, true);
		$this->assertSame(array(ComponentLink::FLAG_NEEDS_UPDATE, $part->ref.' A → B'), $this->flag($lAP));
		$this->assertSame(ComponentLink::FLAG_NEEDS_UPDATE, $this->flag($lW2)[0]);
		$this->assertSame(array(ComponentLink::FLAG_INDIRECT, 'via '.$asm->ref), $this->flag($lW1));
		$this->assertSame(ComponentLink::FLAG_NONE, $this->flag($lAX)[0]);

		$flags = ComponentLink::getComponentFlags($this->db, array($part->id, $asm->id, $asmDwg->id, $partDwg->id));
		$this->assertArrayNotHasKey($part->id, $flags);
		$this->assertSame(1, (int) $flags[$asm->id]);
		$this->assertSame(2, (int) $flags[$asmDwg->id]);
		$this->assertSame(1, (int) $flags[$partDwg->id]);
		$this->assertCount(2, ComponentLink::fetchWhereUsed($this->db, $part->id));

		// Acknowledge: needs a note, only direct flags
		$ack = new ComponentLink($this->db);
		$ack->fetch($lW2->id);
		$this->assertLessThan(0, $ack->acknowledge($this->user, ''));
		$this->assertGreaterThan(0, $ack->acknowledge($this->user, 'View unchanged'));
		$this->assertSame(ComponentLink::FLAG_ACKNOWLEDGED, $this->flag($lW2)[0]);
		$indirect = new ComponentLink($this->db);
		$indirect->fetch($lW1->id);
		$this->assertLessThan(0, $indirect->acknowledge($this->user, 'x'));

		// New assembly revision re-pinned to B: the flag moves up to the drawing
		$ab = $aa->createFromClone($this->user, $aa->id);
		$repin = null;
		foreach (ComponentLink::fetchForParent($this->db, $ab->id) as $row) {
			if ((int) $row->comp_id === (int) $part->id) {
				$repin = new ComponentLink($this->db);
				$repin->fetch($row->rowid);
			}
		}
		$this->assertNotNull($repin, 'Links copied to the new revision');
		$repin->fk_child = $pb->id;
		$this->assertGreaterThan(0, $repin->update($this->user));
		$this->release($ab);
		$this->assertSame(ComponentLink::FLAG_NONE, $this->flag($lAP)[0], 'Old revision no longer current');
		$this->assertSame(array(ComponentLink::FLAG_NEEDS_UPDATE, $asm->ref.' A → B'), $this->flag($lW1));

		// Next part revision flags the acknowledged drawing again; obsolete restores
		$pc = $this->newRevision($part, true);
		$this->assertSame(array(ComponentLink::FLAG_NEEDS_UPDATE, $part->ref.' A → C'), $this->flag($lW2));
		$pc->fetch($pc->id);
		$pc->cancel($this->user);
		$this->assertSame(ComponentLink::FLAG_ACKNOWLEDGED, $this->flag($lW2)[0]);
		$this->assertSame(ComponentLink::FLAG_NONE, $this->flag($repin)[0]);
	}

	public function testOutdatedPinHelper()
	{
		$d = $this->newDesign();
		$part = $this->newComponent($d);
		$asm = $this->newComponent($d, Component::TYPE_ASSEMBLY, 'Frame');
		$pa = $this->newRevision($part, true);
		$aa = $this->newRevision($asm);
		$this->newLink($aa, $pa, ComponentLink::TYPE_USES, 1);
		$rows = ComponentLink::fetchForParent($this->db, $aa->id);
		$this->assertFalse(designtraceLinkIsOutdated($rows[0]));
		$this->newRevision($part, true);
		$rows = ComponentLink::fetchForParent($this->db, $aa->id);
		$this->assertTrue(designtraceLinkIsOutdated($rows[0]));
	}
}
