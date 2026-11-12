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



-- Portfolio consent of designs: every answer, newest = current (also on the design)
CREATE TABLE llx_designtrace_consent(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_design integer NOT NULL,
	consent smallint NOT NULL,				-- 1 granted, 2 refused
	element varchar(32),					-- document it was given on (propal, commande)
	fk_element integer DEFAULT 0 NOT NULL,
	channel varchar(16) NOT NULL,			-- backoffice, webportal, api, licence
	actor varchar(128),
	fk_user integer,						-- staff user who recorded it
	note varchar(255),
	ip varchar(250),
	date_consent datetime NOT NULL
) ENGINE=innodb;
