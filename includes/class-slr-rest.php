<?php
defined( 'ABSPATH' ) || exit;

/**
 * Ingestion endpoints. Pages are usually served from a page cache, so these routes
 * deliberately take no nonce; abuse is limited by payload validation, bot filtering
 * and a per-IP rate limit instead.
 *
 * The same handlers are reachable through REST (default) and admin-ajax (fallback for
 * sites where a security plugin blocks the REST API for visitors).
 */
class SLR_Rest {

	const NS = 'slr/v1';

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/e',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_event' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/lead',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_lead' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					return new WP_REST_Response( array( 'ok' => true, 'slr' => SLR_VERSION ), 200 );
				},
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function register_ajax() {
		foreach ( array( 'slr_e' => 'process_event', 'slr_lead' => 'process_lead' ) as $action => $method ) {
			$cb = function () use ( $method ) {
				$raw  = file_get_contents( 'php://input' );
				$data = self::decode( $raw );
				wp_send_json( self::safely( $method, $data ) );
			};
			add_action( 'wp_ajax_nopriv_' . $action, $cb );
			add_action( 'wp_ajax_' . $action, $cb );
		}
	}

	public static function rest_event( WP_REST_Request $request ) {
		return new WP_REST_Response( self::safely( 'process_event', self::decode( $request->get_body() ) ), 200 );
	}

	public static function rest_lead( WP_REST_Request $request ) {
		return new WP_REST_Response( self::safely( 'process_lead', self::decode( $request->get_body() ) ), 200 );
	}

	/** Tracking must never break the visitor's page or return a 500. */
	private static function safely( $method, $data ) {
		try {
			return self::$method( $data );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Lead Radar: ' . $e->getMessage() ); // phpcs:ignore
			}
			return array( 'ok' => false );
		}
	}

	private static function decode( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > 12000 ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	private static function str( $data, $key, $max = 191 ) {
		if ( ! isset( $data[ $key ] ) || ! is_scalar( $data[ $key ] ) ) {
			return '';
		}
		return mb_substr( sanitize_text_field( (string) $data[ $key ] ), 0, $max );
	}

	private static function url( $data, $key ) {
		return isset( $data[ $key ] ) && is_string( $data[ $key ] ) ? mb_substr( esc_url_raw( $data[ $key ] ), 0, 500 ) : '';
	}

	/** Only accept events that claim to come from this site. */
	private static function same_site( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		return $host && preg_replace( '/^www\./', '', strtolower( $host ) ) === preg_replace( '/^www\./', '', strtolower( $home ) );
	}

	private static function attribution( $attr ) {
		$attr = is_array( $attr ) ? $attr : array();
		return array(
			'source'        => strtolower( self::str( $attr, 's', 100 ) ),
			'medium'        => strtolower( self::str( $attr, 'm', 100 ) ),
			'campaign'      => self::str( $attr, 'c', 191 ),
			'click_id_type' => self::str( $attr, 'ct', 20 ),
			'click_id'      => self::str( $attr, 'ci', 255 ),
		);
	}

	private static function build_context( $uid, $url, $title, $ref, $attr, $first ) {
		$ua   = SLR_Helpers::user_agent();
		$a    = self::attribution( $attr );
		$f    = is_array( $first ) ? $first : $attr;
		return array(
			'uid'     => $uid,
			'url'     => $url,
			'path'    => mb_substr( (string) wp_parse_url( $url, PHP_URL_PATH ), 0, 255 ),
			'title'   => $title,
			'ref'     => $ref,
			'ip_hash' => SLR_Helpers::hash( SLR_Helpers::client_ip() ),
			'ua_hash' => SLR_Helpers::hash( $ua ),
			'device'  => SLR_Helpers::device( $ua ),
			'attr'    => $a,
			'first'   => array(
				'source'   => strtolower( self::str( $f, 's', 100 ) ),
				'medium'   => strtolower( self::str( $f, 'm', 100 ) ),
				'campaign' => self::str( $f, 'c', 191 ),
				'landing'  => self::url( $f, 'l' ),
			),
		);
	}

	/** Validation + context for browser beacons; null means drop silently. */
	private static function context( $data, $bucket, $max ) {
		if ( ! $data || SLR_Helpers::is_bot( SLR_Helpers::user_agent() ) ) {
			return null;
		}
		$uid = self::str( $data, 'v', 64 );
		$url = self::url( $data, 'u' );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $uid ) || ! self::same_site( $url ) ) {
			return null;
		}
		$ctx = self::build_context(
			$uid,
			$url,
			self::str( $data, 'ti', 255 ),
			self::url( $data, 'r' ),
			isset( $data['a'] ) ? $data['a'] : array(),
			isset( $data['fa'] ) ? $data['fa'] : null
		);
		if ( SLR_Helpers::rate_limited( $bucket, $ctx['ip_hash'], $max, 10 * MINUTE_IN_SECONDS ) ) {
			return null;
		}
		return $ctx;
	}

	/**
	 * Context for submissions caught server-side (Contact Form 7, WPForms…). The visitor ID
	 * and attribution come from the cookies set by tracker.js; the page from the referer.
	 */
	public static function server_context() {
		$uid = isset( $_COOKIE['slr_vid'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['slr_vid'] ) ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $uid ) ) {
			$uid = 's' . wp_generate_password( 21, false );
		}
		$attr = array();
		if ( ! empty( $_COOKIE['slr_attr'] ) ) {
			$decoded = json_decode( wp_unslash( $_COOKIE['slr_attr'] ), true );
			$attr    = is_array( $decoded ) ? $decoded : array();
		}
		$url = wp_get_referer();
		$url = $url && self::same_site( $url ) ? esc_url_raw( $url ) : home_url( '/' );
		$pid = url_to_postid( $url );
		return self::build_context( $uid, mb_substr( $url, 0, 500 ), $pid ? mb_substr( wp_strip_all_tags( get_the_title( $pid ) ), 0, 255 ) : '', '', $attr, null );
	}

	private static function upsert_visitor( $ctx ) {
		global $wpdb;
		$table   = SLR_DB::table( 'visitors' );
		$visitor = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE uid = %s", $ctx['uid'] ) );
		$now     = SLR_Helpers::now();

		if ( $visitor ) {
			$wpdb->update( $table, array( 'last_seen' => $now, 'device' => $ctx['device'] ), array( 'id' => $visitor->id ) );
			return $visitor;
		}

		$wpdb->insert(
			$table,
			array(
				'uid'            => $ctx['uid'],
				'first_source'   => $ctx['first']['source'],
				'first_medium'   => $ctx['first']['medium'],
				'first_campaign' => $ctx['first']['campaign'],
				'first_landing'  => $ctx['first']['landing'] ? $ctx['first']['landing'] : $ctx['url'],
				'device'         => $ctx['device'],
				'first_seen'     => $now,
				'last_seen'      => $now,
			)
		);
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $wpdb->insert_id ) );
	}

	/** Same network + same browser seen contacting under another visitor ID in the last 7 days. */
	private static function possible_repeat( $ctx, $visitor_id ) {
		global $wpdb;
		if ( '' === $ctx['ip_hash'] ) {
			return 0;
		}
		return (int) (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . SLR_DB::table( 'events' ) . ' WHERE ip_hash = %s AND ua_hash = %s AND visitor_id <> %d AND created_at >= %s LIMIT 1',
				$ctx['ip_hash'],
				$ctx['ua_hash'],
				$visitor_id,
				wp_date( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * A visitor counts as returning when they already contacted us in an earlier visit
	 * (over 30 minutes ago). Several clicks within one visit are not a repeat customer.
	 */
	private static function contacted_before( $visitor_id ) {
		global $wpdb;
		return (int) (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . SLR_DB::table( 'events' ) . ' WHERE visitor_id = %d AND created_at < %s LIMIT 1',
				$visitor_id,
				wp_date( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS )
			)
		);
	}

	private static function insert_event( $ctx, $visitor, $type, $placement, $label, $target ) {
		global $wpdb;
		$is_repeat = $visitor->contacts > 0 ? self::contacted_before( $visitor->id ) : 0;
		$wpdb->insert(
			SLR_DB::table( 'events' ),
			array(
				'visitor_id'      => $visitor->id,
				'type'            => $type,
				'placement'       => $placement,
				'label'           => $label,
				'target'          => $target,
				'page_url'        => $ctx['url'],
				'page_path'       => $ctx['path'],
				'page_title'      => $ctx['title'],
				'source'          => $ctx['attr']['source'],
				'medium'          => $ctx['attr']['medium'],
				'campaign'        => $ctx['attr']['campaign'],
				'click_id_type'   => $ctx['attr']['click_id_type'],
				'click_id'        => $ctx['attr']['click_id'],
				'referrer'        => $ctx['ref'],
				'device'          => $ctx['device'],
				'ip_hash'         => $ctx['ip_hash'],
				'ua_hash'         => $ctx['ua_hash'],
				'is_repeat'       => $is_repeat,
				'possible_repeat' => $is_repeat ? 0 : self::possible_repeat( $ctx, $visitor->id ),
				'created_at'      => SLR_Helpers::now(),
			)
		);
		$event_id = (int) $wpdb->insert_id;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . SLR_DB::table( 'visitors' ) . ' SET contacts = contacts + 1 WHERE id = %d', $visitor->id ) );
		return array( $event_id, $is_repeat );
	}

	private static function process_event( $data ) {
		$ctx = self::context( $data, 'e', 40 );
		if ( ! $ctx ) {
			return array( 'ok' => true );
		}
		$type = self::str( $data, 't', 20 );
		if ( ! in_array( $type, array( 'whatsapp', 'call' ), true ) ) {
			return array( 'ok' => true );
		}
		$placement = self::str( $data, 'p', 40 );
		if ( ! isset( SLR_Helpers::placement_labels()[ $placement ] ) ) {
			$placement = 'content';
		}

		$visitor                      = self::upsert_visitor( $ctx );
		list( $event_id, $is_repeat ) = self::insert_event( $ctx, $visitor, $type, $placement, self::str( $data, 'l', 191 ), self::str( $data, 'h', 191 ) );

		// A visitor who already left their number came back in a later visit and is reaching out again.
		if ( $is_repeat && '' !== $visitor->phone && SLR_Helpers::setting( 'notify_returning' ) ) {
			SLR_Notify::later( 'returning_visitor', array( $visitor, $type, $ctx ) );
		}
		return array( 'ok' => true, 'id' => $event_id );
	}

	private static function process_lead( $data ) {
		$ctx = self::context( $data, 'l', 6 );
		if ( ! $ctx || empty( $data['f'] ) || ! is_array( $data['f'] ) ) {
			return array( 'ok' => true );
		}
		$f   = $data['f'];
		$out = array();
		foreach ( array( 'form_id' => 100, 'name' => 191, 'phone' => 64, 'email' => 191, 'service' => 191 ) as $k => $max ) {
			$out[ $k ] = self::str( $f, $k, $max );
		}
		$out['message'] = isset( $f['message'] ) && is_scalar( $f['message'] ) ? (string) $f['message'] : '';
		$id             = self::record_lead( $ctx, $out );
		return array( 'ok' => true, 'id' => $id );
	}

	/**
	 * Stores one form submission. $f keys: form_id, name, phone, email, service, message.
	 * Returns the lead ID, or 0 when the submission was ignored.
	 */
	public static function record_lead( $ctx, $f ) {
		global $wpdb;
		$phone = mb_substr( sanitize_text_field( (string) ( isset( $f['phone'] ) ? $f['phone'] : '' ) ), 0, 64 );
		$norm  = SLR_Helpers::normalize_phone( $phone );
		$name  = mb_substr( sanitize_text_field( (string) ( isset( $f['name'] ) ? $f['name'] : '' ) ), 0, 191 );
		$email = sanitize_email( (string) ( isset( $f['email'] ) ? $f['email'] : '' ) );
		if ( strlen( $norm ) < 7 && '' === $name && '' === $email ) {
			return 0;
		}

		$leads   = SLR_DB::table( 'leads' );
		$visitor = self::upsert_visitor( $ctx );

		// Double submit (button pressed twice): keep the first one.
		$dupe = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM $leads WHERE visitor_id = %d AND phone_normalized = %s AND name = %s AND created_at >= %s LIMIT 1",
				$visitor->id,
				$norm,
				$name,
				wp_date( 'Y-m-d H:i:s', time() - 2 * MINUTE_IN_SECONDS )
			)
		);
		if ( $dupe ) {
			return (int) $dupe;
		}

		$previous = $norm ? $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $leads WHERE phone_normalized = %s ORDER BY id ASC LIMIT 1", $norm ) ) : null;
		if ( ! $previous && $email ) {
			$previous = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $leads WHERE email = %s ORDER BY id ASC LIMIT 1", $email ) );
		}
		$form_id          = mb_substr( sanitize_text_field( (string) ( isset( $f['form_id'] ) ? $f['form_id'] : 'form' ) ), 0, 100 );
		list( $event_id ) = self::insert_event( $ctx, $visitor, 'form', 'form', $form_id, $norm );
		$now              = SLR_Helpers::now();

		$wpdb->insert(
			$leads,
			array(
				'visitor_id'       => $visitor->id,
				'event_id'         => $event_id,
				'form_id'          => $form_id,
				'name'             => $name,
				'phone'            => $phone,
				'phone_normalized' => $norm,
				'email'            => $email,
				'service'          => mb_substr( sanitize_text_field( (string) ( isset( $f['service'] ) ? $f['service'] : '' ) ), 0, 191 ),
				'message'          => mb_substr( sanitize_textarea_field( (string) ( isset( $f['message'] ) ? $f['message'] : '' ) ), 0, 3000 ),
				'page_url'         => $ctx['url'],
				'page_title'       => $ctx['title'],
				'source'           => $ctx['attr']['source'],
				'medium'           => $ctx['attr']['medium'],
				'campaign'         => $ctx['attr']['campaign'],
				'click_id_type'    => $ctx['attr']['click_id_type'],
				'status'           => 'new',
				'notes'            => '',
				'is_repeat'        => (int) ( $previous || $visitor->leads > 0 ),
				'repeat_of'        => $previous ? (int) $previous : 0,
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		$lead_id = (int) $wpdb->insert_id;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . SLR_DB::table( 'visitors' ) . ' SET leads = leads + 1, phone = IF(%s = \'\', phone, %s), name = IF(%s = \'\', name, %s) WHERE id = %d',
				$norm,
				$norm,
				$name,
				$name,
				$visitor->id
			)
		);

		if ( $lead_id ) {
			SLR_Notify::later( 'new_lead', array( $lead_id ) );
		}
		return $lead_id;
	}
}
