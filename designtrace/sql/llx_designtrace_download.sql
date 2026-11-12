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


-- One row per file downloaded by a customer (Web portal or REST API signed link).
-- No foreign keys: the log outlives deleted files and revisions.
CREATE TABLE llx_designtrace_download(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_revisionfile integer NOT NULL,
	fk_revision integer NOT NULL,
	filename varchar(255) NOT NULL,
	sha256 char(64),
	fk_soc integer NOT NULL,
	element varchar(64),
	fk_element integer,
	channel varchar(16) NOT NULL,
	actor varchar(128),
	ip varchar(250),
	useragent varchar(255),
	date_download datetime NOT NULL
) ENGINE=innodb;
