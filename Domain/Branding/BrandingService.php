<?php

namespace XcVm\Domain\Branding;

/**
 * BrandingService — personalizacion del panel sin tocar la base de datos.
 *
 * Los valores viven en `config/branding.json` (un unico fichero, se copia y se
 * restaura junto al resto de `config/`), de modo que no hace falta ninguna
 * migracion. Los ficheros subidos (logo, video, favicon) van a
 * `Public/assets/admin/images/branding/` y `Public/assets/admin/videos/branding/`.
 *
 * Las rutas se guardan RELATIVAS (`assets/images/logo.png`) porque las paginas
 * del panel se sirven bajo `/{CODIGO}/`, y nginx mapea `/{CODIGO}/assets/` a
 * `Public/assets/admin/`.
 *
 * @package XC_VM_Domain_Branding
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class BrandingService {

	/** Tamano maximo aceptado por tipo de fichero (bytes). */
	private const MAX_IMAGE_SIZE = 4194304;   // 4 MB
	private const MAX_VIDEO_SIZE = 67108864;  // 64 MB

	/** @var array<string,mixed>|null Cache en memoria. */
	private static $rCache = null;

	/**
	 * Ruta del fichero de configuracion.
	 */
	public static function configPath(): string {
		return MAIN_HOME . 'config/branding.json';
	}

	/**
	 * Valores por defecto (= lo que trae el panel de serie).
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'panel_name'       => '',
			'logo_login'       => 'assets/images/logo.png',
			'logo_topbar'      => 'assets/images/logo-topbar.png',
			'favicon'          => 'assets/images/favicon.ico',
			'login_video'      => 'assets/videos/login-bg.mp4',
			'login_video_webm' => '',
			'login_image'      => '',
			'login_title'      => 'Welcome Back',
			'login_subtitle'   => 'Sign in to your account',
			'support_enabled'  => 1,
			'support_scope'    => 'admin',
			'support_title'    => 'Support project',
			'support_intro'    => '',
			'support_rows'     => array(),
		);
	}

	/**
	 * Todos los valores (por defecto + guardados).
	 *
	 * @param bool $rReload Fuerza la relectura del fichero.
	 * @return array<string,mixed>
	 */
	public static function all(bool $rReload = false): array {
		if (self::$rCache !== null && !$rReload) {
			return self::$rCache;
		}

		$rSaved = array();
		$rFile = self::configPath();

		if (is_readable($rFile)) {
			$rDecoded = json_decode((string) file_get_contents($rFile), true);
			if (is_array($rDecoded)) {
				$rSaved = $rDecoded;
			}
		}

		self::$rCache = array_merge(self::defaults(), $rSaved);

		return self::$rCache;
	}

	/**
	 * Un valor concreto.
	 *
	 * @param string $rKey     Clave.
	 * @param mixed  $rDefault Valor de reserva.
	 * @return mixed
	 */
	public static function get(string $rKey, $rDefault = null) {
		$rAll = self::all();

		return array_key_exists($rKey, $rAll) ? $rAll[$rKey] : $rDefault;
	}

	/**
	 * URL del logo/video/favicon con reserva al valor por defecto.
	 */
	public static function asset(string $rKey): string {
		$rValue = trim((string) self::get($rKey, ''));
		$rDefaults = self::defaults();

		if ($rValue === '') {
			return (string) ($rDefaults[$rKey] ?? '');
		}

		return $rValue;
	}

	public static function loginTitle(): string {
		$rValue = trim((string) self::get('login_title', ''));

		return $rValue !== '' ? $rValue : (string) self::defaults()['login_title'];
	}

	public static function loginSubtitle(): string {
		$rValue = trim((string) self::get('login_subtitle', ''));

		return $rValue !== '' ? $rValue : (string) self::defaults()['login_subtitle'];
	}

	public static function panelName(): string {
		$rValue = trim((string) self::get('panel_name', ''));

		return $rValue !== '' ? $rValue : 'XC_VM';
	}

	/**
	 * Filas del bloque Support ya normalizadas.
	 *
	 * @return array<int,array{label:string,value:string,link:string,icon:string}>
	 */
	public static function supportRows(): array {
		$rRows = self::get('support_rows', array());
		$rReturn = array();

		if (!is_array($rRows)) {
			return $rReturn;
		}

		foreach ($rRows as $rRow) {
			if (!is_array($rRow)) {
				continue;
			}

			$rLabel = trim((string) ($rRow['label'] ?? ''));
			$rValue = trim((string) ($rRow['value'] ?? ''));

			if ($rLabel === '' && $rValue === '') {
				continue;
			}

			$rReturn[] = array(
				'label' => $rLabel,
				'value' => $rValue,
				'link'  => trim((string) ($rRow['link'] ?? '')),
				'icon'  => trim((string) ($rRow['icon'] ?? '')),
			);
		}

		return $rReturn;
	}

	/**
	 * Hay bloque Support que mostrar?
	 */
	public static function hasSupport(): bool {
		if (empty(self::get('support_enabled', 1))) {
			return false;
		}

		return self::supportRows() !== [] || trim((string) self::get('support_intro', '')) !== '';
	}

	/**
	 * Visibilidad del bloque Support por area.
	 *
	 * admin    -> solo panel de administracion
	 * reseller -> admin + panel de reseller
	 * all      -> admin + reseller + portal de usuario
	 *
	 * @param string $rScope 'admin' | 'reseller' | 'user'
	 */
	public static function supportVisibleFor(string $rScope): bool {
		if (!self::hasSupport()) {
			return false;
		}

		$rAllowed = (string) self::get('support_scope', 'admin');

		switch ($rScope) {
			case 'admin':
				return true;

			case 'reseller':
				return in_array($rAllowed, array('reseller', 'all'), true);

			case 'user':
				return $rAllowed === 'all';
		}

		return false;
	}

	/**
	 * HTML del bloque Support (se incluye tal cual en admin, reseller o portal).
	 *
	 * @param object|null $rLanguage Traductor del panel (opcional).
	 */
	public static function renderSupportCard($rLanguage = null): string {
		if (!self::hasSupport()) {
			return '';
		}

		$rRows = self::supportRows();
		$rTitle = trim((string) self::get('support_title', ''));
		$rIntro = trim((string) self::get('support_intro', ''));
		$rCopyLabel = 'Copy';
		$rQrLabel = 'QR';

		if (is_object($rLanguage) && method_exists($rLanguage, 'get')) {
			$rTitle = $rTitle !== '' ? $rTitle : (string) $rLanguage::get('support_project');
		}

		$rTitle = $rTitle !== '' ? $rTitle : 'Support project';

		$rHtml = '<div class="card" id="branding-support"><div class="card-body">';
		$rHtml .= '<h4 class="header-title mb-3">' . htmlspecialchars($rTitle, ENT_QUOTES) . '</h4>';

		if ($rIntro !== '') {
			$rHtml .= '<p class="sub-header">' . nl2br(htmlspecialchars($rIntro, ENT_QUOTES)) . '</p>';
		}

		if ($rRows !== []) {
			$rHtml .= '<div class="table-responsive"><table class="table table-striped table-borderless mb-0"><tbody>';

			foreach ($rRows as $rRow) {
				$rHtml .= '<tr>';
				$rHtml .= '<td class="text-nowrap">';
				if ($rRow['icon'] !== '') {
					$rHtml .= '<i class="' . htmlspecialchars($rRow['icon'], ENT_QUOTES) . ' mr-2"></i>';
				}
				$rHtml .= htmlspecialchars($rRow['label'], ENT_QUOTES) . '</td>';
				$rHtml .= '<td class="text-monospace small">' . htmlspecialchars($rRow['value'], ENT_QUOTES) . '</td>';

				if ($rRow['link'] !== '') {
					$rHtml .= '<td class="text-center" style="width:60px;"><a class="btn btn-sm btn-outline-primary" href="'
						. htmlspecialchars($rRow['link'], ENT_QUOTES) . '" target="_blank" rel="noopener" title="Open"><i class="mdi mdi-open-in-new"></i></a></td>';
				} else {
					$rHtml .= '<td class="text-center" style="width:60px;"></td>';
				}

				if ($rRow['value'] !== '') {
					$rHtml .= '<td class="text-center text-nowrap" style="width:110px;">'
						. '<button type="button" class="btn btn-sm btn-outline-success branding-copy" data-value="'
						. htmlspecialchars($rRow['value'], ENT_QUOTES) . '" title="' . htmlspecialchars($rCopyLabel, ENT_QUOTES) . '"><i class="mdi mdi-content-copy"></i></button> '
						. '<button type="button" class="btn btn-sm btn-outline-primary branding-qr" data-value="'
						. htmlspecialchars($rRow['value'], ENT_QUOTES) . '" title="' . htmlspecialchars($rQrLabel, ENT_QUOTES) . '"><i class="mdi mdi-qrcode"></i></button>'
						. '</td>';
				} else {
					$rHtml .= '<td></td>';
				}

				$rHtml .= '</tr>';
			}

			$rHtml .= '</tbody></table></div>';
		}

		$rHtml .= '</div>';

		// JS propio: el bloque se usa tambien en areas que no cargan settings.php.
		$rHtml .= '<script>
(function () {
	if (window.xcBrandingSupport) { return; }
	window.xcBrandingSupport = true;

	function toast(msg) {
		if (window.jQuery && window.jQuery.toast) { window.jQuery.toast(msg); }
	}

	document.addEventListener("click", function (e) {
		var copyBtn = e.target.closest ? e.target.closest(".branding-copy") : null;
		if (copyBtn) {
			var value = copyBtn.getAttribute("data-value") || "";
			if (navigator.clipboard) {
				navigator.clipboard.writeText(value).then(function () { toast("Copied"); });
			} else {
				var tmp = document.createElement("textarea");
				tmp.value = value;
				document.body.appendChild(tmp);
				tmp.select();
				try { document.execCommand("copy"); toast("Copied"); } catch (err) {}
				document.body.removeChild(tmp);
			}
			return;
		}

		var qrBtn = e.target.closest ? e.target.closest(".branding-qr") : null;
		if (qrBtn) {
			var data = qrBtn.getAttribute("data-value") || "";
			window.open("https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" + encodeURIComponent(data), "_blank");
		}
	});
})();
</script>';

		$rHtml .= '</div>';

		return $rHtml;
	}

	/**
	 * Guarda los valores enviados por el formulario.
	 *
	 * @param array<string,mixed> $rPost  $_POST
	 * @param array<string,mixed> $rFiles $_FILES
	 * @return array{ok:bool,errors:string[]}
	 */
	public static function saveFromPost(array $rPost, array $rFiles = array()): array {
		$rErrors = array();
		$rValues = self::all();

		$rTextFields = array(
			'panel_name',
			'logo_login',
			'logo_topbar',
			'favicon',
			'login_video',
			'login_video_webm',
			'login_image',
			'login_title',
			'login_subtitle',
			'support_title',
			'support_intro',
		);

		foreach ($rTextFields as $rField) {
			if (array_key_exists($rField, $rPost)) {
				$rValues[$rField] = trim((string) $rPost[$rField]);
			}
		}

		$rValues['support_enabled'] = empty($rPost['support_enabled']) ? 0 : 1;

		$rScope = (string) ($rPost['support_scope'] ?? 'admin');
		$rValues['support_scope'] = in_array($rScope, array('admin', 'reseller', 'all'), true) ? $rScope : 'admin';

		// Filas del bloque Support.
		$rValues['support_rows'] = array();

		if (!empty($rPost['support_label']) && is_array($rPost['support_label'])) {
			$rLabels = array_values($rPost['support_label']);
			$rValuesList = array_values((array) ($rPost['support_value'] ?? array()));
			$rLinks = array_values((array) ($rPost['support_link'] ?? array()));
			$rIcons = array_values((array) ($rPost['support_icon'] ?? array()));

			foreach ($rLabels as $rIndex => $rLabel) {
				$rLabel = trim((string) $rLabel);
				$rRowValue = trim((string) ($rValuesList[$rIndex] ?? ''));
				$rLink = trim((string) ($rLinks[$rIndex] ?? ''));
				$rIcon = trim((string) ($rIcons[$rIndex] ?? ''));

				if ($rLabel === '' && $rRowValue === '') {
					continue;
				}

				$rValues['support_rows'][] = array(
					'label' => $rLabel,
					'value' => $rRowValue,
					'link'  => $rLink,
					'icon'  => $rIcon,
				);
			}
		}

		// Subidas de fichero (opcionales).
		foreach (array('logo_login' => 'image', 'logo_topbar' => 'image', 'favicon' => 'image', 'login_image' => 'image') as $rField => $rKind) {
			$rUploaded = self::storeUpload($rField, $rFiles[$rField] ?? array(), $rKind, $rErrors);

			if ($rUploaded !== null) {
				$rValues[$rField] = $rUploaded;
			}
		}

		$rVideo = self::storeUpload('login_video', $rFiles['login_video'] ?? array(), 'video', $rErrors);

		if ($rVideo !== null) {
			$rValues['login_video'] = $rVideo;
			$rValues['login_video_webm'] = '';
		}

		if ($rErrors !== []) {
			return array('ok' => false, 'errors' => $rErrors);
		}

		$rSaved = self::write($rValues);

		return array('ok' => $rSaved, 'errors' => $rSaved ? array() : array('No se pudo escribir config/branding.json (permisos).'));
	}

	/**
	 * Restaura una clave a su valor de fabrica y borra el fichero subido.
	 */
	public static function resetField(string $rField): bool {
		$rDefaults = self::defaults();

		if (!array_key_exists($rField, $rDefaults)) {
			return false;
		}

		$rValues = self::all();
		self::deleteStoredFile((string) ($rValues[$rField] ?? ''));
		$rValues[$rField] = $rDefaults[$rField];

		return self::write($rValues);
	}

	/**
	 * Valida y guarda un fichero subido.
	 *
	 * @return string|null URL relativa guardada, o null si no habia fichero.
	 */
	private static function storeUpload(string $rField, array $rFile, string $rKind, array &$rErrors): ?string {
		if ($rFile === array() || ($rFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
			return null;
		}

		if (($rFile['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
			$rErrors[] = $rField . ': upload failed (error ' . (int) $rFile['error'] . ').';

			return null;
		}

		$rMaxSize = $rKind === 'video' ? self::MAX_VIDEO_SIZE : self::MAX_IMAGE_SIZE;

		if ((int) ($rFile['size'] ?? 0) > $rMaxSize) {
			$rErrors[] = $rField . ': file too large (max ' . round($rMaxSize / 1048576) . ' MB).';

			return null;
		}

		$rAllowed = $rKind === 'video'
			? array('mp4' => 'video/mp4', 'webm' => 'video/webm')
			: array('png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp', 'ico' => 'image/x-icon', 'vnd.microsoft.icon' => 'image/x-icon');

		$rExtension = strtolower((string) pathinfo((string) ($rFile['name'] ?? ''), PATHINFO_EXTENSION));

		if (!array_key_exists($rExtension, $rAllowed)) {
			$rErrors[] = $rField . ': extension .' . $rExtension . ' not allowed.';

			return null;
		}

		if (!is_uploaded_file((string) ($rFile['tmp_name'] ?? ''))) {
			$rErrors[] = $rField . ': invalid upload.';

			return null;
		}

		$rDirectory = $rKind === 'video'
			? MAIN_HOME . 'Public/assets/admin/videos/branding/'
			: MAIN_HOME . 'Public/assets/admin/images/branding/';

		if (!is_dir($rDirectory) && !@mkdir($rDirectory, 0755, true)) {
			$rErrors[] = $rField . ': could not create ' . $rDirectory;

			return null;
		}

		$rFileName = $rField . '_' . date('YmdHis') . '.' . $rExtension;
		$rTarget = $rDirectory . $rFileName;

		if (!@move_uploaded_file((string) $rFile['tmp_name'], $rTarget)) {
			$rErrors[] = $rField . ': could not move the uploaded file.';

			return null;
		}

		@chmod($rTarget, 0644);

		self::deleteStoredFile((string) self::get($rField, ''));

		return ($rKind === 'video' ? 'assets/videos/branding/' : 'assets/images/branding/') . $rFileName;
	}

	/**
	 * Borra un fichero subido por el panel (solo dentro de las carpetas branding).
	 */
	private static function deleteStoredFile(string $rValue): void {
		foreach (array('assets/images/branding/', 'assets/videos/branding/') as $rPrefix) {
			if ($rValue === '' || strpos($rValue, $rPrefix) !== 0) {
				continue;
			}

			$rPath = MAIN_HOME . 'Public/assets/admin/' . substr($rValue, strlen('assets/'));
			$rReal = realpath($rPath);
			$rBase = realpath(MAIN_HOME . 'Public/assets/admin/images/branding') ?: '';
			$rBaseVideo = realpath(MAIN_HOME . 'Public/assets/admin/videos/branding') ?: '';

			// Solo dentro de las carpetas gestionadas por el panel.
			if ($rReal === false) {
				continue;
			}

			if (($rBase !== '' && strpos($rReal, $rBase) === 0) || ($rBaseVideo !== '' && strpos($rReal, $rBaseVideo) === 0)) {
				@unlink($rReal);
			}
		}
	}

	/**
	 * Escritura atomica del JSON.
	 */
	private static function write(array $rValues): bool {
		$rFile = self::configPath();
		$rJson = json_encode($rValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		if ($rJson === false) {
			return false;
		}

		$rTmp = $rFile . '.tmp';

		if (@file_put_contents($rTmp, $rJson . "\n") === false) {
			return false;
		}

		if (!@rename($rTmp, $rFile)) {
			@unlink($rTmp);

			return false;
		}

		@chmod($rFile, 0640);
		self::$rCache = null;

		return true;
	}
}
