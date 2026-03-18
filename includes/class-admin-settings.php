<?php
/**
 * Admin settings — API credentials, team config, and per-driver profiles.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EDR_Admin_Settings {

    private $option_group  = 'edr_iracing_settings_group';
    private $option_name   = 'edr_iracing_settings';
    private $profiles_key  = 'edr_driver_profiles';
    private $page_slug     = 'edr-iracing-drivers';
    private $profiles_slug = 'edr-iracing-profiles';

    public function __construct() {
        add_action( 'admin_menu',            array( $this, 'add_menu_pages' ) );
        add_action( 'admin_init',            array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'admin_post_edr_save_profiles', array( $this, 'handle_save_profiles' ) );
    }

    public function add_menu_pages() {
        add_menu_page(
            'iRacing Drivers',
            'iRacing Drivers',
            'manage_options',
            $this->page_slug,
            array( $this, 'render_settings_page' ),
            'dashicons-car',
            81
        );
        add_submenu_page(
            $this->page_slug, 'API Settings', 'API Settings',
            'manage_options', $this->page_slug, array( $this, 'render_settings_page' )
        );
        add_submenu_page(
            $this->page_slug, 'Driver Profiles', 'Driver Profiles',
            'manage_options', $this->profiles_slug, array( $this, 'render_profiles_page' )
        );
    }

    public function enqueue_admin_scripts( $hook ) {
        if ( false === strpos( $hook, $this->profiles_slug ) ) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script(
            'edr-admin-profiles',
            EDR_IRACING_PLUGIN_URL . 'assets/js/admin-profiles.js',
            array( 'jquery' ), EDR_IRACING_VERSION, true
        );
        wp_enqueue_style(
            'edr-admin-profiles-css',
            EDR_IRACING_PLUGIN_URL . 'assets/css/admin-profiles.css',
            array(), EDR_IRACING_VERSION
        );
    }

    public function register_settings() {
        register_setting( $this->option_group, $this->option_name, array(
            'type'              => 'array',
            'sanitize_callback' => array( $this, 'sanitize' ),
        ) );

        add_settings_section( 'edr_api_section', 'iRacing API Credentials', function () {
            echo '<p>Enter your iRacing OAuth2 client credentials. '
               . 'Register at <a href="https://oauth.iracing.com/oauth2/book/client_registration.html" target="_blank">'
               . 'oauth.iracing.com</a> if you haven\'t already.</p>';
        }, $this->page_slug );

        $fields = array(
            'client_id'     => 'Client ID',
            'client_secret' => 'Client Secret',
            'username'      => 'iRacing Username (email)',
            'password'      => 'iRacing Password',
            'team_id'       => 'Team ID',
            'cache_hours'   => 'Cache Duration (hours)',
        );

        foreach ( $fields as $key => $label ) {
            add_settings_field( $key, $label, function () use ( $key ) {
                $settings = get_option( $this->option_name, array() );
                $value    = esc_attr( isset( $settings[ $key ] ) ? $settings[ $key ] : '' );
                $type     = in_array( $key, array( 'client_secret', 'password' ), true ) ? 'password' : 'text';
                printf(
                    '<input type="%s" name="%s[%s]" value="%s" class="regular-text" autocomplete="off" />',
                    $type, $this->option_name, $key, $value
                );
                if ( 'team_id' === $key ) {
                    echo '<p class="description">Find your team ID in the iRacing member site URL when viewing your team page.</p>';
                }
                if ( 'cache_hours' === $key ) {
                    echo '<p class="description">Minimum 1 hour. Increase for larger rosters.</p>';
                }
            }, $this->page_slug, 'edr_api_section' );
        }
    }

    public function sanitize( $input ) {
        return array(
            'client_id'     => sanitize_text_field( isset( $input['client_id'] )     ? $input['client_id']     : '' ),
            'client_secret' => sanitize_text_field( isset( $input['client_secret'] ) ? $input['client_secret'] : '' ),
            'username'      => sanitize_email( isset( $input['username'] )           ? $input['username']      : '' ),
            'password'      => isset( $input['password'] ) ? $input['password'] : '',
            'team_id'       => absint( isset( $input['team_id'] )     ? $input['team_id']     : 0 ),
            'cache_hours'   => max( 1, absint( isset( $input['cache_hours'] ) ? $input['cache_hours'] : 1 ) ),
        );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $settings = get_option( $this->option_name, array() );
        $is_configured = ! empty( $settings['client_id'] ) && ! empty( $settings['client_secret'] )
                      && ! empty( $settings['username'] )  && ! empty( $settings['password'] );
        ?>
        <div class="wrap">
            <h1>iRacing Drivers &mdash; API Settings</h1>
            <?php if ( ! $is_configured ) : ?>
            <div class="notice notice-warning"><p><strong>iRacing Drivers:</strong> Please complete all credential fields below to enable live data. Use <code>[iracing_drivers demo="yes"]</code> to preview the layout in the meantime.</p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( $this->option_group ); ?>
                <?php do_settings_sections( $this->page_slug ); ?>
                <?php submit_button( 'Save Settings' ); ?>
            </form>

            <hr />
            <h2>Cache Management</h2>
            <p>Driver data is cached to avoid excessive API calls.</p>
            <form method="post">
                <?php wp_nonce_field( 'edr_clear_cache', 'edr_cache_nonce' ); ?>
                <input type="hidden" name="edr_clear_cache" value="1" />
                <?php submit_button( 'Clear Driver Cache', 'secondary' ); ?>
            </form>
            <?php $this->handle_cache_clear(); ?>

            <hr />
            <h2>Shortcode Reference</h2>
            <p>Place on any page or post:</p>
            <pre style="background:#f5f5f5;padding:12px;border-radius:4px;">[iracing_drivers]</pre>

            <table class="widefat striped" style="max-width:900px">
                <thead><tr><th>Attribute</th><th>Default</th><th>Options</th><th>Description</th></tr></thead>
                <tbody>
                    <tr><td><code>title</code></td><td>Our Drivers</td><td>Any text</td><td>Heading above the display</td></tr>
                    <tr><td><code>layout</code></td><td>cards</td><td>cards, table</td><td>Card grid or compact table</td></tr>
                    <tr><td><code>columns</code></td><td>auto</td><td>auto, 1, 2, 3, 4</td><td>Cards per row (cards layout only)</td></tr>
                    <tr><td><code>sort_by</code></td><td>irating</td><td>irating, wins, starts, name, custom</td><td>Sort field. <em>custom</em> uses Display Order set per driver in profiles</td></tr>
                    <tr><td><code>sort_order</code></td><td>desc</td><td>asc, desc</td><td>Ascending or descending</td></tr>
                    <tr><td><code>accent</code></td><td>red</td><td>red, blue, green, gold</td><td>Highlight colour for badges and hover</td></tr>
                    <tr><td><code>card_style</code></td><td>default</td><td>default, minimal</td><td><em>minimal</em> strips the card to essentials only</td></tr>
                    <tr><td><code>show_summary</code></td><td>yes</td><td>yes, no</td><td>Team stat cards at the top</td></tr>
                    <tr><td><code>show_last_race</code></td><td>yes</td><td>yes, no</td><td>Last race result</td></tr>
                    <tr><td><code>show_photo</code></td><td>yes</td><td>yes, no</td><td>Driver photos (if uploaded)</td></tr>
                    <tr><td><code>show_role</code></td><td>yes</td><td>yes, no</td><td>Team role badge (e.g. Team Captain)</td></tr>
                    <tr><td><code>show_number</code></td><td>yes</td><td>yes, no</td><td>Driver number</td></tr>
                    <tr><td><code>show_gear</code></td><td>yes</td><td>yes, no</td><td>Sim gear / setup section</td></tr>
                    <tr><td><code>show_wins</code></td><td>yes</td><td>yes, no</td><td>Wins stat</td></tr>
                    <tr><td><code>show_starts</code></td><td>yes</td><td>yes, no</td><td>Starts stat</td></tr>
                    <tr><td><code>show_top5</code></td><td>yes</td><td>yes, no</td><td>Top 5s stat</td></tr>
                    <tr><td><code>show_laps</code></td><td>yes</td><td>yes, no</td><td>Laps stat</td></tr>
                    <tr><td><code>demo</code></td><td>no</td><td>yes, no</td><td>Show sample data (no API needed)</td></tr>
                </tbody>
            </table>

            <h3>Example with all options:</h3>
            <pre style="background:#f5f5f5;padding:12px;border-radius:4px;">[iracing_drivers title="EDR Roster" layout="cards" columns="3" sort_by="custom" accent="red" show_role="yes" show_number="yes" show_gear="yes" show_summary="yes"]</pre>
        </div>
        <?php
    }

    /* ----- Driver Profiles page ----- */

    public function render_profiles_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $profiles = get_option( $this->profiles_key, array() );
        if ( ! is_array( $profiles ) ) { $profiles = array(); }

        $drivers = get_transient( 'edr_iracing_drivers_cache' );

        if ( empty( $drivers ) || ! is_array( $drivers ) ) {
            $settings = get_option( $this->option_name, array() );
            $ok = ! empty( $settings['client_id'] ) && ! empty( $settings['client_secret'] )
               && ! empty( $settings['username'] )  && ! empty( $settings['password'] );
            if ( $ok ) {
                $api     = new EDR_IRacing_API();
                $drivers = $api->get_all_driver_data();
                unset( $api );
            }
        }

        $role_options = array(
            ''        => '— No role —',
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
        ?>
        <div class="wrap">
            <h1>Driver Profiles &mdash; Photos, Roles &amp; Gear</h1>
            <p>All fields are optional. The frontend layout adapts automatically to whatever is filled in. <strong>Display Order</strong> controls the card sequence when using <code>sort_by="custom"</code>.</p>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>Profiles saved successfully.</p></div>
            <?php endif; ?>

            <?php if ( empty( $drivers ) || ! is_array( $drivers ) ) : ?>
                <div class="notice notice-info">
                    <p>No driver data cached yet. Configure your <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->page_slug ) ); ?>">API credentials</a>,
                    then visit your drivers page (or use <code>[iracing_drivers demo="yes"]</code>) to populate the cache first.</p>
                </div>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="edr_save_profiles" />
                    <?php wp_nonce_field( 'edr_save_profiles', 'edr_profiles_nonce' ); ?>

                    <div class="edr-profiles-grid">
                        <?php foreach ( $drivers as $driver ) :
                            $cid     = intval( $driver['cust_id'] );
                            $profile = isset( $profiles[ $cid ] ) ? $profiles[ $cid ] : array();
                            $photo   = isset( $profile['photo_url'] ) ? $profile['photo_url'] : '';
                        ?>
                        <div class="edr-profile-card">

                            <div class="edr-profile-card-header">
                                <strong><?php echo esc_html( $driver['name'] ); ?></strong>
                                <span class="edr-profile-cid">
                                    <?php if ( ! empty( $profile['number'] ) ) : ?>
                                        <strong style="color:#e63946">#<?php echo esc_html( $profile['number'] ); ?></strong> &nbsp;
                                    <?php endif; ?>
                                    ID: <?php echo $cid; ?>
                                </span>
                            </div>

                            <!-- Photo -->
                            <div class="edr-profile-photo-row">
                                <div class="edr-profile-photo-preview" id="preview-<?php echo $cid; ?>">
                                    <?php if ( $photo ) : ?>
                                        <img src="<?php echo esc_url( $photo ); ?>" alt="" />
                                    <?php else : ?>
                                        <span class="edr-no-photo">No photo</span>
                                    <?php endif; ?>
                                </div>
                                <div class="edr-profile-photo-actions">
                                    <input type="hidden" name="profiles[<?php echo $cid; ?>][photo_url]"
                                           id="photo-<?php echo $cid; ?>" value="<?php echo esc_attr( $photo ); ?>" />
                                    <button type="button" class="button edr-upload-btn" data-cid="<?php echo $cid; ?>">
                                        <?php echo $photo ? 'Change Photo' : 'Upload Photo'; ?>
                                    </button>
                                    <?php if ( $photo ) : ?>
                                    <button type="button" class="button edr-remove-photo-btn" data-cid="<?php echo $cid; ?>">Remove</button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Identity fields -->
                            <div class="edr-profile-gear-fields">
                                <label>
                                    <span>Team Role</span>
                                    <select name="profiles[<?php echo $cid; ?>][role]">
                                        <?php foreach ( $role_options as $val => $label ) :
                                            $sel = ( isset( $profile['role'] ) && $profile['role'] === $val ) ? ' selected' : '';
                                        ?>
                                        <option value="<?php echo esc_attr( $val ); ?>"<?php echo $sel; ?>><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span>Driver Number</span>
                                    <input type="text" name="profiles[<?php echo $cid; ?>][number]"
                                           value="<?php echo esc_attr( isset( $profile['number'] ) ? $profile['number'] : '' ); ?>"
                                           placeholder="e.g. 18" />
                                </label>
                                <label>
                                    <span>Nationality</span>
                                    <input type="text" name="profiles[<?php echo $cid; ?>][nationality]"
                                           value="<?php echo esc_attr( isset( $profile['nationality'] ) ? $profile['nationality'] : '' ); ?>"
                                           placeholder="e.g. 🇦🇺 Australia" />
                                </label>
                                <label>
                                    <span>Tagline / Bio</span>
                                    <input type="text" name="profiles[<?php echo $cid; ?>][tagline]"
                                           value="<?php echo esc_attr( isset( $profile['tagline'] ) ? $profile['tagline'] : '' ); ?>"
                                           placeholder="e.g. Spa specialist · 3x podium" />
                                </label>
                                <label>
                                    <span>Display Order <small style="font-weight:normal">(for sort_by="custom")</small></span>
                                    <input type="number" name="profiles[<?php echo $cid; ?>][sort_order]"
                                           value="<?php echo esc_attr( isset( $profile['sort_order'] ) ? $profile['sort_order'] : '' ); ?>"
                                           placeholder="1" min="1" style="width:80px" />
                                </label>
                            </div>

                            <!-- Gear fields -->
                            <div class="edr-profile-section-heading">Sim Setup</div>
                            <div class="edr-profile-gear-fields">
                                <?php foreach ( $gear_fields as $field_key => $field_label ) : ?>
                                <label>
                                    <span><?php echo esc_html( $field_label ); ?></span>
                                    <input type="text"
                                           name="profiles[<?php echo $cid; ?>][<?php echo $field_key; ?>]"
                                           value="<?php echo esc_attr( isset( $profile[ $field_key ] ) ? $profile[ $field_key ] : '' ); ?>"
                                           placeholder="e.g. <?php echo esc_attr( $this->gear_placeholder( $field_key ) ); ?>" />
                                </label>
                                <?php endforeach; ?>
                            </div>

                        </div>
                        <?php endforeach; ?>
                    </div>

                    <p><input type="submit" class="button button-primary button-large" value="Save All Profiles" /></p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_save_profiles() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }
        if ( ! wp_verify_nonce( isset( $_POST['edr_profiles_nonce'] ) ? $_POST['edr_profiles_nonce'] : '', 'edr_save_profiles' ) ) {
            wp_die( 'Invalid nonce' );
        }

        $raw       = isset( $_POST['profiles'] ) ? $_POST['profiles'] : array();
        $sanitized = array();

        $all_keys = array(
            'photo_url', 'role', 'number', 'nationality', 'tagline', 'sort_order',
            'wheel', 'pedals', 'rig', 'monitor', 'pc', 'other',
        );

        foreach ( $raw as $cid => $fields ) {
            $cid = absint( $cid );
            if ( ! $cid ) { continue; }

            $data = array();
            foreach ( $all_keys as $key ) {
                $val = isset( $fields[ $key ] ) ? $fields[ $key ] : '';
                if ( 'photo_url' === $key ) {
                    $data[ $key ] = esc_url_raw( $val );
                } elseif ( 'sort_order' === $key ) {
                    $data[ $key ] = $val !== '' ? absint( $val ) : '';
                } else {
                    $data[ $key ] = sanitize_text_field( $val );
                }
            }

            $has_data = false;
            foreach ( $data as $v ) {
                if ( '' !== $v ) { $has_data = true; break; }
            }
            if ( $has_data ) { $sanitized[ $cid ] = $data; }
        }
        unset( $raw );

        update_option( $this->profiles_key, $sanitized );
        wp_redirect( admin_url( 'admin.php?page=' . $this->profiles_slug . '&saved=1' ) );
        exit;
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
}
