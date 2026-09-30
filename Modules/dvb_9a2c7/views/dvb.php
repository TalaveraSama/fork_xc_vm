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
					<h4 class="page-title">DVB Tuners</h4>
				</div>
			</div>
		</div>
		<?php
		$rTotalTransponders = count($rTransponders);
		$rLocked = 0;
		$rServicesFound = 0;
		$rTotalAdapters = 0;

		foreach ($rTransponders as $rRow) {
			if ($rRow['scan_status'] === 'ok') {
				$rLocked++;
			}

			$rServicesFound += (int) $rRow['service_count'];
		}

		foreach ($rAdapters as $rServerAdapters) {
			$rTotalAdapters += count($rServerAdapters);
		}
		?>
		<div class="row">
			<div class="col-md-3">
				<div class="card widget-flat">
					<div class="card-body">
						<div class="float-right"><i class="mdi mdi-satellite-uplink widget-icon"></i></div>
						<h5 class="text-muted font-weight-normal mt-0" title="Tuners">Tuners detected</h5>
						<h3 class="mt-3 mb-3"><?php echo $rTotalAdapters; ?></h3>
					</div>
				</div>
			</div>
			<div class="col-md-3">
				<div class="card widget-flat">
					<div class="card-body">
						<div class="float-right"><i class="mdi mdi-access-point widget-icon"></i></div>
						<h5 class="text-muted font-weight-normal mt-0" title="Transponders">Transponders</h5>
						<h3 class="mt-3 mb-3"><?php echo $rTotalTransponders; ?></h3>
					</div>
				</div>
			</div>
			<div class="col-md-3">
				<div class="card widget-flat">
					<div class="card-body">
						<div class="float-right"><i class="mdi mdi-check-network widget-icon"></i></div>
						<h5 class="text-muted font-weight-normal mt-0" title="Scanned">Scanned OK</h5>
						<h3 class="mt-3 mb-3"><?php echo $rLocked; ?></h3>
					</div>
				</div>
			</div>
			<div class="col-md-3">
				<div class="card widget-flat">
					<div class="card-body">
						<div class="float-right"><i class="mdi mdi-television-classic widget-icon"></i></div>
						<h5 class="text-muted font-weight-normal mt-0" title="Services">Services found</h5>
						<h3 class="mt-3 mb-3"><?php echo $rServicesFound; ?></h3>
					</div>
				</div>
			</div>
		</div>

		<?php if ($rTotalAdapters === 0): ?>
			<div class="alert alert-warning">
				<strong>No tuners detected yet.</strong>
				Pick the server that holds the card and press <em>Discover adapters</em>.
				If nothing appears, the driver is not loaded on that node &mdash; check
				<code>lspci | grep -i tbs</code> and <code>dmesg | grep -i frontend</code>.
			</div>
		<?php endif; ?>

		<div class="row">
			<div class="col-12">
				<div class="card">
					<div class="card-body">
						<div class="row mb-2">
							<div class="col-sm-7">
								<a href="dvb_transponder" class="btn btn-danger mb-2">
									<i class="mdi mdi-plus-circle mr-2"></i>Add Transponder
								</a>
								<a href="dvb_adapters" class="btn btn-secondary mb-2">
									<i class="mdi mdi-chip mr-2"></i>Adapters
								</a>
							</div>
							<div class="col-sm-5 text-sm-right">
								<div class="form-inline justify-content-sm-end">
									<select id="discover_server" class="form-control mb-2 mr-2">
										<?php foreach ($rAdapters as $rServerID => $rServerAdapters): ?>
											<option value="<?php echo (int) $rServerID; ?>">
												<?php echo htmlspecialchars((string) ($rServers[$rServerID]['server_name'] ?? ('Server ' . $rServerID)), ENT_QUOTES); ?>
												(<?php echo count($rServerAdapters); ?>)
											</option>
										<?php endforeach; ?>
									</select>
									<button type="button" class="btn btn-primary mb-2" onclick="dvbDiscover();">
										<i class="mdi mdi-magnify mr-1"></i>Discover adapters
									</button>
									<button type="button" class="btn btn-danger ml-1" onclick="dvbStopAll();"
										title="Stops every carrier on this node and kills anything of ours still holding a tuner. Use when a frontend is stuck as busy.">
										<i class="mdi mdi-stop-circle-outline mr-1"></i>Stop everything
									</button>
								</div>
							</div>
						</div>

						<div class="table-responsive">
							<table class="table table-centered table-hover mb-0" id="datatable">
								<thead class="thead-light">
									<tr>
										<th class="text-center">ID</th>
										<th class="text-center"></th>
										<th>Name</th>
										<th>Satellite</th>
										<th>Tuning</th>
										<th>Server</th>
										<th class="text-center">Signal</th>
										<th class="text-center">Services</th>
										<th class="text-center">Streaming</th>
										<th>Last scan</th>
										<th class="text-center">Actions</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($rTransponders as $rRow):
										$rID = (int) $rRow['id'];
										$rSat = in_array(strtoupper((string) $rRow['delivery_system']), ['DVBS', 'DVBS2'], true);

										if (!$rRow['enabled']) {
											$rStatusIcon = '<i class="text-secondary fas fa-square" data-toggle="tooltip" title="Disabled"></i>';
										} elseif ($rRow['scan_status'] === 'ok') {
											$rStatusIcon = '<i class="text-success fas fa-square" data-toggle="tooltip" title="' . htmlspecialchars((string) $rRow['scan_message'], ENT_QUOTES) . '"></i>';
										} elseif ($rRow['scan_status'] === 'scanning') {
											$rStatusIcon = '<i class="text-warning fas fa-sync fa-spin" data-toggle="tooltip" title="Scanning"></i>';
										} elseif ($rRow['scan_status'] === 'error') {
											$rStatusIcon = '<i class="text-danger fas fa-square" data-toggle="tooltip" title="' . htmlspecialchars((string) $rRow['scan_message'], ENT_QUOTES) . '"></i>';
										} else {
											$rStatusIcon = '<i class="text-muted fas fa-square" data-toggle="tooltip" title="Never scanned"></i>';
										}

										if ($rSat) {
											$rTuning = sprintf(
												'%s MHz %s &middot; %s kS/s',
												number_format($rRow['frequency'] / 1000, 0),
												htmlspecialchars((string) $rRow['polarization'], ENT_QUOTES),
												number_format($rRow['symbol_rate'] / 1000, 0)
											);
										} else {
											$rTuning = number_format($rRow['frequency'] / 1000000, 3) . ' MHz';
										}
									?>
										<tr id="dvb-<?php echo $rID; ?>">
											<td class="text-center"><?php echo $rID; ?></td>
											<td class="text-center"><?php echo $rStatusIcon; ?></td>
											<td>
												<a href="dvb_transponder?id=<?php echo $rID; ?>">
													<?php echo htmlspecialchars((string) $rRow['name'], ENT_QUOTES); ?>
												</a>
												<br><small class="text-muted"><?php echo htmlspecialchars((string) $rRow['delivery_system'], ENT_QUOTES); ?></small>
											</td>
											<td><?php echo htmlspecialchars((string) $rRow['satellite'], ENT_QUOTES); ?></td>
											<td><small><?php echo $rTuning; ?></small></td>
											<td>
												<small><?php echo htmlspecialchars((string) ($rServers[(int) $rRow['server_id']]['server_name'] ?? ('#' . (int) $rRow['server_id'])), ENT_QUOTES); ?></small>
											</td>
											<td class="text-center">
												<?php // Two bars beat one number: strength and quality fail for
												// different reasons, and someone aiming a dish needs to watch both
												// move. Refreshed in place by dvbBars(). ?>
												<div class="dvb-bars" data-id="<?php echo (int) $rRow['id']; ?>"
												data-live="<?php echo !empty($rRow['streaming']) ? 1 : 0; ?>"
												style="min-width:130px;">
													<?php foreach ([['s', 'signal_strength', 'S'], ['q', 'signal_quality', 'Q']] as $rBar): ?>
														<?php $rVal = $rRow[$rBar[1]]; ?>
														<div class="d-flex align-items-center mb-1">
															<small class="text-muted mr-1" style="width:10px;"><?php echo $rBar[2]; ?></small>
															<div class="progress flex-grow-1" style="height:8px;">
																<div class="progress-bar dvb-bar-<?php echo $rBar[0]; ?>" role="progressbar"
																	style="width:<?php echo $rVal === null ? 0 : (int) $rVal; ?>%;"></div>
															</div>
															<small class="ml-1 dvb-val-<?php echo $rBar[0]; ?>" style="width:36px;">
																<?php echo $rVal === null ? '&mdash;' : ((int) $rVal . '%'); ?>
															</small>
														</div>
													<?php endforeach; ?>
												</div>
											</td>
											<td class="text-center">
												<?php if ((int) $rRow['service_count'] > 0): ?>
													<a href="dvb_services?transponder_id=<?php echo $rID; ?>" class="badge badge-info">
														<?php echo (int) $rRow['service_count']; ?>
													</a>
												<?php else: ?>
													<span class="text-muted">0</span>
												<?php endif; ?>
											</td>
											<td class="text-center">
												<?php if ($rRow['stream_status'] === 'running'): ?>
													<span class="badge badge-success" title="<?php echo htmlspecialchars((string) $rRow['stream_message'], ENT_QUOTES); ?>">on air</span>
												<?php elseif ($rRow['stream_status'] === 'error'): ?>
													<span class="badge badge-danger">error</span>
												<?php if (trim((string) $rRow['stream_message']) !== ''): ?>
													<?php // A tooltip is where a reason goes to die. The supervisor
													// already knows exactly why this carrier will not start, so
													// say it on the page. ?>
													<div class="small text-danger mt-1" style="white-space:normal;max-width:320px;">
														<?php echo htmlspecialchars((string) $rRow['stream_message'], ENT_QUOTES); ?>
													</div>
												<?php endif; ?>
												<?php else: ?>
													<span class="badge badge-secondary">off</span>
												<?php endif; ?>
											</td>
											<td>
												<small><?php echo $rRow['last_scan'] ? date('Y-m-d H:i', (int) $rRow['last_scan']) : 'never'; ?></small>
											</td>
											<td class="text-center">
												<button type="button" class="btn btn-sm btn-primary" title="Scan now" onclick="dvbScan(<?php echo $rID; ?>);">
													<i class="mdi mdi-radar"></i>
												</button>
												<button type="button" class="btn btn-sm btn-info" title="Live signal meter" onclick="dvbSignal(<?php echo $rID; ?>, '<?php echo htmlspecialchars((string) $rRow['name'], ENT_QUOTES); ?>');">
													<i class="mdi mdi-signal-variant"></i>
												</button>
												<?php if ((int) $rRow['service_count'] > 0): ?>
													<?php if (!empty($rRow['streaming'])): ?>
														<button type="button" class="btn btn-sm btn-warning" title="Stop streaming" onclick="dvbStream(<?php echo $rID; ?>, 'stop');">
															<i class="mdi mdi-stop"></i>
														</button>
													<?php else: ?>
														<button type="button" class="btn btn-sm btn-success" title="Start streaming" onclick="dvbStream(<?php echo $rID; ?>, 'start');">
															<i class="mdi mdi-play"></i>
														</button>
													<?php endif; ?>
												<?php endif; ?>
												<button type="button" class="btn btn-sm btn-secondary" title="Show tuning file" onclick="dvbPreview(<?php echo $rID; ?>);">
													<i class="mdi mdi-file-document-outline"></i>
												</button>
												<button type="button" class="btn btn-sm btn-danger" title="Delete" onclick="dvbDelete(<?php echo $rID; ?>);">
													<i class="mdi mdi-delete"></i>
												</button>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<div id="dvb-meter" class="card border mt-3" style="display:none;">
							<div class="card-body">
								<div class="d-flex justify-content-between align-items-center mb-2">
									<h5 class="mb-0">Signal &mdash; <span id="dvb-meter-name"></span></h5>
									<div>
										<span id="dvb-meter-lock" class="badge badge-secondary mr-2">waiting</span>
										<button type="button" class="btn btn-sm btn-secondary" onclick="dvbSignalStop();">Close</button>
									</div>
								</div>
								<div class="mb-1"><small class="text-muted">Signal strength <span id="dvb-meter-slabel"></span></small></div>
								<div class="progress mb-3" style="height:18px;">
									<div id="dvb-meter-sbar" class="progress-bar" role="progressbar" style="width:0%;">0%</div>
								</div>
								<div class="mb-1"><small class="text-muted">Quality (C/N) <span id="dvb-meter-qlabel"></span></small></div>
								<div class="progress mb-3" style="height:18px;">
									<div id="dvb-meter-qbar" class="progress-bar" role="progressbar" style="width:0%;">0%</div>
								</div>
								<small class="text-muted" id="dvb-meter-detail"></small>
							</div>
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
	// $.toast() is the panel's notifier (jquery-toast, loaded by the admin
	// footer). toastr is a different library and is not shipped here at all.
	function dvbNotify(rMessage, rIcon) {
		if (!rMessage) {
			return;
		}
		$.toast({ text: rMessage, icon: rIcon || 'info', position: 'top-right' });
	}

	// ---- live signal meter -------------------------------------------------
	// Polled rather than streamed: each sample tunes the frontend for a few
	// seconds and releases it, so the tuner is never held open by an idle
	// browser tab. dvbSignalStop() clears the timer, and the reply handler
	// checks the generation counter so a late response from a previous
	// transponder cannot repaint the bars after you switched.
	var dvbMeterTimer = null;
	var dvbMeterGen = 0;

	function dvbSignalStop() {
		dvbMeterGen++;
		if (dvbMeterTimer) {
			clearTimeout(dvbMeterTimer);
			dvbMeterTimer = null;
		}
		$('#dvb-meter').hide();
	}

	function dvbMeterBar(rSel, rValue, rLabel) {
		var rPct = (rValue === null || rValue === undefined) ? 0 : Math.max(0, Math.min(100, rValue));
		var rClass = 'progress-bar ' + (rPct >= 60 ? 'bg-success' : (rPct >= 30 ? 'bg-warning' : 'bg-danger'));
		$(rSel).attr('class', rClass).css('width', rPct + '%').text(rPct + '%');
		return rLabel;
	}

	function dvbSignalPoll(rID) {
		var rGen = dvbMeterGen;

		$.post('./api?action=dvb_signal', { id: rID }, function(rData) {
			if (rGen !== dvbMeterGen) {
				return;
			}

			if (!rData.result) {
				$('#dvb-meter-lock').attr('class', 'badge badge-danger mr-2').text('error');
				var rMsg = rData.error || 'Unknown error.';
				if (rData.log) {
					rMsg += '\n\n--- dvbv5-zap said ---\n' + rData.log;
				}
				$('#dvb-meter-detail').css('white-space', 'pre-wrap').text(rMsg);
				return;
			}

			$('#dvb-meter-lock')
				.attr('class', 'badge mr-2 ' + (rData.locked ? 'badge-success' : 'badge-danger'))
				.text(rData.locked ? 'LOCKED' : 'no lock');

			dvbMeterBar('#dvb-meter-sbar', rData.strength);
			dvbMeterBar('#dvb-meter-qbar', rData.quality);

			$('#dvb-meter-slabel').text(rData.dbm !== null && rData.dbm !== undefined ? '(' + rData.dbm + ' dBm)' : '');
			$('#dvb-meter-qlabel').text(rData.cnr !== null && rData.cnr !== undefined ? '(' + rData.cnr + ' dB)' : '');

			var rBits = [rData.adapter];
			if (rData.ber !== null && rData.ber !== undefined) { rBits.push('postBER ' + rData.ber); }
			if (rData.ucb !== null && rData.ucb !== undefined) { rBits.push('UCB ' + rData.ucb); }
			if (!rData.locked && rData.strength >= 40) {
				rBits.push('carrier present but no lock \u2014 suspect symbol rate, FEC, modulation or polarization rather than the dish');
			}
			if (!rData.locked && rData.strength < 40) {
				rBits.push('almost no carrier \u2014 suspect LNB power, cabling, DiSEqC port or dish alignment');
			}
			$('#dvb-meter-detail').text(rBits.join(' \u00b7 '));

			// Chained, never on a fixed interval, and unhurried: the request
			// behind it holds a PHP-FPM worker for about four seconds while
			// it reads the demodulator, so a tight loop here competes with
			// the rest of the panel for the pool.
			dvbMeterTimer = setTimeout(function() { dvbSignalPoll(rID); }, 3000);
		}, 'json').fail(function(rXHR) {
			if (rGen !== dvbMeterGen) {
				return;
			}
			$('#dvb-meter-lock').attr('class', 'badge badge-danger mr-2').text('error');
			$('#dvb-meter-detail').text('Request failed (' + rXHR.status + ').');
		});
	}

	function dvbSignal(rID, rName) {
		dvbSignalStop();
		$('#dvb-meter-name').text(rName || ('#' + rID));
		$('#dvb-meter-lock').attr('class', 'badge badge-secondary mr-2').text('measuring...');
		$('#dvb-meter-detail').text('Tuning the carrier, this takes a few seconds.');
		$('#dvb-meter-slabel').text('');
		$('#dvb-meter-qlabel').text('');
		dvbMeterBar('#dvb-meter-sbar', 0);
		dvbMeterBar('#dvb-meter-qbar', 0);
		$('#dvb-meter').show();
		dvbSignalPoll(rID);
	}

	function dvbDiscover() {
		$.post('./api?action=dvb_discover', {
			server_id: $('#discover_server').val()
		}, function(rData) {
			if (!rData.result) {
				dvbNotify(rData.error);
				return;
			}
			dvbNotify(rData.note);
			dvbPoll(rData.job_id);
		}, 'json').fail(function(rXHR) {
			dvbNotify('Request failed (' + rXHR.status + '). Check the browser console and the panel log.', 'error');
		});
	}

	function dvbScan(rID) {
		$.post('./api?action=dvb_scan', {
			id: rID
		}, function(rData) {
			if (!rData.result) {
				dvbNotify(rData.error);
				return;
			}
			dvbNotify(rData.note);
			dvbPoll(rData.job_id);
		}, 'json').fail(function(rXHR) {
			dvbNotify('Request failed (' + rXHR.status + '). Check the browser console and the panel log.', 'error');
		});
	}

	// The tuner node picks work up on its cron tick, so the first few polls
	// always come back "pending". Poll every 5s for up to 5 minutes, which
	// comfortably covers a slow scan, then stop rather than hammering forever.
	function dvbPoll(rJobID, rTries) {
		rTries = (rTries || 0) + 1;

		if (rTries > 60) {
			dvbNotify('Still running. Reload the page in a moment to see the result.');
			return;
		}

		setTimeout(function() {
			$.post('./api?action=dvb_job', {
				job_id: rJobID
			}, function(rData) {
				if (!rData.result) {
					return;
				}
				if (rData.finished) {
					dvbNotify(rData.message);
					location.reload();
					return;
				}
				dvbPoll(rJobID, rTries);
			}, 'json').fail(function(rXHR) {
				dvbNotify('Request failed (' + rXHR.status + '). Check the browser console and the panel log.', 'error');
			});
		}, 5000);
	}

	function dvbStream(rID, rSub) {
		$.post('./api?action=dvb_stream', {
			id: rID,
			sub: rSub
		}, function(rData) {
			if (!rData.result) {
				dvbNotify(rData.error);
				return;
			}
			dvbNotify(rData.note);
			dvbPoll(rData.job_id);
		}, 'json').fail(function(rXHR) {
			dvbNotify('Request failed (' + rXHR.status + '). Check the browser console and the panel log.', 'error');
		});
	}

	function dvbPreview(rID) {
		$.post('./api?action=dvb_transponder', {
			id: rID,
			sub: 'preview'
		}, function(rData) {
			if (!rData.result) {
				dvbNotify(rData.error);
				return;
			}
			alert(rData.preview);
		}, 'json').fail(function(rXHR) {
			dvbNotify('Request failed (' + rXHR.status + '). Check the browser console and the panel log.', 'error');
		});
	}

	function dvbDelete(rID) {
		if (!confirm('Delete this transponder and the services found on it? Channels already imported are kept.')) {
			return;
		}

		$.post('./api?action=dvb_transponder', {
			id: rID,
			sub: 'delete'
		}, function() {
			location.reload();
		}, 'json').fail(function(rXHR) {
			dvbNotify('Request failed (' + rXHR.status + '). Check the browser console and the panel log.', 'error');
		});
	}
	// Refresh the list's bars from stored readings only.
	//
	// The first version of this polled the live meter once per carrier. That
	// endpoint shells out to dvb-fe-tool and blocks for four seconds, so a
	// handful of carriers held a PHP-FPM worker each, the pool ran dry and
	// nginx answered 502 across the whole panel. Sampling belongs in cron:dvb,
	// which already runs every minute; this is one cheap query for every row.
	function dvbBars() {
		if ($('.dvb-bars').length === 0) {
			return;
		}

		$.getJSON('./api?action=dvb_signal_cache', function(rData) {
			if (!rData || !rData.result || !rData.levels) {
				return;
			}

			$('.dvb-bars').each(function() {
				var rBox = $(this);
				var rRow = rData.levels[rBox.data('id')];

				if (!rRow) {
					return;
				}

				$.each({ s: rRow.strength, q: rRow.quality }, function(rKey, rVal) {
					if (rVal === null || rVal === undefined) {
						return;
					}

					rBox.find('.dvb-bar-' + rKey).css('width', rVal + '%');
					rBox.find('.dvb-val-' + rKey).text(rVal + '%');
				});
			});
		}).always(function() {
			setTimeout(dvbBars, 20000);
		});
	}

	$(function() { setTimeout(dvbBars, 3000); });
	// A tuner left busy by a lost pid file cannot be recovered from the normal
	// stop, which only marks the row and waits for cron. This is the blunt
	// version: mark everything stopped, then signal anything still running out
	// of our work directory.
	function dvbStopAll() {
		new jBox('Confirm', {
			confirmButton: 'Stop everything',
			cancelButton: 'Cancel',
			content: 'Stop every transponder on this node and kill any process still holding a tuner?',
			confirm: function() {
				$.post('./api?action=dvb_stop_all', {}, function(rData) {
					dvbNotify(rData.note || rData.error, rData.result ? 'success' : 'error');
					setTimeout(function() { location.reload(); }, 1500);
				}, 'json').fail(function(rXHR) {
					dvbNotify('Request failed (' + rXHR.status + ').', 'error');
				});
			}
		}).open();
	}
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
