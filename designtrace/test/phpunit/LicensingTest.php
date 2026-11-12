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
	private function newLicence($code, $sourceAllowed)
	{
		$licence = new Licence($this->db);
		$licence->ref = $code;
		$licence->label = 'ZZ '.$code;
		$this->assertOk($licence->create($this->user), $licence, 'Create licence');
		$draft = $licence->getDraftVersion();
		$draft->wording = '<p>Licence for {customer}, design {design} rev {revision}, {scope}, {date}.</p>';
		$draft->perm_source = $sourceAllowed ? 1 : 0;
		$this->assertGreaterThan(0, $draft->update($this->user));
		$this->assertOk($draft->activate($this->user), $draft, 'Activate');
		return $licence;
	}

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
		$this->assertTrue($sourceShown(), 'Customer-owned under a licence with source');
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

		$acc = DesignTraceLicensing::getAcceptance($this->db, 'propal', $quote->id, $design->id);
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
		$orderAcc = DesignTraceLicensing::getAcceptance($this->db, 'commande', $order->id, $design->id);
		$this->assertNotEmpty($orderAcc);
		$this->assertSame((int) $acc->fk_licenceversion, (int) $orderAcc->fk_licenceversion);
		$this->assertFileExists($conf->commande->multidir_output[$conf->entity].'/'.dol_sanitizeFileName($order->ref).'/'.$acc->filename);

		$this->assertLessThan(0, $licence->delete($this->user), 'A used template cannot be deleted');
	}
}
