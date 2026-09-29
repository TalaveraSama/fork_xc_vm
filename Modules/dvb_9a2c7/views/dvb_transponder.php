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
					<h4 class="page-title"><?php echo htmlspecialchars($_TITLE, ENT_QUOTES); ?></h4>
				</div>
			</div>
		</div>

		<?php foreach ($rErrors as $rError): ?>
			<div class="alert alert-danger"><?php echo htmlspecialchars((string) $rError, ENT_QUOTES); ?></div>
		<?php endforeach; ?>

		<?php foreach ($rNotes as $rNote): ?>
			<div class="alert alert-info"><?php echo htmlspecialchars((string) $rNote, ENT_QUOTES); ?></div>
		<?php endforeach; ?>

		<form method="post" action="dvb_transponder<?php echo ((int) $rTransponder['id'] > 0) ? ('?id=' . (int) $rTransponder['id']) : ''; ?>">
			<div class="row">
				<div class="col-md-6">
					<div class="card">
						<div class="card-body">
							<h5 class="card-title mb-3">Where</h5>

							<div class="form-group">
								<label>Server holding the tuner card</label>
								<select name="server_id" class="form-control" required>
									<option value="">&mdash; pick a server &mdash;</option>
									<?php foreach ($rAdapters as $rServerID => $rServerAdapters): ?>
										<option value="<?php echo (int) $rServerID; ?>" <?php echo ((int) $rTransponder['server_id'] === (int) $rServerID) ? 'selected' : ''; ?>>
											<?php echo htmlspecialchars((string) ($rServers[$rServerID]['server_name'] ?? ('Server ' . $rServerID)), ENT_QUOTES); ?>
											&mdash; <?php echo count($rServerAdapters); ?> tuner(s)
										</option>
									<?php endforeach; ?>
								</select>
								<small class="form-text text-muted">
									The panel itself has no card. Register the machine with the TBS card as an
									XC_VM streaming server first, then pick it here.
								</small>
							</div>

							<div class="form-group">
								<label>Tuner</label>
								<select name="adapter_id" class="form-control">
									<option value="">Any free tuner on that server</option>
									<?php foreach ($rAdapters as $rServerID => $rServerAdapters): ?>
										<?php foreach ($rServerAdapters as $rAdapter): ?>
											<option value="<?php echo (int) $rAdapter['id']; ?>" <?php echo ((int) $rTransponder['adapter_id'] === (int) $rAdapter['id']) ? 'selected' : ''; ?>>
												adapter<?php echo (int) $rAdapter['adapter_num']; ?>/frontend<?php echo (int) $rAdapter['frontend_num']; ?>
												<?php echo $rAdapter['name'] !== '' ? (' — ' . htmlspecialchars((string) $rAdapter['name'], ENT_QUOTES)) : ''; ?>
											</option>
										<?php endforeach; ?>
									<?php endforeach; ?>
								</select>
								<small class="form-text text-muted">
									Pin a tuner when only some inputs on the card are cabled to the satellite
									you want. A pinned tuner is always used, even if busy.
								</small>
							</div>

							<div class="form-group">
								<label>Name</label>
								<input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars((string) $rTransponder['name'], ENT_QUOTES); ?>" placeholder="left blank = 11778 H 27500">
							</div>

							<div class="form-group">
								<label>Satellite / source label</label>
								<input type="text" name="satellite" class="form-control" value="<?php echo htmlspecialchars((string) $rTransponder['satellite'], ENT_QUOTES); ?>" placeholder="Hispasat 30W-6">
							</div>

							<div class="custom-control custom-checkbox">
								<input type="checkbox" class="custom-control-input" id="enabled" name="enabled" value="1" <?php echo !empty($rTransponder['enabled']) ? 'checked' : ''; ?>>
								<label class="custom-control-label" for="enabled">Enabled</label>
							</div>
						</div>
					</div>
				</div>

				<div class="col-md-6">
					<div class="card">
						<div class="card-body">
							<h5 class="card-title mb-3">Tuning</h5>

							<div class="form-group">
								<label>Delivery system</label>
								<select name="delivery_system" class="form-control">
									<?php foreach ($rDeliverySystems as $rSystem => $rUnit): ?>
										<option value="<?php echo htmlspecialchars($rSystem, ENT_QUOTES); ?>" <?php echo ((string) $rTransponder['delivery_system'] === $rSystem) ? 'selected' : ''; ?>>
											<?php echo htmlspecialchars($rSystem, ENT_QUOTES); ?> (frequency in <?php echo $rUnit; ?>)
										</option>
									<?php endforeach; ?>
								</select>
							</div>

							<div class="form-group">
								<label>Frequency</label>
								<input type="text" name="frequency" class="form-control" value="<?php echo htmlspecialchars((string) $rTransponder['frequency'], ENT_QUOTES); ?>" placeholder="11778000" required>
								<small class="form-text text-muted">
									Satellite: the downlink frequency in kHz (11778000 for 11778 MHz). Type it in
									MHz and it will be converted for you. Terrestrial and cable: Hz.
								</small>
							</div>

							<div class="form-row">
								<div class="form-group col-md-6">
									<label>Polarization</label>
									<select name="polarization" class="form-control">
										<?php foreach (['H' => 'Horizontal', 'V' => 'Vertical', 'L' => 'Circular left', 'R' => 'Circular right'] as $rKey => $rLabel): ?>
											<option value="<?php echo $rKey; ?>" <?php echo ((string) $rTransponder['polarization'] === $rKey) ? 'selected' : ''; ?>><?php echo $rLabel; ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="form-group col-md-6">
									<label>Symbol rate</label>
									<input type="text" name="symbol_rate" class="form-control" value="<?php echo htmlspecialchars((string) $rTransponder['symbol_rate'], ENT_QUOTES); ?>" placeholder="27500000">
									<small class="form-text text-muted">Sym/s. 27500 is read as 27500000.</small>
								</div>
							</div>

							<div class="form-row">
								<div class="form-group col-md-4">
									<label>Modulation</label>
									<select name="modulation" class="form-control">
										<?php foreach (['QPSK', 'PSK/8', 'APSK/16', 'APSK/32', 'QAM/64', 'QAM/256', 'QAM/AUTO'] as $rMod): ?>
											<option value="<?php echo $rMod; ?>" <?php echo ((string) $rTransponder['modulation'] === $rMod) ? 'selected' : ''; ?>><?php echo $rMod; ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="form-group col-md-4">
									<label>FEC</label>
									<select name="inner_fec" class="form-control">
										<?php foreach (['AUTO', '1/2', '2/3', '3/4', '3/5', '4/5', '5/6', '7/8', '8/9', '9/10'] as $rFec): ?>
											<option value="<?php echo $rFec; ?>" <?php echo ((string) $rTransponder['inner_fec'] === $rFec) ? 'selected' : ''; ?>><?php echo $rFec; ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="form-group col-md-4">
									<label>Roll-off</label>
									<select name="rolloff" class="form-control">
										<?php foreach (['AUTO', '35', '25', '20'] as $rRoll): ?>
											<option value="<?php echo $rRoll; ?>" <?php echo ((string) $rTransponder['rolloff'] === $rRoll) ? 'selected' : ''; ?>><?php echo $rRoll === 'AUTO' ? 'AUTO' : ('0.' . $rRoll); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-row">
								<div class="form-group col-md-6">
									<label>LNB</label>
									<select name="lnb_type" class="form-control">
										<?php foreach (['UNIVERSAL', 'EXTENDED', 'STANDARD', 'L10700', 'L10750', 'L11300', 'C-BAND', 'C-MULT'] as $rLnb): ?>
											<option value="<?php echo $rLnb; ?>" <?php echo ((string) $rTransponder['lnb_type'] === $rLnb) ? 'selected' : ''; ?>><?php echo $rLnb; ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="form-group col-md-6">
									<label>DiSEqC port</label>
									<select name="diseqc" class="form-control">
										<?php foreach ([0 => 'None (single LNB)', 1 => 'Port 1 / A', 2 => 'Port 2 / B', 3 => 'Port 3 / C', 4 => 'Port 4 / D'] as $rPort => $rLabel): ?>
											<option value="<?php echo $rPort; ?>" <?php echo ((int) $rTransponder['diseqc'] === $rPort) ? 'selected' : ''; ?>><?php echo $rLabel; ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group">
								<label>Input Stream Id (multistream / DVB-S2X)</label>
								<input type="text" name="isi" class="form-control" value="<?php echo ((int) $rTransponder['isi'] >= 0) ? (int) $rTransponder['isi'] : ''; ?>" placeholder="leave empty for a normal carrier">
							</div>
						</div>
					</div>
				</div>
			</div>

			<div class="row">
				<div class="col-12 mb-4">
					<button type="submit" class="btn btn-danger">
						<i class="mdi mdi-content-save mr-1"></i>Save
					</button>
					<button type="submit" name="scan_now" value="1" class="btn btn-primary">
						<i class="mdi mdi-radar mr-1"></i>Save and scan
					</button>
					<a href="dvb" class="btn btn-secondary">Cancel</a>
				</div>
			</div>
		</form>
	</div>
</div>
</body>

</html>
