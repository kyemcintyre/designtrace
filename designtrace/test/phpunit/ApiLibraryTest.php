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
}
