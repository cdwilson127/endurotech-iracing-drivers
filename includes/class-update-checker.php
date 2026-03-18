<?php
/**
 * Lightweight GitHub release updater.
 *
 * Checks the GitHub releases API every 12 hours.  When a newer tag is found
 * WordPress shows the standard "Update Available" notice and allows one-click
 * update from the Plugins screen — all without reinstalling or losing data.
 *
 * All plugin data lives in wp_options (edr_iracing_settings, edr_style_settings,
 * edr_driver_profiles) and is completely untouched by file-level updates.
 *
 * Release workflow:
 *   1. Bump EDR_IRACING_VERSION in the plugin header + constant.
 *   2. Commit and push to GitHub.
 *   3. Create a GitHub Release tagged e.g. "v1.7.0".
 *   4. WordPress sites running the plugin will see "Update Available" within
 *      12 hours (or immediately via the Plugins screen "Check Again" link).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EDR_Update_Checker {

    private $plugin_file;
    private $plugin_slug;
    private $github_repo   = 'cdwilson127/endurotech-iracing-drivers';
    private $transient_key = 'edr_github_release_data';

    public function __construct( $plugin_file ) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename( $plugin_file );

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api',                           array( $this, 'plugin_info' ), 20, 3 );
        add_filter( 'upgrader_source_selection',             array( $this, 'fix_source_dir' ), 10, 4 );
        add_action( 'upgrader_process_complete',             array( $this, 'purge_release_cache' ), 10, 2 );
    }

    /* -----------------------------------------------------------------
     * Fetch latest release from GitHub (cached 12 h)
     * ----------------------------------------------------------------- */

    private function get_release() {
        $cached = get_transient( $this->transient_key );
        // 'none' is the sentinel value cached when no release was found (avoids hammering GitHub API).
        if ( false !== $cached ) {
            return ( 'none' === $cached ) ? null : $cached;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . $this->github_repo . '/releases/latest',
            array(
                'timeout' => 10,
                'headers' => array(
                    'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
                    'Accept'     => 'application/vnd.github.v3+json',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            error_log( 'EDR Update Checker: HTTP error — ' . $response->get_error_message() );
            set_transient( $this->transient_key, 'none', HOUR_IN_SECONDS );
            return null;
        }

        $code = intval( wp_remote_retrieve_response_code( $response ) );
        if ( 200 !== $code ) {
            error_log( 'EDR Update Checker: GitHub API returned HTTP ' . $code . '. No release found or repo is private.' );
            set_transient( $this->transient_key, 'none', HOUR_IN_SECONDS );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['tag_name'] ) ) {
            set_transient( $this->transient_key, 'none', HOUR_IN_SECONDS );
            return null;
        }

        // Prefer an uploaded ZIP asset over the auto-generated zipball (avoids folder rename issues).
        $package = '';
        if ( ! empty( $body['assets'] ) ) {
            foreach ( $body['assets'] as $asset ) {
                if ( isset( $asset['browser_download_url'] ) && substr( $asset['browser_download_url'], -4 ) === '.zip' ) {
                    $package = $asset['browser_download_url'];
                    break;
                }
            }
        }
        if ( ! $package ) {
            $package = isset( $body['zipball_url'] ) ? $body['zipball_url'] : '';
        }

        $release = array(
            'version'     => ltrim( $body['tag_name'], 'v' ),
            'package'     => $package,
            'details_url' => isset( $body['html_url'] ) ? $body['html_url'] : '',
            'changelog'   => isset( $body['body'] )     ? $body['body']     : '',
        );

        set_transient( $this->transient_key, $release, 12 * HOUR_IN_SECONDS );

        return $release;
    }

    /* -----------------------------------------------------------------
     * Hook into WordPress update checks
     * ----------------------------------------------------------------- */

    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_release();
        if ( ! $release || empty( $release['version'] ) ) {
            return $transient;
        }

        if ( version_compare( $release['version'], EDR_IRACING_VERSION, '>' ) ) {
            $transient->response[ $this->plugin_slug ] = (object) array(
                'slug'        => dirname( $this->plugin_slug ),
                'plugin'      => $this->plugin_slug,
                'new_version' => $release['version'],
                'url'         => $release['details_url'],
                'package'     => $release['package'],
            );
        }

        return $transient;
    }

    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }
        if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->plugin_slug ) ) {
            return $result;
        }

        $release = $this->get_release();
        if ( ! $release ) {
            return $result;
        }

        return (object) array(
            'name'          => 'Endurotech iRacing Drivers',
            'slug'          => dirname( $this->plugin_slug ),
            'version'       => $release['version'],
            'download_link' => $release['package'],
            'sections'      => array(
                'changelog' => nl2br( esc_html( $release['changelog'] ) ),
            ),
        );
    }

    /**
     * GitHub ZIP archives unpack to a folder named {owner}-{repo}-{hash}.
     * Rename it to the correct plugin slug so WordPress installs to the right place
     * and does not create a duplicate plugin directory.
     */
    public function fix_source_dir( $source, $remote_source, $upgrader, $extra = null ) {
        global $wp_filesystem;

        if ( ! isset( $extra['plugin'] ) || false === strpos( $extra['plugin'], 'endurotech-iracing-drivers' ) ) {
            return $source;
        }

        // Ensure the WP Filesystem is initialised before we try to move files.
        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $correct = trailingslashit( dirname( $remote_source ) ) . 'endurotech-iracing-drivers/';

        if ( $wp_filesystem && $source !== $correct ) {
            if ( $wp_filesystem->move( $source, $correct, true ) ) {
                return $correct;
            }
            error_log( 'EDR Update Checker: could not rename ' . $source . ' to ' . $correct );
        }

        return $source;
    }

    /** Purge the cached release data after a successful plugin update. */
    public function purge_release_cache( $upgrader, $extra ) {
        if ( ! isset( $extra['type'] ) || 'plugin' !== $extra['type'] ) {
            return;
        }
        $plugins = isset( $extra['plugins'] ) ? (array) $extra['plugins'] : array();
        if ( in_array( $this->plugin_slug, $plugins, true ) ) {
            delete_transient( $this->transient_key );
        }
    }
}
