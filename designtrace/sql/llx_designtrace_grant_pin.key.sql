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

ALTER TABLE llx_designtrace_grant_pin ADD UNIQUE INDEX uk_designtrace_grant_pin (fk_grant, fk_component);
ALTER TABLE llx_designtrace_grant_pin ADD INDEX idx_designtrace_grant_pin_revision (fk_revision);
ALTER TABLE llx_designtrace_grant_pin ADD CONSTRAINT llx_designtrace_grant_pin_fk_grant FOREIGN KEY (fk_grant) REFERENCES llx_designtrace_grant(rowid);
ALTER TABLE llx_designtrace_grant_pin ADD CONSTRAINT llx_designtrace_grant_pin_fk_revision FOREIGN KEY (fk_revision) REFERENCES llx_designtrace_componentrevision(rowid);
