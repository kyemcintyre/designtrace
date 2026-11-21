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

ALTER TABLE llx_designtrace_changeorder ADD COLUMN round integer DEFAULT 1 NOT NULL;
ALTER TABLE llx_designtrace_changeorder ADD COLUMN shareable smallint DEFAULT 0 NOT NULL;
ALTER TABLE llx_designtrace_changeorder ADD COLUMN request_type smallint DEFAULT 0 NOT NULL;
ALTER TABLE llx_designtrace_changeorder ADD COLUMN requested_by varchar(128);
ALTER TABLE llx_designtrace_changeorder ADD COLUMN fk_revision_source integer;
ALTER TABLE llx_designtrace_changeorder ADD COLUMN fk_ticket integer;
ALTER TABLE llx_designtrace_changeorder ADD COLUMN fk_order_source integer;
ALTER TABLE llx_designtrace_changeorder ADD COLUMN fk_propal integer;
ALTER TABLE llx_designtrace_changeorder ADD COLUMN date_closed datetime;

-- Revision credits: size asked for and decided, the outcome, the design whose allowance is used, the rate shown and acknowledged
ALTER TABLE llx_designtrace_changeorder ADD COLUMN credit_size_requested smallint DEFAULT 0 NOT NULL;
ALTER TABLE llx_designtrace_changeorder ADD COLUMN credit_size smallint DEFAULT 0 NOT NULL;
ALTER TABLE llx_designtrace_changeorder ADD COLUMN credit_outcome varchar(8) DEFAULT 'none' NOT NULL;
ALTER TABLE llx_designtrace_changeorder ADD COLUMN fk_credit_design integer;
ALTER TABLE llx_designtrace_changeorder ADD COLUMN credit_rate_ack_date datetime;
ALTER TABLE llx_designtrace_changeorder ADD COLUMN credit_rate_label varchar(64);
ALTER TABLE llx_designtrace_changeorder ADD COLUMN credit_rate_amount double(24,8);
