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



-- Customer decisions on proof revisions: approve or request changes, with the customer's comment
CREATE TABLE llx_designtrace_approval(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_revision integer NOT NULL,
	decision smallint NOT NULL,				-- 1 approved, 2 changes requested
	comment text,
	element varchar(32) NOT NULL,			-- document the proof was shown through (propal, commande, ticket)
	fk_element integer NOT NULL,
	fk_soc integer NOT NULL,
	channel varchar(16) NOT NULL,			-- webportal, api
	actor varchar(128),
	ip varchar(250),
	useragent varchar(255),
	fk_ticket integer,						-- ticket opened for a change request
	fk_eco integer,							-- or draft ECO
	date_decision datetime NOT NULL
) ENGINE=innodb;
