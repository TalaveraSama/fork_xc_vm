<div class="wrapper" <?php

use XcVm\Module\Flussonic\Service\FlussonicUrlBuilder;

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
	echo ' style="display: none;"';
}

/**
 * Print `checked` when a value is truthy.
 *
 * @param mixed $rValue Value under test.
 * @return string
 */
$rChecked = static function ($rValue): string {
	return !empty($rValue) ? ' checked' : '';
};

/**
 * Escape a value for an HTML attribute.
 *
 * @param mixed $rValue Value to escape.
 * @return string
 */
$rAttr = static function ($rValue): string {
	return htmlspecialchars((string) $rValue, ENT_QUOTES);
};

$rIsEdit = (int) $rFlussonic['id'] > 0;
?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include MAIN_HOME . 'Public/Views/admin/topbar.php'; ?>
					</div>
					<h4 class="page-title"><?php echo $rIsEdit ? 'Edit Flussonic Server' : 'Add Flussonic Server'; ?></h4>
				</div>
			</div>
		</div>
		<?php if ($rError !== ''): ?>
			<div class="row">
				<div class="col-12">
					<div class="alert alert-danger alert-dismissible fade show" role="alert">
						<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
						<?php echo htmlspecialchars($rError); ?>
					</div>
				</div>
			</div>
		<?php endif; ?>
		<form method="post" action="flussonic_server<?php echo $rIsEdit ? '?id=' . (int) $rFlussonic['id'] : ''; ?>" id="flussonic-form">
			<div class="row">
				<div class="col-lg-6">
					<div class="card">
						<div class="card-body">
							<h4 class="header-title mb-3"><i class="mdi mdi-server"></i> Connection</h4>
							<p class="text-muted font-13">
								Where the Flussonic Media Server API lives. The panel talks to
								<code>/streamer/api/v3/streams</code> using HTTP Basic auth (or a bearer token).
							</p>

							<div class="form-group row mb-3">
								<label class="col-md-4 col-form-label" for="name">Name</label>
								<div class="col-md-8">
									<input type="text" id="name" name="name" class="form-control" required placeholder="Main Flussonic" value="<?php echo $rAttr($rFlussonic['name']); ?>">
								</div>
							</div>

							<div class="form-group row mb-3">
								<label class="col-md-4 col-form-label" for="api_host">API Address</label>
								<div class="col-md-8">
									<div class="input-group">
										<div class="input-group-prepend">
											<select id="api_scheme" name="api_scheme" class="form-control" style="max-width:100px;">
												<option value="http"<?php echo $rFlussonic['api_scheme'] == 'http' ? ' selected' : ''; ?>>http://</option>
												<option value="https"<?php echo $rFlussonic['api_scheme'] == 'https' ? ' selected' : ''; ?>>https://</option>
											</select>
										</div>
										<input type="text" id="api_host" name="api_host" class="form-control" required placeholder="192.168.1.50" value="<?php echo $rAttr($rFlussonic['api_host']); ?>">
										<div class="input-group-append">
											<span class="input-group-text">:</span>
										</div>
										<input type="number" id="api_port" name="api_port" class="form-control" style="max-width:110px;" min="1" max="65535" value="<?php echo (int) $rFlussonic['api_port']; ?>">
									</div>
									<small class="text-muted">Flussonic's HTTP port — 8080 by default.</small>
								</div>
							</div>

							<div class="form-group row mb-3">
								<label class="col-md-4 col-form-label" for="api_username">API Username</label>
								<div class="col-md-8">
									<input type="text" id="api_username" name="api_username" class="form-control" autocomplete="off" value="<?php echo $rAttr($rFlussonic['api_username']); ?>">
								</div>
							</div>

							<div class="form-group row mb-3">
								<label class="col-md-4 col-form-label" for="api_password">API Password</label>
								<div class="col-md-8">
									<input type="password" id="api_password" name="api_password" class="form-control" autocomplete="new-password" placeholder="<?php echo $rIsEdit && $rFlussonic['api_password'] !== '' ? 'unchanged' : ''; ?>" value="">
									<?php if ($rIsEdit): ?><small class="text-muted">Leave blank to keep the stored password.</small><?php endif; ?>
								</div>
							</div>

							<div class="form-group row mb-3">
								<label class="col-md-4 col-form-label" for="bearer_token">Bearer Token</label>
								<div class="col-md-8">
									<input type="password" id="bearer_token" name="bearer_token" class="form-control" autocomplete="off" placeholder="<?php echo $rIsEdit && $rFlussonic['bearer_token'] !== '' ? 'unchanged' : 'optional'; ?>" value="">
									<small class="text-muted">Used instead of user/password when filled in.<?php echo $rIsEdit ? ' Leave blank to keep the stored token.' : ''; ?></small>
								</div>
							</div>

							<div class="form-group row mb-3">
								<label class="col-md-4 col-form-label" for="verify_tls">Verify TLS Certificate</label>
								<div class="col-md-8">
									<input type="checkbox" id="verify_tls" name="verify_tls" value="1" class="js-switch"<?php echo $rChecked($rFlussonic['verify_tls']); ?>>
									<small class="d-block text-muted">Turn off for self-signed certificates.</small>
								</div>
							</div>

							<div class="form-group row mb-0">
								<label class="col-md-4 col-form-label" for="enabled">Enabled</label>
								<div class="col-md-8">
									<input type="checkbox" id="enabled" name="enabled" value="1" class="js-switch"<?php echo $rChecked($rFlussonic['enabled']); ?>>
								</div>
							</div>
						</div>
					</div>

					<div class="card">
						<div class="card-body">
							<h4 class="header-title mb-3"><i class="mdi mdi-play-network"></i> Playback</h4>
							<p class="text-muted font-13">
								How the panel should pull each stream. Leave the host blank to reuse the API address.
							</p>

							<div class="form-group row mb-3">
								<label class="col-md-4 col-form-label" for="protocol">Source Protocol</label>
								<div class="col-md-8">
									<select id="protocol" name="protocol" class="form-control">
										<?php foreach (FlussonicUrlBuilder::PROTOCOLS as $rKey => $rLabel): ?>
											<option value="<?php echo $rAttr($rKey); ?>"<?php echo $rFlussonic['protocol'] == $rKey ? ' selected' : ''; ?>><?php echo htmlspecialchars($rLabel); ?></option>
										<?php endforeach; ?>
									</select>
									<small class="text-muted">Format used to build the source URL of imported channels.</small>
								</div>
							</div>

							<div class="form-group row mb-3">
								<label class="col-md-4 col-form-label" for="play_host">Playback Address</label>
								<div class="col-md-8">
									<div class="input-group">
										<div class="input-group-prepend">
											<select id="play_scheme" name="play_scheme" class="form-control" style="max-width:100px;">
												<option value=""<?php echo $rFlussonic['play_scheme'] == '' ? ' selected' : ''; ?>>auto</option>
												<option value="http"<?php echo $rFlussonic['play_scheme'] == 'http' ? ' selected' : ''; ?>>http://</option>
												<option value="https"<?php echo $rFlussonic['play_scheme'] == 'https' ? ' selected' : ''; ?>>https://</option>
											</select>
										</div>
										<input type="text" id="play_host" name="play_host" class="form-control" placeholder="same as API host" value="<?php echo $rAttr($rFlussonic['play_host']); ?>">
										<div class="input-group-append">
											<span class="input-group-text">:</span>
										</div>
										<input type="number" id="play_port" name="play_port" class="form-control" style="max-width:110px;" min="0" max="65535" value="<?php echo (int) $rFlussonic['play_port']; ?>">
									</div>
									<small class="text-muted">Port 0 = same as the API port. Use this when a CDN/edge fronts the origin.</small>
								</div>
							</div>

							<div class="form-group row mb-3">
								<label class="col-md-4 col-form-label">RTMP / RTSP Ports</label>
								<div class="col-md-8">
									<div class="input-group">
										<div class="input-group-prepend"><span class="input-group-text">RTMP</span></div>
										<input type="number" id="rtmp_port" name="rtmp_port" class="form-control" min="0" max="65535" value="<?php echo (int) $rFlussonic['rtmp_port']; ?>">
										<div class="input-group-prepend"><span class="input-group-text">RTSP</span></div>
										<input type="number" id="rtsp_port" name="rtsp_port" class="form-control" min="0" max="65535" value="<?php echo (int) $rFlussonic['rtsp_port']; ?>">
									</div>
								</div>
							</div>

							<div class="form-group row mb-0">
								<label class="col-md-4 col-form-label" for="play_token">Playback Token</label>
								<div class="col-md-8">
									<input type="text" id="play_token" name="play_token" class="form-control" placeholder="optional" value="<?php echo $rAttr($rFlussonic['play_token']); ?>">
									<small class="text-muted">Appended as <code>?token=</code> on every source URL.</small>
								</div>
							</div>
						</div>
					</div>
				</div>

				<div class="col-lg-6">
					<div class="card">
						<div class="card-body">
							<h4 class="header-title mb-3"><i class="mdi mdi-import"></i> Import Defaults</h4>
							<p class="text-muted font-13">
								Applied to every channel created from this origin, whether you import by hand or
								let the cron do it.
							</p>

							<div class="form-group row mb-3">
								<label class="col-md-4 col-form-label" for="target_server_id">Streaming Server</label>
								<div class="col-md-8">
									<select id="target_server_id" name="target_server_id" class="form-control">
										<option value="0">Auto (main server)</option>
										<?php foreach (($rServers ?: []) as $rPanelServer): ?>
											<option value="<?php echo (int) $rPanelServer['id']; ?>"<?php echo (int) $rFlussonic['target_server_id'] === (int) $rPanelServer['id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars((string) $rPanelServer['server_name']); ?></option>
										<?php endforeach; ?>
									</select>
									<small class="text-muted">Panel server that will run the imported channels.</small>
								</div>
							</div>

							<div class="form-group row mb-3">
								<label class="col-md-4 col-form-label" for="category_id">Category</label>
								<div class="col-md-8">
									<select id="category_id" name="category_id" class="form-control select2" data-toggle="select2">
										<option value="0">None</option>
										<?php foreach ($rCategories as $rCatID => $rCategory): ?>
											<option value="<?php echo (int) $rCatID; ?>"<?php echo (int) $rFlussonic['category_id'] === (int) $rCatID ? ' selected' : ''; ?>><?php echo htmlspecialchars((string) $rCategory['category_name']); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group row mb-3">
								<label class="col-md-4 col-form-label" for="bouquets">Bouquets</label>
								<div class="col-md-8">
									<input type="hidden" name="bouquets[]" value="0">
									<select id="bouquets" name="bouquets[]" class="form-control select2" data-toggle="select2" multiple>
										<?php foreach ($rBouquets as $rBouquetID => $rBouquet): ?>
											<option value="<?php echo (int) $rBouquetID; ?>"<?php echo in_array((int) $rBouquetID, array_map('intval', $rSelectedBouquets), true) ? ' selected' : ''; ?>><?php echo htmlspecialchars((string) $rBouquet['bouquet_name']); ?></option>
										<?php endforeach; ?>
									</select>
									<small class="text-muted">Imported channels are added to these bouquets automatically.</small>
								</div>
							</div>

							<div class="form-group row mb-3">
								<label class="col-md-4 col-form-label" for="stream_prefix">Channel Name Prefix</label>
								<div class="col-md-8">
									<input type="text" id="stream_prefix" name="stream_prefix" class="form-control" placeholder="optional" value="<?php echo $rAttr($rFlussonic['stream_prefix']); ?>">
									<small class="text-muted">e.g. <code>FLS |</code> — prepended to the channel name.</small>
								</div>
							</div>

							<div class="form-group row mb-3">
								<label class="col-md-4 col-form-label" for="direct_source">Direct Source</label>
								<div class="col-md-8">
									<input type="checkbox" id="direct_source" name="direct_source" value="1" class="js-switch"<?php echo $rChecked($rFlussonic['direct_source']); ?>>
									<small class="d-block text-muted">Hand the Flussonic URL straight to the client instead of restreaming it.</small>
								</div>
							</div>
						</div>
					</div>

					<div class="card">
						<div class="card-body">
							<h4 class="header-title mb-3"><i class="mdi mdi-sync"></i> Synchronisation</h4>

							<div class="form-group row mb-3">
								<label class="col-md-4 col-form-label" for="sync_interval">Sync Interval</label>
								<div class="col-md-8">
									<div class="input-group">
										<input type="number" id="sync_interval" name="sync_interval" class="form-control" min="60" step="60" value="<?php echo (int) $rFlussonic['sync_interval']; ?>">
										<div class="input-group-append"><span class="input-group-text">seconds</span></div>
									</div>
									<small class="text-muted">The cron runs every 5 minutes and skips servers synced more recently than this.</small>
								</div>
							</div>

							<div class="form-group row mb-3">
								<label class="col-md-4 col-form-label" for="auto_import">Auto Import</label>
								<div class="col-md-8">
									<input type="checkbox" id="auto_import" name="auto_import" value="1" class="js-switch"<?php echo $rChecked($rFlussonic['auto_import']); ?>>
									<small class="d-block text-muted">Create a channel for every new stream found on this origin.</small>
								</div>
							</div>

							<div class="form-group row mb-0">
								<label class="col-md-4 col-form-label" for="auto_remove">Forget Missing Streams</label>
								<div class="col-md-8">
									<input type="checkbox" id="auto_remove" name="auto_remove" value="1" class="js-switch"<?php echo $rChecked($rFlussonic['auto_remove']); ?>>
									<small class="d-block text-muted">Drop streams from the catalogue when Flussonic stops publishing them. Imported channels are never deleted.</small>
								</div>
							</div>
						</div>
					</div>

					<div class="card">
						<div class="card-body">
							<div id="probe-result" class="mb-2"></div>
							<button type="button" class="btn btn-outline-info" onClick="flussonicProbe();"><i class="mdi mdi-lan-connect"></i> Test Connection</button>
							<button type="submit" class="btn btn-primary float-right"><i class="mdi mdi-content-save"></i> <?php echo $rIsEdit ? 'Save Server' : 'Add Server'; ?></button>
							<a href="flussonic" class="btn btn-light float-right mr-2">Cancel</a>
						</div>
					</div>
				</div>
			</div>
		</form>
	</div>
</div>
<?php
require_once MAIN_HOME . 'Public/Views/layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script id="scripts">
	var resizeObserver = new ResizeObserver(entries => $(window).scroll());

	function flussonicProbe() {
		var rTarget = $("#probe-result");
		rTarget.html('<div class="text-muted"><i class="mdi mdi-loading mdi-spin"></i> Contacting server...</div>');
		$.getJSON("./api", {
			action: "flussonic_probe",
			id: <?php echo (int) $rFlussonic['id']; ?>,
			api_scheme: $("#api_scheme").val(),
			api_host: $("#api_host").val(),
			api_port: $("#api_port").val(),
			api_username: $("#api_username").val(),
			api_password: $("#api_password").val(),
			bearer_token: $("#bearer_token").val(),
			verify_tls: $("#verify_tls").is(":checked") ? 1 : 0
		}, function(data) {
			if (data.result === true) {
				rTarget.html('<div class="alert alert-success mb-0">Connected. ' + data.streams +
					' stream(s) published' + (data.version ? ' &middot; Flussonic ' + data.version : '') + '.</div>');
			} else {
				rTarget.html('<div class="alert alert-danger mb-0">' + (data.error || "Connection failed.") + '</div>');
			}
		}).fail(function() {
			rTarget.html('<div class="alert alert-danger mb-0">Connection failed.</div>');
		});
	}

	$(document).ready(function() {
		resizeObserver.observe(document.body);
		$("form").attr('autocomplete', 'off');
		$(document).keypress(function(event) {
			if (event.which == 13 && event.target.nodeName != "TEXTAREA") return false;
		});
		var elems = Array.prototype.slice.call(document.querySelectorAll('.js-switch'));
		elems.forEach(function(html) {
			var switchery = new Switchery(html, {
				'color': '#414d5f'
			});
			window.rSwitches[$(html).attr("id")] = switchery;
		});
		$('[data-toggle="select2"]').select2({
			width: '100%'
		});
		setTimeout(pingSession, 30000);
		<?php if (!$rMobile && $rSettings['header_stats']): ?>
			headerStats();
		<?php endif; ?>
		bindHref();
		refreshTooltips();
	});
</script>
</body>

</html>
