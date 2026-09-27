INSERT INTO `crontab` (`filename`, `time`, `enabled`) SELECT 'proxy', '0 5 * * *', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `crontab` WHERE `filename` = 'proxy');
