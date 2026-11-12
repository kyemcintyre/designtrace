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

ALTER TABLE llx_designtrace_time ADD UNIQUE INDEX uk_designtrace_time_fk_element_time (fk_element_time);
ALTER TABLE llx_designtrace_time ADD INDEX idx_designtrace_time_element (element, fk_element);
ALTER TABLE llx_designtrace_time ADD INDEX idx_designtrace_time_fk_design (fk_design);
