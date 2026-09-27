<?php

/**
 * Placeholder page for the dev sandbox.
 *
 * Core admin pages are not booted here (they need the full panel), so any link
 * that leaves the module lands on this page instead of a 404. It still renders
 * through the real admin layout, which keeps the navbar usable.
 *
 * @package XC_VM_Module_Flussonic_Dev
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

require_once MAIN_HOME . 'Public/Views/layouts/admin.php';
require_once MAIN_HOME . 'Public/Views/layouts/footer.php';

$_TITLE = 'Not in this sandbox';
renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);

$rRequested = defined('PAGE_NAME') ? PAGE_NAME : '';
$rStreamID = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$rStream = null;

if ($rRequested === 'stream' && $rStreamID > 0) {
	$db = $GLOBALS['db'];
	$db->query('SELECT * FROM `streams` WHERE `id` = ? LIMIT 1;', $rStreamID);
	$rStream = $db->num_rows() > 0 ? $db->get_row() : null;
}
?>
<div class="wrapper">
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<h4 class="page-title"><?php echo htmlspecialchars($rRequested); ?></h4>
				</div>
			</div>
		</div>
		<?php if ($rStream !== null): ?>
			<div class="row">
				<div class="col-lg-8">
					<div class="card">
						<div class="card-body">
							<h4 class="header-title mb-3">Imported channel #<?php echo (int) $rStream['id']; ?></h4>
							<p class="text-muted font-13">
								This row was created in the panel's own <code>streams</code> table by the Flussonic
								import — exactly what the production panel serves to bouquets, playlists and the
								Xtream API.
							</p>
							<table class="table table-sm table-borderless mb-0">
								<tbody>
									<?php foreach (['stream_display_name' => 'Name', 'stream_source' => 'Source', 'category_id' => 'Categories', 'type' => 'Type', 'order' => 'Order', 'tv_archive_duration' => 'Archive (days)', 'direct_source' => 'Direct source', 'notes' => 'Notes'] as $rColumn => $rLabel): ?>
										<tr>
											<th style="width:180px;"><?php echo $rLabel; ?></th>
											<td><code><?php echo htmlspecialchars((string) ($rStream[$rColumn] ?? '')); ?></code></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>
				</div>
			</div>
		<?php else: ?>
			<div class="row">
				<div class="col-lg-8">
					<div class="card">
						<div class="card-body">
							<p class="mb-2">
								<strong><?php echo htmlspecialchars($rRequested); ?></strong> is a core panel page and is
								not part of this preview — only the Flussonic module runs here.
							</p>
							<a href="flussonic" class="btn btn-primary btn-sm">Flussonic Servers</a>
							<a href="flussonic_streams" class="btn btn-outline-primary btn-sm">Flussonic Streams</a>
						</div>
					</div>
				</div>
			</div>
		<?php endif; ?>
	</div>
</div>
<?php
renderUnifiedLayoutFooter('admin');
