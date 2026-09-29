<div class="wrapper" <?php

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
					<h4 class="page-title">DVB Services</h4>
				</div>
			</div>
		</div>

		<div class="row">
			<div class="col-12">
				<div class="card">
					<div class="card-body">
						<div class="row mb-3">
							<div class="col-sm-8">
								<form method="get" action="dvb_services" class="form-inline">
									<label class="mr-2">Transponder</label>
									<select name="transponder_id" class="form-control mr-2" onchange="this.form.submit();">
										<?php foreach ($rTransponders as $rRow): ?>
											<option value="<?php echo (int) $rRow['id']; ?>" <?php echo ((int) $rRow['id'] === $rTransponderID) ? 'selected' : ''; ?>>
												<?php echo htmlspecialchars((string) $rRow['name'], ENT_QUOTES); ?>
												<?php echo $rRow['satellite'] !== '' ? (' — ' . htmlspecialchars((string) $rRow['satellite'], ENT_QUOTES)) : ''; ?>
												(<?php echo (int) $rRow['service_count']; ?>)
											</option>
										<?php endforeach; ?>
									</select>
								</form>
							</div>
							<div class="col-sm-4 text-sm-right">
								<a href="dvb" class="btn btn-secondary">
									<i class="mdi mdi-arrow-left mr-1"></i>Transponders
								</a>
							</div>
						</div>

						<?php if (empty($rServices)): ?>
							<div class="alert alert-info mb-0">
								Nothing found on this transponder yet. Run a scan from the
								<a href="dvb">Transponders</a> page.
							</div>
						<?php else: ?>
							<div class="card border mb-3">
								<div class="card-body">
									<h5 class="card-title">Import selected as channels</h5>
									<div class="form-row">
										<div class="form-group col-md-3">
											<label>Category</label>
											<select id="import_category" class="form-control">
												<option value="0">No category</option>
												<?php foreach ($rCategories as $rCatID => $rCat): ?>
													<option value="<?php echo (int) $rCatID; ?>">
														<?php echo htmlspecialchars((string) $rCat['category_name'], ENT_QUOTES); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</div>
										<div class="form-group col-md-3">
											<label>Bouquets</label>
											<select id="import_bouquets" class="form-control" multiple size="1">
												<?php foreach ($rBouquets as $rBqID => $rBq): ?>
													<option value="<?php echo (int) $rBqID; ?>">
														<?php echo htmlspecialchars((string) $rBq['bouquet_name'], ENT_QUOTES); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</div>
										<div class="form-group col-md-3">
											<label>Name prefix</label>
											<input type="text" id="import_prefix" class="form-control" placeholder="optional">
										</div>
										<div class="form-group col-md-3">
											<label>&nbsp;</label>
											<div>
												<div class="custom-control custom-checkbox">
													<input type="checkbox" class="custom-control-input" id="import_skip_encrypted" checked>
													<label class="custom-control-label" for="import_skip_encrypted">Skip encrypted</label>
												</div>
												<div class="custom-control custom-checkbox">
													<input type="checkbox" class="custom-control-input" id="import_start" checked>
													<label class="custom-control-label" for="import_start">Start channels after import</label>
												</div>
											</div>
										</div>
									</div>
									<button type="button" class="btn btn-danger" onclick="dvbImport();">
										<i class="mdi mdi-import mr-1"></i>Import selected
									</button>
									<span class="text-muted ml-2" id="import_count">0 selected</span>
								</div>
							</div>

							<div class="table-responsive">
								<table class="table table-centered table-hover mb-0">
									<thead class="thead-light">
										<tr>
											<th class="text-center" style="width: 40px;">
												<input type="checkbox" id="check_all" onclick="dvbCheckAll(this);">
											</th>
											<th class="text-center">SID</th>
											<th>Name</th>
											<th>Provider</th>
											<th class="text-center">Video PID</th>
											<th class="text-center">Audio PID</th>
											<th class="text-center">CA</th>
											<th class="text-center">Channel</th>
											<th>Output</th>
											<th class="text-center"></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($rServices as $rRow): ?>
											<?php $rLinked = !empty($rRow['stream_id']); ?>
											<tr>
												<td class="text-center">
													<input type="checkbox" class="dvb-service" value="<?php echo (int) $rRow['id']; ?>"
														onclick="dvbCount();" <?php echo $rLinked ? 'disabled' : ''; ?>>
												</td>
												<td class="text-center"><?php echo (int) $rRow['service_id']; ?></td>
												<td><?php echo htmlspecialchars((string) $rRow['name'], ENT_QUOTES); ?></td>
												<td><small><?php echo htmlspecialchars((string) $rRow['provider'], ENT_QUOTES); ?></small></td>
												<td class="text-center"><?php echo (int) $rRow['video_pid']; ?></td>
												<td class="text-center"><small><?php echo htmlspecialchars((string) $rRow['audio_pid'], ENT_QUOTES); ?></small></td>
												<td class="text-center">
													<?php if (!empty($rRow['encrypted'])): ?>
														<i class="mdi mdi-lock text-warning" title="Carries conditional access — you need a CAM to watch it"></i>
													<?php else: ?>
														<i class="mdi mdi-lock-open text-success" title="Free to air"></i>
													<?php endif; ?>
												</td>
												<td class="text-center">
													<?php if ($rLinked): ?>
														<a href="stream?id=<?php echo (int) $rRow['stream_id']; ?>" class="badge badge-success">
															#<?php echo (int) $rRow['stream_id']; ?>
														</a>
													<?php else: ?>
														<span class="text-muted">&mdash;</span>
													<?php endif; ?>
												</td>
												<td>
													<?php if ($rLinked && $rRow['output_port']): ?>
														<small><code>udp://<?php echo htmlspecialchars((string) $rRow['output_ip'], ENT_QUOTES); ?>:<?php echo (int) $rRow['output_port']; ?></code></small>
													<?php endif; ?>
												</td>
												<td class="text-center">
													<?php if ($rLinked): ?>
														<button type="button" class="btn btn-sm btn-secondary" title="Unlink from the channel"
															onclick="dvbUnlink(<?php echo (int) $rRow['id']; ?>);">
															<i class="mdi mdi-link-off"></i>
														</button>
													<?php endif; ?>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

<script>
	function dvbNotify(rMessage) {
		if (typeof toastr !== 'undefined') {
			toastr.info(rMessage);
		} else {
			alert(rMessage);
		}
	}

	function dvbCheckAll(rBox) {
		$('.dvb-service:not(:disabled)').prop('checked', rBox.checked);
		dvbCount();
	}

	function dvbCount() {
		$('#import_count').text($('.dvb-service:checked').length + ' selected');
	}

	function dvbImport() {
		var rIDs = $('.dvb-service:checked').map(function() {
			return $(this).val();
		}).get();

		if (rIDs.length === 0) {
			dvbNotify('Select at least one service.');
			return;
		}

		$.post('./api?action=dvb_import', {
			service_ids: rIDs,
			category_id: $('#import_category').val(),
			bouquets: $('#import_bouquets').val() || [],
			prefix: $('#import_prefix').val(),
			skip_encrypted: $('#import_skip_encrypted').is(':checked') ? 1 : 0,
			start: $('#import_start').is(':checked') ? 1 : 0
		}, function(rData) {
			if (rData.error) {
				dvbNotify(rData.error);
			}
			dvbNotify(rData.note);
			setTimeout(function() {
				location.reload();
			}, 1500);
		}, 'json');
	}

	function dvbUnlink(rID) {
		if (!confirm('Unlink this service from its channel? The channel itself is kept.')) {
			return;
		}

		$.post('./api?action=dvb_unlink', {
			id: rID
		}, function(rData) {
			dvbNotify(rData.note || rData.error);
			location.reload();
		}, 'json');
	}
</script>
</body>

</html>
