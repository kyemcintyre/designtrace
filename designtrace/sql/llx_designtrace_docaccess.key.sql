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


ALTER TABLE llx_designtrace_docaccess ADD UNIQUE INDEX uk_designtrace_docaccess (fk_revision, element, fk_element);
ALTER TABLE llx_designtrace_docaccess ADD INDEX idx_designtrace_docaccess_element (element, fk_element);
ALTER TABLE llx_designtrace_docaccess ADD CONSTRAINT llx_designtrace_docaccess_fk_revision FOREIGN KEY (fk_revision) REFERENCES llx_designtrace_componentrevision(rowid);
