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
 * \file        designtrace/test/phpunit/DocLinksTest.php
 * \ingroup     designtrace
 * \brief       Revisions linked to quotes, orders, invoices, BOMs and tickets, carried over
 */

class DocLinksTest extends DesignTraceTestCase
{
	public function testWhatCanBeLinkedWhere()
	{
		$customer = $this->newCustomer();
		$d = $this->newDesign();
		$part = $this->newComponent($d);
		$asm = $this->newComponent($d, Component::TYPE_ASSEMBLY, 'Frame');
		$proofDwg = $this->newComponent($d, Component::TYPE_DRAWING, 'Proof', Component::DRAWING_PROOF);
		$pa = $this->newRevision($part, true);
		$aa = $this->newRevision($asm, true);
		$wa = $this->newRevision($proofDwg);
		$quote = $this->newQuote($customer->id, false);
		$order = $this->newOrder($customer->id, false);
		$ticket = $this->newTicket($customer->id);
		$bom = new BOM($this->db);
		$bom->label = 'ZZ BOM';
		$bom->qty = 1;
		$bom->bomtype = 0;
		$this->assertOk($bom->create($this->user), $bom, 'Create BOM');

		$this->assertGreaterThan(0, $this->linkDoc($wa, 'propal', $quote->id), 'Draft proof on a quote');
		$this->assertLessThan(0, $this->linkDoc($wa, 'propal', $quote->id), 'Duplicate refused');
		$this->assertGreaterThan(0, $this->linkDoc($pa, 'propal', $quote->id));
		$this->assertLessThan(0, $this->linkDoc($wa, 'commande', $order->id), 'Orders take released revisions only');
		$this->assertGreaterThan(0, $this->linkDoc($pa, 'commande', $order->id));
		$this->assertGreaterThan(0, $this->linkDoc($aa, 'bom', $bom->id), (string) $aa->error);
		$this->assertLessThan(0, $this->linkDoc($pa, 'bom', $bom->id), 'BOMs take assemblies only');
		$this->assertGreaterThan(0, $this->linkDoc($pa, 'ticket', $ticket->id));
		$this->assertLessThan(0, $this->linkDoc($pa, 'facture', 999999999), 'Unknown invoice');

		$pa->fetchObjectLinked();
		$this->assertArrayHasKey('commande', $pa->linkedObjectsIds);
		$this->assertArrayHasKey('ticket', $pa->linkedObjectsIds);
		$bom->fetchObjectLinked();
		$this->assertArrayHasKey('designtrace_componentrevision', $bom->linkedObjectsIds);

		$this->newRevision($asm, true);
		$rows = DesignTraceDocLinks::fetchRevisionsOfDocument($this->db, $bom);
		$this->assertCount(1, $rows);
		$this->assertSame('B', $rows[0]->current_rev, 'The BOM sees the newer revision');
	}

	public function testLinksAreCarriedFromQuoteToOrderToInvoice()
	{
		$customer = $this->newCustomer();
		$d = $this->newDesign();
		$part = $this->newComponent($d);
		$pa = $this->newRevision($part, true);
		$quote = $this->newQuote($customer->id, false);
		$this->linkDoc($pa, 'propal', $quote->id);
		$d->add_object_linked('propal', $quote->id);

		$order = new Commande($this->db);
		$order->socid = $customer->id;
		$order->date = dol_now();
		$order->origin = 'propal';
		$order->origin_type = 'propal';
		$order->origin_id = $quote->id;
		$order->linked_objects = array('propal' => $quote->id);
		$this->assertOk($order->create($this->user), $order, 'Order from quote');
		$order->fetchObjectLinked();
		$this->assertArrayHasKey('designtrace_componentrevision', $order->linkedObjectsIds);
		$this->assertArrayHasKey('designtrace_design', $order->linkedObjectsIds);

		$invoice = new Facture($this->db);
		$invoice->socid = $customer->id;
		$invoice->date = dol_now();
		$invoice->type = Facture::TYPE_STANDARD;
		$invoice->origin = 'commande';
		$invoice->origin_type = 'commande';
		$invoice->origin_id = $order->id;
		$invoice->linked_objects = array('commande' => $order->id);
		$this->assertOk($invoice->create($this->user), $invoice, 'Invoice from order');
		$rows = DesignTraceDocLinks::fetchRevisionsOfDocument($this->db, $invoice);
		$this->assertCount(1, $rows);
		$this->assertSame((int) $pa->id, (int) $rows[0]->rev_id);
	}
}
