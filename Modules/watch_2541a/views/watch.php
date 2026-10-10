<?php

use XcVm\Module\Watch\WatchService;

/**
 * Watch Folders — listado de carpetas vigiladas.
 *
 * Endurecido a proposito: si la consulta falla (tabla ausente, columna que no
 * existe, error del planificador...) la pagina lo dice en pantalla en vez de
 * quedarse en blanco, y el boton "Add Folder" se renderiza siempre porque no
 * depende de la base de datos.
 */

$rWatchError = '';
$rWatchFolders = array();

try {
	if (class_exists(WatchService::class)) {
		$rWatchFolders = WatchService::getWatchFolders();
	} else {
		$rWatchError = 'WatchService no esta disponible (modulo no cargado).';
	}
} catch (\Throwable $rWatchException) {
	$rWatchError = get_class($rWatchException) . ': ' . $rWatchException->getMessage();
}

if (!is_array($rWatchFolders)) {
	$rWatchFolders = array();
}

?>
<?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) : ?>
	<div class="alert alert-success alert-dismissible fade show" role="alert">
		The folder is now being watched. It will be scanned during the next Watch Folder run.
		<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
	</div>
<?php endif; ?>

<?php if ($rWatchError !== '') : ?>
	<div class="alert alert-danger" role="alert">
		<strong>No se pudieron leer las carpetas vigiladas.</strong><br />
		<?= htmlspecialchars($rWatchError, ENT_QUOTES); ?>
	</div>
<?php endif; ?>

<div class="card">
	<div class="card-body pb-2">
		<div class="row align-items-center">
			<div class="col-md-7">
				<h4 class="header-title mb-1">Watch Folders</h4>
				<p class="sub-header mb-0">
					Directories on your streaming servers that are scanned automatically to import
					<strong>Movies</strong> and <strong>Series</strong> into the panel.
				</p>
			</div>
			<div class="col-md-5 text-right mt-2 mt-md-0">
				<a href="watch_add" class="btn btn-primary"><i class="mdi mdi-plus"></i> Add Folder</a>
				<a href="settings_watch" class="btn btn-light ml-1"><i class="mdi mdi-settings"></i> Settings</a>
				<a href="watch_output" class="btn btn-light ml-1"><i class="mdi mdi-format-list-bulleted"></i> Logs</a>
			</div>
		</div>
	</div>
	<div class="table-responsive">
		<table id="datatable" class="table table-striped table-borderless mb-0" style="width:100%">
			<thead>
				<tr>
					<th class="text-center">ID</th>
					<th class="text-center">Status</th>
					<th>Type</th>
					<th>Server Name</th>
					<th>Directory</th>
					<th class="text-center">Last Run</th>
					<th class="text-center">Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($rWatchFolders as $rFolder) :
					$rDate = ((int) ($rFolder['last_run'] ?? 0) > 0) ? date('Y-m-d H:i:s', (int) $rFolder['last_run']) : 'Never';
					$rTypeKey = (string) ($rFolder['type'] ?? '');
					$rTypeLabel = array('movie' => 'Movies', 'series' => 'Series', '1' => 'Movies', '2' => 'Series')[$rTypeKey] ?? ($rTypeKey !== '' ? ucfirst($rTypeKey) : '-');
					$rServerID = (int) ($rFolder['server_id'] ?? 0);
					$rServerName = $rServers[$rServerID]['server_name'] ?? ('Server #' . $rServerID);
					$rFolderID = (int) ($rFolder['id'] ?? 0);
				?>
					<tr id="folder-<?= $rFolderID; ?>">
						<td class="text-center"><?= $rFolderID; ?></td>
						<td class="text-center">
							<?php if (!empty($rFolder['active'])) : ?>
								<i class="fas fa-square text-success" data-toggle="tooltip" title="Active"></i>
							<?php else : ?>
								<i class="fas fa-square text-secondary" data-toggle="tooltip" title="Inactive"></i>
							<?php endif; ?>
						</td>
						<td><?= htmlspecialchars($rTypeLabel, ENT_QUOTES); ?></td>
						<td><?= htmlspecialchars((string) $rServerName, ENT_QUOTES); ?></td>
						<td><?= htmlspecialchars((string) ($rFolder['directory'] ?? ''), ENT_QUOTES); ?></td>
						<td class="text-center"><?= htmlspecialchars($rDate, ENT_QUOTES); ?></td>
						<td class="text-center text-nowrap">
							<a href="watch_add?id=<?= $rFolderID; ?>" class="btn btn-sm btn-outline-secondary" data-toggle="tooltip" title="Edit"><i class="mdi mdi-pencil"></i></a>
							<button type="button" class="btn btn-sm btn-outline-primary" onClick="api(<?= $rFolderID; ?>, 'force');" data-toggle="tooltip" title="Run now"><i class="mdi mdi-refresh"></i></button>
							<button type="button" class="btn btn-sm btn-outline-danger" onClick="api(<?= $rFolderID; ?>, 'delete');" data-toggle="tooltip" title="Delete"><i class="mdi mdi-close"></i></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
