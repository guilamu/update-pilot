<?php
/**
 * GitHub Auto-Updater
 *
 * Enables automatic updates from GitHub releases for WordPress plugins.
 *
 * Adapted from the repository's WordPress-GitHub-AutoUpdate reference. This is
 * a WordPress-only plugin, so REQUIRES_GF and the Gravity Forms sidebar script
 * have been removed; everything else follows the template.
 *
 * @package Update_Pilot
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Update_Pilot_GitHub_Updater
 *
 * Handles automatic updates from GitHub releases.
 */
class Update_Pilot_GitHub_Updater
{

    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    /**
     * GitHub username or organization.
     *
     * @var string
     */
    private const GITHUB_USER = 'guilamu';

    /**
     * GitHub repository name.
     *
     * @var string
     */
    private const GITHUB_REPO = 'update-pilot';

    /**
     * Plugin file path relative to plugins directory.
     *
     * @var string
     */
    private const PLUGIN_FILE = 'update-pilot/update-pilot.php';

    /**
     * Plugin slug (used for plugin info popup).
     *
     * @var string
     */
    private const PLUGIN_SLUG = 'update-pilot';

    /**
     * Plugin display name.
     *
     * @var string
     */
    private const PLUGIN_NAME = 'Update Pilot';

    /**
     * Plugin description.
     *
     * @var string
     */
    private const PLUGIN_DESCRIPTION = 'Take command of WordPress auto-updates: choose what updates, schedule when, delay risky releases, and get a truthful log of everything that happened.';

    /**
     * Minimum WordPress version required.
     *
     * @var string
     */
    private const REQUIRES_WP = '6.5';

    /**
     * WordPress version tested up to.
     *
     * @var string
     */
    private const TESTED_WP = '7.0';

    /**
     * Minimum PHP version required.
     *
     * @var string
     */
    private const REQUIRES_PHP = '8.0';

    /**
     * Text domain for translations.
     *
     * @var string
     */
    private const TEXT_DOMAIN = 'update-pilot';

    // =========================================================================
    // CACHE SETTINGS
    // =========================================================================

    /**
     * Cache key for GitHub release data.
     *
     * @var string
     */
    private const CACHE_KEY = 'update_pilot_github_release';

    /**
     * Cache expiration in seconds (12 hours).
     *
     * @var int
     */
    private const CACHE_EXPIRATION = 43200;

    /**
     * How long a failed lookup is remembered, in seconds (5 minutes).
     *
     * @var int
     */
    private const FAILURE_CACHE_EXPIRATION = 300;

    /**
     * Optional GitHub token for private repos or to avoid rate limits.
     *
     * @var string
     */
    private const GITHUB_TOKEN = '';

    // =========================================================================
    // IMPLEMENTATION
    // =========================================================================

    /**
     * Initialize the updater.
     *
     * @return void
     */
    public static function init(): void
    {
        add_filter('update_plugins_github.com', array(self::class, 'check_for_update'), 10, 4);
        add_filter('plugins_api', array(self::class, 'plugin_info'), 20, 3);
        add_filter('plugins_api_result', array(self::class, 'finalize_plugin_info'), PHP_INT_MAX, 3);
        add_filter('upgrader_source_selection', array(self::class, 'fix_folder_name'), 10, 4);
        add_action('admin_head', array(self::class, 'plugin_info_css'));
    }

    /**
     * Rebuild the final plugin information object after all earlier filters.
     *
     * @param false|object|array $result Plugin API result.
     * @param string             $action Requested action.
     * @param object             $args   API arguments.
     * @return false|object|array
     */
    public static function finalize_plugin_info($result, $action, $args)
    {
        if (!self::is_plugin_information_api_request($action, $args)) {
            return $result;
        }

        return self::get_safe_plugin_info_result();
    }

    /**
     * Check whether the current API request is asking for this plugin.
     *
     * @param string $action Requested action.
     * @param mixed  $args   API arguments.
     * @return bool
     */
    private static function is_plugin_information_api_request($action, $args): bool
    {
        return 'plugin_information' === $action
            && is_object($args)
            && isset($args->slug)
            && self::PLUGIN_SLUG === $args->slug;
    }

    /**
     * Get the active plugin file path relative to the plugins directory.
     *
     * @return string
     */
    private static function get_plugin_file(): string
    {
        if (defined('UPILOT_BASENAME') && is_string(UPILOT_BASENAME) && '' !== UPILOT_BASENAME) {
            return UPILOT_BASENAME;
        }

        return self::PLUGIN_FILE;
    }

    /**
     * Get the active plugin directory relative to the plugins directory.
     *
     * @return string
     */
    private static function get_plugin_directory(): string
    {
        return dirname(self::get_plugin_file());
    }

    /**
     * Build the plugin information object once and return a fresh clone.
     *
     * @return stdClass
     */
    private static function get_safe_plugin_info_result(): stdClass
    {
        static $plugin_info = null;

        if ($plugin_info instanceof stdClass) {
            return clone $plugin_info;
        }

        try {
            $plugin_info = self::build_plugin_info_result();
        } catch (Throwable $throwable) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '%s plugin details fallback: %s in %s:%d',
                    self::PLUGIN_NAME,
                    $throwable->getMessage(),
                    $throwable->getFile(),
                    $throwable->getLine()
                ));
            }

            $plugin_info = self::build_fallback_plugin_info_result();
        }

        return clone $plugin_info;
    }

    /**
     * Get release data from GitHub with caching.
     *
     * @return array|null Release data or null on failure.
     */
    private static function get_release_data(): ?array
    {
        $release_data = get_transient(self::CACHE_KEY);

        if (false !== $release_data && is_array($release_data)) {
            return $release_data;
        }

        // A failure is cached too, briefly. Without it, every update check waits
        // out the full 15-second timeout for as long as GitHub is unreachable,
        // and update checks happen on ordinary admin page loads.
        if ('failed' === $release_data) {
            return null;
        }

        $response = wp_remote_get(
            sprintf('https://api.github.com/repos/%s/%s/releases/latest', self::GITHUB_USER, self::GITHUB_REPO),
            array(
                'user-agent' => 'WordPress/' . self::PLUGIN_SLUG,
                'timeout' => 15,
                'headers' => !empty(self::GITHUB_TOKEN)
                    ? array('Authorization' => 'token ' . self::GITHUB_TOKEN)
                    : array(),
            )
        );

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(self::PLUGIN_NAME . ' Update Error: ' . $response->get_error_message());
            }
            set_transient(self::CACHE_KEY, 'failed', self::FAILURE_CACHE_EXPIRATION);
            return null;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if (200 !== $response_code) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(self::PLUGIN_NAME . " Update Error: HTTP {$response_code}");
            }
            set_transient(self::CACHE_KEY, 'failed', self::FAILURE_CACHE_EXPIRATION);
            return null;
        }

        $release_data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($release_data['tag_name'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(self::PLUGIN_NAME . ' Update Error: No tag_name in release');
            }
            set_transient(self::CACHE_KEY, 'failed', self::FAILURE_CACHE_EXPIRATION);
            return null;
        }

        set_transient(self::CACHE_KEY, $release_data, self::CACHE_EXPIRATION);

        return $release_data;
    }

    /**
     * Get the download URL for the plugin package.
     *
     * @param array $release_data Release data from GitHub API.
     * @return string Download URL for the plugin package.
     */
    private static function get_package_url(array $release_data): string
    {
        if (!empty($release_data['assets']) && is_array($release_data['assets'])) {
            // Prefer the asset our release workflow builds, by exact name. Taking
            // the first .zip encountered picks up whatever else a release happens
            // to carry - a source archive, a translation bundle - and installs it
            // as the plugin.
            foreach ($release_data['assets'] as $asset) {
                if (
                    isset($asset['browser_download_url'], $asset['name'])
                    && self::GITHUB_REPO . '.zip' === $asset['name']
                ) {
                    return $asset['browser_download_url'];
                }
            }

            foreach ($release_data['assets'] as $asset) {
                if (
                    isset($asset['browser_download_url']) &&
                    isset($asset['name']) &&
                    substr($asset['name'], -4) === '.zip'
                ) {
                    return $asset['browser_download_url'];
                }
            }
        }

        return $release_data['zipball_url'] ?? '';
    }

    /**
     * Get a package URL suitable for the plugin details footer action button.
     *
     * @param array|null $release_data Release data from GitHub API.
     * @return string
     */
    private static function get_plugin_info_download_link(?array $release_data = null): string
    {
        if (is_array($release_data)) {
            $package_url = self::get_package_url($release_data);

            if ('' !== $package_url) {
                return $package_url;
            }
        }

        return sprintf(
            'https://github.com/%s/%s/releases/latest/download/%s.zip',
            self::GITHUB_USER,
            self::GITHUB_REPO,
            self::GITHUB_REPO
        );
    }

    /**
     * Check for plugin updates from GitHub.
     *
     * @param array|false $update      The plugin update data.
     * @param array       $plugin_data Plugin headers.
     * @param string      $plugin_file Plugin file path.
     * @param array       $locales     Installed locales.
     * @return array|false Updated plugin data or false.
     */
    public static function check_for_update($update, array $plugin_data, string $plugin_file, $locales)
    {
        if (self::get_plugin_file() !== $plugin_file) {
            return $update;
        }

        $release_data = self::get_release_data();
        if (null === $release_data) {
            return $update;
        }

        $new_version = ltrim($release_data['tag_name'], 'v');

        if (version_compare($plugin_data['Version'], $new_version, '>=')) {
            return $update;
        }

        return array(
            'id'           => 'github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO,
            'slug'         => self::PLUGIN_SLUG,
            'plugin'       => self::get_plugin_file(),
            'new_version'  => $new_version,
            'version'      => $new_version,
            'package'      => self::get_package_url($release_data),
            'url'          => $release_data['html_url'],
            'tested'       => self::TESTED_WP,
            'requires_php' => self::REQUIRES_PHP,
            'compatibility' => new stdClass(),
            'icons'        => array(),
            'banners'      => array(),
        );
    }

    /**
     * Provide plugin information for the WordPress plugin details popup.
     *
     * @param false|object|array $res    The result object or array.
     * @param string             $action The type of information being requested.
     * @param object             $args   Plugin API arguments.
     * @return false|object Plugin information or false.
     */
    public static function plugin_info($res, $action, $args)
    {
        if (!self::is_plugin_information_api_request($action, $args)) {
            return $res;
        }

        return self::get_safe_plugin_info_result();
    }

    /**
     * Build plugin information for the WordPress details modal.
     *
     * @return stdClass
     */
    private static function build_plugin_info_result(): stdClass
    {
        $release_data = self::get_release_data();

        $installed_version = '1.0.0';

        if (defined('UPILOT_VERSION')) {
            $installed_version = UPILOT_VERSION;
        } else {
            if (!function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $plugin_file = WP_PLUGIN_DIR . '/' . self::get_plugin_file();
            if (file_exists($plugin_file)) {
                $plugin_data = get_plugin_data($plugin_file, false, false);
                $installed_version = $plugin_data['Version'] ?? '1.0.0';
            }
        }

        $release_version = $release_data
            ? ltrim($release_data['tag_name'], 'v')
            : '';
        $version = $installed_version;
        $has_update = '' !== $release_version
            && version_compare($release_version, $installed_version, '>');

        if ($has_update) {
            $version = $release_version;
        }

        $res               = new stdClass();
        $res->name         = self::PLUGIN_NAME;
        $res->slug         = self::PLUGIN_SLUG;
        $res->plugin       = self::get_plugin_file();
        $res->version      = $version;
        $res->author       = sprintf('<a href="https://github.com/%s">%s</a>', self::GITHUB_USER, self::GITHUB_USER);
        $res->homepage     = sprintf('https://github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO);
        $res->requires     = self::REQUIRES_WP;
        $res->tested       = get_bloginfo('version');
        $res->requires_php = self::REQUIRES_PHP;
        $res->external     = true;
        $res->banners      = array();
        $res->icons        = array();

        $download_link = self::get_plugin_info_download_link($release_data);

        if ('' !== $download_link) {
            $res->download_link = $download_link;
        }

        if ($release_data && !empty($release_data['published_at'])) {
            $res->last_updated  = $release_data['published_at'];
        }

        $readme = self::parse_readme();

        $res->sections = array(
            'description'  => !empty($readme['description'])
                ? $readme['description']
                : '<p>' . esc_html(self::PLUGIN_DESCRIPTION) . '</p>',
        );

        if (!empty($readme['installation'])) {
            $res->sections['installation'] = $readme['installation'];
        }

        if (!empty($readme['faq'])) {
            $res->sections['faq'] = $readme['faq'];
        }

        $changelog_html = '';

        if ($has_update && !empty($release_data['body'])) {
            $changelog_html .= '<h4>' . esc_html($release_version) . '</h4>'
                             . self::markdown_to_html($release_data['body']);
        }

        if (!empty($readme['changelog'])) {
            $changelog_html .= $readme['changelog'];
        }

        $res->sections['changelog'] = !empty($changelog_html)
            ? $changelog_html
            : sprintf(
                '<p>See <a href="https://github.com/%s/%s/releases" target="_blank">GitHub releases</a> for changelog.</p>',
                esc_attr(self::GITHUB_USER),
                esc_attr(self::GITHUB_REPO)
            );

        return $res;
    }

    /**
     * Build a small fallback payload if plugin details generation fails.
     *
     * @return stdClass
     */
    private static function build_fallback_plugin_info_result(): stdClass
    {
        $result               = new stdClass();
        $result->name         = self::PLUGIN_NAME;
        $result->slug         = self::PLUGIN_SLUG;
        $result->plugin       = self::get_plugin_file();
        $result->version      = defined('UPILOT_VERSION') ? UPILOT_VERSION : '1.0.0';
        $result->author       = sprintf('<a href="https://github.com/%s">%s</a>', self::GITHUB_USER, self::GITHUB_USER);
        $result->homepage     = sprintf('https://github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO);
        $result->requires     = self::REQUIRES_WP;
        $result->tested       = get_bloginfo('version');
        $result->requires_php = self::REQUIRES_PHP;
        $result->external     = true;
        $result->banners      = array();
        $result->icons        = array();

        $download_link = self::get_plugin_info_download_link();

        if ('' !== $download_link) {
            $result->download_link = $download_link;
        }

        $result->sections     = array(
            'description' => '<p>' . esc_html(self::PLUGIN_DESCRIPTION) . '</p>',
            'changelog'   => sprintf(
                '<p>See <a href="https://github.com/%s/%s/releases" target="_blank">GitHub releases</a> for changelog.</p>',
                esc_attr(self::GITHUB_USER),
                esc_attr(self::GITHUB_REPO)
            ),
        );

        return $result;
    }

    /**
     * Inject CSS overrides in the plugin-information iframe.
     *
     * wp_kses_post() strips <style> tags from section content, so CSS must be
     * injected via the admin_head hook which fires inside the iframe's <head>.
     *
     * A CSS geometric pattern replaces the banner image area: WordPress only adds
     * the `with-banner` class to #plugin-information-title when $api->banners
     * contains real image URLs. A small JS snippet adds the class manually so the
     * CSS pattern and h2 styling apply without any external image.
     *
     * @return void
     */
    public static function plugin_info_css(): void
    {
        if (!isset($_GET['plugin'], $_GET['tab'])) {
            return;
        }
        if ('plugin-information' !== sanitize_text_field(wp_unslash($_GET['tab']))
            || self::PLUGIN_SLUG !== sanitize_text_field(wp_unslash($_GET['plugin']))) {
            return;
        }

        $pattern_css = '--s: 27px;'
            . '--c1: #b2b2b2;'
            . '--c2: #ffffff;'
            . '--c3: #d9d9d9;'
            . '--_g: var(--c3) 0 120deg, #0000 0;';

        $pattern_bg = 'conic-gradient(from -60deg at 50% calc(100%/3), var(--_g)),'
            . 'conic-gradient(from 120deg at 50% calc(200%/3), var(--_g)),'
            . 'conic-gradient(from 60deg at calc(200%/3), var(--c3) 60deg, var(--c2) 0 120deg, #0000 0),'
            . 'conic-gradient(from 180deg at calc(100%/3), var(--c1) 60deg, var(--_g)),'
            . 'linear-gradient(90deg, var(--c1) calc(100%/6), var(--c2) 0 50%,'
            . 'var(--c1) 0 calc(500%/6), var(--c2) 0)';

        echo '<style>'
            . '#plugin-information-title.with-banner {'
            .   $pattern_css
            .   'background: ' . $pattern_bg . ' !important;'
            .   'background-size: calc(1.732 * var(--s)) var(--s) !important;'
            . '}'
            . '#plugin-information-title.with-banner h2 {'
            .   'position: relative;'
            .   'font-family: "Helvetica Neue", sans-serif;'
            .   'display: inline-block;'
            .   'font-size: 30px;'
            .   'line-height: 1.68;'
            .   'box-sizing: border-box;'
            .   'max-width: 100%;'
            .   'padding: 0 15px;'
            .   'margin-top: 174px;'
            .   'color: #fff;'
            .   'background: rgba(29, 35, 39, 0.9);'
            .   'text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);'
            .   'box-shadow: 0 0 30px rgba(255, 255, 255, 0.1);'
            .   'border-radius: 8px;'
            . '}'
            . '#section-holder .section h2 { margin: 1.5em 0 0.5em; clear: none; }'
            . '#section-holder .section h3 { margin: 1.5em 0 0.5em; }'
            . '#section-holder .section > :first-child { margin-top: 0; }'
            . '.md-table { display: table; width: 100%; border-collapse: collapse; margin: 1em 0; font-size: 13px; }'
            . '.md-tr { display: table-row; }'
            . '.md-tr > span { display: table-cell; padding: 6px 10px; border: 1px solid #ddd; vertical-align: top; }'
            . '.md-th > span { font-weight: 600; background: #f5f5f5; }'
            . '</style>';

        echo '<script>'
            . 'document.addEventListener("DOMContentLoaded",function(){'
            . 'var title=document.getElementById("plugin-information-title");'
            . 'if(title){title.classList.add("with-banner");}'
            . '});'
            . '</script>';
    }

    // ------------------------------------------------------------------
    // README.md parsing
    // ------------------------------------------------------------------

    /**
     * Parse the local README.md into description, installation, FAQ and changelog HTML.
     *
     * @return array{description: string, installation: string, faq: string, changelog: string}
     */
    private static function parse_readme(): array
    {
        $readme_path = WP_PLUGIN_DIR . '/' . dirname(self::get_plugin_file()) . '/README.md';

        if (!file_exists($readme_path)) {
            return array();
        }

        $content = file_get_contents($readme_path);
        if (false === $content) {
            return array();
        }

        $content = preg_replace('/^#\s+[^\n]+\n*/m', '', $content, 1);

        $utility_sections = array(
            'changelog', 'requirements', 'installation', 'faq',
            'project structure', 'acknowledgements', 'license',
        );

        $parts = preg_split('/^##\s+/m', $content);

        $description  = trim($parts[0] ?? '');
        $installation = '';
        $faq          = '';
        $changelog    = '';

        for ($i = 1, $count = count($parts); $i < $count; $i++) {
            $lines = explode("\n", $parts[$i], 2);
            $title = strtolower(trim($lines[0]));
            $body  = trim($lines[1] ?? '');

            if ('installation' === $title) {
                $installation .= $body . "\n\n";
            } elseif ('faq' === $title) {
                $faq .= $body . "\n\n";
            } elseif ('changelog' === $title) {
                $changelog .= $body . "\n\n";
            } elseif (!in_array($title, $utility_sections, true)) {
                $description .= "\n\n## " . trim($lines[0]) . "\n" . $body;
            }
        }

        return array(
            'description'  => self::markdown_to_html(trim($description)),
            'installation' => self::markdown_to_html(trim($installation)),
            'faq'          => self::markdown_to_html(trim($faq)),
            'changelog'    => self::markdown_to_html(trim($changelog)),
        );
    }

    /**
     * Convert Markdown to HTML using Parsedown.
     *
     * @param string $markdown Markdown source.
     * @return string
     */
    private static function markdown_to_html(string $markdown): string
    {
        if ('' === $markdown) {
            return '';
        }

        $markdown = preg_replace('/!\[[^\]]*\]\([^\)]+\)/', '', $markdown);
        $markdown = preg_replace('/<p\b[^>]*>\s*(?:(?:<a\b[^>]*>\s*)?<img\b[^>]*>\s*(?:<\/a>\s*)?)+<\/p>\s*/is', '', $markdown);
        $markdown = preg_replace('/(?:<a\b[^>]*>\s*)?<img\b[^>]*>\s*(?:<\/a>)?/i', '', $markdown);

        if (!class_exists('Parsedown')) {
            require_once __DIR__ . '/Parsedown.php';
        }

        $parsedown = new Parsedown();
        $parsedown->setMarkupEscaped(true);

        $html = $parsedown->text($markdown);

        $html = self::tables_to_divs($html);

        return $html;
    }

    /**
     * Convert HTML tables to div/span structures compatible with wp_kses.
     *
     * @param string $html HTML containing <table> elements.
     * @return string HTML with tables replaced by styled div/span.
     */
    private static function tables_to_divs(string $html): string
    {
        return preg_replace_callback('/<table>(.*?)<\/table>/s', function ($m) {
            $table_html = $m[1];
            $output = '<div class="md-table">';

            preg_match_all('/<tr>(.*?)<\/tr>/s', $table_html, $rows);

            foreach ($rows[1] as $idx => $row_content) {
                $is_header = (0 === $idx && strpos($table_html, '<thead>') !== false);
                $row_class = $is_header ? 'md-tr md-th' : 'md-tr';

                preg_match_all('/<t[hd]>(.*?)<\/t[hd]>/s', $row_content, $cells);

                $output .= '<div class="' . $row_class . '">';
                foreach ($cells[1] as $cell) {
                    $output .= '<span>' . $cell . '</span>';
                }
                $output .= '</div>';
            }

            $output .= '</div>';
            return $output;
        }, $html);
    }

    /**
     * Rename the extracted folder to match the expected plugin folder name.
     *
     * @param string      $source        File source location.
     * @param string      $remote_source Remote file source location.
     * @param WP_Upgrader $upgrader      WP_Upgrader instance.
     * @param array       $hook_extra    Extra arguments passed to hooked filters.
     * @return string|WP_Error The corrected source path or WP_Error on failure.
     */
    public static function fix_folder_name($source, $remote_source, $upgrader, $hook_extra)
    {
        global $wp_filesystem;

        if (!isset($hook_extra['plugin'])) {
            return $source;
        }

        if (self::get_plugin_file() !== $hook_extra['plugin']) {
            return $source;
        }

        $correct_folder = self::get_plugin_directory();

        $source_folder = basename(untrailingslashit($source));

        if ($source_folder === $correct_folder) {
            return $source;
        }

        $new_source = trailingslashit($remote_source) . $correct_folder . '/';

        if ($wp_filesystem && $wp_filesystem->move($source, $new_source)) {
            return $new_source;
        }

        if ($wp_filesystem && $wp_filesystem->copy($source, $new_source, true) && $wp_filesystem->delete($source, true)) {
            return $new_source;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '%s updater: failed to rename update folder from %s to %s',
                self::PLUGIN_NAME,
                $source,
                $new_source
            ));
        }

        return new WP_Error(
            'rename_failed',
            __('Unable to rename the update folder. Please retry or update manually.', 'update-pilot')
        );
    }
}

// Initialize the updater
Update_Pilot_GitHub_Updater::init();
