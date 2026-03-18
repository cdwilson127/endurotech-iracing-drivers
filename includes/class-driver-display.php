<?php
/**
 * Frontend rendering — [iracing_drivers] shortcode.
 *
 * Primary data source: admin-managed profiles (edr_driver_profiles).
 * Secondary data source: iRacing API cache (overrides stats when linked).
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
        }
    }

    /* ------------------------------------------------------------------
     * Shortcode entry point
     * ------------------------------------------------------------------ */

    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'title'          => 'Our Drivers',
            'demo'           => 'no',
            'layout'         => 'cards',
            'columns'        => 'auto',
            'card_style'     => 'default',
            'accent'         => 'red',
            'sort_by'        => 'irating',
            'sort_order'     => 'desc',
            'show_summary'   => 'yes',
            'show_last_race' => 'yes',
            'show_photo'     => 'yes',
            'show_role'      => 'yes',
            'show_number'    => 'yes',
            'show_gear'      => 'yes',
            'show_wins'      => 'yes',
            'show_starts'    => 'yes',
            'show_top5'      => 'yes',
            'show_laps'      => 'yes',
        ), $atts, 'iracing_drivers' );

        $o = $this->normalise_options( $atts );

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

        $wrap_classes = array( 'edr-drivers-wrap' );
        if ( 'red' !== $o['accent'] ) { $wrap_classes[] = 'edr-accent-' . $o['accent']; }

        ob_start();
        ?>
        <div class="<?php echo esc_attr( implode( ' ', $wrap_classes ) ); ?>">

            <?php if ( $o['demo'] ) : ?>
            <div class="edr-demo-banner">
                Preview mode &mdash; sample data only. Remove <code>demo="yes"</code> once your drivers are configured.
            </div>
            <?php endif; ?>

            <?php $this->render_header( $o['title'], $drivers ); ?>

            <?php if ( $o['show_summary'] ) : ?>
                <?php $this->render_summary( $drivers ); ?>
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
        if ( ! is_array( $api_cache ) ) { $api_cache = array(); }

        $api_lookup = array();
        foreach ( $api_cache as $ad ) {
            $api_lookup[ intval( $ad['cust_id'] ) ] = $ad;
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

            $irating       = $manual_ir !== '' ? intval( $manual_ir ) : 0;
            $safety_rating = $manual_sr !== '' ? $manual_sr           : '';
            $wins          = $manual_w  !== '' ? intval( $manual_w )  : 0;
            $starts        = $manual_s  !== '' ? intval( $manual_s )  : 0;
            $top5          = $manual_t5 !== '' ? intval( $manual_t5 ) : 0;
            $laps          = $manual_l  !== '' ? intval( $manual_l )  : 0;
            $last_race     = null;

            if ( $cust_id && is_numeric( $cust_id ) && isset( $api_lookup[ intval( $cust_id ) ] ) ) {
                $api = $api_lookup[ intval( $cust_id ) ];

                if ( $manual_ir === '' && isset( $api['irating'] ) )       { $irating       = $api['irating']; }
                if ( $manual_sr === '' && isset( $api['safety_rating'] ) ) { $safety_rating  = $api['safety_rating']; }
                if ( $manual_w  === '' && isset( $api['wins'] ) )          { $wins           = intval( $api['wins'] ); }
                if ( $manual_s  === '' && isset( $api['starts'] ) )        { $starts         = intval( $api['starts'] ); }
                if ( $manual_t5 === '' && isset( $api['top5'] ) )          { $top5           = intval( $api['top5'] ); }
                if ( $manual_l  === '' && isset( $api['laps'] ) )          { $laps           = intval( $api['laps'] ); }

                if ( ! empty( $api['last_race'] ) ) {
                    $last_race = $api['last_race'];
                }

                if ( $name === 'Unknown' && ! empty( $api['name'] ) ) {
                    $name = $api['name'];
                }

                unset( $api );
            }

            $drivers[] = array(
                'driver_id'     => $driver_id,
                'cust_id'       => $cust_id ? $cust_id : $driver_id,
                'name'          => $name,
                'irating'       => $irating,
                'safety_rating' => $safety_rating,
                'wins'          => $wins,
                'starts'        => $starts,
                'top5'          => $top5,
                'laps'          => $laps,
                'last_race'     => $last_race,
            );
        }
        unset( $api_lookup );

        return array( 'drivers' => $drivers, 'profiles' => $profiles );
    }

    /* ------------------------------------------------------------------
     * Options normalisation
     * ------------------------------------------------------------------ */

    private function normalise_options( $atts ) {
        $bool_keys = array(
            'demo', 'show_summary', 'show_last_race', 'show_photo',
            'show_role', 'show_number', 'show_gear',
            'show_wins', 'show_starts', 'show_top5', 'show_laps',
        );

        $o = array();
        foreach ( $atts as $k => $v ) {
            if ( in_array( $k, $bool_keys, true ) ) {
                $o[ $k ] = ( 'yes' === strtolower( (string) $v ) );
            } else {
                $o[ $k ] = strtolower( (string) $v );
            }
        }

        $o['title']      = $atts['title'];
        $o['layout']     = in_array( $o['layout'], array( 'cards', 'table' ), true ) ? $o['layout'] : 'cards';
        $o['columns']    = in_array( $o['columns'], array( 'auto', '1', '2', '3', '4' ), true ) ? $o['columns'] : 'auto';
        $o['card_style'] = in_array( $o['card_style'], array( 'default', 'minimal' ), true ) ? $o['card_style'] : 'default';
        $o['accent']     = in_array( $o['accent'], array( 'red', 'blue', 'green', 'gold' ), true ) ? $o['accent'] : 'red';
        $o['sort_by']    = in_array( $o['sort_by'], array( 'irating', 'wins', 'starts', 'name', 'custom' ), true ) ? $o['sort_by'] : 'irating';
        $o['sort_order'] = ( 'asc' === $o['sort_order'] ) ? 'asc' : 'desc';

        return $o;
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
                    $result = intval( isset( $a['irating'] ) ? $a['irating'] : 0 )
                            - intval( isset( $b['irating'] ) ? $b['irating'] : 0 );
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

    private function render_header( $title, $drivers ) {
        ?>
        <div class="edr-drivers-header">
            <h2><?php echo esc_html( $title ); ?></h2>
            <p class="edr-drivers-subtitle">
                <?php echo count( $drivers ); ?> drivers &middot; Live stats from iRacing
            </p>
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
            if ( ! empty( $d['irating'] ) ) { $avg_ir += $d['irating']; $ir_n++; }
        }
        $avg_ir = $ir_n ? round( $avg_ir / $ir_n ) : 0;
        ?>
        <div class="edr-drivers-stats-bar">
            <div class="edr-stat-card"><span class="edr-stat-value"><?php echo number_format( count( $drivers ) ); ?></span><span class="edr-stat-label">Drivers</span></div>
            <div class="edr-stat-card"><span class="edr-stat-value"><?php echo number_format( $avg_ir ); ?></span><span class="edr-stat-label">Avg iRating</span></div>
            <div class="edr-stat-card"><span class="edr-stat-value"><?php echo number_format( $total_wins ); ?></span><span class="edr-stat-label">Total Wins</span></div>
            <div class="edr-stat-card"><span class="edr-stat-value"><?php echo number_format( $total_starts ); ?></span><span class="edr-stat-label">Total Starts</span></div>
            <div class="edr-stat-card"><span class="edr-stat-value"><?php echo number_format( $total_laps ); ?></span><span class="edr-stat-label">Total Laps</span></div>
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
                $photo     = isset( $profile['photo_url'] ) ? $profile['photo_url'] : '';
                $role      = isset( $profile['role'] )      ? $profile['role']      : '';
                $number    = isset( $profile['number'] )    ? $profile['number']    : '';
                $nat       = isset( $profile['nationality'] ) ? $profile['nationality'] : '';
                $tagline   = isset( $profile['tagline'] )   ? $profile['tagline']   : '';
                $gear      = $this->get_gear_items( $profile );

                $has_photo = $o['show_photo'] && ! empty( $photo );
                $has_role  = $o['show_role']  && ! empty( $role );
                $has_num   = $o['show_number'] && ! empty( $number );
                $has_gear  = $o['show_gear']  && ! empty( $gear );

                $card_class = 'edr-driver-card';
                if ( $has_photo ) { $card_class .= ' edr-card-has-photo'; }
                if ( $has_gear )  { $card_class .= ' edr-card-has-gear'; }
            ?>
            <div class="<?php echo esc_attr( $card_class ); ?>">

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
                        </div>

                        <h3 class="edr-card-name"><?php echo esc_html( $driver['name'] ); ?></h3>

                        <?php if ( ! empty( $nat ) ) : ?>
                            <span class="edr-driver-nat"><?php echo esc_html( $nat ); ?></span>
                        <?php endif; ?>

                        <?php if ( ! empty( $tagline ) ) : ?>
                            <p class="edr-driver-tagline"><?php echo esc_html( $tagline ); ?></p>
                        <?php endif; ?>

                        <div class="edr-card-badges">
                            <?php if ( ! empty( $driver['irating'] ) ) : ?>
                                <span class="edr-irating-badge"><?php echo number_format( $driver['irating'] ); ?> iR</span>
                            <?php endif; ?>
                            <?php if ( ! empty( $driver['safety_rating'] ) ) : ?>
                                <span class="edr-sr-badge <?php echo esc_attr( $this->sr_class( floatval( $driver['safety_rating'] ) ) ); ?>">
                                    <?php echo esc_html( $driver['safety_rating'] ); ?> SR
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
                        <span class="edr-card-stat-val"><?php echo number_format( $driver['wins'] ); ?></span>
                        <span class="edr-card-stat-lbl">Wins</span>
                    </div>
                    <?php endif; ?>
                    <?php if ( $o['show_starts'] ) : ?>
                    <div class="edr-card-stat">
                        <span class="edr-card-stat-val"><?php echo number_format( $driver['starts'] ); ?></span>
                        <span class="edr-card-stat-lbl">Starts</span>
                    </div>
                    <?php endif; ?>
                    <?php if ( $o['show_top5'] ) : ?>
                    <div class="edr-card-stat">
                        <span class="edr-card-stat-val"><?php echo number_format( $driver['top5'] ); ?></span>
                        <span class="edr-card-stat-lbl">Top 5s</span>
                    </div>
                    <?php endif; ?>
                    <?php if ( $o['show_laps'] ) : ?>
                    <div class="edr-card-stat">
                        <span class="edr-card-stat-val"><?php echo number_format( $driver['laps'] ); ?></span>
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

                <?php if ( $has_gear && 'minimal' !== $o['card_style'] ) : ?>
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

            </div>
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
                        <th class="edr-col-sr">Safety Rating</th>
                        <?php if ( $o['show_wins'] )   : ?><th class="edr-col-wins">Wins</th><?php endif; ?>
                        <?php if ( $o['show_starts'] ) : ?><th class="edr-col-starts">Starts</th><?php endif; ?>
                        <?php if ( $o['show_top5'] )   : ?><th class="edr-col-top5">Top 5s</th><?php endif; ?>
                        <?php if ( $o['show_laps'] )   : ?><th class="edr-col-laps">Laps</th><?php endif; ?>
                        <?php if ( $o['show_last_race'] ) : ?><th class="edr-col-lastrace">Last Race</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $drivers as $i => $driver ) :
                        $did     = isset( $driver['driver_id'] ) ? $driver['driver_id'] : $driver['cust_id'];
                        $profile = isset( $profiles[ $did ] ) ? $profiles[ $did ] : array();
                        $role    = isset( $profile['role'] )   ? $profile['role']   : '';
                        $number  = isset( $profile['number'] ) ? $profile['number'] : '';
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
                            <?php $nat = isset( $profile['nationality'] ) ? $profile['nationality'] : ''; ?>
                            <?php if ( ! empty( $nat ) ) : ?>
                                <span class="edr-driver-nat-inline"><?php echo esc_html( $nat ); ?></span>
                            <?php endif; ?>
                        </td>

                        <td class="edr-col-ir">
                            <?php if ( ! empty( $driver['irating'] ) ) : ?>
                                <span class="edr-irating-badge"><?php echo number_format( $driver['irating'] ); ?></span>
                            <?php else : ?><span class="edr-na">&mdash;</span><?php endif; ?>
                        </td>

                        <td class="edr-col-sr">
                            <?php if ( ! empty( $driver['safety_rating'] ) ) : ?>
                                <span class="edr-sr-badge <?php echo esc_attr( $this->sr_class( floatval( $driver['safety_rating'] ) ) ); ?>">
                                    <?php echo esc_html( $driver['safety_rating'] ); ?>
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
                $items[ $label ] = $profile[ $key ];
            }
        }
        return $items;
    }

    private function sr_class( $sr ) {
        if ( $sr >= 4.00 ) { return 'edr-sr-a'; }
        if ( $sr >= 3.00 ) { return 'edr-sr-b'; }
        if ( $sr >= 2.00 ) { return 'edr-sr-c'; }
        return 'edr-sr-d';
    }

    /* ------------------------------------------------------------------
     * Demo data
     * ------------------------------------------------------------------ */

    private function get_demo_drivers() {
        return array(
            array( 'driver_id' => 1001, 'cust_id' => 1001, 'name' => 'Chris Wilson',      'irating' => 4850, 'safety_rating' => '4.72', 'wins' => 12, 'starts' => 87, 'top5' => 34, 'laps' => 1842,
                'last_race' => array( 'series' => 'Creventic Endurance Series', 'track' => 'Circuit de Spa-Francorchamps', 'finish' => 1, 'start' => 3, 'incidents' => 2, 'sof' => 4210, 'date' => '' ) ),
            array( 'driver_id' => 1002, 'cust_id' => 1002, 'name' => 'Erik van der Bijl', 'irating' => 4420, 'safety_rating' => '4.51', 'wins' => 8,  'starts' => 63, 'top5' => 27, 'laps' => 1390,
                'last_race' => array( 'series' => 'Global Endurance Tour', 'track' => 'Watkins Glen International', 'finish' => 2, 'start' => 1, 'incidents' => 0, 'sof' => 3980, 'date' => '' ) ),
            array( 'driver_id' => 1003, 'cust_id' => 1003, 'name' => 'Aden Hartley',      'irating' => 3910, 'safety_rating' => '3.88', 'wins' => 4,  'starts' => 51, 'top5' => 19, 'laps' => 1105,
                'last_race' => array( 'series' => 'Creventic Endurance Series', 'track' => 'Mount Panorama Circuit', 'finish' => 4, 'start' => 6, 'incidents' => 4, 'sof' => 3750, 'date' => '' ) ),
            array( 'driver_id' => 1004, 'cust_id' => 1004, 'name' => 'Luke Brennan',      'irating' => 3640, 'safety_rating' => '3.44', 'wins' => 2,  'starts' => 44, 'top5' => 14, 'laps' => 940,
                'last_race' => array( 'series' => 'Global Endurance Tour', 'track' => 'Nurburgring Nordschleife', 'finish' => 7, 'start' => 5, 'incidents' => 6, 'sof' => 3420, 'date' => '' ) ),
            array( 'driver_id' => 1005, 'cust_id' => 1005, 'name' => 'Sarah Kowalski',    'irating' => 3280, 'safety_rating' => '4.10', 'wins' => 1,  'starts' => 29, 'top5' => 9,  'laps' => 620,
                'last_race' => array( 'series' => 'iRacing Endurance Series', 'track' => 'Suzuka International Racing Course', 'finish' => 3, 'start' => 4, 'incidents' => 1, 'sof' => 2990, 'date' => '' ) ),
            array( 'driver_id' => 1006, 'cust_id' => 1006, 'name' => 'Marco Deluca',      'irating' => 2970, 'safety_rating' => '2.85', 'wins' => 0,  'starts' => 18, 'top5' => 5,  'laps' => 388,
                'last_race' => array( 'series' => 'Global Endurance Tour', 'track' => 'Daytona International Speedway', 'finish' => 11, 'start' => 9, 'incidents' => 8, 'sof' => 2710, 'date' => '' ) ),
        );
    }

    private function get_demo_profiles() {
        return array(
            1001 => array(
                'photo_url' => '', 'role' => 'captain', 'number' => '18',
                'nationality' => 'Australia', 'tagline' => 'Spa specialist - 3x podium winner',
                'sort_order' => 1, 'wheel' => 'Simucube 2 Pro', 'pedals' => 'Heusinkveld Sprint',
                'rig' => 'Trak Racer TR160', 'monitor' => 'Samsung 49" Odyssey G9',
                'pc' => 'RTX 4080, i9-13900K, 64GB', 'other' => '',
            ),
            1002 => array(
                'photo_url' => '', 'role' => 'lead', 'number' => '7',
                'nationality' => 'South Africa', 'tagline' => 'Endurance specialist',
                'sort_order' => 2, 'wheel' => 'Fanatec DD2', 'pedals' => 'Fanatec Clubsport V3',
                'rig' => 'Next Level Racing F-GT Elite', 'monitor' => 'LG 34" Ultrawide',
                'pc' => '', 'other' => '',
            ),
            1003 => array(
                'photo_url' => '', 'role' => 'pro', 'number' => '33',
                'nationality' => 'Australia', 'tagline' => '',
                'sort_order' => 3, 'wheel' => '', 'pedals' => '', 'rig' => '',
                'monitor' => '', 'pc' => '', 'other' => '',
            ),
            1004 => array(
                'photo_url' => '', 'role' => 'silver', 'number' => '44',
                'nationality' => 'New Zealand', 'tagline' => '',
                'sort_order' => 4, 'wheel' => '', 'pedals' => '', 'rig' => '',
                'monitor' => '', 'pc' => '', 'other' => '',
            ),
            1005 => array(
                'photo_url' => '', 'role' => 'bronze', 'number' => '55',
                'nationality' => 'United Kingdom', 'tagline' => 'Clean racer - zero incident record',
                'sort_order' => 5, 'wheel' => '', 'pedals' => '', 'rig' => '',
                'monitor' => '', 'pc' => '', 'other' => '',
            ),
            1006 => array(
                'photo_url' => '', 'role' => 'academy', 'number' => '99',
                'nationality' => 'Italy', 'tagline' => '',
                'sort_order' => 6, 'wheel' => '', 'pedals' => '', 'rig' => '',
                'monitor' => '', 'pc' => '', 'other' => '',
            ),
        );
    }
}
