<?php

/**
 * XC_VM Application Configuration
 *
 * Copyright (c) 2026 Vateron-Media
 *
 * @author      Divarion_D
 * @license     GNU Affero General Public License v3.0 (AGPL-3.0)
 * @link        https://github.com/Vateron-Media/XC_VM
 *
 * This file contains application-level configuration constants,
 * including versioning, Git repositories, and feature flags.
 */

// ── Runtime Safety Flags ───────────────────────────────────────

// phpMiniAdmin — direct MySQL access from the admin panel. Disabled by default:
// it exposes raw database access, so enable it per-install only when needed.
define('DB_ACCESS_ENABLED', false); // Set to true to allow phpMiniAdmin access; false disables it

// Password phpMiniAdmin requires (on top of the admin session). Keep EMPTY in the
// repo — a committed password is public and protects nothing; set a strong one on
// the specific install. When empty, only local IPs (127.0.0.1/::1) may pass.
define('DB_ACCESS_PWD', ""); // Set a strong password to protect database access

// Forces error display on-screen regardless of the DB setting (debug_show_errors).
// Set to true locally for development; must be false in production.
define('DEV_MODE', false);

// ── Version & Git Configuration ────────────────────────────────

define('XC_VM_VERSION', '2.5.1');

define('GIT_OWNER',       'Vateron-Media');
define('GIT_REPO_MAIN',   'fork_xc_vm');
define('GIT_REPO_UPDATE', 'XC_VM_Update');
define('GIT_REPO_BIN',    'fork_xc_vm');
define('GIT_REPO_PROXY',  'XC_VM_Proxy');

// Every repository the panel fetches from is owned per-repo rather than by
// one shared constant, so each can be pointed at this fork independently.
// The panel and its bundled binaries must come from here: the in-panel
// updater would otherwise pull upstream's archive over the patches applied
// in this tree. GeoIP and the proxy node are data this fork adds nothing to,
// but they are mirrored too so that nothing the panel downloads at runtime
// depends on Vateron-Media staying reachable. Point any of these back at
// 'Vateron-Media' to fall back to upstream for that one repository.
define('GIT_OWNER_MAIN',   'TalaveraSama');
define('GIT_OWNER_BIN',    'TalaveraSama');
define('GIT_OWNER_UPDATE', 'TalaveraSama');
define('GIT_OWNER_PROXY',  'TalaveraSama');

// ── Miscellaneous Settings ─────────────────────────────────────

define('MONITOR_CALLS', 3);          // Number of retry attempts for monitoring tasks
define('OPENSSL_EXTRA', 'fNiu3XD448xTDa27xoY4'); // Additional OpenSSL entropy/seed (review necessity)