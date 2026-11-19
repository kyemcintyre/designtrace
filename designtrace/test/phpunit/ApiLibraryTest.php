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
 * \file        designtrace/test/phpunit/ApiLibraryTest.php
 * \ingroup     designtrace
 * \brief       REST endpoints of the customer library, called on the API class directly
 */

dol_include_once('/designtrace/class/grant.class.php');

class ApiLibraryTest extends DesignTraceTestCase
{
	/**
	 * @var DesignTrace
	 */
	private $api;

	protected function setUp(): void
	{
		parent::setUp();
		// Not Dolibarr's api_access.class.php: it registers Restler's autoloader, which takes over PHPUnit's own
		// classes. The API class only needs the base class, a RestException and DolibarrApiAccess::$user.
		require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';
		if (!class_exists('Luracast\\Restler\\RestException', false)) {
			// Stand-in: the real one needs Restler's scope and loader
			eval('namespace Luracast\\Restler; class RestException extends \\Exception { public function __construct($code, $message = null) { parent::__construct((string) $message, $code); } }');
		}
		if (!class_exists('DolibarrApiAccess', false)) {
			eval('class DolibarrApiAccess { public static $user; }');
		}
		dol_include_once('/designtrace/class/api_designtrace.class.php');
		DolibarrApiAccess::$user = $this->user;	// Admin: has the right "Customer files through the API"
		$this->api = new DesignTrace();
	}

	private function expectCode($code, callable $call)
	{
		try {
			$call();
		} catch (\Luracast\Restler\RestException $e) {
			$this->assertSame($code, $e->getCode(), $e->getMessage());
			return;
		}
		$this->fail('Expected a '.$code);
	}

	public function testLibraryDesignAndCompareEndpoints()
	{
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id, 'label' => 'ZZ Api design'), true);
		$part = $this->newComponent($design);
		$a = $this->newRevision($part);
		$this->addFile($a, 'a.pdf', '%PDF a', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->release($a);
		DesignTraceGrant::create($this->db, $this->user, $customer->id, $design->id);
		$b = $this->newRevision($part);
		$this->addFile($b, 'a.pdf', '%PDF b', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$b->customer_note = 'Better';
		$b->update($this->user);
		$this->release($b);

		$list = $this->api->getLibrary($customer->id, 'api design');
		$this->assertSame(1, $list['total']);
		$this->assertSame($design->ref, $list['items'][0]['ref']);
		$this->assertTrue($list['items'][0]['flags']['newer_revision']);
		$this->expectCode(400, function () use ($customer) {
			$this->api->getLibrary($customer->id, '', 'bogus');
		});
		$this->expectCode(400, function () use ($customer) {
			$this->api->getLibrary($customer->id, '', '', 0, 'nonsense');
		});

		$detail = $this->api->getDesign($customer->id, $design->id);
		$this->assertSame($part->id, $detail['tree'][0]['id']);
		$this->expectCode(404, function () use ($design) {
			$this->api->getDesign($this->newCustomer('ZZ Other')->id, $design->id);
		});
		$this->assertSame($a->id, $this->api->getRevision($customer->id, $a->id)['id']);
		$this->expectCode(404, function () use ($customer) {
			$this->api->getRevision($customer->id, 999999999);
		});
		$cmp = $this->api->compareRevisions($customer->id, $part->id);
		$this->assertSame('changed', $cmp['files'][0]['change']);
		$this->assertCount(1, $this->api->getNewerRevisions($customer->id));
	}

	public function testDesignLicenceEndpointsAndDownloadGate()
	{
		$customer = $this->newCustomer();
		$licence = $this->newLicence('ZZAPI', false);
		$design = $this->newDesign(array('fk_soc' => $customer->id, 'fk_licence' => $licence->id), true);
		$part = $this->newComponent($design);
		$a = $this->newRevision($part);
		$pdf = $this->addFile($a, 'a.pdf', '%PDF a', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->release($a);
		$grantid = DesignTraceGrant::create($this->db, $this->user, $customer->id, $design->id);

		$this->expectCode(403, function () use ($customer, $pdf, $grantid) {
			$this->api->getDownloadLink($customer->id, $pdf->id, 'grant', $grantid);
		});
		$lic = $this->api->getDesignLicence($customer->id, $design->id);
		$this->assertCount(1, $lic);
		$this->assertSame($design->id, $lic[0]['design_id']);
		$this->assertNull($lic[0]['accepted']);
		$this->assertStringContainsString('ZZ Test customer', $lic[0]['wording_html']);

		$this->expectCode(409, function () use ($customer, $design) {
			$this->api->acceptDesignLicence($customer->id, $design->id, 999999999);
		});
		$res = $this->api->acceptDesignLicence($customer->id, $design->id, $lic[0]['version_id'], 'portal:zz', '198.51.100.9', 'UA');
		$this->assertGreaterThan(0, $res['id']);
		$this->assertTrue($this->api->getDesignLicence($customer->id, $design->id)[0]['accepted']['pdf']);
		$link = $this->api->getDownloadLink($customer->id, $pdf->id, 'grant', $grantid);
		$this->assertNotEmpty($link);

		DesignTraceGrant::revoke($this->db, $this->user, $grantid);
		$this->expectCode(403, function () use ($customer, $design) {
			$this->api->getDesignLicence($customer->id, $design->id);
		});
	}

	public function testApprovalsAttentionHistoryAndConsentEndpoints()
	{
		$customer = $this->newCustomer();
		$licence = $this->newLicence('ZZATT', false);
		$design = $this->newDesign(array('fk_soc' => $customer->id, 'fk_licence' => $licence->id), true);
		$quote = $this->newQuote($customer->id);
		$revs = array();
		foreach (array('a', 'b') as $n) {
			$rev = $this->newRevision($this->newComponent($design, Component::TYPE_PART, 'ZZ '.$n));
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_componentrevision SET needs_approval = 1 WHERE rowid = ".((int) $rev->id));
			$rev->fetch($rev->id);
			$rev->validate($this->user);
			$rev->fetch($rev->id);
			$this->linkDoc($rev, 'propal', $quote->id);
			$revs[$n] = $rev;
		}

		$att = $this->api->getAttention($customer->id);
		$this->assertSame(1, $att['licences']);
		$this->assertSame(1, $att['approvals'], 'One entry for the design');
		$this->assertSame(1, $att['consent']);
		$this->assertSame(0, $att['newer_revisions']);
		$this->assertSame(3, $att['total']);
		$this->assertSame(array($design->id), $att['designs']['approvals']);

		$pending = $this->api->listApprovals($customer->id);
		$this->assertCount(1, $pending);
		$this->assertSame('design', $pending[0]['scope']);
		$this->assertTrue($this->api->getLibrary($customer->id)['items'][0]['flags']['approval']);

		$this->expectCode(400, function () use ($customer, $design) {
			$this->api->approveScope($customer->id, 'bogus', $design->id);
		});
		$this->expectCode(409, function () use ($customer, $design) {
			$this->api->requestScopeChanges($customer->id, 'design', $design->id, '  ');
		});
		$res = $this->api->requestScopeChanges($customer->id, 'design', $design->id, 'Make it thicker', array($revs['a']->id), 'portal:ann', 'user', '198.51.100.3');
		$this->assertSame(1, $res['round']);
		$this->assertSame(array($revs['a']->id), $res['revision_ids']);
		$this->assertGreaterThan(0, $res['eco_id']);

		// Only admins approve for this customer
		$this->db->query("INSERT INTO ".MAIN_DB_PREFIX."societe_extrafields (fk_object, designtrace_approvers) VALUES (".((int) $customer->id).", 1) ON DUPLICATE KEY UPDATE designtrace_approvers = 1");
		$entries = $this->api->listApprovals($customer->id);
		$scopes = array_column($entries, null, 'scope');
		$this->assertArrayNotHasKey('eco', $scopes, 'The declined revision no longer waits on the customer');
		foreach (array(
			function () use ($customer, $design) {
				$this->api->approveScope($customer->id, 'design', $design->id, '', 'portal:bob', 'user');
			},
			function () use ($customer, $design) {
				$this->api->acceptDesignLicence($customer->id, $design->id, 1, 'portal:bob', '', '', 'user');
			},
		) as $call) {
			try {
				$call();
				$this->fail('Expected refusal');
			} catch (\Luracast\Restler\RestException $e) {
				$this->assertSame(403, $e->getCode());
				$this->assertStringStartsWith('role_not_allowed', $e->getMessage());
			}
		}
		$ok = $this->api->approveScope($customer->id, 'design', $design->id, 'Fine', 'portal:ann', 'admin');
		$this->assertSame(array($revs['b']->id), $ok['revision_ids']);

		$types = array_column($this->api->getHistory($customer->id), 'type');
		$this->assertContains('approval', $types);
		$this->assertContains('changes_requested', $types);
		$actors = array_column($this->api->getHistory($customer->id, $design->id), 'actor');
		$this->assertContains('portal:ann', $actors, 'Entries name the actor');

		// Design-level consent; a confidential design is never asked
		$this->assertSame('none', $this->api->getDesignConsent($customer->id, $design->id)['consent']);
		$set = $this->api->setDesignConsent($customer->id, $design->id, 'granted', 'portal:ann');
		$this->assertTrue($set['changed']);
		$this->assertFalse($this->api->setDesignConsent($customer->id, $design->id, 'granted')['changed']);
		$this->expectCode(400, function () use ($customer, $design) {
			$this->api->setDesignConsent($customer->id, $design->id, 'maybe');
		});
		$design->confidential = 1;
		$design->update($this->user);
		$this->assertTrue($this->api->getDesignConsent($customer->id, $design->id)['confidential']);
		$this->expectCode(409, function () use ($customer, $design) {
			$this->api->setDesignConsent($customer->id, $design->id, 'refused');
		});
		$this->assertSame(0, $this->api->getAttention($customer->id)['consent'], 'No consent question for a confidential design');
	}

	public function testChangeRequestEndpoints()
	{
		global $conf;

		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$rev = $this->newRevision($this->newComponent($design), true);
		$order = $this->newOrder($customer->id, true);
		$this->linkDoc($rev, 'commande', $order->id);
		$order->cloture($this->user);

		$this->expectCode(409, function () use ($customer, $rev) {
			$this->api->reportChangeRequest($customer->id, $rev->id, 'bogus', 'x', 'y');
		});
		$res = $this->api->reportChangeRequest($customer->id, $rev->id, 'problem', 'Cracked', 'It cracked in use', $order->id, 'portal:ann');
		$this->assertGreaterThan(0, $res['eco_id']);
		$this->assertGreaterThan(0, $res['ticket_id']);
		$list = $this->api->listEcos($customer->id);
		$this->assertSame($res['eco_ref'], $list[0]['ref']);
		$this->assertSame('problem', $list[0]['type']);
		$detail = $this->api->getEco($customer->id, $res['eco_id']);
		$this->assertSame('It cracked in use', $detail['request_text']);
		$this->expectCode(404, function () use ($res) {
			$this->api->getEco($this->newCustomer('ZZ Other')->id, $res['eco_id']);
		});

		$this->assertTrue($this->api->commentOnEco($customer->id, $res['eco_id'], 'Photos follow', 'portal:ann')['ok']);
		$this->expectCode(409, function () use ($customer, $res) {
			$this->api->commentOnEco($customer->id, $res['eco_id'], ' ');
		});
		$this->expectCode(400, function () use ($customer, $res) {
			$this->api->attachEcoFile($customer->id, $res['eco_id'], 'a.png', '***not base64***');
		});
		$stored = $this->api->attachEcoFile($customer->id, $res['eco_id'], 'crack.png', base64_encode('PNG'), 'portal:ann')['filename'];
		$this->assertSame('customer-crack.png', $stored);
		$this->assertSame(array('customer-crack.png'), array_column($this->api->getEco($customer->id, $res['eco_id'])['reference_files'], 'filename'));
		$eco = new ChangeOrder($this->db);
		$eco->fetch($res['eco_id']);
		dol_delete_dir_recursive($conf->designtrace->multidir_output[$conf->entity].'/changeorder/'.dol_sanitizeFileName($eco->ref));

		$this->assertSame('withdrawn', $this->api->withdrawEco($customer->id, $res['eco_id'], 'Fixed it myself', 'portal:ann')['status']);
		$this->expectCode(409, function () use ($customer, $res) {
			$this->api->withdrawEco($customer->id, $res['eco_id']);
		});
		$this->assertSame('withdrawn', $this->api->listEcos($customer->id)[0]['status']);

		// Ask for access again
		$d2 = $this->newDesign(array('fk_soc' => $customer->id), true);
		$this->newRevision($this->newComponent($d2), true);
		$g2 = DesignTraceGrant::create($this->db, $this->user, $customer->id, $d2->id);
		$this->expectCode(409, function () use ($customer, $d2) {
			$this->api->requestAccess($customer->id, $d2->id);
		});
		DesignTraceGrant::revoke($this->db, $this->user, $g2);
		$t = $this->api->requestAccess($customer->id, $d2->id, 'Please', 'portal:ann');
		$this->assertFalse($t['existing']);
		$this->assertTrue($this->api->requestAccess($customer->id, $d2->id)['existing']);
	}
}
