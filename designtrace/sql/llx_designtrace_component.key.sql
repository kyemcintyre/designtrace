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
ALTER TABLE llx_designtrace_component ADD INDEX idx_designtrace_component_rowid (rowid);
ALTER TABLE llx_designtrace_component ADD INDEX idx_designtrace_component_entity (entity);
ALTER TABLE llx_designtrace_component ADD UNIQUE INDEX uk_designtrace_component_ref (ref, entity);
ALTER TABLE llx_designtrace_component ADD INDEX idx_designtrace_component_fk_design (fk_design);
ALTER TABLE llx_designtrace_component ADD INDEX idx_designtrace_component_type (type);
ALTER TABLE llx_designtrace_component ADD INDEX idx_designtrace_component_fk_product (fk_product);
ALTER TABLE llx_designtrace_component ADD INDEX idx_designtrace_component_status (status);
-- END MODULEBUILDER INDEXES

--ALTER TABLE llx_designtrace_component ADD UNIQUE INDEX uk_designtrace_component_fieldxy(fieldx, fieldy);

ALTER TABLE llx_designtrace_component ADD CONSTRAINT llx_designtrace_component_fk_design FOREIGN KEY (fk_design) REFERENCES llx_designtrace_design(rowid);
