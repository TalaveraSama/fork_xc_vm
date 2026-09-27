-- flussonic module — teardown (single deletion file)
-- Drops every table the module owns. Runs on uninstall. Channels already
-- imported into `streams` are deliberately NOT touched: by then they are
-- ordinary panel streams that subscribers may be watching.
DROP TABLE IF EXISTS `flussonic_streams`;
DROP TABLE IF EXISTS `flussonic_servers`;
