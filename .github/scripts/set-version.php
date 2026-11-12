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
 * Write the release version into the module descriptor ($this->version), which is what Dolibarr
 * shows on the module page and compares on upgrade. Run by semantic-release before packaging:
 *
 *     php .github/scripts/set-version.php 1.2.3
 */

$version = $argv[1] ?? '';
if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version)) {
	fwrite(STDERR, "Usage: php set-version.php <x.y.z>\n");
	exit(1);
}

$file = dirname(__DIR__, 2).'/designtrace/core/modules/modDesignTrace.class.php';
$source = file_get_contents($file);
$pattern = "/(\\\$this->version\\s*=\\s*)'[^']*'(\\s*;)/";
if ($source === false || preg_match_all($pattern, $source) !== 1) {
	fwrite(STDERR, "Expected exactly one \$this->version = '...'; in ".$file."\n");
	exit(1);
}
$updated = preg_replace($pattern, "\${1}'".$version."'\${2}", $source);
if (file_put_contents($file, $updated) === false) {
	fwrite(STDERR, "Could not write ".$file."\n");
	exit(1);
}
echo "Module version set to ".$version."\n";
