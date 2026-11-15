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
 * \file        designtrace/test/phpunit/ChangeRequestsTest.php
 * \ingroup     designtrace
 * \brief       Customer change requests: problem/change flow, shared ECOs, comments, files, withdraw, ask for access
 */

dol_include_once('/designtrace/class/changerequests.class.php');
dol_include_once('/designtrace/class/grant.class.php');

class ChangeRequestsTest extends DesignTraceTestCase
{
	/**
	 * A customer holding a released revision through a delivered order
	 */
	private function scenario()
	{
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id, 'label' => 'ZZ Req'), true);
		$part = $this->newComponent($design);
		$rev = $this->newRevision($part, true);
		$order = $this->newOrder($customer->id, true);
		$this->assertGreaterThan(0, $this->linkDoc($rev, 'commande', $order->id), $rev->error);
		return array($customer, $design, $part, $rev, $order);
	}

	private function deliver($order)
	{
		$order->cloture($this->user);
	}

	public function testReportAProblemCreatesTicketAndSharedDraftEco()
	{
		$R = DesignTraceChangeRequests::class;
		list($customer, $design, $part, $rev, $order) = $this->scenario();
		$err = '';

		$this->assertSame(-1, $R::report($this->db, $this->user, $customer->id, 'bogus', $rev->id, 'x', 'y', 0, 'portal:ann', $err), 'Bad type');
		$this->assertSame(-1, $R::report($this->db, $this->user, $customer->id, 'problem', $rev->id, ' ', 'y', 0, 'portal:ann', $err), 'Summary required');
		$this->assertSame(-1, $R::report($this->db, $this->user, $customer->id, 'problem', $rev->id, 'x', '', 0, 'portal:ann', $err), 'Text required');
		$this->assertSame(-1, $R::report($this->db, $this->user, $this->newCustomer('ZZ Other')->id, 'problem', $rev->id, 'x', 'y', 0, 'portal:ann', $err), 'Not their revision');
		$this->assertSame(-1, $R::report($this->db, $this->user, $customer->id, 'problem', $rev->id, 'x', 'y', $order->id, 'portal:ann', $err), 'The order is not delivered yet');
		$this->deliver($order);
		$this->assertSame(-1, $R::report($this->db, $this->user, $customer->id, 'change', $rev->id, 'x', 'y', $order->id, 'portal:ann', $err), 'Only a problem names an order');

		$res = $R::report($this->db, $this->user, $customer->id, 'problem', $rev->id, 'Hole is off-centre', 'The 5 mm hole is 1 mm too far left', $order->id, 'portal:ann', $err, '198.51.100.8');
		$this->assertIsArray($res, $err);
		$eco = new ChangeOrder($this->db);
		$this->assertGreaterThan(0, $eco->fetch($res['eco_id']));
		$this->assertSame(ChangeOrder::STATUS_DRAFT, (int) $eco->status);
		$this->assertSame(ChangeOrder::REASON_CUSTOMER, (int) $eco->reason);
		$this->assertSame(1, $eco->countAffectedComponents());
		$info = $eco->getRequestInfo();
		$this->assertSame(1, (int) $info->shareable);
		$this->assertSame(ChangeOrder::REQUEST_PROBLEM, (int) $info->request_type);
		$this->assertSame('portal:ann', $info->requested_by);
		$this->assertSame($rev->id, (int) $info->fk_revision_source);
		$this->assertSame((int) $order->id, (int) $info->fk_order_source);
		$this->assertSame($res['ticket_id'], (int) $info->fk_ticket);

		$ticket = new Ticket($this->db);
		$this->assertGreaterThan(0, $ticket->fetch($res['ticket_id']));
		$this->assertSame('DESIGN_PROBLEM', $ticket->type_code);
		$this->assertSame((int) $customer->id, (int) $ticket->fk_soc);
		$this->assertStringContainsString('1 mm too far left', $ticket->message);
		$this->assertSame(1, $this->countRows("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."element_element WHERE sourcetype = 'ticket' AND fk_source = ".((int) $res['ticket_id'])." AND targettype = 'designtrace_componentrevision' AND fk_target = ".((int) $rev->id)));
		$this->assertSame(1, $this->countRows("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."element_element WHERE sourcetype = 'commande' AND fk_source = ".((int) $order->id)." AND targettype = 'ticket' AND fk_target = ".((int) $res['ticket_id'])));

		$eco->note_private = 'INTERNAL: supplier is to blame';
		$eco->update($this->user);
		$list = $R::fetchList($this->db, $customer->id);
		$this->assertCount(1, $list);
		$this->assertSame('problem', $list[0]['type']);
		$this->assertSame('open', $list[0]['status']);
		$this->assertSame($res['ticket_ref'], $list[0]['ticket_ref']);
		$this->assertCount(0, $R::fetchList($this->db, $this->newCustomer('ZZ Other')->id));
		$detail = $R::fetchDetail($this->db, $customer->id, $eco->id);
		$this->assertSame('The 5 mm hole is 1 mm too far left', $detail['request_text']);
		$this->assertStringNotContainsString('INTERNAL', json_encode($detail), 'Never internal notes');
		$this->assertSame(array('opened'), array_column($detail['timeline'], 'type'));
		$this->assertSame('portal:ann', $detail['timeline'][0]['actor']);
		$this->assertSame(array($part->id), array_column($detail['components'], 'id'));
		$this->assertTrue($detail['can_withdraw']);
		$this->assertSame((int) $order->id, $detail['order_id']);
		$this->assertNull($R::fetchDetail($this->db, $this->newCustomer('ZZ Other2')->id, $eco->id));

		// A change needs no order
		$this->assertIsArray($R::report($this->db, $this->user, $customer->id, 'change', $rev->id, 'Add a chamfer', 'Please chamfer the edges', 0, 'portal:ann', $err), $err);
		$this->assertCount(2, $R::fetchList($this->db, $customer->id));
	}

	public function testCommentsFilesAndWithdraw()
	{
		global $conf;

		$R = DesignTraceChangeRequests::class;
		list($customer, $design, $part, $rev, $order) = $this->scenario();
		$err = '';
		$res = $R::report($this->db, $this->user, $customer->id, 'change', $rev->id, 'Thicker', 'Make it 3 mm thick', 0, 'portal:ann', $err);
		$ecoid = $res['eco_id'];

		$this->assertGreaterThan(0, $R::comment($this->db, $this->user, $customer->id, $ecoid, 'Also in black please', 'portal:bob', $err), $err);
		$this->assertLessThan(0, $R::comment($this->db, $this->user, $customer->id, $ecoid, '  ', 'portal:bob', $err));
		$this->assertLessThan(0, $R::comment($this->db, $this->user, $this->newCustomer('ZZ Other')->id, $ecoid, 'x', 'portal:bob', $err));
		$comments = $R::fetchDetail($this->db, $customer->id, $ecoid)['comments'];
		$this->assertStringContainsString('Also in black please', end($comments)['text']);
		$this->assertStringContainsString('portal:bob', end($comments)['text']);

		$stored = $R::addReferenceFile($this->db, $customer->id, $ecoid, '../sketch 1.png', 'PNGDATA', 'portal:ann', $err);
		$this->assertSame('customer-sketch 1.png', $stored, $err);
		$exe = $R::addReferenceFile($this->db, $customer->id, $ecoid, 'run.php', '<?php echo 1;', 'portal:ann', $err);
		$this->assertStringEndsWith('.noexe', $exe, 'Executables are neutralised as core does');
		$this->assertSame('', $R::addReferenceFile($this->db, $customer->id, $ecoid, 'empty.txt', '', 'portal:ann', $err));
		$this->assertSame('', $R::addReferenceFile($this->db, $this->newCustomer('ZZ Other')->id, $ecoid, 'a.txt', 'x', 'portal:ann', $err));
		$detail = $R::fetchDetail($this->db, $customer->id, $ecoid);
		$this->assertSame(array('customer-run.php.noexe', 'customer-sketch 1.png'), array_column($detail['reference_files'], 'filename'));
		$eco = new ChangeOrder($this->db);
		$eco->fetch($ecoid);
		$dir = $conf->designtrace->multidir_output[$conf->entity].'/changeorder/'.dol_sanitizeFileName($eco->ref);
		$this->assertFileExists($dir.'/customer-sketch 1.png');
		dol_delete_dir_recursive($dir);

		// Withdraw: both cancelled, both kept
		$this->assertGreaterThan(0, $R::withdraw($this->db, $this->user, $customer->id, $ecoid, 'portal:ann', 'No longer needed', $err), $err);
		$eco->fetch($ecoid);
		$this->assertSame(ChangeOrder::STATUS_CANCELED, (int) $eco->status);
		$ticket = new Ticket($this->db);
		$ticket->fetch($res['ticket_id']);
		$this->assertSame(Ticket::STATUS_CANCELED, (int) $ticket->status);
		$detail = $R::fetchDetail($this->db, $customer->id, $ecoid);
		$this->assertSame('withdrawn', $detail['status']);
		$this->assertContains('withdrawn', array_column($detail['timeline'], 'type'));
		$this->assertStringContainsString('withdrew', end($detail['comments'])['text']);
		$this->assertFalse($detail['can_withdraw']);
		$this->assertFalse($detail['can_attach']);
		$this->assertLessThan(0, $R::withdraw($this->db, $this->user, $customer->id, $ecoid, 'portal:ann', '', $err), 'Only once');
		$this->assertLessThan(0, $R::comment($this->db, $this->user, $customer->id, $ecoid, 'late', 'portal:ann', $err), 'Ticket is closed');
		$this->assertSame('', $R::addReferenceFile($this->db, $customer->id, $ecoid, 'late.txt', 'x', 'portal:ann', $err));
		$this->assertCount(1, $R::fetchList($this->db, $customer->id), 'Kept in history');
	}

	public function testOnlyApprovedEcosCannotBeWithdrawnAndShareableToggle()
	{
		$R = DesignTraceChangeRequests::class;
		list($customer, $design, $part, $rev, $order) = $this->scenario();
		$err = '';
		$res = $R::report($this->db, $this->user, $customer->id, 'change', $rev->id, 'Thicker', 'Make it thicker', 0, 'portal:ann', $err);
		$eco = new ChangeOrder($this->db);
		$eco->fetch($res['eco_id']);
		$ok = $eco->approve($this->user);
		$this->assertGreaterThan(0, $ok, 'approve='.var_export($ok, true).' err='.$eco->error.' status='.$eco->status.' comps='.$eco->countAffectedComponents());
		$this->assertLessThan(0, $R::withdraw($this->db, $this->user, $customer->id, $eco->id, 'portal:ann', '', $err));
		$this->assertSame('approved', $R::fetchDetail($this->db, $customer->id, $eco->id)['status']);

		// An ECO raised by staff is shared only when its toggle is on, and cannot be withdrawn by the customer
		$staff = new ChangeOrder($this->db);
		$staff->label = 'ZZ internal';
		$staff->reason = ChangeOrder::REASON_COST;
		$staff->fk_soc = $customer->id;
		$this->assertOk($staff->create($this->user), $staff, 'Create ECO');
		$this->assertCount(1, $R::fetchList($this->db, $customer->id));
		$this->assertNull($R::fetchDetail($this->db, $customer->id, $staff->id));
		$this->assertGreaterThan(0, $staff->setShareable(true));
		$this->assertCount(2, $R::fetchList($this->db, $customer->id));
		$d = $R::fetchDetail($this->db, $customer->id, $staff->id);
		$this->assertSame('', $d['type']);
		$this->assertFalse($d['can_withdraw']);
		$this->assertLessThan(0, $R::withdraw($this->db, $this->user, $customer->id, $staff->id, 'portal:ann', '', $err));
		$this->assertGreaterThan(0, $staff->setShareable(false));
		$this->assertCount(1, $R::fetchList($this->db, $customer->id));
	}

	public function testLinkedQuoteMakesTheEcoWait()
	{
		$R = DesignTraceChangeRequests::class;
		list($customer, $design, $part, $rev, $order) = $this->scenario();
		$err = '';
		$res = $R::report($this->db, $this->user, $customer->id, 'change', $rev->id, 'Bigger', 'Scale up 10%', 0, 'portal:ann', $err);
		$eco = new ChangeOrder($this->db);
		$eco->fetch($res['eco_id']);
		$quote = $this->newQuote($customer->id);

		$this->assertLessThan(0, $eco->linkQuote($this->newQuote($this->newCustomer('ZZ Other')->id)->id), 'Another customer\'s quote');
		$this->assertGreaterThan(0, $eco->linkQuote($quote->id));
		$this->assertFalse($eco->isQuoteSettled());
		$this->assertLessThan(0, $eco->approve($this->user), 'Waits on the quote');
		$this->assertStringContainsString($eco->ref, $eco->error);
		$detail = $R::fetchDetail($this->db, $customer->id, $eco->id);
		$this->assertTrue($detail['waiting_for_quote']);
		$this->assertSame((int) $quote->id, $detail['quote']['id']);
		$this->assertFalse($detail['quote']['accepted']);

		$this->db->query("UPDATE ".MAIN_DB_PREFIX."propal SET fk_statut = 2 WHERE rowid = ".((int) $quote->id));
		$this->assertTrue($eco->isQuoteSettled());
		$this->assertTrue($R::fetchDetail($this->db, $customer->id, $eco->id)['quote']['accepted']);
		$eco->error = '';
		$this->assertGreaterThan(0, $eco->approve($this->user));
		$this->assertGreaterThan(0, $eco->linkQuote(0));
	}

	public function testAskForAccessAgain()
	{
		$R = DesignTraceChangeRequests::class;
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id, 'label' => 'ZZ Gone'), true);
		$rev = $this->newRevision($this->newComponent($design), true);
		$grantid = DesignTraceGrant::create($this->db, $this->user, $customer->id, $design->id);
		$err = '';

		$this->assertSame(-1, $R::askAccess($this->db, $this->user, $customer->id, $design->id, '', 'portal:ann', $err), 'Still has access');
		$this->assertSame(-1, $R::askAccess($this->db, $this->user, $this->newCustomer('ZZ Other')->id, $design->id, '', 'portal:ann', $err), 'Never had it');
		DesignTraceGrant::revoke($this->db, $this->user, $grantid);
		$res = $R::askAccess($this->db, $this->user, $customer->id, $design->id, 'We need it for a repair', 'portal:ann', $err);
		$this->assertIsArray($res, $err);
		$this->assertFalse($res['existing']);
		$ticket = new Ticket($this->db);
		$ticket->fetch($res['ticket_id']);
		$this->assertStringContainsString($design->ref, $ticket->subject);
		$this->assertStringContainsString('We need it for a repair', $ticket->message);
		$again = $R::askAccess($this->db, $this->user, $customer->id, $design->id, '', 'portal:bob', $err);
		$this->assertTrue($again['existing']);
		$this->assertSame($res['ticket_id'], $again['ticket_id']);
	}
}
