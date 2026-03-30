<?php
/**
 * Admin settings — API credentials, display settings, feature toggles,
 * and per-driver profile management.
 *
 * Drivers are managed entirely from the admin. The iRacing API is optional —
 * when linked via Customer ID, live stats override the manual values.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EDR_Admin_Settings {

    private $option_group  = 'edr_iracing_settings_group';
    private $option_name   = 'edr_iracing_settings';
    private $style_group   = 'edr_style_settings_group';
    private $style_key     = 'edr_style_settings';
    private $profiles_key  = 'edr_driver_profiles';
    private $page_slug     = 'edr-iracing-drivers';
    private $profiles_slug = 'edr-iracing-profiles';

    public function __construct() {
        add_action( 'admin_menu',            array( $this, 'add_menu_pages' ) );
        add_action( 'admin_init',            array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

        add_action( 'wp_ajax_edr_fetch_single_driver', array( $this, 'ajax_fetch_single_driver' ) );
    }

    /* ================================================================
       Menu & Scripts
       ================================================================ */

    public function add_menu_pages() {
        add_menu_page(
            'iRacing Drivers', 'iRacing Drivers', 'manage_options',
            $this->page_slug, array( $this, 'render_settings_page' ),
            'dashicons-car', 81
        );
        add_submenu_page(
            $this->page_slug, 'Settings', 'Settings',
            'manage_options', $this->page_slug, array( $this, 'render_settings_page' )
        );
        add_submenu_page(
            $this->page_slug, 'Manage Drivers', 'Manage Drivers',
            'manage_options', $this->profiles_slug, array( $this, 'render_profiles_page' )
        );
    }

    public function enqueue_admin_scripts( $hook ) {
        if ( false !== strpos( $hook, $this->profiles_slug ) ) {
            wp_enqueue_media();
            wp_enqueue_script(
                'edr-admin-profiles',
                EDR_IRACING_PLUGIN_URL . 'assets/js/admin-profiles.js',
                array( 'jquery' ), EDR_IRACING_VERSION, true
            );
            wp_localize_script( 'edr-admin-profiles', 'edrAdmin', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'edr_fetch_single_driver' ),
            ) );
            wp_enqueue_style(
                'edr-admin-profiles-css',
                EDR_IRACING_PLUGIN_URL . 'assets/css/admin-profiles.css',
                array(), EDR_IRACING_VERSION
            );
        }
    }

    /* ================================================================
       Settings registration (API + Style)
       ================================================================ */

    public function register_settings() {
        // API credentials
        register_setting( $this->option_group, $this->option_name, array(
            'type'              => 'array',
            'sanitize_callback' => array( $this, 'sanitize_api' ),
        ) );

        add_settings_section( 'edr_api_section', 'iRacing API Credentials', function () {
            echo '<p>Enter your iRacing OAuth2 client credentials. These are <strong>optional</strong> &mdash; '
               . 'you can manage drivers manually without them. When connected, live stats override manual values.</p>';
        }, $this->page_slug );

        $api_fields = array(
            'client_id'     => 'Client ID',
            'client_secret' => 'Client Secret',
            'username'      => 'iRacing Username (email)',
            'password'      => 'iRacing Password',
            'team_id'       => 'Team ID',
            'cache_hours'   => 'Cache Duration (hours)',
        );
        foreach ( $api_fields as $key => $label ) {
            add_settings_field( $key, $label, function () use ( $key ) {
                $settings = get_option( $this->option_name, array() );
                $value    = esc_attr( isset( $settings[ $key ] ) ? $settings[ $key ] : '' );
                $type     = in_array( $key, array( 'client_secret', 'password' ), true ) ? 'password' : 'text';
                printf(
                    '<input type="%s" name="%s[%s]" value="%s" class="regular-text" autocomplete="off" />',
                    $type, $this->option_name, $key, $value
                );
                if ( 'team_id'     === $key ) { echo '<p class="description">Find your team ID in the iRacing member site URL when viewing your team page.</p>'; }
                if ( 'cache_hours' === $key ) { echo '<p class="description">Hours between live data refreshes. Default <strong>24</strong> (once per day). The API is only called when the cache expires — not on every page visit.</p>'; }
            }, $this->page_slug, 'edr_api_section' );
        }

        // Style settings
        register_setting( $this->style_group, $this->style_key, array(
            'type'              => 'array',
            'sanitize_callback' => array( $this, 'sanitize_style' ),
        ) );

        // Feature Toggles section
        add_settings_section( 'edr_features_section', 'Feature Toggles', function () {
            echo '<p>Enable or disable features globally. These are the defaults — override per-shortcode with attributes (e.g. <code>card_flip="no"</code>).</p>';
        }, $this->page_slug . '-style' );

        $feature_fields = array(
            'feature_card_flip'      => array( 'Card Flip on Hover',        'Flip driver cards on hover to reveal gear/bio info.' ),
            'feature_counters'       => array( 'Animated Stat Counters',    'Stats count up with animation when scrolled into view.' ),
            'feature_show_trend'     => array( 'iRating Trend Badge',       'Show ▲/▼ badge indicating iRating change since last race.' ),
            'feature_show_active'    => array( 'Recently Active Indicator', 'Green dot on drivers who raced within the last 30 days.' ),
            'feature_show_spotlight' => array( 'Driver Spotlight Card',     'Featured drivers get a hero card at the top of the grid.' ),
            'feature_show_ticker'    => array( 'Recent Race Ticker',        'Scrolling ticker of latest race results (off by default).' ),
            'feature_show_filter'    => array( 'Role Filter Bar',           'Show filter buttons to display drivers by team role (off by default).' ),
            'feature_show_summary'   => array( 'Team Summary Bar',          'Stat bar showing total wins, starts, average iRating.' ),
            'feature_show_last_race' => array( 'Last Race Result',          'Show last race result strip on each driver card.' ),
            'feature_show_photo'     => array( 'Driver Photos',             'Display uploaded driver photos.' ),
            'feature_show_gear'      => array( 'Sim Setup / Gear Section',  'Show sim hardware info on card back (or front in non-flip mode).' ),
        );

        foreach ( $feature_fields as $key => $info ) {
            add_settings_field( $key, $info[0], function () use ( $key, $info ) {
                $style = get_option( $this->style_key, array() );
                $val   = isset( $style[ $key ] ) ? $style[ $key ] : '1';
                printf(
                    '<label><input type="checkbox" name="%s[%s]" value="1" %s /> %s</label>',
                    $this->style_key, $key, checked( '1', $val, false ), esc_html( $info[1] )
                );
            }, $this->page_slug . '-style', 'edr_features_section' );
        }

        // Display Style section
        add_settings_section( 'edr_style_section', 'Display Style', function () {
            echo '<p>Customise colours, border radius, and the subtitle text. Changes apply site-wide.</p>';
        }, $this->page_slug . '-style' );

        $style_fields = array(
            'accent_color'  => array( 'Accent Colour',      'color',  '#f0f000',  'Card borders, badges, and highlights.' ),
            'card_bg'       => array( 'Card Background',    'color',  '#111111',  'Background colour for individual driver cards.' ),
            'border_radius' => array( 'Border Radius (px)', 'number', '0',        'Rounded corners on cards (0 = sharp, matches site style).' ),
            'max_width'     => array( 'Max Width',          'text',   '',         'Max width of the whole section, e.g. 1200px or 90%. Leave blank for full width.' ),
            'subtitle_text' => array( 'Subtitle Text',      'text',   '',         'Custom text below the heading. Leave blank for the default. Type "none" to hide it entirely.' ),
            'ticker_speed'  => array( 'Ticker Speed (seconds)', 'ticker_speed', '60', 'How many seconds for one full scroll cycle. Higher = slower. Default 60.' ),
        );

        foreach ( $style_fields as $key => $info ) {
            add_settings_field( $key, $info[0], function () use ( $key, $info ) {
                $style = get_option( $this->style_key, array() );
                $val   = isset( $style[ $key ] ) ? $style[ $key ] : $info[2];
                if ( 'color' === $info[1] ) {
                    printf(
                        '<input type="color" name="%s[%s]" value="%s" id="edr-color-%s" style="height:34px;padding:2px;cursor:pointer" /> '
                        . '<input type="text" value="%s" class="small-text" placeholder="%s" '
                        . 'oninput="document.getElementById(\'edr-color-%s\').value=this.value" /> '
                        . '<p class="description">%s</p>',
                        $this->style_key, $key, esc_attr( $val ), esc_attr( $key ),
                        esc_attr( $val ), esc_attr( $info[2] ),
                        esc_attr( $key ),
                        esc_html( $info[3] )
                    );
                } elseif ( 'number' === $info[1] ) {
                    printf(
                        '<input type="number" name="%s[%s]" value="%s" min="0" max="30" class="small-text" /> px'
                        . '<p class="description">%s</p>',
                        $this->style_key, $key, esc_attr( $val ), esc_html( $info[3] )
                    );
                } elseif ( 'ticker_speed' === $info[1] ) {
                    printf(
                        '<input type="number" name="%s[%s]" value="%s" min="5" max="300" class="small-text" /> seconds'
                        . '<p class="description">%s</p>',
                        $this->style_key, $key, esc_attr( $val ), esc_html( $info[3] )
                    );
                } else {
                    printf(
                        '<input type="text" name="%s[%s]" value="%s" class="regular-text" placeholder="%s" />'
                        . '<p class="description">%s</p>',
                        $this->style_key, $key, esc_attr( $val ), esc_attr( $info[2] ), esc_html( $info[3] )
                    );
                }
            }, $this->page_slug . '-style', 'edr_style_section' );
        }
    }

    public function sanitize_api( $input ) {
        $sanitized = array(
            'client_id'     => sanitize_text_field( isset( $input['client_id'] )     ? $input['client_id']     : '' ),
            'client_secret' => sanitize_text_field( isset( $input['client_secret'] ) ? $input['client_secret'] : '' ),
            'username'      => sanitize_email( isset( $input['username'] )           ? $input['username']      : '' ),
            'password'      => isset( $input['password'] ) ? $input['password'] : '',
            'team_id'       => absint( isset( $input['team_id'] )     ? $input['team_id']     : 0 ),
            'cache_hours'   => max( 1, absint( isset( $input['cache_hours'] ) ? $input['cache_hours'] : 1 ) ),
        );

        // Reschedule cron whenever the sync interval changes.
        $old = get_option( 'edr_iracing_settings', array() );
        $old_hours = isset( $old['cache_hours'] ) ? intval( $old['cache_hours'] ) : 24;
        if ( intval( $sanitized['cache_hours'] ) !== $old_hours ) {
            if ( function_exists( 'edr_schedule_sync' ) ) {
                edr_schedule_sync();
            }
        }

        return $sanitized;
    }

    public function sanitize_style( $input ) {
        $feature_keys = array(
            'feature_card_flip', 'feature_counters', 'feature_show_trend',
            'feature_show_active', 'feature_show_spotlight', 'feature_show_ticker',
            'feature_show_filter', 'feature_show_summary', 'feature_show_last_race',
            'feature_show_photo', 'feature_show_gear',
        );

        $out = array();

        foreach ( $feature_keys as $k ) {
            $out[ $k ] = ( isset( $input[ $k ] ) && '1' === strval( $input[ $k ] ) ) ? '1' : '0';
        }

        // Accent colour — validate hex
        $accent = isset( $input['accent_color'] ) ? trim( $input['accent_color'] ) : '#f1c40f';
        $out['accent_color'] = preg_match( '/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $accent ) ? $accent : '#f1c40f';

        $card_bg = isset( $input['card_bg'] ) ? trim( $input['card_bg'] ) : '#161616';
        $out['card_bg'] = preg_match( '/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $card_bg ) ? $card_bg : '#161616';

        $out['border_radius'] = min( 30, max( 0, absint( isset( $input['border_radius'] ) ? $input['border_radius'] : 10 ) ) );
        $out['subtitle_text'] = sanitize_text_field( isset( $input['subtitle_text'] ) ? $input['subtitle_text'] : '' );
        $out['ticker_speed']  = min( 300, max( 5, absint( isset( $input['ticker_speed'] ) ? $input['ticker_speed'] : 60 ) ) );
        $raw_mw = isset( $input['max_width'] ) ? trim( $input['max_width'] ) : '';
        $out['max_width'] = preg_replace( '/[^0-9a-zA-Z%\.px]/', '', $raw_mw );

        return $out;
    }

    /* ================================================================
       Settings page
       ================================================================ */

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $settings = get_option( $this->option_name, array() );
        $is_configured = ! empty( $settings['client_id'] ) && ! empty( $settings['client_secret'] )
                      && ! empty( $settings['username'] )  && ! empty( $settings['password'] );
        ?>
        <div class="wrap">
            <h1>iRacing Drivers &mdash; Settings</h1>

            <?php if ( ! $is_configured ) : ?>
            <div class="notice notice-info">
                <p>API credentials are <strong>optional</strong>. You can add drivers manually on the
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->profiles_slug ) ); ?>">Manage Drivers</a> page without them.</p>
            </div>
            <?php endif; ?>

            <!-- ── API Credentials ── -->
            <h2>API Credentials</h2>
            <form method="post" action="options.php">
                <?php settings_fields( $this->option_group ); ?>
                <?php do_settings_sections( $this->page_slug ); ?>
                <?php submit_button( 'Save API Settings' ); ?>
            </form>

            <hr />

            <!-- ── Feature Toggles + Display Style ── -->
            <h2>Feature Toggles &amp; Display Style</h2>
            <form method="post" action="options.php">
                <?php settings_fields( $this->style_group ); ?>
                <?php do_settings_sections( $this->page_slug . '-style' ); ?>
                <?php submit_button( 'Save Display Settings' ); ?>
            </form>

            <hr />

            <!-- ── Cache Management ── -->
            <h2>Cache Management</h2>
            <p>Driver data is cached to avoid excessive API calls.</p>
            <form method="post">
                <?php wp_nonce_field( 'edr_clear_cache', 'edr_cache_nonce' ); ?>
                <input type="hidden" name="edr_clear_cache" value="1" />
                <?php submit_button( 'Clear Driver Cache', 'secondary' ); ?>
            </form>
            <?php $this->handle_cache_clear(); ?>

            <hr />

            <!-- ── Plugin Update Checker ── -->
            <h2>Plugin Update Checker</h2>
            <p>Tests the connection to GitHub and shows exactly what the update checker sees.</p>
            <form method="post">
                <?php wp_nonce_field( 'edr_test_updater', 'edr_updater_nonce' ); ?>
                <input type="hidden" name="edr_test_updater" value="1" />
                <?php submit_button( 'Test Update Checker', 'secondary' ); ?>
            </form>
            <?php $this->handle_updater_test(); ?>

            <hr />

            <!-- ── Shortcode Reference ── -->
            <h2>Shortcode Reference</h2>
            <p>Place on any page or post:</p>
            <pre style="background:#f5f5f5;padding:12px;border-radius:4px;">[iracing_drivers]</pre>

            <table class="widefat striped" style="max-width:1000px">
                <thead><tr><th>Attribute</th><th>Default</th><th>Options</th><th>Description</th></tr></thead>
                <tbody>
                    <tr><td><code>title</code></td><td>Our Drivers</td><td>Any text</td><td>Main section heading</td></tr>
                    <tr><td><code>label</code></td><td>The Team</td><td>Any text or empty</td><td>Small uppercase label above the heading (leave empty to hide)</td></tr>
                    <tr><td><code>layout</code></td><td>cards</td><td>cards, table</td><td>Card grid or compact table</td></tr>
                    <tr><td><code>columns</code></td><td>auto</td><td>auto, 1, 2, 3, 4</td><td>Cards per row (cards layout only)</td></tr>
                    <tr><td><code>sort_by</code></td><td>irating</td><td>irating, wins, starts, name, custom</td><td>Sort field. <em>custom</em> uses Display Order</td></tr>
                    <tr><td><code>sort_order</code></td><td>desc</td><td>asc, desc</td><td>Ascending or descending</td></tr>
                    <tr><td><code>accent</code></td><td>auto</td><td>auto, red, blue, green, gold</td><td><em>auto</em> uses the accent colour set above; presets override it</td></tr>
                    <tr><td><code>card_style</code></td><td>default</td><td>default, minimal</td><td><em>minimal</em> strips the card to essentials</td></tr>
                    <tr><td colspan="4" style="background:#f9f9f9;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.05em">Feature toggles — use &ldquo;yes&rdquo;, &ldquo;no&rdquo;, or &ldquo;inherit&rdquo; (uses admin default)</td></tr>
                    <tr><td><code>show_summary</code></td><td>inherit</td><td>yes, no</td><td>Team stat bar at the top</td></tr>
                    <tr><td><code>show_last_race</code></td><td>inherit</td><td>yes, no</td><td>Last race result strip</td></tr>
                    <tr><td><code>show_photo</code></td><td>inherit</td><td>yes, no</td><td>Driver photos</td></tr>
                    <tr><td><code>show_gear</code></td><td>inherit</td><td>yes, no</td><td>Sim gear / setup section</td></tr>
                    <tr><td><code>card_flip</code></td><td>inherit</td><td>yes, no</td><td>3D flip on hover to reveal bio/gear</td></tr>
                    <tr><td><code>counters</code></td><td>inherit</td><td>yes, no</td><td>Animated stat counters</td></tr>
                    <tr><td><code>show_trend</code></td><td>inherit</td><td>yes, no</td><td>iRating trend badge (▲/▼)</td></tr>
                    <tr><td><code>show_active</code></td><td>inherit</td><td>yes, no</td><td>Recently active indicator</td></tr>
                    <tr><td><code>show_spotlight</code></td><td>inherit</td><td>yes, no</td><td>Featured driver spotlight card</td></tr>
                    <tr><td><code>show_ticker</code></td><td>inherit</td><td>yes, no</td><td>Scrolling race results ticker</td></tr>
                    <tr><td><code>show_filter</code></td><td>inherit</td><td>yes, no</td><td>Role filter bar</td></tr>
                    <tr><td><code>ticker_speed</code></td><td>inherit</td><td>5&ndash;300</td><td>Ticker scroll duration in seconds (higher = slower). Inherits admin setting.</td></tr>
                    <tr><td colspan="4" style="background:#f9f9f9;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.05em">Individual stat columns (always explicit)</td></tr>
                    <tr><td><code>show_role</code></td><td>yes</td><td>yes, no</td><td>Team role badge</td></tr>
                    <tr><td><code>show_number</code></td><td>yes</td><td>yes, no</td><td>Driver number</td></tr>
                    <tr><td><code>show_wins</code></td><td>yes</td><td>yes, no</td><td>Wins stat</td></tr>
                    <tr><td><code>show_starts</code></td><td>yes</td><td>yes, no</td><td>Starts stat</td></tr>
                    <tr><td><code>show_top5</code></td><td>yes</td><td>yes, no</td><td>Top 5s stat</td></tr>
                    <tr><td><code>show_laps</code></td><td>yes</td><td>yes, no</td><td>Laps stat</td></tr>
                    <tr><td><code>demo</code></td><td>no</td><td>yes, no</td><td>Show sample data (no API needed)</td></tr>
                </tbody>
            </table>

            <h3>Example:</h3>
            <pre style="background:#f5f5f5;padding:12px;border-radius:4px;">[iracing_drivers title="EDR Roster" layout="cards" columns="3" sort_by="custom" card_flip="yes" show_ticker="yes"]</pre>
        </div>
        <?php
    }

    /* ================================================================
       Manage Drivers page
       ================================================================ */

    public function render_profiles_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $this->process_profiles_actions();

        $profiles = get_option( $this->profiles_key, array() );
        if ( ! is_array( $profiles ) ) { $profiles = array(); }

        $api_configured = $this->is_api_configured();
        $api_cache      = get_transient( 'edr_iracing_drivers_cache' );
        if ( ! is_array( $api_cache ) || empty( $api_cache ) ) {
            $api_cache = get_option( 'edr_iracing_api_snapshot', array() );
        }
        if ( ! is_array( $api_cache ) ) { $api_cache = array(); }

        $role_options = array(
            ''        => '-- No role --',
            'captain' => 'Team Captain',
            'lead'    => 'Lead Driver',
            'pro'     => 'Pro Driver',
            'silver'  => 'Silver Driver',
            'bronze'  => 'Bronze Driver',
            'reserve' => 'Reserve Driver',
            'academy' => 'Academy',
        );

        $gear_fields = array(
            'wheel'   => 'Wheel Base / Wheel',
            'pedals'  => 'Pedals',
            'rig'     => 'Rig / Cockpit',
            'monitor' => 'Monitor / VR',
            'pc'      => 'PC Specs',
            'other'   => 'Other Gear',
        );

        $flag_options = $this->get_flag_options();
        ?>
        <div class="wrap">
            <h1>Manage Drivers</h1>
            <p>Add drivers manually and configure everything here. If an iRacing Customer ID is set and the API is connected, live stats will override manual values automatically.</p>
            <p><em>Tip: drag the <strong>&#9776;</strong> handle on any driver card to reorder them (updates the Display Order automatically).</em></p>

            <?php if ( isset( $_GET['msg'] ) ) : ?>
                <?php $this->render_admin_notice( sanitize_text_field( $_GET['msg'] ) ); ?>
            <?php endif; ?>

            <!-- Add new driver -->
            <div class="edr-add-driver-box">
                <h3>Add New Driver</h3>
                <form method="post" action="" class="edr-add-driver-form">
                    <input type="hidden" name="edr_action" value="add_driver" />
                    <?php wp_nonce_field( 'edr_add_driver', 'edr_add_nonce' ); ?>
                    <label>
                        <span>Driver Name <strong style="color:#f1c40f">*</strong></span>
                        <input type="text" name="driver_name" required placeholder="e.g. Chris Wilson" />
                    </label>
                    <label>
                        <span>iRacing Customer ID <small>(optional &mdash; links to live API data)</small></span>
                        <input type="text" name="cust_id" placeholder="e.g. 123456" />
                    </label>
                    <label>
                        <span>Team Role</span>
                        <select name="role">
                            <?php foreach ( $role_options as $val => $lbl ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $lbl ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Driver Number</span>
                        <input type="text" name="number" placeholder="e.g. 18" />
                    </label>
                    <input type="submit" class="button button-primary" value="Add Driver" />
                </form>
            </div>

            <!-- Sync from API -->
            <?php if ( $api_configured ) : ?>
            <div class="edr-sync-box">
                <form method="post" action="" style="display:inline">
                    <input type="hidden" name="edr_action" value="sync_api" />
                    <?php wp_nonce_field( 'edr_sync_api', 'edr_sync_nonce' ); ?>
                    <input type="submit" class="button button-secondary" value="Sync Team Roster from iRacing API"
                           onclick="return confirm('This will fetch your team roster and add any new drivers. Existing drivers will not be changed. Continue?');" />
                </form>
                <p class="description">Pulls your iRacing team roster and adds any drivers not already listed below.</p>
            </div>
            <?php endif; ?>

            <!-- Existing drivers -->
            <?php if ( empty( $profiles ) ) : ?>
                <div class="notice notice-info" style="margin-top:20px"><p>No drivers yet. Use the form above to add your first driver, or sync from the iRacing API.</p></div>
            <?php else : ?>
                <form method="post" action="">
                    <input type="hidden" name="edr_action" value="save_profiles" />
                    <?php wp_nonce_field( 'edr_save_profiles', 'edr_profiles_nonce' ); ?>

                    <div class="edr-profiles-grid" id="edr-sortable-grid">
                        <?php foreach ( $profiles as $driver_id => $profile ) :
                            $name      = isset( $profile['name'] )      ? $profile['name']      : 'Unknown';
                            $cust_id   = isset( $profile['cust_id'] )   ? $profile['cust_id']   : '';
                            $photo     = isset( $profile['photo_url'] ) ? $profile['photo_url'] : '';
                            $featured  = ! empty( $profile['featured'] );
                            $flag_code = isset( $profile['flag_code'] ) ? $profile['flag_code'] : '';

                            $api_linked = false;
                            $api_data   = array();
                            if ( $cust_id && ! empty( $api_cache ) ) {
                                foreach ( $api_cache as $ad ) {
                                    if ( isset( $ad['cust_id'] ) && intval( $ad['cust_id'] ) === intval( $cust_id ) ) {
                                        $api_linked = true;
                                        $api_data   = $ad;
                                        break;
                                    }
                                }
                            }
                        ?>
                        <div class="edr-profile-card" data-driver-id="<?php echo esc_attr( $driver_id ); ?>">

                            <div class="edr-profile-card-header">
                                <span class="edr-drag-handle" title="Drag to reorder">&#9776;</span>
                                <strong><?php echo esc_html( $name ); ?></strong>
                                <span class="edr-profile-cid">
                                    <?php if ( ! empty( $profile['number'] ) ) : ?>
                                        <strong style="color:#f1c40f">#<?php echo esc_html( $profile['number'] ); ?></strong> &nbsp;
                                    <?php endif; ?>
                                    <?php if ( $featured ) : ?>
                                        <span style="color:#f1c40f" title="Featured / Spotlight">&#9733; Featured</span> &nbsp;
                                    <?php endif; ?>
                                    <?php if ( $api_linked ) : ?>
                                        <span style="color:#2ecc71" title="Live stats from iRacing API">&#9679; API linked</span>
                                    <?php elseif ( $cust_id ) : ?>
                                        <span style="color:#f39c12" title="Customer ID set but no API data cached yet">&#9679; Awaiting API</span>
                                    <?php else : ?>
                                        <span style="color:#888">Manual</span>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <input type="hidden" name="profiles[<?php echo esc_attr( $driver_id ); ?>][_id]" value="<?php echo esc_attr( $driver_id ); ?>" />

                            <!-- Photo -->
                            <div class="edr-profile-photo-row">
                                <div class="edr-profile-photo-preview" id="preview-<?php echo esc_attr( $driver_id ); ?>">
                                    <?php if ( $photo ) : ?>
                                        <img src="<?php echo esc_url( $photo ); ?>" alt="" />
                                    <?php else : ?>
                                        <span class="edr-no-photo">No photo</span>
                                    <?php endif; ?>
                                </div>
                                <div class="edr-profile-photo-actions">
                                    <input type="hidden" name="profiles[<?php echo esc_attr( $driver_id ); ?>][photo_url]"
                                           id="photo-<?php echo esc_attr( $driver_id ); ?>" value="<?php echo esc_attr( $photo ); ?>" />
                                    <button type="button" class="button edr-upload-btn" data-cid="<?php echo esc_attr( $driver_id ); ?>">
                                        <?php echo $photo ? 'Change Photo' : 'Upload Photo'; ?>
                                    </button>
                                    <?php if ( $photo ) : ?>
                                    <button type="button" class="button edr-remove-photo-btn" data-cid="<?php echo esc_attr( $driver_id ); ?>">Remove</button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Identity -->
                            <div class="edr-profile-section-heading">Identity</div>
                            <div class="edr-profile-gear-fields">
                                <label>
                                    <span>Driver Name</span>
                                    <input type="text" name="profiles[<?php echo esc_attr( $driver_id ); ?>][name]"
                                           value="<?php echo esc_attr( $name ); ?>" />
                                </label>
                                <label>
                                    <span>iRacing Customer ID <small>(leave blank for manual-only)</small></span>
                                    <input type="text" name="profiles[<?php echo esc_attr( $driver_id ); ?>][cust_id]"
                                           value="<?php echo esc_attr( $cust_id ); ?>" placeholder="e.g. 123456" />
                                </label>
                                <label>
                                    <span>Team Role</span>
                                    <select name="profiles[<?php echo esc_attr( $driver_id ); ?>][role]">
                                        <?php foreach ( $role_options as $val => $lbl ) :
                                            $sel = ( isset( $profile['role'] ) && $profile['role'] === $val ) ? ' selected' : '';
                                        ?>
                                        <option value="<?php echo esc_attr( $val ); ?>"<?php echo $sel; ?>><?php echo esc_html( $lbl ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span>Driver Number</span>
                                    <input type="text" name="profiles[<?php echo esc_attr( $driver_id ); ?>][number]"
                                           value="<?php echo esc_attr( isset( $profile['number'] ) ? $profile['number'] : '' ); ?>" placeholder="e.g. 18" />
                                </label>
                                <label>
                                    <span>Country / Flag</span>
                                    <select name="profiles[<?php echo esc_attr( $driver_id ); ?>][flag_code]"
                                            class="edr-flag-select" data-cid="<?php echo esc_attr( $driver_id ); ?>">
                                        <?php foreach ( $flag_options as $code => $label ) :
                                            $sel = ( $flag_code === $code ) ? ' selected' : '';
                                        ?>
                                        <option value="<?php echo esc_attr( $code ); ?>"<?php echo $sel; ?>><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span>Nationality <small>(text shown on card, auto-filled from Country above)</small></span>
                                    <input type="text" name="profiles[<?php echo esc_attr( $driver_id ); ?>][nationality]"
                                           id="nat-<?php echo esc_attr( $driver_id ); ?>"
                                           value="<?php echo esc_attr( isset( $profile['nationality'] ) ? $profile['nationality'] : '' ); ?>" placeholder="e.g. Australia" />
                                </label>
                                <label>
                                    <span>Tagline / Bio</span>
                                    <input type="text" name="profiles[<?php echo esc_attr( $driver_id ); ?>][tagline]"
                                           value="<?php echo esc_attr( isset( $profile['tagline'] ) ? $profile['tagline'] : '' ); ?>" placeholder="e.g. Spa specialist" />
                                </label>
                                <label>
                                    <span>Display Order <small>(for sort_by="custom", lower = first)</small></span>
                                    <input type="number" name="profiles[<?php echo esc_attr( $driver_id ); ?>][sort_order]"
                                           class="edr-sort-order-input"
                                           value="<?php echo esc_attr( isset( $profile['sort_order'] ) ? $profile['sort_order'] : '' ); ?>" placeholder="1" min="1" style="width:80px" />
                                </label>
                                <label style="flex-direction:row;align-items:center;gap:8px;">
                                    <input type="checkbox" name="profiles[<?php echo esc_attr( $driver_id ); ?>][featured]"
                                           value="1" <?php checked( $featured ); ?> />
                                    <span>Featured / Spotlight <small>(shows as hero card at top of grid)</small></span>
                                </label>
                            </div>

                            <!-- Stats -->
                            <div class="edr-profile-section-heading" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                                <span>Stats</span>
                                <?php if ( $cust_id && $api_configured ) : ?>
                                    <button type="button" class="button button-small edr-fetch-driver-btn"
                                            data-cid="<?php echo esc_attr( $cust_id ); ?>"
                                            data-driver-id="<?php echo esc_attr( $driver_id ); ?>">Fetch Stats from iRacing</button>
                                <?php endif; ?>
                                <?php if ( $api_linked ) : ?>
                                    <small style="color:#2ecc71;font-weight:normal">&mdash; live values from API shown in placeholders, manual values override if set</small>
                                <?php elseif ( $cust_id ) : ?>
                                    <small style="font-weight:normal">&mdash; click Fetch Stats to pull live data for this driver</small>
                                <?php else : ?>
                                    <small style="font-weight:normal">&mdash; enter manually, or link an iRacing Customer ID for automatic data</small>
                                <?php endif; ?>
                            </div>
                            <div class="edr-fetch-result" id="fetch-result-<?php echo esc_attr( $driver_id ); ?>" style="display:none"></div>
                            <div class="edr-profile-gear-fields">
                                <?php
                                $stat_fields = array(
                                    'irating'       => 'iRating',
                                    'safety_rating' => 'Safety Rating',
                                    'wins'          => 'Wins',
                                    'starts'        => 'Starts',
                                    'top5'          => 'Top 5s',
                                    'laps'          => 'Laps',
                                );
                                foreach ( $stat_fields as $skey => $slabel ) :
                                    $manual_val = isset( $profile[ $skey ] ) ? $profile[ $skey ] : '';
                                    $api_val    = isset( $api_data[ $skey ] ) ? $api_data[ $skey ] : '';
                                    $ph = $api_linked && $api_val !== '' ? 'API: ' . $api_val : '';
                                ?>
                                <label>
                                    <span><?php echo esc_html( $slabel ); ?></span>
                                    <input type="text" name="profiles[<?php echo esc_attr( $driver_id ); ?>][<?php echo $skey; ?>]"
                                           value="<?php echo esc_attr( $manual_val ); ?>"
                                           placeholder="<?php echo esc_attr( $ph ); ?>"
                                           id="stat-<?php echo esc_attr( $driver_id ); ?>-<?php echo $skey; ?>" />
                                </label>
                                <?php endforeach; ?>
                            </div>

                            <!-- Gear -->
                            <div class="edr-profile-section-heading">Sim Setup</div>
                            <div class="edr-profile-gear-fields">
                                <?php foreach ( $gear_fields as $field_key => $field_label ) : ?>
                                <label>
                                    <span><?php echo esc_html( $field_label ); ?></span>
                                    <input type="text"
                                           name="profiles[<?php echo esc_attr( $driver_id ); ?>][<?php echo $field_key; ?>]"
                                           value="<?php echo esc_attr( isset( $profile[ $field_key ] ) ? $profile[ $field_key ] : '' ); ?>"
                                           placeholder="e.g. <?php echo esc_attr( $this->gear_placeholder( $field_key ) ); ?>" />
                                </label>
                                <?php endforeach; ?>
                            </div>

                            <!-- Delete -->
                            <div class="edr-profile-card-footer">
                                <a href="<?php echo esc_url( wp_nonce_url(
                                    admin_url( 'admin.php?page=' . $this->profiles_slug . '&edr_action=delete_driver&driver_id=' . urlencode( $driver_id ) ),
                                    'edr_delete_driver_' . $driver_id, 'edr_del_nonce'
                                ) ); ?>" class="edr-delete-link"
                                   onclick="return confirm('Remove <?php echo esc_js( $name ); ?> from the roster?');">
                                    Remove Driver
                                </a>
                            </div>

                        </div>
                        <?php endforeach; ?>
                    </div>

                    <p style="margin-top:20px">
                        <input type="submit" class="button button-primary button-large" value="Save All Drivers" />
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ================================================================
       Form action router — replaces admin-post.php
       ================================================================ */

    private function process_profiles_actions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $action = '';
        if ( isset( $_POST['edr_action'] ) ) {
            $action = sanitize_text_field( $_POST['edr_action'] );
        } elseif ( isset( $_GET['edr_action'] ) ) {
            $action = sanitize_text_field( $_GET['edr_action'] );
        }
        if ( ! $action ) {
            return;
        }

        switch ( $action ) {
            case 'add_driver':
                $this->handle_add_driver();
                break;
            case 'save_profiles':
                $this->handle_save_profiles();
                break;
            case 'sync_api':
                $this->handle_sync_api();
                break;
            case 'delete_driver':
                $this->handle_delete_driver();
                break;
        }
    }

    /* ================================================================
       POST handlers
       ================================================================ */

    public function handle_add_driver() {
        if ( ! wp_verify_nonce( isset( $_POST['edr_add_nonce'] ) ? $_POST['edr_add_nonce'] : '', 'edr_add_driver' ) ) {
            wp_die( 'Invalid nonce' );
        }

        $name    = sanitize_text_field( isset( $_POST['driver_name'] ) ? $_POST['driver_name'] : '' );
        $cust_id = sanitize_text_field( isset( $_POST['cust_id'] )     ? $_POST['cust_id']     : '' );
        $role    = sanitize_text_field( isset( $_POST['role'] )        ? $_POST['role']        : '' );
        $number  = sanitize_text_field( isset( $_POST['number'] )      ? $_POST['number']      : '' );

        if ( empty( $name ) ) {
            wp_redirect( admin_url( 'admin.php?page=' . $this->profiles_slug . '&msg=name_required' ) );
            exit;
        }

        $profiles = get_option( $this->profiles_key, array() );
        if ( ! is_array( $profiles ) ) { $profiles = array(); }

        if ( $cust_id && is_numeric( $cust_id ) ) {
            $driver_id = intval( $cust_id );
            if ( isset( $profiles[ $driver_id ] ) ) {
                wp_redirect( admin_url( 'admin.php?page=' . $this->profiles_slug . '&msg=duplicate_id' ) );
                exit;
            }
        } else {
            $counter   = intval( get_option( 'edr_manual_driver_counter', 0 ) ) + 1;
            $driver_id = 'm_' . $counter;
            update_option( 'edr_manual_driver_counter', $counter );
        }

        $profiles[ $driver_id ] = array(
            'name'          => $name,
            'cust_id'       => $cust_id,
            'role'          => $role,
            'number'        => $number,
            'nationality'   => '',
            'flag_code'     => '',
            'tagline'       => '',
            'sort_order'    => '',
            'featured'      => '',
            'irating'       => '',
            'safety_rating' => '',
            'wins'          => '',
            'starts'        => '',
            'top5'          => '',
            'laps'          => '',
            'photo_url'     => '',
            'wheel'         => '',
            'pedals'        => '',
            'rig'           => '',
            'monitor'       => '',
            'pc'            => '',
            'other'         => '',
        );

        update_option( $this->profiles_key, $profiles );
        wp_redirect( admin_url( 'admin.php?page=' . $this->profiles_slug . '&msg=added' ) );
        exit;
    }

    public function handle_delete_driver() {

        $driver_id = isset( $_GET['driver_id'] ) ? sanitize_text_field( $_GET['driver_id'] ) : '';
        if ( ! wp_verify_nonce( isset( $_GET['edr_del_nonce'] ) ? $_GET['edr_del_nonce'] : '', 'edr_delete_driver_' . $driver_id ) ) {
            wp_die( 'Invalid nonce' );
        }

        $profiles = get_option( $this->profiles_key, array() );
        if ( ! is_array( $profiles ) ) { $profiles = array(); }

        $key = is_numeric( $driver_id ) ? intval( $driver_id ) : $driver_id;
        if ( isset( $profiles[ $key ] ) ) {
            unset( $profiles[ $key ] );
            update_option( $this->profiles_key, $profiles );
        }

        wp_redirect( admin_url( 'admin.php?page=' . $this->profiles_slug . '&msg=deleted' ) );
        exit;
    }

    public function handle_save_profiles() {
        if ( ! wp_verify_nonce( isset( $_POST['edr_profiles_nonce'] ) ? $_POST['edr_profiles_nonce'] : '', 'edr_save_profiles' ) ) {
            wp_die( 'Invalid nonce' );
        }

        $raw = isset( $_POST['profiles'] ) ? $_POST['profiles'] : array();
        $sanitized = array();

        $all_keys = array(
            'name', 'cust_id', 'role', 'number', 'nationality', 'flag_code', 'tagline', 'sort_order', 'featured',
            'irating', 'safety_rating', 'wins', 'starts', 'top5', 'laps',
            'photo_url', 'wheel', 'pedals', 'rig', 'monitor', 'pc', 'other',
        );

        foreach ( $raw as $driver_id => $fields ) {
            $driver_id = sanitize_text_field( $driver_id );
            if ( empty( $driver_id ) ) { continue; }

            $data = array();
            foreach ( $all_keys as $key ) {
                $val = isset( $fields[ $key ] ) ? $fields[ $key ] : '';
                if ( 'photo_url' === $key ) {
                    $data[ $key ] = esc_url_raw( $val );
                } elseif ( 'sort_order' === $key ) {
                    $data[ $key ] = $val !== '' ? absint( $val ) : '';
                } elseif ( 'featured' === $key ) {
                    $data[ $key ] = ( '1' === strval( $val ) ) ? '1' : '';
                } elseif ( 'flag_code' === $key ) {
                    $data[ $key ] = preg_replace( '/[^A-Z]/', '', strtoupper( sanitize_text_field( $val ) ) );
                } elseif ( in_array( $key, array( 'wins', 'starts', 'top5', 'laps' ), true ) ) {
                    $data[ $key ] = $val !== '' ? absint( $val ) : '';
                } else {
                    $data[ $key ] = sanitize_text_field( $val );
                }
            }

            $store_key = is_numeric( $driver_id ) ? intval( $driver_id ) : $driver_id;
            $sanitized[ $store_key ] = $data;
        }
        unset( $raw );

        update_option( $this->profiles_key, $sanitized );
        wp_redirect( admin_url( 'admin.php?page=' . $this->profiles_slug . '&msg=saved' ) );
        exit;
    }

    public function handle_sync_api() {
        if ( ! wp_verify_nonce( isset( $_POST['edr_sync_nonce'] ) ? $_POST['edr_sync_nonce'] : '', 'edr_sync_api' ) ) {
            wp_die( 'Invalid nonce' );
        }

        $api     = new EDR_IRacing_API();
        // Clear any cached token so we get a fresh auth attempt.
        delete_transient( 'edr_iracing_token' );
        $drivers = $api->get_all_driver_data();

        if ( empty( $drivers ) || ! is_array( $drivers ) ) {
            $error = $api->get_last_error();
            unset( $api );
            $error_param = $error ? '&detail=' . urlencode( $error ) : '';
            wp_redirect( admin_url( 'admin.php?page=' . $this->profiles_slug . '&msg=sync_fail' . $error_param ) );
            exit;
        }
        unset( $api );

        $profiles = get_option( $this->profiles_key, array() );
        if ( ! is_array( $profiles ) ) { $profiles = array(); }

        $added = 0;
        foreach ( $drivers as $d ) {
            $cid = intval( isset( $d['cust_id'] ) ? $d['cust_id'] : 0 );
            if ( ! $cid ) { continue; }

            $already_exists = isset( $profiles[ $cid ] );
            if ( ! $already_exists ) {
                foreach ( $profiles as $p ) {
                    if ( isset( $p['cust_id'] ) && intval( $p['cust_id'] ) === $cid ) {
                        $already_exists = true;
                        break;
                    }
                }
            }

            if ( ! $already_exists ) {
                $profiles[ $cid ] = array(
                    'name'          => isset( $d['name'] ) ? $d['name'] : 'Unknown',
                    'cust_id'       => strval( $cid ),
                    'role'          => '',
                    'number'        => '',
                    'nationality'   => '',
                    'flag_code'     => '',
                    'tagline'       => '',
                    'sort_order'    => '',
                    'featured'      => '',
                    'irating'       => '',
                    'safety_rating' => '',
                    'wins'          => '',
                    'starts'        => '',
                    'top5'          => '',
                    'laps'          => '',
                    'photo_url'     => '',
                    'wheel'         => '',
                    'pedals'        => '',
                    'rig'           => '',
                    'monitor'       => '',
                    'pc'            => '',
                    'other'         => '',
                );
                $added++;
            }
        }

        update_option( $this->profiles_key, $profiles );
        wp_redirect( admin_url( 'admin.php?page=' . $this->profiles_slug . '&msg=synced&count=' . $added ) );
        exit;
    }

    /**
     * AJAX: fetch stats for a single driver and return diagnostic JSON.
     * Dumps raw API field names so we can discover the correct keys.
     */
    public function ajax_fetch_single_driver() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        check_ajax_referer( 'edr_fetch_single_driver', 'nonce' );

        $cust_id = intval( isset( $_POST['cust_id'] ) ? $_POST['cust_id'] : 0 );
        if ( ! $cust_id ) {
            wp_send_json_error( 'No Customer ID provided.' );
        }

        $api = new EDR_IRacing_API();
        if ( ! $api->is_configured() ) {
            wp_send_json_error( 'API credentials are not configured. Go to Settings first.' );
        }

        $result = array( 'cust_id' => $cust_id );

        // 1. Sports Car iRating via /member/chart_data (category 5, chart_type 1)
        $ir_chart = $api->get_member_chart_data( $cust_id, 5, 1 );
        $result['ir_chart_raw_keys'] = is_array( $ir_chart ) ? array_keys( $ir_chart ) : 'null';
        if ( is_array( $ir_chart ) ) {
            $ir_data = null;
            if ( isset( $ir_chart['data'] ) )       { $ir_data = $ir_chart['data']; }
            elseif ( isset( $ir_chart['chart_data'] ) ) { $ir_data = $ir_chart['chart_data']; }
            else { $ir_data = $ir_chart; }

            if ( is_array( $ir_data ) && ! empty( $ir_data ) ) {
                $result['ir_data_count'] = count( $ir_data );
                $result['ir_data_last']  = end( $ir_data );
                $last_val = isset( $result['ir_data_last']['value'] ) ? $result['ir_data_last']['value'] : null;
                $result['sports_car_irating'] = $last_val;
                $result['ir_data_first'] = reset( $ir_data );
            } else {
                $result['ir_data_count'] = 0;
                $result['sports_car_irating'] = null;
                $result['ir_chart_full'] = $ir_chart;
            }
        } else {
            $result['sports_car_irating'] = null;
        }
        unset( $ir_chart );

        // 2. Sports Car Safety Rating via /member/chart_data (category 5, chart_type 3)
        $sr_chart = $api->get_member_chart_data( $cust_id, 5, 3 );
        if ( is_array( $sr_chart ) ) {
            $sr_data = null;
            if ( isset( $sr_chart['data'] ) )       { $sr_data = $sr_chart['data']; }
            elseif ( isset( $sr_chart['chart_data'] ) ) { $sr_data = $sr_chart['chart_data']; }
            else { $sr_data = $sr_chart; }

            if ( is_array( $sr_data ) && ! empty( $sr_data ) ) {
                $sr_last = end( $sr_data );
                $raw_sr  = isset( $sr_last['value'] ) ? intval( $sr_last['value'] ) : 0;
                $lic_map = array( 1 => 'R', 2 => 'D', 3 => 'C', 4 => 'B', 5 => 'A', 6 => 'Pro' );
                $lic_num = intval( floor( $raw_sr / 1000 ) );
                $sub     = $raw_sr % 1000;
                $result['sports_car_sr_raw']   = $raw_sr;
                $result['sports_car_license']  = isset( $lic_map[ $lic_num ] ) ? $lic_map[ $lic_num ] : '?';
                $result['sports_car_sr']       = number_format( $sub / 100, 2 );
            } else {
                $result['sports_car_sr'] = null;
                $result['sr_chart_full'] = $sr_chart;
            }
        } else {
            $result['sports_car_sr'] = null;
        }
        unset( $sr_chart );

        // 3. Career stats (quick check)
        $career = $api->get_member_career_stats( $cust_id );
        if ( ! empty( $career['stats'] ) && is_array( $career['stats'] ) ) {
            $result['career_categories'] = array();
            foreach ( $career['stats'] as $s ) {
                $result['career_categories'][] = array(
                    'category_id' => isset( $s['category_id'] ) ? $s['category_id'] : '?',
                    'category'    => isset( $s['category'] )    ? $s['category']    : '?',
                    'starts'      => isset( $s['starts'] )      ? $s['starts']      : 0,
                    'wins'        => isset( $s['wins'] )        ? $s['wins']        : 0,
                );
            }
        }
        unset( $career );

        // 4. Recent races (first 3 for context)
        $recent = $api->get_member_recent_races( $cust_id );
        if ( ! empty( $recent['races'] ) && is_array( $recent['races'] ) ) {
            $result['recent_races_count'] = count( $recent['races'] );
            $result['recent_first3'] = array();
            foreach ( array_slice( $recent['races'], 0, 3 ) as $r ) {
                $result['recent_first3'][] = array(
                    'series'      => isset( $r['series_name'] )         ? $r['series_name']         : '?',
                    'track'       => isset( $r['track']['track_name'] ) ? $r['track']['track_name'] : '?',
                    'newi_rating' => isset( $r['newi_rating'] )         ? $r['newi_rating']         : '?',
                    'new_sub'     => isset( $r['new_sub_level'] )       ? $r['new_sub_level']       : '?',
                );
            }
        } else {
            $result['recent_races_count'] = 0;
        }
        unset( $recent );

        unset( $api );
        wp_send_json_success( $result );
    }

    /* ================================================================
       Helpers
       ================================================================ */

    private function is_api_configured() {
        $s = get_option( $this->option_name, array() );
        return ! empty( $s['client_id'] ) && ! empty( $s['client_secret'] )
            && ! empty( $s['username'] )  && ! empty( $s['password'] );
    }

    private function render_admin_notice( $msg ) {
        $notices = array(
            'saved'         => array( 'success', 'All driver profiles saved.' ),
            'added'         => array( 'success', 'Driver added successfully.' ),
            'deleted'       => array( 'success', 'Driver removed.' ),
            'synced'        => array( 'success', 'Sync complete. ' . ( isset( $_GET['count'] ) ? absint( $_GET['count'] ) . ' new driver(s) imported.' : '' ) ),
            'sync_fail'     => array( 'error',   'API sync failed. ' . ( ! empty( $_GET['detail'] ) ? sanitize_text_field( wp_unslash( $_GET['detail'] ) ) : 'Check your credentials and team ID.' ) ),
            'fetch_ok'      => array( 'success', 'Driver stats fetched from iRacing API.' ),
            'fetch_fail'    => array( 'error',   'Could not fetch stats for this driver. Check the Customer ID and API credentials.' ),
            'name_required' => array( 'error',   'Driver name is required.' ),
            'duplicate_id'  => array( 'warning', 'A driver with that iRacing Customer ID already exists.' ),
        );
        if ( ! isset( $notices[ $msg ] ) ) { return; }
        list( $type, $text ) = $notices[ $msg ];
        printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
    }

    private function gear_placeholder( $key ) {
        $map = array(
            'wheel'   => 'Fanatec DD Pro, Simucube 2...',
            'pedals'  => 'Heusinkveld Sprint, Fanatec V3...',
            'rig'     => 'Trak Racer TR160, 80/20 custom...',
            'monitor' => 'Samsung 49 ultrawide, Meta Quest 3...',
            'pc'      => 'RTX 4080, i9-13900K, 32GB...',
            'other'   => 'Stream Deck, button box...',
        );
        return isset( $map[ $key ] ) ? $map[ $key ] : '';
    }

    private function handle_cache_clear() {
        if ( empty( $_POST['edr_clear_cache'] ) ) { return; }
        if ( ! wp_verify_nonce( isset( $_POST['edr_cache_nonce'] ) ? $_POST['edr_cache_nonce'] : '', 'edr_clear_cache' ) ) { return; }
        delete_transient( 'edr_iracing_drivers_cache' );
        echo '<div class="notice notice-success"><p>Driver cache cleared.</p></div>';
    }

    private function handle_updater_test() {
        if ( empty( $_POST['edr_test_updater'] ) ) { return; }
        if ( ! wp_verify_nonce( isset( $_POST['edr_updater_nonce'] ) ? $_POST['edr_updater_nonce'] : '', 'edr_test_updater' ) ) { return; }

        // Clear the cached release so we get a fresh result.
        delete_transient( 'edr_github_release_data' );

        $repo = 'cdwilson127/endurotech-iracing-drivers';
        $url  = 'https://api.github.com/repos/' . $repo . '/releases/latest';

        $response = wp_remote_get( $url, array(
            'timeout' => 15,
            'headers' => array(
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
                'Accept'     => 'application/vnd.github.v3+json',
            ),
        ) );

        echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:16px 20px;margin-top:12px;max-width:700px;border-radius:4px;font-family:monospace;font-size:13px">';
        echo '<strong>Update Checker Diagnostic</strong><br><br>';
        echo 'GitHub API URL: <a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $url ) . '</a><br>';
        echo 'Current plugin version: <strong>' . EDR_IRACING_VERSION . '</strong><br><br>';

        if ( is_wp_error( $response ) ) {
            echo '<span style="color:#b32d2e">&#10007; HTTP Error: ' . esc_html( $response->get_error_message() ) . '</span>';
        } else {
            $code = wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            echo 'HTTP Status: <strong>' . intval( $code ) . '</strong><br>';

            if ( 200 === intval( $code ) && ! empty( $body['tag_name'] ) ) {
                $version = ltrim( $body['tag_name'], 'v' );
                $newer   = version_compare( $version, EDR_IRACING_VERSION, '>' );
                echo 'Latest release tag: <strong>' . esc_html( $body['tag_name'] ) . '</strong> (version ' . esc_html( $version ) . ')<br>';
                echo 'Published: ' . esc_html( isset( $body['published_at'] ) ? $body['published_at'] : 'unknown' ) . '<br>';
                echo 'Package URL: ' . esc_html( isset( $body['zipball_url'] ) ? $body['zipball_url'] : 'none' ) . '<br><br>';
                if ( $newer ) {
                    echo '<span style="color:#0a7227">&#10003; Update available: ' . esc_html( $version ) . ' &gt; ' . EDR_IRACING_VERSION . '</span><br>';
                    echo '<span style="color:#0a7227">&#10003; WordPress should show the update button. If it does not, go to <strong>Dashboard &rarr; Updates &rarr; Check Again</strong>.</span>';
                } else {
                    echo '<span style="color:#888">&#8212; Plugin is already up to date (GitHub: ' . esc_html( $version ) . ', Installed: ' . EDR_IRACING_VERSION . ').</span>';
                }
            } elseif ( 404 === intval( $code ) ) {
                echo '<span style="color:#b32d2e">&#10007; 404 — Repository not found or no releases published yet.<br>';
                echo 'Check: (1) Is the repo public? (2) Has a release been published (not just a push)?</span>';
            } elseif ( 403 === intval( $code ) ) {
                echo '<span style="color:#b32d2e">&#10007; 403 — GitHub API rate limit hit or repo is private.<br>';
                echo 'Anonymous API requests are limited to 60/hour. Try again in a few minutes.</span>';
            } else {
                echo '<span style="color:#b32d2e">&#10007; Unexpected response (' . intval( $code ) . ').</span><br>';
                echo '<pre style="font-size:11px;overflow:auto;max-height:200px">' . esc_html( wp_remote_retrieve_body( $response ) ) . '</pre>';
            }
        }

        echo '</div>';
    }

    private function get_flag_options() {
        return array(
            ''   => '— No flag —',
            'AU' => '🇦🇺 Australia',
            'NZ' => '🇳🇿 New Zealand',
            'GB' => '🇬🇧 United Kingdom',
            'IE' => '🇮🇪 Ireland',
            'US' => '🇺🇸 United States',
            'CA' => '🇨🇦 Canada',
            'MX' => '🇲🇽 Mexico',
            'BR' => '🇧🇷 Brazil',
            'AR' => '🇦🇷 Argentina',
            'CL' => '🇨🇱 Chile',
            'CO' => '🇨🇴 Colombia',
            'DE' => '🇩🇪 Germany',
            'FR' => '🇫🇷 France',
            'IT' => '🇮🇹 Italy',
            'ES' => '🇪🇸 Spain',
            'PT' => '🇵🇹 Portugal',
            'NL' => '🇳🇱 Netherlands',
            'BE' => '🇧🇪 Belgium',
            'SE' => '🇸🇪 Sweden',
            'NO' => '🇳🇴 Norway',
            'DK' => '🇩🇰 Denmark',
            'FI' => '🇫🇮 Finland',
            'AT' => '🇦🇹 Austria',
            'CH' => '🇨🇭 Switzerland',
            'PL' => '🇵🇱 Poland',
            'CZ' => '🇨🇿 Czech Republic',
            'HU' => '🇭🇺 Hungary',
            'SK' => '🇸🇰 Slovakia',
            'RO' => '🇷🇴 Romania',
            'HR' => '🇭🇷 Croatia',
            'RS' => '🇷🇸 Serbia',
            'SI' => '🇸🇮 Slovenia',
            'GR' => '🇬🇷 Greece',
            'RU' => '🇷🇺 Russia',
            'UA' => '🇺🇦 Ukraine',
            'EE' => '🇪🇪 Estonia',
            'LV' => '🇱🇻 Latvia',
            'LT' => '🇱🇹 Lithuania',
            'IS' => '🇮🇸 Iceland',
            'TR' => '🇹🇷 Turkey',
            'IL' => '🇮🇱 Israel',
            'AE' => '🇦🇪 UAE',
            'ZA' => '🇿🇦 South Africa',
            'JP' => '🇯🇵 Japan',
            'KR' => '🇰🇷 South Korea',
            'CN' => '🇨🇳 China',
            'IN' => '🇮🇳 India',
            'SG' => '🇸🇬 Singapore',
            'TH' => '🇹🇭 Thailand',
        );
    }
}
