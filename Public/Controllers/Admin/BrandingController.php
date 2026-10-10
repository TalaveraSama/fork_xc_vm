<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Domain\Branding\BrandingService;

/**
 * BrandingController — personalizacion del panel (logo, video del login, textos
 * y bloque Support project).
 *
 * GET  /branding  -> formulario
 * POST /branding  -> guardar (multipart: admite subida de logo/video/favicon)
 *
 * No toca la base de datos: todo vive en `config/branding.json`.
 *
 * @renders Views/admin/branding.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class BrandingController extends BaseAdminController {

	public function index(): void {
		// La pagina se gobierna por el mismo permiso que Ajustes. No se usa el
		// chequeo por nombre de pagina de BaseAdminController porque con URLs
		// bonitas (/{codigo}/branding) el SCRIPT_FILENAME es index.php y el
		// mapeo por nombre no aplica.
		$this->requireAdvPermission('adv', 'settings');

		$rMessage = '';
		$rError = '';

		if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
			$rReset = trim((string) ($_POST['reset_field'] ?? ''));

			if ($rReset !== '') {
				if (BrandingService::resetField($rReset)) {
					$rMessage = 'Valor de fabrica restaurado en "' . $rReset . '".';
				} else {
					$rError = 'No se pudo restaurar "' . $rReset . '".';
				}
			} else {
				$rResult = BrandingService::saveFromPost($_POST, $_FILES);

				if (!empty($rResult['ok'])) {
					$rMessage = 'Branding guardado.';
				} else {
					$rError = implode(' | ', (array) ($rResult['errors'] ?? array('No se pudo guardar.')));
				}
			}
		}

		$this->setTitle('Branding');

		$this->render('branding', array(
			'rBranding' => BrandingService::all(true),
			'rDefaults' => BrandingService::defaults(),
			'rMessage'  => $rMessage,
			'rError'    => $rError,
		));
	}
}
