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


ALTER TABLE llx_designtrace_licenceaccept ADD COLUMN date_revoked datetime;
ALTER TABLE llx_designtrace_licenceaccept DROP INDEX uk_designtrace_licenceaccept;
ALTER TABLE llx_designtrace_licenceaccept DROP INDEX uk_designtrace_licenceaccept_soc;
ALTER TABLE llx_designtrace_licenceaccept DROP INDEX uk_designtrace_licenceaccept_rev;
-- Not unique: ended acceptances are kept, so the same version can be accepted again (several rows per key);
-- "already accepted" is checked by the code against active rows only
ALTER TABLE llx_designtrace_licenceaccept ADD INDEX idx_designtrace_licenceaccept_soc_design (fk_soc, fk_design);
ALTER TABLE llx_designtrace_licenceaccept ADD INDEX idx_designtrace_licenceaccept_soc (fk_soc);
ALTER TABLE llx_designtrace_licenceaccept ADD INDEX idx_designtrace_licenceaccept_version (fk_licenceversion);
