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

-- Revision of a component a grant is pinned to. Moved only by hand, never when a newer revision is released.
CREATE TABLE llx_designtrace_grant_pin(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_grant integer NOT NULL,
	fk_component integer NOT NULL,
	fk_revision integer NOT NULL
) ENGINE=innodb;
