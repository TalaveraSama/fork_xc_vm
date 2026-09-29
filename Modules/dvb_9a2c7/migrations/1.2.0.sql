-- dvb module — version delta 1.2.0 (forward-only)
-- Adds newcamd/CS378X descrambling. Panels already running 1.1.0 have the four
-- scan and streaming tables but none of the conditional access ones, so this
-- file creates dvb_camd and widens dvb_services with the CAMD link, the
-- intermediate port and the decryption state.
--
-- Fresh installs get all of this from database.sql (master) and never run this
-- file. Idempotent, so re-running it is a no-op.
CREATE TABLE IF NOT EXISTS `dvb_camd` (
	`id` int(11) NOT NULL AUTO_INCREMENT,
	`name` varchar(190) COLLATE utf8_unicode_ci DEFAULT NULL,
	`protocol` varchar(16) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'NEWCAMD',
	`host` varchar(190) COLLATE utf8_unicode_ci DEFAULT NULL,
	`port` int(11) NOT NULL DEFAULT 2233,
	`username` varchar(190) COLLATE utf8_unicode_ci DEFAULT NULL,
	`password` varchar(190) COLLATE utf8_unicode_ci DEFAULT NULL,
	`des_key` varchar(64) COLLATE utf8_unicode_ci DEFAULT '0102030405060708091011121314',
	`ca_system` varchar(32) COLLATE utf8_unicode_ci DEFAULT 'CONAX',
	`caid` varchar(16) COLLATE utf8_unicode_ci DEFAULT NULL,
	`emm` tinyint(1) NOT NULL DEFAULT 0,
	`input_buffer` int(11) NOT NULL DEFAULT 0,
	`mute_on_error` tinyint(1) NOT NULL DEFAULT 1,
	`enabled` tinyint(1) NOT NULL DEFAULT 1,
	`notes` text COLLATE utf8_unicode_ci,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- enc_port is the port DVBlast writes to when the service is descrambled.
-- output_port keeps its meaning either way, so no streams row has to change.
ALTER TABLE `dvb_services`
	ADD COLUMN IF NOT EXISTS `camd_id` int(11) DEFAULT NULL AFTER `output_port`,
	ADD COLUMN IF NOT EXISTS `enc_port` int(11) DEFAULT NULL AFTER `camd_id`,
	ADD COLUMN IF NOT EXISTS `decrypt_status` varchar(16) COLLATE utf8_unicode_ci DEFAULT 'off' AFTER `enc_port`,
	ADD COLUMN IF NOT EXISTS `decrypt_message` text COLLATE utf8_unicode_ci AFTER `decrypt_status`;
