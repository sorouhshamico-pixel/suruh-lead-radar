<?php
defined( 'ABSPATH' ) || exit;

/**
 * Self-diagnosis for any WordPress install: finds what would silently stop tracking
 * (blocked REST API, script removed by a cache/optimizer, broken mail…) and fixes what it
 * safely can (switches to the admin-ajax transport when REST is blocked).
 */
class SLR_Health {

	const RESULT = 'slr_health_result';

	private static function remote_args( $extra = array() ) {
		return array_merge(
			array(
				'timeout'    => 12,
				'sslverify'  => false, // loopback on hosts with self-signed or mismatched certs.
				'user-agent' => 'Mozilla/5.0 (LeadRadar health check)',
				'headers'    => array( 'Cache-Control' => 'no-cache' ),
			),
			$extra
		);
	}

	public static function run() {
		global $wpdb;
		$checks = array();

		// 1. Database tables.
		$missing = array();
		foreach ( array( 'visitors', 'events', 'leads' ) as $t ) {
			if ( SLR_DB::table( $t ) !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', SLR_DB::table( $t ) ) ) ) {
				$missing[] = $t;
			}
		}
		if ( $missing ) {
			SLR_DB::install();
			$checks[] = array( 'warn', 'قاعدة البيانات', 'كانت بعض الجداول ناقصة (' . implode( ', ', $missing ) . ') وتمت إعادة إنشائها.' );
		} else {
			$checks[] = array( 'ok', 'قاعدة البيانات', 'الجداول الثلاثة موجودة.' );
		}

		// 2. Receiving endpoint.
		$checks[] = self::check_transport();

		// 3. Tracker present on the public homepage.
		$checks[] = self::check_tracker();

		// 4. Last data received.
		$last = $wpdb->get_var( 'SELECT MAX(created_at) FROM ' . SLR_DB::table( 'events' ) );
		if ( $last ) {
			$hours    = ( current_time( 'timestamp' ) - strtotime( $last ) ) / HOUR_IN_SECONDS;
			$checks[] = array( $hours > 72 ? 'warn' : 'ok', 'آخر بيانات وصلت', 'آخر ضغطة/طلب مسجّل: ' . $last . ( $hours > 72 ? ' — لم يصل شيء منذ أكثر من 3 أيام، تأكد من البنود أعلاه.' : '' ) );
		} else {
			$checks[] = array( 'warn', 'آخر بيانات وصلت', 'لم تصل أي ضغطة بعد. افتح الموقع من الجوال أو نافذة خاصة (بدون تسجيل دخول) واضغط زر واتساب ثم أعد الفحص.' );
		}

		// 5. Email delivery.
		$mailer = SLR_Helpers::mailer_plugin();
		if ( SLR_Helpers::setting( 'email_enabled' ) ) {
			$checks[] = $mailer
				? array( 'ok', 'البريد', 'الإرسال يتم عبر «' . $mailer . '». إذا لم تصل الرسائل راجع سجل هذه الإضافة وتأكد أنها مربوطة وأن بريد المرسل موثّق.' )
				: array( 'warn', 'البريد', 'لا توجد إضافة SMTP، والبريد يُرسل عبر خادم الاستضافة مباشرة وغالبًا يصل للسبام أو لا يصل. ثبّت WP Mail SMTP أو FluentSMTP.' );
		}

		// 6. Telegram.
		if ( SLR_Helpers::setting( 'telegram_enabled' ) ) {
			$checks[] = self::check_telegram();
		}

		// 7. Form plugins captured server-side.
		$forms = SLR_Integrations::detected();
		$checks[] = array(
			'ok',
			'الفورمات',
			( $forms && SLR_Helpers::setting( 'capture_plugin_forms' ) ? 'يتم التقاط فورمات: ' . implode( '، ', $forms ) . ' تلقائيًا. ' : '' ) . 'الفورمات المخصصة المتتبَّعة بالمحدد: ' . SLR_Helpers::setting( 'form_selectors' ),
		);

		// 8. Daily cleanup cron.
		if ( ! wp_next_scheduled( 'slr_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'slr_daily_cleanup' );
		}

		$result = array(
			'time'   => SLR_Helpers::now(),
			'checks' => $checks,
		);
		update_option( self::RESULT, $result, false );
		return $result;
	}

	/**
	 * REST can be blocked for visitors by security plugins (Wordfence, iThemes, "Disable REST
	 * API"…). When it is and admin-ajax works, switch the tracker to admin-ajax.
	 */
	private static function check_transport() {
		$rest      = wp_remote_get( rest_url( SLR_Rest::NS . '/ping' ), self::remote_args() );
		$rest_code = is_wp_error( $rest ) ? 0 : (int) wp_remote_retrieve_response_code( $rest );
		$rest_ok   = 200 === $rest_code && false !== strpos( wp_remote_retrieve_body( $rest ), '"slr"' );

		if ( $rest_ok ) {
			if ( 'ajax' === get_option( 'slr_transport' ) ) {
				update_option( 'slr_transport', 'rest', true );
				return array( 'ok', 'استقبال البيانات', 'REST API يعمل الآن، وتمت إعادة التتبع إليه.' );
			}
			return array( 'ok', 'استقبال البيانات', 'REST API يعمل (' . rest_url( SLR_Rest::NS . '/' ) . ').' );
		}

		$ajax    = wp_remote_post( admin_url( 'admin-ajax.php?action=slr_e' ), self::remote_args( array( 'body' => '{}' ) ) );
		$ajax_ok = ! is_wp_error( $ajax ) && 200 === (int) wp_remote_retrieve_response_code( $ajax ) && false !== strpos( wp_remote_retrieve_body( $ajax ), '"ok"' );

		if ( in_array( $rest_code, array( 401, 403, 404, 405, 503 ), true ) && $ajax_ok ) {
			update_option( 'slr_transport', 'ajax', true );
			return array( 'warn', 'استقبال البيانات', 'REST API محجوب للزوار (كود ' . $rest_code . ') — غالبًا بسبب إضافة حماية. تم التحويل تلقائيًا إلى admin-ajax والتتبع يعمل. امسح الكاش ليظهر التغيير.' );
		}
		if ( ! $rest_code && ! $ajax_ok ) {
			$msg = is_wp_error( $rest ) ? $rest->get_error_message() : '';
			return array( 'warn', 'استقبال البيانات', 'لم يستطع الموقع فحص نفسه (' . $msg . '). هذا شائع في بعض الاستضافات ولا يعني أن التتبع متوقف — تأكد من «آخر بيانات وصلت».' );
		}
		if ( $ajax_ok ) {
			update_option( 'slr_transport', 'ajax', true );
			return array( 'warn', 'استقبال البيانات', 'REST API لا يستجيب بشكل صحيح (كود ' . $rest_code . '). تم التحويل إلى admin-ajax.' );
		}
		return array( 'fail', 'استقبال البيانات', 'REST API و admin-ajax كلاهما محجوب (كود ' . $rest_code . '). اسمح بالمسار /wp-json/slr/v1/ في إضافة الحماية أو جدار الاستضافة.' );
	}

	private static function check_tracker() {
		$res = wp_remote_get( add_query_arg( 'slr_check', time(), home_url( '/' ) ), self::remote_args() );
		if ( is_wp_error( $res ) ) {
			return array( 'warn', 'سكربت التتبع', 'تعذر فتح الصفحة الرئيسية للفحص (' . $res->get_error_message() . '). افتح مصدر الصفحة وابحث عن tracker.js يدويًا.' );
		}
		$html = wp_remote_retrieve_body( $res );
		if ( false === strpos( $html, 'assets/tracker.js' ) && false === strpos( $html, 'SLR_CFG' ) ) {
			return array( 'fail', 'سكربت التتبع', 'السكربت غير موجود في الصفحة الرئيسية. امسح كاش الموقع (وكاش Cloudflare إن وجد)، وتأكد أن الثيم يستدعي wp_footer().' );
		}
		if ( preg_match( '/<script[^>]+(type="(?:litespeed|rocketlazyloadscript|text\/rocketlazyloadscript|pmdelayedscript)[^"]*")[^>]*tracker\.js/i', $html ) ) {
			return array( 'warn', 'سكربت التتبع', 'السكربت موجود لكن إضافة تسريع تؤخر تشغيله حتى أول تفاعل. أضف tracker.js إلى استثناءات «Delay JS» في إضافة الكاش.' );
		}
		return array( 'ok', 'سكربت التتبع', 'السكربت يعمل في الصفحة الرئيسية بدون تأخير.' );
	}

	private static function check_telegram() {
		$token = SLR_Helpers::clean_token( SLR_Helpers::setting( 'telegram_token' ) );
		if ( '' === $token ) {
			return array( 'fail', 'تيليجرام', 'التنبيه مفعّل لكن الـ Bot Token فارغ.' );
		}
		$me = SLR_Notify::telegram_api( $token, 'getMe' );
		if ( ! $me['ok'] ) {
			if ( 0 === $me['code'] ) {
				return array( 'fail', 'تيليجرام', 'السيرفر لا يستطيع الوصول إلى api.telegram.org (' . $me['description'] . '). اطلب من الاستضافة السماح بالاتصال الخارجي.' );
			}
			return array( 'fail', 'تيليجرام', 'الـ Bot Token غير صحيح (' . $me['description'] . ').' );
		}
		$bot = isset( $me['result']['username'] ) ? '@' . $me['result']['username'] : '';
		if ( '' === trim( (string) SLR_Helpers::setting( 'telegram_chat_id' ) ) ) {
			return array( 'warn', 'تيليجرام', 'البوت ' . $bot . ' سليم لكن الـ Chat ID فارغ. استخدم زر «جلب Chat ID تلقائيًا».' );
		}
		return array( 'ok', 'تيليجرام', 'البوت ' . $bot . ' سليم ومتصل. اضغط «إرسال تنبيه تجريبي» للتأكد من وصول الرسائل.' );
	}
}
