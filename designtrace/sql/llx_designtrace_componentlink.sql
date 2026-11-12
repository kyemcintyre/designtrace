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

-- Link from a parent revision to the exact child revision it uses, depicts or references.
-- The flag columns are maintained by ComponentLink::recomputeFlags().
CREATE TABLE llx_designtrace_componentlink(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_parent integer NOT NULL,
	fk_child integer NOT NULL,
	link_type integer NOT NULL,
	qty double(24,8),
	note varchar(255),
	flag_status integer DEFAULT 0 NOT NULL,
	flag_note varchar(255),
	fk_ack_revision integer,
	ack_note text,
	fk_user_ack integer,
	date_ack datetime,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer NOT NULL,
	fk_user_modif integer,
	import_key varchar(14)
) ENGINE=innodb;
