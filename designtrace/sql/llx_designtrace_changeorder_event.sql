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


-- Timeline of an ECO: one entry per round (opened, changes requested by the customer...) with who and why
CREATE TABLE llx_designtrace_changeorder_event(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_eco integer NOT NULL,
	round integer DEFAULT 1 NOT NULL,
	type varchar(32) NOT NULL,
	actor varchar(128),
	comment text,
	date_event datetime NOT NULL
) ENGINE=innodb;
