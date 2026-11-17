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
 * \file        designtrace/test/phpunit/DesignLibraryTest.php
 * \ingroup     designtrace
 * \brief       Customer design library: list, filters, detail tree, ended access, newer revisions, compare
 */

dol_include_once('/designtrace/class/designlibrary.class.php');

class DesignLibraryTest extends DesignTraceTestCase
{
	private function ids($result)
	{
		return array_map(function ($i) {
			return $i['ref'];
		}, $result['items']);
	}

	public function testLibraryListFiltersAndFlags()
	{
		$L = DesignTraceLibrary::class;
		$customer = $this->newCustomer();
		$other = $this->newCustomer('ZZ Other');
		$project = $this->newProject($customer->id);
		$d1 = $this->newDesign(array('fk_soc' => $customer->id, 'label' => 'ZZ Lamp shade', 'fk_project' => $project->id), true);
		$d2 = $this->newDesign(array('fk_soc' => $customer->id, 'label' => 'ZZ Bracket', 'confidential' => 1), true);
		$d3 = $this->newDesign(array('fk_soc' => $other->id, 'label' => 'ZZ Not theirs'), true);
		$p1 = $this->newComponent($d1, Component::TYPE_PART, 'ZZ shade');
		$p2 = $this->newComponent($d2, Component::TYPE_ASSEMBLY, 'ZZ arm');
		$p3 = $this->newComponent($d3);
		$r1 = $this->newRevision($p1, true);
		$r2 = $this->newRevision($p2, true);
		$this->newRevision($p3, true);

		$this->assertSame(0, $L::fetchLibrary($this->db, $customer->id)['total'], 'Nothing reached yet');
		$quote = $this->newQuote($customer->id);
		$this->linkDoc($r1, 'propal', $quote->id);
		DesignTraceGrant::create($this->db, $this->user, $customer->id, $d2->id);

		$all = $L::fetchLibrary($this->db, $customer->id);
		$this->assertSame(array($d1->ref, $d2->ref), $this->ids($all));
		$this->assertSame(2, $all['total']);
		$lamp = $all['items'][0];
		$this->assertSame('quote', $lamp['bases'][0]['type']);
		$this->assertSame($project->id, $lamp['project_id']);
		$this->assertFalse($lamp['confidential']);
		$this->assertTrue($lamp['flags']['consent'], 'Consent not asked yet');
		$arm = $all['items'][1];
		$this->assertTrue($arm['confidential']);
		$this->assertSame('Confidential', $arm['confidential_label']);
		$this->assertFalse($arm['flags']['consent'], 'No consent question for a confidential design');
		$this->assertSame(1, $arm['components']['assembly']);

		$this->assertSame(array($d1->ref), $this->ids($L::fetchLibrary($this->db, $customer->id, array('q' => 'shade'))));
		$this->assertSame(array($d2->ref), $this->ids($L::fetchLibrary($this->db, $customer->id, array('q' => $p2->ref))), 'Search by part number');
		$this->assertSame(array($d1->ref), $this->ids($L::fetchLibrary($this->db, $customer->id, array('q' => strtolower($d1->ref)))), 'Search by DSN');
		$this->assertSame(array($d2->ref), $this->ids($L::fetchLibrary($this->db, $customer->id, array('type' => 'assembly'))));
		$this->assertSame(array($d1->ref), $this->ids($L::fetchLibrary($this->db, $customer->id, array('project' => $project->id))));
		$this->assertSame(array($d1->ref), $this->ids($L::fetchLibrary($this->db, $customer->id, array('element' => 'propal', 'docid' => $quote->id))));
		$this->assertSame(array($d2->ref), $this->ids($L::fetchLibrary($this->db, $customer->id, array('element' => 'grant'))));
		$page = $L::fetchLibrary($this->db, $customer->id, array(), 1, 1);
		$this->assertSame(2, $page['total']);
		$this->assertSame(array($d2->ref), $this->ids($page), 'Paging');
		$this->assertCount(0, $L::fetchLibrary($this->db, $other->id, array('q' => 'lamp'))['items']);

		// Archived designs stay, flagged
		$d1->fetch($d1->id);
		$d1->cancel($this->user);
		$archived = $L::fetchLibrary($this->db, $customer->id, array('design' => $d1->id));
		$this->assertTrue($archived['items'][0]['archived']);
	}

	public function testAccessEndedShowsNameAndHistoryOnly()
	{
		$L = DesignTraceLibrary::class;
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id, 'label' => 'ZZ Gone'), true);
		$part = $this->newComponent($design);
		$rev = $this->newRevision($part);
		$this->addFile($rev, 'a.pdf', '%PDF', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->release($rev);
		$grantid = DesignTraceGrant::create($this->db, $this->user, $customer->id, $design->id);
		$this->assertFalse($L::fetchDesign($this->db, $customer->id, $design->id)['access_ended']);
		DesignTraceGrant::revoke($this->db, $this->user, $grantid);

		$entry = $L::fetchDesign($this->db, $customer->id, $design->id);
		$this->assertTrue($entry['access_ended']);
		$this->assertSame($design->ref, $entry['ref']);
		$this->assertSame(array(), $entry['tree']);
		$types = array_column($entry['history'], 'type');
		$this->assertContains('grant_given', $types);
		$this->assertContains('grant_revoked', $types);
		$this->assertCount(0, DesignTraceClientAccess::fetchVisibleFiles($this->db, $customer->id));
		$this->assertTrue($L::fetchLibrary($this->db, $customer->id)['items'][0]['access_ended']);

		// Another basis brings the access back
		$quote = $this->newQuote($customer->id);
		$this->linkDoc($rev, 'propal', $quote->id);
		$this->assertFalse($L::fetchDesign($this->db, $customer->id, $design->id)['access_ended']);
		$this->assertNull($L::fetchDesign($this->db, $this->newCustomer('ZZ Other')->id, $design->id), 'Never had access');
	}

	public function testTreeExternalComponentsAndNewerRevisions()
	{
		$L = DesignTraceLibrary::class;
		$customer = $this->newCustomer();
		$d1 = $this->newDesign(array('fk_soc' => $customer->id, 'label' => 'ZZ Main'), true);
		$d2 = $this->newDesign(array('fk_soc' => $customer->id, 'label' => 'ZZ Shared lib'), true);
		$asm = $this->newComponent($d1, Component::TYPE_ASSEMBLY, 'ZZ asm');
		$part = $this->newComponent($d1, Component::TYPE_PART, 'ZZ part');
		$ext = $this->newComponent($d2, Component::TYPE_PART, 'ZZ screw');
		$rp = $this->newRevision($part, true);
		$re = $this->newRevision($ext, true);
		$ra = $this->newRevision($asm);
		foreach (array($rp, $re) as $child) {
			$link = $this->newLink($ra, $child, ComponentLink::TYPE_USES, 1);
			$this->assertGreaterThan(0, $link->id, 'Link '.$link->error);
		}
		$this->addFile($ra, 'asm.pdf', '%PDF', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->release($ra);
		$quote = $this->newQuote($customer->id);
		$this->linkDoc($ra, 'propal', $quote->id);
		$this->linkDoc($rp, 'propal', $quote->id);

		$detail = $L::fetchDesign($this->db, $customer->id, $d1->id);
		$byref = array();
		foreach ($detail['tree'] as $node) {
			$byref[$node['ref']] = $node;
		}
		$this->assertSame(array($asm->ref, $part->ref), array_keys($byref), 'The external screw is not reached');
		$this->assertCount(1, $byref[$asm->ref]['children']);
		$this->assertSame($part->id, $byref[$asm->ref]['children'][0]['component_id']);
		$this->assertSame('assembly', $byref[$asm->ref]['type']);
		$this->assertSame(array($byref[$asm->ref]['id']), array_values(array_filter($detail['roots'], function ($id) use ($asm) {
			return $id === $asm->id;
		})));
		$this->assertCount(1, $byref[$asm->ref]['revisions'][0]['files']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $byref[$asm->ref]['revisions'][0]['files'][0]['sha256']);

		// With access to the screw too, it shows as an external dependency
		$this->linkDoc($re, 'propal', $quote->id);
		$detail = $L::fetchDesign($this->db, $customer->id, $d1->id);
		$ext2 = array_values(array_filter($detail['tree'], function ($n) use ($ext) {
			return $n['id'] === $ext->id;
		}));
		$this->assertTrue($ext2[0]['external']);
		$this->assertCount(2, array_values(array_filter($detail['tree'], function ($n) use ($asm) {
			return $n['id'] === $asm->id;
		})[0]['children']));

		// Newer revision, also for a pinned grant
		$this->assertCount(0, $L::fetchNewerRevisions($this->db, $customer->id));
		$grantid = DesignTraceGrant::create($this->db, $this->user, $customer->id, $d1->id, array($part->id));
		$rp2 = $this->newRevision($part);
		$rp2->customer_note = 'Wall thickness 2 mm';
		$rp2->update($this->user);
		$this->release($rp2);
		$newer = $L::fetchNewerRevisions($this->db, $customer->id);
		$this->assertCount(1, $newer);
		$this->assertSame($part->id, $newer[0]['component_id']);
		$this->assertSame($rp2->id, $newer[0]['current_revision']);
		$this->assertSame('Wall thickness 2 mm', $newer[0]['current_note']);
		$this->assertTrue($newer[0]['pinned']);
		$this->assertTrue($L::fetchLibrary($this->db, $customer->id, array('design' => $d1->id))['items'][0]['flags']['newer_revision']);
		$this->assertCount(1, $L::fetchLibrary($this->db, $customer->id, array('action' => 1, 'design' => $d1->id))['items']);
		unset($grantid);
	}

	public function testRevisionDetailAndCompare()
	{
		$L = DesignTraceLibrary::class;
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$part = $this->newComponent($design);
		$a = $this->newRevision($part);
		$this->addFile($a, 'body.pdf', '%PDF a', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->addFile($a, 'keep.step', 'same', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->addFile($a, 'gone.3mf', 'old', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->addFile($a, 'secret.pdf', 'hidden', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_HIDDEN);
		$a->customer_note = 'First release';
		$a->update($this->user);
		$this->release($a);
		$grantid = DesignTraceGrant::create($this->db, $this->user, $customer->id, $design->id);
		$b = $this->newRevision($part);
		$this->addFile($b, 'body.pdf', '%PDF b changed', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->addFile($b, 'keep.step', 'same', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->addFile($b, 'new.pdf', 'new', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->addFile($b, 'secret2.pdf', 'hidden', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_HIDDEN);
		$b->customer_note = 'Stronger body';
		$b->update($this->user);
		$this->release($b);

		$held = $L::fetchRevision($this->db, $customer->id, $a->id);
		$this->assertSame('First release', $held['release_note']);
		$this->assertCount(3, $held['files'], 'Hidden file not listed');
		$this->assertTrue($held['is_delivered']);
		$this->assertFalse($held['is_current']);
		$current = $L::fetchRevision($this->db, $customer->id, $b->id);
		$this->assertTrue($current['is_current']);
		$this->assertSame(array(), $current['files'], 'Files of a revision not held cannot be downloaded');
		$this->assertNull($L::fetchRevision($this->db, $customer->id, $this->newRevision($this->newComponent($design))->id));

		$diff = $L::compareRevisions($this->db, $customer->id, $part->id);
		$this->assertSame('First release', $diff['from']['release_note']);
		$this->assertSame('Stronger body', $diff['to']['release_note']);
		$changes = array();
		foreach ($diff['files'] as $f) {
			$changes[$f['name']] = $f['change'];
		}
		$this->assertSame(array('body.pdf' => 'changed', 'gone.3mf' => 'removed', 'keep.step' => 'unchanged', 'new.pdf' => 'added'), $changes, 'Hidden files never revealed');
		$this->assertNull($L::compareRevisions($this->db, $this->newCustomer('ZZ Other')->id, $part->id));
		unset($grantid);
	}

	public function testTreeListsEveryHeldRevision()
	{
		$L = DesignTraceLibrary::class;
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$part = $this->newComponent($design);
		// A goes out on an order that is then delivered, B is only quoted, C is given through a grant
		$a = $this->newRevision($part);
		$this->addFile($a, 'a.pdf', '%PDF a', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->release($a);
		$order = $this->newOrder($customer->id, true);
		$this->assertGreaterThan(0, $this->linkDoc($a, 'commande', $order->id), 'Link order '.$a->error);
		$this->assertOk($order->cloture($this->user), $order, 'Close order');
		$b = $this->newRevision($part);
		$this->addFile($b, 'b.pdf', '%PDF b', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->release($b);
		$quote = $this->newQuote($customer->id);
		$this->assertGreaterThan(0, $this->linkDoc($b, 'propal', $quote->id), 'Link quote '.$b->error);
		$c = $this->newRevision($part);
		$this->addFile($c, 'c.pdf', '%PDF c', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->release($c);
		$grantid = DesignTraceGrant::create($this->db, $this->user, $customer->id, $design->id, array($part->id));
		$this->assertGreaterThan(0, $grantid);

		$detail = $L::fetchDesign($this->db, $customer->id, $design->id);
		$this->assertCount(1, $detail['tree']);
		$node = $detail['tree'][0];
		$this->assertSame($c->id, $node['held_revision']);
		$this->assertSame($c->id, $node['delivered_revision'], 'The latest delivered one');
		$byid = array();
		foreach ($node['revisions'] as $rev) {
			$byid[$rev['id']] = $rev;
		}
		$this->assertSame(array($a->id, $b->id, $c->id), array_keys($byid), 'Earlier revisions held are listed, oldest first');
		$this->assertTrue($byid[$a->id]['is_delivered'], 'Delivered on the order');
		$this->assertFalse($byid[$b->id]['is_delivered'], 'Only quoted');
		$this->assertTrue($byid[$c->id]['is_delivered'], 'Given through the grant');
		$this->assertTrue($byid[$c->id]['is_current']);
		foreach (array($a, $b, $c) as $rev) {
			$this->assertCount(1, $byid[$rev->id]['files'], $rev->ref.' files');
		}
		$this->assertTrue($L::fetchRevision($this->db, $customer->id, $a->id)['is_delivered']);
		$this->assertFalse($L::fetchRevision($this->db, $customer->id, $b->id)['is_delivered']);
	}
}
