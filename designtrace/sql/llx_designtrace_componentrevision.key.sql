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


-- BEGIN MODULEBUILDER INDEXES
ALTER TABLE llx_designtrace_componentrevision ADD INDEX idx_designtrace_componentrevision_rowid (rowid);
ALTER TABLE llx_designtrace_componentrevision ADD INDEX idx_designtrace_componentrevision_entity (entity);
ALTER TABLE llx_designtrace_componentrevision ADD UNIQUE INDEX uk_designtrace_componentrevision_ref (ref, entity);
ALTER TABLE llx_designtrace_componentrevision ADD UNIQUE INDEX uk_designtrace_componentrevision_rev (fk_component, rev);
ALTER TABLE llx_designtrace_componentrevision ADD INDEX idx_designtrace_componentrevision_fk_component (fk_component);
ALTER TABLE llx_designtrace_componentrevision ADD INDEX idx_designtrace_componentrevision_date_release (date_release);
ALTER TABLE llx_designtrace_componentrevision ADD INDEX idx_designtrace_componentrevision_status (status);
-- END MODULEBUILDER INDEXES

--ALTER TABLE llx_designtrace_componentrevision ADD UNIQUE INDEX uk_designtrace_componentrevision_fieldxy(fieldx, fieldy);

ALTER TABLE llx_designtrace_componentrevision ADD CONSTRAINT llx_designtrace_componentrevision_fk_component FOREIGN KEY (fk_component) REFERENCES llx_designtrace_component(rowid);
