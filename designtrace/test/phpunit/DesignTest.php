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
 * \file        designtrace/test/phpunit/DesignTest.php
 * \ingroup     designtrace
 * \brief       Designs, components and revisions: numbering, defaults, workflow
 */

class DesignTest extends DesignTraceTestCase
{
	public function testDesignNumberedOnValidateAndDesignerDefaultsToCreator()
	{
		$design = $this->newDesign();
		$this->assertStringStartsWith('(PROV', $design->ref);
		$this->assertSame((int) $this->user->id, (int) $design->fk_user_designer);

		$this->assertOk($design->validate($this->user), $design, 'Validate');
		$design->fetch($design->id);
		$this->assertMatchesRegularExpression('/^DSN-\d{4}$/', $design->ref);
		$this->assertSame(Design::STATUS_VALIDATED, (int) $design->status);

		$other = $this->newDesign(array('fk_user_designer' => 999));
		$this->assertSame(999, (int) $other->fk_user_designer);
	}

	public function testDesignDefaultsToWorkshopOwnedAndNotConfidential()
	{
		$design = $this->newDesign();
		$this->assertSame(Design::OWNERSHIP_WORKSHOP, (int) $design->ownership);
		$this->assertSame(0, (int) $design->confidential);
		$this->assertFalse(Design::ownershipAllowsSource(Design::OWNERSHIP_WORKSHOP));
		$this->assertTrue(Design::ownershipAllowsSource(Design::OWNERSHIP_CUSTOMER));
		$this->assertTrue(Design::ownershipAllowsSource(Design::OWNERSHIP_SHARED));
	}

	public function testDesignWithComponentsCannotBeDeleted()
	{
		$design = $this->newDesign();
		$this->newComponent($design);
		$this->assertLessThanOrEqual(0, $design->delete($this->user));
		$this->assertSame(1, $this->countRows('SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'designtrace_design WHERE rowid = '.((int) $design->id)));
	}

	public function testDesignTakesCustomerAndProjectFromItsQuote()
	{
		$customer = $this->newCustomer();
		$project = $this->newProject($customer->id);
		$quote = new Propal($this->db);
		$quote->socid = $customer->id;
		$quote->date = dol_now();
		$quote->duree_validite = 30;
		$quote->fk_project = $project->id;
		$quote->create($this->user);
		$order = $this->newOrder($customer->id, false);

		$design = $this->newDesign(array('linked_objects' => array('propal' => $quote->id, 'commande' => $order->id)));
		$this->assertSame((int) $customer->id, (int) $design->fk_soc);
		$this->assertSame((int) $project->id, (int) $design->fk_project);
		$design->fetchObjectLinked();
		$this->assertArrayHasKey('propal', $design->linkedObjectsIds);
		$this->assertArrayHasKey('commande', $design->linkedObjectsIds);
	}

	public function testDesignRefusesQuoteOfAnotherCustomerOrUnknownDocuments()
	{
		$a = $this->newCustomer('ZZ A');
		$b = $this->newCustomer('ZZ B');
		$quote = $this->newQuote($a->id, false);

		$design = new Design($this->db);
		$design->label = 'ZZ wrong customer';
		$design->fk_soc = $b->id;
		$design->linked_objects = array('propal' => $quote->id);
		$this->assertLessThan(0, $design->create($this->user));
		$this->assertEmpty($design->id);

		$design = new Design($this->db);
		$design->label = 'ZZ unknown order';
		$design->linked_objects = array('commande' => 999999999);
		$this->assertLessThan(0, $design->create($this->user));

		$design = new Design($this->db);
		$design->label = 'ZZ invoice';
		$design->linked_objects = array('facture' => 1);
		$this->assertLessThan(0, $design->create($this->user));
	}

	public function testComponentsAreNumberedPerTypeAndDrawingsLosePhysicalFields()
	{
		$design = $this->newDesign();
		$part = new Component($this->db);
		$part->fk_design = $design->id;
		$part->type = Component::TYPE_PART;
		$part->label = 'Plate';
		$part->sourcing = Component::SOURCING_PRINT;
		$part->finish = 'Black PLA';
		$part->weight = 12.5;
		$this->assertOk($part->create($this->user), $part, 'Create part');
		$part->fetch($part->id);
		$this->assertMatchesRegularExpression('/^PRT-\d{4}$/', $part->ref);
		$this->assertSame('Black PLA', $part->finish);

		$assembly = $this->newComponent($design, Component::TYPE_ASSEMBLY, 'Frame');
		$this->assertMatchesRegularExpression('/^ASM-\d{4}$/', $assembly->ref);

		$drawing = new Component($this->db);
		$drawing->fk_design = $design->id;
		$drawing->type = Component::TYPE_DRAWING;
		$drawing->drawing_type = Component::DRAWING_TDS;
		$drawing->label = 'Drawing';
		$drawing->sourcing = Component::SOURCING_PRINT;
		$drawing->finish = 'x';
		$drawing->weight = 3;
		$this->assertOk($drawing->create($this->user), $drawing, 'Create drawing');
		$drawing->fetch($drawing->id);
		$this->assertMatchesRegularExpression('/^DRW-\d{4}$/', $drawing->ref);
		$this->assertEmpty($drawing->sourcing);
		$this->assertEmpty($drawing->finish);
		$this->assertEmpty($drawing->weight);
	}

	public function testComponentRefusesBadValues()
	{
		$part = $this->newComponent($this->newDesign());
		$part->weight = -1;
		$this->assertLessThan(0, $part->update($this->user));
		$part->fetch($part->id);
		$part->sourcing = 7;
		$this->assertLessThan(0, $part->update($this->user));
	}

	public function testComponentProjectDefaultsToTheDesigns()
	{
		$project = $this->newProject();
		$part = $this->newComponent($this->newDesign(array('fk_project' => $project->id)));
		$this->assertSame((int) $project->id, (int) $part->fk_project);
	}

	public function testRevisionLettersWorkflowAndClone()
	{
		$part = $this->newComponent($this->newDesign());
		$a = new ComponentRevision($this->db);
		$a->fk_component = $part->id;
		$a->change_reason = 1;
		$a->material = 'PETG';
		$a->process = 'FDM';
		$a->machine = 'X1C';
		$this->assertOk($a->create($this->user), $a, 'Create revision');
		$a->fetch($a->id);
		$this->assertSame('A', $a->rev);
		$this->assertSame($part->ref.'-A', $a->ref);
		$this->assertSame(ComponentRevision::STATUS_DRAFT, (int) $a->status);

		$second = new ComponentRevision($this->db);
		$second->fk_component = $part->id;
		$this->assertLessThan(0, $second->create($this->user), 'One draft or in-review revision at a time');

		$this->assertOk($a->validate($this->user), $a, 'Submit for review');
		$a->fetch($a->id);
		$this->assertSame(ComponentRevision::STATUS_INREVIEW, (int) $a->status);
		$this->release($a);
		$this->assertSame(ComponentRevision::STATUS_RELEASED, (int) $a->status);
		$this->assertTrue($a->isLocked());
		$this->assertLessThan(0, $a->delete($this->user), 'Released revisions cannot be deleted');

		$b = $a->createFromClone($this->user, $a->id);
		$this->assertIsObject($b);
		$this->assertSame('B', $b->rev);
		$this->assertNull($b->change_reason);
		$this->assertSame('PETG', $b->material);
		$this->assertSame('FDM', $b->process);
		$this->assertSame(0, (int) $b->customer_approval);
	}

	public function testPrintSettingsAreGone()
	{
		$revision = new ComponentRevision($this->db);
		foreach (array('layer_height', 'nozzle', 'infill', 'print_time', 'filament') as $field) {
			$this->assertArrayNotHasKey($field, $revision->fields);
		}
	}

	public function testOwnershipIsNormalised()
	{
		$design = $this->newDesign(array('ownership' => 42, 'confidential' => 'yes'));
		$this->assertSame(Design::OWNERSHIP_WORKSHOP, (int) $design->ownership);
		$this->assertSame(1, (int) $design->confidential);
	}
}
