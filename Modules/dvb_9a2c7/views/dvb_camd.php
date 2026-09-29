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
					<h4 class="page-title">DVB Card Servers</h4>
				</div>
			</div>
		</div>

		<?php if (!empty($rNotice)): ?>
			<div class="row">
				<div class="col-12">
					<div class="alert alert-success"><?php echo htmlspecialchars((string) $rNotice, ENT_QUOTES); ?></div>
				</div>
			</div>
		<?php endif; ?>

		<?php if (!empty($rError)): ?>
			<div class="row">
				<div class="col-12">
					<div class="alert alert-danger"><?php echo htmlspecialchars((string) $rError, ENT_QUOTES); ?></div>
				</div>
			</div>
		<?php endif; ?>

		<div class="row">
			<div class="col-lg-5">
				<div class="card">
					<div class="card-body">
						<h5 class="mt-0 mb-3">
							<?php echo $rEditing === null ? 'Add a card server' : 'Edit card server'; ?>
						</h5>

						<form method="post" action="./dvb_camd<?php echo $rEditing === null ? '' : ('?id=' . (int) $rEditing['id']); ?>">
							<input type="hidden" name="do" value="save">

							<div class="form-group">
								<label>Name</label>
								<input type="text" name="name" class="form-control" placeholder="Leave blank to use host:port"
									value="<?php echo htmlspecialchars((string) ($rEditing['name'] ?? ''), ENT_QUOTES); ?>">
							</div>

							<div class="form-group">
								<label>Protocol</label>
								<select name="protocol" id="camd-protocol" class="form-control">
									<?php foreach (['NEWCAMD' => 'NEWCAMD', 'CS378X' => 'CS378X (camd35 over TCP)'] as $rValue => $rLabel): ?>
										<option value="<?php echo $rValue; ?>" <?php echo (strtoupper((string) ($rEditing['protocol'] ?? 'NEWCAMD')) === $rValue) ? 'selected' : ''; ?>>
											<?php echo htmlspecialchars($rLabel, ENT_QUOTES); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<small class="text-muted">CS378X is what OSCam exposes by default. NEWCAMD additionally needs the DES key.</small>
							</div>

							<div class="form-row">
								<div class="form-group col-8">
									<label>Host</label>
									<input type="text" name="host" class="form-control" required
										value="<?php echo htmlspecialchars((string) ($rEditing['host'] ?? ''), ENT_QUOTES); ?>">
								</div>
								<div class="form-group col-4">
									<label>Port</label>
									<input type="number" name="port" class="form-control" min="1" max="65535" required
										value="<?php echo (int) ($rEditing['port'] ?? 2233); ?>">
								</div>
							</div>

							<div class="form-row">
								<div class="form-group col-6">
									<label>Username</label>
									<input type="text" name="username" class="form-control" autocomplete="off" required
										value="<?php echo htmlspecialchars((string) ($rEditing['username'] ?? ''), ENT_QUOTES); ?>">
								</div>
								<div class="form-group col-6">
									<label>Password</label>
									<input type="text" name="password" class="form-control" autocomplete="off"
										value="<?php echo htmlspecialchars((string) ($rEditing['password'] ?? ''), ENT_QUOTES); ?>">
								</div>
							</div>

							<div class="form-group" id="camd-deskey-group">
								<label>DES key</label>
								<input type="text" name="des_key" class="form-control" spellcheck="false"
									value="<?php echo htmlspecialchars((string) ($rEditing['des_key'] ?? '0102030405060708091011121314'), ENT_QUOTES); ?>">
								<small class="text-muted">
									NEWCAMD only. Exactly 28 hex characters. A wrong key is rejected by the server
									the same way a wrong password is, so check both if login fails.
								</small>
							</div>

							<div class="form-row">
								<div class="form-group col-6">
									<label>CA system</label>
									<select name="ca_system" class="form-control">
										<?php
										$rSystems = ['CONAX', 'CRYPTOWORKS', 'IRDETO', 'VIACCESS', 'MEDIAGUARD', 'SECA', 'VIDEOGUARD', 'NDS', 'NAGRA', 'BULCRYPT', 'GRIFFIN', 'DGCRYPT', 'DRECRYPT'];
										$rCurrent = strtoupper((string) ($rEditing['ca_system'] ?? 'CONAX'));
										foreach ($rSystems as $rSystem):
										?>
											<option value="<?php echo $rSystem; ?>" <?php echo ($rCurrent === $rSystem) ? 'selected' : ''; ?>><?php echo $rSystem; ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="form-group col-6">
									<label>CAID <small class="text-muted">(optional)</small></label>
									<input type="text" name="caid" class="form-control" placeholder="e.g. 0963" spellcheck="false"
										value="<?php echo htmlspecialchars((string) ($rEditing['caid'] ?? ''), ENT_QUOTES); ?>">
									<small class="text-muted">Overrides the CA system when set.</small>
								</div>
							</div>

							<div class="form-group">
								<label>Input buffer (ms)</label>
								<input type="number" name="input_buffer" class="form-control" min="0" max="10000"
									value="<?php echo (int) ($rEditing['input_buffer'] ?? 0); ?>">
								<small class="text-muted">
									Delays decoding so late control words still arrive in time. Set it above the
									slowest answer your card server gives: a remote line answering in 2 seconds
									needs about 2500 here, and leaving it at 0 produces a stutter every crypto
									period that looks exactly like a weak signal.
								</small>
							</div>
							<div class="form-group">
								<label>Max simultaneous connections</label>
								<input type="number" name="max_connections" class="form-control" min="0" max="1000"
									value="<?php echo (int) ($rEditing['max_connections'] ?? 0); ?>">
								<small class="form-text text-muted">
									One encrypted channel is one CAMD session. Set this to the session
									limit of your line and the surplus is held back with a clear reason
									instead of being rejected in a reconnect loop. 0 means no cap.
								</small>
							</div>

							<div class="form-group">
								<div class="custom-control custom-checkbox mb-1">
									<input type="checkbox" class="custom-control-input" id="camd-emm" name="emm" value="1"
										<?php echo !empty($rEditing['emm']) ? 'checked' : ''; ?>>
									<label class="custom-control-label" for="camd-emm">
										Forward EMMs <small class="text-muted">&mdash; keeps card entitlements updated. Off unless the provider asks for it.</small>
									</label>
								</div>
								<div class="custom-control custom-checkbox mb-1">
									<input type="checkbox" class="custom-control-input" id="camd-mute" name="mute_on_error" value="1"
										<?php echo (!isset($rEditing['mute_on_error']) || !empty($rEditing['mute_on_error'])) ? 'checked' : ''; ?>>
									<label class="custom-control-label" for="camd-mute">
										Output nothing without a valid control word
										<small class="text-muted">&mdash; a black channel is diagnosable, scrambled noise gets blamed on the encoder.</small>
									</label>
								</div>
								<div class="custom-control custom-checkbox">
									<input type="checkbox" class="custom-control-input" id="camd-enabled" name="enabled" value="1"
										<?php echo (!isset($rEditing['enabled']) || !empty($rEditing['enabled'])) ? 'checked' : ''; ?>>
									<label class="custom-control-label" for="camd-enabled">Enabled</label>
								</div>
							</div>

							<div class="form-group">
								<label>Notes</label>
								<textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars((string) ($rEditing['notes'] ?? ''), ENT_QUOTES); ?></textarea>
							</div>

							<button type="submit" class="btn btn-primary">
								<i class="mdi mdi-content-save mr-1"></i><?php echo $rEditing === null ? 'Add' : 'Save'; ?>
							</button>
							<?php if ($rEditing !== null): ?>
								<a href="./dvb_camd" class="btn btn-secondary">Cancel</a>
							<?php endif; ?>
						</form>
					</div>
				</div>
			</div>

			<div class="col-lg-7">
				<div class="card">
					<div class="card-body">
						<h5 class="mt-0 mb-3">Configured servers</h5>

						<?php if (empty($rCamds)): ?>
							<p class="text-muted mb-0">
								No card servers yet. Add one on the left, then assign it to the encrypted
								services you want descrambled from the DVB Services page.
							</p>
						<?php else: ?>
							<div class="table-responsive">
								<table class="table table-sm table-centered table-hover mb-0">
									<thead class="thead-light">
										<tr>
											<th>Name</th>
											<th>Protocol</th>
											<th>Server</th>
											<th>CA</th>
											<th class="text-center">Channels</th>
											<th class="text-center">State</th>
											<th class="text-right">Actions</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($rCamds as $rCamd): ?>
											<tr>
												<td>
													<?php echo htmlspecialchars((string) $rCamd['name'], ENT_QUOTES); ?>
													<?php if (trim((string) $rCamd['notes']) !== ''): ?>
														<br><small class="text-muted"><?php echo htmlspecialchars(mb_substr((string) $rCamd['notes'], 0, 80), ENT_QUOTES); ?></small>
													<?php endif; ?>
												</td>
												<td><?php echo htmlspecialchars((string) $rCamd['protocol'], ENT_QUOTES); ?></td>
												<td><code><?php echo htmlspecialchars($rCamd['host'] . ':' . $rCamd['port'], ENT_QUOTES); ?></code></td>
												<td>
													<?php echo htmlspecialchars((string) $rCamd['ca_system'], ENT_QUOTES); ?>
													<?php if (trim((string) $rCamd['caid']) !== ''): ?>
														<br><small class="text-muted">CAID <?php echo htmlspecialchars((string) $rCamd['caid'], ENT_QUOTES); ?></small>
													<?php endif; ?>
												</td>
												<td class="text-center"><?php echo (int) $rCamd['service_count']; ?></td>
												<td class="text-center">
													<?php if (!empty($rCamd['enabled'])): ?>
														<span class="badge badge-success">Enabled</span>
													<?php else: ?>
														<span class="badge badge-secondary">Disabled</span>
													<?php endif; ?>
												</td>
												<td class="text-right text-nowrap">
													<button class="btn btn-sm btn-outline-info camd-probe" data-id="<?php echo (int) $rCamd['id']; ?>" title="Test TCP reachability">
														<i class="mdi mdi-lan-connect"></i>
													</button>
													<button class="btn btn-sm btn-outline-secondary camd-toggle" data-id="<?php echo (int) $rCamd['id']; ?>" title="Enable or disable">
														<i class="mdi mdi-power"></i>
													</button>
													<a href="./dvb_camd?id=<?php echo (int) $rCamd['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
														<i class="mdi mdi-pencil"></i>
													</a>
													<form method="post" action="./dvb_camd?id=<?php echo (int) $rCamd['id']; ?>" class="d-inline"
														onsubmit="return confirm('Delete this card server? Its channels keep working but stop being decrypted.');">
														<input type="hidden" name="do" value="delete">
														<button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
															<i class="mdi mdi-delete"></i>
														</button>
													</form>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php endif; ?>

						<div class="alert alert-secondary mt-3 mb-0">
							<h5 class="mt-0">How decryption is wired</h5>
							<p class="mb-1">
								DVBlast pulls the encrypted service off the tuner and writes it to a private
								loopback port. <code>tsdecrypt</code> reads that, sends the ECMs to this card
								server, and writes the clear stream to the port the panel channel already
								reads. Assigning or removing a card server therefore never changes the
								source of a channel.
							</p>
							<p class="mb-0">
								The tuner node needs <code>tsdecrypt</code> installed. One process runs per
								encrypted channel, so a line with a session limit will notice.
							</p>
						</div>
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
	$(function() {
		// The DES key only means anything to NEWCAMD, and leaving it visible
		// under CS378X invites someone to fill it in and wonder why nothing
		// changes.
		function syncDesKey() {
			$('#camd-deskey-group').toggle($('#camd-protocol').val() === 'NEWCAMD');
		}

		$('#camd-protocol').on('change', syncDesKey);
		syncDesKey();

		$('.camd-probe').on('click', function() {
			var button = $(this);
			var icon = button.find('i');

			button.prop('disabled', true);
			icon.attr('class', 'mdi mdi-loading mdi-spin');

			$.post('./api?action=dvb_camd', { sub: 'probe', id: button.data('id') }, function(response) {
				button.prop('disabled', false);
				icon.attr('class', 'mdi mdi-lan-connect');

				if (response.result) {
					$.toast({ text: response.note, icon: 'success', position: 'top-right' });
				} else {
					$.toast({ text: response.error, icon: 'error', position: 'top-right' });
				}
			}, 'json').fail(function() {
				button.prop('disabled', false);
				icon.attr('class', 'mdi mdi-lan-connect');
				$.toast({ text: 'The reachability test could not be run.', icon: 'error', position: 'top-right' });
			});
		});

		$('.camd-toggle').on('click', function() {
			$.post('./api?action=dvb_camd', { sub: 'toggle', id: $(this).data('id') }, function(response) {
				if (response.result) {
					$.toast({ text: response.note, icon: 'success', position: 'top-right' });
					setTimeout(function() { window.location.reload(); }, 600);
				} else {
					$.toast({ text: response.error, icon: 'error', position: 'top-right' });
				}
			}, 'json').fail(function(rXHR) {
				$.toast({ text: 'Request failed (' + rXHR.status + ').', icon: 'error', position: 'top-right' });
			});
		});
	});
</script>
</body>

</html>
