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
 * \file        designtrace/test/phpunit/CadTimeTest.php
 * \ingroup     designtrace
 * \brief       CAD time logged on designs, components and ECOs
 */

class CadTimeTest extends DesignTraceTestCase
{
	public function testTimeGoesToTheDesignTaskOfTheProject()
	{
		$T = DesignTraceCadTime::class;
		$project = $this->newProject();
		$design = $this->newDesign(array('label' => 'ZZ Lamp', 'fk_project' => $project->id), true);
		$component = $this->newComponent($design);
		$err = '';

		$this->assertTrue($T::userCanLog($this->user));
		$this->assertSame((int) $project->id, (int) $T::getProjectId($this->db, $design));
		$this->assertSame((int) $project->id, (int) $T::getProjectId($this->db, $component));
		$this->assertLessThan(0, $T::logTime($this->db, $this->user, $design, 0, dol_now(), 0, '', $err), 'Zero duration');

		$t1 = $T::logTime($this->db, $this->user, $design, 0, dol_now(), 5400, 'Concept sketches', $err);
		$this->assertGreaterThan(0, $t1, $err);
		$taskId = $T::findDefaultTask($this->db, $design, $project->id);
		$task = new Task($this->db);
		$task->fetch($taskId);
		$this->assertSame('CAD: '.$design->ref.' ZZ Lamp', $task->label);
		$this->assertSame((int) $project->id, (int) $task->fk_project);
		$this->assertContains((int) $this->user->id, array_map('intval', $task->getListContactId('internal')));
		$res = $this->db->query("SELECT element_duration, elementtype, note FROM ".MAIN_DB_PREFIX."element_time WHERE rowid = ".((int) $t1));
		$line = $this->db->fetch_object($res);
		$this->assertSame(5400, (int) $line->element_duration);
		$this->assertSame('task', $line->elementtype);
		$this->assertSame('Concept sketches', $line->note);

		$t2 = $T::logTime($this->db, $this->user, $component, 0, dol_now(), 3600, '', $err);
		$this->assertGreaterThan(0, $t2, $err);
		$this->assertSame($taskId, $T::findDefaultTask($this->db, $component, $project->id), 'Components use the design task');
		$lines = $T::fetchLines($this->db, $design);
		$this->assertCount(2, $lines);
		$this->assertSame(9000, array_sum(array_map(function ($l) {
			return (int) $l->duration;
		}, $lines)), 'The design rolls up its components');
		$this->assertCount(1, $T::fetchLines($this->db, $component));
		$this->assertSame('CAD work on '.$component->ref, $T::fetchLines($this->db, $component)[0]->note);
		$task->fetch($taskId);
		$this->assertSame(9000, (int) $task->duration_effective);

		$other = new Task($this->db);
		$other->fk_project = $project->id;
		$other->label = 'Commercial rate';
		$other->description = '';
		$other->note_public = '';
		$other->note_private = '';
		$other->ref = 'TKZZ'.bin2hex(random_bytes(3));
		$other->create($this->user);
		$this->assertGreaterThan(0, $T::logTime($this->db, $this->user, $design, $other->id, dol_now(), 600, 'x', $err));
		$this->assertLessThan(0, $T::logTime($this->db, $this->user, $design, 999999999, dol_now(), 600, 'x', $err), 'Task of another project');

		$row = $T::fetchLines($this->db, $component)[0];
		$this->assertGreaterThan(0, $T::deleteLine($this->db, $this->user, $row->rowid, $err), $err);
		$this->assertSame(0, $this->countRows("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."element_time WHERE rowid = ".((int) $t2)));

		$this->db->query("UPDATE ".MAIN_DB_PREFIX."element_time SET invoice_id = 1 WHERE rowid = ".((int) $t1));
		$row = array_values(array_filter($T::fetchLines($this->db, $design), function ($l) use ($t1) {
			return (int) $l->time_id === (int) $t1;
		}))[0];
		$this->assertLessThan(0, $T::deleteLine($this->db, $this->user, $row->rowid, $err), 'Invoiced time is kept');
	}

	public function testEcoNeedsAProject()
	{
		$T = DesignTraceCadTime::class;
		$eco = new ChangeOrder($this->db);
		$eco->label = 'ZZ eco';
		$eco->reason = ChangeOrder::REASON_CUSTOMER;
		$eco->create($this->user);
		$err = '';
		$this->assertLessThan(0, $T::logTime($this->db, $this->user, $eco, 0, dol_now(), 600, '', $err));
		$eco->fk_project = $this->newProject()->id;
		$eco->update($this->user);
		$this->assertGreaterThan(0, $T::logTime($this->db, $this->user, $eco, 0, dol_now(), 600, '', $err), $err);
		$this->assertCount(1, $T::fetchLines($this->db, $eco));
	}
}
