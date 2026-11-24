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
 * \file        designtrace/test/phpunit/StaffApiTest.php
 * \ingroup     designtrace
 * \brief       Staff endpoints of the REST API: rights, triage, grants, visibility, links, revisions, ECOs, licences, metrics
 */

dol_include_once('/designtrace/class/grant.class.php');
dol_include_once('/designtrace/class/approvals.class.php');

class StaffApiTest extends DesignTraceTestCase
{
	/**
	 * @var DesignTrace
	 */
	private $api;

	protected function setUp(): void
	{
		parent::setUp();
		// See ApiLibraryTest: Restler's own loader and exception are not loaded under PHPUnit
		require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';
		if (!class_exists('Luracast\\Restler\\RestException', false)) {
			eval('namespace Luracast\\Restler; class RestException extends \\Exception { public function __construct($code, $message = null) { parent::__construct((string) $message, $code); } }');
		}
		if (!class_exists('DolibarrApiAccess', false)) {
			eval('class DolibarrApiAccess { public static $user; }');
		}
		dol_include_once('/designtrace/class/api_designtrace.class.php');
		DolibarrApiAccess::$user = $this->user;
		$this->api = new DesignTrace();
	}

	private function expectCode($code, callable $call, $contains = '')
	{
		try {
			$call();
		} catch (\Luracast\Restler\RestException $e) {
			$this->assertSame($code, $e->getCode(), $e->getMessage());
			if ($contains !== '') {
				$this->assertStringContainsString($contains, $e->getMessage());
			}
			return;
		}
		$this->fail('Expected a '.$code);
	}

	private function asUser($user)
	{
		DolibarrApiAccess::$user = $user;
	}

	private function inReview($revision)
	{
		$revision->fetch($revision->id);
		$this->assertGreaterThan(0, $revision->validate($this->user));
		$revision->fetch($revision->id);
		return $revision;
	}

	public function testRightsAndExternalUsers()
	{
		$none = $this->newUser();
		$reader = $this->newUser(array('design/read', 'componentrevision/read'));
		$this->asUser($none);
		$this->expectCode(403, function () {
			$this->api->getTriage();
		}, 'design read');
		$this->asUser($reader);
		$this->assertArrayHasKey('counts', $this->api->getTriage());
		$this->assertArrayHasKey('approval_turnaround', $this->api->getStaffMetrics());
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$this->expectCode(403, function () use ($customer, $design) {
			$this->api->createStaffGrant($customer->id, $design->id);
		}, 'design write');
		$this->expectCode(403, function () {
			$this->api->approveStaffEco(1);
		}, 'changeorder approve');
		$this->expectCode(403, function () {
			$this->api->setStaffFileVisibility(1, 'now');
		}, 'managevisibility');
		$this->expectCode(403, function () {
			$this->api->forceStaffReacceptance(1);
		}, 'licence write');

		$external = $this->newUser(array('design/read'));
		$external->socid = $customer->id;
		$this->asUser($external);
		$this->expectCode(403, function () {
			$this->api->getTriage();
		}, 'staff users only');
	}

	public function testTriageQueue()
	{
		$customer = $this->newCustomer();
		$licence = $this->newLicence('ZZTRI', false);
		$design = $this->newDesign(array('fk_soc' => $customer->id, 'fk_licence' => $licence->id, 'label' => 'ZZ Triage'), true);
		$secret = $this->newDesign(array('fk_soc' => $customer->id, 'confidential' => 1), true);
		$rev = $this->newRevision($this->newComponent($design, Component::TYPE_PART, 'ZZ t1'));
		$srev = $this->newRevision($this->newComponent($secret, Component::TYPE_PART, 'ZZ t2'));
		foreach (array($rev, $srev) as $r) {
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_componentrevision SET needs_approval = 1 WHERE rowid = ".((int) $r->id));
			$this->inReview($r);
		}
		$ok = $this->newRevision($this->newComponent($design, Component::TYPE_PART, 'ZZ t3'), true);
		DesignTraceGrant::create($this->db, $this->user, $customer->id, $design->id);

		$staffEco = new ChangeOrder($this->db);
		$staffEco->label = 'ZZ staff';
		$staffEco->reason = ChangeOrder::REASON_COST;
		$this->assertOk($staffEco->create($this->user), $staffEco, 'ECO');
		$customerEco = new ChangeOrder($this->db);
		$customerEco->label = 'ZZ customer';
		$customerEco->reason = ChangeOrder::REASON_CUSTOMER;
		$customerEco->fk_soc = $customer->id;
		$this->assertOk($customerEco->create($this->user), $customerEco, 'ECO');
		$customerEco->markCustomerRequest(ChangeOrder::REQUEST_CHANGE, 'portal:ann', $rev->id);

		$triage = $this->api->getTriage();
		$approvals = array_column($triage['approvals'], null, 'revision_id');
		$this->assertArrayHasKey($rev->id, $approvals);
		$this->assertSame('awaiting_customer', $approvals[$rev->id]['state']);
		$this->assertSame((int) $customer->id, $approvals[$rev->id]['socid']);
		$this->assertNotNull($approvals[$rev->id]['in_review_since']);
		$this->assertArrayHasKey($srev->id, $approvals, 'The admin sees confidential designs');
		$ecos = array_column($triage['ecos'], null, 'id');
		$this->assertTrue($ecos[$customerEco->id]['customer_request']);
		$this->assertSame('approve', $ecos[$customerEco->id]['next_action']);
		$this->assertFalse($ecos[$staffEco->id]['customer_request']);
		$ids = array_column($triage['ecos'], 'id');
		$this->assertLessThan(array_search($staffEco->id, $ids), array_search($customerEco->id, $ids), 'Customer requests first');
		$this->assertContains($design->id, array_column($triage['licences'], 'design_id'));
		$this->assertSame($triage['counts']['approvals'], count($triage['approvals']));

		// A staff user who cannot see confidential designs does not see theirs
		$reader = $this->newUser(array('design/read', 'componentrevision/read'));
		$this->asUser($reader);
		$approvals = array_column($this->api->getTriage()['approvals'], null, 'revision_id');
		$this->assertArrayHasKey($rev->id, $approvals);
		$this->assertArrayNotHasKey($srev->id, $approvals);
		$this->expectCode(404, function () use ($customer, $secret) {
			$this->api->getStaffCustomerDesign($customer->id, $secret->id);
		});
		unset($ok);
	}

	public function testDesignList()
	{
		$customer = $this->newCustomer();
		$owned = $this->newDesign(array('fk_soc' => $customer->id, 'label' => 'ZZ List customer design'), true);
		$mine = $this->newDesign(array('label' => 'ZZ List workshop design', 'ownership' => Design::OWNERSHIP_WORKSHOP), true);
		$secret = $this->newDesign(array('label' => 'ZZ List secret', 'confidential' => 1), true);
		$rev = $this->newRevision($this->newComponent($owned, Component::TYPE_PART, 'ZZ list part'));
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_componentrevision SET needs_approval = 1 WHERE rowid = ".((int) $rev->id));
		$this->inReview($rev);

		$all = $this->api->getStaffDesigns('ZZ List');
		$byid = array_column($all['items'], null, 'id');
		$this->assertArrayHasKey($owned->id, $byid);
		$this->assertArrayHasKey($mine->id, $byid, 'A design with no customer is listed');
		$this->assertSame(0, $byid[$mine->id]['socid']);
		$this->assertSame('workshop', $byid[$mine->id]['ownership']);
		$this->assertSame(1, $byid[$owned->id]['parts']);
		$this->assertTrue($byid[$owned->id]['needs']['approval']);
		$this->assertTrue($byid[$owned->id]['needs_action']);
		$this->assertFalse($byid[$mine->id]['needs_action']);

		$none = array_column($this->api->getStaffDesigns('ZZ List', 'none')['items'], 'id');
		$this->assertContains($mine->id, $none);
		$this->assertNotContains($owned->id, $none);
		$of = array_column($this->api->getStaffDesigns('ZZ List', (string) $customer->id)['items'], 'id');
		$this->assertSame(array($owned->id), $of);
		$needs = array_column($this->api->getStaffDesigns('ZZ List', '', '', '', 'approval')['items'], 'id');
		$this->assertSame(array($owned->id), $needs);
		$this->assertContains($owned->id, array_column($this->api->getStaffDesigns((string) $owned->id)['items'], 'id'), 'Found by id');
		$this->assertSame(1, count($this->api->getStaffDesigns('ZZ List', '', '', '', '', '', 0, 0, 0, 'ref', 0, 1)['items']));

		$this->expectCode(400, function () {
			$this->api->getStaffDesigns('', 'abc');
		});
		$this->expectCode(400, function () {
			$this->api->getStaffDesigns('', '', '', 'nope');
		});
		$this->expectCode(400, function () {
			$this->api->getStaffDesigns('', '', '', '', '', 'quote', 0);
		});

		// A staff user who cannot see confidential designs does not get them
		$this->assertArrayHasKey($secret->id, array_column($this->api->getStaffDesigns('ZZ List secret')['items'], null, 'id'));
		$reader = $this->newUser(array('design/read'));
		$this->asUser($reader);
		$this->assertArrayNotHasKey($secret->id, array_column($this->api->getStaffDesigns('ZZ List secret')['items'], null, 'id'));
		$this->asUser($this->newUser());
		$this->expectCode(403, function () {
			$this->api->getStaffDesigns();
		});
	}

	public function testGrantsFileVisibilityAndCustomerView()
	{
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$part = $this->newComponent($design);
		$a = $this->newRevision($part);
		$pdf = $this->addFile($a, 'a.pdf', '%PDF', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_HIDDEN);
		$gcode = $this->addFile($a, 'a.gcode', 'G1', RevisionFile::ROLE_PRODUCTION, RevisionFile::VISIBILITY_HIDDEN);
		$this->release($a);

		$g = $this->api->createStaffGrant($customer->id, $design->id, array(), array(), '+30 days', 'trial');
		$this->assertGreaterThan(0, $g['id']);
		$this->expectCode(409, function () use ($customer, $design) {
			$this->api->createStaffGrant($customer->id, $this->newDesign(array('fk_soc' => $customer->id), true)->id);
		}, 'Nothing released');
		$this->expectCode(400, function () use ($g) {
			$this->api->setStaffGrantExpiry($g['id'], 'not a date');
		});
		$list = $this->api->listStaffGrants($customer->id);
		$this->assertTrue($list[0]['active']);
		$this->assertNotNull($list[0]['date_expiry']);
		$this->assertSame(array($part->id => $a->id), $list[0]['pins']);

		$view = $this->api->getStaffCustomerDesign($customer->id, $design->id);
		$files = array_column($view['files'], null, 'filename');
		$this->assertFalse($files[$pdf->filename]['shown_to_customer']);
		$this->assertSame('hidden', $files[$pdf->filename]['reason_not_shown']);
		$this->assertSame('production file', $files[$gcode->filename]['reason_not_shown']);
		$this->assertCount(1, $view['grants']);
		$this->assertNull($view['licence']);

		$this->assertSame('now', $this->api->setStaffFileVisibility($pdf->id, 'now')['visibility']);
		$this->expectCode(400, function () use ($pdf) {
			$this->api->setStaffFileVisibility($pdf->id, 'sometimes');
		});
		$this->expectCode(409, function () use ($gcode) {
			$this->api->setStaffFileVisibility($gcode->id, 'now');
		}, 'production');
		$this->expectCode(404, function () {
			$this->api->setStaffFileVisibility(999999999, 'now');
		});
		$view = $this->api->getStaffCustomerDesign($customer->id, $design->id);
		$this->assertTrue(array_column($view['files'], null, 'filename')[$pdf->filename]['shown_to_customer']);
		$this->assertCount(1, $this->api->getStaffLibrary($customer->id)['items']);

		// Pins
		$b = $this->newRevision($part);
		$this->release($b);
		$this->assertTrue($this->api->moveStaffGrantPin($g['id'], $part->id, $b->id)['ok']);
		// The pin moved on, but the grant still opens Rev A's file: the staff view shows it as the customer sees it
		$view = $this->api->getStaffCustomerDesign($customer->id, $design->id);
		$this->assertTrue(array_column($view['files'], null, 'filename')[$pdf->filename]['shown_to_customer']);
		$this->expectCode(409, function () use ($g, $part) {
			$this->api->moveStaffGrantPin($g['id'], $part->id, $this->newRevision($this->newComponent($this->newDesign(array(), true)))->id);
		});
		$this->assertTrue($this->api->setStaffGrantExpiry($g['id'], '')['ok']);
		$this->assertNull($this->api->listStaffGrants($customer->id)[0]['date_expiry']);
		$this->assertTrue($this->api->revokeStaffGrant($g['id'])['revoked']);
		$this->assertFalse($this->api->revokeStaffGrant($g['id'])['revoked']);
		$this->expectCode(404, function () {
			$this->api->revokeStaffGrant(999999999);
		});
		$this->assertFalse($this->api->listStaffGrants($customer->id)[0]['active']);
		$this->assertTrue($this->api->getStaffCustomerDesign($customer->id, $design->id)['access_ended']);
	}

	public function testLinksAndRevisionEdits()
	{
		$customer = $this->newCustomer();
		$other = $this->newCustomer('ZZ Other');
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$part = $this->newComponent($design);
		$rev = $this->newRevision($part);
		$quote = $this->newQuote($customer->id);

		$this->assertTrue($this->api->linkStaffDesign($design->id, 'quote', $quote->id)['ok']);
		$this->assertTrue($this->api->linkStaffDesign($design->id, 'quote', $quote->id)['ok'], 'Idempotent');
		$this->expectCode(409, function () use ($design, $other) {
			$this->api->linkStaffDesign($design->id, 'quote', $this->newQuote($other->id)->id);
		}, 'another customer');
		$this->expectCode(400, function () use ($design, $quote) {
			$this->api->linkStaffDesign($design->id, 'ticket', $quote->id);
		});

		$this->assertTrue($this->api->linkStaffRevision($rev->id, 'quote', $quote->id)['ok']);
		$this->expectCode(409, function () use ($rev, $quote) {
			$this->api->linkStaffRevision($rev->id, 'quote', $quote->id);
		});
		$this->expectCode(400, function () use ($rev, $quote) {
			$this->api->linkStaffRevision($rev->id, 'banana', $quote->id);
		});
		$this->assertTrue($this->api->setStaffLinkVisible($rev->id, 'quote', $quote->id, 0)['visible'] === false);
		$this->expectCode(404, function () use ($rev) {
			$this->api->setStaffLinkVisible($rev->id, 'quote', 999999999, 1);
		});
		$this->assertTrue($this->api->unlinkStaffRevision($rev->id, 'quote', $quote->id)['unlinked']);
		$this->assertFalse($this->api->unlinkStaffRevision($rev->id, 'quote', $quote->id)['unlinked']);

		// A delivered order keeps its revisions
		$released = $this->newRevision($this->newComponent($design), true);
		$order = $this->newOrder($customer->id, true);
		$this->assertTrue($this->api->linkStaffRevision($released->id, 'order', $order->id)['ok']);
		$order->cloture($this->user);
		$this->expectCode(409, function () use ($released, $order) {
			$this->api->unlinkStaffRevision($released->id, 'order', $order->id);
		}, $order->ref);

		// Release note and flag on a draft; released revisions are read-only
		$r = $this->api->updateStaffRevision($rev->id, array('customer_note' => 'Stronger', 'needs_approval' => 1));
		$this->assertSame('Stronger', $r['customer_note']);
		$this->assertSame(1, $r['needs_approval']);
		$this->expectCode(409, function () use ($released) {
			$this->api->updateStaffRevision($released->id, array('customer_note' => 'x'));
		});
		$this->expectCode(404, function () {
			$this->api->updateStaffRevision(999999999, array());
		});

		// Release is blocked while the customer has not approved
		$this->inReview($rev);
		$this->expectCode(409, function () use ($rev) {
			$this->api->releaseStaffRevision($rev->id);
		}, $rev->ref);
		$this->api->linkStaffRevision($rev->id, 'quote', $quote->id);
		$done = DesignTraceApprovals::approve($this->db, $this->user, $customer->id, 'design', $design->id, 'admin', '', 'api', 'portal:ann', $err);
		$this->assertIsArray($done);
		$res = $this->api->releaseStaffRevision($rev->id);
		$this->assertSame(ComponentRevision::STATUS_RELEASED, $res['status']);
		$this->expectCode(409, function () use ($rev) {
			$this->api->releaseStaffRevision($rev->id);
		});
	}

	public function testStaffRecordsTheCustomersDecision()
	{
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$revs = array();
		foreach (array('a', 'b', 'c') as $name) {
			$rev = $this->newRevision($this->newComponent($design, Component::TYPE_PART, 'ZZ '.$name));
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_componentrevision SET needs_approval = 1 WHERE rowid = ".((int) $rev->id));
			$revs[$name] = $this->inReview($rev);
		}

		$reader = $this->newUser(array('design/read', 'componentrevision/read', 'componentrevision/write'));
		$this->asUser($reader);
		$this->expectCode(403, function () use ($revs) {
			$this->api->recordStaffCustomerDecision($revs['a']->id, array('decision' => 'approve', 'received' => 'phone'));
		}, 'componentrevision release');
		$this->asUser($this->user);

		$this->expectCode(404, function () {
			$this->api->recordStaffCustomerDecision(999999999, array('decision' => 'approve', 'received' => 'phone'));
		});
		$this->expectCode(400, function () use ($revs) {
			$this->api->recordStaffCustomerDecision($revs['a']->id, array('decision' => 'maybe', 'received' => 'phone'));
		}, 'decision');
		$this->expectCode(400, function () use ($revs) {
			$this->api->recordStaffCustomerDecision($revs['a']->id, array('decision' => 'approve'));
		}, 'received');
		$this->expectCode(409, function () use ($revs) {
			$this->api->recordStaffCustomerDecision($revs['a']->id, array('decision' => 'changes', 'received' => 'email'));
		});

		// Release is blocked until the decision is recorded
		$this->expectCode(409, function () use ($revs) {
			$this->api->releaseStaffRevision($revs['a']->id);
		}, $revs['a']->ref);
		$res = $this->api->recordStaffCustomerDecision($revs['a']->id, array('decision' => 'approve', 'received' => 'phone, Ann', 'comment' => 'Happy'));
		$this->assertSame(array('decision' => 'approved', 'revision_ids' => array((int) $revs['a']->id)), $res);
		$this->assertSame(ComponentRevision::STATUS_RELEASED, $this->api->releaseStaffRevision($revs['a']->id)['status']);
		$this->expectCode(409, function () use ($revs) {
			$this->api->recordStaffCustomerDecision($revs['a']->id, array('decision' => 'approve', 'received' => 'phone'));
		});

		// One approval for everything waiting in the design, then changes on one that was left
		$res = $this->api->recordStaffCustomerDecision($revs['b']->id, array('decision' => 'approve', 'received' => 'email, Ann', 'whole_scope' => true));
		$this->assertEqualsCanonicalizing(array((int) $revs['b']->id, (int) $revs['c']->id), $res['revision_ids']);
		$this->assertSame(2, $this->countRows("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."designtrace_approval WHERE channel = 'staff' AND decision = 1 AND fk_revision IN (".((int) $revs['b']->id).", ".((int) $revs['c']->id).")"));

		$d = $this->newRevision($this->newComponent($design, Component::TYPE_PART, 'ZZ d'));
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_componentrevision SET needs_approval = 1 WHERE rowid = ".((int) $d->id));
		$d = $this->inReview($d);
		$res = $this->api->recordStaffCustomerDecision($d->id, array('decision' => 'changes', 'received' => 'in person', 'comment' => 'Hole too small'));
		$this->assertSame('changes', $res['decision']);
		$this->assertGreaterThan(0, $res['eco_id']);
		$this->assertSame(1, $res['round']);
		$d->fetch($d->id);
		$this->assertSame(ComponentRevision::STATUS_DECLINED, (int) $d->status);
	}

	public function testTriageListsDeclinedRevisionsUntilFollowedUp()
	{
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$secret = $this->newDesign(array('fk_soc' => $customer->id, 'confidential' => 1), true);
		$revs = array();
		foreach (array('open' => $design, 'secret' => $secret) as $name => $d) {
			$rev = $this->newRevision($this->newComponent($d, Component::TYPE_PART, 'ZZ '.$name));
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_componentrevision SET needs_approval = 1 WHERE rowid = ".((int) $rev->id));
			$rev = $this->inReview($rev);
			$this->api->recordStaffCustomerDecision($rev->id, array('decision' => 'changes', 'received' => 'phone', 'comment' => 'Thicker'));
			$revs[$name] = $rev;
		}

		$triage = $this->api->getTriage();
		foreach (array('approvals', 'ecos', 'licences', 'needs_update', 'counts') as $key) {
			$this->assertArrayHasKey($key, $triage, 'Existing keys are unchanged');
		}
		$this->assertArrayNotHasKey($revs['open']->id, array_column($triage['approvals'], null, 'revision_id'), 'Not waiting on the customer any more');
		$declined = array_column($triage['declined'], null, 'revision_id');
		$this->assertArrayHasKey($revs['open']->id, $declined);
		$this->assertSame('staff:'.$this->user->login, $declined[$revs['open']->id]['declined_by']);
		$this->assertGreaterThan(0, $declined[$revs['open']->id]['eco_id']);
		$this->assertSame(count($triage['declined']), $triage['counts']['declined']);

		// A staff user who cannot see confidential designs does not see theirs
		$this->asUser($this->newUser(array('design/read', 'componentrevision/read')));
		$declined = array_column($this->api->getTriage()['declined'], null, 'revision_id');
		$this->assertArrayHasKey($revs['open']->id, $declined);
		$this->assertArrayNotHasKey($revs['secret']->id, $declined);
		$this->asUser($this->user);

		// Gone once the workshop makes the new revision
		$revs['open']->fetch($revs['open']->id);
		$this->assertIsObject($revs['open']->createFromClone($this->user, $revs['open']->id));
		$this->assertArrayNotHasKey($revs['open']->id, array_column($this->api->getTriage()['declined'], null, 'revision_id'));
	}

	public function testDeclineLinkedRunsTheCascadeAgain()
	{
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$part = $this->inReview($this->newRevision($this->newComponent($design, Component::TYPE_PART, 'ZZ part')));
		$proof = $this->newRevision($this->newComponent($design, Component::TYPE_DRAWING, 'ZZ proof', Component::DRAWING_PROOF));
		$this->assertGreaterThan(0, (int) $this->newLink($proof, $part, ComponentLink::TYPE_DEPICTS)->id);
		$proof = $this->inReview($proof);

		$this->expectCode(409, function () use ($proof) {
			$this->api->declineStaffLinkedRevisions($proof->id);
		});
		ComponentRevision::markDeclined($this->db, (int) $proof->id);

		$reader = $this->newUser(array('design/read', 'componentrevision/read', 'componentrevision/write'));
		$this->asUser($reader);
		$this->expectCode(403, function () use ($proof) {
			$this->api->declineStaffLinkedRevisions($proof->id);
		}, 'componentrevision release');
		$this->asUser($this->user);

		$dry = $this->api->declineStaffLinkedRevisions($proof->id, array('dry_run' => true));
		$this->assertTrue($dry['dry_run']);
		$this->assertSame(array(array('id' => (int) $part->id, 'ref' => $part->ref)), $dry['declined']);
		$part->fetch($part->id);
		$this->assertSame(ComponentRevision::STATUS_INREVIEW, (int) $part->status);

		$res = $this->api->declineStaffLinkedRevisions($proof->id);
		$this->assertFalse($res['dry_run']);
		$this->assertSame($dry['declined'], $res['declined']);
		$part->fetch($part->id);
		$this->assertSame(ComponentRevision::STATUS_DECLINED, (int) $part->status);
		$this->assertSame(array(), $this->api->declineStaffLinkedRevisions($proof->id)['declined']);

		// Triage names the parent it was declined with, and when
		$declined = array_column($this->api->getTriage()['declined'], null, 'revision_id');
		$this->assertSame($proof->ref, $declined[$part->id]['declined_with']);
		$this->assertNotEmpty($declined[$part->id]['declined_at']);
		$this->assertSame('', $declined[$part->id]['declined_by']);
		$this->assertNull($declined[$proof->id]['declined_with'], 'Declined itself, not with a parent');
	}

	public function testEcoActionsAndForcedReacceptance()
	{
		$customer = $this->newCustomer();
		$licence = $this->newLicence('ZZSTAFF', false);
		$design = $this->newDesign(array('fk_soc' => $customer->id, 'fk_licence' => $licence->id), true);
		$part = $this->newComponent($design);
		$rev = $this->newRevision($part, true);
		$quote = $this->newQuote($customer->id);
		$err = '';
		$this->assertIsArray(DesignTraceChangeRequests::report($this->db, $this->user, $customer->id, 'change', $this->linkedRevision($rev, $customer), 'Thicker', 'Make it thicker', 0, 'portal:ann', $err), $err);
		$ecoid = (int) $this->db->fetch_object($this->db->query("SELECT MAX(rowid) as id FROM ".MAIN_DB_PREFIX."designtrace_changeorder"))->id;

		$this->expectCode(409, function () use ($ecoid) {
			$this->api->setStaffEcoShareable($ecoid, 0);
		}, 'stays shared');
		$staffEco = new ChangeOrder($this->db);
		$staffEco->label = 'ZZ staff';
		$staffEco->reason = ChangeOrder::REASON_COST;
		$staffEco->fk_soc = $customer->id;
		$this->assertOk($staffEco->create($this->user), $staffEco, 'ECO');
		$this->assertTrue($this->api->setStaffEcoShareable($staffEco->id, 1)['shareable']);
		$this->assertFalse($this->api->setStaffEcoShareable($staffEco->id, 0)['shareable']);

		$link = $this->api->linkStaffEcoQuote($ecoid, $quote->id);
		$this->assertFalse($link['settled']);
		$this->expectCode(409, function () use ($ecoid) {
			$this->api->approveStaffEco($ecoid);
		}, 'waits');
		$this->expectCode(409, function () use ($ecoid) {
			$this->api->implementStaffEco($ecoid);
		});
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."propal SET fk_statut = 2 WHERE rowid = ".((int) $quote->id));
		$this->assertSame(ChangeOrder::STATUS_APPROVED, $this->api->approveStaffEco($ecoid)['status']);
		$this->assertSame(ChangeOrder::STATUS_IMPLEMENTED, $this->api->implementStaffEco($ecoid)['status']);
		$this->assertSame(ChangeOrder::STATUS_CLOSED, $this->api->closeStaffEco($ecoid)['status']);
		$this->expectCode(409, function () use ($ecoid) {
			$this->api->closeStaffEco($ecoid);
		});
		$this->expectCode(404, function () {
			$this->api->closeStaffEco(999999999);
		});
		$this->expectCode(409, function () use ($customer, $staffEco) {
			$this->api->linkStaffEcoQuote($staffEco->id, $this->newQuote($this->newCustomer('ZZ Other')->id)->id);
		});

		// Forced re-acceptance
		$e = '';
		$v = $licence->getCurrentVersion();
		$this->assertGreaterThan(0, DesignTraceLicensing::acceptDesign($this->db, $customer->id, $design->id, $v->id, 'api', 'portal:ann', $e), $e);
		$this->assertTrue(DesignTraceLicensing::isAccepted($this->db, $customer->id, $design->id));
		$this->assertSame(1, $this->api->forceStaffReacceptance($design->id, $customer->id)['ended']);
		$this->assertFalse(DesignTraceLicensing::isAccepted($this->db, $customer->id, $design->id));
		$this->assertSame(0, $this->api->forceStaffReacceptance($design->id)['ended']);
		$this->expectCode(404, function () {
			$this->api->forceStaffReacceptance(999999999);
		});
	}

	/**
	 * Make the customer hold the revision (linked to a quote)
	 */
	private function linkedRevision($rev, $customer)
	{
		$quote = $this->newQuote($customer->id);
		$this->assertGreaterThan(0, $this->linkDoc($rev, 'propal', $quote->id), $rev->error);
		return $rev->id;
	}

	public function testMetrics()
	{
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id, 'label' => 'ZZ Metrics'), true);
		$before = $this->api->getStaffMetrics();
		$month = dol_print_date(dol_now(), '%Y-%m', 'gmt');
		$count = function ($rows) use ($month) {
			foreach ($rows as $row) {
				if ($row['month'] === $month) {
					return $row['count'];
				}
			}
			return 0;
		};

		$rev = $this->newRevision($this->newComponent($design));
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_componentrevision SET needs_approval = 1 WHERE rowid = ".((int) $rev->id));
		$this->inReview($rev);
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_componentrevision SET date_review = '".$this->db->idate(dol_now() - 7200)."' WHERE rowid = ".((int) $rev->id));
		$this->linkDoc($rev, 'propal', $this->newQuote($customer->id)->id);
		$err = '';
		$this->assertIsArray(DesignTraceApprovals::approve($this->db, $this->user, $customer->id, 'design', $design->id, 'admin', '', 'api', 'portal:ann', $err), $err);
		$this->release($rev);
		$released = $this->newRevision($this->newComponent($design), true);
		$order = $this->newOrder($customer->id, true);
		$this->linkDoc($released, 'commande', $order->id);
		$order->cloture($this->user);
		$eco = new ChangeOrder($this->db);
		$eco->label = 'ZZ cycle';
		$eco->reason = ChangeOrder::REASON_COST;
		$this->assertOk($eco->create($this->user), $eco, 'ECO');
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_changeorder SET status = ".ChangeOrder::STATUS_IMPLEMENTED.", date_creation = '".$this->db->idate(dol_now() - 86400 * 3)."' WHERE rowid = ".((int) $eco->id));
		$eco->fetch($eco->id);
		$this->assertGreaterThan(0, $eco->close($this->user));

		$after = $this->api->getStaffMetrics();
		$this->assertSame(2, $count($after['revisions_released_per_month']) - $count($before['revisions_released_per_month']));
		$this->assertSame(1, $count($after['designs_delivered_per_month']) - $count($before['designs_delivered_per_month']), 'Counted per design, not per revision');
		$this->assertSame($before['approval_turnaround']['count'] + 1, $after['approval_turnaround']['count']);
		$sum = function ($m) {
			return (int) $m['average_seconds'] * $m['count'];
		};
		$added = $sum($after['approval_turnaround']) - $sum($before['approval_turnaround']);
		$this->assertGreaterThanOrEqual(7200 - 5, $added, 'Review start to approval: two hours');
		$this->assertLessThan(7200 + 300, $added);
		$cycle = $sum($after['eco_cycle_time']) - $sum($before['eco_cycle_time']);
		$this->assertGreaterThanOrEqual(86400 * 3 - 5, $cycle, 'Raised three days ago');
		$this->assertSame($before['eco_cycle_time']['count'] + 1, $after['eco_cycle_time']['count']);
		$this->assertIsArray($after['cad_hours_per_design']);
		$empty = $this->api->getStaffMetrics('2000-01-01', '2000-12-31');
		$this->assertSame(array(), $empty['revisions_released_per_month']);
		$this->assertSame(0, $empty['approval_turnaround']['count']);
		$this->assertNull($empty['approval_turnaround']['average_seconds']);
		$this->expectCode(400, function () {
			$this->api->getStaffMetrics('garbage');
		});
	}
}
