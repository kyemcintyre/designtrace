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



ALTER TABLE llx_designtrace_changeorder ADD INDEX idx_designtrace_changeorder_rowid (rowid);
ALTER TABLE llx_designtrace_changeorder ADD INDEX idx_designtrace_changeorder_entity (entity);
ALTER TABLE llx_designtrace_changeorder ADD UNIQUE INDEX uk_designtrace_changeorder_ref (ref, entity);
ALTER TABLE llx_designtrace_changeorder ADD INDEX idx_designtrace_changeorder_reason (reason);
ALTER TABLE llx_designtrace_changeorder ADD INDEX idx_designtrace_changeorder_fk_soc (fk_soc);
ALTER TABLE llx_designtrace_changeorder ADD INDEX idx_designtrace_changeorder_fk_project (fk_project);
ALTER TABLE llx_designtrace_changeorder ADD INDEX idx_designtrace_changeorder_fk_user_requester (fk_user_requester);
ALTER TABLE llx_designtrace_changeorder ADD INDEX idx_designtrace_changeorder_status (status);
