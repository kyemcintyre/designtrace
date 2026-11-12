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
 * \file        designtrace/test/phpunit/DesignTraceTestCase.php
 * \ingroup     designtrace
 * \brief       Base class of the DesignTrace tests: one rolled-back transaction per test, fixtures
 */

use PHPUnit\Framework\TestCase;

/**
 * Each test runs in a database transaction rolled back afterwards. Files are not transactional:
 * document folders that did not exist before the test are removed after it (refs are reused once
 * the transaction is rolled back, so a leftover folder would be picked up by the next test).
 * Settings changed with setConst() are put back in memory (the database row is rolled back).
 */
abstract class DesignTraceTestCase extends TestCase
{
	/** @var DoliDB */
	protected $db;
	/** @var User */
	protected $user;
	/** @var array<string,bool> Document folders present before the test */
	private $dirsBefore = array();
	/** @var array<string,mixed> Settings changed by the test, with their value before */
	private $constBefore = array();
	/** @var string Folder for the test's source files */
	private $tmpDir = '';

	protected function setUp(): void
	{
		global $db, $user;

		$this->db = $db;
		$this->user = $user;
		$this->dirsBefore = array_fill_keys($this->listDocumentDirs(), true);
		$this->db->begin();
	}

	protected function tearDown(): void
	{
		global $conf, $user;

		while ($this->db->transaction_opened > 0) {
			$this->db->rollback();
		}
		foreach ($this->constBefore as $name => $value) {
			if ($value === null) {
				unset($conf->global->$name);
			} else {
				$conf->global->$name = $value;
			}
		}
		$this->constBefore = array();
		$user = $this->user;
		$GLOBALS['user'] = $this->user;
		foreach ($this->listDocumentDirs() as $dir) {
			if (!isset($this->dirsBefore[$dir])) {
				dol_delete_dir_recursive($dir);
			}
		}
		if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
			dol_delete_dir_recursive($this->tmpDir);
		}
		$this->tmpDir = '';
	}

	/**
	 * Object folders the tests can create files in
	 *
	 * @return string[]
	 */
	private function listDocumentDirs()
	{
		global $conf;

		$roots = array();
		$base = $conf->designtrace->multidir_output[$conf->entity] ?? (DOL_DATA_ROOT.'/designtrace');
		foreach (array('design', 'component', 'componentrevision', 'changeorder') as $sub) {
			$roots[] = $base.'/'.$sub;
		}
		foreach (array('propal', 'commande', 'ticket') as $module) {
			if (!empty($conf->$module->multidir_output[$conf->entity])) {
				$roots[] = $conf->$module->multidir_output[$conf->entity];
			}
		}
		$dirs = array();
		foreach ($roots as $root) {
			foreach ((glob($root.'/*', GLOB_ONLYDIR) ?: array()) as $dir) {
				$dirs[] = $dir;
			}
		}
		return $dirs;
	}

	/**
	 * Change a setting for this test only
	 *
	 * @param	string		$name	Constant name
	 * @param	string|null	$value	Value, null to delete it
	 * @return	void
	 */
	protected function setConst($name, $value)
	{
		global $conf;

		if (!array_key_exists($name, $this->constBefore)) {
			$this->constBefore[$name] = $conf->global->$name ?? null;
		}
		if ($value === null) {
			dolibarr_del_const($this->db, $name, $conf->entity);
			unset($conf->global->$name);
		} else {
			dolibarr_set_const($this->db, $name, $value, 'chaine', 0, '', $conf->entity);
		}
	}

	/**
	 * A source file to upload
	 *
	 * @param	string	$content	File content
	 * @return	string				Path
	 */
	protected function tmpFile($content)
	{
		if ($this->tmpDir === '') {
			$this->tmpDir = sys_get_temp_dir().'/designtrace-test-'.getmypid().'-'.bin2hex(random_bytes(4));
			dol_mkdir($this->tmpDir);
		}
		$path = $this->tmpDir.'/'.bin2hex(random_bytes(6));
		file_put_contents($path, $content);
		return $path;
	}

	/**
	 * A zip file (3MF) to upload
	 *
	 * @param	array<string,string>	$entries	Name => content
	 * @return	string							Path
	 */
	protected function tmpZip($entries)
	{
		$path = $this->tmpFile('');
		unlink($path);
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		foreach ($entries as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();
		return $path;
	}

	/**
	 * Fail with the object's error when a call did not succeed
	 *
	 * @param	int|object	$result		Result of create(), release()...
	 * @param	object		$object		Object holding error/errors
	 * @param	string		$what		What was done
	 * @return	void
	 */
	protected function assertOk($result, $object, $what)
	{
		$ok = is_object($result) || (is_numeric($result) && $result > 0) || (is_array($result));
		$this->assertTrue($ok, $what.' failed: '.($object->error ?? '').' '.implode(', ', (array) ($object->errors ?? array())));
	}

	protected function newCustomer($name = 'ZZ Test customer')
	{
		$soc = new Societe($this->db);
		$soc->name = $name;
		$soc->client = 1;
		$soc->code_client = 'auto';
		$this->assertOk($soc->create($this->user), $soc, 'Create customer');
		return $soc;
	}

	protected function newProject($socid = 0)
	{
		$project = new Project($this->db);
		$project->ref = 'ZZPJ'.bin2hex(random_bytes(3));
		$project->title = 'ZZ test project';
		$project->socid = $socid;
		$project->date_start = dol_now();
		$project->opp_amount = '';
		$project->description = '';
		$this->assertOk($project->create($this->user), $project, 'Create project');
		$this->assertOk($project->setValid($this->user), $project, 'Validate project');
		return $project;
	}

	/**
	 * @param	array<string,mixed>	$props		Properties to set before create
	 * @param	bool				$validate	Make it Active (DSN number)
	 */
	protected function newDesign($props = array(), $validate = false)
	{
		$design = new Design($this->db);
		$design->label = 'ZZ test design';
		foreach ($props as $key => $value) {
			$design->$key = $value;
		}
		$this->assertOk($design->create($this->user), $design, 'Create design');
		if ($validate) {
			$this->assertOk($design->validate($this->user), $design, 'Validate design');
		}
		$design->fetch($design->id);
		return $design;
	}

	protected function newComponent($design, $type = Component::TYPE_PART, $label = 'ZZ part', $drawingType = null)
	{
		$component = new Component($this->db);
		$component->fk_design = $design->id;
		$component->type = $type;
		$component->drawing_type = $drawingType;
		$component->label = $label;
		$this->assertOk($component->create($this->user), $component, 'Create component');
		$component->fetch($component->id);
		return $component;
	}

	protected function newRevision($component, $release = false)
	{
		$revision = new ComponentRevision($this->db);
		$revision->fk_component = $component->id;
		$this->assertOk($revision->create($this->user), $revision, 'Create revision');
		$revision->fetch($revision->id);
		if ($release) {
			$this->release($revision);
		}
		return $revision;
	}

	protected function release($revision)
	{
		$revision->fetch($revision->id);
		$this->assertOk($revision->release($this->user), $revision, 'Release '.$revision->ref);
		$revision->fetch($revision->id);
		return $revision;
	}

	/**
	 * @return RevisionFile
	 */
	protected function addFile($revision, $name, $content, $role = RevisionFile::ROLE_PRESENTATION, $visibility = RevisionFile::VISIBILITY_HIDDEN)
	{
		$file = $revision->addFile($this->user, $this->tmpFile($content), $name, $role, 0);
		$this->assertIsObject($file, 'Add '.$name.': '.$revision->error);
		if ($visibility) {
			$file->visibility = $visibility;
			$file->update($this->user);
		}
		return $file;
	}

	protected function newLink($parent, $child, $type, $qty = null)
	{
		$link = new ComponentLink($this->db);
		$link->fk_parent = $parent->id;
		$link->fk_child = $child->id;
		$link->link_type = $type;
		$link->qty = $qty;
		$link->create($this->user);
		return $link;
	}

	protected function newQuote($socid, $validate = true)
	{
		$quote = new Propal($this->db);
		$quote->socid = $socid;
		$quote->date = dol_now();
		$quote->duree_validite = 30;
		$this->assertOk($quote->create($this->user), $quote, 'Create quote');
		if ($validate) {
			$this->assertOk($quote->valid($this->user), $quote, 'Validate quote');
		}
		$quote->fetch($quote->id);
		return $quote;
	}

	/**
	 * @param	array<int,array{0:float,1:float}>	$lines	Lines (unit price, qty)
	 */
	protected function newOrder($socid, $validate = true, $delivered = false, $lines = array())
	{
		$order = new Commande($this->db);
		$order->socid = $socid;
		$order->date = dol_now();
		$this->assertOk($order->create($this->user), $order, 'Create order');
		foreach ($lines as $line) {
			$order->addline('ZZ print', $line[0], $line[1], 0);
		}
		if ($validate) {
			$this->assertOk($order->valid($this->user), $order, 'Validate order');
		}
		if ($delivered) {
			$this->assertOk($order->cloture($this->user), $order, 'Close order');
		}
		$order->fetch($order->id);
		return $order;
	}

	protected function newTicket($socid, $subject = 'ZZ test ticket')
	{
		$ticket = new Ticket($this->db);
		$ticket->subject = $subject;
		$ticket->message = 'ZZ';
		$ticket->fk_soc = $socid;
		$ticket->ref = $ticket->getDefaultRef();
		$ticket->track_id = generate_random_id(16);
		$this->assertOk($ticket->create($this->user), $ticket, 'Create ticket');
		return $ticket;
	}

	/**
	 * Link a revision to a quote, order, ticket...
	 */
	protected function linkDoc($revision, $element, $docid)
	{
		$revision->error = '';
		$revision->errors = array();
		return DesignTraceDocLinks::link($this->user, $revision, $element, $docid);
	}

	/**
	 * A user without admin rights, with the given right ids
	 *
	 * @param	int[]	$rights		Right ids
	 */
	protected function newUser($rights = array())
	{
		$u = new User($this->db);
		$u->login = 'zz_test_'.bin2hex(random_bytes(4));
		$u->lastname = 'ZZ test user';
		$u->statut = 1;
		$this->assertOk($u->create($this->user), $u, 'Create user');
		foreach ($rights as $id) {
			$u->addrights($id);
		}
		$u->clearrights();
		$u->loadRights();
		return $u;
	}

	/**
	 * A customer with what the portals show: a design with a part (released, PDF and STEP visible
	 * now) and a proof drawing (in review, PDF visible now), on a validated quote, and the part on a
	 * delivered order (one line, 2 x 25)
	 *
	 * @return array{customer:Societe,design:Design,part:Component,rev:ComponentRevision,proof:ComponentRevision,quote:Propal,order:Commande,pdf:RevisionFile,step:RevisionFile,proofpdf:RevisionFile}
	 */
	protected function newCustomerScenario()
	{
		$customer = $this->newCustomer('ZZ Scenario customer');
		$project = $this->newProject($customer->id);
		$design = $this->newDesign(array('label' => 'ZZ Lamp', 'fk_soc' => $customer->id, 'fk_project' => $project->id), true);
		$part = $this->newComponent($design, Component::TYPE_PART, 'Shade');
		$rev = new ComponentRevision($this->db);
		$rev->fk_component = $part->id;
		$rev->material = 'PETG';
		$rev->machine = 'Bambu X1C';
		$this->assertOk($rev->create($this->user), $rev, 'Create revision');
		$rev->fetch($rev->id);
		$pdf = $this->addFile($rev, 'shade.pdf', '%PDF-1.4 shade '.bin2hex(random_bytes(8)), RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$step = $this->addFile($rev, 'shade.step', 'ISO-10303-21; '.bin2hex(random_bytes(8)), RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->release($rev);

		$drawing = $this->newComponent($design, Component::TYPE_DRAWING, 'Proof', Component::DRAWING_PROOF);
		$proof = $this->newRevision($drawing);
		$proofpdf = $this->addFile($proof, 'proof.pdf', '%PDF-1.4 proof', RevisionFile::ROLE_PRESENTATION, RevisionFile::VISIBILITY_NOW);
		$this->assertOk($proof->validate($this->user), $proof, 'Proof in review');
		$proof->fetch($proof->id);

		$quote = $this->newQuote($customer->id);
		$this->assertGreaterThan(0, $this->linkDoc($rev, 'propal', $quote->id), (string) $rev->error);
		$this->assertGreaterThan(0, $this->linkDoc($proof, 'propal', $quote->id), (string) $proof->error);
		$design->add_object_linked('propal', $quote->id);

		$order = new Commande($this->db);
		$order->socid = $customer->id;
		$order->date = dol_now();
		$order->fk_project = $project->id;
		$this->assertOk($order->create($this->user), $order, 'Create order');
		$order->addline('ZZ shade print', 25, 2, 0);
		$this->assertGreaterThan(0, $this->linkDoc($rev, 'commande', $order->id), (string) $rev->error);
		$design->add_object_linked('commande', $order->id);
		$this->assertOk($order->valid($this->user), $order, 'Validate order');
		$this->assertOk($order->cloture($this->user), $order, 'Deliver order');
		$order->fetch($order->id);
		$order->fetch_lines();

		return array('customer' => $customer, 'design' => $design, 'part' => $part, 'rev' => $rev, 'proof' => $proof,
			'quote' => $quote, 'order' => $order, 'pdf' => $pdf, 'step' => $step, 'proofpdf' => $proofpdf);
	}

	protected function countRows($sql)
	{
		$resql = $this->db->query($sql);
		$this->assertNotFalse($resql, (string) $this->db->lasterror());
		$obj = $this->db->fetch_object($resql);
		return (int) $obj->nb;
	}
}
