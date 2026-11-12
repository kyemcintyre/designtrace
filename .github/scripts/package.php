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
 * Build the Dolibarr module package <outdir>/module_designtrace-<version>.zip, holding the
 * designtrace/ folder as Dolibarr's "Deploy external module" expects.
 *
 *     php .github/scripts/package.php <x.y.z> [outdir] [release-notes.md]
 *
 * The version is never committed: the repo's descriptor says 'development', and only the copy in
 * the zip gets $this->version = '<x.y.z>'. With release notes, the zip's ChangeLog.md starts with
 * them (above the earlier entries). Only files tracked by git are packaged (working-tree content), minus development files.
 * Run by semantic-release, which attaches the zip to the GitHub release of tag v<x.y.z>.
 */

$root = dirname(__DIR__, 2);
$version = $argv[1] ?? '';
$outdir = $argv[2] ?? 'dist';
$notesFile = $argv[3] ?? '';
if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version)) {
	fwrite(STDERR, "Usage: php package.php <x.y.z> [outdir] [release-notes.md]\n");
	exit(1);
}
if ($outdir[0] !== '/') {
	$outdir = $root.'/'.$outdir;
}

// Development files that stay out of the package (paths inside designtrace/)
$exclude = array(
	'#^test/#',
	'#^build/#',
	'#^\.tx/#',
	'#^AGENTS\.md$#',
	'#^modulebuilder\.txt$#',
	'#\.back$#',
	'#(^|/)\.gitkeep$#',
);

// Files whose content changes in the package
$descriptorPath = 'designtrace/core/modules/modDesignTrace.class.php';
$descriptor = (string) file_get_contents($root.'/'.$descriptorPath);
$pattern = "/(\\\$this->version\\s*=\\s*)'[^']*'(\\s*;)/";
if (preg_match_all($pattern, $descriptor) !== 1) {
	fwrite(STDERR, "Expected exactly one \$this->version = '...'; in ".$descriptorPath."\n");
	exit(1);
}
$override = array($descriptorPath => preg_replace($pattern, "\${1}'".$version."'\${2}", $descriptor));

if ($notesFile !== '') {
	$notes = trim((string) file_get_contents($notesFile));
	$changelog = (string) file_get_contents($root.'/designtrace/ChangeLog.md');
	// The notes go above the first release heading, under the title and intro
	$pos = strpos($changelog, "\n## ");
	$override['designtrace/ChangeLog.md'] = ($pos === false ? rtrim($changelog)."\n\n".$notes."\n"
		: substr($changelog, 0, $pos + 1).$notes."\n\n".substr($changelog, $pos + 1));
}

exec('git -C '.escapeshellarg($root).' ls-files -z -- designtrace', $out, $rc);
if ($rc !== 0) {
	fwrite(STDERR, "git ls-files failed\n");
	exit(1);
}
$files = array_filter(explode("\0", implode("\n", $out)), 'strlen');

if (!is_dir($outdir) && !mkdir($outdir, 0775, true)) {
	fwrite(STDERR, "Cannot create ".$outdir."\n");
	exit(1);
}
$zipfile = $outdir.'/module_designtrace-'.$version.'.zip';
@unlink($zipfile);
$zip = new ZipArchive();
if ($zip->open($zipfile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
	fwrite(STDERR, "Cannot create ".$zipfile."\n");
	exit(1);
}
$count = 0;
foreach ($files as $path) {
	$inner = substr($path, strlen('designtrace/'));
	foreach ($exclude as $re) {
		if (preg_match($re, $inner)) {
			continue 2;
		}
	}
	if (isset($override[$path])) {
		$zip->addFromString($path, $override[$path]);
	} elseif (is_file($root.'/'.$path)) {
		$zip->addFile($root.'/'.$path, $path);
	} else {
		continue;	// Deleted in the working tree
	}
	$count++;
}
if (!$zip->close()) {
	fwrite(STDERR, "Could not write ".$zipfile."\n");
	exit(1);
}
echo "Packaged ".$count." files (version ".$version.") into ".$zipfile."\n";
