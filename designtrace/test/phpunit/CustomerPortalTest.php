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
 * \file        designtrace/test/phpunit/CustomerPortalTest.php
 * \ingroup     designtrace
 * \brief       The customer view shared by the REST API and the Web portal
 */

class CustomerPortalTest extends DesignTraceTestCase
{
	public function testDocumentsOfACustomer()
	{
		$CP = DesignTraceCustomerPortal::class;
		$s = $this->newCustomerScenario();
		$soc = (int) $s['customer']->id;
		$qkey = 'propal_'.$s['quote']->id;
		$okey = 'commande_'.$s['order']->id;

		$docs = $CP::fetchDocuments($this->db, $soc);
		$this->assertEqualsCanonicalizing(array($qkey, $okey), array_keys($docs));
		$quote = $docs[$qkey];
		$this->assertCount(3, $quote['files'], 'Part PDF and STEP, proof PDF');
		$this->assertCount(1, $quote['proofs']);
		$this->assertCount(1, $quote['designs']);
		$this->assertNull($quote['reorder']);
		$this->assertSame($s['quote']->ref, $quote['doc_ref']);
		$this->assertGreaterThan(0, $quote['date']);
		$order = $docs[$okey];
		$this->assertCount(2, $order['files']);
		$this->assertSame(Commande::STATUS_CLOSED, $order['doc_status']);
		$this->assertTrue($order['reorder']['possible']);
		$this->assertSame(0, $order['reorder']['pending_quote_id']);

		$this->assertCount(1, $CP::fetchDocuments($this->db, $soc, 'commande'));
		$this->assertCount(1, $CP::fetchDocuments($this->db, $soc, 'propal', $s['quote']->id));
		$this->assertCount(0, $CP::fetchDocuments($this->db, $this->newCustomer('ZZ Other')->id));
		$this->assertCount(0, $CP::fetchDocuments($this->db, 0));

		$draft = $this->newQuote($soc, false);
		$s['design']->add_object_linked('propal', $draft->id);
		$this->assertArrayNotHasKey('propal_'.$draft->id, $CP::fetchDocuments($this->db, $soc), 'Drafts are never listed');

		$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_design SET confidential = 1 WHERE rowid = ".((int) $s['design']->id));
		$quote = $CP::fetchDocuments($this->db, $soc, 'propal', $s['quote']->id)[$qkey];
		$this->assertCount(0, $quote['designs'], 'No gallery question for confidential designs');
		$this->assertCount(3, $quote['files']);
	}

	public function testConsentReorderAndLicenceCopy()
	{
		$CP = DesignTraceCustomerPortal::class;
		$s = $this->newCustomerScenario();
		$soc = (int) $s['customer']->id;
		$q = (int) $s['quote']->id;
		$design = (int) $s['design']->id;
		$other = (int) $this->newCustomer('ZZ Other')->id;
		$draft = $this->newQuote($soc, false);
		$err = '';

		$this->assertLessThan(0, $CP::setConsent($this->db, $other, 'propal', $q, $design, 1, 'api', 'x', null, $err));
		$this->assertSame('document', $err);
		$this->assertLessThan(0, $CP::setConsent($this->db, $soc, 'propal', $draft->id, $design, 1, 'api', 'x', null, $err));
		$this->assertSame('document', $err);
		$this->assertLessThan(0, $CP::setConsent($this->db, $soc, 'propal', $q, $design + 1000000, 1, 'api', 'x', null, $err));
		$this->assertSame('design', $err);
		$this->assertLessThan(0, $CP::setConsent($this->db, $soc, 'propal', $q, $design, 7, 'api', 'x', null, $err));
		$this->assertSame('consent', $err);
		$this->assertGreaterThan(0, $CP::setConsent($this->db, $soc, 'propal', $q, $design, 1, 'api', 'site:zz', '203.0.113.9', $err));
		$this->assertSame(0, $CP::setConsent($this->db, $soc, 'propal', $q, $design, 1, 'api', 'x', null, $err));

		$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_design SET confidential = 1 WHERE rowid = ".$design);
		$this->assertLessThan(0, $CP::setConsent($this->db, $soc, 'propal', $q, $design, 2, 'api', 'x', null, $err));
		$this->assertSame('design', $err);
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_design SET confidential = 0 WHERE rowid = ".$design);

		$this->assertLessThan(0, $CP::reorder($this->db, $this->user, $other, $s['order']->id, 'api', 'x', $err));
		$this->assertSame('notfound', $err);
		$qid = $CP::reorder($this->db, $this->user, $soc, $s['order']->id, 'api', 'site:zz', $err);
		$this->assertGreaterThan(0, $qid, $err);
		$this->assertSame($qid, $CP::fetchDocuments($this->db, $soc, 'commande', $s['order']->id)['commande_'.$s['order']->id]['reorder']['pending_quote_id']);

		$licence = new Licence($this->db);
		$licence->fetch(0, 'PERSONAL');
		$s['design']->fk_licence = $licence->id;
		$s['design']->update($this->user);
		$this->assertNull($CP::getAcceptancePdf($this->db, $soc, 'propal', $q, $design), 'No copy before acceptance');
		$version = $licence->getCurrentVersion();
		$this->assertGreaterThan(0, DesignTraceLicensing::accept($this->db, $soc, 'propal', $q, $design, $version->id, 'api', 'site:zz', $err), $err);
		$pdf = $CP::getAcceptancePdf($this->db, $soc, 'propal', $q, $design);
		$this->assertNotNull($pdf);
		$this->assertStringStartsWith('%PDF', file_get_contents($pdf['path']));
		$this->assertSame($version->ref, $pdf['version_ref']);
		$this->assertNull($CP::getAcceptancePdf($this->db, $other, 'propal', $q, $design), 'Only the customer gets it');
	}
}
