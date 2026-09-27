<div class="wrapper" <?php

use XcVm\Core\Config\SettingsManager;
use XcVm\Module\Flussonic\Service\FlussonicUrlBuilder;

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
	echo ' style="display: none;"';
}

/**
 * Human-readable bitrate.
 *
 * @param int $rKbps Bitrate in kbit/s as reported by Flussonic.
 * @return string
 */
$rBitrate = static function ($rKbps): string {
	$rKbps = (int) $rKbps;

	if ($rKbps <= 0) {
		return '<span class="text-muted">—</span>';
	}

	return $rKbps >= 1000
		? number_format($rKbps / 1000, 1) . ' <small>Mbps</small>'
		: $rKbps . ' <small>kbps</small>';
};

/**
 * Human-readable DVR depth.
 *
 * @param int $rSeconds Depth in seconds.
 * @return string
 */
$rDepth = static function ($rSeconds): string {
	$rSeconds = (int) $rSeconds;

	if ($rSeconds <= 0) {
		return '<span class="text-muted">—</span>';
	}
	if ($rSeconds >= 86400) {
		return round($rSeconds / 86400, 1) . 'd';
	}
	if ($rSeconds >= 3600) {
		return round($rSeconds / 3600, 1) . 'h';
	}

	return round($rSeconds / 60) . 'm';
};

$rTotal = count($rRows);
$rPending = 0;
$rAliveTotal = 0;

foreach ($rRows as $rRow) {
	if ((int) $rRow['stream_id'] === 0) {
		$rPending++;
	}
	if ((int) $rRow['alive'] === 1) {
		$rAliveTotal++;
	}
}
?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include MAIN_HOME . 'Public/Views/admin/topbar.php'; ?>
					</div>
					<h4 class="page-title">Flussonic Streams</h4>
				</div>
			</div>
		</div>

		<?php if (count($rFlussonicServers) === 0): ?>
			<div class="row">
				<div class="col-12">
					<div class="card">
						<div class="card-body text-center p-4">
							<i class="mdi mdi-server-network-off mdi-48px text-muted"></i>
							<h4>No Flussonic server configured</h4>
							<p class="text-muted">Add a Flussonic Media Server first — its streams will show up here.</p>
							<a href="flussonic_server" class="btn btn-primary"><i class="mdi mdi-plus"></i> Add Flussonic Server</a>
						</div>
					</div>
				</div>
			</div>
		<?php else: ?>
			<div class="row">
				<div class="col-12">
					<div class="card">
						<div class="card-body">
							<form method="get" action="flussonic_streams" class="form-inline" id="filter-form">
								<label class="mr-2" for="server">Server</label>
								<select id="server" name="server" class="form-control mr-3" onchange="$('#filter-form').submit();">
									<option value="0">All servers</option>
									<?php foreach ($rFlussonicServers as $rServerID => $rServerRow): ?>
										<option value="<?php echo (int) $rServerID; ?>"<?php echo $rServerFilter === (int) $rServerID ? ' selected' : ''; ?>>
											<?php echo htmlspecialchars((string) $rServerRow['name']); ?>
										</option>
									<?php endforeach; ?>
								</select>

								<label class="mr-2" for="filter">Show</label>
								<select id="filter" name="filter" class="form-control mr-3" onchange="$('#filter-form').submit();">
									<option value=""<?php echo $rStatusFilter === '' ? ' selected' : ''; ?>>Everything</option>
									<option value="new"<?php echo $rStatusFilter === 'new' ? ' selected' : ''; ?>>Not imported</option>
									<option value="imported"<?php echo $rStatusFilter === 'imported' ? ' selected' : ''; ?>>Imported</option>
									<option value="alive"<?php echo $rStatusFilter === 'alive' ? ' selected' : ''; ?>>Alive</option>
									<option value="offline"<?php echo $rStatusFilter === 'offline' ? ' selected' : ''; ?>>Offline</option>
								</select>

								<input type="text" name="search" class="form-control mr-2" placeholder="Search name or title" value="<?php echo htmlspecialchars((string) $rSearch, ENT_QUOTES); ?>">
								<button type="submit" class="btn btn-light mr-3"><i class="mdi mdi-magnify"></i></button>

								<span class="mr-3">
									<span class="badge badge-secondary"><?php echo $rTotal; ?> listed</span>
									<span class="badge badge-success"><?php echo $rAliveTotal; ?> alive</span>
									<span class="badge badge-warning"><?php echo $rPending; ?> not imported</span>
								</span>
							</form>
						</div>
					</div>
				</div>
			</div>

			<div class="row">
				<div class="col-12">
					<div class="card">
						<div class="card-body">
							<div class="mb-3">
								<button type="button" class="btn btn-primary" onClick="flussonicImportSelected();">
									<i class="mdi mdi-download"></i> Import Selected
									<span class="badge badge-light" id="selected-count">0</span>
								</button>
								<button type="button" class="btn btn-outline-primary" onClick="flussonicImportAll();">
									<i class="mdi mdi-download-multiple"></i> Import All Pending
								</button>
								<button type="button" class="btn btn-outline-info" onClick="flussonicSyncAll();">
									<i class="mdi mdi-sync"></i> Sync Now
								</button>
								<a href="flussonic" class="btn btn-light float-right"><i class="mdi mdi-server"></i> Manage Servers</a>
							</div>

							<div style="overflow-x:auto;">
								<table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
									<thead>
										<tr>
											<th class="text-center" style="width:20px;">
												<input type="checkbox" id="check-all" onclick="flussonicToggleAll(this);">
											</th>
											<th class="text-center">ID</th>
											<th class="text-center">State</th>
											<th>Stream</th>
											<th>Server</th>
											<th class="text-center">Bitrate</th>
											<th class="text-center">Clients</th>
											<th class="text-center">Video</th>
											<th class="text-center">DVR</th>
											<th class="text-center">Panel</th>
											<th class="text-center">Actions</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($rRows as $rRow):
											$rID = (int) $rRow['id'];
											$rServerRow = $rFlussonicServers[(int) $rRow['server_id']] ?? null;
											$rImportedID = (int) $rRow['stream_id'];
											$rPlayUrl = (string) $rRow['play_url'];
											$rEmbed = $rServerRow !== null ? FlussonicUrlBuilder::embedUrl($rServerRow, (string) $rRow['name']) : '';
										?>
											<tr id="fstream-<?php echo $rID; ?>">
												<td class="text-center">
													<input type="checkbox" class="stream-check" value="<?php echo $rID; ?>" onclick="flussonicCount();">
												</td>
												<td class="text-center"><?php echo $rID; ?></td>
												<td class="text-center">
													<?php if ((int) $rRow['alive'] === 1): ?>
														<i class="text-success fas fa-square" data-toggle="tooltip" title="Alive"></i>
													<?php else: ?>
														<i class="text-danger fas fa-square" data-toggle="tooltip" title="Offline"></i>
													<?php endif; ?>
												</td>
												<td>
													<strong><?php echo htmlspecialchars((string) ($rRow['title'] ?: $rRow['name'])); ?></strong>
													<br /><small class="text-muted"><?php echo htmlspecialchars((string) $rRow['name']); ?></small>
													<?php if ($rRow['input_url']): ?>
														<br /><small class="text-muted" data-toggle="tooltip" title="<?php echo htmlspecialchars((string) $rRow['input_url'], ENT_QUOTES); ?>">
															<i class="mdi mdi-import"></i> <?php echo htmlspecialchars(mb_substr((string) $rRow['input_url'], 0, 48)); ?>
														</small>
													<?php endif; ?>
												</td>
												<td>
													<?php echo $rServerRow !== null ? htmlspecialchars((string) $rServerRow['name']) : '<span class="text-muted">unknown</span>'; ?>
												</td>
												<td class="text-center"><?php echo $rBitrate($rRow['bitrate']); ?></td>
												<td class="text-center"><?php echo (int) $rRow['clients'] > 0 ? (int) $rRow['clients'] : '<span class="text-muted">0</span>'; ?></td>
												<td class="text-center">
													<?php if ($rRow['resolution']): ?>
														<?php echo htmlspecialchars((string) $rRow['resolution']); ?>
														<br /><small class="text-muted"><?php echo htmlspecialchars((string) ($rRow['video_codec'] ?: '')); ?><?php echo $rRow['audio_codec'] ? ' / ' . htmlspecialchars((string) $rRow['audio_codec']) : ''; ?></small>
													<?php else: ?>
														<span class="text-muted">—</span>
													<?php endif; ?>
												</td>
												<td class="text-center"><?php echo $rDepth($rRow['dvr_depth']); ?></td>
												<td class="text-center">
													<?php if ($rImportedID > 0): ?>
														<a href="stream?id=<?php echo $rImportedID; ?>" class="badge badge-success" data-toggle="tooltip" title="Open channel #<?php echo $rImportedID; ?>">imported</a>
													<?php else: ?>
														<span class="badge badge-warning">pending</span>
													<?php endif; ?>
												</td>
												<td class="text-center text-nowrap">
													<?php if ($rImportedID === 0): ?>
														<button type="button" class="btn btn-sm btn-outline-primary" onClick="flussonicImportOne(<?php echo $rID; ?>);" data-toggle="tooltip" title="Import as channel"><i class="mdi mdi-download"></i></button>
													<?php endif; ?>
													<button type="button" class="btn btn-sm btn-outline-secondary" onClick="flussonicCopy('<?php echo htmlspecialchars(addslashes($rPlayUrl), ENT_QUOTES); ?>');" data-toggle="tooltip" title="<?php echo htmlspecialchars($rPlayUrl, ENT_QUOTES); ?>"><i class="mdi mdi-content-copy"></i></button>
													<?php if ($rEmbed !== ''): ?>
														<a href="<?php echo htmlspecialchars($rEmbed, ENT_QUOTES); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-info" data-toggle="tooltip" title="Preview on Flussonic"><i class="mdi mdi-play"></i></a>
													<?php endif; ?>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
							<?php if ($rTotal === 0): ?>
								<div class="text-center text-muted p-3">
									Nothing catalogued yet for this filter. Hit <strong>Sync Now</strong> to ask the
									Flussonic server what it is publishing.
								</div>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
		<?php endif; ?>
	</div>
</div>
<?php
require_once MAIN_HOME . 'Public/Views/layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script id="scripts">
	var resizeObserver = new ResizeObserver(entries => $(window).scroll());
	var rServerFilter = <?php echo (int) $rServerFilter; ?>;

	function flussonicToggleAll(rSource) {
		$(".stream-check").prop("checked", rSource.checked);
		flussonicCount();
	}

	function flussonicCount() {
		$("#selected-count").text($(".stream-check:checked").length);
	}

	function flussonicSelected() {
		return $(".stream-check:checked").map(function() {
			return this.value;
		}).get();
	}

	function flussonicCopy(rURL) {
		if (navigator.clipboard) {
			navigator.clipboard.writeText(rURL);
		} else {
			var rTemp = $("<input>").val(rURL).appendTo("body").select();
			document.execCommand("copy");
			rTemp.remove();
		}
		$.toast("Source URL copied to clipboard.");
	}

	function flussonicImport(rParams, rMessage) {
		$.toast(rMessage);
		$.getJSON("./api", rParams, function(data) {
			if (data.result === true) {
				$.toast(data.imported + " channel(s) created" + (data.skipped > 0 ? ", " + data.skipped + " skipped" : "") + ".");
				if (data.error) {
					$.toast(data.error, "error");
				}
				setTimeout(function() {
					location.reload();
				}, 1200);
			} else {
				$.toast(data.error || "An error occured while processing your request.");
			}
		}).fail(function() {
			$.toast("An error occured while processing your request.");
		});
	}

	function flussonicImportOne(rID) {
		flussonicImport({
			action: "flussonic_import",
			ids: rID
		}, "Importing stream...");
	}

	function flussonicImportSelected() {
		var rIDs = flussonicSelected();
		if (rIDs.length === 0) {
			$.toast("Select at least one stream first.");
			return;
		}
		flussonicImport({
			action: "flussonic_import",
			ids: rIDs.join(",")
		}, "Importing " + rIDs.length + " stream(s)...");
	}

	function flussonicImportAll() {
		new jBox("Confirm", {
			confirmButton: "Import",
			cancelButton: "Cancel",
			content: "Import every stream that is not a channel yet" + (rServerFilter > 0 ? " from the selected server" : " from every enabled server") + "?",
			confirm: function() {
				flussonicImport({
					action: "flussonic_import",
					all: 1,
					server_id: rServerFilter
				}, "Importing pending streams...");
			}
		}).open();
	}

	function flussonicSyncAll() {
		$.toast("Asking Flussonic for the current stream list...");
		$.getJSON("./api?action=flussonic_sync" + (rServerFilter > 0 ? "&id=" + rServerFilter : ""), function(data) {
			if (data.result === true) {
				$.toast(data.found + " stream(s) found, " + data.new + " new.");
			} else {
				$.toast(data.error || "An error occured while processing your request.");
			}
			setTimeout(function() {
				location.reload();
			}, 1200);
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
		<?php if (count($rFlussonicServers) > 0): ?>
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
					flussonicCount();
				},
				order: [
					[3, "asc"]
				],
				pageLength: 50,
				columnDefs: [{
					"orderable": false,
					"targets": [0, 10]
				}, {
					"visible": false,
					"targets": [1]
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
