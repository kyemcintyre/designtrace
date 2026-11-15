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
 * \file        designtrace/test/phpunit/ReviewFixesTest.php
 * \ingroup     designtrace
 * \brief       Regression tests for the findings of the review of the customer library branch
 */

dol_include_once('/designtrace/class/grant.class.php');
dol_include_once('/designtrace/class/changerequests.class.php');
dol_include_once('/designtrace/class/approvals.class.php');
dol_include_once('/designtrace/class/staffapi.class.php');

class ReviewFixesTest extends DesignTraceTestCase
{
	/**
	 * @var DesignTrace
	 */
	private $api;

	protected function setUp(): void
	{
		parent::setUp();
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

	private function newOrderFromQuote($customer, $quote)
	{
		$order = new Commande($this->db);
		$order->socid = $customer->id;
		$order->date = dol_now();
		$order->origin = 'propal';
		$order->origin_type = 'propal';
		$order->origin_id = $quote->id;
		$order->linked_objects = array('propal' => $quote->id);
		$this->assertOk($order->create($this->user), $order, 'Order from quote');
		$order->fetch($order->id);
		return $order;
	}

	public function testForcedReacceptanceIsNotCarriedBackToANewOrderAndSameVersionCanBeAcceptedAgain()
	{
		$L = DesignTraceLicensing::class;
		$customer = $this->newCustomer();
		$licence = $this->newLicence('ZZREV', false);
		$design = $this->newDesign(array('fk_soc' => $customer->id, 'fk_licence' => $licence->id), true);
		$quote = $this->newQuote($customer->id);
		$design->add_object_linked('propal', $quote->id);
		$version = $licence->getCurrentVersion();
		$err = '';
		$first = $L::accept($this->db, $customer->id, 'propal', $quote->id, $design->id, $version->id, 'api', 'zz', $err);
		$this->assertGreaterThan(0, $first, $err);
		$firstFile = $L::getAcceptance($this->db, $customer->id, $design->id)->filename;

		$this->assertGreaterThan(0, $L::forceReacceptance($this->db, $design->id, $customer->id));
		$order = $this->newOrderFromQuote($customer, $quote);
		$this->assertFalse($L::isAccepted($this->db, $customer->id, $design->id, 'commande', $order->id), 'A revoked acceptance is not copied to the order');
		$this->assertFalse($L::isAccepted($this->db, $customer->id, $design->id, 'propal', $quote->id));

		// The same version, accepted again after the force: no duplicate-key error, earlier PDF kept as evidence
		$second = $L::accept($this->db, $customer->id, 'propal', $quote->id, $design->id, $version->id, 'api', 'zz', $err);
		$this->assertGreaterThan(0, $second, $err);
		$this->assertNotSame($first, $second);
		$this->assertTrue($L::isAccepted($this->db, $customer->id, $design->id, 'propal', $quote->id));
		$secondFile = $L::getAcceptance($this->db, $customer->id, $design->id)->filename;
		$this->assertNotSame($firstFile, $secondFile, 'The evidence copy of the first acceptance is not overwritten');

		// Same for the design itself
		$forced = $L::forceReacceptance($this->db, $design->id, $customer->id);
		$this->assertGreaterThan(0, $forced, 'Second force: '.$this->db->lasterror());
		$third = $L::acceptDesign($this->db, $customer->id, $design->id, $version->id, 'api', 'zz', $err);
		$this->assertGreaterThan(0, $third, 'acceptDesign: '.$err);
		$this->assertTrue($L::isAccepted($this->db, $customer->id, $design->id));
	}

	public function testAcceptancesUnderTwoTemplatesLiveSideBySide()
	{
		$L = DesignTraceLicensing::class;
		$customer = $this->newCustomer();
		$a = $this->newLicence('ZZTA', false);
		$b = $this->newLicence('ZZTB', false);
		$design = $this->newDesign(array('fk_soc' => $customer->id, 'fk_licence' => $a->id), true);
		$quote = $this->newQuote($customer->id);
		$design->add_object_linked('propal', $quote->id);
		$L::setOverride($this->db, $this->user, 'propal', $quote->id, $b->id);
		$err = '';
		$id = $L::acceptDesign($this->db, $customer->id, $design->id, $b->getCurrentVersion()->id, 'api', 'zz', $err);
		$this->assertGreaterThan(0, $id, $err);
		$this->assertTrue($L::isAccepted($this->db, $customer->id, $design->id), 'Reached through a grant or project: the template accepted for their quote counts');
		$this->assertTrue($L::isAccepted($this->db, $customer->id, $design->id, 'propal', $quote->id));
	}

	public function testChangeRequestDoesNotTurnAnEndedGrantIntoPermanentAccess()
	{
		$CA = DesignTraceClientAccess::class;
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$part = $this->newComponent($design);
		$rev = $this->newRevision($part);
		$this->addFile($rev, 'a.pdf', '%PDF a', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$rev = $this->release($rev);
		$grantid = DesignTraceGrant::create($this->db, $this->user, $customer->id, $design->id);
		$this->assertGreaterThan(0, $grantid);
		$err = '';
		$res = DesignTraceChangeRequests::report($this->db, $this->user, $customer->id, 'change', $rev->id, 'Longer', 'Make it longer', 0, 'portal:ann', $err);
		$this->assertIsArray($res, $err);
		DesignTraceGrant::revoke($this->db, $this->user, $grantid);
		$this->assertCount(0, $CA::fetchVisibleFiles($this->db, $customer->id), 'The ticket link is not a way to the files');
	}

	public function testMovePinRefusesUnpinnedComponentsAndEndedGrants()
	{
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$part = $this->newComponent($design);
		$a = $this->release($this->newRevision($part));
		$grantid = DesignTraceGrant::create($this->db, $this->user, $customer->id, $design->id, array($part->id));
		$other = $this->newComponent($design, Component::TYPE_PART, 'ZZ other');
		$o = $this->release($this->newRevision($other));
		$this->assertSame(-3, DesignTraceGrant::movePin($this->db, $grantid, $other->id, $o->id), 'The grant does not cover that component');
		$this->assertFalse(DesignTraceGrant::isDelivered($this->db, $customer->id, $o->id), 'Nothing delivered for a pin that was not moved');
		$b = $this->release($this->newRevision($part));
		DesignTraceGrant::revoke($this->db, $this->user, $grantid);
		$this->assertSame(-3, DesignTraceGrant::movePin($this->db, $grantid, $part->id, $b->id), 'An ended grant cannot be changed');
		$this->assertSame(-4, DesignTraceGrant::create($this->db, $this->user, $customer->id, $this->newDesign(array(), false)->id), 'A draft design is not granted');
		$this->assertSame(-4, DesignTraceGrant::create($this->db, $this->user, 999999999, $design->id), 'Unknown customer');
	}

	public function testCustomerDecisionIsForgottenWhenTheRevisionGoesBackToDraft()
	{
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$quote = $this->newQuote($customer->id);
		$rev = $this->newRevision($this->newComponent($design));
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_componentrevision SET needs_approval = 1 WHERE rowid = ".((int) $rev->id));
		$rev->fetch($rev->id);
		$this->assertGreaterThan(0, $rev->validate($this->user));
		$this->assertGreaterThan(0, $this->linkDoc($rev, 'propal', $quote->id));
		$err = '';
		$this->assertIsArray(DesignTraceApprovals::approve($this->db, $this->user, $customer->id, 'design', $design->id, 'user', '', 'api', 'portal:ann', $err), $err);
		$rev->fetch($rev->id);
		$this->assertSame(1, (int) $rev->customer_approval);

		$this->assertGreaterThan(0, $rev->setDraft($this->user));
		$rev->fetch($rev->id);
		$this->assertSame(0, (int) $rev->customer_approval, 'Reworked: the old approval no longer counts');
		$this->assertLessThan(0, $rev->release($this->user), 'Release waits for a new approval');
		$this->assertGreaterThan(0, $rev->validate($this->user));
		$pending = DesignTraceApprovals::fetchPending($this->db, $customer->id);
		$this->assertSame(1, (int) array_values($pending)[0]['awaiting'], 'The resubmission is awaiting the customer again');
	}

	public function testEcoDetailShowsReleaseDatesOfResultingRevisions()
	{
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);
		$part = $this->newComponent($design);
		$rev = $this->release($this->newRevision($part));
		$order = $this->newOrder($customer->id, true);
		$this->linkDoc($rev, 'commande', $order->id);
		$err = '';
		$res = DesignTraceChangeRequests::report($this->db, $this->user, $customer->id, 'change', $rev->id, 'Wider', 'Wider please', 0, 'portal:ann', $err);
		$this->assertIsArray($res, $err);
		$eco = new ChangeOrder($this->db);
		$eco->fetch($res['eco_id']);
		$this->assertGreaterThan(0, $eco->approve($this->user), (string) $eco->error);
		$next = $this->newRevision($part);
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_componentrevision SET fk_eco = ".((int) $eco->id)." WHERE rowid = ".((int) $next->id));
		$this->release($next);
		$detail = DesignTraceChangeRequests::fetchDetail($this->db, $customer->id, $eco->id);
		$this->assertNotEmpty($detail['resulting_revisions']);
		$this->assertGreaterThan(time() - 3600, $detail['resulting_revisions'][0]['date_release']);
	}

	public function testHistoryShowsNothingOfOtherCustomersOrOlderRevisions()
	{
		$mine = $this->newCustomer('ZZ Mine');
		$other = $this->newCustomer('ZZ Other');
		$design = $this->newDesign(array('fk_soc' => $mine->id), true);
		$part = $this->newComponent($design);
		$old = $this->release($this->newRevision($part));
		$held = $this->release($this->newRevision($part));
		// The other customer holds revision B through a grant, answers consent, mine has nothing on record
		$grantid = DesignTraceGrant::create($this->db, $this->user, $other->id, $design->id);
		$this->assertGreaterThan(0, $grantid);
		DesignTracePortfolio::setConsent($this->db, (int) $design->id, DesignTracePortfolio::CONSENT_GRANTED, DesignTracePortfolio::CHANNEL_API, 'portal:other-secret', '', 0, 0, '');
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."designtrace_consent SET fk_design = ".((int) $design->id)." WHERE actor = 'portal:other-secret'");
		$quote = $this->newQuote($mine->id);
		$this->linkDoc($held, 'propal', $quote->id);

		$history = DesignTraceLibrary::fetchHistory($this->db, $mine->id);
		$texts = array_column($history, 'text');
		$this->assertContains($held->ref, $texts);
		$this->assertNotContains($old->ref, $texts, 'A revision older than the held one was never given');
		foreach ($history as $event) {
			$this->assertStringNotContainsString('other-secret', $event['actor'], 'No other party in the history');
			if ($event['type'] === 'consent') {
				$this->assertSame('', $event['actor'], 'Not given on their own document: nobody is named');
			}
			if ($event['type'] === 'release') {
				$this->assertSame('', $event['actor'], 'No staff login');
			}
		}
		// The design is made for this customer, so the answer is theirs to see (but not through someone else's actor)
		$consents = array_filter($history, function ($e) {
			return $e['type'] === 'consent';
		});
		$this->assertCount(1, $consents);

		$otherHistory = DesignTraceLibrary::fetchHistory($this->db, $other->id);
		$this->assertCount(0, array_filter($otherHistory, function ($e) {
			return $e['type'] === 'consent';
		}), 'The design is not made for them: they do not see its consent answers');
	}

	public function testOnlyTheCustomerADesignIsMadeForAnswersItsConsent()
	{
		$owner = $this->newCustomer('ZZ Owner');
		$guest = $this->newCustomer('ZZ Guest');
		$design = $this->newDesign(array('fk_soc' => $owner->id), true);
		$this->release($this->newRevision($this->newComponent($design)));
		$this->assertGreaterThan(0, DesignTraceGrant::create($this->db, $this->user, $guest->id, $design->id));
		$this->assertGreaterThan(0, DesignTraceGrant::create($this->db, $this->user, $owner->id, $design->id));

		try {
			$this->api->setDesignConsent($guest->id, $design->id, 'refused', 'portal:guest');
			$this->fail('Expected a 403');
		} catch (\Luracast\Restler\RestException $e) {
			$this->assertSame(403, $e->getCode());
		}
		$this->assertTrue($this->api->setDesignConsent($owner->id, $design->id, 'granted', 'portal:owner')['changed']);
	}

	public function testLicenceAcceptedThroughTheDesignGivesPortfolioConsentToItsOwnerOnly()
	{
		$L = DesignTraceLicensing::class;
		$owner = $this->newCustomer('ZZ Owner');
		$guest = $this->newCustomer('ZZ Guest');
		$licence = $this->newLicence('ZZPF', false);
		$version = $licence->getDraftVersion() ?: $licence->getCurrentVersion();
		$new = $licence->newVersion($this->user);
		$new->perm_portfolio = 1;
		$new->update($this->user);
		$new->activate($this->user);
		$design = $this->newDesign(array('fk_soc' => $owner->id, 'fk_licence' => $licence->id), true);
		$err = '';
		$L::acceptDesign($this->db, $guest->id, $design->id, $licence->getCurrentVersion()->id, 'api', 'g', $err);
		$design->fetch($design->id);
		$this->assertSame(DesignTracePortfolio::CONSENT_NONE, (int) $design->portfolio_consent, 'A guest accepting does not decide for the owner');
		$L::acceptDesign($this->db, $owner->id, $design->id, $licence->getCurrentVersion()->id, 'api', 'o', $err);
		$design->fetch($design->id);
		$this->assertSame(DesignTracePortfolio::CONSENT_GRANTED, (int) $design->portfolio_consent);
	}

	public function testStaffLibraryAndEcosHideConfidentialDesigns()
	{
		$customer = $this->newCustomer();
		$secret = $this->newDesign(array('fk_soc' => $customer->id, 'confidential' => 1), true);
		$part = $this->newComponent($secret);
		$eco = new ChangeOrder($this->db);
		$eco->label = 'ZZ secret eco';
		$eco->reason = ChangeOrder::REASON_CUSTOMER;
		$eco->fk_soc = $customer->id;
		$this->assertOk($eco->create($this->user), $eco, 'ECO');
		$eco->addAffectedComponent($this->user, $part->id, '');
		$open = $this->newDesign(array('fk_soc' => $customer->id), true);
		$this->release($this->newRevision($this->newComponent($open)));
		DesignTraceGrant::create($this->db, $this->user, $customer->id, $open->id);
		$this->release($this->newRevision($part));
		DesignTraceGrant::create($this->db, $this->user, $customer->id, $secret->id);

		$this->assertTrue(DesignTraceStaff::isEcoHidden($this->db, $eco, array((int) $secret->id)));
		$this->assertFalse(DesignTraceStaff::isEcoHidden($this->db, $eco, array()));
		$this->assertFalse(DesignTraceStaff::isEcoHidden($this->db, $eco, array((int) $open->id)));

		$page = DesignTraceLibrary::fetchLibrary($this->db, $customer->id, array('exclude' => array((int) $secret->id)), 0, 25);
		$this->assertSame(1, $page['total'], 'Hidden designs are out of the total, not just the page');
		$this->assertSame(array((int) $open->id), array_column($page['items'], 'id'));
	}

	public function testCompareDoesNotShowMetadataOfFilesTheCustomerCannotGet()
	{
		$customer = $this->newCustomer();
		$design = $this->newDesign(array('fk_soc' => $customer->id), true);	// workshop-owned: never delivers source
		$part = $this->newComponent($design);
		$a = $this->newRevision($part);
		$this->addFile($a, 'a.pdf', '%PDF a', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->addFile($a, 'a.fcstd', 'SRC a', RevisionFile::ROLE_SOURCE, RevisionFile::VISIBILITY_NOW);
		$this->release($a);
		$b = $this->newRevision($part);
		$this->addFile($b, 'a.pdf', '%PDF b', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->addFile($b, 'a.fcstd', 'SRC b', RevisionFile::ROLE_SOURCE, RevisionFile::VISIBILITY_NOW);
		$this->release($b);
		DesignTraceGrant::create($this->db, $this->user, $customer->id, $design->id, array($part->id), array($part->id => $a->id));
		$compare = DesignTraceLibrary::compareRevisions($this->db, $customer->id, $part->id, $a->id, $b->id);
		$this->assertNotNull($compare);
		$names = array_column($compare['files'], 'name');
		$this->assertContains('a.pdf', $names);
		$this->assertNotContains('a.fcstd', $names, 'Source files the customer cannot download are not described');
	}
}
