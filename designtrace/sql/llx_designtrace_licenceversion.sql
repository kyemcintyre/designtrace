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



-- Versions of a licence template: drafts are editable, the current one and superseded ones are
-- read-only so acceptances keep the exact wording agreed to
CREATE TABLE llx_designtrace_licenceversion(
	-- BEGIN MODULEBUILDER FIELDS
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	ref varchar(64) NOT NULL,
	fk_licence integer NOT NULL,
	version integer NOT NULL,
	wording text,
	perm_personal smallint DEFAULT 1 NOT NULL,
	perm_commercial smallint DEFAULT 0 NOT NULL,
	perm_resell smallint DEFAULT 0 NOT NULL,
	perm_modify smallint DEFAULT 0 NOT NULL,
	perm_source smallint DEFAULT 0 NOT NULL,
	perm_exclusive smallint DEFAULT 0 NOT NULL,
	perm_attribution smallint DEFAULT 0 NOT NULL,
	perm_portfolio smallint DEFAULT 0 NOT NULL,
	change_note varchar(255),
	date_activation datetime,
	fk_user_activation integer,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer NOT NULL,
	fk_user_modif integer,
	import_key varchar(14),
	status integer DEFAULT 0 NOT NULL
	-- END MODULEBUILDER FIELDS
) ENGINE=innodb;
