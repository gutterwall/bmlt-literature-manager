<?php
/**
 * A fake BMLT root server for testing: the client_interface/json/ switchers the plugin uses, over a fixed,
 * fictional dataset. No WordPress needed; used in-process by the PHPUnit suite and over HTTP (router.php) by the
 * end-to-end test and for manual testing.
 *
 * Faithful to the BMLT root server's JSON client interface (bmlt-enabled/bmlt-root-server, 3.x):
 *   - every value is a string, ids included ("12", "0");
 *   - GetSearchResults returns only published meetings, takes services[] (or services=), recursive=1 to include
 *     every service body below the ones asked for, and data_field_key to choose the fields returned;
 *   - unknown switchers answer HTTP 400 ... (the real server answers 404 with an HTML page; either is an error).
 *
 * Everything is fictional. Phone numbers use the NANP 555-01xx fictional range.
 */

namespace BmltLitTest;

final class FakeBmlt {

	const VERSION = '3.4.1';

	/**
	 * Service bodies: id => [parent_id, name, type, description].
	 *
	 * 100 Fictional Region
	 *   101 Riverside Area        (many meetings: the order form shows its search box)
	 *   102 Hillcrest Area
	 *   103 Lakeside Area
	 *     104 Lakeside North Area (a sub-Area: its meetings count under Lakeside)
	 *   105 Empty Area            (no meetings)
	 * 200 Other Region
	 *   201 Faraway Area
	 */
	const SERVICE_BODIES = array(
		100 => array( 0, 'Fictional Region', 'RS', 'A made-up Region for testing.' ),
		101 => array( 100, 'Riverside Area', 'AS', 'Meets along the river.' ),
		102 => array( 100, 'Hillcrest Area', 'AS', '' ),
		103 => array( 100, 'Lakeside Area', 'AS', '' ),
		104 => array( 103, 'Lakeside North Area', 'AS', '' ),
		105 => array( 100, 'Empty Area', 'AS', '' ),
		200 => array( 0, 'Other Region', 'RS', '' ),
		201 => array( 200, 'Faraway Area', 'AS', '' ),
	);

	/**
	 * Meetings: id => [service body, name, weekday (1 = Sunday), start time, municipality, location, published].
	 */
	public static function meetings() {
		$m = array(
			// Riverside: 18 meetings, so the order form offers a meeting search (more than 15).
			1001 => array( 101, 'Just for Today', 2, '19:00:00', 'Riverton', 'St. Anne Hall', '1' ),
			1002 => array( 101, 'Riverside Serenity', 4, '12:00:00', 'Riverton', 'Library Room B', '1' ),
			1003 => array( 101, 'Recovery & Beyond <b>Group</b>', 7, '20:30:00', 'Riverton', 'Grace Church', '1' ),
			1004 => array( 101, 'Café Clean Time', 1, '10:00:00', 'Millbrook', 'Community Center', '1' ),
			1005 => array( 101, 'Hidden Meeting', 3, '18:00:00', 'Riverton', 'Annex', '0' ),
			1006 => array( 101, '', 5, '07:00:00', 'Millbrook', 'Fire Hall', '1' ),
		);
		$towns = array( 'Riverton', 'Millbrook', 'Eastbank' );
		for ( $i = 7; $i <= 19; $i++ ) {
			$m[ 1000 + $i ] = array( 101, sprintf( 'River Group %02d', $i ), 1 + ( $i % 7 ), sprintf( '%02d:%02d:00', 6 + $i, ( $i % 2 ) * 30 ), $towns[ $i % 3 ], 'Hall ' . $i, '1' );
		}
		$m += array(
			2001 => array( 102, 'Hilltop Hope', 2, '19:30:00', 'Hillcrest', 'Old School', '1' ),
			2002 => array( 102, 'Steps on the Hill', 6, '18:30:00', 'Hillcrest', 'Library', '1' ),
			3001 => array( 103, 'Lakeside Living', 3, '19:00:00', 'Laketown', 'Boat House', '1' ),
			3002 => array( 104, 'North Shore Group', 5, '20:00:00', 'Northshore', 'Pier 4', '1' ),
			9001 => array( 201, 'Faraway Friends', 1, '18:00:00', 'Distant City', 'Hall', '1' ),
		);
		return $m;
	}

	/**
	 * Service body ids at or below the given ones.
	 *
	 * @param int[] $ids Ids.
	 * @return int[]
	 */
	public static function descendants( array $ids ) {
		$out   = array();
		$queue = $ids;
		while ( $queue ) {
			$id = (int) array_shift( $queue );
			if ( isset( $out[ $id ] ) ) {
				continue;
			}
			$out[ $id ] = $id;
			foreach ( self::SERVICE_BODIES as $child => $sb ) {
				if ( $sb[0] === $id ) {
					$queue[] = $child;
				}
			}
		}
		return array_values( $out );
	}

	/**
	 * Answer one request.
	 *
	 * @param array $get Query parameters, as PHP parses them ($_GET).
	 * @return array{0:int,1:string,2:string} HTTP status, content type, body.
	 */
	public static function handle( array $get ) {
		$switcher = isset( $get['switcher'] ) ? (string) $get['switcher'] : '';
		switch ( $switcher ) {
			case 'GetServerInfo':
				return self::json(
					array(
						array(
							'version'      => self::VERSION,
							'versionInt'   => '3004001',
							'langs'        => 'en',
							'nativeLang'   => 'en',
							'centerLongitude' => '-77.4',
							'centerLatitude'  => '37.5',
						),
					)
				);
			case 'GetServiceBodies':
				$rows = array();
				foreach ( self::SERVICE_BODIES as $id => $sb ) {
					$rows[] = array(
						'id'          => (string) $id,
						'parent_id'   => (string) $sb[0],
						'name'        => $sb[1],
						'description' => $sb[3],
						'type'        => $sb[2],
						'url'         => 'https://' . strtolower( strtok( $sb[1], ' ' ) ) . '.example.org/',
						'helpline'    => '555-0100',
						'world_id'    => 'AR' . $id,
					);
				}
				return self::json( $rows );
			case 'GetSearchResults':
				return self::json( self::search( $get ) );
			default:
				return array( 400, 'text/plain', 'Unknown switcher' );
		}
	}

	/**
	 * GetSearchResults.
	 *
	 * @param array $get Query.
	 * @return array
	 */
	private static function search( array $get ) {
		$services = array();
		if ( isset( $get['services'] ) ) {
			foreach ( (array) $get['services'] as $s ) {
				foreach ( explode( ',', (string) $s ) as $id ) {
					if ( (int) $id > 0 ) {
						$services[] = (int) $id;
					}
				}
			}
		}
		if ( $services && isset( $get['recursive'] ) && '1' === (string) $get['recursive'] ) {
			$services = self::descendants( $services );
		}
		$fields = isset( $get['data_field_key'] ) && '' !== (string) $get['data_field_key'] ? explode( ',', (string) $get['data_field_key'] ) : null;
		$rows   = array();
		foreach ( self::meetings() as $id => $m ) {
			if ( '1' !== $m[6] || ( $services && ! in_array( $m[0], $services, true ) ) ) {
				continue;
			}
			$row = array(
				'id_bigint'             => (string) $id,
				'meeting_name'          => $m[1],
				'weekday_tinyint'       => (string) $m[2],
				'start_time'            => $m[3],
				'duration_time'         => '01:00:00',
				'service_body_bigint'   => (string) $m[0],
				'location_municipality' => $m[4],
				'location_province'     => 'VA',
				'location_neighborhood' => '',
				'location_text'         => $m[5],
				'location_street'       => $id . ' Main Street',
				'published'             => $m[6],
				'contact_phone_1'       => '555-0199',
			);
			if ( null !== $fields ) {
				$row = array_intersect_key( $row, array_flip( $fields ) );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * JSON response.
	 *
	 * @param mixed $data Data.
	 * @return array
	 */
	private static function json( $data ) {
		return array( 200, 'application/json', (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}
