-- dvb module — teardown (single deletion file)
-- Drops every table the module owns. Runs on uninstall. Child rows go first so
-- the order stays valid if foreign keys are ever added.
--
-- Note this does NOT delete the panel channels created by importing services.
-- Those are ordinary rows in `streams` and survive the module being removed, by
-- design: uninstalling a tuner integration should not silently delete a
-- customer-facing lineup. They stop receiving data once DVBlast is gone.
DROP TABLE IF EXISTS `dvb_jobs`;
DROP TABLE IF EXISTS `dvb_services`;
DROP TABLE IF EXISTS `dvb_transponders`;
DROP TABLE IF EXISTS `dvb_adapters`;
DROP TABLE IF EXISTS `dvb_camd`;
