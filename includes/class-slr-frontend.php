<?php
defined( 'ABSPATH' ) || exit;

class SLR_Frontend {

	const HANDLE = 'slr-tracker';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'exclude_from_optimizers' ), 10, 2 );

		// Page optimizers that delay / combine / defer scripts would hold the tracker until the
		// first scroll or tap, losing quick clicks. Opt out of each popular one.
		add_filter( 'litespeed_optimize_js_excludes', array( __CLASS__, 'add_to_list' ) );
		add_filter( 'litespeed_optm_js_defer_exc', array( __CLASS__, 'add_to_list' ) );
		add_filter( 'litespeed_optm_gm_js_exc', array( __CLASS__, 'add_to_list' ) );
		add_filter( 'rocket_exclude_js', array( __CLASS__, 'add_to_list' ) );
		add_filter( 'rocket_exclude_defer_js', array( __CLASS__, 'add_to_list' ) );
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'add_to_list' ) );
		add_filter( 'rocket_minify_excluded_external_js', array( __CLASS__, 'add_to_list' ) );
		add_filter( 'sgo_javascript_combine_exclude', array( __CLASS__, 'add_handle' ) );
		add_filter( 'sgo_js_minify_exclude', array( __CLASS__, 'add_handle' ) );
		add_filter( 'sgo_js_async_exclude', array( __CLASS__, 'add_handle' ) );
		add_filter( 'perfmatters_delay_js_exclusions', array( __CLASS__, 'add_to_list' ) );
		add_filter( 'flying_press_exclude_from_minify:js', array( __CLASS__, 'add_to_list' ) );
		add_filter( 'autoptimize_filter_js_exclude', array( __CLASS__, 'autoptimize' ) );
		add_filter( 'w3tc_minify_js_do_tag_minification', array( __CLASS__, 'w3tc' ), 10, 3 );
	}

	public static function enqueue() {
		if ( SLR_Helpers::setting( 'exclude_logged_in' ) && current_user_can( 'edit_posts' ) ) {
			return;
		}
		wp_enqueue_script( self::HANDLE, SLR_URL . 'assets/tracker.js', array(), SLR_VERSION, true );
		wp_add_inline_script(
			self::HANDLE,
			'window.SLR_CFG=' . wp_json_encode(
				array(
					'api'   => esc_url_raw( rest_url( SLR_Rest::NS . '/' ) ),
					'ajax'  => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
					'mode'  => 'ajax' === get_option( 'slr_transport' ) ? 'ajax' : 'rest',
					'forms' => (string) SLR_Helpers::setting( 'form_selectors' ),
				)
			) . ';',
			'before'
		);
	}

	public static function exclude_from_optimizers( $tag, $handle ) {
		if ( self::HANDLE === $handle ) {
			$tag = str_replace( '<script ', '<script data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-cfasync="false" data-pagespeed-no-defer nowprocket ', $tag );
		}
		return $tag;
	}

	public static function add_to_list( $list ) {
		$list   = is_array( $list ) ? $list : array();
		$list[] = 'assets/tracker.js';
		$list[] = 'SLR_CFG';
		return $list;
	}

	public static function add_handle( $list ) {
		$list   = is_array( $list ) ? $list : array();
		$list[] = self::HANDLE;
		return $list;
	}

	public static function autoptimize( $list ) {
		return trim( (string) $list . ', assets/tracker.js, SLR_CFG', ', ' );
	}

	public static function w3tc( $do, $script_tag, $file ) {
		return false !== strpos( (string) $file, 'assets/tracker.js' ) ? false : $do;
	}
}
