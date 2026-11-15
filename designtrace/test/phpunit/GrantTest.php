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
 * \file        designtrace/test/phpunit/GrantTest.php
 * \ingroup     designtrace
 * \brief       Design-level grants, the project channel and the customer release note
 */

dol_include_once('/designtrace/class/grant.class.php');

class GrantTest extends DesignTraceTestCase
{
	private function names($rows)
	{
		$names = array_map(function ($r) {
			return $r->filename;
		}, $rows);
		sort($names);
		return $names;
	}

	public function testGrantPinsRevisionAndKeepsItWhenNewerIsReleased()
	{
		$CA = DesignTraceClientAccess::class;
		$customer = $this->newCustomer();
		$other = $this->newCustomer('ZZ Other');
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$part = $this->newComponent($design);
		$a = $this->newRevision($part);
		$this->addFile($a, 'a.pdf', '%PDF a', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->addFile($a, 'a.gcode', 'G1', RevisionFile::ROLE_PRODUCTION, RevisionFile::VISIBILITY_NOW);
		$this->release($a);

		$unreleased = $this->newDesign(array('fk_soc' => $customer->id), true);
		$this->newRevision($this->newComponent($unreleased));
		$this->assertSame(-2, DesignTraceGrant::create($this->db, $this->user, $customer->id, $unreleased->id), 'Nothing released to pin');
		$grantid = DesignTraceGrant::create($this->db, $this->user, $customer->id, $design->id);
		$this->assertGreaterThan(0, $grantid);
		$rows = $CA::fetchVisibleFiles($this->db, $customer->id);
		$this->assertSame(array($part->ref.'-a.pdf'), $this->names($rows), 'Presentation file only');
		$this->assertSame('grant', $rows[0]->element);
		$this->assertSame($grantid, (int) $rows[0]->doc_id);
		$this->assertCount(0, $CA::fetchVisibleFiles($this->db, $other->id));
		$this->assertTrue(DesignTraceGrant::isDelivered($this->db, $customer->id, $a->id), 'Delivered as soon as granted');
		$this->assertNotNull($CA::getDownloadableFile($this->db, $customer->id, $rows[0]->file_id));

		// A newer release does not move the pin
		$b = $this->newRevision($part);
		$this->addFile($b, 'b.pdf', '%PDF b', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->release($b);
		$this->assertSame(array($part->ref.'-a.pdf'), $this->names($CA::fetchVisibleFiles($this->db, $customer->id)));
		$this->assertFalse(DesignTraceGrant::isDelivered($this->db, $customer->id, $b->id));

		// Moved by hand
		$this->assertSame(1, DesignTraceGrant::movePin($this->db, $grantid, $part->id, $b->id));
		$this->assertSame(array($part->ref.'-b.pdf'), $this->names($CA::fetchVisibleFiles($this->db, $customer->id)));
		$this->assertTrue(DesignTraceGrant::isDelivered($this->db, $customer->id, $b->id));
		$draft = $this->newRevision($this->newComponent($design));
		$this->assertSame(-3, DesignTraceGrant::movePin($this->db, $grantid, $part->id, $draft->id), 'Only released revisions of the component');
	}

	public function testExpiryAndRevocationKeepTheRecord()
	{
		$CA = DesignTraceClientAccess::class;
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$part = $this->newComponent($design);
		$a = $this->newRevision($part);
		$this->addFile($a, 'a.pdf', '%PDF a', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->release($a);

		$grantid = DesignTraceGrant::create($this->db, $this->user, $customer->id, $design->id, array($part->id), array(), dol_now() + 3600);
		$this->assertCount(1, $CA::fetchVisibleFiles($this->db, $customer->id));
		DesignTraceGrant::setExpiry($this->db, $grantid, dol_now() - 10);
		$this->assertCount(0, $CA::fetchVisibleFiles($this->db, $customer->id), 'Expired');
		DesignTraceGrant::setExpiry($this->db, $grantid, null);
		$this->assertCount(1, $CA::fetchVisibleFiles($this->db, $customer->id), 'Never expires');

		$this->assertSame(1, DesignTraceGrant::revoke($this->db, $this->user, $grantid));
		$this->assertSame(0, DesignTraceGrant::revoke($this->db, $this->user, $grantid), 'Already revoked');
		$this->assertCount(0, $CA::fetchVisibleFiles($this->db, $customer->id));
		$grants = DesignTraceGrant::fetchForCustomer($this->db, $customer->id);
		$this->assertCount(1, $grants, 'Ended grant is kept');
		$this->assertFalse($grants[0]->active);
		$this->assertSame(array($part->id => $a->id), $grants[0]->pins);
	}

	public function testVisibleOnCompletionWaitsForOrderOrProject()
	{
		$CA = DesignTraceClientAccess::class;
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$part = $this->newComponent($design);
		$a = $this->newRevision($part);
		$this->addFile($a, 'now.pdf', '%PDF', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->addFile($a, 'later.step', 'ISO', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_ON_COMPLETION);
		$this->release($a);
		DesignTraceGrant::create($this->db, $this->user, $customer->id, $design->id);
		$this->assertSame(array($part->ref.'-now.pdf'), $this->names($CA::fetchVisibleFiles($this->db, $customer->id)));

		$order = $this->newOrder($customer->id, true);
		$this->assertGreaterThan(0, $this->linkDoc($a, 'commande', $order->id));
		$this->assertCount(1, $CA::fetchVisibleFiles($this->db, $customer->id, 'grant'), 'Order not complete');
		$order->cloture($this->user);
		$this->assertSame(array($part->ref.'-later.step', $part->ref.'-now.pdf'), $this->names($CA::fetchVisibleFiles($this->db, $customer->id, 'grant')));
	}

	public function testProjectChannel()
	{
		$CA = DesignTraceClientAccess::class;
		$customer = $this->newCustomer();
		$other = $this->newCustomer('ZZ Other');
		$part = $this->newComponent($this->newDesign(array('fk_soc' => $customer->id), true));
		$a = $this->newRevision($part);
		$this->addFile($a, 'now.pdf', '%PDF', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->addFile($a, 'later.step', 'ISO', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_ON_COMPLETION);
		$this->release($a);

		$project = $this->newProject($customer->id);
		$this->assertGreaterThan(0, $this->linkDoc($a, 'project', $project->id));
		$this->assertSame('project', $CA::normaliseElement('project'));
		$this->assertSame(array($part->ref.'-now.pdf'), $this->names($CA::fetchVisibleFiles($this->db, $customer->id, 'project')));
		$this->assertCount(0, $CA::fetchVisibleFiles($this->db, $other->id));
		$project->setClose($this->user);
		$this->assertCount(2, $CA::fetchVisibleFiles($this->db, $customer->id, 'project'), 'Visible on completion once the project is closed');
	}

	public function testCustomerReleaseNoteIsSeparateAndNotCloned()
	{
		$part = $this->newComponent($this->newDesign(array(), true));
		$a = $this->newRevision($part);
		$a->customer_note = 'New bracket radius, fits the 2026 housing';
		$a->description = 'internal: tolerance tweak';
		$this->assertGreaterThan(0, $a->update($this->user));
		$fresh = new ComponentRevision($this->db);
		$fresh->fetch($a->id);
		$this->assertSame('New bracket radius, fits the 2026 housing', $fresh->customer_note);
		$this->assertSame('internal: tolerance tweak', $fresh->description);
		$this->release($a);
		$clone = new ComponentRevision($this->db);
		$clone->fetch($a->id);
		$res = $clone->createFromClone($this->user, $a->id);
		$this->assertGreaterThan(0, is_object($res) ? $res->id : $res);
		$new = new ComponentRevision($this->db);
		$new->fetch(is_object($res) ? $res->id : $res);
		$this->assertEmpty($new->customer_note, 'Release note is per revision');
		$this->assertSame('internal: tolerance tweak', $new->description, 'Internal notes still carry over');
	}

	public function testLicenceGatesDownloadsAndSourceFilesThroughGrants()
	{
		$CA = DesignTraceClientAccess::class;
		$customer = $this->newCustomer();
		$licence = $this->newLicence('ZZGRT', true);
		$design = $this->newDesign(array('fk_soc' => $customer->id, 'fk_licence' => $licence->id, 'ownership' => Design::OWNERSHIP_CUSTOMER), true);
		$part = $this->newComponent($design);
		$a = $this->newRevision($part);
		$pdf = $this->addFile($a, 'a.pdf', '%PDF', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$src = $this->addFile($a, 'a.FCStd', 'src', RevisionFile::ROLE_SOURCE, RevisionFile::VISIBILITY_NOW);
		$this->release($a);
		$grantid = DesignTraceGrant::create($this->db, $this->user, $customer->id, $design->id);

		$this->assertCount(2, $CA::fetchVisibleFiles($this->db, $customer->id, 'grant'), 'Listed before acceptance (licence allows source)');
		$this->assertNull($CA::getDownloadableFile($this->db, $customer->id, $pdf->id, 'grant', $grantid, $blocked));
		$this->assertSame('licence', $blocked);

		$err = '';
		$id = DesignTraceLicensing::acceptDesign($this->db, $customer->id, $design->id, $licence->getCurrentVersion()->id, 'api', 'zz', $err);
		$this->assertGreaterThan(0, $id, $err);
		$this->assertNotNull($CA::getDownloadableFile($this->db, $customer->id, $pdf->id, 'grant', $grantid));
		$this->assertNotNull($CA::getDownloadableFile($this->db, $customer->id, $src->id, 'grant', $grantid));

		// Revoking the grant keeps the acceptance and ends the access
		DesignTraceGrant::revoke($this->db, $this->user, $grantid);
		$this->assertNull($CA::getDownloadableFile($this->db, $customer->id, $pdf->id, 'grant', $grantid));
		$this->assertNotNull(DesignTraceLicensing::getAcceptance($this->db, $customer->id, $design->id));

		// A workshop-owned design never delivers source whatever the licence
		$design->ownership = Design::OWNERSHIP_WORKSHOP;
		$design->update($this->user);
		$grant2 = DesignTraceGrant::create($this->db, $this->user, $customer->id, $design->id);
		$this->assertCount(1, $CA::fetchVisibleFiles($this->db, $customer->id, 'grant', $grant2));
	}
}
