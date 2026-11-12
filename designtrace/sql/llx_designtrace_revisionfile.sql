-- Copyright (C) 2026		Kye McIntyre
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.

-- One row per file of a component revision. The file itself is in the revision's documents
-- directory (designtrace/componentrevision/<revision ref>/<filename>).
CREATE TABLE llx_designtrace_revisionfile(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_revision integer NOT NULL,
	filename varchar(255) NOT NULL,
	role integer NOT NULL,
	format varchar(16),
	filesize bigint,
	sha256 char(64) NOT NULL,
	visibility integer DEFAULT 0 NOT NULL,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer NOT NULL,
	fk_user_modif integer,
	import_key varchar(14)
) ENGINE=innodb;
