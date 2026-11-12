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
 * \file        designtrace/test/phpunit/RevisionFileTest.php
 * \ingroup     designtrace
 * \brief       Revision files: names, roles, hashes, limits, verification, lock on release
 */

class RevisionFileTest extends DesignTraceTestCase
{
	private $part;
	private $revision;

	protected function setUp(): void
	{
		parent::setUp();
		$this->part = $this->newComponent($this->newDesign(), Component::TYPE_PART, 'Plate');
		$this->revision = $this->newRevision($this->part);
	}

	public function testNamesRolesAndHashes()
	{
		$ref = $this->part->ref;
		$f = $this->addFile($this->revision, 'Bracket.FCStd', 'fcstd v1', 0);
		$this->assertSame($ref.'-Bracket.fcstd', $f->filename);
		$this->assertSame(RevisionFile::ROLE_SOURCE, (int) $f->role);
		$this->assertSame(hash('sha256', 'fcstd v1'), $f->sha256);
		$this->assertSame('fcstd', $f->format);
		$this->assertSame(8, (int) $f->filesize);
		$this->assertFileExists($this->revision->getFilesDir().'/'.$ref.'-Bracket.fcstd');

		$this->assertSame($ref.'.step', $this->addFile($this->revision, $ref.'.step', 'step', 0)->filename, 'A name that starts with the number is kept');
		$this->assertSame(RevisionFile::ROLE_PRODUCTION, (int) $this->addFile($this->revision, 'part.gcode', 'G1', 0)->role);
		$this->assertSame(RevisionFile::ROLE_PRESENTATION, (int) $this->addFile($this->revision, 'notes.xyz', 'x', 0)->role, 'Unknown extension = presentation');
		$this->assertSame(RevisionFile::ROLE_SOURCE, (int) $this->addFile($this->revision, 'manual.pdf', 'x', RevisionFile::ROLE_SOURCE)->role, 'Explicit role kept');
	}

	public function test3mfSlicerProjectsAreProduction()
	{
		$add = function ($name, $entries) {
			$file = $this->revision->addFile($this->user, $this->tmpZip($entries), $name, 0, 0);
			$this->assertIsObject($file, (string) $this->revision->error);
			return (int) $file->role;
		};
		$this->assertSame(RevisionFile::ROLE_PRESENTATION, $add('plain.3mf', array('3D/3dmodel.model' => 'x')));
		$this->assertSame(RevisionFile::ROLE_PRODUCTION, $add('bambu.3mf', array('3D/3dmodel.model' => 'x', 'Metadata/project_settings.config' => '{}')));
		$this->assertSame(RevisionFile::ROLE_PRODUCTION, $add('sliced.gcode.3mf', array('Metadata/plate_1.gcode' => 'G1')));
	}

	public function testExecutablesAndOversizedFilesAreRefused()
	{
		$this->assertSame(-1, $this->revision->addFile($this->user, $this->tmpFile('<?php echo 1;'), 'evil.php', 0, 0));
		$this->setConst('DESIGNTRACE_MAX_UPLOAD_MB', '1');
		$this->assertSame(-1, $this->revision->addFile($this->user, $this->tmpFile(str_repeat('x', 1024 * 1024 + 1)), 'big.stl', 0, 0));
	}

	public function testUploadLimitNeverExceedsPhp()
	{
		$php = min(RevisionFile::iniSizeToBytes(ini_get('upload_max_filesize')), RevisionFile::iniSizeToBytes(ini_get('post_max_size')));
		$this->setConst('DESIGNTRACE_MAX_UPLOAD_MB', '100000');
		$this->assertLessThanOrEqual($php, RevisionFile::getMaxUploadBytes());
		$this->assertSame(1048576, RevisionFile::iniSizeToBytes('1M'));
		$this->assertSame(2147483648, RevisionFile::iniSizeToBytes('2G'));
	}

	public function testSameNameReplacesWhileUnlocked()
	{
		$this->addFile($this->revision, 'Bracket.FCStd', 'v1', 0);
		$f = $this->addFile($this->revision, 'Bracket.FCStd', 'v2', 0);
		$this->assertSame(hash('sha256', 'v2'), $f->sha256);
		$this->assertSame(1, $this->revision->countFiles());
	}

	public function testPrefixCanBeSwitchedOff()
	{
		$this->setConst('DESIGNTRACE_FILE_NO_PREFIX', '1');
		$f = $this->addFile($this->revision, 'loose.stl', 'x', 0);
		$this->assertSame('loose.stl', $f->filename);
		$this->assertCount(1, $this->revision->warnings);
	}

	public function testVerifyFindsChangedMissingAndUntrackedFiles()
	{
		$step = $this->addFile($this->revision, 'a.step', 'step', 0);
		$stl = $this->addFile($this->revision, 'b.stl', 'stl', 0);
		$check = $this->revision->verifyFiles();
		$this->assertSame(array('ok', 'ok'), array_values($check['files']));
		$this->assertSame(array(), $check['untracked']);

		$dir = $this->revision->getFilesDir();
		file_put_contents($dir.'/'.$step->filename, 'tampered');
		unlink($dir.'/'.$stl->filename);
		file_put_contents($dir.'/stray.txt', 's');
		$check = $this->revision->verifyFiles();
		$this->assertSame('changed', $check['files'][$step->id]);
		$this->assertSame('missing', $check['files'][$stl->id]);
		$this->assertSame(array('stray.txt'), $check['untracked']);
	}

	public function testChangingTheRevMovesTheFolder()
	{
		$f = $this->addFile($this->revision, 'Bracket.FCStd', 'x', 0);
		$old = $this->revision->getFilesDir();
		$this->revision->rev = 'A1';
		$this->assertOk($this->revision->update($this->user), $this->revision, 'Change rev');
		$this->assertDirectoryDoesNotExist($old);
		$this->assertFileExists($this->revision->getFilesDir().'/'.$f->filename);
	}

	public function testReleaseRequiresSourceWhenSet()
	{
		$this->setConst('DESIGNTRACE_RELEASE_REQUIRES_SOURCE', '1');
		$this->assertLessThan(0, $this->revision->release($this->user));
		$this->addFile($this->revision, 'Bracket.FCStd', 'x', 0);
		$this->revision->error = '';
		$this->revision->errors = array();
		$this->assertOk($this->revision->release($this->user), $this->revision, 'Release with source');
	}

	public function testReleasedFilesAreLockedAndForcedDeletesAreLogged()
	{
		$step = $this->addFile($this->revision, 'a.step', 'step', 0);
		$this->release($this->revision);
		$this->assertSame(-1, $this->revision->addFile($this->user, $this->tmpFile('x'), 'late.stl', 0, 0));
		$this->assertLessThan(0, $this->revision->deleteFile($this->user, $step));
		$this->assertLessThan(0, $this->revision->deleteFile($this->user, $step, true, ' '), 'A reason is required');
		$this->assertGreaterThan(0, $this->revision->deleteFile($this->user, $step, true, 'Wrong export'));
		$this->assertFileDoesNotExist($this->revision->getFilesDir().'/'.$step->filename);

		$res = $this->db->query("SELECT note FROM ".MAIN_DB_PREFIX."actioncomm WHERE code = 'AC_DESIGNTRACE_FILE_DELETE' AND fk_element = ".((int) $this->revision->id));
		$event = $this->db->fetch_object($res);
		$this->assertNotEmpty($event);
		$this->assertStringContainsString('Wrong export', $event->note);
		$this->assertStringContainsString($step->sha256, $event->note);
	}

	public function testNewRevisionStartsWithoutFilesAndDeletingADraftRemovesThem()
	{
		$this->addFile($this->revision, 'a.step', 'step', 0);
		$this->release($this->revision);
		$b = $this->revision->createFromClone($this->user, $this->revision->id);
		$this->assertSame(0, $b->countFiles());
		$this->assertSame((int) $this->revision->id, (int) $b->getPreviousRevision()->id);

		$this->addFile($b, 'tmp.stl', 'x', 0);
		$dir = $b->getFilesDir();
		$this->assertGreaterThan(0, $b->delete($this->user));
		$this->assertDirectoryDoesNotExist($dir);
		$this->assertSame(0, $this->countRows('SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'designtrace_revisionfile WHERE fk_revision = '.((int) $b->id)));
	}
}
