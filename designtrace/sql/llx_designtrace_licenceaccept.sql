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


-- Licence acceptances: who accepted which exact version, for which design and document, when.
-- No foreign keys: the record outlives deleted documents.
CREATE TABLE llx_designtrace_licenceaccept(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_licenceversion integer NOT NULL,
	fk_licence integer NOT NULL,
	fk_design integer NOT NULL,
	element varchar(64) NOT NULL,
	fk_element integer NOT NULL,
	fk_soc integer NOT NULL,
	channel varchar(16) NOT NULL,
	actor varchar(128),
	ip varchar(250),
	useragent varchar(255),
	wording_sha256 char(64),
	filename varchar(255),
	note varchar(255),
	date_accept datetime NOT NULL
) ENGINE=innodb;
