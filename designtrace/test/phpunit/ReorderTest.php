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
 * \file        designtrace/test/phpunit/ReorderTest.php
 * \ingroup     designtrace
 * \brief       Reorder from a delivered order
 */

class ReorderTest extends DesignTraceTestCase
{
	public function testDraftQuoteFromADeliveredOrder()
	{
		$R = DesignTraceReorder::class;
		$s = $this->newCustomerScenario();
		$order = $s['order'];
		$err = '';
		$this->assertTrue($R::canReorder($this->db, $order));

		$qid = $R::createQuote($this->db, $this->user, $order, 'webportal', 'portal:zz', $err);
		$this->assertGreaterThan(0, $qid, $err);
		$quote = new Propal($this->db);
		$quote->fetch($qid);
		$this->assertSame(0, (int) $quote->status);
		$this->assertSame((int) $s['customer']->id, (int) $quote->socid);
		$this->assertSame((int) $order->fk_project, (int) $quote->fk_project);
		$this->assertCount(1, $quote->lines);
		$this->assertEquals(25, (float) $quote->lines[0]->subprice);
		$this->assertEquals(2, (float) $quote->lines[0]->qty);
		$revs = DesignTraceDocLinks::fetchRevisionsOfDocument($this->db, $quote);
		$this->assertCount(1, $revs);
		$this->assertSame((int) $s['rev']->id, (int) $revs[0]->rev_id);
		$this->assertSame(1, $this->countRows("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."element_element WHERE sourcetype = 'propal' AND fk_source = ".((int) $qid)." AND targettype = 'designtrace_design' AND fk_target = ".((int) $s['design']->id)));
		$this->assertSame(1, $this->countRows("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."element_element WHERE sourcetype = 'commande' AND fk_source = ".((int) $order->id)." AND targettype = 'propal' AND fk_target = ".((int) $qid)));
		foreach (array('PETG', 'Bambu X1C', $order->ref, 'portal:zz') as $text) {
			$this->assertStringContainsString($text, $quote->note_private);
		}
		$this->assertSame($qid, $R::getPendingQuote($this->db, $order->id));

		$this->assertSame($qid, $R::createQuote($this->db, $this->user, $order, 'api', 'site:zz', $err), 'The customer gets the same draft back');
		$staff = $R::createQuote($this->db, $this->user, $order, 'backoffice', 'admin', $err);
		$this->assertGreaterThan(0, $staff);
		$this->assertNotSame($qid, $staff, 'Staff can always make another');

		$b = $s['rev']->createFromClone($this->user, $s['rev']->id);
		$this->release($b);
		$q3 = new Propal($this->db);
		$q3->fetch($R::createQuote($this->db, $this->user, $order, 'backoffice', 'admin', $err));
		$this->assertStringContainsString($b->ref, $q3->note_private, 'A newer revision is mentioned');
		$this->assertSame((int) $s['rev']->id, (int) DesignTraceDocLinks::fetchRevisionsOfDocument($this->db, $q3)[0]->rev_id, 'Still the delivered one');

		$errors = array();
		$this->assertGreaterThan(0, DesignTraceClientAccess::updateDelivered($this->db, $this->user, $order, $s['rev'], $b, 'test', 0, $errors));
		$q4 = new Propal($this->db);
		$q4->fetch($R::createQuote($this->db, $this->user, $order, 'backoffice', 'admin', $err));
		$this->assertSame((int) $b->id, (int) DesignTraceDocLinks::fetchRevisionsOfDocument($this->db, $q4)[0]->rev_id, 'After Update delivered revision, the new one');
	}

	public function testOpenOrdersCannotBeReordered()
	{
		$s = $this->newCustomerScenario();
		$open = $this->newOrder($s['customer']->id, false);
		$this->linkDoc($s['rev'], 'commande', $open->id);
		$open->valid($this->user);
		$open->fetch($open->id);
		$err = '';
		$this->assertFalse(DesignTraceReorder::canReorder($this->db, $open));
		$this->assertLessThan(0, DesignTraceReorder::createQuote($this->db, $this->user, $open, 'api', 'x', $err));
	}
}
