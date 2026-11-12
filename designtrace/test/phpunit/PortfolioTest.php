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
 * \file        designtrace/test/phpunit/PortfolioTest.php
 * \ingroup     designtrace
 * \brief       Portfolio consent and the gallery
 */

class PortfolioTest extends DesignTraceTestCase
{
	public function testConsentAndGallery()
	{
		global $conf;

		$P = DesignTracePortfolio::class;
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('label' => 'ZZ Gallery lamp', 'fk_soc' => $customer->id), true);
		$this->assertSame($P::CONSENT_NONE, $P::getConsent($this->db, $design->id));
		$this->assertLessThan(0, $P::setConsent($this->db, $design->id, 5, 'api', 'x'), 'Bad value');
		$this->assertGreaterThan(0, $P::setConsent($this->db, $design->id, $P::CONSENT_GRANTED, $P::CHANNEL_BACKOFFICE, 'admin', '', 0, $this->user->id, 'phone'));
		$this->assertSame(0, $P::setConsent($this->db, $design->id, $P::CONSENT_GRANTED, $P::CHANNEL_WEBPORTAL, 'portal:x'), 'Same answer: unchanged, not logged');
		$history = $P::fetchHistory($this->db, $design->id);
		$this->assertCount(1, $history);
		$this->assertSame('backoffice', $history[0]->channel);
		$this->assertSame('phone', $history[0]->note);
		$design->fetch($design->id);
		$this->assertTrue($P::isInGallery($design));

		$dir = $conf->designtrace->multidir_output[$conf->entity].'/design/'.$design->ref;
		dol_mkdir($dir);
		file_put_contents($dir.'/render.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
		file_put_contents($dir.'/notes.txt', 'x');
		addFileIntoDatabaseIndex($dir, 'render.png');
		addFileIntoDatabaseIndex($dir, 'notes.txt');
		$entry = array_values(array_filter($P::fetchGallery($this->db), function ($x) use ($design) {
			return (int) $x['id'] === (int) $design->id;
		}));
		$this->assertCount(1, $entry);
		$this->assertCount(1, $entry[0]['images'], 'Images only');
		$this->assertSame('render.png', $entry[0]['images'][0]['filename']);
		$imageId = $entry[0]['images'][0]['image_id'];
		$this->assertSame('render.png', $P::getImage($this->db, $design->id, $imageId)['filename']);

		$link = $P::buildSignedUrl($design->id, $imageId);
		parse_str(parse_url($link['url'], PHP_URL_QUERY), $q);
		$this->assertSame('', DesignTraceClientAccess::checkSignedParams($q));
		$this->assertSame('portfolio', $q['t']);
		$q['d'] = $design->id + 1;
		$this->assertNotSame('', DesignTraceClientAccess::checkSignedParams($q));

		$design->confidential = 1;
		$design->update($this->user);
		$design->fetch($design->id);
		$this->assertFalse($P::isInGallery($design));
		$this->assertEmpty($P::getImage($this->db, $design->id, $imageId));
		$design->confidential = 0;
		$design->update($this->user);
		$this->assertGreaterThan(0, $P::setConsent($this->db, $design->id, $P::CONSENT_REFUSED, $P::CHANNEL_WEBPORTAL, 'portal:x', 'propal', 7));
		$this->assertEmpty($P::getImage($this->db, $design->id, $imageId));

		$design->fetch($design->id);
		$design->label = 'ZZ Renamed';
		$design->update($this->user);
		$this->assertSame($P::CONSENT_REFUSED, $P::getConsent($this->db, $design->id), 'Editing the design keeps consent');
		$clone = $design->createFromClone($this->user, $design->id);
		$clone->fetch($clone->id);
		$this->assertSame(0, (int) $clone->portfolio_consent, 'A clone starts not asked');
	}

	public function testLicenceWithPortfolioUseGrantsConsentOnlyWhenNotAsked()
	{
		$P = DesignTracePortfolio::class;
		$customer = $this->newCustomer();
		$licence = new Licence($this->db);
		$licence->fetch(0, 'PERSONAL');
		$version = $licence->getCurrentVersion();
		$this->assertSame(1, (int) $version->perm_portfolio);
		$quote = $this->newQuote($customer->id);
		$err = '';

		$asked = $this->newDesign(array('fk_soc' => $customer->id, 'fk_licence' => $licence->id), true);
		$asked->add_object_linked('propal', $quote->id);
		$this->assertGreaterThan(0, DesignTraceLicensing::accept($this->db, $customer->id, 'propal', $quote->id, $asked->id, $version->id, 'api', 'zz', $err, '198.51.100.20'), $err);
		$history = $P::fetchHistory($this->db, $asked->id);
		$this->assertSame($P::CONSENT_GRANTED, $P::getConsent($this->db, $asked->id));
		$this->assertSame('licence', $history[0]->channel);
		$this->assertSame($version->ref, $history[0]->note);
		$this->assertSame('198.51.100.20', $history[0]->ip);

		$refused = $this->newDesign(array('fk_soc' => $customer->id, 'fk_licence' => $licence->id, 'label' => 'ZZ No'), true);
		$P::setConsent($this->db, $refused->id, $P::CONSENT_REFUSED, 'backoffice', 'admin');
		$refused->add_object_linked('propal', $quote->id);
		$this->assertGreaterThan(0, DesignTraceLicensing::accept($this->db, $customer->id, 'propal', $quote->id, $refused->id, $version->id, 'api', 'zz', $err));
		$this->assertSame($P::CONSENT_REFUSED, $P::getConsent($this->db, $refused->id), 'A licence never overrides a refusal');
	}
}
