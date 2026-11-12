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
 * Build the Dolibarr module package: <outdir>/module_designtrace-<version>.zip, holding the
 * designtrace/ folder as Dolibarr's "Deploy external module" expects. The version is read from the
 * module descriptor (set by set-version.php). Only files tracked by git are packaged, with their
 * working-tree content, minus development files (tests, Module Builder and agent files).
 *
 *     php .github/scripts/package.php [outdir]       (default: dist)
 */

$root = dirname(__DIR__, 2);
$outdir = $argv[1] ?? 'dist';
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

$descriptor = file_get_contents($root.'/designtrace/core/modules/modDesignTrace.class.php');
if (!preg_match("/\\\$this->version\\s*=\\s*'(\\d+\\.\\d+\\.\\d+[^']*)'\\s*;/", (string) $descriptor, $m)) {
	fwrite(STDERR, "No x.y.z version in the module descriptor\n");
	exit(1);
}
$version = $m[1];

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
	if (!is_file($root.'/'.$path)) {
		continue;	// Deleted in the working tree
	}
	$zip->addFile($root.'/'.$path, $path);
	$count++;
}
if (!$zip->close()) {
	fwrite(STDERR, "Could not write ".$zipfile."\n");
	exit(1);
}
echo "Packaged ".$count." files into ".$zipfile."\n";
