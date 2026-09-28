<?php
defined( 'ABSPATH' ) || exit;

class SLR_Helpers {

	public static function default_settings() {
		return array(
			'business_name'        => '',
			'reply_template'       => 'مرحباً {name}، معك {business} بخصوص طلبك من موقعنا.',
			'notify_email'         => get_option( 'admin_email' ),
			'email_enabled'        => 1,
			'telegram_enabled'     => 0,
			'telegram_token'       => '',
			'telegram_chat_id'     => '',
			'notify_returning'     => 1,
			'exclude_logged_in'    => 1,
			'capture_plugin_forms' => 1,
			'retention_days'       => 365,
			'form_selectors'       => 'form[data-slr-form]',
			'delete_on_uninstall'  => 0,
		);
	}

	public static function business_name() {
		$name = trim( (string) self::setting( 'business_name' ) );
		return '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/** WhatsApp link to reply to a lead, pre-filled with the configurable greeting. */
	public static function reply_link( $phone, $name ) {
		$text = strtr(
			(string) self::setting( 'reply_template' ),
			array(
				'{name}'     => $name,
				'{business}' => self::business_name(),
			)
		);
		return self::wa_link( $phone, trim( $text ) );
	}

	/** Accepts a token pasted with spaces or a leading "bot" and returns the bare token. */
	public static function clean_token( $token ) {
		$token = preg_replace( '/\s+/', '', (string) $token );
		$token = preg_replace( '/^bot(?=\d)/i', '', $token );
		return preg_replace( '/[^A-Za-z0-9:_-]/', '', $token );
	}

	/** Name of the active SMTP / mail-delivery plugin, or '' when WordPress uses PHP mail(). */
	public static function mailer_plugin() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$files = array(
			'wp-mail-smtp/wp_mail_smtp.php'     => 'WP Mail SMTP',
			'wp-mail-smtp-pro/wp_mail_smtp.php' => 'WP Mail SMTP',
			'fluent-smtp/fluent-smtp.php'       => 'FluentSMTP',
			'post-smtp/postman-smtp.php'        => 'Post SMTP',
			'easy-wp-smtp/easy-wp-smtp.php'     => 'Easy WP SMTP',
			'site-mailer/site-mailer.php'       => 'Site Mailer (Elementor)',
			'smtp-mailer/main.php'              => 'SMTP Mailer',
			'gosmtp/gosmtp.php'                 => 'GoSMTP',
			'wp-ses/wp-ses.php'                 => 'WP Offload SES',
			'mailgun/mailgun.php'               => 'Mailgun',
			'sendgrid-email-delivery-simplified/wpsendgrid.php' => 'SendGrid',
		);
		foreach ( $files as $file => $label ) {
			if ( is_plugin_active( $file ) ) {
				return $label;
			}
		}
		return '';
	}

	public static function settings() {
		$saved = get_option( 'slr_settings', array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::default_settings() );
	}

	public static function setting( $key ) {
		$s = self::settings();
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	/** Local site time in MySQL format. */
	public static function now() {
		return current_time( 'mysql' );
	}

	/**
	 * Normalizes a phone number so different spellings of the same Saudi number match.
	 * 0500944612, +966500944612, 00966500944612, ٠٥٠٠٩٤٤٦١٢ and 500944612 all become 966500944612.
	 */
	public static function normalize_phone( $phone ) {
		$phone = strtr(
			(string) $phone,
			array(
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			)
		);
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( '' === $digits ) {
			return '';
		}
		if ( 0 === strpos( $digits, '00' ) ) {
			$digits = substr( $digits, 2 );
		}
		if ( 10 === strlen( $digits ) && 0 === strpos( $digits, '05' ) ) {
			$digits = '966' . substr( $digits, 1 );
		} elseif ( 9 === strlen( $digits ) && 0 === strpos( $digits, '5' ) ) {
			$digits = '966' . $digits;
		} elseif ( 0 === strpos( $digits, '9660' ) ) {
			$digits = '966' . substr( $digits, 4 );
		}
		return substr( $digits, 0, 20 );
	}

	public static function client_ip() {
		// Hostinger/LiteSpeed and Cloudflare forward the real IP in these headers.
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = trim( explode( ',', wp_unslash( $_SERVER[ $key ] ) )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '';
	}

	/** Salted hash: repeat detection works without storing the raw IP. */
	public static function hash( $value ) {
		return '' === $value ? '' : sha1( get_option( 'slr_salt' ) . '|' . $value );
	}

	public static function user_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '';
	}

	public static function device( $ua ) {
		if ( preg_match( '/iPad|Tablet|Android(?!.*Mobile)/i', $ua ) ) {
			return 'tablet';
		}
		if ( preg_match( '/Mobile|Android|iPhone|iPod/i', $ua ) ) {
			return 'mobile';
		}
		return 'desktop';
	}

	public static function is_bot( $ua ) {
		return '' === $ua || (bool) preg_match( '/bot|crawl|spider|slurp|headless|lighthouse|pagespeed|preview|curl|wget|python|facebookexternalhit|monitor/i', $ua );
	}

	/** Simple fixed-window rate limit per IP hash. */
	public static function rate_limited( $bucket, $ip_hash, $max, $window ) {
		$key   = 'slr_rl_' . $bucket . '_' . substr( $ip_hash, 0, 20 );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return true;
		}
		set_transient( $key, $count + 1, $window );
		return false;
	}

	public static function type_labels() {
		return array(
			'whatsapp' => 'واتساب',
			'call'     => 'اتصال',
			'form'     => 'فورم',
		);
	}

	public static function placement_labels() {
		return array(
			'floating' => 'زر عائم',
			'header'   => 'الهيدر',
			'footer'   => 'الفوتر',
			'content'  => 'داخل المحتوى',
			'form'     => 'فورم',
		);
	}

	public static function status_labels() {
		return array(
			'new'        => 'جديد',
			'contacted'  => 'تم التواصل',
			'interested' => 'مهتم',
			'won'        => 'تم التوريد / البيع',
			'lost'       => 'غير مهتم',
			'spam'       => 'سبام',
		);
	}

	public static function source_label( $source, $medium ) {
		$map = array(
			'google'    => 'Google',
			'facebook'  => 'Facebook',
			'instagram' => 'Instagram',
			'tiktok'    => 'TikTok',
			'snapchat'  => 'Snapchat',
			'linkedin'  => 'LinkedIn',
			'bing'      => 'Bing',
			'x'         => 'X',
			'direct'    => 'دخول مباشر',
		);
		$name = isset( $map[ $source ] ) ? $map[ $source ] : ( $source ? $source : 'غير معروف' );
		$med  = array(
			'cpc'      => 'إعلان',
			'paid'     => 'إعلان',
			'organic'  => 'بحث عضوي',
			'social'   => 'سوشيال',
			'referral' => 'إحالة',
		);
		if ( $medium && isset( $med[ $medium ] ) ) {
			return $name . ' · ' . $med[ $medium ];
		}
		return $medium && 'none' !== $medium ? $name . ' · ' . $medium : $name;
	}

	/** Returns [from, to, preset] for the admin date filter, both inclusive local datetimes. */
	public static function date_range() {
		$preset = isset( $_GET['range'] ) ? sanitize_key( $_GET['range'] ) : '30d';
		$today  = wp_date( 'Y-m-d' );
		$days   = array(
			'today' => 0,
			'7d'    => 6,
			'30d'   => 29,
			'90d'   => 89,
		);
		if ( 'custom' === $preset && ! empty( $_GET['from'] ) && ! empty( $_GET['to'] ) ) {
			$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ) ? $_GET['from'] : $today;
			$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ) ? $_GET['to'] : $today;
		} else {
			if ( ! isset( $days[ $preset ] ) ) {
				$preset = '30d';
			}
			$to   = $today;
			$from = wp_date( 'Y-m-d', strtotime( $today . ' -' . $days[ $preset ] . ' days' ) );
		}
		return array( $from . ' 00:00:00', $to . ' 23:59:59', $preset );
	}

	public static function wa_link( $phone, $text = '' ) {
		$n = self::normalize_phone( $phone );
		return $n ? 'https://wa.me/' . $n . ( $text ? '?text=' . rawurlencode( $text ) : '' ) : '';
	}
}
