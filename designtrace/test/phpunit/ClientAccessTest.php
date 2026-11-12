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
 * \file        designtrace/test/phpunit/ClientAccessTest.php
 * \ingroup     designtrace
 * \brief       What customers see, delivered orders, signed links, download log, request helpers
 */

class ClientAccessTest extends DesignTraceTestCase
{
	private function names($rows)
	{
		return array_map(function ($r) {
			return $r->filename;
		}, $rows);
	}

	public function testVisibilityRules()
	{
		$CA = DesignTraceClientAccess::class;
		$customer = $this->newCustomer();
		$other = $this->newCustomer('ZZ Other');
		$part = $this->newComponent($this->newDesign(array('fk_soc' => $customer->id)));
		$a = $this->newRevision($part);
		$pdf = $this->addFile($a, 'plate.pdf', '%PDF a', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$step = $this->addFile($a, 'plate.step', 'ISO a', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_ON_COMPLETION);
		$src = $this->addFile($a, 'plate.FCStd', 'src', RevisionFile::ROLE_SOURCE, RevisionFile::VISIBILITY_NOW);
		$gcode = $this->addFile($a, 'plate.gcode', 'G1', RevisionFile::ROLE_PRODUCTION, RevisionFile::VISIBILITY_NOW);

		$quote = $this->newQuote($customer->id, false);
		$this->assertGreaterThan(0, $this->linkDoc($a, 'propal', $quote->id));
		$this->assertCount(0, $CA::fetchVisibleFiles($this->db, $customer->id), 'Draft quote shows nothing');
		$quote->valid($this->user);
		$this->assertSame(array($pdf->filename), $this->names($CA::fetchVisibleFiles($this->db, $customer->id)), 'Visible-now presentation file only');
		$this->assertCount(0, $CA::fetchVisibleFiles($this->db, $other->id));
		$this->assertNull($CA::getDownloadableFile($this->db, $customer->id, $gcode->id), 'Production never');
		$this->assertNull($CA::getDownloadableFile($this->db, $customer->id, $src->id), 'Source not without licence');
		$this->assertNull($CA::getDownloadableFile($this->db, $other->id, $pdf->id));
		$this->assertNotNull($CA::getDownloadableFile($this->db, $customer->id, $pdf->id));

		$CA::setLinkVisibility($this->db, $this->user, $a->id, 'propal', $quote->id, 0);
		$this->assertCount(0, $CA::fetchVisibleFiles($this->db, $customer->id), 'Link switched off');
		$CA::setLinkVisibility($this->db, $this->user, $a->id, 'propal', $quote->id, 1);
		$this->assertCount(1, $CA::fetchVisibleFiles($this->db, $customer->id));

		$this->release($a);
		$order = $this->newOrder($customer->id, false);
		$this->assertGreaterThan(0, $this->linkDoc($a, 'commande', $order->id));
		$order->valid($this->user);
		$this->assertSame(array($pdf->filename), $this->names($CA::fetchVisibleFiles($this->db, $customer->id, 'commande')));
		$order->cloture($this->user);
		$names = $this->names($CA::fetchVisibleFiles($this->db, $customer->id, 'commande', $order->id));
		sort($names);
		$expected = array($pdf->filename, $step->filename);
		sort($expected);
		$this->assertSame($expected, $names, 'Visible on completion once delivered');
		$this->assertCount(1, $CA::fetchVisibleFiles($this->db, $customer->id, 'propal'));

		$ticket = $this->newTicket($customer->id);
		$this->linkDoc($a, 'ticket', $ticket->id);
		$this->assertCount(1, $CA::fetchVisibleFiles($this->db, $customer->id, 'ticket'));
	}

	public function testDeliveredOrdersAreLockedAndUpdatedExplicitly()
	{
		$CA = DesignTraceClientAccess::class;
		$s = $this->newCustomerScenario();
		$order = $s['order'];
		$a = $s['rev'];
		$b = $a->createFromClone($this->user, $a->id);
		$b->fetch($b->id);
		$this->addFile($b, 'shade.pdf', '%PDF b', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->release($b);

		$this->assertLessThan(0, $this->linkDoc($b, 'commande', $order->id), 'Delivered order locked');
		$errors = array();
		$this->assertLessThan(0, $CA::updateDelivered($this->db, $this->user, $order, $a, $b, '', 0, $errors), 'Reason required');
		$this->assertLessThan(0, $CA::updateDelivered($this->db, $this->user, $order, $b, $a, 'x', 0, $errors), 'Only to a newer revision');
		$this->assertGreaterThan(0, $CA::updateDelivered($this->db, $this->user, $order, $a, $b, 'Crack at bend fixed', 0, $errors), implode(', ', $errors));

		$rows = $CA::fetchVisibleFiles($this->db, $s['customer']->id, 'commande', $order->id);
		$this->assertCount(1, $rows);
		$this->assertSame((int) $b->id, (int) $rows[0]->rev_id);
		$this->assertSame(1, $this->countRows("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."actioncomm WHERE code = 'AC_DESIGNTRACE_DELIVERED_UPDATE' AND fk_element = ".((int) $order->id)." AND note LIKE '%Crack at bend fixed%'"));
	}

	public function testSignedLinks()
	{
		$CA = DesignTraceClientAccess::class;
		$s = $this->newCustomerScenario();
		$row = $CA::getDownloadableFile($this->db, $s['customer']->id, $s['pdf']->id);
		$link = $CA::buildSignedUrl($row, $s['customer']->id, 'zz-test');
		parse_str(parse_url($link['url'], PHP_URL_QUERY), $p);
		$this->assertSame('', $CA::checkSignedParams($p));

		$tampered = $p;
		$tampered['s'] = $s['customer']->id + 1;
		$this->assertSame('Bad signature', $CA::checkSignedParams($tampered));
		$tampered = $p;
		$tampered['f'] = $s['step']->id;
		$this->assertSame('Bad signature', $CA::checkSignedParams($tampered));
		$expired = $p;
		$expired['e'] = dol_now() - 10;
		$expired['sig'] = $CA::sign($expired);
		$this->assertSame('Link expired', $CA::checkSignedParams($expired));
	}

	public function testDownloadLogKeepsTheCustomersIpFromABackend()
	{
		$CA = DesignTraceClientAccess::class;
		$s = $this->newCustomerScenario();
		$row = $CA::getDownloadableFile($this->db, $s['customer']->id, $s['pdf']->id);
		$id = $CA::logDownload($this->db, $row, $s['customer']->id, $CA::CHANNEL_API_DIRECT, 'site:zz', '203.0.113.9', "Mozilla/5.0\n(test)");
		$this->assertGreaterThan(0, $id);
		$res = $this->db->query("SELECT channel, actor, ip, useragent FROM ".MAIN_DB_PREFIX."designtrace_download WHERE rowid = ".((int) $id));
		$log = $this->db->fetch_object($res);
		$this->assertSame('apidirect', $log->channel);
		$this->assertSame('site:zz', $log->actor);
		$this->assertSame('203.0.113.9', $log->ip);
		$this->assertStringNotContainsString("\n", $log->useragent);
		$this->assertSame(1, (int) ($CA::countDownloads($this->db, $s['rev']->id)[$s['pdf']->id] ?? 0));
		$this->assertCount(1, $CA::fetchDownloads($this->db, $s['rev']->id));
	}

	public function testRequestHelpers()
	{
		$CA = DesignTraceClientAccess::class;
		$this->assertSame('203.0.113.9', $CA::resolveClientIp('203.0.113.9'));
		$this->assertSame('2001:db8::1', $CA::resolveClientIp('2001:db8::1'));
		$this->assertNotSame('not-an-ip', $CA::resolveClientIp('not-an-ip'), 'Invalid IP replaced by the caller\'s');
		$this->assertSame(255, strlen($CA::resolveUserAgent(str_repeat('a', 400))));
		$this->assertSame('propal', $CA::normaliseElement('quote'));
		$this->assertSame('commande', $CA::normaliseElement('order'));
		$this->assertSame('ticket', $CA::normaliseElement('ticket'));
		$this->assertTrue($CA::is3dFormat('step'));
		$this->assertTrue($CA::is3dFormat('3mf'));
		$this->assertFalse($CA::is3dFormat('pdf'));
		$this->assertArrayHasKey('apidirect', $CA::getChannelLabels());
	}

	public function testViewerOriginsSetting()
	{
		$this->setConst('DESIGNTRACE_VIEWER_ORIGINS', "https://a.example\nhttps://b.example/");
		$origins = DesignTraceClientAccess::getViewerOrigins();
		$this->assertContains('https://a.example', $origins);
	}
}
