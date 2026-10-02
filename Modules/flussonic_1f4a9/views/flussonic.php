<div class="wrapper" <?php

use XcVm\Core\Config\SettingsManager;
use XcVm\Module\Flussonic\Service\FlussonicUrlBuilder;

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
	echo ' style="display: none;"';
} ?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include MAIN_HOME . 'Public/Views/admin/topbar.php'; ?>
					</div>
					<h4 class="page-title">Flussonic Servers</h4>
				</div>
			</div>
		</div>
		<?php
		$rTotalServers = count($rFlussonicServers);
		$rOnline = 0;
		$rDiscovered = 0;
		$rAlive = 0;
		$rImported = 0;

		foreach ($rFlussonicServers as $rRow) {
			if ($rRow['enabled'] && $rRow['status']) {
				$rOnline++;
			}

			$rRowStats = $rStats[(int) $rRow['id']] ?? ['total' => 0, 'alive' => 0, 'imported' => 0];
			$rDiscovered += $rRowStats['total'];
			$rAlive += $rRowStats['alive'];
			$rImported += $rRowStats['imported'];
		}
		?>
		<div class="row">
			<div class="col-12">
				<?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS): ?>
					<div class="alert alert-success alert-dismissible fade show" role="alert">
						<button type="button" class="close" data-dismiss="alert" aria-label="Close">
							<span aria-hidden="true">&times;</span>
						</button>
						Flussonic server saved. Its streams are being catalogued and will appear under <a href="flussonic_streams">Available Streams</a>.
					</div>
				<?php endif; ?>
			</div>
		</div>
		<div class="row">
			<div class="col-md-3 col-6">
				<div class="card">
					<div class="card-body p-2 text-center">
						<h3 class="mb-0"><?php echo $rTotalServers; ?></h3>
						<small class="text-muted text-uppercase">Servers (<?php echo $rOnline; ?> online)</small>
					</div>
				</div>
			</div>
			<div class="col-md-3 col-6">
				<div class="card">
					<div class="card-body p-2 text-center">
						<h3 class="mb-0"><?php echo $rDiscovered; ?></h3>
						<small class="text-muted text-uppercase">Streams discovered</small>
					</div>
				</div>
			</div>
			<div class="col-md-3 col-6">
				<div class="card">
					<div class="card-body p-2 text-center">
						<h3 class="mb-0 text-success"><?php echo $rAlive; ?></h3>
						<small class="text-muted text-uppercase">Currently alive</small>
					</div>
				</div>
			</div>
			<div class="col-md-3 col-6">
				<div class="card">
					<div class="card-body p-2 text-center">
						<h3 class="mb-0 text-info"><?php echo $rImported; ?></h3>
						<small class="text-muted text-uppercase">Imported as channels</small>
					</div>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-12">
				<div class="card">
					<div class="card-body" style="overflow-x:auto;">
						<?php if ($rTotalServers == 0): ?>
							<div class="text-center p-4">
								<i class="mdi mdi-server-network mdi-48px text-muted"></i>
								<h4>No Flussonic server yet</h4>
								<p class="text-muted">
									Add the address and API credentials of a Flussonic Media Server. The panel will
									read every stream it publishes through the v3 API and let you import the ones
									you want as live channels.
								</p>
								<a href="flussonic_server" class="btn btn-primary"><i class="mdi mdi-plus"></i> Add Flussonic Server</a>
							</div>
						<?php else: ?>
							<table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
								<thead>
									<tr>
										<th class="text-center">ID</th>
										<th class="text-center">Status</th>
										<th>Server</th>
										<th class="text-center">Protocol</th>
										<th class="text-center">Streams</th>
										<th class="text-center">Imported</th>
										<th class="text-center">Auto</th>
										<th class="text-center">Last Sync</th>
										<th class="text-center">Actions</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($rFlussonicServers as $rRow):
										$rID = (int) $rRow['id'];
										$rRowStats = $rStats[$rID] ?? ['total' => 0, 'alive' => 0, 'imported' => 0];
										$rProtocol = FlussonicUrlBuilder::protocol($rRow['protocol']);
										$rProtocolLabel = explode('—', FlussonicUrlBuilder::PROTOCOLS[$rProtocol])[0];

										if (!$rRow['enabled']) {
											$rStatusIcon = '<i class="text-secondary fas fa-square" data-toggle="tooltip" title="Disabled"></i>';
										} elseif ($rRow['status']) {
											$rStatusIcon = '<i class="text-success fas fa-square" data-toggle="tooltip" title="Online"></i>';
										} else {
											$rStatusIcon = '<i class="text-danger fas fa-square" data-toggle="tooltip" title="' . htmlspecialchars((string) ($rRow['last_error'] ?: 'Offline'), ENT_QUOTES) . '"></i>';
										}
									?>
										<tr id="flussonic-<?php echo $rID; ?>">
											<td class="text-center"><?php echo $rID; ?></td>
											<td class="text-center"><?php echo $rStatusIcon; ?></td>
											<td>
												<?php echo htmlspecialchars((string) $rRow['name']); ?>
												<br /><small class="text-muted"><?php echo htmlspecialchars(FlussonicUrlBuilder::apiBase($rRow)); ?></small>
												<?php if (!$rRow['status'] && $rRow['enabled'] && $rRow['last_error']): ?>
													<br /><small class="text-danger"><?php echo htmlspecialchars(mb_substr((string) $rRow['last_error'], 0, 90)); ?></small>
												<?php endif; ?>
											</td>
											<td class="text-center"><span class="badge badge-secondary"><?php echo trim($rProtocolLabel); ?></span></td>
											<td class="text-center">
												<span data-toggle="tooltip" title="<?php echo $rRowStats['alive']; ?> alive">
													<?php echo $rRowStats['total']; ?>
													<small class="text-success">(<?php echo $rRowStats['alive']; ?>)</small>
												</span>
											</td>
											<td class="text-center"><?php echo $rRowStats['imported']; ?></td>
											<td class="text-center">
												<?php if ($rRow['auto_import']): ?>
													<i class="mdi mdi-download text-success" data-toggle="tooltip" title="New streams are imported automatically"></i>
												<?php else: ?>
													<i class="mdi mdi-download text-muted" data-toggle="tooltip" title="Manual import"></i>
												<?php endif; ?>
											</td>
											<td class="text-center">
												<?php echo $rRow['last_sync'] > 0 ? date('d/m/Y H:i', (int) $rRow['last_sync']) : '<small class="text-muted">never</small>'; ?>
											</td>
											<td class="text-center text-nowrap">
												<a href="flussonic_streams?server=<?php echo $rID; ?>" class="btn btn-sm btn-outline-info" data-toggle="tooltip" title="Browse streams"><i class="mdi mdi-playlist-play"></i></a>
												<button type="button" class="btn btn-sm btn-outline-primary" onClick="flussonicSync(<?php echo $rID; ?>);" data-toggle="tooltip" title="Sync now"><i class="mdi mdi-sync"></i></button>
												<a href="flussonic_server?id=<?php echo $rID; ?>" class="btn btn-sm btn-outline-secondary" data-toggle="tooltip" title="Edit"><i class="mdi mdi-pencil"></i></a>
												<?php if ($rRow['enabled']): ?>
													<button type="button" class="btn btn-sm btn-outline-warning" onClick="flussonicServer(<?php echo $rID; ?>, 'disable');" data-toggle="tooltip" title="Disable"><i class="mdi mdi-pause"></i></button>
												<?php else: ?>
													<button type="button" class="btn btn-sm btn-outline-success" onClick="flussonicServer(<?php echo $rID; ?>, 'enable');" data-toggle="tooltip" title="Enable"><i class="mdi mdi-play"></i></button>
												<?php endif; ?>
												<button type="button" class="btn btn-sm btn-outline-danger" onClick="flussonicServer(<?php echo $rID; ?>, 'delete');" data-toggle="tooltip" title="Delete"><i class="mdi mdi-delete"></i></button>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
<?php
require_once MAIN_HOME . 'Public/Views/layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script id="scripts">
	var resizeObserver = new ResizeObserver(entries => $(window).scroll());

	function flussonicSync(rID) {
		$.toast("Syncing Flussonic server...");
		$.getJSON("./api?action=flussonic_sync&id=" + rID, function(data) {
			if (data.result === true) {
				$.toast(data.found + " stream(s) found, " + data.new + " new, " + data.imported + " imported.");
				setTimeout(function() {
					location.reload();
				}, 1200);
			} else {
				$.toast(data.error || "An error occured while processing your request.");
			}
		}).fail(function (rXHR) {
			// Without this a failed request is completely silent: no
			// toast, no console entry, nothing. A 502 or an expired
			// session then looks exactly like a button that does nothing.
			$.toast("Request failed (" + rXHR.status + "). Check the panel log.");
		});
	}

	function flussonicSyncAll() {
		$.toast("Syncing every enabled Flussonic server...");
		$.getJSON("./api?action=flussonic_sync", function(data) {
			if (data.result === true) {
				$.toast(data.found + " stream(s) across " + data.servers + " server(s), " + data.new + " new.");
			} else {
				$.toast(data.error || "An error occured while processing your request.");
			}
			setTimeout(function() {
				location.reload();
			}, 1200);
		}).fail(function (rXHR) {
			// Without this a failed request is completely silent: no
			// toast, no console entry, nothing. A 502 or an expired
			// session then looks exactly like a button that does nothing.
			$.toast("Request failed (" + rXHR.status + "). Check the panel log.");
		});
	}

	function flussonicServer(rID, rType, rConfirm = false) {
		if ((rType == "delete") && (!rConfirm)) {
			new jBox("Confirm", {
				confirmButton: "Delete",
				cancelButton: "Cancel",
				content: "Are you sure you want to delete this Flussonic server?<br/>Channels already imported from it will be kept.",
				confirm: function() {
					flussonicServer(rID, rType, true);
				}
			}).open();
			return;
		}
		$.getJSON("./api?action=flussonic_server&sub=" + rType + "&id=" + rID, function(data) {
			if (data.result === true) {
				if (rType == "delete") {
					var rRow = findRowByID($("#datatable").DataTable(), 0, rID);
					if (rRow) {
						$("#datatable").DataTable().rows(rRow).remove().draw(false);
					}
					$.toast("Flussonic server has been deleted.");
				} else {
					$.toast("Flussonic server has been updated.");
					setTimeout(function() {
						location.reload();
					}, 800);
				}
			} else {
				$.toast(data.error || "An error occured while processing your request.");
			}
		}).fail(function (rXHR) {
			// Without this a failed request is completely silent: no
			// toast, no console entry, nothing. A 502 or an expired
			// session then looks exactly like a button that does nothing.
			$.toast("Request failed (" + rXHR.status + "). Check the panel log.");
		});
	}

	$(document).ready(function() {
		resizeObserver.observe(document.body);
		$("form").attr('autocomplete', 'off');
		$.fn.dataTable.ext.errMode = 'none';
		setTimeout(pingSession, 30000);
		<?php if (!$rMobile && $rSettings['header_stats']): ?>
			headerStats();
		<?php endif; ?>
		bindHref();
		refreshTooltips();
		<?php if ($rTotalServers > 0): ?>
			$("#datatable").DataTable({
				language: {
					paginate: {
						previous: "<i class='mdi mdi-chevron-left'>",
						next: "<i class='mdi mdi-chevron-right'>"
					}
				},
				drawCallback: function() {
					bindHref();
					refreshTooltips();
				},
				order: [
					[0, "asc"]
				],
				columnDefs: [{
					"orderable": false,
					"targets": [8]
				}],
				responsive: false
			});
			$("#datatable").css("width", "100%");
		<?php endif; ?>
	});
	<?php if (SettingsManager::getAll()['enable_search']): ?>
		$(document).ready(function() {
			initSearch();
		});
	<?php endif; ?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
