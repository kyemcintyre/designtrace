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
 * \file        designtrace/test/phpunit/CardBlocksTest.php
 * \ingroup     designtrace
 * \brief       Card blocks for grants and customer requests, and reference files on the ticket
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/designtrace/class/changerequests.class.php');
dol_include_once('/designtrace/lib/designtrace_grants.lib.php');
dol_include_once('/designtrace/lib/designtrace_changeorder.lib.php');

class CardBlocksTest extends DesignTraceTestCase
{
	private function render($function, $object)
	{
		global $db, $user, $form;
		$db = $this->db;
		$user = $this->user;
		$form = new Form($this->db);
		ob_start();
		$function($object);
		return ob_get_clean();
	}

	public function testGrantsBlockListsGrantsAndRevocation()
	{
		$customer = $this->newCustomer('ZZ Grantee');
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$this->newRevision($this->newComponent($design), true);

		$html = $this->render('designtracePrintGrants', $design);
		$this->assertStringContainsString('name="action" value="grant_add"', $html);

		$grantid = DesignTraceGrant::create($this->db, $this->user, $customer->id, $design->id);
		$html = $this->render('designtracePrintGrants', $design);
		$this->assertStringContainsString('ZZ Grantee', $html);
		$this->assertStringContainsString('action=grant_revoke&grantid='.$grantid, $html);

		DesignTraceGrant::revoke($this->db, $this->user, $grantid);
		$html = $this->render('designtracePrintGrants', $design);
		$this->assertStringNotContainsString('action=grant_revoke', $html, 'An ended grant is kept but cannot be revoked again');
	}

	public function testCustomerRequestBlockAndTicketCopyOfReferenceFiles()
	{
		global $conf;
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$rev = $this->newRevision($this->newComponent($design), true);
		$order = $this->newOrder($customer->id, true);
		$this->linkDoc($rev, 'commande', $order->id);
		$err = '';
		$res = DesignTraceChangeRequests::report($this->db, $this->user, $customer->id, 'change', $rev->id, 'Wider slot', 'Make the slot 2 mm wider', 0, 'portal:ann', $err);
		$this->assertIsArray($res, $err);
		$eco = new ChangeOrder($this->db);
		$eco->fetch($res['eco_id']);

		$html = $this->render('designtracePrintEcoCustomerRequest', $eco);
		$this->assertStringContainsString('portal:ann', $html);
		$this->assertStringContainsString('eco_setshareable', $html);
		$this->assertStringContainsString('Round', $html);

		$stored = DesignTraceChangeRequests::addReferenceFile($this->db, $customer->id, $eco->id, 'sketch.png', 'PNGDATA', 'portal:ann', $err);
		$this->assertSame('customer-sketch.png', $stored, $err);
		$ticket = new Ticket($this->db);
		$ticket->fetch($res['ticket_id']);
		$path = $conf->ticket->dir_output.'/'.dol_sanitizeFileName($ticket->ref).'/'.$stored;
		$this->assertFileExists($path, 'Reference file copied to the ticket');
		dol_delete_dir_recursive($conf->ticket->dir_output.'/'.dol_sanitizeFileName($ticket->ref));
	}
}
