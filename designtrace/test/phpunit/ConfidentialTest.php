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
 * \file        designtrace/test/phpunit/ConfidentialTest.php
 * \ingroup     designtrace
 * \brief       Confidential designs: who sees them, list filters, document.php hook
 */

class ConfidentialTest extends DesignTraceTestCase
{
	public function testConfidentialDesignsAreHiddenFromOtherUsers()
	{
		$reader = $this->newUser(array('design/read', 'component/read', 'componentrevision/read'));
		$this->assertNotEmpty($this->user->hasRight('designtrace', 'design', 'readconfidential'));
		$this->assertEmpty($reader->hasRight('designtrace', 'design', 'readconfidential'));

		$design = $this->newDesign(array('label' => 'ZZ Secret'));
		$this->assertTrue(Design::userCanSee($reader, $design));
		$this->assertNotContains((int) $design->id, Design::getHiddenDesignIds($this->db, $reader));

		$design->confidential = 1;
		$design->update($this->user);
		$design->fetch($design->id);
		$this->assertFalse(Design::userCanSee($reader, $design));
		$this->assertContains((int) $design->id, Design::getHiddenDesignIds($this->db, $reader));
		$this->assertTrue(Design::userCanSee($this->user, $design));
		$this->assertSame('', Design::sqlConfidentialFilter($this->user));

		$component = $this->newComponent($design);
		$revision = $this->newRevision($component);
		$this->assertSame((int) $design->id, (int) Design::getDesignIdOf($this->db, $revision));
		$this->assertSame((int) $design->id, (int) Design::getDesignIdOf($this->db, $component));

		$p = MAIN_DB_PREFIX;
		$this->assertSame(0, $this->countRows("SELECT COUNT(*) as nb FROM {$p}designtrace_design as t WHERE t.rowid = ".((int) $design->id).Design::sqlConfidentialFilter($reader, 't')));
		$this->assertSame(0, $this->countRows("SELECT COUNT(*) as nb FROM {$p}designtrace_component as t WHERE t.rowid = ".((int) $component->id).Design::sqlConfidentialFilterOn($reader, 't.fk_design')));
		$this->assertSame(0, $this->countRows("SELECT COUNT(*) as nb FROM {$p}designtrace_componentrevision as t WHERE t.rowid = ".((int) $revision->id).Design::sqlConfidentialFilterOnComponent($reader, 't.fk_component')));
		$this->assertSame(0, $this->countRows("SELECT COUNT(*) as nb FROM {$p}designtrace_componentrevision as t WHERE t.rowid = ".((int) $revision->id).Design::sqlConfidentialFilterOnRevision($reader, 't.rowid')));
		$this->assertSame(1, $this->countRows("SELECT COUNT(*) as nb FROM {$p}designtrace_component as t WHERE t.rowid = ".((int) $component->id).Design::sqlConfidentialFilterOn($this->user, 't.fk_design')));

		$design->fk_user_designer = $reader->id;
		$design->update($this->user);
		$this->assertTrue(Design::userCanSee($reader, $design), 'The designer sees their own');
	}

	public function testDocumentDownloadHook()
	{
		$reader = $this->newUser(array('design/read', 'component/read', 'componentrevision/read'));
		$design = $this->newDesign(array('confidential' => 1), true);
		$revision = $this->newRevision($this->newComponent($design));
		$hooks = new HookManager($this->db);
		$actions = new ActionsDesignTrace($this->db);
		$object = new stdClass();
		$action = '';
		$revFile = DOL_DATA_ROOT.'/designtrace/componentrevision/'.$revision->ref.'/'.$revision->ref.'-x.step';

		$GLOBALS['user'] = $reader;
		$this->assertLessThan(0, $actions->downloadDocument(array('modulepart' => 'designtrace', 'original_file' => $revFile), $object, $action, $hooks));
		$this->assertLessThan(0, $actions->downloadDocument(array('modulepart' => 'designtrace', 'original_file' => DOL_DATA_ROOT.'/designtrace/design/'.$design->ref.'/img.png'), $object, $action, $hooks));
		$this->assertSame(0, $actions->downloadDocument(array('modulepart' => 'propal', 'original_file' => 'x/y.pdf'), $object, $action, $hooks), 'Other modules untouched');
		$GLOBALS['user'] = $this->user;
		$this->assertSame(0, $actions->downloadDocument(array('modulepart' => 'designtrace', 'original_file' => $revFile), $object, $action, $hooks));
	}
}
