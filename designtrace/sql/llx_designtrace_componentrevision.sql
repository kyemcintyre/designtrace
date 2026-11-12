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


CREATE TABLE llx_designtrace_componentrevision(
	-- BEGIN MODULEBUILDER FIELDS
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL, 
	ref varchar(128) NOT NULL, 
	entity integer DEFAULT 1 NOT NULL, 
	fk_component integer NOT NULL, 
	rev varchar(16) NOT NULL, 
	change_reason integer, 
	fk_eco integer, 
	description text, 
	material varchar(255), 
	process varchar(255), 
	machine varchar(255), 
	layer_height double(24,8), 
	nozzle_size double(24,8), 
	infill integer, 
	print_time integer, 
	filament_used double(24,8), 
	date_release datetime, 
	fk_user_release integer, 
	note_public text, 
	note_private text, 
	date_creation datetime NOT NULL, 
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, 
	fk_user_creat integer NOT NULL, 
	fk_user_modif integer, 
	last_main_doc varchar(255), 
	import_key varchar(14), 
	model_pdf varchar(255), 
	status integer DEFAULT 0 NOT NULL
	-- END MODULEBUILDER FIELDS
) ENGINE=innodb;
