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

ALTER TABLE llx_designtrace_componentlink ADD UNIQUE INDEX uk_designtrace_componentlink (fk_parent, fk_child);
ALTER TABLE llx_designtrace_componentlink ADD INDEX idx_designtrace_componentlink_fk_child (fk_child);
ALTER TABLE llx_designtrace_componentlink ADD INDEX idx_designtrace_componentlink_flag_status (flag_status);
ALTER TABLE llx_designtrace_componentlink ADD CONSTRAINT llx_designtrace_componentlink_fk_parent FOREIGN KEY (fk_parent) REFERENCES llx_designtrace_componentrevision(rowid);
ALTER TABLE llx_designtrace_componentlink ADD CONSTRAINT llx_designtrace_componentlink_fk_child FOREIGN KEY (fk_child) REFERENCES llx_designtrace_componentrevision(rowid);
