-- flussonic module — master schema (current version)
-- Full CREATE for a fresh install. Must always reflect the LATEST schema
-- (every migrations/<semver>.sql delta folded in), mirroring core's
-- bin/install/database.sql. Owns: flussonic_servers, flussonic_streams.

-- One row per external Flussonic Media Server the panel pulls from.
-- NOTE: this is NOT an XC_VM streaming node (`servers`). The link to the node
-- that should run the imported channels is `target_server_id`.
CREATE TABLE IF NOT EXISTS `flussonic_servers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(128) COLLATE utf8_unicode_ci DEFAULT NULL,
  `api_scheme` varchar(8) COLLATE utf8_unicode_ci DEFAULT 'http',
  `api_host` varchar(190) COLLATE utf8_unicode_ci DEFAULT NULL,
  `api_port` int(5) DEFAULT 8080,
  `api_username` varchar(128) COLLATE utf8_unicode_ci DEFAULT NULL,
  `api_password` varchar(256) COLLATE utf8_unicode_ci DEFAULT NULL,
  `bearer_token` varchar(512) COLLATE utf8_unicode_ci DEFAULT NULL,
  `verify_tls` tinyint(1) DEFAULT 1,
  `play_scheme` varchar(8) COLLATE utf8_unicode_ci DEFAULT NULL,
  `play_host` varchar(190) COLLATE utf8_unicode_ci DEFAULT NULL,
  `play_port` int(5) DEFAULT 0,
  `rtmp_port` int(5) DEFAULT 1935,
  `rtsp_port` int(5) DEFAULT 554,
  `protocol` varchar(16) COLLATE utf8_unicode_ci DEFAULT 'hls',
  `play_token` varchar(256) COLLATE utf8_unicode_ci DEFAULT NULL,
  `enabled` tinyint(1) DEFAULT 1,
  `auto_import` tinyint(1) DEFAULT 0,
  `auto_remove` tinyint(1) DEFAULT 1,
  `direct_source` tinyint(1) DEFAULT 0,
  `sync_interval` int(11) DEFAULT 300,
  `target_server_id` int(11) DEFAULT 0,
  `category_id` int(11) DEFAULT 0,
  `bouquets` varchar(4096) COLLATE utf8_unicode_ci DEFAULT '[]',
  `stream_prefix` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `status` tinyint(1) DEFAULT 0,
  `last_sync` int(11) DEFAULT 0,
  `last_error` varchar(512) COLLATE utf8_unicode_ci DEFAULT NULL,
  `server_info` mediumtext COLLATE utf8_unicode_ci DEFAULT NULL,
  `streams_found` int(11) DEFAULT 0,
  `added` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `endpoint` (`api_host`,`api_port`),
  KEY `enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- Catalogue of the streams discovered on those servers. `stream_id` links a
-- discovered stream to the panel channel it produced (0 = not imported yet),
-- which is what keeps re-syncs idempotent.
CREATE TABLE IF NOT EXISTS `flussonic_streams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) DEFAULT 0,
  `name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `title` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `alive` tinyint(1) DEFAULT 0,
  `bitrate` int(11) DEFAULT 0,
  `clients` int(11) DEFAULT 0,
  `input_url` varchar(1024) COLLATE utf8_unicode_ci DEFAULT NULL,
  `video_codec` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL,
  `audio_codec` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL,
  `resolution` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL,
  `dvr_depth` int(11) DEFAULT 0,
  `logo` varchar(1024) COLLATE utf8_unicode_ci DEFAULT NULL,
  `play_url` varchar(1024) COLLATE utf8_unicode_ci DEFAULT NULL,
  `stream_id` int(11) DEFAULT 0,
  `first_seen` int(11) DEFAULT 0,
  `last_seen` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `server_stream` (`server_id`,`name`(180)),
  KEY `stream_id` (`stream_id`),
  KEY `alive` (`alive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
