-- dvb module — version delta 1.6.0 (forward-only)
-- Adds an opt-in NIT sweep to transponders.
--
-- dvbv5-scan reads the Network Information Table and adds every other carrier
-- the broadcaster announces to its own work queue. The module has passed
-- -F/--file-freqs-only to suppress that, on the grounds that walking a whole
-- satellite outlasts SCAN_TIMEOUT and leaves no output file. True, and it
-- also threw away a great deal: run by hand, one sweep of a single carrier
-- returned six transponders and 196 services.
--
-- So it becomes a per-transponder choice. Zero keeps the fast behaviour.
--
-- Fresh installs get this from database.sql (master) and never run this file.
-- Idempotent, so re-running it is a no-op.
ALTER TABLE `dvb_transponders`
	ADD COLUMN IF NOT EXISTS `scan_nit` tinyint(1) NOT NULL DEFAULT 0 AFTER `diseqc`;
