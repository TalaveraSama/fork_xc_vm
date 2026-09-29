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
							<div class="alert alert-warning">
								<strong>Importing as channels is not wired up yet in 1.0.0.</strong>
								The scan results below are real and stored; the button that turns a
								selection into panel streams lands in the next version.
							</div>

							<div class="table-responsive">
								<table class="table table-centered table-hover mb-0" id="datatable">
									<thead class="thead-light">
										<tr>
											<th class="text-center">SID</th>
											<th>Name</th>
											<th>Provider</th>
											<th class="text-center">Type</th>
											<th class="text-center">Video PID</th>
											<th class="text-center">Audio PID</th>
											<th class="text-center">CA</th>
											<th class="text-center">Imported</th>
											<th>Last seen</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($rServices as $rRow): ?>
											<tr>
												<td class="text-center"><?php echo (int) $rRow['service_id']; ?></td>
												<td><?php echo htmlspecialchars((string) $rRow['name'], ENT_QUOTES); ?></td>
												<td><small><?php echo htmlspecialchars((string) $rRow['provider'], ENT_QUOTES); ?></small></td>
												<td class="text-center">
													<?php echo ((int) $rRow['video_pid'] > 0) ? '<i class="mdi mdi-television" title="TV"></i>' : '<i class="mdi mdi-radio" title="Radio"></i>'; ?>
												</td>
												<td class="text-center"><?php echo (int) $rRow['video_pid']; ?></td>
												<td class="text-center"><small><?php echo htmlspecialchars((string) $rRow['audio_pid'], ENT_QUOTES); ?></small></td>
												<td class="text-center">
													<?php if (!empty($rRow['encrypted'])): ?>
														<i class="mdi mdi-lock text-warning" title="Carries conditional access"></i>
													<?php else: ?>
														<i class="mdi mdi-lock-open text-success" title="Free to air"></i>
													<?php endif; ?>
												</td>
												<td class="text-center">
													<?php if (!empty($rRow['stream_id'])): ?>
														<span class="badge badge-success">#<?php echo (int) $rRow['stream_id']; ?></span>
													<?php else: ?>
														<span class="text-muted">&mdash;</span>
													<?php endif; ?>
												</td>
												<td><small><?php echo $rRow['last_seen'] ? date('Y-m-d H:i', (int) $rRow['last_seen']) : ''; ?></small></td>
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
<script src="assets/js/listings.js"></script>
</body>

</html>
