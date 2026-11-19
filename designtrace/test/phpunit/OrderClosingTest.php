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
 * \file        designtrace/test/phpunit/OrderClosingTest.php
 * \ingroup     designtrace
 * \brief       Design-only orders closed once paid (DESIGNTRACE_CLOSE_PAID_DESIGN_ORDERS)
 */

dol_include_once('/designtrace/class/orderclosing.class.php');
dol_include_once('/designtrace/class/designlibrary.class.php');

class OrderClosingTest extends DesignTraceTestCase
{
	/**
	 * A validated order of the customer with a released part revision linked
	 *
	 * @param	int		$socid		Customer id
	 * @param	int		$linetype	0 product, 1 service
	 * @return	array{order:Commande,rev:ComponentRevision}
	 */
	private function designOrder($socid, $linetype = 1)
	{
		$design = $this->newDesign(array('fk_soc' => $socid), true);
		$rev = $this->newRevision($this->newComponent($design), true);
		$order = new Commande($this->db);
		$order->socid = $socid;
		$order->date = dol_now();
		$this->assertOk($order->create($this->user), $order, 'Create order');
		$order->addline('ZZ design work', 100, 1, 0, 0, 0, 0, 0, 0, 0, 'HT', 0, '', '', $linetype);
		$this->assertGreaterThan(0, $this->linkDoc($rev, 'commande', $order->id), (string) $rev->error);
		$this->assertOk($order->valid($this->user), $order, 'Validate order');
		$order->fetch($order->id);
		return array('order' => $order, 'rev' => $rev);
	}

	/**
	 * A validated invoice made from the order, for an amount
	 */
	private function invoice($order, $amount, $type = Facture::TYPE_STANDARD)
	{
		$invoice = new Facture($this->db);
		$invoice->socid = $order->socid;
		$invoice->date = dol_now();
		$invoice->type = $type;
		$invoice->linked_objects = array('commande' => $order->id);
		$this->assertOk($invoice->create($this->user), $invoice, 'Create invoice');
		$invoice->addline('ZZ design work', $amount, 1, 0, 0, 0, 0, 0, '', '', 0, 0, 0, 'HT', 0, 1);
		$this->assertOk($invoice->validate($this->user), $invoice, 'Validate invoice');
		$invoice->fetch($invoice->id);
		return $invoice;
	}

	private function orderStatus($order)
	{
		$order->fetch($order->id);
		return (int) $order->status;
	}

	public function testPaidDesignOrderIsClosedAndDelivered()
	{
		$this->setConst('DESIGNTRACE_CLOSE_PAID_DESIGN_ORDERS', '1');
		$customer = $this->newCustomer();
		$s = $this->designOrder($customer->id);
		$invoice = $this->invoice($s['order'], 100);
		$this->assertSame(Commande::STATUS_VALIDATED, $this->orderStatus($s['order']));
		$this->assertOk($invoice->setPaid($this->user), $invoice, 'Invoice paid');
		$this->assertSame(Commande::STATUS_CLOSED, $this->orderStatus($s['order']));
		$this->assertArrayHasKey((int) $s['rev']->id, DesignTraceLibrary::getDeliveredRevisionIds($this->db, $customer->id));
	}

	public function testOffByDefault()
	{
		$this->setConst('DESIGNTRACE_CLOSE_PAID_DESIGN_ORDERS', null);
		$customer = $this->newCustomer();
		$s = $this->designOrder($customer->id);
		$invoice = $this->invoice($s['order'], 100);
		$this->assertOk($invoice->setPaid($this->user), $invoice, 'Invoice paid');
		$this->assertSame(Commande::STATUS_VALIDATED, $this->orderStatus($s['order']));
	}

	public function testOrderWithGoodsOrNoDesignStaysOpen()
	{
		$this->setConst('DESIGNTRACE_CLOSE_PAID_DESIGN_ORDERS', '1');
		$customer = $this->newCustomer();
		// A product line is something to ship: it closes through its shipments
		$s = $this->designOrder($customer->id, 0);
		$invoice = $this->invoice($s['order'], 100);
		$this->assertOk($invoice->setPaid($this->user), $invoice, 'Invoice paid');
		$this->assertSame(Commande::STATUS_VALIDATED, $this->orderStatus($s['order']));

		// No revision linked: not a design order
		$order = new Commande($this->db);
		$order->socid = $customer->id;
		$order->date = dol_now();
		$this->assertOk($order->create($this->user), $order, 'Create order');
		$order->addline('ZZ consult', 100, 1, 0, 0, 0, 0, 0, 0, 0, 'HT', 0, '', '', 1);
		$this->assertOk($order->valid($this->user), $order, 'Validate order');
		$this->assertFalse(DesignTraceOrderClosing::isPaidDesignOrder($this->db, $order->id));
	}

	public function testDepositAloneDoesNotCloseButTheFinalInvoiceDoes()
	{
		$this->setConst('DESIGNTRACE_CLOSE_PAID_DESIGN_ORDERS', '1');
		$customer = $this->newCustomer();
		$s = $this->designOrder($customer->id);
		$deposit = $this->invoice($s['order'], 50, Facture::TYPE_DEPOSIT);
		$this->assertOk($deposit->setPaid($this->user), $deposit, 'Deposit paid');
		$this->assertSame(Commande::STATUS_VALIDATED, $this->orderStatus($s['order']));

		// The final invoice not yet paid keeps it open; paid, the two come to the order's total
		$final = $this->invoice($s['order'], 50);
		$this->assertFalse(DesignTraceOrderClosing::isPaidDesignOrder($this->db, $s['order']->id));
		$this->assertOk($final->setPaid($this->user), $final, 'Final invoice paid');
		$this->assertSame(Commande::STATUS_CLOSED, $this->orderStatus($s['order']));
	}

	public function testWriteOffsCountOnlyForSmallDifferences()
	{
		$this->setConst('DESIGNTRACE_CLOSE_PAID_DESIGN_ORDERS', '1');
		$customer = $this->newCustomer();
		$s = $this->designOrder($customer->id);
		$invoice = $this->invoice($s['order'], 100);
		$this->assertOk($invoice->setPaid($this->user, Facture::CLOSECODE_OTHER, 'ZZ rest not paid'), $invoice, 'Closed, other');
		$this->assertSame(Commande::STATUS_VALIDATED, $this->orderStatus($s['order']));

		$s = $this->designOrder($customer->id);
		$invoice = $this->invoice($s['order'], 100);
		$this->assertOk($invoice->setPaid($this->user, Facture::CLOSECODE_BANKCHARGE, 'ZZ bank fee'), $invoice, 'Closed, bank charge');
		$this->assertSame(Commande::STATUS_CLOSED, $this->orderStatus($s['order']));
	}

	public function testBadDebtIsNotPaid()
	{
		$this->setConst('DESIGNTRACE_CLOSE_PAID_DESIGN_ORDERS', '1');
		$customer = $this->newCustomer();
		$s = $this->designOrder($customer->id);
		$invoice = $this->invoice($s['order'], 100);
		$this->assertOk($invoice->setPaid($this->user, Facture::CLOSECODE_BADDEBT, 'ZZ never paid'), $invoice, 'Closed as bad debt');
		$this->assertSame(Commande::STATUS_VALIDATED, $this->orderStatus($s['order']));
	}
}
