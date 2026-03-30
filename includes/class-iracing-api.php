<?php
/**
 * iRacing Data API client using OAuth2 password_limited flow.
 *
 * Authentication and data-fetching pattern adapted from
 * https://github.com/dbousamra/iracing-bot (iracing-client.ts).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EDR_IRacing_API {

    private static $OAUTH_URL    = 'https://oauth.iracing.com/oauth2/token';
    private static $DATA_API_URL = 'https://members-ng.iracing.com/data';

    private $client_id     = '';
    private $client_secret = '';
    private $username      = '';
    private $password      = '';
    private $last_error    = '';

    public function __construct() {
        $settings = get_option( 'edr_iracing_settings', array() );

        $this->client_id     = isset( $settings['client_id'] )     ? $settings['client_id']     : '';
        $this->client_secret = isset( $settings['client_secret'] ) ? $settings['client_secret'] : '';
        $this->username      = isset( $settings['username'] )      ? $settings['username']      : '';
        $this->password      = isset( $settings['password'] )      ? $settings['password']      : '';
    }

    public function is_configured() {
        return $this->client_id && $this->client_secret
            && $this->username && $this->password;
    }

    /**
     * Return the last error message for display in admin notices.
     */
    public function get_last_error() {
        return $this->last_error;
    }

    private function hash_value( $value, $salt ) {
        return base64_encode( hash( 'sha256', $value . strtolower( $salt ), true ) );
    }

    /**
     * Obtain an OAuth2 access token.
     * Retries up to 3 times with a 2-second delay to handle the intermittent
     * 405 responses from the iRacing OAuth endpoint.
     */
    private function get_access_token() {
        $cached = get_transient( 'edr_iracing_token' );
        if ( $cached ) {
            return $cached;
        }

        $hashed_password = $this->hash_value( $this->password, $this->username );
        $hashed_secret   = $this->hash_value( $this->client_secret, $this->client_id );

        $max_retries = 3;

        for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
            $response = wp_remote_post( self::$OAUTH_URL, array(
                'timeout' => 30,
                'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
                'body'    => http_build_query( array(
                    'grant_type'    => 'password_limited',
                    'client_id'     => $this->client_id,
                    'client_secret' => $hashed_secret,
                    'username'      => $this->username,
                    'password'      => $hashed_password,
                    'scope'         => 'iracing.auth',
                ) ),
            ) );

            if ( is_wp_error( $response ) ) {
                $this->last_error = 'OAuth connection error (attempt ' . $attempt . '/' . $max_retries . '): ' . $response->get_error_message();
                error_log( 'EDR iRacing: ' . $this->last_error );
                if ( $attempt < $max_retries ) { sleep( 2 ); }
                continue;
            }

            $status   = intval( wp_remote_retrieve_response_code( $response ) );
            $raw_body = wp_remote_retrieve_body( $response );

            // Retry on 405 or 5xx — these are intermittent issues from iRacing.
            if ( 405 === $status || $status >= 500 ) {
                $this->last_error = 'OAuth HTTP ' . $status . ' (attempt ' . $attempt . '/' . $max_retries . '): ' . $raw_body;
                error_log( 'EDR iRacing: ' . $this->last_error );
                if ( $attempt < $max_retries ) { sleep( 2 ); }
                continue;
            }

            if ( $status < 200 || $status >= 300 ) {
                $this->last_error = 'OAuth HTTP ' . $status . ': ' . $raw_body;
                error_log( 'EDR iRacing: ' . $this->last_error );
                return null;
            }

            $body = json_decode( $raw_body, true );
            unset( $response, $raw_body );

            if ( empty( $body['access_token'] ) ) {
                $this->last_error = 'OAuth response missing access_token: ' . wp_json_encode( $body );
                error_log( 'EDR iRacing: ' . $this->last_error );
                return null;
            }

            $expires = max( ( intval( isset( $body['expires_in'] ) ? $body['expires_in'] : 3600 ) ) - 120, 60 );
            set_transient( 'edr_iracing_token', $body['access_token'], $expires );

            $token = $body['access_token'];
            unset( $body );

            return $token;
        }

        // All retries exhausted.
        return null;
    }

    /**
     * iRacing returns a JSON envelope with a `link` to a pre-signed S3 URL
     * where the real payload lives. Some endpoints use chunk_info instead.
     */
    private function api_request( $endpoint, $query = array() ) {
        $token = $this->get_access_token();
        if ( ! $token ) {
            return null;
        }

        $url = self::$DATA_API_URL . $endpoint;
        if ( $query ) {
            $url .= '?' . http_build_query( $query );
        }

        $response = wp_remote_get( $url, array(
            'timeout' => 30,
            'headers' => array( 'Authorization' => 'Bearer ' . $token ),
        ) );

        if ( is_wp_error( $response ) ) {
            $this->last_error = 'API error on ' . $endpoint . ': ' . $response->get_error_message();
            error_log( 'EDR iRacing: ' . $this->last_error );
            return null;
        }

        $status_code = intval( wp_remote_retrieve_response_code( $response ) );

        // If we get a 401, token may have expired — clear it and retry once.
        if ( 401 === $status_code ) {
            delete_transient( 'edr_iracing_token' );
            $token = $this->get_access_token();
            if ( ! $token ) {
                return null;
            }

            $response = wp_remote_get( $url, array(
                'timeout' => 30,
                'headers' => array( 'Authorization' => 'Bearer ' . $token ),
            ) );

            if ( is_wp_error( $response ) ) {
                return null;
            }
            $status_code = intval( wp_remote_retrieve_response_code( $response ) );
        }

        if ( $status_code < 200 || $status_code >= 300 ) {
            $this->last_error = 'API HTTP ' . $status_code . ' on ' . $endpoint . ': ' . wp_remote_retrieve_body( $response );
            error_log( 'EDR iRacing: ' . $this->last_error );
            return null;
        }

        $raw_body = wp_remote_retrieve_body( $response );
        unset( $response );

        $body = json_decode( $raw_body, true );
        unset( $raw_body );

        if ( ! empty( $body['link'] ) ) {
            $link = $body['link'];
            unset( $body );

            $data_response = wp_remote_get( $link, array( 'timeout' => 30 ) );
            if ( is_wp_error( $data_response ) ) {
                return null;
            }

            $data_body = wp_remote_retrieve_body( $data_response );
            unset( $data_response );

            $result = json_decode( $data_body, true );
            unset( $data_body );

            return $result;
        }

        if ( ! empty( $body['data']['chunk_info'] )
             && isset( $body['data']['chunk_info']['base_download_url'] )
             && isset( $body['data']['chunk_info']['chunk_file_names'] )
             && is_array( $body['data']['chunk_info']['chunk_file_names'] ) ) {
            $base_url    = $body['data']['chunk_info']['base_download_url'];
            $chunk_names = $body['data']['chunk_info']['chunk_file_names'];
            unset( $body );

            $all_data = array();
            foreach ( $chunk_names as $chunk_name ) {
                $chunk_resp = wp_remote_get( $base_url . $chunk_name, array( 'timeout' => 30 ) );
                if ( ! is_wp_error( $chunk_resp ) ) {
                    $chunk_body = wp_remote_retrieve_body( $chunk_resp );
                    unset( $chunk_resp );

                    $chunk_data = json_decode( $chunk_body, true );
                    unset( $chunk_body );

                    if ( is_array( $chunk_data ) ) {
                        $all_data = array_merge( $all_data, $chunk_data );
                    }
                    unset( $chunk_data );
                }
            }
            return $all_data;
        }

        return $body;
    }

    public function get_team( $team_id ) {
        return $this->api_request( '/team/get', array( 'team_id' => intval( $team_id ) ) );
    }

    public function get_member_career_stats( $cust_id ) {
        return $this->api_request( '/stats/member_career', array( 'cust_id' => intval( $cust_id ) ) );
    }

    public function get_member_recent_races( $cust_id ) {
        return $this->api_request( '/stats/member_recent_races', array( 'cust_id' => intval( $cust_id ) ) );
    }

    /**
     * Fetch iRating or Safety Rating history for a specific category.
     * chart_type: 1 = iRating, 2 = TT Rating, 3 = Safety Rating.
     * category_id: 1 = Oval, 2 = Road, 3 = Dirt Oval, 4 = Dirt Road, 5 = Sports Car.
     */
    public function get_member_chart_data( $cust_id, $category_id, $chart_type ) {
        return $this->api_request( '/member/chart_data', array(
            'cust_id'     => intval( $cust_id ),
            'category_id' => intval( $category_id ),
            'chart_type'  => intval( $chart_type ),
        ) );
    }

    /**
     * Kept for the diagnostic tool on the admin page.
     */
    public function get_member_info( $cust_ids ) {
        if ( is_array( $cust_ids ) ) {
            $cust_ids = implode( ',', array_map( 'intval', $cust_ids ) );
        }
        return $this->api_request( '/member/get', array( 'cust_ids' => $cust_ids ) );
    }

    /**
     * Fetch everything needed for the drivers page.
     * Results are cached as a transient.
     */
    public function get_all_driver_data() {
        $settings = get_option( 'edr_iracing_settings', array() );
        $team_id  = intval( isset( $settings['team_id'] ) ? $settings['team_id'] : 0 );
        if ( ! $team_id ) {
            $this->last_error = 'No Team ID configured.';
            return null;
        }

        $cache_hours = max( 1, intval( isset( $settings['cache_hours'] ) ? $settings['cache_hours'] : 1 ) );
        $cached      = get_transient( 'edr_iracing_drivers_cache' );
        if ( false !== $cached && is_array( $cached ) && ! empty( $cached ) ) {
            return $cached;
        }

        $team = $this->get_team( $team_id );
        if ( ! $team || empty( $team['roster'] ) ) {
            if ( ! $this->last_error ) {
                $this->last_error = 'Could not fetch team roster. Response: ' . wp_json_encode( $team );
            }
            return null;
        }

        $roster = $team['roster'];
        unset( $team );

        @set_time_limit( 300 );

        $drivers     = array();
        $batch_size  = 5;
        $batch_pause = 2;
        $count       = 0;

        foreach ( $roster as $member ) {
            $cust_id = intval( $member['cust_id'] );
            if ( ! $cust_id ) {
                continue;
            }
            $name = isset( $member['display_name'] ) ? $member['display_name'] : 'Unknown';

            $count++;
            if ( $count > 1 && 0 === ( $count - 1 ) % $batch_size ) {
                sleep( $batch_pause );
            }

            try {
                $driver_data = $this->fetch_single_driver_data( $cust_id, $name );
                if ( $driver_data ) {
                    $drivers[] = $driver_data;
                }
            } catch ( \Exception $e ) {
                error_log( 'EDR iRacing: Exception fetching ' . $name . ' (cust_id ' . $cust_id . '): ' . $e->getMessage() );
            }
        }
        unset( $roster );

        usort( $drivers, array( $this, 'sort_by_irating_desc' ) );

        set_transient( 'edr_iracing_drivers_cache', $drivers, $cache_hours * HOUR_IN_SECONDS );
        update_option( 'edr_iracing_api_snapshot', $drivers, false );

        return $drivers;
    }

    /**
     * Fetch all stat data for a single driver.
     */
    private function fetch_single_driver_data( $cust_id, $name ) {
        $career     = $this->get_member_career_stats( $cust_id );
        $road_stats = $this->extract_category_stats( $career, 'road' );
        unset( $career );

        $irating      = null;
        $irating_prev = null;
        $ir_chart  = $this->get_member_chart_data( $cust_id, 5, 1 );
        $ir_points = $this->extract_chart_points( $ir_chart );
        unset( $ir_chart );

        if ( ! empty( $ir_points ) ) {
            $last_point = end( $ir_points );
            $ir_val     = isset( $last_point['value'] ) ? intval( $last_point['value'] ) : 0;
            $irating    = ( $ir_val > 0 ) ? $ir_val : null;

            if ( count( $ir_points ) >= 2 ) {
                $prev_point   = $ir_points[ count( $ir_points ) - 2 ];
                $prev_val     = isset( $prev_point['value'] ) ? intval( $prev_point['value'] ) : 0;
                $irating_prev = ( $prev_val > 0 ) ? $prev_val : null;
            }
        }

        $safety_rating = null;
        $license_class = null;
        $sr_chart  = $this->get_member_chart_data( $cust_id, 5, 3 );
        $sr_points = $this->extract_chart_points( $sr_chart );
        unset( $sr_chart );

        if ( ! empty( $sr_points ) ) {
            $last_sr = end( $sr_points );
            $raw_sr  = isset( $last_sr['value'] ) ? intval( $last_sr['value'] ) : 0;
            if ( $raw_sr > 0 ) {
                $license_class = intval( floor( $raw_sr / 1000 ) );
                $sub_level     = $raw_sr % 1000;
                $safety_rating = number_format( $sub_level / 100, 2 );
            }
        }

        $last_race = null;
        $recent    = $this->get_member_recent_races( $cust_id );

        if ( ! empty( $recent['races'] ) ) {
            usort( $recent['races'], array( $this, 'sort_races_desc' ) );

            $any_race   = $recent['races'][0];
            $finish_raw = isset( $any_race['finish_position'] ) ? $any_race['finish_position'] : null;
            $start_raw  = isset( $any_race['start_position'] )  ? $any_race['start_position']  : null;
            $last_race  = array(
                'series'    => isset( $any_race['series_name'] )         ? $any_race['series_name']         : '',
                'track'     => isset( $any_race['track']['track_name'] ) ? $any_race['track']['track_name'] : '',
                'finish'    => is_numeric( $finish_raw ) ? intval( $finish_raw ) : '-',
                'start'     => is_numeric( $start_raw )  ? intval( $start_raw )  : '-',
                'incidents' => isset( $any_race['incidents'] )           ? $any_race['incidents']           : 0,
                'sof'       => isset( $any_race['strength_of_field'] )   ? $any_race['strength_of_field']   : 0,
                'date'      => isset( $any_race['session_start_time'] )  ? $any_race['session_start_time']  : '',
            );
            unset( $any_race );
        }
        unset( $recent );

        if ( null === $irating ) {
            error_log( 'EDR iRacing: No Sports Car iRating chart data for ' . $name . ' (cust_id ' . $cust_id . ').' );
        }

        return array(
            'cust_id'        => $cust_id,
            'name'           => $name,
            'irating'        => $irating,
            'irating_prev'   => $irating_prev,
            'safety_rating'  => $safety_rating,
            'license_class'  => $license_class,
            'wins'           => $road_stats['wins'],
            'starts'         => $road_stats['starts'],
            'top5'           => $road_stats['top5'],
            'laps'           => $road_stats['laps'],
            'last_race'      => $last_race,
            'last_race_date' => $last_race ? $last_race['date'] : '',
        );
    }

    private function extract_chart_points( $chart ) {
        if ( ! is_array( $chart ) ) {
            return array();
        }

        if ( isset( $chart['data'] ) && is_array( $chart['data'] ) ) {
            return $chart['data'];
        }

        if ( isset( $chart['chart_data'] ) && is_array( $chart['chart_data'] ) ) {
            return $chart['chart_data'];
        }

        $first = reset( $chart );
        if ( is_array( $first ) && ( isset( $first['value'] ) || isset( $first['when'] ) ) ) {
            return $chart;
        }

        return array();
    }

    public function sort_races_desc( $a, $b ) {
        $time_a = isset( $a['session_start_time'] ) ? strtotime( $a['session_start_time'] ) : 0;
        $time_b = isset( $b['session_start_time'] ) ? strtotime( $b['session_start_time'] ) : 0;
        return $time_b - $time_a;
    }

    public function sort_by_irating_desc( $a, $b ) {
        $a_ir = ( isset( $a['irating'] ) && null !== $a['irating'] ) ? intval( $a['irating'] ) : -1;
        $b_ir = ( isset( $b['irating'] ) && null !== $b['irating'] ) ? intval( $b['irating'] ) : -1;
        return $b_ir - $a_ir;
    }

    private function extract_category_stats( $career, $type ) {
        $defaults = array( 'wins' => 0, 'starts' => 0, 'top5' => 0, 'laps' => 0 );

        if ( empty( $career['stats'] ) ) {
            return $defaults;
        }

        $target_ids = ( 'road' === $type ) ? array( 2, 5 ) : array( 1 );

        $combined = $defaults;
        foreach ( $career['stats'] as $stat ) {
            $cat_id = intval( isset( $stat['category_id'] ) ? $stat['category_id'] : 0 );
            if ( in_array( $cat_id, $target_ids, true ) ) {
                $combined['wins']   += intval( isset( $stat['wins'] )   ? $stat['wins']   : 0 );
                $combined['starts'] += intval( isset( $stat['starts'] ) ? $stat['starts'] : 0 );
                $combined['top5']   += intval( isset( $stat['top5'] )   ? $stat['top5']   : 0 );
                $combined['laps']   += intval( isset( $stat['laps'] )   ? $stat['laps']   : 0 );
            }
        }

        return $combined;
    }
}
