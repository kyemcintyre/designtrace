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

ALTER TABLE llx_designtrace_revisionfile ADD UNIQUE INDEX uk_designtrace_revisionfile_name (fk_revision, filename);
ALTER TABLE llx_designtrace_revisionfile ADD INDEX idx_designtrace_revisionfile_role (role);
ALTER TABLE llx_designtrace_revisionfile ADD INDEX idx_designtrace_revisionfile_sha256 (sha256);
ALTER TABLE llx_designtrace_revisionfile ADD CONSTRAINT llx_designtrace_revisionfile_fk_revision FOREIGN KEY (fk_revision) REFERENCES llx_designtrace_componentrevision(rowid);
