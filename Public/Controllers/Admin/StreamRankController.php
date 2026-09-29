<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Http\RequestManager;

/**
 * StreamRankController — рейтинг стримов.
 *
 * @renders Views/admin/stream_rank.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StreamRankController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $db;

        $rStreamTypes = array(1 => 'Live Stream', 2 => 'Movie', 3 => 'Created Channel', 4 => 'Radio Station', 5 => 'Episode');
        // get() with a default, not getAll()['period']: the page is reachable
        // from the menu with no query string, and `?:` still evaluates its left
        // operand, so the missing key warns before the fallback is applied.
        // Whitelisted because the value selects a row set in `streams_stats`
        // and drives which <option> the view marks selected.
        $rPeriod = (string) RequestManager::get('period', 'all');
        if (!in_array($rPeriod, array('today', 'week', 'month', 'all'), true)) {
            $rPeriod = 'all';
        }
        $db->query('SELECT `streams_stats`.*, `streams`.`stream_display_name` FROM `streams_stats` INNER JOIN `streams` ON `streams`.`id` = `streams_stats`.`stream_id` WHERE `streams_stats`.`type` = ? AND `streams`.`type` IN (1,3) GROUP BY `stream_id` ORDER BY `streams_stats`.`rank` ASC LIMIT 500;', $rPeriod);
        $rRows = $db->get_rows();

        $this->setTitle('Stream Rank');
        $this->render('stream_rank', compact('rStreamTypes', 'rPeriod', 'rRows'));
    }
}
