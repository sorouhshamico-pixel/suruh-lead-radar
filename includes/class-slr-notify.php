<?php
defined( 'ABSPATH' ) || exit;

class SLR_Notify {

	const LOG_OPTION = 'slr_notify_log';

	private static $queue = array();

	/**
	 * Runs a notification after the response has been sent, so a slow mail server or
	 * Telegram never delays the visitor's form submission.
	 */
	public static function later( $method, $args ) {
		if ( ! self::$queue ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_queue' ), 1 );
		}
		self::$queue[] = array( $method, $args );
	}

	public static function flush_queue() {
		ignore_user_abort( true );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		foreach ( self::$queue as $job ) {
			try {
				call_user_func_array( array( __CLASS__, $job[0] ), $job[1] );
			} catch ( Throwable $e ) {
				self::log( 'system', false, $e->getMessage(), $job[0] );
			}
		}
		self::$queue = array();
	}

	public static function new_lead( $lead_id ) {
		global $wpdb;
		$lead = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SLR_DB::table( 'leads' ) . ' WHERE id = %d', $lead_id ) );
		if ( ! $lead ) {
			return;
		}
		$clicks = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . SLR_DB::table( 'events' ) . " WHERE visitor_id = %d AND type <> 'form'", $lead->visitor_id )
		);

		$title = $lead->is_repeat ? '🔁 طلب جديد من عميل سابق' : '🆕 طلب جديد من الموقع';
		$lines = array(
			'الاسم: ' . $lead->name,
			'الجوال: ' . $lead->phone,
		);
		if ( $lead->email ) {
			$lines[] = 'البريد: ' . $lead->email;
		}
		if ( $lead->service ) {
			$lines[] = 'الخدمة: ' . $lead->service;
		}
		if ( $lead->message ) {
			$lines[] = 'التفاصيل: ' . $lead->message;
		}
		$lines[] = 'الصفحة: ' . ( $lead->page_title ? $lead->page_title : $lead->page_url );
		$lines[] = 'المصدر: ' . SLR_Helpers::source_label( $lead->source, $lead->medium ) . ( $lead->campaign ? ' (' . $lead->campaign . ')' : '' );
		if ( $clicks ) {
			$lines[] = 'ضغطات واتساب/اتصال سابقة لنفس الزائر: ' . $clicks;
		}

		self::send( $title, $lines, admin_url( 'admin.php?page=slr-leads&lead=' . $lead->id ), SLR_Helpers::reply_link( $lead->phone, $lead->name ) );
	}

	public static function returning_visitor( $visitor, $type, $ctx ) {
		$key = 'slr_ret_' . $visitor->id;
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 6 * HOUR_IN_SECONDS );

		$lines = array(
			'الاسم: ' . ( $visitor->name ? $visitor->name : 'غير معروف' ),
			'الجوال: ' . $visitor->phone,
			'الإجراء: ضغط ' . SLR_Helpers::type_labels()[ $type ],
			'الصفحة: ' . ( $ctx['title'] ? $ctx['title'] : $ctx['url'] ),
		);
		self::send(
			'🔥 عميل سابق رجع للموقع وتواصل مرة أخرى',
			$lines,
			admin_url( 'admin.php?page=slr-visitor&id=' . $visitor->id ),
			SLR_Helpers::wa_link( $visitor->phone )
		);
	}

	/**
	 * Sends through every enabled channel. Telegram goes first: it is fast and does not
	 * depend on the site's mail setup, so a broken mailer can never block it.
	 * Returns [channel => [ok, message]] for the settings-page test.
	 */
	private static function send( $title, $lines, $admin_url, $wa_link ) {
		$s       = SLR_Helpers::settings();
		$results = array();

		if ( ! empty( $s['telegram_enabled'] ) ) {
			try {
				$results['telegram'] = self::send_telegram( $s, $title, $lines, $admin_url, $wa_link );
			} catch ( Throwable $e ) {
				$results['telegram'] = array( false, $e->getMessage() );
			}
		}
		if ( ! empty( $s['email_enabled'] ) ) {
			try {
				$results['email'] = self::send_email( $s, $title, $lines, $admin_url, $wa_link );
			} catch ( Throwable $e ) {
				$results['email'] = array( false, $e->getMessage() );
			}
		}

		foreach ( $results as $channel => $r ) {
			self::log( $channel, $r[0], $r[1], $title );
		}
		return $results;
	}

	private static function send_telegram( $s, $title, $lines, $admin_url, $wa_link ) {
		$token = SLR_Helpers::clean_token( $s['telegram_token'] );
		$chat  = trim( (string) $s['telegram_chat_id'] );
		if ( '' === $token || '' === $chat ) {
			return array( false, 'أدخل الـ Bot Token والـ Chat ID أولًا.' );
		}

		$text = $title . "\n\n" . implode( "\n", $lines ) . "\n\n🔗 " . $admin_url;
		if ( $wa_link ) {
			$text .= "\n💬 واتساب العميل: " . $wa_link;
		}

		$res = self::telegram_api(
			$token,
			'sendMessage',
			array(
				'chat_id'                  => $chat,
				'text'                     => mb_substr( $text, 0, 4000 ),
				'disable_web_page_preview' => 'true',
			)
		);
		return $res['ok'] ? array( true, 'تم الإرسال إلى تيليجرام.' ) : array( false, self::explain_telegram_error( $res ) );
	}

	/** Calls the Bot API and normalises the answer to [ok, code, description, result]. */
	public static function telegram_api( $token, $method, $body = array() ) {
		$response = wp_remote_post(
			'https://api.telegram.org/bot' . $token . '/' . $method,
			array(
				'timeout' => 8,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'          => false,
				'code'        => 0,
				'description' => $response->get_error_message(),
				'result'      => null,
			);
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return array(
			'ok'          => ! empty( $data['ok'] ),
			'code'        => (int) wp_remote_retrieve_response_code( $response ),
			'description' => isset( $data['description'] ) ? (string) $data['description'] : '',
			'result'      => isset( $data['result'] ) ? $data['result'] : null,
		);
	}

	private static function explain_telegram_error( $res ) {
		$d = strtolower( $res['description'] );
		if ( 0 === $res['code'] ) {
			return 'السيرفر لم يستطع الاتصال بتيليجرام (' . $res['description'] . '). غالبًا جدار حماية الاستضافة يمنع الاتصال بـ api.telegram.org — اطلب من الدعم الفني السماح به.';
		}
		if ( 401 === $res['code'] || 404 === $res['code'] ) {
			return 'الـ Bot Token غير صحيح. انسخه من جديد من @BotFather كاملًا (رقم ثم : ثم حروف).';
		}
		if ( false !== strpos( $d, 'chat not found' ) ) {
			return 'الـ Chat ID غير صحيح، أو لم تضغط «Start» في البوت بعد. افتح البوت في تيليجرام واضغط Start ثم استخدم زر «جلب Chat ID تلقائيًا».';
		}
		if ( false !== strpos( $d, 'blocked' ) || false !== strpos( $d, "can't initiate" ) || false !== strpos( $d, 'initiate conversation' ) ) {
			return 'البوت لا يستطيع مراسلتك لأنك لم تبدأ محادثة معه أو قمت بحظره. افتح البوت واضغط Start.';
		}
		if ( false !== strpos( $d, 'not enough rights' ) || false !== strpos( $d, 'kicked' ) ) {
			return 'البوت ليس عضوًا في المجموعة/القناة أو لا يملك صلاحية الإرسال. أضفه للمجموعة (وكمشرف في القنوات).';
		}
		return 'رفض تيليجرام الرسالة: ' . $res['description'] . ' (كود ' . $res['code'] . ')';
	}

	private static function send_email( $s, $title, $lines, $admin_url, $wa_link ) {
		if ( ! is_email( $s['notify_email'] ) ) {
			return array( false, 'البريد المستلم غير صحيح.' );
		}

		$body  = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;font-size:15px;line-height:1.8">';
		$body .= '<h2 style="margin:0 0 12px">' . esc_html( $title ) . '</h2>';
		foreach ( $lines as $line ) {
			$body .= '<div>' . nl2br( esc_html( $line ) ) . '</div>';
		}
		$body .= '<p style="margin-top:16px"><a href="' . esc_url( $admin_url ) . '">فتح في لوحة التحكم</a>';
		if ( $wa_link ) {
			$body .= ' · <a href="' . esc_url( $wa_link ) . '">مراسلة العميل على واتساب</a>';
		}
		$body .= '</p></div>';

		$error   = '';
		$catcher = function ( $wp_error ) use ( &$error ) {
			$error = $wp_error->get_error_message();
		};
		add_action( 'wp_mail_failed', $catcher );
		$sent = wp_mail( $s['notify_email'], $title . ' — ' . SLR_Helpers::business_name(), $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		remove_action( 'wp_mail_failed', $catcher );

		if ( $sent ) {
			$note = SLR_Helpers::mailer_plugin() ? '' : ' (تنبيه: لا توجد إضافة SMTP — قد تصل الرسائل إلى السبام)';
			return array( true, 'قبل ووردبريس إرسال البريد إلى ' . $s['notify_email'] . $note . '.' );
		}
		$mailer = SLR_Helpers::mailer_plugin();
		$hint   = $mailer
			? 'راجع إعدادات وسجل إضافة «' . $mailer . '» — غالبًا غير مربوطة أو المرسل غير موثّق.'
			: 'الاستضافة لا ترسل البريد مباشرة. ثبّت إضافة SMTP مثل WP Mail SMTP أو FluentSMTP واربطها ببريد حقيقي.';
		return array( false, 'فشل إرسال البريد' . ( $error ? ': ' . $error : '' ) . '. ' . $hint );
	}

	private static function log( $channel, $ok, $message, $title ) {
		$log = get_option( self::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'time'    => SLR_Helpers::now(),
				'channel' => $channel,
				'ok'      => (bool) $ok,
				'message' => $message,
				'title'   => $title,
			)
		);
		update_option( self::LOG_OPTION, array_slice( $log, 0, 20 ), false );
	}

	/** Used by the settings page "send test" button. */
	public static function test() {
		return self::send( '✅ رسالة تجريبية من رادار العملاء', array( 'التنبيهات تعمل بشكل صحيح.' ), admin_url( 'admin.php?page=slr-dashboard' ), '' );
	}

	/**
	 * Finds the chat ID of whoever last messaged the bot, so the user does not have to
	 * look it up. Returns [chat_id|null, message].
	 */
	public static function detect_chat_id( $token ) {
		$token = SLR_Helpers::clean_token( $token );
		if ( '' === $token ) {
			return array( null, 'أدخل الـ Bot Token واحفظ أولًا.' );
		}
		$res = self::telegram_api( $token, 'getUpdates', array( 'limit' => 50 ) );
		if ( ! $res['ok'] ) {
			if ( 409 === $res['code'] ) {
				return array( null, 'هذا البوت مربوط بـ Webhook لخدمة أخرى، فلا يمكن قراءة رسائله. أنشئ بوتًا جديدًا خاصًا بالتنبيهات.' );
			}
			return array( null, self::explain_telegram_error( $res ) );
		}
		$updates = is_array( $res['result'] ) ? array_reverse( $res['result'] ) : array();
		foreach ( $updates as $u ) {
			foreach ( array( 'message', 'channel_post', 'my_chat_member', 'edited_message' ) as $k ) {
				if ( isset( $u[ $k ]['chat']['id'] ) ) {
					$chat = $u[ $k ]['chat'];
					$name = isset( $chat['title'] ) ? $chat['title'] : trim( ( isset( $chat['first_name'] ) ? $chat['first_name'] : '' ) . ' ' . ( isset( $chat['last_name'] ) ? $chat['last_name'] : '' ) );
					return array( (string) $chat['id'], 'تم العثور على المحادثة: ' . $name );
				}
			}
		}
		return array( null, 'لم يصل البوت أي رسالة بعد. افتح البوت في تيليجرام، اضغط Start وأرسل أي كلمة، ثم اضغط الزر مرة أخرى.' );
	}
}
