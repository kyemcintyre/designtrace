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

-- Design-level access grants: a customer gets a whole design or single components without a quote,
-- order or ticket. Ended grants (revoked or expired) are kept: the download log and licence record
-- refer to them. The pinned revisions are in llx_designtrace_grant_pin.
CREATE TABLE llx_designtrace_grant(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_soc integer NOT NULL,
	fk_design integer NOT NULL,
	whole_design smallint DEFAULT 0 NOT NULL,
	date_expiry datetime,
	date_revoked datetime,
	fk_user_revoke integer,
	note varchar(255),
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer NOT NULL
) ENGINE=innodb;
