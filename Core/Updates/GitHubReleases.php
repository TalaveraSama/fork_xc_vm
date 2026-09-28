<?php

namespace XcVm\Core\Updates;

use XcVm\Core\Http\Request;

/**
 * GitHubReleases PHP class - wrapper for GitHub Releases API
 *
 * @package VateronMedia_GitHubReleases
 * @author Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 *
 * A PHP class created specifically for the \XC_VM project to interact with the GitHub Releases API.
 * Provides methods to fetch release versions, changelogs, asset hashes, and GeoLite database information
 * with caching support.
 */

class GitHubReleases {
    private $owner;
    private $repo;
    private $api_url;
    private $headers;
    private $timeout = 30; // Total transfer timeout in seconds (the /releases list ships changelogs and is tens of KB; 5s was too tight during installs when the link is saturated by apt/wget)
    private $connect_timeout = 10; // Connection-phase timeout in seconds — fail fast on an unreachable host without capping slow transfers
    private $cache_file = '/home/xc_vm/tmp/gitapi'; // Cache file path
    private string $channel = 'stable'; // 'stable' или 'unstable'
    private $hash_file = 'hashes.md5';
    private $cache_ttl = 1800; // Cache TTL in seconds (30 minutes)

    /**
     * Initialize a GitHubReleases instance.
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param string $channel Update channel: 'stable' or 'unstable'
     * @param string|null $token GitHub API token
     */
    public function __construct(string $owner, string $repo, ?string $channel = 'stable', ?string $token = null) {
        $this->owner = $owner;
        $this->repo = $repo;
        $this->channel = in_array($channel, ['stable', 'unstable']) ? $channel : 'stable';
        // Keyed by owner too: locate() walks several owners of the same
        // repository name, and a cache shared between them would serve one
        // owner's tag list while the download URLs are built from another.
        $rSlug = preg_replace('/[^A-Za-z0-9._-]/', '_', "{$owner}_{$repo}");
        $this->cache_file = "{$this->cache_file}_{$rSlug}_{$this->channel}"; // Уникальный кэш для owner+repo+канала
        $this->api_url = "https://api.github.com/repos/{$owner}/{$repo}/releases";
        $this->headers = $token ? [
            "Authorization: Bearer {$token}",
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28'
        ] : [];
    }

    /**
     * Clear the cached release data by deleting the cache file.
     */
    public function clearCache(): void {
        if (file_exists($this->cache_file)) {
            unlink($this->cache_file);
            error_log("Cache cleared for {$this->owner}/{$this->repo} by deleting {$this->cache_file}");
        }
    }

    /**
     * Check if the cache file is still valid based on TTL.
     *
     * @return bool True if cache is valid, False otherwise.
     */
    private function isCacheValid(): bool {
        if (!file_exists($this->cache_file)) {
            return false;
        }
        $cache_timestamp = filemtime($this->cache_file);
        return (time() - $cache_timestamp) < $this->cache_ttl;
    }

    /**
     * Load cache from file.
     *
     * @return array|null The cached data, or null if the file doesn't exist or is invalid.
     */
    private function loadCache(): ?array {
        if (!file_exists($this->cache_file)) {
            return null;
        }
        $content = file_get_contents($this->cache_file);
        if ($content === false) {
            error_log("Failed to read cache file {$this->cache_file}");
            return null;
        }
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Failed to parse cache file {$this->cache_file}: " . json_last_error_msg());
            return null;
        }
        return $data;
    }

    /**
     * Save data to cache file with file locking to prevent race conditions.
     *
     * @param array $data The data to cache.
     * @return bool True on success, False on failure.
     */
    private function saveCache(array $data): bool {
        $json = json_encode($data);
        if ($json === false) {
            error_log("Failed to encode cache data to JSON");
            return false;
        }

        $file = fopen($this->cache_file, 'c');
        if ($file === false) {
            error_log("Failed to open cache file {$this->cache_file} for writing");
            return false;
        }

        if (flock($file, LOCK_EX)) {
            ftruncate($file, 0);
            fwrite($file, $json);
            fflush($file);
            flock($file, LOCK_UN);
            fclose($file);
            error_log("Cache saved to {$this->cache_file}");
            return true;
        } else {
            error_log("Failed to acquire lock on cache file {$this->cache_file}");
            fclose($file);
            return false;
        }
    }

    /**
     * Fetch all release versions (tags) from the GitHub repository, using cache if valid.
     *
     * @return array List of version tags in descending order (latest first).
     * @throws \Exception If the request fails.
     */
    public function getReleases(): array {
        if ($this->isCacheValid()) {
            error_log("Using cached releases (channel: {$this->channel}) from {$this->cache_file}");
            $cache = $this->loadCache();
            if ($cache !== null) {
                $releases = $this->filterReleasesByChannel($cache);
                return array_values(array_map(fn($r) => $r['tag_name'], $releases));
            }
        }

        try {
            error_log("Fetching releases for {$this->owner}/{$this->repo} (channel: {$this->channel})");
            $response = $this->makeRequest($this->api_url);
            $data = json_decode($response, true);
            if ($data === null) {
                throw new \Exception("Failed to parse API response: " . json_last_error_msg());
            }

            $filtered = $this->filterReleasesByChannel($data);
            $this->saveCache($data); // Сохраняем полные данные, фильтруем при использовании

            $releases = array_values(array_map(fn($r) => $r['tag_name'], $filtered));
            error_log("Retrieved " . count($releases) . " releases for channel '{$this->channel}'");
            return $releases;
        } catch (\Exception $e) {
            error_log("Failed to fetch releases: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Fetch the release list unfiltered, honouring the cache.
     *
     * getReleases() drops the prerelease flag on the way out, so callers that
     * need to tell a prerelease apart from a stable one cannot use it.
     *
     * @return array The decoded /releases payload.
     * @throws \Exception When the request fails or the payload is not JSON.
     */
    private function getRawReleases(): array {
        if ($this->isCacheValid()) {
            $cache = $this->loadCache();
            if (is_array($cache)) {
                return $cache;
            }
        }

        $data = json_decode($this->makeRequest($this->api_url), true);
        if (!is_array($data)) {
            throw new \Exception("Failed to parse API response: " . json_last_error_msg());
        }

        $this->saveCache($data);
        return $data;
    }

    /**
     * Newest release holding the per-distribution runtime tarballs.
     *
     * This fork publishes two kinds of release side by side in one repository:
     * the panel under a version tag (2.4.0) and the mirrored runtime under
     * `binaries-<upstream tag>`. The binaries release is a prerelease, so it is
     * never getReleases()[0] on the stable channel -- that hands back the panel
     * tag, which carries no per-distribution asset. The updater then downloads
     * nothing, bin_version.json is never rewritten, and the next update_binaries
     * signal retries the same doomed download, once a minute, forever.
     *
     * Mirrors get_latest_binaries_tag() in build/install so a running panel and
     * a fresh install agree on which release holds the runtime.
     *
     * @return string|null Newest `binaries-` tag; the newest stable tag when the
     *                     repository has none (upstream single-purpose
     *                     XC_VM_Binaries); null when there is no usable release.
     * @throws \Exception When the release list cannot be fetched.
     */
    public function getBinariesTag(): ?string {
        $releases = $this->getRawReleases();

        foreach ($releases as $release) {
            if (!empty($release['draft'])) {
                continue;
            }
            $tag = trim((string) ($release['tag_name'] ?? ''));
            if (strncmp($tag, 'binaries-', 9) === 0) {
                return $tag;
            }
        }

        foreach ($releases as $release) {
            if (!empty($release['draft']) || !empty($release['prerelease'])) {
                continue;
            }
            $tag = trim((string) ($release['tag_name'] ?? ''));
            if ($tag !== '') {
                return $tag;
            }
        }

        return null;
    }

    /**
     * First owner in $rOwners whose copy of $repo actually serves releases.
     *
     * The data repositories (XC_VM_Update, XC_VM_Proxy) are mirrors: this fork
     * owns one so an install does not depend on upstream staying reachable, but
     * the mirror may not exist yet. Asking GitHub for a repository that is not
     * there answers 404, which getReleases() raises -- and an uncaught throw out
     * of a cron job lands in the panel log as an EXCEPTION for what is only a
     * data refresh. Try the owners in order and let the caller decide what to do
     * when none answers. Same shape and same order as BINARIES_SOURCES and
     * PANEL_SOURCES in build/install: this fork first, upstream as a safety net.
     *
     * @param string[] $rOwners Candidate owners, most preferred first.
     * @return self|null Ready instance, or null when no owner served a release.
     */
    public static function locate(array $rOwners, string $repo, ?string $channel = 'stable', ?string $token = null): ?self {
        $rSeen = [];

        foreach ($rOwners as $rOwner) {
            $rOwner = trim((string) $rOwner);
            if ($rOwner === '' || isset($rSeen[$rOwner])) {
                continue;
            }
            $rSeen[$rOwner] = true;

            $rCandidate = new self($rOwner, $repo, $channel, $token);
            try {
                if (!empty($rCandidate->getReleases())) {
                    return $rCandidate;
                }
                error_log("No releases in {$rOwner}/{$repo}, trying next owner");
            } catch (\Exception $e) {
                error_log("Mirror {$rOwner}/{$repo} unusable: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Get the latest available version that is newer than the current one.
     *
     * Unlike getNextVersion(), this skips intermediate versions and returns
     * the newest release directly, allowing jump updates.
     *
     * @param string $current_version The current version tag (e.g., "1.0.0").
     * @return string|null The latest version tag, or null if already up-to-date or version not found.
     */
    public function getLatestVersion(string $current_version): ?string {
        $releases = $this->getReleases();

        if (empty($releases)) {
            return null;
        }

        $latest = $releases[0];

        if (version_compare($latest, $current_version, '<=')) {
            return null;
        }

        return $latest;
    }

    /**
     * Retrieve the MD5 hash of a release asset from its corresponding hash file.
     *
     * @param string $version The release tag (e.g., "1.0.0").
     * @param string $asset_name The asset file name to get the hash for (e.g., "update.tar.gz").
     * @return string|null The MD5 hash string, or null if not found or invalid.
     */
    public function getAssetHash(string $version, string $asset_name): ?string {
        $hashURL = "https://github.com/{$this->owner}/{$this->repo}/releases/download/{$version}/{$this->hash_file}";
        try {
            $hash_response = $this->makeRequest($hashURL);

            $hash_text = trim($hash_response);
            $lines = explode("\n", $hash_text);

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }
                $parts = preg_split('/\s+/', $line, 2);
                if (count($parts) === 2 && $parts[1] === $asset_name) {
                    error_log("Retrieved MD5 hash for {$asset_name} in version {$version}");
                    return $parts[0];
                }
            }
            error_log("Asset '{$asset_name}' not found in hash file for version {$version}");
            return null;
        } catch (\Exception $e) {
            error_log("Failed to fetch asset hash from {$hashURL}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Retrieve the changelog for a specific release from its changelog.json.
     *
     * The file is fetched from the repository at the given tag via raw.githubusercontent.
     * It contains a single object: {"version": "X.Y.Z", "changes": [...]}.
     *
     * @param string $version The release tag to fetch the changelog for.
     * @return array A JSON-compatible array containing the changelog entry wrapped in an array.
     */
    public function getChangelog(string $version): array {
        try {
            $url = "https://raw.githubusercontent.com/{$this->owner}/{$this->repo}/refs/tags/{$version}/changelog.json";
            $response = $this->makeRequest($url);
            $changelog = json_decode($response, true);
            if ($changelog === null) {
                error_log("Failed to parse changelog JSON for version {$version}");
                return [];
            }
            error_log("Successfully retrieved changelog for version {$version} with " . count($changelog['changes'] ?? []) . " changes");
            return [$changelog];
        } catch (\Exception $e) {
            error_log("Failed to fetch changelog for version {$version}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Validate whether a version string follows the format X.Y.Z.
     *
     * @param string $version The version string to validate (e.g., "1.0.0").
     * @return bool True if valid, False otherwise.
     * @throws \InvalidArgumentException If the version string is too long or contains invalid parts.
     */
    public static function isValidVersion(string $version): bool {
        if (!is_string($version)) {
            error_log("Version must be a string");
            return false;
        }

        if (strlen($version) > 20) {
            error_log("Version string too long");
            throw new \InvalidArgumentException("Version string is too long");
        }

        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $version)) {
            error_log("Invalid version format: {$version}");
            return false;
        }

        $parts = explode('.', $version);
        if (count($parts) !== 3) {
            error_log("Version must have three parts: {$version}");
            return false;
        }

        foreach ($parts as $part) {
            $num = (int)$part;
            if ($num < 0) {
                error_log("Negative numbers are not allowed in version: {$version}");
                return false;
            }
            if (strlen($part) > 1 && $part[0] === '0') {
                error_log("Leading zeros are not allowed in version: {$version}");
                return false;
            }
        }

        return true;
    }

    /**
     * Make an HTTP request using cURL.
     *
     * @param string $url The URL to request.
     * @return string The response body.
     * @throws \Exception If the request fails.
     */
    private function makeRequest(string $url): string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connect_timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Vateron-Media/\XC_VM');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \Exception("cURL error: {$error}");
        }

        if ($http_code !== 200) {
            switch ($http_code) {
                case 404:
                    error_log("Resource not found (404)");
                    throw new \Exception("Resource not found (404)");
                case 403:
                    error_log("Access forbidden (403) - Check API rate limits or permissions");
                    throw new \Exception("Access forbidden (403)");
                case 500:
                    error_log("Server error (500)");
                    throw new \Exception("Server error (500)");
                default:
                    error_log("Unexpected HTTP status code: {$http_code}");
                    throw new \Exception("Unexpected HTTP status code: {$http_code}");
            }
        }

        return $response;
    }

    /**
     * Get update file details for a specific file type and version.
     *
     * @param string $file_type The type of update file (main, lb, lb_update).
     * @param string $version The current version tag (e.g., "1.0.0").
     * @return array|null Array with URL and MD5 hash, or null if no next version.
     * @throws \Exception If the file type is invalid.
     */
    public function getUpdateFile(string $file_type, string $version) {
        switch ($file_type) {
            case "main":
                $update_file = "xc_vm.tar.gz";
                break;
            case "lb":
                $update_file = "loadbalancer.tar.gz";
                break;
            case "lb_update":
                $update_file = "loadbalancer.tar.gz";
                break;
            default:
                throw new \Exception("Not valid file type");
        }
        $target_version = $this->getLatestVersion($version);
        if (is_null($target_version)) {
            $target_version = $version;
        }
        $upd_archive_url = "https://github.com/{$this->owner}/{$this->repo}/releases/download/{$target_version}/{$update_file}";
        $hash_md5 = $this->getAssetHash($target_version, $update_file);

        $data = ["url" => $upd_archive_url, "md5" => $hash_md5];
        return $data;
    }

    /**
     * Retrieves update information for the specified version
     * 
     * @param string $version Current version to check for updates
     * @return array|null Array with update information or null in case of error
     * @throws \InvalidArgumentException If an incorrect version is passed
     */
    public function getUpdate(string $version): ?array {
        try {
            $latest_version = $this->getLatestVersion($version);
            if ($latest_version === null) {
                error_log("No update available for version {$version} on channel '{$this->channel}'");
                return null;
            }

            $changelog = $this->getChangelog($latest_version);

            $url = "https://github.com/{$this->owner}/{$this->repo}/releases/tag/{$latest_version}";

            return [
                "version" => $latest_version,
                "changelog" => $changelog,
                "url" => $url
            ];
        } catch (\Exception $e) {
            error_log("Error while fetching update: " . $e->getMessage());
            return null;
        }
    }
    /**
     * Retrieve the latest GeoLite database release information.
     *
     * This method fetches the latest release version from the repository,
     * builds download URLs for GeoLite2 database files (ASN, City, Country),
     * and prepares metadata including file paths, permissions, and MD5 hashes.
     *
     * @return array|null Returns an associative array with the latest version and file data,
     *                    or null if no releases are available.
     */
    public function getGeolite(): ?array {
        // Get all available releases from the repository
        $releases = $this->getReleases();

        // If there are no releases, return null
        if (empty($releases)) {
            return null;
        }

        // Take the latest release (the first in the list)
        $latest_version = $releases[0];

        // Prepare the list of data files
        $data_files = array();

        // Iterate over required GeoLite2 database files
        foreach (["GeoLite2-City.mmdb", "GeoLite2-Country.mmdb", "GeoLite2-ASN.mmdb"] as $file) {
            // Construct the GitHub release download URL
            $file_url = "https://github.com/{$this->owner}/{$this->repo}/releases/download/{$latest_version}/{$file}";

            // Fetch the MD5 hash for file integrity verification
            $hash_md5 = $this->getAssetHash($latest_version, $file);

            // Add file information to the list
            $data_files[] = [
                "fileurl"   => $file_url,                                // Remote file URL
                "path"      => "/home/xc_vm/bin/maxmind/{$file}",        // Local path where the file should be stored
                "md5"       => $hash_md5                                // File hash (MD5)
            ];
        }

        // Prepare final data structure containing version and files metadata
        $data = [
            "version" => $latest_version,
            "files"   => $data_files,
        ];

        // Return the release data
        return $data;
    }

    /**
     * Filter releases based on the selected channel.
     *
     * @param array $releases Raw releases from GitHub API
     * @return array Filtered releases
     */
    private function filterReleasesByChannel(array $releases): array {
        if ($this->channel === 'unstable') {
            return $releases; // Все релизы
        }

        // stable: только не pre-release
        return array_filter($releases, function ($release) {
            return empty($release['prerelease']);
        });
    }

    /**
     * Set the request timeout for cURL operations.
     *
     * @param int $seconds Timeout in seconds
     */
    public function setTimeout(int $seconds): void {
        $this->timeout = max(1, $seconds);
    }

    /**
     * Change the update channel and clear cache.
     *
     * @param string $channel 'stable' or 'unstable'
     */
    public function setChannel(string $channel): void {
        if (!in_array($channel, ['stable', 'unstable'])) {
            throw new \InvalidArgumentException("Channel must be 'stable' or 'unstable'");
        }
        if ($this->channel !== $channel) {
            $oldCacheFile = $this->cache_file;
            $baseCacheFile = preg_replace('/_(stable|unstable)$/', '', $this->cache_file);
            if (!is_string($baseCacheFile) || $baseCacheFile === '') {
                $baseCacheFile = $this->cache_file;
            }

            $this->channel = $channel;

            // Remove old-channel cache explicitly before switching file path.
            if (file_exists($oldCacheFile)) {
                unlink($oldCacheFile);
            }

            $this->cache_file = $baseCacheFile . "_{$channel}";

            // Ensure target-channel cache is also reset.
            $this->clearCache();
            error_log("Update channel changed to '{$channel}' and cache cleared");
        }
    }
}

/**
 * ------------------------------------------------------------
 * 🔧 Инициализация:
 *
 *   $gh = new GitHubReleases("Vateron-Media", "XC_VM", 'stable');
 *   // Можно передать токен для увеличения лимита API:
 *   // $gh = new GitHubReleases("owner", "repo", "ghp_XXXXXXX");
 *
 * ------------------------------------------------------------
 *
 * 1. Получить список релизов:
 *
 *   $releases = $gh->getReleases();
 *   print_r($releases);
 *
 * ------------------------------------------------------------
 * 2. Получить последнюю доступную версию (прыжок на latest):
 *
 *   $latest = $gh->getLatestVersion("1.0.0");
 *   echo $latest; // e.g. "2.0.2" — пропускает промежуточные версии
 *
 * ------------------------------------------------------------
 * 3. Проверить хэш файла из релиза:
 *
 *   $hash = $gh->getAssetHash("1.2.0", "update.tar.gz");
 *   echo $hash;
 *
 * ------------------------------------------------------------
 * 4. Загрузить changelog:
 *
 *   $changelog = $gh->getChangelog("1.2.0");
 *   print_r($changelog);
 *
 * ------------------------------------------------------------
 * 5. Проверка корректности версии:
 *
 *   var_dump(GitHubReleases::isValidVersion("1.0.0")); // true
 *   var_dump(GitHubReleases::isValidVersion("01.0.0")); // false
 *
 * ------------------------------------------------------------
 * 6. Получение архива обновления:
 *
 *   $upd = $gh->getUpdateFile("main", "1.0.0");
 *   print_r($upd);
 *
 * ------------------------------------------------------------
 * 7. Проверить наличие обновления:
 *
 *   $update = $gh->getUpdate("1.0.0");
 *   print_r($update);
 *
 * ------------------------------------------------------------
 * 8. Получить информацию о GeoLite базах:
 *
 *   $gh = new GitHubReleases("Vateron-Media", "XC_VM_Update");
 *   $geo = $gh->getGeolite();
 *   print_r($geo);
 *
 * ------------------------------------------------------------
 * ⚠️ Важно:
 * - Кеш хранится в /home/xc_vm/tmp/gitapi_repo
 * - TTL кеша: 30 минут (1800 сек)
 */
