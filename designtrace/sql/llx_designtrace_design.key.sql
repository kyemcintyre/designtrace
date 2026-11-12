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
ALTER TABLE llx_designtrace_design ADD INDEX idx_designtrace_design_rowid (rowid);
ALTER TABLE llx_designtrace_design ADD INDEX idx_designtrace_design_entity (entity);
ALTER TABLE llx_designtrace_design ADD UNIQUE INDEX uk_designtrace_design_ref (ref, entity);
ALTER TABLE llx_designtrace_design ADD INDEX idx_designtrace_design_fk_soc (fk_soc);
ALTER TABLE llx_designtrace_design ADD INDEX idx_designtrace_design_fk_project (fk_project);
ALTER TABLE llx_designtrace_design ADD INDEX idx_designtrace_design_fk_user_designer (fk_user_designer);
ALTER TABLE llx_designtrace_design ADD INDEX idx_designtrace_design_fk_licence (fk_licence);
ALTER TABLE llx_designtrace_design ADD INDEX idx_designtrace_design_status (status);
-- END MODULEBUILDER INDEXES

--ALTER TABLE llx_designtrace_design ADD UNIQUE INDEX uk_designtrace_design_fieldxy(fieldx, fieldy);

--ALTER TABLE llx_designtrace_design ADD CONSTRAINT llx_designtrace_design_fk_field FOREIGN KEY (fk_field) REFERENCES llx_designtrace_myotherobject(rowid);
