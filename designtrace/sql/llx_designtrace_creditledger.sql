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

-- Revision credits ledger: one row per movement, never edited. amount is signed whole credits (waive and
-- overage rows are 0: they record a decision, not a movement). note_public is shown to the customer,
-- note_private never leaves the staff API.
CREATE TABLE llx_designtrace_creditledger(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_design integer NOT NULL,
	fk_soc integer NOT NULL,
	type varchar(16) NOT NULL,
	amount integer DEFAULT 0 NOT NULL,
	fk_grant integer,
	fk_eco integer,
	note_public varchar(255),
	note_private varchar(255),
	actor varchar(128),
	date_creation datetime NOT NULL
) ENGINE=innodb;
