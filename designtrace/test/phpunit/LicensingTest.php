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
 * \file        designtrace/test/phpunit/LicensingTest.php
 * \ingroup     designtrace
 * \brief       Licence templates and versions, the licence that applies, acceptance, source files
 */

class LicensingTest extends DesignTraceTestCase
{
	public function testTemplatesAndVersions()
	{
		$bad = new Licence($this->db);
		$bad->ref = 'zz bad';
		$bad->label = 'ZZ';
		$this->assertLessThan(0, $bad->create($this->user), 'Codes have no spaces');

		$licence = new Licence($this->db);
		$licence->ref = 'zzver';
		$licence->label = 'ZZ';
		$this->assertOk($licence->create($this->user), $licence, 'Create licence');
		$this->assertSame('ZZVER', $licence->ref);
		$draft = $licence->getDraftVersion();
		$this->assertSame('ZZVER-v1', $draft->ref);
		$this->assertNull($licence->getCurrentVersion());
		$this->assertLessThan(0, $draft->activate($this->user), 'No wording, no activation');

		$draft->error = '';
		$draft->errors = array();
		$draft->wording = '{customer}';
		$draft->perm_source = 1;
		$draft->update($this->user);
		$this->assertGreaterThan(0, $draft->activate($this->user));
		$v1 = $licence->getCurrentVersion();
		$v1->wording = 'changed';
		$this->assertLessThan(0, $v1->update($this->user), 'Current version is read-only');

		$v2 = $licence->newVersion($this->user);
		$this->assertIsObject($v2);
		$this->assertSame(2, (int) $v2->version);
		$this->assertSame(1, (int) $v2->perm_source);
		$this->assertLessThan(0, $licence->newVersion($this->user), 'One draft at a time');
	}

	public function testStarterTemplatesExist()
	{
		foreach (array('FULL', 'PERSONAL', 'COMMERCIAL', 'EXCLUSIVE', 'PRINTS') as $code) {
			$licence = new Licence($this->db);
			$this->assertGreaterThan(0, $licence->fetch(0, $code), $code);
			$this->assertNotNull($licence->getCurrentVersion(), $code);
		}
	}

	public function testLicenceThatAppliesAndSourceFiles()
	{
		$customer = $this->newCustomer();
		$personal = new Licence($this->db);
		$personal->fetch(0, 'PERSONAL');
		$withSource = $this->newLicence('ZZSRC', true);
		$design = $this->newDesign(array('fk_soc' => $customer->id, 'fk_licence' => $personal->id));
		$rev = $this->newRevision($this->newComponent($design));
		$this->addFile($rev, 'shade.pdf', '%PDF', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$src = $this->addFile($rev, 'shade.FCStd', 'src', RevisionFile::ROLE_SOURCE, RevisionFile::VISIBILITY_NOW);
		$this->release($rev);
		$quote = $this->newQuote($customer->id);
		$this->linkDoc($rev, 'propal', $quote->id);
		$sourceShown = function () use ($customer, $src) {
			return DesignTraceClientAccess::getDownloadableFile($this->db, $customer->id, $src->id) !== null;
		};

		$info = DesignTraceLicensing::fetchDocumentLicences($this->db, 'propal', $quote->id);
		$this->assertSame('design', $info[$design->id]['source']);
		$this->assertSame('PERSONAL-v'.$personal->getCurrentVersion()->version, $info[$design->id]['version']->ref);
		$this->assertFalse($sourceShown());

		DesignTraceLicensing::setOverride($this->db, $this->user, 'propal', $quote->id, $withSource->id);
		$info = DesignTraceLicensing::fetchDocumentLicences($this->db, 'propal', $quote->id);
		$this->assertSame('document', $info[$design->id]['source']);
		$this->assertSame('ZZSRC-v1', $info[$design->id]['version']->ref);
		$this->assertFalse($sourceShown(), 'Workshop-owned designs never deliver source files');

		$design->ownership = Design::OWNERSHIP_CUSTOMER;
		$design->update($this->user);
		$this->assertFalse($sourceShown(), 'Visible, but the licence must be accepted before any download');
		DesignTraceClientAccess::getDownloadableFile($this->db, $customer->id, $src->id, '', 0, $blocked);
		$this->assertSame('licence', $blocked);
		// Staff previewing as the customer get what the customer would get once they accept: no more
		$preview = DesignTraceClientAccess::getDownloadableFile($this->db, $customer->id, $src->id, '', 0, $blocked, true);
		$this->assertNotNull($preview, 'A staff preview does not wait for the licence');
		$this->assertSame($src->id, (int) $preview->file_id);
		$this->assertNull(DesignTraceClientAccess::getDownloadableFile($this->db, $customer->id, $src->id + 100000, '', 0, $blocked, true), 'Only files the customer can see');
		$this->assertGreaterThan(0, DesignTraceClientAccess::logDownload($this->db, $preview, $customer->id, DesignTraceClientAccess::CHANNEL_ADMIN_PREVIEW, 'admin:staff@example.com'));
		$this->assertCount(1, DesignTraceClientAccess::fetchDownloads($this->db, $rev->id), 'Staff see the preview in the log');
		$types = array_column(DesignTraceLibrary::fetchHistory($this->db, $customer->id, $design->id), 'type');
		$this->assertNotContains('download', $types, 'A staff preview is not the customer\'s download');
		$types = array_column(DesignTraceLibrary::fetchDesignHistory($this->db, $customer->id, $design->id), 'type');
		$this->assertNotContains('download', $types);
		$err = '';
		$this->assertGreaterThan(0, DesignTraceLicensing::accept($this->db, $customer->id, 'propal', $quote->id, $design->id, $withSource->getCurrentVersion()->id, 'api', 'zz', $err), $err);
		$this->assertTrue($sourceShown(), 'Customer-owned under an accepted licence with source');
	}

	public function testAcceptanceWritesAPdfAndIsCarriedToTheOrder()
	{
		global $conf;

		$customer = $this->newCustomer();
		$other = $this->newCustomer('ZZ Other');
		$licence = $this->newLicence('ZZACC', true);
		$design = $this->newDesign(array('fk_soc' => $customer->id, 'fk_licence' => $licence->id, 'label' => 'ZZ Lamp'), true);
		$quote = $this->newQuote($customer->id);
		$design->add_object_linked('propal', $quote->id);
		$version = $licence->getCurrentVersion();
		$err = '';

		$this->assertLessThan(0, DesignTraceLicensing::accept($this->db, $customer->id, 'propal', $quote->id, $design->id, 999999999, 'api', 'zz', $err), 'Stale version');
		$this->assertLessThan(0, DesignTraceLicensing::accept($this->db, $other->id, 'propal', $quote->id, $design->id, $version->id, 'api', 'zz', $err), 'Other customer');
		$id = DesignTraceLicensing::accept($this->db, $customer->id, 'propal', $quote->id, $design->id, $version->id, 'webportal', 'portal:zz', $err, '198.51.100.20', 'Mozilla/5.0 (test)');
		$this->assertGreaterThan(0, $id, $err);
		$this->assertSame($id, DesignTraceLicensing::accept($this->db, $customer->id, 'propal', $quote->id, $design->id, $version->id, 'webportal', 'portal:zz', $err), 'Accepting again returns the same id');

		$acc = DesignTraceLicensing::getAcceptance($this->db, $customer->id, $design->id);
		$this->assertSame('198.51.100.20', $acc->ip);
		$res = $this->db->query('SELECT useragent, wording_sha256 FROM '.MAIN_DB_PREFIX.'designtrace_licenceaccept WHERE rowid = '.((int) $id));
		$row = $this->db->fetch_object($res);
		$this->assertSame('Mozilla/5.0 (test)', $row->useragent);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $row->wording_sha256);
		$pdf = $conf->propal->multidir_output[$conf->entity].'/'.dol_sanitizeFileName($quote->ref).'/'.$acc->filename;
		$this->assertFileExists($pdf);
		$this->assertStringStartsWith('%PDF', file_get_contents($pdf));

		$v2 = $licence->newVersion($this->user);
		$v2->wording = 'v2 {customer}';
		$v2->perm_source = 0;
		$v2->update($this->user);
		$v2->activate($this->user);
		$info = DesignTraceLicensing::fetchDocumentLicences($this->db, 'propal', $quote->id);
		$this->assertSame('ZZACC-v1', $info[$design->id]['version']->ref, 'The accepted version keeps counting');
		$this->assertStringContainsString('ZZ Test customer', $info[$design->id]['version']->renderWording(DesignTraceLicensing::getWordingValues($quote, $info[$design->id]['design'], dol_now())));

		$order = new Commande($this->db);
		$order->socid = $customer->id;
		$order->date = dol_now();
		$order->origin = 'propal';
		$order->origin_type = 'propal';
		$order->origin_id = $quote->id;
		$order->linked_objects = array('propal' => $quote->id);
		$this->assertOk($order->create($this->user), $order, 'Order from quote');
		$order->fetch($order->id);
		$orderAcc = DesignTraceLicensing::getAcceptance($this->db, $customer->id, $design->id);
		$this->assertNotEmpty($orderAcc);
		$this->assertSame((int) $acc->fk_licenceversion, (int) $orderAcc->fk_licenceversion);
		$this->assertFileExists($conf->commande->multidir_output[$conf->entity].'/'.dol_sanitizeFileName($order->ref).'/'.$acc->filename);

		// Anchored to design + customer: the order is covered without a second acceptance
		$orderInfo = DesignTraceLicensing::fetchDocumentLicences($this->db, 'commande', $order->id);
		$this->assertNotEmpty($orderInfo[$design->id]['acceptance']);
		$this->assertTrue(DesignTraceLicensing::isAccepted($this->db, $customer->id, $design->id, 'commande', $order->id));
		$this->assertFalse(DesignTraceLicensing::isAccepted($this->db, $other->id, $design->id), 'Other customers accept for themselves');

		// A forced re-acceptance ends it everywhere; the record stays
		$this->assertGreaterThan(0, DesignTraceLicensing::forceReacceptance($this->db, $design->id, $customer->id));
		$this->assertFalse(DesignTraceLicensing::isAccepted($this->db, $customer->id, $design->id, 'propal', $quote->id));
		$this->assertFalse(DesignTraceLicensing::isAccepted($this->db, $customer->id, $design->id, 'commande', $order->id));
		$res = $this->db->query('SELECT COUNT(*) as n FROM '.MAIN_DB_PREFIX.'designtrace_licenceaccept WHERE fk_design = '.((int) $design->id));
		$this->assertGreaterThanOrEqual(2, (int) $this->db->fetch_object($res)->n);

		// Accepting again, for the design itself (no document), covers the quote and the order too
		$current = $licence->getCurrentVersion();
		$this->assertSame($v2->id, $current->id);
		$id2 = DesignTraceLicensing::acceptDesign($this->db, $customer->id, $design->id, $current->id, 'api', 'zz', $err, '198.51.100.21');
		$this->assertGreaterThan(0, $id2, $err);
		$this->assertTrue(DesignTraceLicensing::isAccepted($this->db, $customer->id, $design->id, 'commande', $order->id));
		$res = $this->db->query('SELECT filename, ip FROM '.MAIN_DB_PREFIX.'designtrace_licenceaccept WHERE rowid = '.((int) $id2));
		$row = $this->db->fetch_object($res);
		$this->assertSame('198.51.100.21', $row->ip);
		$this->assertFileExists(DesignTraceLicensing::getAcceptancePdfPath($this->db, DesignTraceLicensing::getAcceptance($this->db, $customer->id, $design->id)));
		$this->assertSame($id2, DesignTraceLicensing::acceptDesign($this->db, $customer->id, $design->id, $current->id, 'api', 'zz', $err), 'Idempotent');

		$this->assertLessThan(0, $licence->delete($this->user), 'A used template cannot be deleted');
	}
}
