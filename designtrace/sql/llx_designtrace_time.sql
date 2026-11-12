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



-- CAD time: which design, component or ECO a Dolibarr time spent line (llx_element_time) was for
CREATE TABLE llx_designtrace_time(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_element_time integer NOT NULL,		-- llx_element_time (the duration, date, user and note live there)
	element varchar(32) NOT NULL,			-- design, component, changeorder
	fk_element integer NOT NULL,
	fk_design integer,						-- design it rolls up to (none for an ECO)
	datec datetime NOT NULL,
	fk_user_creat integer NOT NULL
) ENGINE=innodb;
