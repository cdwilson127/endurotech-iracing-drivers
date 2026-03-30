<?php
/**
 * Frontend rendering — [iracing_drivers] shortcode.
 *
 * Primary data source: admin-managed profiles (edr_driver_profiles).
 * Secondary data source: iRacing API cache (overrides stats when linked).
 *
 * Feature toggles resolve in this order:
 *   1. Explicit shortcode attribute (e.g. card_flip="no")
 *   2. Admin default from edr_style_settings
 *   3. Hardcoded fallback
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EDR_Driver_Display {

    public function __construct() {
        add_shortcode( 'iracing_drivers', array( $this, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function enqueue_assets() {
        global $post;
        if ( $post && has_shortcode( $post->post_content, 'iracing_drivers' ) ) {
            wp_enqueue_style(
                'edr-iracing-drivers',
                EDR_IRACING_PLUGIN_URL . 'assets/css/drivers.css',
                array(), EDR_IRACING_VERSION
            );
            wp_enqueue_script(
                'edr-iracing-drivers-js',
                EDR_IRACING_PLUGIN_URL . 'assets/js/drivers.js',
                array(), EDR_IRACING_VERSION, true
            );
        }
    }

    /* ------------------------------------------------------------------
     * Shortcode entry point
     * ------------------------------------------------------------------ */

    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'title'          => 'Our Drivers',
            'label'          => 'The Team',
            'demo'           => 'no',
            'layout'         => 'cards',
            'columns'        => 'auto',
            'card_style'     => 'default',
            'accent'         => 'auto',
            'sort_by'        => 'irating',
            'sort_order'     => 'desc',
            // Feature toggles — 'inherit' means use admin default
            'show_summary'   => 'inherit',
            'show_last_race' => 'inherit',
            'show_photo'     => 'inherit',
            'show_gear'      => 'inherit',
            'card_flip'      => 'inherit',
            'counters'       => 'inherit',
            'show_trend'     => 'inherit',
            'show_active'    => 'inherit',
            'show_spotlight' => 'inherit',
            'show_ticker'    => 'inherit',
            'show_filter'    => 'inherit',
            'ticker_speed'   => 'inherit',
            // Explicit stat column toggles (no admin default, always on/off)
            'show_role'      => 'yes',
            'show_number'    => 'yes',
            'show_wins'      => 'yes',
            'show_starts'    => 'yes',
            'show_top5'      => 'yes',
            'show_laps'      => 'yes',
            'max_width'      => '',
        ), $atts, 'iracing_drivers' );

        $style_settings = get_option( 'edr_style_settings', array() );
        $o = $this->normalise_options( $atts, $style_settings );

        if ( $o['demo'] ) {
            $drivers  = $this->get_demo_drivers();
            $profiles = $this->get_demo_profiles();
        } else {
            $result   = $this->build_driver_list();
            $drivers  = $result['drivers'];
            $profiles = $result['profiles'];

            if ( empty( $drivers ) ) {
                if ( current_user_can( 'manage_options' ) ) {
                    return '<div class="edr-drivers-notice">No drivers configured yet. '
                         . '<a href="' . esc_url( admin_url( 'admin.php?page=edr-iracing-profiles' ) ) . '">Add drivers</a> '
                         . 'or use <code>[iracing_drivers demo="yes"]</code> to preview the layout.</div>';
                }
                return '<div class="edr-drivers-notice">Driver stats are currently being updated. Check back soon.</div>';
            }
        }

        $drivers = $this->sort_drivers( $drivers, $profiles, $o );

        $inline_css = $this->build_inline_css( $style_settings, $o['accent'], $o['ticker_speed'] );
        $wrap_style = $this->build_wrap_style( $inline_css, $o['max_width'] );

        ob_start();
        ?>
        <div class="edr-drivers-wrap" style="<?php echo esc_attr( $wrap_style ); ?>">

            <?php if ( $o['demo'] ) : ?>
            <div class="edr-demo-banner">
                Preview mode &mdash; sample data only. Remove <code>demo="yes"</code> once your drivers are configured.
            </div>
            <?php endif; ?>

            <?php $this->render_header( $o['title'], $o['label'], $drivers, $style_settings ); ?>

            <?php if ( $o['show_ticker'] ) : ?>
                <?php $this->render_ticker( $drivers, $profiles, $o['ticker_speed'] ); ?>
            <?php endif; ?>

            <?php if ( $o['show_summary'] ) : ?>
                <?php $this->render_summary( $drivers ); ?>
            <?php endif; ?>

            <?php if ( $o['show_filter'] && 'cards' === $o['layout'] ) : ?>
                <?php $this->render_filter_bar( $drivers, $profiles ); ?>
            <?php endif; ?>

            <?php if ( $o['show_spotlight'] && 'cards' === $o['layout'] ) : ?>
                <?php $this->render_spotlight( $drivers, $profiles, $o ); ?>
            <?php endif; ?>

            <?php if ( 'cards' === $o['layout'] ) : ?>
                <?php $this->render_cards( $drivers, $profiles, $o ); ?>
            <?php else : ?>
                <?php $this->render_table( $drivers, $profiles, $o ); ?>
            <?php endif; ?>

            <p class="edr-drivers-footer">Data sourced from the iRacing Data API</p>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ------------------------------------------------------------------
     * Build driver list from profiles + API merge
     * ------------------------------------------------------------------ */

    private function build_driver_list() {
        $profiles = get_option( 'edr_driver_profiles', array() );
        if ( ! is_array( $profiles ) ) { $profiles = array(); }

        $api_cache = get_transient( 'edr_iracing_drivers_cache' );

        // If the cache has expired, schedule a background refresh via WP-Cron
        // instead of fetching synchronously (which caused 504 gateway timeouts).
        if ( false === $api_cache && ! get_transient( 'edr_iracing_refresh_lock' ) ) {
            set_transient( 'edr_iracing_refresh_lock', 1, 120 );
            if ( ! wp_next_scheduled( 'edr_cron_sync_drivers' ) ) {
                wp_schedule_single_event( time(), 'edr_cron_sync_drivers' );
            }
            // Trigger WP-Cron spawn so the sync starts immediately in the background.
            if ( function_exists( 'spawn_cron' ) ) {
                spawn_cron();
            }
        }

        if ( ! is_array( $api_cache ) || empty( $api_cache ) ) {
            $snapshot = get_option( 'edr_iracing_api_snapshot', array() );
            if ( is_array( $snapshot ) && ! empty( $snapshot ) ) {
                $api_cache = $snapshot;
            }
        }

        if ( ! is_array( $api_cache ) ) { $api_cache = array(); }

        $api_lookup = array();
        foreach ( $api_cache as $ad ) {
            if ( isset( $ad['cust_id'] ) && $ad['cust_id'] ) {
                $api_lookup[ intval( $ad['cust_id'] ) ] = $ad;
            }
        }
        unset( $api_cache );

        $drivers = array();

        foreach ( $profiles as $driver_id => $profile ) {
            $name    = isset( $profile['name'] )    ? $profile['name']    : 'Unknown';
            $cust_id = isset( $profile['cust_id'] ) ? $profile['cust_id'] : '';

            $manual_ir = isset( $profile['irating'] )       ? $profile['irating']       : '';
            $manual_sr = isset( $profile['safety_rating'] )  ? $profile['safety_rating'] : '';
            $manual_w  = isset( $profile['wins'] )           ? $profile['wins']          : '';
            $manual_s  = isset( $profile['starts'] )         ? $profile['starts']        : '';
            $manual_t5 = isset( $profile['top5'] )           ? $profile['top5']          : '';
            $manual_l  = isset( $profile['laps'] )           ? $profile['laps']          : '';

            // Null means "no valid iRating" (new/unrated member); -1 = iRacing's sentinel for unrated.
            $raw_ir  = $manual_ir !== '' ? intval( $manual_ir ) : null;
            $irating = ( null !== $raw_ir && $raw_ir >= 0 ) ? $raw_ir : null;
            $irating_prev   = null;
            $safety_rating  = $manual_sr !== '' ? $manual_sr           : '';
            $license_class  = null;
            $wins           = $manual_w  !== '' ? intval( $manual_w )  : 0;
            $starts         = $manual_s  !== '' ? intval( $manual_s )  : 0;
            $top5           = $manual_t5 !== '' ? intval( $manual_t5 ) : 0;
            $laps           = $manual_l  !== '' ? intval( $manual_l )  : 0;
            $last_race      = null;
            $last_race_date = '';

            if ( $cust_id && is_numeric( $cust_id ) && isset( $api_lookup[ intval( $cust_id ) ] ) ) {
                $api = $api_lookup[ intval( $cust_id ) ];

                if ( $manual_ir === '' && array_key_exists( 'irating', $api ) ) {
                    $api_ir  = $api['irating'];
                    $irating = ( null !== $api_ir && intval( $api_ir ) >= 0 ) ? intval( $api_ir ) : null;
                }
                if ( isset( $api['irating_prev'] ) ) { $irating_prev = $api['irating_prev']; }
                if ( $manual_sr === '' && isset( $api['safety_rating'] ) ) { $safety_rating = $api['safety_rating']; }
                if ( isset( $api['license_class'] ) ) { $license_class = $api['license_class']; }
                if ( $manual_w  === '' && isset( $api['wins'] ) )          { $wins          = intval( $api['wins'] ); }
                if ( $manual_s  === '' && isset( $api['starts'] ) )        { $starts        = intval( $api['starts'] ); }
                if ( $manual_t5 === '' && isset( $api['top5'] ) )          { $top5          = intval( $api['top5'] ); }
                if ( $manual_l  === '' && isset( $api['laps'] ) )          { $laps          = intval( $api['laps'] ); }

                if ( ! empty( $api['last_race'] ) )      { $last_race      = $api['last_race']; }
                if ( ! empty( $api['last_race_date'] ) ) { $last_race_date = $api['last_race_date']; }

                if ( 'Unknown' === $name && ! empty( $api['name'] ) ) { $name = $api['name']; }

                unset( $api );
            }

            $drivers[] = array(
                'driver_id'      => $driver_id,
                'cust_id'        => $cust_id ? $cust_id : $driver_id,
                'name'           => $name,
                'irating'        => $irating,
                'irating_prev'   => $irating_prev,
                'safety_rating'  => $safety_rating,
                'license_class'  => $license_class,
                'wins'           => $wins,
                'starts'         => $starts,
                'top5'           => $top5,
                'laps'           => $laps,
                'last_race'      => $last_race,
                'last_race_date' => $last_race_date,
            );
        }
        unset( $api_lookup );

        return array( 'drivers' => $drivers, 'profiles' => $profiles );
    }

    /* ------------------------------------------------------------------
     * Feature toggle resolution
     * ------------------------------------------------------------------ */

    /**
     * Resolve a feature toggle via three-tier priority:
     *   1. Explicit shortcode attribute ("yes"/"no")
     *   2. Admin default from edr_style_settings
     *   3. Hardcoded fallback
     */
    private function feature_enabled( $raw_attr, $admin_settings, $feature_key ) {
        $val = strtolower( (string) $raw_attr );

        if ( 'yes' === $val ) { return true; }
        if ( 'no'  === $val ) { return false; }

        // 'inherit' or anything else → fall through to admin default
        $admin_key = 'feature_' . $feature_key;
        if ( isset( $admin_settings[ $admin_key ] ) ) {
            return (bool) $admin_settings[ $admin_key ];
        }

        // Hardcoded fallbacks
        $fallbacks = array(
            'card_flip'      => true,
            'counters'       => true,
            'show_trend'     => true,
            'show_active'    => true,
            'show_spotlight' => true,
            'show_ticker'    => false,
            'show_filter'    => false,
            'show_summary'   => true,
            'show_last_race' => true,
            'show_photo'     => true,
            'show_gear'      => true,
        );

        return isset( $fallbacks[ $feature_key ] ) ? $fallbacks[ $feature_key ] : false;
    }

    /* ------------------------------------------------------------------
     * Options normalisation
     * ------------------------------------------------------------------ */

    private function normalise_options( $atts, $style_settings ) {
        $o = array();

        // Simple bool attributes (always explicit, no admin default)
        $explicit_bool = array( 'demo', 'show_role', 'show_number', 'show_wins', 'show_starts', 'show_top5', 'show_laps' );
        foreach ( $explicit_bool as $k ) {
            $o[ $k ] = ( 'yes' === strtolower( (string) $atts[ $k ] ) );
        }

        // Feature toggles (three-tier resolution)
        $feature_keys = array( 'show_summary', 'show_last_race', 'show_photo', 'show_gear',
                               'card_flip', 'counters', 'show_trend', 'show_active',
                               'show_spotlight', 'show_ticker', 'show_filter' );
        foreach ( $feature_keys as $k ) {
            $o[ $k ] = $this->feature_enabled( $atts[ $k ], $style_settings, $k );
        }

        $o['title']      = $atts['title'];
        $o['label']      = $atts['label'];
        $o['layout']     = in_array( strtolower( $atts['layout'] ), array( 'cards', 'table' ), true ) ? strtolower( $atts['layout'] ) : 'cards';
        $o['columns']    = in_array( strtolower( $atts['columns'] ), array( 'auto', '1', '2', '3', '4' ), true ) ? strtolower( $atts['columns'] ) : 'auto';
        $o['card_style'] = in_array( strtolower( $atts['card_style'] ), array( 'default', 'minimal' ), true ) ? strtolower( $atts['card_style'] ) : 'default';
        $o['accent']     = in_array( strtolower( $atts['accent'] ), array( 'auto', 'red', 'blue', 'green', 'gold' ), true ) ? strtolower( $atts['accent'] ) : 'auto';
        $o['sort_by']    = in_array( strtolower( $atts['sort_by'] ), array( 'irating', 'wins', 'starts', 'name', 'custom' ), true ) ? strtolower( $atts['sort_by'] ) : 'irating';
        $o['sort_order'] = ( 'asc' === strtolower( $atts['sort_order'] ) ) ? 'asc' : 'desc';

        // Ticker speed in seconds — shortcode overrides admin setting overrides default (60s)
        $raw_speed = strtolower( (string) $atts['ticker_speed'] );
        if ( 'inherit' === $raw_speed ) {
            $admin_speed = isset( $style_settings['ticker_speed'] ) ? intval( $style_settings['ticker_speed'] ) : 0;
            $o['ticker_speed'] = $admin_speed > 0 ? $admin_speed : 60;
        } else {
            $parsed = intval( $raw_speed );
            $o['ticker_speed'] = $parsed > 0 ? $parsed : 60;
        }

        // 3D flip is disabled in minimal card style
        if ( 'minimal' === $o['card_style'] ) {
            $o['card_flip'] = false;
        }

        // Max width — e.g. "1200px", "90%", or empty for full width
        $raw_mw = trim( $atts['max_width'] );
        if ( '' === $raw_mw && isset( $style_settings['max_width'] ) && '' !== trim( $style_settings['max_width'] ) ) {
            $raw_mw = trim( $style_settings['max_width'] );
        }
        $o['max_width'] = $raw_mw;

        return $o;
    }

    /* ------------------------------------------------------------------
     * Inline CSS variable builder
     * ------------------------------------------------------------------ */

    private function build_inline_css( $style, $accent_override = 'auto', $ticker_speed = 60 ) {
        $presets = array(
            'red'  => array( '#e63946', '230,57,70' ),
            'blue' => array( '#2196f3', '33,150,243' ),
            'green'=> array( '#2ecc71', '46,204,113' ),
            'gold' => array( '#f1c40f', '241,196,15' ),
        );

        if ( 'auto' !== $accent_override && isset( $presets[ $accent_override ] ) ) {
            list( $accent_hex, $accent_rgb ) = $presets[ $accent_override ];
        } else {
            $accent_hex = isset( $style['accent_color'] ) ? $style['accent_color'] : '#f1c40f';
            $accent_rgb = $this->hex_to_rgb( $accent_hex );
            if ( ! $accent_rgb ) { $accent_rgb = '241,196,15'; }
        }

        $card_bg = isset( $style['card_bg'] ) ? $style['card_bg'] : '#161616';
        $radius  = isset( $style['border_radius'] ) ? intval( $style['border_radius'] ) : 10;

        $speed = max( 5, intval( $ticker_speed ) );
        $css = sprintf(
            '--edr-accent:%s;--edr-accent-dim:rgba(%s,0.18);--edr-accent-hover:rgba(%s,0.08);--edr-bg-card:%s;--edr-radius:%dpx;--edr-ticker-speed:%ds',
            esc_attr( $accent_hex ), $accent_rgb, $accent_rgb, esc_attr( $card_bg ), $radius, $speed
        );
        return $css;
    }

    private function build_wrap_style( $inline_css, $max_width ) {
        $style = $inline_css;
        if ( '' !== $max_width ) {
            $clean = preg_replace( '/[^0-9a-zA-Z%\.px]/', '', $max_width );
            if ( $clean ) {
                $style .= ';--edr-max-width:' . $clean;
            }
        }
        return $style;
    }

    private function hex_to_rgb( $hex ) {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if ( strlen( $hex ) !== 6 ) { return null; }
        return hexdec( substr( $hex, 0, 2 ) ) . ',' . hexdec( substr( $hex, 2, 2 ) ) . ',' . hexdec( substr( $hex, 4, 2 ) );
    }

    /* ------------------------------------------------------------------
     * Sorting
     * ------------------------------------------------------------------ */

    private function sort_drivers( $drivers, $profiles, $o ) {
        $sort_by    = $o['sort_by'];
        $sort_order = $o['sort_order'];

        usort( $drivers, function ( $a, $b ) use ( $sort_by, $sort_order, $profiles ) {
            $id_a = isset( $a['driver_id'] ) ? $a['driver_id'] : $a['cust_id'];
            $id_b = isset( $b['driver_id'] ) ? $b['driver_id'] : $b['cust_id'];

            switch ( $sort_by ) {
                case 'name':
                    $result = strcmp( $a['name'], $b['name'] );
                    break;
                case 'wins':
                    $result = intval( $a['wins'] ) - intval( $b['wins'] );
                    break;
                case 'starts':
                    $result = intval( $a['starts'] ) - intval( $b['starts'] );
                    break;
                case 'custom':
                    $order_a = isset( $profiles[ $id_a ]['sort_order'] ) && $profiles[ $id_a ]['sort_order'] !== ''
                        ? intval( $profiles[ $id_a ]['sort_order'] ) : 9999;
                    $order_b = isset( $profiles[ $id_b ]['sort_order'] ) && $profiles[ $id_b ]['sort_order'] !== ''
                        ? intval( $profiles[ $id_b ]['sort_order'] ) : 9999;
                    $result = $order_a - $order_b;
                    break;
                case 'irating':
                default:
                    $a_ir = ( null !== $a['irating'] ) ? intval( $a['irating'] ) : -1;
                    $b_ir = ( null !== $b['irating'] ) ? intval( $b['irating'] ) : -1;
                    $result = $a_ir - $b_ir;
                    break;
            }

            if ( 'name' === $sort_by || 'custom' === $sort_by ) {
                return ( 'desc' === $sort_order ) ? -$result : $result;
            }
            return ( 'asc' === $sort_order ) ? $result : -$result;
        } );

        return $drivers;
    }

    /* ------------------------------------------------------------------
     * Header
     * ------------------------------------------------------------------ */

    private function render_header( $title, $label, $drivers, $style_settings ) {
        $subtitle = isset( $style_settings['subtitle_text'] ) ? trim( $style_settings['subtitle_text'] ) : '';
        $hidden   = ( 'none' === strtolower( $subtitle ) );
        $show_label = ! empty( $label );
        ?>
        <div class="edr-drivers-header">
            <?php if ( $show_label ) : ?>
                <div class="edr-section-label"><?php echo esc_html( $label ); ?></div>
            <?php endif; ?>
            <h2 class="edr-section-heading"><?php echo esc_html( $title ); ?></h2>
            <?php if ( ! $hidden ) : ?>
                <?php if ( '' !== $subtitle ) : ?>
                    <p class="edr-drivers-subtitle"><?php echo esc_html( $subtitle ); ?></p>
                <?php else : ?>
                    <p class="edr-drivers-subtitle"><?php echo count( $drivers ); ?> drivers &middot; Live stats from iRacing</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Summary stat bar
     * ------------------------------------------------------------------ */

    private function render_summary( $drivers ) {
        $total_wins   = array_sum( array_column( $drivers, 'wins' ) );
        $total_starts = array_sum( array_column( $drivers, 'starts' ) );
        $total_laps   = array_sum( array_column( $drivers, 'laps' ) );
        $avg_ir = 0; $ir_n = 0;
        foreach ( $drivers as $d ) {
            if ( null !== $d['irating'] && $d['irating'] > 0 ) {
                $avg_ir += $d['irating'];
                $ir_n++;
            }
        }
        $avg_ir = $ir_n ? round( $avg_ir / $ir_n ) : 0;
        ?>
        <div class="edr-drivers-stats-bar">
            <div class="edr-stat-card"><span class="edr-stat-value" data-counter="<?php echo count( $drivers ); ?>"><?php echo number_format( count( $drivers ) ); ?></span><span class="edr-stat-label">Drivers</span></div>
            <div class="edr-stat-card"><span class="edr-stat-value" data-counter="<?php echo $avg_ir; ?>"><?php echo intval( $avg_ir ); ?></span><span class="edr-stat-label">Avg iRating</span></div>
            <div class="edr-stat-card"><span class="edr-stat-value" data-counter="<?php echo $total_wins; ?>"><?php echo number_format( $total_wins ); ?></span><span class="edr-stat-label">Total Wins</span></div>
            <div class="edr-stat-card"><span class="edr-stat-value" data-counter="<?php echo $total_starts; ?>"><?php echo number_format( $total_starts ); ?></span><span class="edr-stat-label">Total Starts</span></div>
            <div class="edr-stat-card"><span class="edr-stat-value" data-counter="<?php echo $total_laps; ?>"><?php echo number_format( $total_laps ); ?></span><span class="edr-stat-label">Total Laps</span></div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Role filter bar
     * ------------------------------------------------------------------ */

    private function render_filter_bar( $drivers, $profiles ) {
        $role_labels = array(
            'captain' => 'Captain',
            'lead'    => 'Lead',
            'pro'     => 'Pro',
            'silver'  => 'Silver',
            'bronze'  => 'Bronze',
            'reserve' => 'Reserve',
            'academy' => 'Academy',
        );

        $present_roles = array();
        foreach ( $drivers as $d ) {
            $did  = isset( $d['driver_id'] ) ? $d['driver_id'] : $d['cust_id'];
            $role = isset( $profiles[ $did ]['role'] ) ? $profiles[ $did ]['role'] : '';
            if ( $role && isset( $role_labels[ $role ] ) ) {
                $present_roles[ $role ] = $role_labels[ $role ];
            }
        }

        if ( empty( $present_roles ) ) { return; }
        ?>
        <div class="edr-filter-bar" role="group" aria-label="Filter drivers by role">
            <button class="edr-filter-btn edr-filter-active" data-filter="all">All</button>
            <?php foreach ( $present_roles as $role => $label ) : ?>
            <button class="edr-filter-btn" data-filter="<?php echo esc_attr( $role ); ?>"><?php echo esc_html( $label ); ?></button>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Spotlight section (featured drivers)
     * ------------------------------------------------------------------ */

    private function render_spotlight( $drivers, $profiles, $o ) {
        $featured = array();
        foreach ( $drivers as $d ) {
            $did = isset( $d['driver_id'] ) ? $d['driver_id'] : $d['cust_id'];
            $d_role = isset( $profiles[ $did ]['role'] ) ? $profiles[ $did ]['role'] : '';
            if ( 'inactive' === $d_role ) { continue; }
            if ( ! empty( $profiles[ $did ]['featured'] ) ) {
                $featured[] = $d;
            }
        }
        if ( empty( $featured ) ) { return; }
        ?>
        <div class="edr-spotlight-section">
            <?php foreach ( $featured as $driver ) :
                $did     = isset( $driver['driver_id'] ) ? $driver['driver_id'] : $driver['cust_id'];
                $profile = isset( $profiles[ $did ] ) ? $profiles[ $did ] : array();
                $photo   = isset( $profile['photo_url'] ) ? $profile['photo_url'] : '';
                $role    = isset( $profile['role'] )      ? $profile['role']      : '';
                $number  = isset( $profile['number'] )    ? $profile['number']    : '';
                $nat     = isset( $profile['nationality'] ) ? $profile['nationality'] : '';
                $flag    = isset( $profile['flag_code'] ) ? $this->get_flag( $profile['flag_code'] ) : '';
                $tagline = isset( $profile['tagline'] )   ? $profile['tagline']   : '';
            ?>
            <div class="edr-spotlight-card">
                <?php if ( $o['show_photo'] && $photo ) : ?>
                <div class="edr-spotlight-photo">
                    <img src="<?php echo esc_url( $photo ); ?>" alt="<?php echo esc_attr( $driver['name'] ); ?>" loading="lazy" />
                </div>
                <?php endif; ?>
                <div class="edr-spotlight-info">
                    <div class="edr-spotlight-meta">
                        <?php if ( $number ) : ?>
                            <span class="edr-driver-number edr-spotlight-num"><?php echo esc_html( $number ); ?></span>
                        <?php endif; ?>
                        <?php if ( $role ) : ?>
                            <?php echo $this->role_badge( $role ); ?>
                        <?php endif; ?>
                        <span class="edr-spotlight-label">Featured Driver</span>
                    </div>
                    <h3 class="edr-spotlight-name"><?php echo esc_html( $driver['name'] ); ?></h3>
                    <?php if ( $flag || $nat ) : ?>
                        <span class="edr-driver-nat"><?php echo $flag ? $flag . ' ' : ''; ?><?php echo esc_html( $nat ); ?></span>
                    <?php endif; ?>
                    <?php if ( $tagline ) : ?>
                        <p class="edr-spotlight-tagline"><?php echo esc_html( $tagline ); ?></p>
                    <?php endif; ?>
                    <div class="edr-spotlight-stats">
                        <?php if ( null !== $driver['irating'] && $driver['irating'] > 0 ) : ?>
                            <div class="edr-spotlight-stat">
                                <span class="edr-spotlight-stat-val"><?php echo intval( $driver['irating'] ); ?></span>
                                <span class="edr-spotlight-stat-lbl">iRating</span>
                            </div>
                        <?php endif; ?>
                        <div class="edr-spotlight-stat">
                            <span class="edr-spotlight-stat-val"><?php echo number_format( $driver['wins'] ); ?></span>
                            <span class="edr-spotlight-stat-lbl">Wins</span>
                        </div>
                        <div class="edr-spotlight-stat">
                            <span class="edr-spotlight-stat-val"><?php echo number_format( $driver['starts'] ); ?></span>
                            <span class="edr-spotlight-stat-lbl">Starts</span>
                        </div>
                        <div class="edr-spotlight-stat">
                            <span class="edr-spotlight-stat-val"><?php echo number_format( $driver['top5'] ); ?></span>
                            <span class="edr-spotlight-stat-lbl">Top 5s</span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Race ticker
     * ------------------------------------------------------------------ */

    private function render_ticker( $drivers, $profiles, $speed = 60 ) {
        $items = array();
        foreach ( $drivers as $d ) {
            if ( empty( $d['last_race'] ) ) { continue; }
            $lr  = $d['last_race'];
            $did = isset( $d['driver_id'] ) ? $d['driver_id'] : $d['cust_id'];
            $num = isset( $profiles[ $did ]['number'] ) ? $profiles[ $did ]['number'] : '';
            $label = $num ? '#' . $num . ' ' . $d['name'] : $d['name'];
            $items[] = 'P' . $lr['finish'] . ' &mdash; ' . esc_html( $label ) . ' at ' . esc_html( $lr['track'] );
        }

        if ( empty( $items ) ) { return; }

        // Duplicate for seamless loop
        $content = implode( ' &nbsp;&nbsp;&bull;&nbsp;&nbsp; ', array_merge( $items, $items ) );
        $speed_s = max( 5, intval( $speed ) ) . 's';
        ?>
        <div class="edr-ticker-wrap" style="--edr-ticker-speed:<?php echo esc_attr( $speed_s ); ?>">
            <span class="edr-ticker-label">Latest Results</span>
            <div class="edr-ticker-track" aria-hidden="true">
                <div class="edr-ticker-content"><?php echo $content; ?></div>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Card grid layout
     * ------------------------------------------------------------------ */

    private function render_cards( $drivers, $profiles, $o ) {
        $grid_class = 'edr-cards-grid';
        if ( 'auto' !== $o['columns'] ) {
            $grid_class .= ' edr-cols-' . $o['columns'];
        }
        if ( 'minimal' === $o['card_style'] ) {
            $grid_class .= ' edr-cards-minimal';
        }
        ?>
        <div class="<?php echo esc_attr( $grid_class ); ?>">
            <?php foreach ( $drivers as $i => $driver ) :
                $did       = isset( $driver['driver_id'] ) ? $driver['driver_id'] : $driver['cust_id'];
                $profile   = isset( $profiles[ $did ] ) ? $profiles[ $did ] : array();
                $role      = isset( $profile['role'] )        ? $profile['role']        : '';

                // Skip inactive drivers — they stay in admin but are hidden from the site.
                if ( 'inactive' === $role ) { continue; }

                $photo     = isset( $profile['photo_url'] )   ? $profile['photo_url']   : '';
                $number    = isset( $profile['number'] )      ? $profile['number']      : '';
                $nat       = isset( $profile['nationality'] )  ? $profile['nationality'] : '';
                $flag_code = isset( $profile['flag_code'] )   ? $profile['flag_code']   : '';
                $flag      = $this->get_flag( $flag_code );
                $tagline   = isset( $profile['tagline'] )     ? wp_unslash( $profile['tagline'] ) : '';
                $gear      = $this->get_gear_items( $profile );

                $has_photo = $o['show_photo'] && ! empty( $photo );
                $has_role  = $o['show_role']  && ! empty( $role );
                $has_num   = $o['show_number'] && ! empty( $number );
                $has_gear  = $o['show_gear']  && ! empty( $gear );

                $is_active = $this->is_recently_active( $driver );

                // Trend calculation
                $trend_html = '';
                if ( $o['show_trend'] && null !== $driver['irating'] && null !== $driver['irating_prev'] ) {
                    $diff = intval( $driver['irating'] ) - intval( $driver['irating_prev'] );
                    if ( $diff > 0 ) {
                        $trend_html = '<span class="edr-trend edr-trend-up">&#9650; +' . number_format( $diff ) . '</span>';
                    } elseif ( $diff < 0 ) {
                        $trend_html = '<span class="edr-trend edr-trend-down">&#9660; ' . number_format( $diff ) . '</span>';
                    }
                }

                $card_class = 'edr-driver-card';
                if ( $o['card_flip'] )  { $card_class .= ' edr-flippable'; }
                if ( $has_photo )       { $card_class .= ' edr-card-has-photo'; }
                if ( $has_gear )        { $card_class .= ' edr-card-has-gear'; }
                if ( $is_active && $o['show_active'] ) { $card_class .= ' edr-card-active'; }
            ?>
            <div class="<?php echo esc_attr( $card_class ); ?>"
                 data-role="<?php echo esc_attr( $role ); ?>">

                <?php if ( $o['card_flip'] ) : ?>
                <div class="edr-card-inner">
                    <!-- Front face -->
                    <div class="edr-card-front">
                <?php endif; ?>

                        <div class="edr-card-top">
                            <?php if ( $has_photo ) : ?>
                            <div class="edr-card-photo">
                                <img src="<?php echo esc_url( $photo ); ?>"
                                     alt="<?php echo esc_attr( $driver['name'] ); ?>"
                                     loading="lazy" />
                            </div>
                            <?php endif; ?>

                            <div class="edr-card-identity">
                                <div class="edr-card-meta-row">
                                    <span class="edr-card-rank">#<?php echo $i + 1; ?></span>
                                    <?php if ( $has_num ) : ?>
                                        <span class="edr-driver-number"><?php echo esc_html( $number ); ?></span>
                                    <?php endif; ?>
                                    <?php if ( $has_role ) : ?>
                                        <?php echo $this->role_badge( $role ); ?>
                                    <?php endif; ?>
                                    <?php if ( $o['show_active'] && $is_active ) : ?>
                                        <span class="edr-active-dot" title="Raced in the last 30 days"></span>
                                    <?php endif; ?>
                                </div>

                                <h3 class="edr-card-name"><?php echo esc_html( $driver['name'] ); ?></h3>

                                <?php if ( $flag || $nat ) : ?>
                                    <span class="edr-driver-nat"><?php echo $flag ? $flag . ' ' : ''; ?><?php echo esc_html( $nat ); ?></span>
                                <?php endif; ?>

                                <?php if ( ! empty( $tagline ) && ! $o['card_flip'] ) : ?>
                                    <p class="edr-driver-tagline"><?php echo esc_html( $tagline ); ?></p>
                                <?php endif; ?>

                                <div class="edr-card-badges">
                                    <?php if ( null !== $driver['irating'] && $driver['irating'] > 0 ) : ?>
                                        <span class="edr-irating-badge"
                                              data-counter="<?php echo intval( $driver['irating'] ); ?>">
                                            iR <?php echo intval( $driver['irating'] ); ?>
                                        </span>
                                        <?php echo $trend_html; ?>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $driver['safety_rating'] ) ) : ?>
                                        <?php $lic = isset( $driver['license_class'] ) ? intval( $driver['license_class'] ) : 0; ?>
                                        <span class="edr-sr-lic-badge <?php echo esc_attr( $this->license_css_class( $lic ) ); ?>">
                                            <span class="edr-sr-val"><?php echo esc_html( $driver['safety_rating'] ); ?></span>
                                            <span class="edr-lic-letter"><?php echo esc_html( $this->license_letter( $lic ) ); ?></span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php $any_stat = $o['show_wins'] || $o['show_starts'] || $o['show_top5'] || $o['show_laps']; ?>
                        <?php if ( $any_stat ) : ?>
                        <div class="edr-card-stats">
                            <?php if ( $o['show_wins'] ) : ?>
                            <div class="edr-card-stat">
                                <span class="edr-card-stat-val" data-counter="<?php echo intval( $driver['wins'] ); ?>"><?php echo number_format( $driver['wins'] ); ?></span>
                                <span class="edr-card-stat-lbl">Wins</span>
                            </div>
                            <?php endif; ?>
                            <?php if ( $o['show_starts'] ) : ?>
                            <div class="edr-card-stat">
                                <span class="edr-card-stat-val" data-counter="<?php echo intval( $driver['starts'] ); ?>"><?php echo number_format( $driver['starts'] ); ?></span>
                                <span class="edr-card-stat-lbl">Starts</span>
                            </div>
                            <?php endif; ?>
                            <?php if ( $o['show_top5'] ) : ?>
                            <div class="edr-card-stat">
                                <span class="edr-card-stat-val" data-counter="<?php echo intval( $driver['top5'] ); ?>"><?php echo number_format( $driver['top5'] ); ?></span>
                                <span class="edr-card-stat-lbl">Top 5s</span>
                            </div>
                            <?php endif; ?>
                            <?php if ( $o['show_laps'] ) : ?>
                            <div class="edr-card-stat">
                                <span class="edr-card-stat-val" data-counter="<?php echo intval( $driver['laps'] ); ?>"><?php echo number_format( $driver['laps'] ); ?></span>
                                <span class="edr-card-stat-lbl">Laps</span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ( $o['show_last_race'] && ! empty( $driver['last_race'] ) ) : ?>
                        <div class="edr-card-lastrace">
                            <span class="edr-lastrace-pos">P<?php echo esc_html( $driver['last_race']['finish'] ); ?></span>
                            <span class="edr-lastrace-detail">
                                <?php echo esc_html( $driver['last_race']['track'] ); ?>
                                <small><?php echo esc_html( $driver['last_race']['series'] ); ?></small>
                            </span>
                        </div>
                        <?php endif; ?>

                        <?php if ( $has_gear && ! $o['card_flip'] && 'minimal' !== $o['card_style'] ) : ?>
                        <div class="edr-card-gear">
                            <span class="edr-gear-heading">Sim Setup</span>
                            <div class="edr-gear-list">
                                <?php foreach ( $gear as $label => $value ) : ?>
                                <div class="edr-gear-item">
                                    <span class="edr-gear-label"><?php echo esc_html( $label ); ?></span>
                                    <span class="edr-gear-value"><?php echo esc_html( $value ); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                <?php if ( $o['card_flip'] ) : ?>
                    </div><!-- /.edr-card-front -->

                    <!-- Back face -->
                    <div class="edr-card-back">
                        <div class="edr-card-back-inner">
                            <h3 class="edr-card-back-name">
                                <?php echo esc_html( $driver['name'] ); ?>
                                <?php if ( $o['show_active'] && $is_active ) : ?>
                                    <span class="edr-active-dot" title="Raced in the last 30 days"></span>
                                <?php endif; ?>
                            </h3>

                            <?php if ( $flag || $nat ) : ?>
                                <span class="edr-driver-nat edr-back-nat"><?php echo $flag ? $flag . ' ' : ''; ?><?php echo esc_html( $nat ); ?></span>
                            <?php endif; ?>

                            <?php if ( $tagline ) : ?>
                                <p class="edr-driver-tagline edr-back-tagline"><?php echo esc_html( $tagline ); ?></p>
                            <?php endif; ?>

                            <?php if ( null !== $driver['irating'] && $driver['irating'] > 0 ) : ?>
                            <div class="edr-back-stat-row">
                                <span>iR <?php echo intval( $driver['irating'] ); ?></span>
                                <?php echo $trend_html; ?>
                                <?php if ( ! empty( $driver['safety_rating'] ) ) : ?>
                                    <?php $lic_back = isset( $driver['license_class'] ) ? intval( $driver['license_class'] ) : 0; ?>
                                    <span class="edr-sr-lic-badge <?php echo esc_attr( $this->license_css_class( $lic_back ) ); ?>">
                                        <span class="edr-sr-val"><?php echo esc_html( $driver['safety_rating'] ); ?></span>
                                        <span class="edr-lic-letter"><?php echo esc_html( $this->license_letter( $lic_back ) ); ?></span>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <?php if ( $has_gear ) : ?>
                            <div class="edr-back-gear">
                                <span class="edr-gear-heading">Sim Setup</span>
                                <div class="edr-gear-list">
                                    <?php foreach ( $gear as $label => $value ) : ?>
                                    <div class="edr-gear-item">
                                        <span class="edr-gear-label"><?php echo esc_html( $label ); ?></span>
                                        <span class="edr-gear-value"><?php echo esc_html( $value ); ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ( $o['show_last_race'] && ! empty( $driver['last_race'] ) ) : ?>
                            <div class="edr-back-last-race">
                                <span class="edr-gear-heading">Last Race</span>
                                <div class="edr-lastrace-info">
                                    <span class="edr-lastrace-pos">P<?php echo esc_html( $driver['last_race']['finish'] ); ?></span>
                                    <span class="edr-lastrace-detail">
                                        <?php echo esc_html( $driver['last_race']['track'] ); ?>
                                        <small><?php echo esc_html( $driver['last_race']['series'] ); ?></small>
                                    </span>
                                </div>
                            </div>
                            <?php endif; ?>

                            <p class="edr-flip-hint">Hover off to flip back</p>
                        </div>
                    </div><!-- /.edr-card-back -->
                </div><!-- /.edr-card-inner -->
                <?php endif; ?>

            </div><!-- /.edr-driver-card -->
            <?php endforeach; ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Table layout
     * ------------------------------------------------------------------ */

    private function render_table( $drivers, $profiles, $o ) {
        ?>
        <div class="edr-drivers-table-wrap">
            <table class="edr-drivers-table">
                <thead>
                    <tr>
                        <th class="edr-col-rank">#</th>
                        <?php if ( $o['show_role'] || $o['show_number'] ) : ?>
                        <th class="edr-col-role"></th>
                        <?php endif; ?>
                        <th class="edr-col-name">Driver</th>
                        <th class="edr-col-ir">iRating</th>
                        <th class="edr-col-sr">SR</th>
                        <?php if ( $o['show_wins'] )   : ?><th class="edr-col-wins">Wins</th><?php endif; ?>
                        <?php if ( $o['show_starts'] ) : ?><th class="edr-col-starts">Starts</th><?php endif; ?>
                        <?php if ( $o['show_top5'] )   : ?><th class="edr-col-top5">Top 5s</th><?php endif; ?>
                        <?php if ( $o['show_laps'] )   : ?><th class="edr-col-laps">Laps</th><?php endif; ?>
                        <?php if ( $o['show_last_race'] ) : ?><th class="edr-col-lastrace">Last Race</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $drivers as $i => $driver ) :
                        $did      = isset( $driver['driver_id'] ) ? $driver['driver_id'] : $driver['cust_id'];
                        $profile  = isset( $profiles[ $did ] ) ? $profiles[ $did ] : array();
                        $role     = isset( $profile['role'] )      ? $profile['role']      : '';
                        if ( 'inactive' === $role ) { continue; }
                        $number   = isset( $profile['number'] )    ? $profile['number']    : '';
                        $nat      = isset( $profile['nationality'] ) ? $profile['nationality'] : '';
                        $flag     = isset( $profile['flag_code'] ) ? $this->get_flag( $profile['flag_code'] ) : '';
                        $is_active = $this->is_recently_active( $driver );
                    ?>
                    <tr>
                        <td class="edr-col-rank"><?php echo $i + 1; ?></td>

                        <?php if ( $o['show_role'] || $o['show_number'] ) : ?>
                        <td class="edr-col-role" style="white-space:nowrap">
                            <?php if ( $o['show_number'] && ! empty( $number ) ) : ?>
                                <span class="edr-driver-number"><?php echo esc_html( $number ); ?></span>
                            <?php endif; ?>
                            <?php if ( $o['show_role'] && ! empty( $role ) ) : ?>
                                <?php echo $this->role_badge( $role ); ?>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>

                        <td class="edr-col-name">
                            <span class="edr-driver-name"><?php echo esc_html( $driver['name'] ); ?></span>
                            <?php if ( $flag || $nat ) : ?>
                                <span class="edr-driver-nat-inline"><?php echo $flag ? $flag . ' ' : ''; ?><?php echo esc_html( $nat ); ?></span>
                            <?php endif; ?>
                            <?php if ( $o['show_active'] && $is_active ) : ?>
                                <span class="edr-active-dot" title="Raced in the last 30 days"></span>
                            <?php endif; ?>
                        </td>

                        <td class="edr-col-ir">
                            <?php if ( null !== $driver['irating'] && $driver['irating'] > 0 ) : ?>
                                <span class="edr-irating-badge">iR <?php echo intval( $driver['irating'] ); ?></span>
                            <?php else : ?><span class="edr-na">&mdash;</span><?php endif; ?>
                        </td>

                        <td class="edr-col-sr">
                            <?php if ( ! empty( $driver['safety_rating'] ) ) : ?>
                                <?php $lic_tbl = isset( $driver['license_class'] ) ? intval( $driver['license_class'] ) : 0; ?>
                                <span class="edr-sr-lic-badge <?php echo esc_attr( $this->license_css_class( $lic_tbl ) ); ?>">
                                    <span class="edr-sr-val"><?php echo esc_html( $driver['safety_rating'] ); ?></span>
                                    <span class="edr-lic-letter"><?php echo esc_html( $this->license_letter( $lic_tbl ) ); ?></span>
                                </span>
                            <?php else : ?><span class="edr-na">&mdash;</span><?php endif; ?>
                        </td>

                        <?php if ( $o['show_wins'] )   : ?><td class="edr-col-wins"><?php echo number_format( $driver['wins'] ); ?></td><?php endif; ?>
                        <?php if ( $o['show_starts'] ) : ?><td class="edr-col-starts"><?php echo number_format( $driver['starts'] ); ?></td><?php endif; ?>
                        <?php if ( $o['show_top5'] )   : ?><td class="edr-col-top5"><?php echo number_format( $driver['top5'] ); ?></td><?php endif; ?>
                        <?php if ( $o['show_laps'] )   : ?><td class="edr-col-laps"><?php echo number_format( $driver['laps'] ); ?></td><?php endif; ?>

                        <?php if ( $o['show_last_race'] ) : ?>
                        <td class="edr-col-lastrace">
                            <?php if ( ! empty( $driver['last_race'] ) ) : ?>
                            <div class="edr-lastrace-info">
                                <span class="edr-lastrace-pos">P<?php echo esc_html( $driver['last_race']['finish'] ); ?></span>
                                <span class="edr-lastrace-detail">
                                    <?php echo esc_html( $driver['last_race']['track'] ); ?>
                                    <br /><small><?php echo esc_html( $driver['last_race']['series'] ); ?></small>
                                </span>
                            </div>
                            <?php else : ?><span class="edr-na">&mdash;</span><?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private function is_recently_active( $driver ) {
        $date = isset( $driver['last_race_date'] ) ? $driver['last_race_date'] : '';
        if ( empty( $date ) ) { return false; }
        $ts = strtotime( $date );
        return $ts && ( time() - $ts ) < ( 30 * DAY_IN_SECONDS );
    }

    /**
     * Generate an emoji flag from a 2-letter ISO 3166-1 alpha-2 country code.
     * Works on PHP 7.2+ (mb_chr available).
     */
    private function get_flag( $code ) {
        if ( empty( $code ) || strlen( $code ) !== 2 ) { return ''; }
        $code = strtoupper( $code );
        $flag = '';
        foreach ( str_split( $code ) as $char ) {
            $offset = 0x1F1E6 + ( ord( $char ) - ord( 'A' ) );
            if ( function_exists( 'mb_chr' ) ) {
                $flag .= mb_chr( $offset, 'UTF-8' );
            } else {
                $flag .= '&#x' . dechex( $offset ) . ';';
            }
        }
        return $flag;
    }

    private function role_badge( $role ) {
        $labels = array(
            'captain' => 'Team Captain',
            'lead'    => 'Lead Driver',
            'pro'     => 'Pro Driver',
            'silver'  => 'Silver Driver',
            'bronze'  => 'Bronze Driver',
            'reserve' => 'Reserve',
            'academy' => 'Academy',
        );
        if ( empty( $role ) || ! isset( $labels[ $role ] ) ) { return ''; }
        return '<span class="edr-role-badge edr-role-' . esc_attr( $role ) . '">'
             . esc_html( $labels[ $role ] ) . '</span>';
    }

    private function get_gear_items( $profile ) {
        $gear_map = array(
            'wheel'   => 'Wheel',
            'pedals'  => 'Pedals',
            'rig'     => 'Rig',
            'monitor' => 'Display',
            'pc'      => 'PC',
            'other'   => 'Other',
        );
        $items = array();
        foreach ( $gear_map as $key => $label ) {
            if ( ! empty( $profile[ $key ] ) ) {
                $items[ $label ] = wp_unslash( $profile[ $key ] );
            }
        }
        return $items;
    }

    private function license_css_class( $license_class ) {
        $map = array( 1 => 'edr-lic-r', 2 => 'edr-lic-d', 3 => 'edr-lic-c', 4 => 'edr-lic-b', 5 => 'edr-lic-a', 6 => 'edr-lic-pro' );
        return isset( $map[ $license_class ] ) ? $map[ $license_class ] : 'edr-lic-d';
    }

    private function license_letter( $license_class ) {
        $map = array( 1 => 'R', 2 => 'D', 3 => 'C', 4 => 'B', 5 => 'A', 6 => 'Pro' );
        return isset( $map[ $license_class ] ) ? $map[ $license_class ] : '?';
    }

    /* ------------------------------------------------------------------
     * Demo data
     * ------------------------------------------------------------------ */

    private function get_demo_drivers() {
        return array(
            array( 'driver_id' => 1001, 'cust_id' => 1001, 'name' => 'Chris Wilson',      'irating' => 4850, 'irating_prev' => 4720, 'safety_rating' => '4.72', 'license_class' => 5, 'wins' => 12, 'starts' => 87, 'top5' => 34, 'laps' => 1842, 'last_race_date' => date( 'Y-m-d', strtotime( '-5 days' ) ),
                'last_race' => array( 'series' => 'Creventic Endurance Series', 'track' => 'Circuit de Spa-Francorchamps', 'finish' => 1, 'start' => 3, 'incidents' => 2, 'sof' => 4210, 'date' => date( 'Y-m-d', strtotime( '-5 days' ) ) ) ),
            array( 'driver_id' => 1002, 'cust_id' => 1002, 'name' => 'Erik van der Bijl', 'irating' => 4420, 'irating_prev' => 4480, 'safety_rating' => '4.51', 'license_class' => 5, 'wins' => 8,  'starts' => 63, 'top5' => 27, 'laps' => 1390, 'last_race_date' => date( 'Y-m-d', strtotime( '-12 days' ) ),
                'last_race' => array( 'series' => 'Global Endurance Tour', 'track' => 'Watkins Glen International', 'finish' => 2, 'start' => 1, 'incidents' => 0, 'sof' => 3980, 'date' => date( 'Y-m-d', strtotime( '-12 days' ) ) ) ),
            array( 'driver_id' => 1003, 'cust_id' => 1003, 'name' => 'Aden Hartley',      'irating' => 3910, 'irating_prev' => 3910, 'safety_rating' => '3.88', 'license_class' => 4, 'wins' => 4,  'starts' => 51, 'top5' => 19, 'laps' => 1105, 'last_race_date' => date( 'Y-m-d', strtotime( '-45 days' ) ),
                'last_race' => array( 'series' => 'Creventic Endurance Series', 'track' => 'Mount Panorama Circuit', 'finish' => 4, 'start' => 6, 'incidents' => 4, 'sof' => 3750, 'date' => '' ) ),
            array( 'driver_id' => 1004, 'cust_id' => 1004, 'name' => 'Luke Brennan',      'irating' => 3640, 'irating_prev' => 3580, 'safety_rating' => '3.44', 'license_class' => 3, 'wins' => 2,  'starts' => 44, 'top5' => 14, 'laps' => 940, 'last_race_date' => date( 'Y-m-d', strtotime( '-8 days' ) ),
                'last_race' => array( 'series' => 'Global Endurance Tour', 'track' => 'Nurburgring Nordschleife', 'finish' => 7, 'start' => 5, 'incidents' => 6, 'sof' => 3420, 'date' => date( 'Y-m-d', strtotime( '-8 days' ) ) ) ),
            array( 'driver_id' => 1005, 'cust_id' => 1005, 'name' => 'Sarah Kowalski',    'irating' => 3280, 'irating_prev' => null, 'safety_rating' => '4.10', 'license_class' => 5, 'wins' => 1,  'starts' => 29, 'top5' => 9,  'laps' => 620, 'last_race_date' => date( 'Y-m-d', strtotime( '-20 days' ) ),
                'last_race' => array( 'series' => 'iRacing Endurance Series', 'track' => 'Suzuka International Racing Course', 'finish' => 3, 'start' => 4, 'incidents' => 1, 'sof' => 2990, 'date' => '' ) ),
            array( 'driver_id' => 1006, 'cust_id' => 1006, 'name' => 'Marco Deluca',      'irating' => null, 'irating_prev' => null, 'safety_rating' => '2.85', 'license_class' => 2, 'wins' => 0,  'starts' => 18, 'top5' => 5,  'laps' => 388, 'last_race_date' => '',
                'last_race' => null ),
        );
    }

    private function get_demo_profiles() {
        return array(
            1001 => array(
                'photo_url' => '', 'role' => 'captain', 'number' => '18', 'featured' => '1', 'flag_code' => 'AU',
                'nationality' => 'Australia', 'tagline' => 'Spa specialist — 3x podium winner',
                'sort_order' => 1, 'wheel' => 'Simucube 2 Pro', 'pedals' => 'Heusinkveld Sprint',
                'rig' => 'Trak Racer TR160', 'monitor' => 'Samsung 49" Odyssey G9',
                'pc' => 'RTX 4080, i9-13900K, 64GB', 'other' => '',
            ),
            1002 => array(
                'photo_url' => '', 'role' => 'lead', 'number' => '7', 'featured' => '', 'flag_code' => 'ZA',
                'nationality' => 'South Africa', 'tagline' => 'Endurance specialist',
                'sort_order' => 2, 'wheel' => 'Fanatec DD2', 'pedals' => 'Fanatec Clubsport V3',
                'rig' => 'Next Level Racing F-GT Elite', 'monitor' => 'LG 34" Ultrawide',
                'pc' => '', 'other' => '',
            ),
            1003 => array(
                'photo_url' => '', 'role' => 'pro', 'number' => '33', 'featured' => '', 'flag_code' => 'AU',
                'nationality' => 'Australia', 'tagline' => '',
                'sort_order' => 3, 'wheel' => '', 'pedals' => '', 'rig' => '',
                'monitor' => '', 'pc' => '', 'other' => '',
            ),
            1004 => array(
                'photo_url' => '', 'role' => 'silver', 'number' => '44', 'featured' => '', 'flag_code' => 'NZ',
                'nationality' => 'New Zealand', 'tagline' => '',
                'sort_order' => 4, 'wheel' => '', 'pedals' => '', 'rig' => '',
                'monitor' => '', 'pc' => '', 'other' => '',
            ),
            1005 => array(
                'photo_url' => '', 'role' => 'bronze', 'number' => '55', 'featured' => '', 'flag_code' => 'GB',
                'nationality' => 'United Kingdom', 'tagline' => 'Clean racer — zero incident record',
                'sort_order' => 5, 'wheel' => '', 'pedals' => '', 'rig' => '',
                'monitor' => '', 'pc' => '', 'other' => '',
            ),
            1006 => array(
                'photo_url' => '', 'role' => 'academy', 'number' => '99', 'featured' => '', 'flag_code' => 'IT',
                'nationality' => 'Italy', 'tagline' => '',
                'sort_order' => 6, 'wheel' => '', 'pedals' => '', 'rig' => '',
                'monitor' => '', 'pc' => '', 'other' => '',
            ),
        );
    }
}
