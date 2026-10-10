<?php

use XcVm\Domain\Branding\BrandingService;

/**
 * Branding — logos, video del login, textos y bloque Support project.
 *
 * @var array<string,mixed> $rBranding
 * @var array<string,mixed> $rDefaults
 * @var string              $rMessage
 * @var string              $rError
 */

$rUrl = static function (string $rKey) use ($rBranding): string {
	return htmlspecialchars((string) ($rBranding[$rKey] ?? ''), ENT_QUOTES);
};

$rSupportRows = is_array($rBranding['support_rows'] ?? null) ? $rBranding['support_rows'] : array();
$rScopes = array(
	'admin'    => 'Solo administracion',
	'reseller' => 'Administracion + reseller',
	'all'      => 'Administracion + reseller + usuario',
);

?>
<?php if ($rMessage !== '') : ?>
	<div class="alert alert-success alert-dismissible fade show" role="alert">
		<?= htmlspecialchars($rMessage, ENT_QUOTES); ?>
		<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
	</div>
<?php endif; ?>

<?php if ($rError !== '') : ?>
	<div class="alert alert-danger alert-dismissible fade show" role="alert">
		<?= htmlspecialchars($rError, ENT_QUOTES); ?>
		<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
	</div>
<?php endif; ?>

<form method="post" action="branding" enctype="multipart/form-data" id="branding-form">
	<div class="card">
		<div class="card-body">
			<h4 class="header-title mb-1">Branding</h4>
			<p class="sub-header mb-0">
				Logotipos, video del login, textos y el bloque <strong>Support project</strong>.
				Todo se guarda en <code>config/branding.json</code> (no toca la base de datos) y los ficheros
				subidos van a <code>Public/assets/admin/</code>.
			</p>
		</div>
	</div>

	<div class="card">
		<div class="card-body">
			<h4 class="header-title mb-3">Panel</h4>
			<div class="form-group row">
				<label class="col-md-3 col-form-label" for="panel_name">Nombre del panel</label>
				<div class="col-md-5">
					<input type="text" class="form-control" id="panel_name" name="panel_name"
						value="<?= $rUrl('panel_name'); ?>" placeholder="XC_VM">
					<small class="text-muted">Vacio = XC_VM.</small>
				</div>
			</div>
			<div class="form-group row mb-0">
				<label class="col-md-3 col-form-label" for="footer_text">Texto del pie</label>
				<div class="col-md-7">
					<input type="text" class="form-control" id="footer_text" name="footer_text"
						value="<?= $rUrl('footer_text'); ?>" placeholder="&copy; 2026 Tu Marca. Todos los derechos reservados.">
					<small class="text-muted">Aparece en el pie del panel y en el del login. Vacio = el texto original del panel.</small>
				</div>
			</div>
		</div>
	</div>

	<div class="card">
		<div class="card-body">
			<h4 class="header-title mb-3">Logotipos</h4>

			<div class="form-group row">
				<label class="col-md-3 col-form-label" for="logo_login">Logo del login</label>
				<div class="col-md-5">
					<input type="text" class="form-control mb-2" id="logo_login" name="logo_login" value="<?= $rUrl('logo_login'); ?>">
					<input type="file" class="form-control-file" name="logo_login" accept=".png,.jpg,.jpeg,.gif,.svg,.webp">
					<small class="text-muted">PNG/JPG/SVG, max 4 MB. Subir un fichero tiene prioridad sobre la ruta.</small>
				</div>
				<div class="col-md-4">
					<img src="<?= $rUrl('logo_login'); ?>" alt="logo" style="max-height:60px; max-width:100%;" class="mb-2 d-block">
					<button type="submit" class="btn btn-sm btn-light" name="reset_field" value="logo_login" formnovalidate>
						<i class="mdi mdi-restore"></i> Restaurar
					</button>
				</div>
			</div>

			<div class="form-group row">
				<label class="col-md-3 col-form-label" for="logo_topbar">Logo de la barra superior</label>
				<div class="col-md-5">
					<input type="text" class="form-control mb-2" id="logo_topbar" name="logo_topbar" value="<?= $rUrl('logo_topbar'); ?>">
					<input type="file" class="form-control-file" name="logo_topbar" accept=".png,.jpg,.jpeg,.gif,.svg,.webp">
				</div>
				<div class="col-md-4">
					<img src="<?= $rUrl('logo_topbar'); ?>" alt="logo topbar" style="max-height:60px; max-width:100%;" class="mb-2 d-block">
					<button type="submit" class="btn btn-sm btn-light" name="reset_field" value="logo_topbar" formnovalidate>
						<i class="mdi mdi-restore"></i> Restaurar
					</button>
				</div>
			</div>

			<div class="form-group row mb-0">
				<label class="col-md-3 col-form-label" for="favicon">Favicon</label>
				<div class="col-md-5">
					<input type="text" class="form-control mb-2" id="favicon" name="favicon" value="<?= $rUrl('favicon'); ?>">
					<input type="file" class="form-control-file" name="favicon" accept=".ico,.png,.svg">
				</div>
				<div class="col-md-4">
					<img src="<?= $rUrl('favicon'); ?>" alt="favicon" style="max-height:32px;" class="mb-2 d-block">
					<button type="submit" class="btn btn-sm btn-light" name="reset_field" value="favicon" formnovalidate>
						<i class="mdi mdi-restore"></i> Restaurar
					</button>
				</div>
			</div>
		</div>
	</div>

	<div class="card">
		<div class="card-body">
			<h4 class="header-title mb-3">Pagina de login</h4>

			<div class="form-group row">
				<label class="col-md-3 col-form-label" for="login_video">Video de fondo</label>
				<div class="col-md-5">
					<input type="text" class="form-control mb-2" id="login_video" name="login_video" value="<?= $rUrl('login_video'); ?>">
					<input type="file" class="form-control-file" name="login_video" accept=".mp4,.webm">
					<small class="text-muted">MP4/WebM, max 64 MB. Un video pesado retrasa la carga del login.</small>
				</div>
				<div class="col-md-4">
					<?php if (preg_match('/\.(mp4|webm)$/i', (string) ($rBranding['login_video'] ?? ''))) : ?>
						<video src="<?= $rUrl('login_video'); ?>" controls muted style="max-height:90px; max-width:100%;" class="mb-2 d-block"></video>
					<?php endif; ?>
					<button type="submit" class="btn btn-sm btn-light" name="reset_field" value="login_video" formnovalidate>
						<i class="mdi mdi-restore"></i> Restaurar
					</button>
				</div>
			</div>

			<div class="form-group row">
				<label class="col-md-3 col-form-label" for="login_video_webm">Video WebM (opcional)</label>
				<div class="col-md-5">
					<input type="text" class="form-control" id="login_video_webm" name="login_video_webm" value="<?= $rUrl('login_video_webm'); ?>">
					<small class="text-muted">Segunda fuente para navegadores sin MP4.</small>
				</div>
			</div>

			<div class="form-group row">
				<label class="col-md-3 col-form-label" for="login_image">Imagen de fondo (alternativa)</label>
				<div class="col-md-5">
					<input type="text" class="form-control mb-2" id="login_image" name="login_image" value="<?= $rUrl('login_image'); ?>">
					<input type="file" class="form-control-file" name="login_image" accept=".png,.jpg,.jpeg,.webp">
					<small class="text-muted">Si la pones y quitas el video, el login usa esta imagen.</small>
				</div>
				<div class="col-md-4">
					<?php if ((string) ($rBranding['login_image'] ?? '') !== '') : ?>
						<img src="<?= $rUrl('login_image'); ?>" alt="login background" style="max-height:60px; max-width:100%;" class="mb-2 d-block">
					<?php endif; ?>
					<button type="submit" class="btn btn-sm btn-light" name="reset_field" value="login_image" formnovalidate>
						<i class="mdi mdi-restore"></i> Restaurar
					</button>
				</div>
			</div>

			<div class="form-group row">
				<label class="col-md-3 col-form-label" for="login_title">Titulo</label>
				<div class="col-md-5">
					<input type="text" class="form-control" id="login_title" name="login_title" value="<?= $rUrl('login_title'); ?>">
				</div>
			</div>

			<div class="form-group row mb-0">
				<label class="col-md-3 col-form-label" for="login_subtitle">Subtitulo</label>
				<div class="col-md-5">
					<input type="text" class="form-control" id="login_subtitle" name="login_subtitle" value="<?= $rUrl('login_subtitle'); ?>">
				</div>
			</div>
		</div>
	</div>

	<div class="card">
		<div class="card-body">
			<h4 class="header-title mb-1">Support project</h4>
			<p class="sub-header">
				Bloque de donativos/soporte. Sustituye las direcciones que trae el panel de serie por las tuyas
				y decide quien lo ve.
			</p>

			<div class="form-group row">
				<label class="col-md-3 col-form-label" for="support_enabled">Mostrar el bloque</label>
				<div class="col-md-5">
					<input name="support_enabled" id="support_enabled" type="checkbox" value="1"
						<?= !empty($rBranding['support_enabled']) ? ' checked' : ''; ?>
						data-plugin="switchery" class="js-switch" data-color="#039cfd">
				</div>
			</div>

			<div class="form-group row">
				<label class="col-md-3 col-form-label" for="support_scope">Visible en</label>
				<div class="col-md-5">
					<select class="form-control" id="support_scope" name="support_scope">
						<?php foreach ($rScopes as $rKey => $rLabel) : ?>
							<option value="<?= $rKey; ?>"<?= ((string) ($rBranding['support_scope'] ?? 'admin') === $rKey) ? ' selected' : ''; ?>><?= $rLabel; ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="form-group row">
				<label class="col-md-3 col-form-label" for="support_title">Titulo</label>
				<div class="col-md-5">
					<input type="text" class="form-control" id="support_title" name="support_title"
						value="<?= $rUrl('support_title'); ?>" placeholder="Support project">
				</div>
			</div>

			<div class="form-group row">
				<label class="col-md-3 col-form-label" for="support_intro">Texto de introduccion</label>
				<div class="col-md-7">
					<textarea class="form-control" id="support_intro" name="support_intro" rows="3"><?= $rUrl('support_intro'); ?></textarea>
				</div>
			</div>

			<div class="table-responsive">
				<table class="table table-striped table-borderless" id="branding-rows">
					<thead>
						<tr>
							<th style="width:22%;">Nombre</th>
							<th style="width:30%;">Direccion / valor</th>
							<th style="width:20%;">Enlace (opcional)</th>
							<th style="width:18%;">Icono (clase CSS)</th>
							<th style="width:10%;"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($rSupportRows as $rRow) : ?>
							<tr>
								<td><input type="text" class="form-control" name="support_label[]" value="<?= htmlspecialchars((string) ($rRow['label'] ?? ''), ENT_QUOTES); ?>"></td>
								<td><input type="text" class="form-control" name="support_value[]" value="<?= htmlspecialchars((string) ($rRow['value'] ?? ''), ENT_QUOTES); ?>"></td>
								<td><input type="text" class="form-control" name="support_link[]" value="<?= htmlspecialchars((string) ($rRow['link'] ?? ''), ENT_QUOTES); ?>" placeholder="https://..."></td>
								<td><input type="text" class="form-control" name="support_icon[]" value="<?= htmlspecialchars((string) ($rRow['icon'] ?? ''), ENT_QUOTES); ?>" placeholder="fab fa-bitcoin"></td>
								<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger branding-remove-row"><i class="mdi mdi-close"></i></button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<button type="button" class="btn btn-sm btn-outline-primary" id="branding-add-row">
				<i class="mdi mdi-plus"></i> Anadir fila
			</button>

			<template id="branding-row-template">
				<tr>
					<td><input type="text" class="form-control" name="support_label[]" value=""></td>
					<td><input type="text" class="form-control" name="support_value[]" value=""></td>
					<td><input type="text" class="form-control" name="support_link[]" value="" placeholder="https://..."></td>
					<td><input type="text" class="form-control" name="support_icon[]" value="" placeholder="fab fa-bitcoin"></td>
					<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger branding-remove-row"><i class="mdi mdi-close"></i></button></td>
				</tr>
			</template>
		</div>
	</div>

	<div class="text-right mb-4">
		<input type="submit" class="btn btn-primary" value="Guardar cambios">
	</div>
</form>

<script id="scripts">
	(function () {
		var addBtn = document.getElementById("branding-add-row");
		var tpl = document.getElementById("branding-row-template");
		var tbody = document.querySelector("#branding-rows tbody");

		if (addBtn && tpl && tbody) {
			addBtn.addEventListener("click", function () {
				tbody.appendChild(tpl.content.cloneNode(true));
			});
		}

		document.addEventListener("click", function (e) {
			var btn = e.target.closest ? e.target.closest(".branding-remove-row") : null;
			if (btn) {
				var row = btn.closest("tr");
				if (row) { row.parentNode.removeChild(row); }
			}
		});
	})();
</script>
