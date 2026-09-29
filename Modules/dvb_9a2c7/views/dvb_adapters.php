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
					<h4 class="page-title">DVB Adapters</h4>
				</div>
			</div>
		</div>

		<div class="row">
			<div class="col-12">
				<div class="card">
					<div class="card-body">
						<div class="row mb-3">
							<div class="col-sm-12 text-sm-right">
								<a href="dvb" class="btn btn-secondary">
									<i class="mdi mdi-arrow-left mr-1"></i>Transponders
								</a>
							</div>
						</div>

						<?php $rAny = false; ?>
						<?php foreach ($rAdapters as $rServerID => $rServerAdapters): ?>
							<?php if (empty($rServerAdapters)) { continue; } ?>
							<?php $rAny = true; ?>

							<h5 class="mt-2 mb-2">
								<?php echo htmlspecialchars((string) ($rServers[$rServerID]['server_name'] ?? ('Server ' . $rServerID)), ENT_QUOTES); ?>
								<small class="text-muted">&mdash; <?php echo count($rServerAdapters); ?> frontend(s)</small>
							</h5>

							<div class="table-responsive mb-4">
								<table class="table table-sm table-centered table-hover mb-0">
									<thead class="thead-light">
										<tr>
											<th>Device node</th>
											<th>Card</th>
											<th>Delivery systems</th>
											<th>Bus</th>
											<th class="text-center">In use by</th>
											<th>Last seen</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($rServerAdapters as $rAdapter): ?>
											<tr>
												<td><code>/dev/dvb/adapter<?php echo (int) $rAdapter['adapter_num']; ?>/frontend<?php echo (int) $rAdapter['frontend_num']; ?></code></td>
												<td><?php echo htmlspecialchars((string) $rAdapter['name'], ENT_QUOTES); ?></td>
												<td><small><?php echo htmlspecialchars((string) $rAdapter['delivery_systems'], ENT_QUOTES); ?></small></td>
												<td><small><?php echo htmlspecialchars((string) $rAdapter['bus_info'], ENT_QUOTES); ?></small></td>
												<td class="text-center">
													<?php if (!empty($rAdapter['in_use_by'])): ?>
														<span class="badge badge-warning">transponder #<?php echo (int) $rAdapter['in_use_by']; ?></span>
													<?php else: ?>
														<span class="badge badge-success">free</span>
													<?php endif; ?>
												</td>
												<td><small><?php echo $rAdapter['last_seen'] ? date('Y-m-d H:i', (int) $rAdapter['last_seen']) : ''; ?></small></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php endforeach; ?>

						<?php if (!$rAny): ?>
							<div class="alert alert-info mb-0">
								<p class="mb-2"><strong>No tuners recorded yet.</strong> Press <em>Discover adapters</em> on the
								<a href="dvb">Transponders</a> page, choosing the server that holds the card.</p>
								<p class="mb-0">If discovery finds nothing, the driver is not loaded on that node. For a TBS card:</p>
								<pre class="mb-0 mt-2">lspci | grep -i tbs
ls /dev/dvb
dmesg | grep -i frontend</pre>
							</div>
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
</body>

</html>
