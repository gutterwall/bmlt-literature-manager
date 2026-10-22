<?php
/**
 * BMLT Root Server client (Semantic / client_interface JSON API).
 *
 * @package BMLT_Lit
 * @version 6.3.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the BMLT root server and caches results.
 *
 * Uses the documented client interface: {root}/client_interface/json/?switcher=...
 * (GetServerInfo, GetServiceBodies, GetSearchResults). See https://bmlt.app.
 */
class Bmlt_Client {

	const CRON_HOOK      = 'bmlt_lit_cron_refresh';
	const CACHE_DOC      = 'cache/bmlt';
	const CACHE_SB       = 'service_bodies';
	const CACHE_MEETINGS = 'meetings';
	const STATUS         = 'status';
	const BACKOFF        = 'bmlt_lit_bmlt_backoff';
	const LOCK           = 'bmlt_lit_bmlt_lock';

	/**
	 * Meeting fields requested from BMLT (keeps responses small).
	 */
	const MEETING_FIELDS = 'id_bigint,meeting_name,weekday_tinyint,start_time,service_body_bigint,location_municipality,location_province,location_neighborhood,location_text,location_street,published';

	/**
	 * Read one section of the BMLT cache document.
	 *
	 * @param string $key Section.
	 * @return array
	 */
	private static function cache_get( $key ) {
		$doc = Store::get( self::CACHE_DOC );
		return ( isset( $doc[ $key ] ) && is_array( $doc[ $key ] ) ) ? $doc[ $key ] : array();
	}

	/**
	 * Write one section of the BMLT cache document.
	 *
	 * @param string $key   Section.
	 * @param array  $value Value.
	 * @return true|\WP_Error
	 */
	private static function cache_put( $key, array $value ) {
		return Store::tx(
			static function () use ( $key, $value ) {
				$doc         = Store::get( self::CACHE_DOC );
				$doc[ $key ] = $value;
				return Store::put( self::CACHE_DOC, $doc );
			}
		);
	}

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_refresh' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
	}

	/**
	 * Add the plugin's refresh interval.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$b     = Settings::bmlt();
		$hours = max( 1, min( 168, (int) $b['refresh_hours'] ) );
		$schedules['bmlt_lit_interval'] = array(
			'interval' => $hours * HOUR_IN_SECONDS,
			/* translators: %d: number of hours. */
			'display'  => sprintf( __( 'Every %d hours (BMLT Literature Manager)', 'bmlt-literature-manager' ), $hours ),
		);
		return $schedules;
	}

	/**
	 * Schedule (or reschedule) the refresh event.
	 *
	 * @param bool $reschedule Clear existing first.
	 */
	public static function schedule_cron( $reschedule = false ) {
		if ( ! has_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ) ) {
			add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		}
		if ( $reschedule ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'bmlt_lit_interval', self::CRON_HOOK );
		}
	}

	/**
	 * Ensure the event exists.
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			self::schedule_cron();
		}
	}

	/**
	 * Cron callback.
	 */
	public static function cron_refresh() {
		if ( '' === self::endpoint() ) {
			return;
		}
		self::refresh_all();
	}

	/**
	 * JSON endpoint URL.
	 *
	 * @return string
	 */
	public static function endpoint() {
		$o = Settings::bmlt();
		if ( ! empty( $o['endpoint_override'] ) ) {
			return (string) $o['endpoint_override'];
		}
		if ( empty( $o['root_url'] ) ) {
			return '';
		}
		return untrailingslashit( (string) $o['root_url'] ) . '/client_interface/json/';
	}

	/**
	 * Perform an API request.
	 *
	 * @param string $switcher Switcher name.
	 * @param array  $params   Query params (arrays become key[]=v).
	 * @return array|\WP_Error
	 */
	public static function request( $switcher, array $params = array() ) {
		$endpoint = self::endpoint();
		if ( '' === $endpoint ) {
			return new \WP_Error( 'bmlt_not_configured', __( 'The BMLT Root Server URL has not been configured.', 'bmlt-literature-manager' ) );
		}

		$query = array( 'switcher=' . rawurlencode( $switcher ) );
		foreach ( $params as $key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $v ) {
					$query[] = rawurlencode( (string) $key ) . '[]=' . rawurlencode( (string) $v );
				}
			} else {
				$query[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
			}
		}
		$url = $endpoint . ( false === strpos( $endpoint, '?' ) ? '?' : '&' ) . implode( '&', $query );

		$o       = Settings::bmlt();
		$timeout = max( 3, min( 120, (int) $o['timeout'] ) );
		$start   = microtime( true );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $timeout,
				'redirection' => 3,
				'headers'     => array( 'Accept' => 'application/json' ),
				'user-agent'  => 'BMLT-Literature-Manager/' . BMLT_LIT_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( ! empty( $o['debug'] ) ) {
			Helpers::log(
				'BMLT request',
				array(
					'switcher' => $switcher,
					'url'      => $url,
					'seconds'  => round( microtime( true ) - $start, 3 ),
				)
			);
		}

		if ( is_wp_error( $response ) ) {
			Helpers::log( 'BMLT request failed', array( 'error' => $response->get_error_message() ) );
			return new \WP_Error(
				'bmlt_http',
				/* translators: %s: error message. */
				sprintf( __( 'Could not connect to the BMLT server: %s', 'bmlt-literature-manager' ), $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error(
				'bmlt_http_status',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'The BMLT server responded with HTTP status %d. Please verify the Root Server URL.', 'bmlt-literature-manager' ), $code )
			);
		}

		$body = trim( (string) wp_remote_retrieve_body( $response ) );
		$body = preg_replace( '/^\xEF\xBB\xBF/', '', $body );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'bmlt_json',
				__( 'The BMLT server did not return valid JSON. Make sure the URL points to the Root Server (for example https://example.org/main_server).', 'bmlt-literature-manager' )
			);
		}
		return $data;
	}

	/**
	 * Get a scalar string field from an array.
	 *
	 * @param array  $row Row.
	 * @param string $key Key.
	 * @return string
	 */
	private static function str( array $row, $key ) {
		if ( ! isset( $row[ $key ] ) || is_array( $row[ $key ] ) || is_object( $row[ $key ] ) ) {
			return '';
		}
		return sanitize_text_field( (string) $row[ $key ] );
	}

	/**
	 * Normalize service bodies.
	 *
	 * @param array $data Raw.
	 * @return array Keyed by ID.
	 */
	private static function normalize_service_bodies( array $data ) {
		$out = array();
		foreach ( $data as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = absint( isset( $row['id'] ) ? $row['id'] : 0 );
			if ( ! $id ) {
				continue;
			}
			$url = self::str( $row, 'url' );
			$out[ $id ] = array(
				'id'          => $id,
				'parent_id'   => absint( isset( $row['parent_id'] ) ? $row['parent_id'] : 0 ),
				'name'        => Helpers::limit( self::str( $row, 'name' ), 255 ),
				'description' => Helpers::limit( self::str( $row, 'description' ), 1000 ),
				'type'        => Helpers::limit( self::str( $row, 'type' ), 10 ),
				'url'         => $url ? esc_url_raw( $url ) : '',
				'helpline'    => Helpers::limit( self::str( $row, 'helpline' ), 100 ),
				'world_id'    => Helpers::limit( self::str( $row, 'world_id' ), 50 ),
			);
		}
		return $out;
	}

	/**
	 * Fetch and cache service bodies.
	 *
	 * @return array|\WP_Error
	 */
	public static function refresh_service_bodies() {
		$data = self::request( 'GetServiceBodies' );
		if ( is_wp_error( $data ) ) {
			self::record_status( false, $data->get_error_message() );
			return $data;
		}
		$bodies = self::normalize_service_bodies( $data );
		self::cache_put(
			self::CACHE_SB,
			array(
				'fetched_at' => time(),
				'data'       => $bodies,
			)
		);
		self::sync_area_metadata( $bodies );
		return $bodies;
	}

	/**
	 * Copy BMLT metadata into stored Area configuration for selected Areas.
	 *
	 * @param array $bodies Normalized service bodies.
	 */
	public static function sync_area_metadata( array $bodies ) {
		$config  = Settings::areas_config();
		$changed = false;
		foreach ( Settings::selected_areas() as $id ) {
			if ( ! isset( $bodies[ $id ] ) ) {
				continue;
			}
			$sb     = $bodies[ $id ];
			$area   = isset( $config[ $id ] ) && is_array( $config[ $id ] ) ? $config[ $id ] : array();
			$parent = $sb['parent_id'] && isset( $bodies[ $sb['parent_id'] ] ) ? $bodies[ $sb['parent_id'] ]['name'] : '';
			$new    = array_merge(
				$area,
				array(
					'name'          => $sb['name'],
					'parent_id'     => $sb['parent_id'],
					'parent_name'   => $parent,
					'type'          => $sb['type'],
					'description'   => $sb['description'],
					'bmlt_url'      => $sb['url'],
					'bmlt_helpline' => $sb['helpline'],
				)
			);
			if ( $new !== $area ) {
				$config[ $id ] = $new;
				$changed       = true;
			}
		}
		if ( $changed ) {
			Settings::update( Settings::AREAS, $config );
		}
	}

	/**
	 * Cached service bodies.
	 *
	 * @return array Keyed by ID.
	 */
	public static function get_service_bodies() {
		$cache = self::cache_get( self::CACHE_SB );
		return ( isset( $cache['data'] ) && is_array( $cache['data'] ) ) ? $cache['data'] : array();
	}

	/**
	 * Timestamp of cached service bodies.
	 *
	 * @return int
	 */
	public static function service_bodies_fetched_at() {
		$cache = self::cache_get( self::CACHE_SB );
		return isset( $cache['fetched_at'] ) ? (int) $cache['fetched_at'] : 0;
	}

	/**
	 * Map a (possibly child) service body up to the nearest selected Area.
	 *
	 * @param int   $sb_id    Service body ID.
	 * @param array $bodies   Service bodies.
	 * @param int[] $selected Selected Areas.
	 * @return int 0 when not under a selected Area.
	 */
	private static function resolve_area( $sb_id, array $bodies, array $selected ) {
		$guard = 0;
		while ( $sb_id && $guard < 20 ) {
			if ( in_array( $sb_id, $selected, true ) ) {
				return $sb_id;
			}
			$sb_id = isset( $bodies[ $sb_id ] ) ? (int) $bodies[ $sb_id ]['parent_id'] : 0;
			++$guard;
		}
		return 0;
	}

	/**
	 * Fetch and cache meetings for the selected Areas.
	 *
	 * @return array|\WP_Error
	 */
	public static function refresh_meetings() {
		$selected = Settings::selected_areas();
		if ( empty( $selected ) ) {
			self::cache_put(
				self::CACHE_MEETINGS,
				array(
					'fetched_at' => time(),
					'areas'      => array(),
					'data'       => array(),
				)
			);
			return array();
		}

		$bodies = self::get_service_bodies();
		if ( empty( $bodies ) ) {
			$bodies = self::refresh_service_bodies();
			if ( is_wp_error( $bodies ) ) {
				return $bodies;
			}
		}

		$data = self::request(
			'GetSearchResults',
			array(
				'services'       => $selected,
				'recursive'      => '1',
				'data_field_key' => self::MEETING_FIELDS,
			)
		);
		if ( is_wp_error( $data ) ) {
			self::record_status( false, $data->get_error_message() );
			return $data;
		}

		$meetings = array();
		foreach ( $data as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = absint( isset( $row['id_bigint'] ) ? $row['id_bigint'] : 0 );
			if ( ! $id ) {
				continue;
			}
			if ( isset( $row['published'] ) && '0' === (string) $row['published'] ) {
				continue;
			}
			$sb   = absint( isset( $row['service_body_bigint'] ) ? $row['service_body_bigint'] : 0 );
			$area = self::resolve_area( $sb, $bodies, $selected );
			if ( ! $area ) {
				continue;
			}
			$time = self::str( $row, 'start_time' );
			$time = preg_match( '/^(\d{1,2}):(\d{2})/', $time, $m ) ? sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] ) : '';

			$meetings[ $id ] = array(
				'id'           => $id,
				'name'         => Helpers::limit( self::str( $row, 'meeting_name' ), 255 ),
				'area_id'      => $area,
				'sb_id'        => $sb,
				'weekday'      => max( 0, min( 7, absint( isset( $row['weekday_tinyint'] ) ? $row['weekday_tinyint'] : 0 ) ) ),
				'start_time'   => $time,
				'municipality' => Helpers::limit( self::str( $row, 'location_municipality' ), 120 ),
				'province'     => Helpers::limit( self::str( $row, 'location_province' ), 60 ),
				'neighborhood' => Helpers::limit( self::str( $row, 'location_neighborhood' ), 120 ),
				'location'     => Helpers::limit( self::str( $row, 'location_text' ), 200 ),
			);
			if ( '' === $meetings[ $id ]['name'] ) {
				/* translators: %d: meeting ID. */
				$meetings[ $id ]['name'] = sprintf( __( 'Meeting #%d', 'bmlt-literature-manager' ), $id );
			}
		}

		$meetings = self::sort_meetings( $meetings );

		$sorted_sel = $selected;
		sort( $sorted_sel );
		self::cache_put(
			self::CACHE_MEETINGS,
			array(
				'fetched_at' => time(),
				'areas'      => $sorted_sel,
				'data'       => $meetings,
			)
		);
		return $meetings;
	}

	/**
	 * Sort meetings by Area name → locality → meeting name.
	 *
	 * @param array $meetings Meetings keyed by ID.
	 * @return array
	 */
	public static function sort_meetings( array $meetings ) {
		$names = array();
		uasort(
			$meetings,
			static function ( $a, $b ) use ( &$names ) {
				foreach ( array( $a['area_id'], $b['area_id'] ) as $aid ) {
					if ( ! isset( $names[ $aid ] ) ) {
						$names[ $aid ] = Settings::area_name( $aid );
					}
				}
				$c = strnatcasecmp( $names[ $a['area_id'] ], $names[ $b['area_id'] ] );
				if ( 0 !== $c ) {
					return $c;
				}
				$c = strnatcasecmp( $a['municipality'], $b['municipality'] );
				if ( 0 !== $c ) {
					return $c;
				}
				$c = strnatcasecmp( $a['name'], $b['name'] );
				if ( 0 !== $c ) {
					return $c;
				}
				return $a['weekday'] <=> $b['weekday'];
			}
		);
		return $meetings;
	}

	/**
	 * Refresh everything, honoring failure back-off.
	 *
	 * @return true|\WP_Error
	 */
	public static function refresh_all() {
		$sb = self::refresh_service_bodies();
		if ( is_wp_error( $sb ) ) {
			set_transient( self::BACKOFF, 1, 10 * MINUTE_IN_SECONDS );
			return $sb;
		}
		$m = self::refresh_meetings();
		if ( is_wp_error( $m ) ) {
			set_transient( self::BACKOFF, 1, 10 * MINUTE_IN_SECONDS );
			return $m;
		}
		delete_transient( self::BACKOFF );
		self::record_status( true, '' );
		return true;
	}

	/**
	 * Get meetings for the selected Areas, using cache whenever possible.
	 *
	 * Remote fetch happens only when the cache is missing/expired, no back-off is active and no
	 * other request is already fetching. If BMLT fails, the last good cache is returned (stale).
	 *
	 * @param bool $allow_remote Whether a remote refresh may be attempted.
	 * @return array { meetings: array, fetched_at: int, stale: bool, error: string }
	 */
	public static function get_meetings( $allow_remote = true ) {
		$cache    = self::cache_get( self::CACHE_MEETINGS );
		$selected = Settings::selected_areas();
		sort( $selected );
		$b        = Settings::bmlt();
		$matches  = isset( $cache['areas'] ) && array_map( 'intval', (array) $cache['areas'] ) === $selected && isset( $cache['data'] );
		$fresh    = $matches && empty( $cache['expired'] ) && isset( $cache['fetched_at'] ) && (int) $cache['fetched_at'] + max( 1, (int) $b['cache_hours'] ) * HOUR_IN_SECONDS > time();
		$error    = '';

		if ( ! $fresh && $allow_remote && ! empty( $selected ) && false === get_transient( self::BACKOFF ) && false === get_transient( self::LOCK ) ) {
			set_transient( self::LOCK, 1, 60 );
			$result = self::refresh_all();
			delete_transient( self::LOCK );
			if ( ! is_wp_error( $result ) ) {
				$cache   = self::cache_get( self::CACHE_MEETINGS );
				$matches = true;
				$fresh   = true;
			} else {
				$error = $result->get_error_message();
			}
		}

		$meetings = ( isset( $cache['data'] ) && is_array( $cache['data'] ) ) ? $cache['data'] : array();
		if ( ! $matches ) {
			// Selection changed but refresh failed: filter old cache to still-selected Areas.
			$meetings = array_filter(
				$meetings,
				static function ( $m ) use ( $selected ) {
					return in_array( (int) $m['area_id'], $selected, true );
				}
			);
		}

		return array(
			'meetings'   => $meetings,
			'fetched_at' => isset( $cache['fetched_at'] ) ? (int) $cache['fetched_at'] : 0,
			'stale'      => ! $fresh,
			'error'      => $error,
		);
	}

	/**
	 * Look up one cached meeting.
	 *
	 * @param int $meeting_id Meeting ID.
	 * @return array|null
	 */
	public static function get_meeting( $meeting_id ) {
		$result = self::get_meetings( false );
		$id     = absint( $meeting_id );
		return isset( $result['meetings'][ $id ] ) ? $result['meetings'][ $id ] : null;
	}

	/**
	 * Test the connection and return a diagnostic summary.
	 *
	 * @return array
	 */
	public static function test_connection() {
		$result = array(
			'success'        => false,
			'version'        => '',
			'service_bodies' => 0,
			'meetings'       => 0,
			'meetings_scope' => 'all',
			'endpoint'       => self::endpoint(),
			'messages'       => array(),
		);

		$info = self::request( 'GetServerInfo' );
		if ( is_wp_error( $info ) ) {
			$result['messages'][] = $info->get_error_message();
			self::record_status( false, $info->get_error_message() );
			return $result;
		}
		$first = ( isset( $info[0] ) && is_array( $info[0] ) ) ? $info[0] : $info;
		if ( isset( $first['version'] ) && is_scalar( $first['version'] ) ) {
			$result['version'] = sanitize_text_field( (string) $first['version'] );
		}

		$bodies = self::refresh_service_bodies();
		if ( is_wp_error( $bodies ) ) {
			$result['messages'][] = $bodies->get_error_message();
			return $result;
		}
		$result['service_bodies'] = count( $bodies );

		$selected = Settings::selected_areas();
		$params   = array( 'data_field_key' => 'id_bigint' );
		if ( $selected ) {
			$params['services']       = $selected;
			$params['recursive']      = '1';
			$result['meetings_scope'] = 'selected';
		}
		$meetings = self::request( 'GetSearchResults', $params );
		if ( is_wp_error( $meetings ) ) {
			$result['messages'][] = $meetings->get_error_message();
			return $result;
		}
		$result['meetings'] = count( $meetings );
		$result['success']  = true;
		self::record_status( true, '' );
		return $result;
	}

	/**
	 * Record last attempt status.
	 *
	 * @param bool   $ok    Success.
	 * @param string $error Error message.
	 */
	private static function record_status( $ok, $error ) {
		$status                 = self::cache_get( self::STATUS );
		$status['last_attempt'] = time();
		if ( $ok ) {
			$status['last_success'] = time();
			$status['last_error']   = '';
		} else {
			$status['last_error'] = Helpers::limit( (string) $error, 500 );
		}
		self::cache_put( self::STATUS, $status );
	}

	/**
	 * Status info.
	 *
	 * @return array
	 */
	public static function status() {
		$status = self::cache_get( self::STATUS );
		return wp_parse_args(
			$status,
			array(
				'last_attempt' => 0,
				'last_success' => 0,
				'last_error'   => '',
			)
		);
	}

	/**
	 * Clear all BMLT caches.
	 */
	public static function clear_cache() {
		Store::tx(
			static function () {
				$doc = Store::get( self::CACHE_DOC );
				unset( $doc[ self::CACHE_SB ], $doc[ self::CACHE_MEETINGS ] );
				return Store::put( self::CACHE_DOC, $doc );
			}
		);
		delete_transient( self::BACKOFF );
		delete_transient( self::LOCK );
	}

	/**
	 * Mark cached meetings as due for refresh (keeps the data as a fallback).
	 */
	public static function expire_cache() {
		$cache = self::cache_get( self::CACHE_MEETINGS );
		if ( $cache ) {
			$cache['expired'] = true;
			self::cache_put( self::CACHE_MEETINGS, $cache );
		}
	}

	/**
	 * Build a hierarchical, indented list of service bodies for display.
	 *
	 * @return array List of [ 'body' => array, 'depth' => int ].
	 */
	public static function service_body_tree() {
		$bodies   = self::get_service_bodies();
		$children = array();
		foreach ( $bodies as $id => $b ) {
			$parent = ( $b['parent_id'] && isset( $bodies[ $b['parent_id'] ] ) ) ? (int) $b['parent_id'] : 0;
			$children[ $parent ][] = $id;
		}
		foreach ( $children as &$list ) {
			usort(
				$list,
				static function ( $a, $b ) use ( $bodies ) {
					return strnatcasecmp( $bodies[ $a ]['name'], $bodies[ $b ]['name'] );
				}
			);
		}
		unset( $list );

		$out  = array();
		$seen = array();
		$walk = static function ( $parent, $depth ) use ( &$walk, &$out, &$seen, $children, $bodies ) {
			if ( empty( $children[ $parent ] ) || $depth > 15 ) {
				return;
			}
			foreach ( $children[ $parent ] as $id ) {
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$out[]       = array(
					'body'  => $bodies[ $id ],
					'depth' => $depth,
				);
				$walk( $id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return $out;
	}
}
