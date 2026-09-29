-- dvb module — master schema (current version)
-- Full CREATE for a fresh install. Must always reflect the LATEST schema
-- (every migrations/<semver>.sql delta folded in), mirroring core's
-- bin/install/database.sql.
-- Owns: dvb_adapters, dvb_transponders, dvb_services, dvb_jobs.
--
-- Design note: the panel almost never runs on the machine that holds the
-- tuner card (here the panel is on a VPS, the TBS6909X is on a box in the
-- rack). Load-balancer nodes point their MySQL at the main server
-- (Cli/Commands/LbInstallFlow.php writes hostname => main server_ip), so the
-- database IS the transport. The panel writes a row into `dvb_jobs`, the
-- module's cron on the tuner node picks it up, drives the hardware and writes
-- the answer back. No new internal-API action is needed — and there is no
-- module hook in InternalApiController to add one to anyway.

-- One row per DVB frontend found on a streaming node. Refreshed by the
-- `discover` job. Rows are never deleted automatically, so that a transponder
-- pinned to adapter 3 keeps its binding across a reboot that renumbers nothing.
CREATE TABLE IF NOT EXISTS `dvb_adapters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL DEFAULT 1,
  `adapter_num` int(11) NOT NULL DEFAULT 0,
  `frontend_num` int(11) NOT NULL DEFAULT 0,
  `name` varchar(190) COLLATE utf8_unicode_ci DEFAULT NULL,
  `delivery_systems` varchar(190) COLLATE utf8_unicode_ci DEFAULT NULL,
  `bus_info` varchar(190) COLLATE utf8_unicode_ci DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `in_use_by` int(11) DEFAULT NULL,
  `last_seen` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `node_frontend` (`server_id`,`adapter_num`,`frontend_num`),
  KEY `server_id` (`server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- One row per transponder/mux the operator defines. Frequencies are stored in
-- kHz for satellite (11778000 = 11778 MHz) and in Hz for terrestrial/cable,
-- which is what dvbv5 expects in each case.
--
-- `output_host` is where DVBlast fans the services out to. It defaults to
-- 127.0.0.1 on purpose: the panel streams created from this transponder are
-- pinned to the same node, so ffmpeg reads the multicast-free loopback and
-- nothing touches the network. Set it to a 239.x.y.z group only when another
-- machine has to receive the same services -- multicast across a cheap switch
-- is a well-known way to break both the switch and the stream.
CREATE TABLE IF NOT EXISTS `dvb_transponders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL DEFAULT 1,
  `adapter_id` int(11) DEFAULT NULL,
  `name` varchar(190) COLLATE utf8_unicode_ci DEFAULT NULL,
  `satellite` varchar(190) COLLATE utf8_unicode_ci DEFAULT NULL,
  `delivery_system` varchar(32) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'DVBS2',
  `frequency` bigint(20) NOT NULL DEFAULT 0,
  `polarization` varchar(1) COLLATE utf8_unicode_ci DEFAULT 'H',
  `symbol_rate` int(11) NOT NULL DEFAULT 27500000,
  `modulation` varchar(16) COLLATE utf8_unicode_ci DEFAULT 'QPSK',
  `inner_fec` varchar(8) COLLATE utf8_unicode_ci DEFAULT 'AUTO',
  `rolloff` varchar(8) COLLATE utf8_unicode_ci DEFAULT 'AUTO',
  `pilot` varchar(8) COLLATE utf8_unicode_ci DEFAULT 'AUTO',
  `bandwidth` int(11) DEFAULT 0,
  `isi` int(11) NOT NULL DEFAULT -1,
  `pls_mode` varchar(8) COLLATE utf8_unicode_ci DEFAULT 'ROOT',
  `pls_code` int(11) NOT NULL DEFAULT 0,
  `lnb_type` varchar(32) COLLATE utf8_unicode_ci DEFAULT 'UNIVERSAL',
  `lnb_low` int(11) NOT NULL DEFAULT 9750000,
  `lnb_high` int(11) NOT NULL DEFAULT 10600000,
  `lnb_switch` int(11) NOT NULL DEFAULT 11700000,
  `diseqc` int(11) NOT NULL DEFAULT 0,
  `scan_status` varchar(16) COLLATE utf8_unicode_ci DEFAULT 'never',
  `scan_message` text COLLATE utf8_unicode_ci,
  `last_scan` int(11) DEFAULT NULL,
  `signal_strength` int(11) DEFAULT NULL,
  `signal_quality` int(11) DEFAULT NULL,
  `output_host` varchar(64) COLLATE utf8_unicode_ci DEFAULT '127.0.0.1',
  `streaming` tinyint(1) NOT NULL DEFAULT 0,
  `stream_status` varchar(16) COLLATE utf8_unicode_ci DEFAULT 'stopped',
  `stream_message` text COLLATE utf8_unicode_ci,
  `stream_checked` int(11) DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `server_id` (`server_id`),
  KEY `adapter_id` (`adapter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- One row per service (channel) seen on a transponder.
--
-- Two ports, not one, when the service is encrypted. `output_port` is always
-- what the panel channel reads, so assigning or removing a CAMD never rewrites
-- `streams`.`stream_source`. For a free-to-air service DVBlast writes straight
-- to `output_port`. For an encrypted one DVBlast writes to `enc_port` and
-- tsdecrypt sits between the two. `stream_id` is the
-- panel `streams`.id once the operator imports it. NULL means "found but not
-- imported". Rows survive a rescan so the link is not lost — `last_seen` is
-- how you spot a service that has gone off the transponder.
CREATE TABLE IF NOT EXISTS `dvb_services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transponder_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL DEFAULT 0,
  `name` varchar(190) COLLATE utf8_unicode_ci DEFAULT NULL,
  `provider` varchar(190) COLLATE utf8_unicode_ci DEFAULT NULL,
  `service_type` int(11) NOT NULL DEFAULT 1,
  `pmt_pid` int(11) NOT NULL DEFAULT 0,
  `pcr_pid` int(11) NOT NULL DEFAULT 0,
  `video_pid` int(11) NOT NULL DEFAULT 0,
  `audio_pid` varchar(190) COLLATE utf8_unicode_ci DEFAULT NULL,
  `video_codec` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL,
  `audio_codec` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL,
  `encrypted` tinyint(1) NOT NULL DEFAULT 0,
  `stream_id` int(11) DEFAULT NULL,
  `output_ip` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `output_port` int(11) DEFAULT NULL,
  `camd_id` int(11) DEFAULT NULL,
  `enc_port` int(11) DEFAULT NULL,
  `decrypt_status` varchar(16) COLLATE utf8_unicode_ci DEFAULT 'off',
  `decrypt_message` text COLLATE utf8_unicode_ci,
  `first_seen` int(11) DEFAULT NULL,
  `last_seen` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transponder_service` (`transponder_id`,`service_id`),
  KEY `stream_id` (`stream_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- One row per CAMD server the tuner node may ask for control words.
--
-- This is a CLIENT credential set, not a server: tsdecrypt opens a TCP session
-- to someone else's card server, sends the ECMs it pulls out of the transport
-- stream, and gets code words back. NEWCAMD and CS378X (camd35 over tcp, what
-- OSCam usually speaks) are both available because tsdecrypt speaks both and
-- supporting the second one costs nothing.
--
-- `des_key` only applies to NEWCAMD. It is the 28 hex character key agreed with
-- the server, and getting it wrong looks exactly like a wrong password.
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

-- Work queue. The panel inserts, the tuner node's cron consumes. Kept
-- deliberately small and dumb: one row = one hardware operation.
CREATE TABLE IF NOT EXISTS `dvb_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL DEFAULT 1,
  `type` varchar(32) COLLATE utf8_unicode_ci NOT NULL,
  `ref_id` int(11) DEFAULT NULL,
  `payload` text COLLATE utf8_unicode_ci,
  `status` varchar(16) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'pending',
  `result` text COLLATE utf8_unicode_ci,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `created_at` int(11) DEFAULT NULL,
  `started_at` int(11) DEFAULT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dispatch` (`server_id`,`status`),
  KEY `ref_id` (`ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
