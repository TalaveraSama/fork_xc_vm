<?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) : ?>
	<div class="alert alert-success alert-dismissible fade show" role="alert">
		The server is now being synced. It will be scanned during the next Plex Sync run.
		<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
	</div>
<?php endif; ?>

<div class="card">
	<div class="card-body pb-2">
		<div class="row align-items-center">
			<div class="col-md-7">
				<h4 class="header-title mb-1">Plex Libraries</h4>
				<p class="sub-header mb-0">Plex libraries that are scanned automatically to import content into the panel.</p>
			</div>
			<div class="col-md-5 text-right mt-2 mt-md-0">
				<a href="plex_add" class="btn btn-primary"><i class="mdi mdi-plus"></i> Add Library</a>
			</div>
		</div>
	</div>
	<div class="table-responsive">
		<table id="datatable" class="table table-striped table-borderless mb-0" style="width:100%">
			<thead>
				<tr>
					<th class="text-center">ID</th>
					<th class="text-center">Status</th>
					<th class="text-center">Plex IP</th>
					<th>Server Name</th>
					<th>Library</th>
					<th class="text-center">Last Run</th>
					<th class="text-center">Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ((is_array($rPlexServers ?? null) ? $rPlexServers : []) as $rServer) :
					$rDate = ($rServer['last_run'] > 0) ? date('Y-m-d H:i:s', $rServer['last_run']) : 'Never';
					$rLibraryName = 'Unknown';
					foreach ((array) json_decode($rServer['plex_libraries'], true) as $rLibrary) {
						if (intval($rLibrary['key']) == intval($rServer['directory'])) {
							$rLibraryName = $rLibrary['title'];
							break;
						}
					}
					$rServerAdd = is_null($rServer['server_add']) ? 0 : count((array) json_decode($rServer['server_add'], true));
				?>
					<tr id="folder-<?= intval($rServer['id']); ?>">
						<td class="text-center"><?= intval($rServer['id']); ?></td>
						<td class="text-center">
							<?php if ($rServer['active']) : ?>
								<i class="fas fa-square text-success"></i>
							<?php else : ?>
								<i class="fas fa-square text-secondary"></i>
							<?php endif; ?>
						</td>
						<td class="text-center"><?= $rServer['plex_ip']; ?></td>
						<td>
							<?= $rServers[$rServer['server_id']]['server_name']; ?>
							<?php if ($rServerAdd > 0) : ?>
								<span class="badge badge-info">+<?= $rServerAdd; ?></span>
							<?php endif; ?>
						</td>
						<td><?= $rLibraryName; ?></td>
						<td class="text-center"><?= $rDate; ?></td>
						<td class="text-center">
							<div class="d-inline-block">
								<a href="./plex_add?id=<?= intval($rServer['id']); ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="mdi mdi-pencil"></i></a>
								<button type="button" class="btn btn-sm btn-outline-secondary" onClick="api(<?= intval($rServer['id']); ?>, 'force');" title="Run now"><i class="mdi mdi-refresh"></i></button>
								<button type="button" class="btn btn-sm btn-outline-danger" onClick="api(<?= intval($rServer['id']); ?>, 'delete');" title="Delete"><i class="mdi mdi-close"></i></button>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
