<?php
/**
 * Admin settings — API credentials, team config, and per-driver profiles.
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
    private $profiles_key  = 'edr_driver_profiles';
    private $page_slug     = 'edr-iracing-drivers';
    private $profiles_slug = 'edr-iracing-profiles';

    public function __construct() {
        add_action( 'admin_menu',            array( $this, 'add_menu_pages' ) );
        add_action( 'admin_init',            array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

        add_action( 'admin_post_edr_save_profiles',  array( $this, 'handle_save_profiles' ) );
        add_action( 'admin_post_edr_add_driver',      array( $this, 'handle_add_driver' ) );
        add_action( 'admin_post_edr_delete_driver',    array( $this, 'handle_delete_driver' ) );
        add_action( 'admin_post_edr_sync_api',         array( $this, 'handle_sync_api' ) );
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
            $this->page_slug, 'API Settings', 'API Settings',
            'manage_options', $this->page_slug, array( $this, 'render_settings_page' )
        );
        add_submenu_page(
            $this->page_slug, 'Manage Drivers', 'Manage Drivers',
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

    /* ================================================================
       API Settings page (unchanged)
       ================================================================ */

    public function register_settings() {
        register_setting( $this->option_group, $this->option_name, array(
            'type'              => 'array',
            'sanitize_callback' => array( $this, 'sanitize' ),
        ) );

        add_settings_section( 'edr_api_section', 'iRacing API Credentials', function () {
            echo '<p>Enter your iRacing OAuth2 client credentials. These are <strong>optional</strong> &mdash; '
               . 'you can manage drivers manually without them. When connected, live stats override manual values.</p>';
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
            <div class="notice notice-info"><p>API credentials are <strong>optional</strong>. You can add drivers manually on the <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->profiles_slug ) ); ?>">Manage Drivers</a> page without them.</p></div>
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
                    <tr><td><code>sort_by</code></td><td>irating</td><td>irating, wins, starts, name, custom</td><td>Sort field. <em>custom</em> uses Display Order</td></tr>
                    <tr><td><code>sort_order</code></td><td>desc</td><td>asc, desc</td><td>Ascending or descending</td></tr>
                    <tr><td><code>accent</code></td><td>red</td><td>red, blue, green, gold</td><td>Highlight colour for badges and hover</td></tr>
                    <tr><td><code>card_style</code></td><td>default</td><td>default, minimal</td><td><em>minimal</em> strips the card to essentials</td></tr>
                    <tr><td><code>show_summary</code></td><td>yes</td><td>yes, no</td><td>Team stat cards at the top</td></tr>
                    <tr><td><code>show_last_race</code></td><td>yes</td><td>yes, no</td><td>Last race result</td></tr>
                    <tr><td><code>show_photo</code></td><td>yes</td><td>yes, no</td><td>Driver photos (if uploaded)</td></tr>
                    <tr><td><code>show_role</code></td><td>yes</td><td>yes, no</td><td>Team role badge</td></tr>
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

    /* ================================================================
       Manage Drivers page
       ================================================================ */

    public function render_profiles_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $profiles = get_option( $this->profiles_key, array() );
        if ( ! is_array( $profiles ) ) { $profiles = array(); }

        $api_configured = $this->is_api_configured();
        $api_cache      = get_transient( 'edr_iracing_drivers_cache' );
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
        ?>
        <div class="wrap">
            <h1>Manage Drivers</h1>
            <p>Add drivers manually and configure everything here. If an iRacing Customer ID is set and the API is connected, live stats will override manual values automatically.</p>

            <?php if ( isset( $_GET['msg'] ) ) : ?>
                <?php $this->render_admin_notice( sanitize_text_field( $_GET['msg'] ) ); ?>
            <?php endif; ?>

            <!-- Add new driver -->
            <div class="edr-add-driver-box">
                <h3>Add New Driver</h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edr-add-driver-form">
                    <input type="hidden" name="action" value="edr_add_driver" />
                    <?php wp_nonce_field( 'edr_add_driver', 'edr_add_nonce' ); ?>
                    <label>
                        <span>Driver Name <strong style="color:#e63946">*</strong></span>
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
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                    <input type="hidden" name="action" value="edr_sync_api" />
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
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="edr_save_profiles" />
                    <?php wp_nonce_field( 'edr_save_profiles', 'edr_profiles_nonce' ); ?>

                    <div class="edr-profiles-grid">
                        <?php foreach ( $profiles as $driver_id => $profile ) :
                            $name      = isset( $profile['name'] )      ? $profile['name']      : 'Unknown';
                            $cust_id   = isset( $profile['cust_id'] )   ? $profile['cust_id']   : '';
                            $photo     = isset( $profile['photo_url'] ) ? $profile['photo_url'] : '';

                            $api_linked = false;
                            $api_data   = array();
                            if ( $cust_id && ! empty( $api_cache ) ) {
                                foreach ( $api_cache as $ad ) {
                                    if ( intval( $ad['cust_id'] ) === intval( $cust_id ) ) {
                                        $api_linked = true;
                                        $api_data   = $ad;
                                        break;
                                    }
                                }
                            }
                        ?>
                        <div class="edr-profile-card">

                            <div class="edr-profile-card-header">
                                <strong><?php echo esc_html( $name ); ?></strong>
                                <span class="edr-profile-cid">
                                    <?php if ( ! empty( $profile['number'] ) ) : ?>
                                        <strong style="color:#e63946">#<?php echo esc_html( $profile['number'] ); ?></strong> &nbsp;
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

                            <!-- Hidden driver ID -->
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
                                    <span>Nationality</span>
                                    <input type="text" name="profiles[<?php echo esc_attr( $driver_id ); ?>][nationality]"
                                           value="<?php echo esc_attr( isset( $profile['nationality'] ) ? $profile['nationality'] : '' ); ?>" placeholder="e.g. Australia" />
                                </label>
                                <label>
                                    <span>Tagline / Bio</span>
                                    <input type="text" name="profiles[<?php echo esc_attr( $driver_id ); ?>][tagline]"
                                           value="<?php echo esc_attr( isset( $profile['tagline'] ) ? $profile['tagline'] : '' ); ?>" placeholder="e.g. Spa specialist" />
                                </label>
                                <label>
                                    <span>Display Order <small>(for sort_by="custom")</small></span>
                                    <input type="number" name="profiles[<?php echo esc_attr( $driver_id ); ?>][sort_order]"
                                           value="<?php echo esc_attr( isset( $profile['sort_order'] ) ? $profile['sort_order'] : '' ); ?>" placeholder="1" min="1" style="width:80px" />
                                </label>
                            </div>

                            <!-- Stats -->
                            <div class="edr-profile-section-heading">
                                Stats
                                <?php if ( $api_linked ) : ?>
                                    <small style="color:#2ecc71;font-weight:normal">&mdash; live values from API shown in placeholders, manual values override if set</small>
                                <?php else : ?>
                                    <small style="font-weight:normal">&mdash; enter manually, or link an iRacing Customer ID for automatic data</small>
                                <?php endif; ?>
                            </div>
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
                                           placeholder="<?php echo esc_attr( $ph ); ?>" />
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
                                    admin_url( 'admin-post.php?action=edr_delete_driver&driver_id=' . urlencode( $driver_id ) ),
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
       POST handlers
       ================================================================ */

    public function handle_add_driver() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }
        if ( ! wp_verify_nonce( isset( $_POST['edr_add_nonce'] ) ? $_POST['edr_add_nonce'] : '', 'edr_add_driver' ) ) {
            wp_die( 'Invalid nonce' );
        }

        $name    = sanitize_text_field( isset( $_POST['driver_name'] ) ? $_POST['driver_name'] : '' );
        $cust_id = sanitize_text_field( isset( $_POST['cust_id'] )     ? $_POST['cust_id']     : '' );
        $role    = sanitize_text_field( isset( $_POST['role'] )         ? $_POST['role']         : '' );
        $number  = sanitize_text_field( isset( $_POST['number'] )       ? $_POST['number']       : '' );

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
            'tagline'       => '',
            'sort_order'    => '',
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
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }

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
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }
        if ( ! wp_verify_nonce( isset( $_POST['edr_profiles_nonce'] ) ? $_POST['edr_profiles_nonce'] : '', 'edr_save_profiles' ) ) {
            wp_die( 'Invalid nonce' );
        }

        $raw = isset( $_POST['profiles'] ) ? $_POST['profiles'] : array();
        $sanitized = array();

        $all_keys = array(
            'name', 'cust_id', 'role', 'number', 'nationality', 'tagline', 'sort_order',
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
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }
        if ( ! wp_verify_nonce( isset( $_POST['edr_sync_nonce'] ) ? $_POST['edr_sync_nonce'] : '', 'edr_sync_api' ) ) {
            wp_die( 'Invalid nonce' );
        }

        $api     = new EDR_IRacing_API();
        $drivers = $api->get_all_driver_data();
        unset( $api );

        if ( empty( $drivers ) || ! is_array( $drivers ) ) {
            wp_redirect( admin_url( 'admin.php?page=' . $this->profiles_slug . '&msg=sync_fail' ) );
            exit;
        }

        $profiles = get_option( $this->profiles_key, array() );
        if ( ! is_array( $profiles ) ) { $profiles = array(); }

        $added = 0;
        foreach ( $drivers as $d ) {
            $cid = intval( $d['cust_id'] );
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
                    'tagline'       => '',
                    'sort_order'    => '',
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
            'sync_fail'     => array( 'error',   'API sync failed. Check your credentials and team ID.' ),
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
}
