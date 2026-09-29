-- dvb module — version delta 1.3.0 (forward-only)
-- Adds a session cap to CAMD profiles. Descrambling is per service, so one
-- encrypted channel is one NEWCAMD login. A transponder with more encrypted
-- channels than the account allows has the surplus rejected by the server,
-- and a rejected tsdecrypt reconnects for ever, which looks like a flood.
--
-- Zero means no cap, which is the previous behaviour, so an upgraded panel
-- keeps working exactly as before until somebody fills the field in.
--
-- Fresh installs get this from database.sql (master) and never run this file.
-- Idempotent, so re-running it is a no-op.
ALTER TABLE `dvb_camd`
	ADD COLUMN IF NOT EXISTS `max_connections` int(11) NOT NULL DEFAULT 0 AFTER `input_buffer`;
