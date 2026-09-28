<?php
defined( 'ABSPATH' ) || exit;

class SLR_Admin {

	const PER_PAGE = 50;

	public static function cap() {
		return apply_filters( 'slr_capability', 'manage_options' );
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_slr_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_slr_update_lead', array( __CLASS__, 'update_lead' ) );
		add_action( 'admin_post_slr_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_slr_test_notify', array( __CLASS__, 'test_notify' ) );
		add_action( 'admin_post_slr_health', array( __CLASS__, 'health' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer_credit' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'plugin_row_meta' ), 10, 2 );
	}

	/* --------------------------------------------------------------- credit */

	private static function developer_links() {
		return array(
			array( 'معرض الأعمال', 'مستقل', 'https://mostaql.com/u/M1_m2/portfolio', '💼' ),
			array( 'GitHub', 'المشاريع البرمجية', 'https://github.com/mgkh286?tab=repositories', '🐙' ),
			array( 'السيرة الذاتية', 'Resume', 'https://mohamedkhalifa.netlify.app', '📄' ),
		);
	}

	/** Developer credit in the footer of the plugin's own screens; the name opens a card of links. */
	public static function footer_credit( $text ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'slr-' ) ) {
			return $text;
		}
		$html = '<span class="slr-credit">تطوير <details class="slr-dev"><summary>محمد خليفة</summary><span class="slr-dev-card">';
		$html .= '<span class="slr-dev-head"><span class="slr-dev-avatar">م</span><span><strong>محمد خليفة</strong><small>مطوّر ووردبريس وحلول الويب</small></span></span>';
		foreach ( self::developer_links() as $l ) {
			$html .= '<a href="' . esc_url( $l[2] ) . '" target="_blank" rel="noopener"><span class="slr-dev-ico">' . $l[3] . '</span><span><b>' . esc_html( $l[0] ) . '</b><small>' . esc_html( $l[1] ) . '</small></span><span class="slr-dev-arrow">↗</span></a>';
		}
		return $html . '</span></details> · رادار العملاء ' . esc_html( SLR_VERSION ) . '</span>';
	}

	public static function plugin_row_meta( $links, $file ) {
		if ( plugin_basename( SLR_FILE ) === $file ) {
			foreach ( self::developer_links() as $l ) {
				$links[] = '<a href="' . esc_url( $l[2] ) . '" target="_blank" rel="noopener">' . esc_html( $l[0] ) . '</a>';
			}
		}
		return $links;
	}

	/* ------------------------------------------------------------------ menu */

	public static function menu() {
		global $wpdb;
		$new   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SLR_DB::table( 'leads' ) . " WHERE status = 'new'" );
		$badge = $new ? ' <span class="awaiting-mod">' . $new . '</span>' : '';

		add_menu_page( 'رادار العملاء', 'رادار العملاء' . $badge, self::cap(), 'slr-dashboard', array( __CLASS__, 'page_dashboard' ), 'dashicons-chart-area', 3 );
		add_submenu_page( 'slr-dashboard', 'الإحصائيات', 'الإحصائيات', self::cap(), 'slr-dashboard', array( __CLASS__, 'page_dashboard' ) );
		add_submenu_page( 'slr-dashboard', 'طلبات الفورم', 'طلبات الفورم' . $badge, self::cap(), 'slr-leads', array( __CLASS__, 'page_leads' ) );
		add_submenu_page( 'slr-dashboard', 'سجل الضغطات', 'سجل الضغطات', self::cap(), 'slr-clicks', array( __CLASS__, 'page_clicks' ) );
		add_submenu_page( 'slr-dashboard', 'الإعدادات', 'الإعدادات', self::cap(), 'slr-settings', array( __CLASS__, 'page_settings' ) );
		// Hidden page (reachable by link only).
		add_submenu_page( 'options.php', 'ملف الزائر', 'ملف الزائر', self::cap(), 'slr-visitor', array( __CLASS__, 'page_visitor' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'slr-' ) && 'index.php' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'slr-admin', SLR_URL . 'assets/admin.css', array(), SLR_VERSION );
	}

	/* --------------------------------------------------------------- helpers */

	private static function url( $page, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	private static function fmt_date( $mysql ) {
		$ts = strtotime( $mysql );
		return $ts ? date_i18n( 'Y/m/d · H:i', $ts ) : '';
	}

	private static function type_badge( $type ) {
		$labels = SLR_Helpers::type_labels();
		return '<span class="slr-badge slr-t-' . esc_attr( $type ) . '">' . esc_html( isset( $labels[ $type ] ) ? $labels[ $type ] : $type ) . '</span>';
	}

	private static function repeat_badge( $row ) {
		if ( ! empty( $row->is_repeat ) ) {
			return '<span class="slr-badge slr-repeat" title="تواصل من قبل">🔁 مكرر</span>';
		}
		if ( ! empty( $row->possible_repeat ) ) {
			return '<span class="slr-badge slr-maybe" title="نفس الشبكة والمتصفح لزائر آخر خلال 7 أيام">مكرر محتمل</span>';
		}
		return '<span class="slr-badge slr-new">جديد</span>';
	}

	private static function status_badge( $status ) {
		$labels = SLR_Helpers::status_labels();
		return '<span class="slr-badge slr-s-' . esc_attr( $status ) . '">' . esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ) . '</span>';
	}

	private static function page_cell( $url, $title ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$text = $title ? $title : ( $path ? urldecode( $path ) : $url );
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" title="' . esc_attr( urldecode( (string) $path ) ) . '">' . esc_html( wp_trim_words( $text, 8, '…' ) ) . '</a>';
	}

	private static function range_form( $page, $extra = '' ) {
		list( $from, $to, $preset ) = SLR_Helpers::date_range();
		$presets = array(
			'today'  => 'اليوم',
			'7d'     => '7 أيام',
			'30d'    => '30 يوم',
			'90d'    => '90 يوم',
			'custom' => 'مخصص',
		);
		echo '<form method="get" class="slr-filters"><input type="hidden" name="page" value="' . esc_attr( $page ) . '">';
		echo '<select name="range" onchange="this.form.querySelector(\'.slr-custom\').style.display=this.value===\'custom\'?\'inline-flex\':\'none\'">';
		foreach ( $presets as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $preset, $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select>';
		echo '<span class="slr-custom" style="display:' . ( 'custom' === $preset ? 'inline-flex' : 'none' ) . '">';
		echo '<input type="date" name="from" value="' . esc_attr( substr( $from, 0, 10 ) ) . '"> — <input type="date" name="to" value="' . esc_attr( substr( $to, 0, 10 ) ) . '"></span>';
		echo $extra; // phpcs:ignore -- built from escaped parts by callers.
		echo '<button class="button">تطبيق</button></form>';
	}

	private static function select_filter( $name, $options, $all_label ) {
		$current = isset( $_GET[ $name ] ) ? sanitize_key( $_GET[ $name ] ) : '';
		$html    = '<select name="' . esc_attr( $name ) . '"><option value="">' . esc_html( $all_label ) . '</option>';
		foreach ( $options as $k => $v ) {
			$html .= '<option value="' . esc_attr( $k ) . '"' . selected( $current, $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		return $html . '</select>';
	}

	private static function pagination( $total, $paged ) {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages < 2 ) {
			return;
		}
		echo '<div class="slr-pagination">' . paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $pages,
				'prev_text' => '→',
				'next_text' => '←',
			)
		) . '</div>';
	}

	private static function notice() {
		if ( isset( $_GET['slr_msg'] ) ) {
			$msgs = array(
				'saved'   => 'تم الحفظ.',
				'updated' => 'تم تحديث الطلب.',
				'tested'  => 'تم إرسال رسالة تجريبية. تحقق من البريد / تيليجرام.',
			);
			$k = sanitize_key( $_GET['slr_msg'] );
			if ( isset( $msgs[ $k ] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msgs[ $k ] ) . '</p></div>';
			}
		}
	}

	/* ------------------------------------------------------------- dashboard */

	private static function type_totals( $from, $to ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT type, COUNT(*) total, COUNT(DISTINCT visitor_id) people FROM ' . SLR_DB::table( 'events' ) . ' WHERE created_at BETWEEN %s AND %s GROUP BY type',
				$from,
				$to
			),
			OBJECT_K
		);
		$out = array();
		foreach ( array( 'whatsapp', 'call', 'form' ) as $t ) {
			$out[ $t ] = isset( $rows[ $t ] ) ? (int) $rows[ $t ]->total : 0;
		}
		return $out;
	}

	private static function delta( $now, $before ) {
		if ( ! $before ) {
			return $now ? '<span class="slr-delta up">جديد</span>' : '';
		}
		$pct = round( ( $now - $before ) / $before * 100 );
		$cls = $pct >= 0 ? 'up' : 'down';
		return '<span class="slr-delta ' . $cls . '">' . ( $pct >= 0 ? '▲ ' : '▼ ' ) . abs( $pct ) . '%</span>';
	}

	public static function page_dashboard() {
		global $wpdb;
		list( $from, $to ) = SLR_Helpers::date_range();
		$events = SLR_DB::table( 'events' );
		$span   = strtotime( $to ) - strtotime( $from ) + 1;
		$pfrom  = gmdate( 'Y-m-d H:i:s', strtotime( $from ) - $span );
		$pto    = gmdate( 'Y-m-d H:i:s', strtotime( $from ) - 1 );

		$now  = self::type_totals( $from, $to );
		$prev = self::type_totals( $pfrom, $pto );

		$people = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT visitor_id) total,
					COUNT(DISTINCT CASE WHEN is_repeat = 1 THEN visitor_id END) repeaters
				FROM $events WHERE created_at BETWEEN %s AND %s",
				$from,
				$to
			)
		);
		$total_people = (int) $people->total;
		$repeaters    = (int) $people->repeaters;

		echo '<div class="wrap slr-wrap"><h1 class="slr-title">📡 رادار العملاء <small>من تواصل معك، ومن أين، وهل هو عميل جديد</small></h1>';
		self::range_form( 'slr-dashboard' );

		$cards = array(
			array( 'whatsapp', 'ضغطات واتساب', $now['whatsapp'], $prev['whatsapp'] ),
			array( 'call', 'ضغطات اتصال', $now['call'], $prev['call'] ),
			array( 'form', 'طلبات الفورم', $now['form'], $prev['form'] ),
		);
		echo '<div class="slr-cards">';
		foreach ( $cards as $c ) {
			echo '<div class="slr-card slr-c-' . esc_attr( $c[0] ) . '"><div class="slr-card-label">' . esc_html( $c[1] ) . '</div><div class="slr-card-value">' . number_format_i18n( $c[2] ) . '</div>' . self::delta( $c[2], $c[3] ) . '</div>';
		}
		echo '<div class="slr-card"><div class="slr-card-label">أشخاص تواصلوا</div><div class="slr-card-value">' . number_format_i18n( $total_people ) . '</div><span class="slr-card-sub">منهم ' . number_format_i18n( $repeaters ) . ' رجعوا للتواصل مرة أخرى 🔁</span></div>';
		echo '</div>';

		// Daily chart.
		$daily = $wpdb->get_results(
			$wpdb->prepare( "SELECT DATE(created_at) d, type, COUNT(*) c FROM $events WHERE created_at BETWEEN %s AND %s GROUP BY d, type", $from, $to )
		);
		echo '<div class="slr-panel"><h2>التواصل اليومي</h2>' . self::daily_chart( $daily, $from, $to ) . '</div>';

		echo '<div class="slr-grid">';

		// Top pages.
		$pages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT page_path, MAX(page_title) title, MAX(page_url) url,
					SUM(type = 'whatsapp') wa, SUM(type = 'call') calls, SUM(type = 'form') forms, COUNT(*) total
				FROM $events WHERE created_at BETWEEN %s AND %s GROUP BY page_path ORDER BY total DESC LIMIT 10",
				$from,
				$to
			)
		);
		echo '<div class="slr-panel"><h2>أكثر الصفحات جلبًا للعملاء</h2>';
		if ( $pages ) {
			echo '<table class="slr-table"><thead><tr><th>الصفحة</th><th>واتساب</th><th>اتصال</th><th>فورم</th><th>المجموع</th></tr></thead><tbody>';
			foreach ( $pages as $p ) {
				echo '<tr><td>' . self::page_cell( $p->url, $p->title ) . '</td><td>' . (int) $p->wa . '</td><td>' . (int) $p->calls . '</td><td>' . (int) $p->forms . '</td><td><strong>' . (int) $p->total . '</strong></td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo self::empty_state();
		}
		echo '</div>';

		// Buttons / placements.
		$places = $wpdb->get_results(
			$wpdb->prepare( "SELECT placement, type, COUNT(*) c FROM $events WHERE created_at BETWEEN %s AND %s AND type <> 'form' GROUP BY placement, type ORDER BY c DESC", $from, $to )
		);
		$pl = SLR_Helpers::placement_labels();
		$tl = SLR_Helpers::type_labels();
		$items = array();
		foreach ( $places as $p ) {
			$items[] = array( ( isset( $pl[ $p->placement ] ) ? $pl[ $p->placement ] : $p->placement ) . ' · ' . $tl[ $p->type ], (int) $p->c, $p->type );
		}
		echo '<div class="slr-panel"><h2>أي الأزرار أكثر ضغطًا؟</h2>' . self::bars( $items ) . '</div>';

		// Sources.
		$sources = $wpdb->get_results(
			$wpdb->prepare( "SELECT source, medium, COUNT(*) c, SUM(type = 'form') forms FROM $events WHERE created_at BETWEEN %s AND %s GROUP BY source, medium ORDER BY c DESC LIMIT 10", $from, $to )
		);
		$items = array();
		foreach ( $sources as $s ) {
			$items[] = array( SLR_Helpers::source_label( $s->source, $s->medium ) . ( $s->forms ? ' · ' . (int) $s->forms . ' فورم' : '' ), (int) $s->c, 'source' );
		}
		echo '<div class="slr-panel"><h2>مصادر العملاء</h2>' . self::bars( $items ) . '</div>';

		// Devices.
		$devices = $wpdb->get_results(
			$wpdb->prepare( "SELECT device, COUNT(*) c FROM $events WHERE created_at BETWEEN %s AND %s GROUP BY device ORDER BY c DESC", $from, $to )
		);
		$dl    = array( 'mobile' => '📱 جوال', 'desktop' => '💻 كمبيوتر', 'tablet' => 'تابلت' );
		$items = array();
		foreach ( $devices as $d ) {
			$items[] = array( isset( $dl[ $d->device ] ) ? $dl[ $d->device ] : $d->device, (int) $d->c, 'device' );
		}
		echo '<div class="slr-panel"><h2>الأجهزة</h2>' . self::bars( $items ) . '</div>';

		echo '</div>';

		// Heatmap.
		$heat = $wpdb->get_results(
			$wpdb->prepare( "SELECT DAYOFWEEK(created_at) dow, HOUR(created_at) h, COUNT(*) c FROM $events WHERE created_at BETWEEN %s AND %s GROUP BY dow, h", $from, $to )
		);
		echo '<div class="slr-panel"><h2>أوقات الذروة <small>(متى يتواصل العملاء — بتوقيت الموقع)</small></h2>' . self::heatmap( $heat ) . '</div>';

		// Latest leads.
		$latest = $wpdb->get_results( 'SELECT * FROM ' . SLR_DB::table( 'leads' ) . ' ORDER BY id DESC LIMIT 8' );
		echo '<div class="slr-panel"><h2>آخر الطلبات <a class="button button-small" href="' . esc_url( self::url( 'slr-leads' ) ) . '">عرض الكل</a></h2>';
		self::leads_table( $latest );
		echo '</div></div>';
	}

	private static function empty_state() {
		return '<p class="slr-empty">لا توجد بيانات في هذه الفترة بعد.</p>';
	}

	private static function bars( $items ) {
		if ( ! $items ) {
			return self::empty_state();
		}
		$max  = max( array_column( $items, 1 ) );
		$html = '<div class="slr-bars">';
		foreach ( $items as $it ) {
			$w     = $max ? max( 2, round( $it[1] / $max * 100 ) ) : 0;
			$html .= '<div class="slr-bar-row"><span class="slr-bar-label">' . esc_html( $it[0] ) . '</span><span class="slr-bar-track"><span class="slr-bar slr-b-' . esc_attr( $it[2] ) . '" style="width:' . $w . '%"></span></span><span class="slr-bar-val">' . number_format_i18n( $it[1] ) . '</span></div>';
		}
		return $html . '</div>';
	}

	private static function daily_chart( $rows, $from, $to ) {
		$days = array();
		for ( $t = strtotime( substr( $from, 0, 10 ) ); $t <= strtotime( substr( $to, 0, 10 ) ); $t += DAY_IN_SECONDS ) {
			$days[ gmdate( 'Y-m-d', $t ) ] = array( 'whatsapp' => 0, 'call' => 0, 'form' => 0 );
		}
		foreach ( $rows as $r ) {
			if ( isset( $days[ $r->d ][ $r->type ] ) ) {
				$days[ $r->d ][ $r->type ] = (int) $r->c;
			}
		}
		$max = 0;
		foreach ( $days as $d ) {
			$max = max( $max, array_sum( $d ) );
		}
		if ( ! $max ) {
			return self::empty_state();
		}

		$n     = count( $days );
		$w     = 1000;
		$h     = 220;
		$pad   = 24;
		$slot  = $w / $n;
		$bar   = max( 2, $slot * 0.7 );
		$color = array( 'whatsapp' => '#25D366', 'call' => '#2271b1', 'form' => '#e0a800' );
		$tl    = SLR_Helpers::type_labels();
		$svg   = '<svg class="slr-chart" viewBox="0 0 ' . $w . ' ' . ( $h + $pad ) . '" preserveAspectRatio="none" role="img">';
		$svg  .= '<line x1="0" y1="' . $h . '" x2="' . $w . '" y2="' . $h . '" stroke="#dcdcde"/>';
		$i     = 0;
		foreach ( $days as $date => $d ) {
			$x = $w - ( $i + 1 ) * $slot + ( $slot - $bar ) / 2; // RTL: oldest on the right.
			$y = $h;
			foreach ( array( 'whatsapp', 'call', 'form' ) as $t ) {
				if ( ! $d[ $t ] ) {
					continue;
				}
				$bh   = $d[ $t ] / $max * ( $h - 10 );
				$y   -= $bh;
				$svg .= '<rect x="' . round( $x, 1 ) . '" y="' . round( $y, 1 ) . '" width="' . round( $bar, 1 ) . '" height="' . round( $bh, 1 ) . '" fill="' . $color[ $t ] . '"><title>' . esc_html( $date . ' · ' . $tl[ $t ] . ': ' . $d[ $t ] ) . '</title></rect>';
			}
			if ( $n <= 31 || 0 === $i % 7 ) {
				$svg .= '<text x="' . round( $x + $bar / 2, 1 ) . '" y="' . ( $h + 16 ) . '" font-size="11" text-anchor="middle" fill="#646970">' . esc_html( gmdate( 'j/n', strtotime( $date ) ) ) . '</text>';
			}
			$i++;
		}
		$svg .= '</svg>';

		$legend = '<div class="slr-legend">';
		foreach ( $color as $t => $c ) {
			$legend .= '<span><i style="background:' . $c . '"></i>' . esc_html( $tl[ $t ] ) . '</span>';
		}
		return $svg . $legend . '<span>أعلى يوم: ' . $max . '</span></div>';
	}

	private static function heatmap( $rows ) {
		if ( ! $rows ) {
			return self::empty_state();
		}
		$grid = array();
		$max  = 0;
		foreach ( $rows as $r ) {
			$grid[ (int) $r->dow ][ (int) $r->h ] = (int) $r->c;
			$max                                  = max( $max, (int) $r->c );
		}
		$names = array( 1 => 'الأحد', 2 => 'الإثنين', 3 => 'الثلاثاء', 4 => 'الأربعاء', 5 => 'الخميس', 6 => 'الجمعة', 7 => 'السبت' );
		$html  = '<div class="slr-heat-wrap"><table class="slr-heat"><thead><tr><th></th>';
		for ( $h = 0; $h < 24; $h++ ) {
			$html .= '<th>' . $h . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $names as $dow => $name ) {
			$html .= '<tr><th>' . $name . '</th>';
			for ( $h = 0; $h < 24; $h++ ) {
				$c     = isset( $grid[ $dow ][ $h ] ) ? $grid[ $dow ][ $h ] : 0;
				$a     = $c ? 0.12 + 0.88 * $c / $max : 0;
				$html .= '<td style="background:rgba(34,113,177,' . round( $a, 2 ) . ')" title="' . esc_attr( $name . ' ' . $h . ':00 — ' . $c ) . '">' . ( $c ? $c : '' ) . '</td>';
			}
			$html .= '</tr>';
		}
		return $html . '</tbody></table></div>';
	}

	/* ----------------------------------------------------------------- leads */

	private static function leads_table( $rows ) {
		if ( ! $rows ) {
			echo self::empty_state();
			return;
		}
		echo '<table class="slr-table widefat striped"><thead><tr><th>التاريخ</th><th>الاسم</th><th>الجوال</th><th>الخدمة</th><th>الصفحة</th><th>المصدر</th><th>العميل</th><th>الحالة</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $l ) {
			$wa = SLR_Helpers::reply_link( $l->phone, $l->name );
			echo '<tr>';
			echo '<td>' . esc_html( self::fmt_date( $l->created_at ) ) . '</td>';
			echo '<td><strong>' . esc_html( $l->name ) . '</strong></td>';
			echo '<td dir="ltr" class="slr-phone">' . esc_html( $l->phone ) . ( $wa ? ' <a class="slr-wa" href="' . esc_url( $wa ) . '" target="_blank" title="مراسلة على واتساب">واتساب</a>' : '' ) . '</td>';
			echo '<td>' . esc_html( $l->service ) . '</td>';
			echo '<td>' . self::page_cell( $l->page_url, $l->page_title ) . '</td>';
			echo '<td>' . esc_html( SLR_Helpers::source_label( $l->source, $l->medium ) ) . '</td>';
			echo '<td>' . self::repeat_badge( $l ) . '</td>';
			echo '<td>' . self::status_badge( $l->status ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( self::url( 'slr-leads', array( 'lead' => $l->id ) ) ) . '">فتح</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function leads_where( &$args ) {
		global $wpdb;
		list( $from, $to ) = SLR_Helpers::date_range();
		$where = $wpdb->prepare( 'created_at BETWEEN %s AND %s', $from, $to );
		if ( ! empty( $_GET['status'] ) ) {
			$where .= $wpdb->prepare( ' AND status = %s', sanitize_key( $_GET['status'] ) );
		}
		if ( ! empty( $_GET['repeat'] ) ) {
			$where .= 'yes' === $_GET['repeat'] ? ' AND is_repeat = 1' : ' AND is_repeat = 0';
		}
		if ( ! empty( $_GET['s'] ) ) {
			$s      = sanitize_text_field( wp_unslash( $_GET['s'] ) );
			$like   = '%' . $wpdb->esc_like( $s ) . '%';
			$norm   = SLR_Helpers::normalize_phone( $s );
			$where .= $wpdb->prepare( ' AND (name LIKE %s OR phone LIKE %s OR service LIKE %s OR message LIKE %s', $like, $like, $like, $like );
			$where .= $norm ? $wpdb->prepare( ' OR phone_normalized = %s)', $norm ) : ')';
		}
		$args = array( 'range', 'from', 'to', 'status', 'repeat', 's' );
		return $where;
	}

	public static function page_leads() {
		if ( ! empty( $_GET['lead'] ) ) {
			self::page_lead( absint( $_GET['lead'] ) );
			return;
		}
		global $wpdb;
		$table = SLR_DB::table( 'leads' );
		$args  = array();
		$where = self::leads_where( $args );
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ) );

		$export = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'slr_export', 'what' => 'leads' ), self::current_args( $args ) ), admin_url( 'admin-post.php' ) ), 'slr_export' );

		echo '<div class="wrap slr-wrap"><h1 class="slr-title">📝 طلبات الفورم <a class="page-title-action" href="' . esc_url( $export ) . '">تصدير Excel</a></h1>';
		self::notice();
		$extra  = self::select_filter( 'status', SLR_Helpers::status_labels(), 'كل الحالات' );
		$extra .= self::select_filter( 'repeat', array( 'no' => 'عملاء جدد', 'yes' => 'عملاء مكررين' ), 'جديد + مكرر' );
		$extra .= '<input type="search" name="s" placeholder="اسم، جوال، خدمة…" value="' . esc_attr( isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '' ) . '">';
		self::range_form( 'slr-leads', $extra );
		echo '<p class="slr-count">' . number_format_i18n( $total ) . ' طلب</p>';
		self::leads_table( $rows );
		self::pagination( $total, $paged );
		echo '</div>';
	}

	private static function current_args( $keys ) {
		$out = array();
		foreach ( $keys as $k ) {
			if ( isset( $_GET[ $k ] ) && '' !== $_GET[ $k ] ) {
				$out[ $k ] = sanitize_text_field( wp_unslash( $_GET[ $k ] ) );
			}
		}
		return $out;
	}

	private static function page_lead( $id ) {
		global $wpdb;
		$lead = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SLR_DB::table( 'leads' ) . ' WHERE id = %d', $id ) );
		echo '<div class="wrap slr-wrap">';
		if ( ! $lead ) {
			echo '<h1>الطلب غير موجود</h1></div>';
			return;
		}
		$wa = SLR_Helpers::reply_link( $lead->phone, $lead->name );

		echo '<p><a href="' . esc_url( self::url( 'slr-leads' ) ) . '">→ كل الطلبات</a></p>';
		echo '<h1 class="slr-title">' . esc_html( $lead->name ? $lead->name : 'بدون اسم' ) . ' ' . self::repeat_badge( $lead ) . ' ' . self::status_badge( $lead->status ) . '</h1>';
		self::notice();

		echo '<div class="slr-grid slr-grid-2"><div class="slr-panel"><h2>بيانات الطلب</h2><table class="slr-kv">';
		$kv = array(
			'التاريخ'     => self::fmt_date( $lead->created_at ),
			'الجوال'      => $lead->phone,
			'البريد'      => $lead->email,
			'الخدمة'      => $lead->service,
			'التفاصيل'    => $lead->message,
			'المصدر'      => SLR_Helpers::source_label( $lead->source, $lead->medium ) . ( $lead->campaign ? ' — حملة: ' . $lead->campaign : '' ) . ( $lead->click_id_type ? ' (' . $lead->click_id_type . ')' : '' ),
			'الفورم'      => $lead->form_id,
		);
		foreach ( $kv as $k => $v ) {
			if ( '' !== (string) $v ) {
				echo '<tr><th>' . esc_html( $k ) . '</th><td>' . nl2br( esc_html( $v ) ) . '</td></tr>';
			}
		}
		echo '<tr><th>الصفحة</th><td>' . self::page_cell( $lead->page_url, $lead->page_title ) . '</td></tr></table>';
		if ( $wa ) {
			echo '<p><a class="button button-primary slr-wa-btn" href="' . esc_url( $wa ) . '" target="_blank">مراسلة العميل على واتساب</a> ';
			echo '<a class="button" href="tel:' . esc_attr( '+' . SLR_Helpers::normalize_phone( $lead->phone ) ) . '">اتصال</a></p>';
		}
		echo '</div>';

		echo '<div class="slr-panel"><h2>المتابعة</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'slr_update_lead_' . $lead->id );
		echo '<input type="hidden" name="action" value="slr_update_lead"><input type="hidden" name="id" value="' . (int) $lead->id . '">';
		echo '<p><label>الحالة<br><select name="status">';
		foreach ( SLR_Helpers::status_labels() as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $lead->status, $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select></label></p><p><label>ملاحظات داخلية<br><textarea name="notes" rows="6" class="large-text">' . esc_textarea( $lead->notes ) . '</textarea></label></p>';
		echo '<p><button class="button button-primary">حفظ</button></p></form></div></div>';

		// Other requests from the same number.
		if ( $lead->phone_normalized ) {
			$others = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SLR_DB::table( 'leads' ) . ' WHERE phone_normalized = %s AND id <> %d ORDER BY id DESC', $lead->phone_normalized, $lead->id ) );
			if ( $others ) {
				echo '<div class="slr-panel"><h2>🔁 طلبات أخرى من نفس الرقم (' . count( $others ) . ')</h2>';
				self::leads_table( $others );
				echo '</div>';
			}
		}

		self::journey( (int) $lead->visitor_id, $lead->phone_normalized );
		echo '</div>';
	}

	public static function update_lead() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! current_user_can( self::cap() ) || ! check_admin_referer( 'slr_update_lead_' . $id ) ) {
			wp_die( 'غير مسموح' );
		}
		global $wpdb;
		$status = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'new';
		if ( ! isset( SLR_Helpers::status_labels()[ $status ] ) ) {
			$status = 'new';
		}
		$wpdb->update(
			SLR_DB::table( 'leads' ),
			array(
				'status'     => $status,
				'notes'      => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
				'updated_at' => SLR_Helpers::now(),
			),
			array( 'id' => $id )
		);
		wp_safe_redirect( self::url( 'slr-leads', array( 'lead' => $id, 'slr_msg' => 'updated' ) ) );
		exit;
	}

	/* ------------------------------------------------------------- visitors */

	/** Timeline of everything a visitor did, merged with other devices that used the same phone. */
	private static function journey( $visitor_id, $phone = '' ) {
		global $wpdb;
		$ids = array( $visitor_id );
		if ( $phone ) {
			$ids = array_unique( array_merge( $ids, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . SLR_DB::table( 'visitors' ) . ' WHERE phone = %s', $phone ) ) ) ) );
		}
		$ids    = array_filter( $ids );
		if ( ! $ids ) {
			return;
		}
		$in     = implode( ',', array_map( 'intval', $ids ) );
		$events = $wpdb->get_results( 'SELECT * FROM ' . SLR_DB::table( 'events' ) . " WHERE visitor_id IN ($in) ORDER BY created_at DESC LIMIT 200" );
		$vis    = $wpdb->get_results( 'SELECT * FROM ' . SLR_DB::table( 'visitors' ) . " WHERE id IN ($in)" );

		echo '<div class="slr-panel"><h2>🧭 رحلة العميل</h2>';
		foreach ( $vis as $v ) {
			echo '<p class="slr-muted">جهاز: ' . esc_html( $v->device ) . ' · أول زيارة: ' . esc_html( self::fmt_date( $v->first_seen ) ) . ' · أول مصدر: ' . esc_html( SLR_Helpers::source_label( $v->first_source, $v->first_medium ) ) . ( $v->first_campaign ? ' (' . esc_html( $v->first_campaign ) . ')' : '' ) . ' · صفحة الدخول: ' . self::page_cell( $v->first_landing, '' ) . '</p>';
		}
		if ( count( $vis ) > 1 ) {
			echo '<p class="slr-muted">⚠️ نفس الرقم استُخدم من ' . count( $vis ) . ' أجهزة مختلفة — الأحداث مدمجة أدناه.</p>';
		}
		if ( ! $events ) {
			echo self::empty_state() . '</div>';
			return;
		}
		$pl = SLR_Helpers::placement_labels();
		echo '<ol class="slr-timeline">';
		foreach ( $events as $e ) {
			echo '<li>' . self::type_badge( $e->type ) . ' <strong>' . esc_html( self::fmt_date( $e->created_at ) ) . '</strong> — ' . self::page_cell( $e->page_url, $e->page_title );
			if ( 'form' !== $e->type ) {
				echo ' · ' . esc_html( isset( $pl[ $e->placement ] ) ? $pl[ $e->placement ] : $e->placement ) . ( $e->label ? ' «' . esc_html( $e->label ) . '»' : '' );
			}
			echo ' · <span class="slr-muted">' . esc_html( SLR_Helpers::source_label( $e->source, $e->medium ) ) . '</span></li>';
		}
		echo '</ol></div>';
	}

	public static function page_visitor() {
		global $wpdb;
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$v  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SLR_DB::table( 'visitors' ) . ' WHERE id = %d', $id ) );
		echo '<div class="wrap slr-wrap"><p><a href="' . esc_url( self::url( 'slr-clicks' ) ) . '">→ سجل الضغطات</a></p>';
		if ( ! $v ) {
			echo '<h1>الزائر غير موجود</h1></div>';
			return;
		}
		echo '<h1 class="slr-title">' . esc_html( $v->name ? $v->name : 'زائر #' . $v->id ) . ( $v->phone ? ' <small dir="ltr">+' . esc_html( $v->phone ) . '</small>' : '' ) . '</h1>';
		echo '<div class="slr-cards"><div class="slr-card"><div class="slr-card-label">مرات التواصل</div><div class="slr-card-value">' . (int) $v->contacts . '</div></div>';
		echo '<div class="slr-card"><div class="slr-card-label">طلبات فورم</div><div class="slr-card-value">' . (int) $v->leads . '</div></div>';
		echo '<div class="slr-card"><div class="slr-card-label">آخر نشاط</div><div class="slr-card-value slr-small">' . esc_html( self::fmt_date( $v->last_seen ) ) . '</div></div></div>';
		if ( $v->phone ) {
			$leads = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SLR_DB::table( 'leads' ) . ' WHERE phone_normalized = %s ORDER BY id DESC', $v->phone ) );
			echo '<div class="slr-panel"><h2>طلباته</h2>';
			self::leads_table( $leads );
			echo '</div>';
		}
		self::journey( (int) $v->id, $v->phone );
		echo '</div>';
	}

	/* ---------------------------------------------------------------- clicks */

	private static function clicks_where( &$args ) {
		global $wpdb;
		list( $from, $to ) = SLR_Helpers::date_range();
		$where = $wpdb->prepare( 'e.created_at BETWEEN %s AND %s', $from, $to );
		if ( ! empty( $_GET['type'] ) && isset( SLR_Helpers::type_labels()[ $_GET['type'] ] ) ) {
			$where .= $wpdb->prepare( ' AND e.type = %s', sanitize_key( $_GET['type'] ) );
		}
		if ( ! empty( $_GET['placement'] ) ) {
			$where .= $wpdb->prepare( ' AND e.placement = %s', sanitize_key( $_GET['placement'] ) );
		}
		if ( ! empty( $_GET['repeat'] ) ) {
			$map    = array(
				'new'   => ' AND e.is_repeat = 0 AND e.possible_repeat = 0',
				'yes'   => ' AND e.is_repeat = 1',
				'maybe' => ' AND e.possible_repeat = 1',
			);
			$where .= isset( $map[ $_GET['repeat'] ] ) ? $map[ $_GET['repeat'] ] : '';
		}
		if ( ! empty( $_GET['s'] ) ) {
			$like   = '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) . '%';
			$where .= $wpdb->prepare( ' AND (e.page_url LIKE %s OR e.page_title LIKE %s OR e.label LIKE %s OR e.source LIKE %s OR e.campaign LIKE %s OR v.name LIKE %s)', $like, $like, $like, $like, $like, $like );
		}
		$args = array( 'range', 'from', 'to', 'type', 'placement', 'repeat', 's' );
		return $where;
	}

	public static function page_clicks() {
		global $wpdb;
		$args   = array();
		$where  = self::clicks_where( $args );
		$from   = SLR_DB::table( 'events' ) . ' e LEFT JOIN ' . SLR_DB::table( 'visitors' ) . ' v ON v.id = e.visitor_id';
		$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $from WHERE $where" );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT e.*, v.name vname, v.phone vphone FROM $from WHERE $where ORDER BY e.id DESC LIMIT %d OFFSET %d", self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ) );
		$export = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'slr_export', 'what' => 'events' ), self::current_args( $args ) ), admin_url( 'admin-post.php' ) ), 'slr_export' );

		echo '<div class="wrap slr-wrap"><h1 class="slr-title">👆 سجل الضغطات <a class="page-title-action" href="' . esc_url( $export ) . '">تصدير Excel</a></h1>';
		$extra  = self::select_filter( 'type', SLR_Helpers::type_labels(), 'كل الأنواع' );
		$extra .= self::select_filter( 'placement', SLR_Helpers::placement_labels(), 'كل الأماكن' );
		$extra .= self::select_filter( 'repeat', array( 'new' => 'جديد', 'yes' => 'مكرر', 'maybe' => 'مكرر محتمل' ), 'الكل' );
		$extra .= '<input type="search" name="s" placeholder="صفحة، زر، حملة…" value="' . esc_attr( isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '' ) . '">';
		self::range_form( 'slr-clicks', $extra );
		echo '<p class="slr-count">' . number_format_i18n( $total ) . ' حدث</p>';

		if ( ! $rows ) {
			echo self::empty_state() . '</div>';
			return;
		}
		$pl = SLR_Helpers::placement_labels();
		echo '<table class="slr-table widefat striped"><thead><tr><th>الوقت</th><th>النوع</th><th>الصفحة</th><th>الزر</th><th>المصدر</th><th>الجهاز</th><th>الزائر</th><th>جديد / مكرر</th></tr></thead><tbody>';
		foreach ( $rows as $e ) {
			$who = $e->vname ? esc_html( $e->vname ) : 'زائر #' . (int) $e->visitor_id;
			echo '<tr><td>' . esc_html( self::fmt_date( $e->created_at ) ) . '</td>';
			echo '<td>' . self::type_badge( $e->type ) . '</td>';
			echo '<td>' . self::page_cell( $e->page_url, $e->page_title ) . '</td>';
			echo '<td>' . esc_html( isset( $pl[ $e->placement ] ) ? $pl[ $e->placement ] : $e->placement ) . ( $e->label ? '<br><span class="slr-muted">' . esc_html( wp_trim_words( $e->label, 6, '…' ) ) . '</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( SLR_Helpers::source_label( $e->source, $e->medium ) ) . ( $e->campaign ? '<br><span class="slr-muted">' . esc_html( $e->campaign ) . '</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( $e->device ) . '</td>';
			echo '<td><a href="' . esc_url( self::url( 'slr-visitor', array( 'id' => $e->visitor_id ) ) ) . '">' . $who . '</a></td>';
			echo '<td>' . self::repeat_badge( $e ) . '</td></tr>';
		}
		echo '</tbody></table>';
		self::pagination( $total, $paged );
		echo '</div>';
	}

	/* ---------------------------------------------------------------- export */

	public static function export() {
		if ( ! current_user_can( self::cap() ) || ! check_admin_referer( 'slr_export' ) ) {
			wp_die( 'غير مسموح' );
		}
		global $wpdb;
		$what = isset( $_GET['what'] ) && 'events' === $_GET['what'] ? 'events' : 'leads';
		$args = array();

		if ( 'leads' === $what ) {
			$where  = self::leads_where( $args );
			$rows   = $wpdb->get_results( 'SELECT * FROM ' . SLR_DB::table( 'leads' ) . " WHERE $where ORDER BY id DESC", ARRAY_A );
			$header = array( 'id', 'التاريخ', 'الاسم', 'الجوال', 'الجوال الموحد', 'البريد', 'الخدمة', 'التفاصيل', 'الصفحة', 'المصدر', 'الحملة', 'مكرر', 'الحالة', 'ملاحظات' );
			$status = SLR_Helpers::status_labels();
			$map    = function ( $r ) use ( $status ) {
				return array( $r['id'], $r['created_at'], $r['name'], $r['phone'], $r['phone_normalized'], $r['email'], $r['service'], $r['message'], $r['page_url'], SLR_Helpers::source_label( $r['source'], $r['medium'] ), $r['campaign'], $r['is_repeat'] ? 'نعم' : 'لا', isset( $status[ $r['status'] ] ) ? $status[ $r['status'] ] : $r['status'], $r['notes'] );
			};
		} else {
			$where  = self::clicks_where( $args );
			$rows   = $wpdb->get_results( 'SELECT e.*, v.name vname, v.phone vphone FROM ' . SLR_DB::table( 'events' ) . ' e LEFT JOIN ' . SLR_DB::table( 'visitors' ) . " v ON v.id = e.visitor_id WHERE $where ORDER BY e.id DESC LIMIT 50000", ARRAY_A );
			$header = array( 'id', 'الوقت', 'النوع', 'الصفحة', 'عنوان الصفحة', 'مكان الزر', 'نص الزر', 'المصدر', 'الحملة', 'الجهاز', 'رقم الزائر', 'اسم الزائر', 'جوال الزائر', 'مكرر' );
			$tl     = SLR_Helpers::type_labels();
			$pl     = SLR_Helpers::placement_labels();
			$map    = function ( $r ) use ( $tl, $pl ) {
				return array( $r['id'], $r['created_at'], $tl[ $r['type'] ] ?? $r['type'], $r['page_url'], $r['page_title'], $pl[ $r['placement'] ] ?? $r['placement'], $r['label'], SLR_Helpers::source_label( $r['source'], $r['medium'] ), $r['campaign'], $r['device'], $r['visitor_id'], $r['vname'], $r['vphone'], $r['is_repeat'] ? 'مكرر' : ( $r['possible_repeat'] ? 'محتمل' : 'جديد' ) );
			};
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename=suruh-' . $what . '-' . wp_date( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM so Excel shows Arabic correctly.
		fputcsv( $out, $header );
		foreach ( $rows as $r ) {
			// Neutralise values Excel would evaluate as formulas, but leave phone numbers like +966… intact.
			fputcsv( $out, array_map( function ( $v ) {
				$v = (string) $v;
				return preg_match( '/^[=@\t\r]|^[+\-](?![\d\s()-]+$)/', $v ) ? "'" . $v : $v;
			}, $map( $r ) ) );
		}
		fclose( $out );
		exit;
	}

	/* -------------------------------------------------------------- settings */

	public static function page_settings() {
		$s = SLR_Helpers::settings();
		echo '<div class="wrap slr-wrap"><h1 class="slr-title">⚙️ إعدادات رادار العملاء</h1>';
		self::notice();
		self::flash();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="slr-panel slr-settings">';
		wp_nonce_field( 'slr_save_settings' );
		echo '<input type="hidden" name="action" value="slr_save_settings">';

		echo '<h2>عام</h2><table class="form-table">';
		echo '<tr><th>اسم النشاط</th><td><input type="text" class="regular-text" name="business_name" value="' . esc_attr( $s['business_name'] ) . '" placeholder="' . esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . '"><p class="description">يظهر في رسائل التنبيه وفي رسالة الرد على العميل. اتركه فارغًا لاستخدام اسم الموقع.</p></td></tr>';
		echo '<tr><th>رسالة الرد على واتساب</th><td><input type="text" class="large-text" name="reply_template" value="' . esc_attr( $s['reply_template'] ) . '"><p class="description">النص الجاهز عند الضغط على «مراسلة العميل». المتغيرات: <code>{name}</code> اسم العميل، <code>{business}</code> اسم النشاط.</p></td></tr>';
		echo '</table>';

		echo '<h2>التنبيهات</h2><table class="form-table">';
		echo '<tr><th>تيليجرام <span class="slr-rec">موصى به</span></th><td><label><input type="checkbox" name="telegram_enabled" value="1"' . checked( $s['telegram_enabled'], 1, false ) . '> إرسال رسالة تيليجرام فورية</label>';
		echo '<p><input type="text" class="regular-text" name="telegram_token" placeholder="Bot Token — مثال: 123456789:AAH..." value="' . esc_attr( $s['telegram_token'] ) . '" dir="ltr" autocomplete="off"></p>';
		echo '<p><input type="text" class="regular-text" name="telegram_chat_id" placeholder="Chat ID" value="' . esc_attr( $s['telegram_chat_id'] ) . '" dir="ltr"> ';
		echo '<button class="button" name="slr_after" value="detect_chat">🔎 جلب Chat ID تلقائيًا</button></p>';
		echo '<ol class="description slr-steps"><li>افتح <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a> ← <code>/newbot</code> ← انسخ الـ Token والصقه هنا.</li><li>افتح البوت الجديد واضغط <b>Start</b> وأرسل أي رسالة.</li><li>اضغط «جلب Chat ID تلقائيًا» — سيُحفظ مباشرة. (للمجموعات: أضف البوت للمجموعة وأرسل رسالة فيها أولًا)</li></ol></td></tr>';
		echo '<tr><th>البريد</th><td><label><input type="checkbox" name="email_enabled" value="1"' . checked( $s['email_enabled'], 1, false ) . '> إرسال بريد عند كل طلب جديد</label><br><input type="email" class="regular-text" name="notify_email" value="' . esc_attr( $s['notify_email'] ) . '">';
		$mailer = SLR_Helpers::mailer_plugin();
		echo '<p class="description">' . ( $mailer ? 'الإرسال عبر: <b>' . esc_html( $mailer ) . '</b>. إذا لم يصل البريد فالمشكلة في إعداد هذه الإضافة — راجع سجلها.' : '⚠️ لا توجد إضافة SMTP؛ البريد غالبًا لن يصل. ثبّت WP Mail SMTP أو FluentSMTP.' ) . '</p></td></tr>';
		echo '<tr><th>عميل سابق رجع</th><td><label><input type="checkbox" name="notify_returning" value="1"' . checked( $s['notify_returning'], 1, false ) . '> نبّهني عندما يضغط واتساب/اتصال شخص سبق أن ترك رقمه (مرة كل 6 ساعات لكل عميل)</label></td></tr>';
		echo '</table>';

		echo '<h2>التتبع</h2><table class="form-table">';
		echo '<tr><th>استثناء فريق الموقع</th><td><label><input type="checkbox" name="exclude_logged_in" value="1"' . checked( $s['exclude_logged_in'], 1, false ) . '> لا تسجّل ضغطات المسجّلين كمحررين أو مدراء</label></td></tr>';
		$detected = SLR_Integrations::detected();
		echo '<tr><th>فورمات الإضافات</th><td><label><input type="checkbox" name="capture_plugin_forms" value="1"' . checked( $s['capture_plugin_forms'], 1, false ) . '> التقاط الطلبات تلقائيًا من Contact Form 7 و WPForms و Elementor Forms و Gravity و Fluent و Ninja Forms</label>';
		echo '<p class="description">' . ( $detected ? 'المكتشف في هذا الموقع: <b>' . esc_html( implode( '، ', $detected ) ) . '</b>' : 'لا توجد إضافة فورم مدعومة مفعّلة حاليًا.' ) . '</p></td></tr>';
		echo '<tr><th>فورمات مخصصة (HTML)</th><td><input type="text" class="large-text" name="form_selectors" value="' . esc_attr( $s['form_selectors'] ) . '" dir="ltr"><p class="description">للفورمات المكتوبة يدويًا (مثل فورم يفتح واتساب). محددات CSS مفصولة بفاصلة، أو أضف للفورم الخاصية <code dir="ltr">data-slr-form</code>.</p></td></tr>';
		echo '<tr><th>مدة حفظ الضغطات</th><td><input type="number" min="30" name="retention_days" value="' . (int) $s['retention_days'] . '"> يوم <p class="description">الطلبات لا تُحذف تلقائيًا أبدًا.</p></td></tr>';
		echo '<tr><th>عند حذف الإضافة</th><td><label><input type="checkbox" name="delete_on_uninstall" value="1"' . checked( $s['delete_on_uninstall'], 1, false ) . '> احذف كل البيانات</label></td></tr>';
		echo '</table><p><button class="button button-primary">حفظ الإعدادات</button> <button class="button" name="slr_after" value="test">حفظ وإرسال تنبيه تجريبي</button></p></form>';

		self::render_health();
		self::render_notify_log();

		echo '<div class="slr-panel"><h2>تخصيص الأزرار (اختياري)</h2><p>كل روابط واتساب و<code>tel:</code> تُتتبَّع تلقائيًا. لتسمية زر معيّن أضف له في Elementor (Attributes):</p><code dir="ltr">data-slr-label|زر عرض السعر</code> &nbsp; <code dir="ltr">data-slr-placement|header</code></div>';
		echo '</div>';
	}

	/** One-off result messages stored for the current user by the last action. */
	private static function flash() {
		$key  = 'slr_flash_' . get_current_user_id();
		$msgs = get_transient( $key );
		if ( ! $msgs ) {
			return;
		}
		delete_transient( $key );
		foreach ( (array) $msgs as $m ) {
			$cls = $m[0] ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . $cls . '"><p><b>' . esc_html( $m[1] ) . ':</b> ' . esc_html( $m[2] ) . '</p></div>';
		}
	}

	private static function set_flash( $msgs ) {
		set_transient( 'slr_flash_' . get_current_user_id(), $msgs, 5 * MINUTE_IN_SECONDS );
	}

	private static function render_health() {
		$result = get_option( SLR_Health::RESULT );
		if ( ! $result ) {
			$result = SLR_Health::run(); // first visit: diagnose automatically.
		}
		$run    = wp_nonce_url( admin_url( 'admin-post.php?action=slr_health' ), 'slr_health' );
		echo '<div class="slr-panel" id="slr-health"><h2>🩺 فحص النظام <a class="button button-small" href="' . esc_url( $run ) . '">تشغيل الفحص</a></h2>';
		if ( ! $result ) {
			echo '<p class="slr-muted">اضغط «تشغيل الفحص» للتأكد أن التتبع والتنبيهات تعمل على هذا الموقع.</p></div>';
			return;
		}
		$icons = array( 'ok' => '✅', 'warn' => '⚠️', 'fail' => '❌' );
		echo '<table class="slr-table slr-health">';
		foreach ( $result['checks'] as $c ) {
			echo '<tr class="slr-h-' . esc_attr( $c[0] ) . '"><td>' . $icons[ $c[0] ] . '</td><th>' . esc_html( $c[1] ) . '</th><td>' . esc_html( $c[2] ) . '</td></tr>';
		}
		echo '</table><p class="slr-muted">آخر فحص: ' . esc_html( $result['time'] ) . ' · طريقة الاستقبال: ' . ( 'ajax' === get_option( 'slr_transport' ) ? 'admin-ajax' : 'REST API' ) . '</p></div>';
	}

	private static function render_notify_log() {
		$log = get_option( SLR_Notify::LOG_OPTION, array() );
		if ( ! $log ) {
			return;
		}
		$names = array( 'telegram' => 'تيليجرام', 'email' => 'البريد', 'system' => 'النظام' );
		echo '<div class="slr-panel"><h2>📨 سجل آخر التنبيهات</h2><table class="slr-table"><thead><tr><th>الوقت</th><th>القناة</th><th>الرسالة</th><th>النتيجة</th></tr></thead><tbody>';
		foreach ( $log as $l ) {
			echo '<tr><td>' . esc_html( self::fmt_date( $l['time'] ) ) . '</td><td>' . esc_html( isset( $names[ $l['channel'] ] ) ? $names[ $l['channel'] ] : $l['channel'] ) . '</td><td>' . esc_html( $l['title'] ) . '</td><td>' . ( $l['ok'] ? '✅ ' : '❌ ' ) . esc_html( $l['message'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function save_settings() {
		if ( ! current_user_can( self::cap() ) || ! check_admin_referer( 'slr_save_settings' ) ) {
			wp_die( 'غير مسموح' );
		}
		$p = wp_unslash( $_POST );
		$s = array(
			'business_name'        => sanitize_text_field( $p['business_name'] ?? '' ),
			'reply_template'       => sanitize_text_field( $p['reply_template'] ?? '' ),
			'notify_email'         => sanitize_email( $p['notify_email'] ?? '' ),
			'email_enabled'        => empty( $p['email_enabled'] ) ? 0 : 1,
			'telegram_enabled'     => empty( $p['telegram_enabled'] ) ? 0 : 1,
			'telegram_token'       => SLR_Helpers::clean_token( $p['telegram_token'] ?? '' ),
			'telegram_chat_id'     => preg_replace( '/[^0-9@A-Za-z_-]/', '', $p['telegram_chat_id'] ?? '' ),
			'notify_returning'     => empty( $p['notify_returning'] ) ? 0 : 1,
			'exclude_logged_in'    => empty( $p['exclude_logged_in'] ) ? 0 : 1,
			'capture_plugin_forms' => empty( $p['capture_plugin_forms'] ) ? 0 : 1,
			'retention_days'       => max( 30, absint( $p['retention_days'] ?? 365 ) ),
			'form_selectors'       => sanitize_text_field( $p['form_selectors'] ?? '' ),
			'delete_on_uninstall'  => empty( $p['delete_on_uninstall'] ) ? 0 : 1,
		);
		$after = isset( $p['slr_after'] ) ? sanitize_key( $p['slr_after'] ) : '';

		if ( 'detect_chat' === $after ) {
			list( $chat, $msg ) = SLR_Notify::detect_chat_id( $s['telegram_token'] );
			if ( $chat ) {
				$s['telegram_chat_id'] = $chat;
				$s['telegram_enabled'] = 1;
			}
			self::set_flash( array( array( (bool) $chat, 'Chat ID', $chat ? $msg . ' — تم حفظ الرقم ' . $chat : $msg ) ) );
		}
		update_option( 'slr_settings', $s, false );

		if ( 'test' === $after ) {
			self::flash_test();
		}
		wp_safe_redirect( self::url( 'slr-settings', $after ? array() : array( 'slr_msg' => 'saved' ) ) );
		exit;
	}

	private static function flash_test() {
		$results = SLR_Notify::test();
		$names   = array( 'telegram' => 'تيليجرام', 'email' => 'البريد' );
		$msgs    = array();
		foreach ( $results as $channel => $r ) {
			$msgs[] = array( $r[0], $names[ $channel ], $r[1] );
		}
		if ( ! $msgs ) {
			$msgs[] = array( false, 'التنبيهات', 'لا توجد قناة مفعّلة. فعّل تيليجرام أو البريد أولًا.' );
		}
		self::set_flash( $msgs );
	}

	public static function test_notify() {
		if ( ! current_user_can( self::cap() ) || ! check_admin_referer( 'slr_test_notify' ) ) {
			wp_die( 'غير مسموح' );
		}
		self::flash_test();
		wp_safe_redirect( self::url( 'slr-settings' ) );
		exit;
	}

	public static function health() {
		if ( ! current_user_can( self::cap() ) || ! check_admin_referer( 'slr_health' ) ) {
			wp_die( 'غير مسموح' );
		}
		SLR_Health::run();
		wp_safe_redirect( self::url( 'slr-settings' ) . '#slr-health' );
		exit;
	}

	/* ------------------------------------------------------ wp-admin widget */

	public static function dashboard_widget() {
		if ( current_user_can( self::cap() ) ) {
			wp_add_dashboard_widget( 'slr_widget', '📡 رادار العملاء — آخر 7 أيام', array( __CLASS__, 'render_widget' ) );
		}
	}

	public static function render_widget() {
		$from = wp_date( 'Y-m-d', strtotime( '-6 days' ) ) . ' 00:00:00';
		$t    = self::type_totals( $from, wp_date( 'Y-m-d' ) . ' 23:59:59' );
		echo '<div class="slr-mini"><span class="slr-c-whatsapp">واتساب <b>' . (int) $t['whatsapp'] . '</b></span><span class="slr-c-call">اتصال <b>' . (int) $t['call'] . '</b></span><span class="slr-c-form">فورم <b>' . (int) $t['form'] . '</b></span></div>';
		echo '<p><a class="button" href="' . esc_url( self::url( 'slr-dashboard', array( 'range' => '7d' ) ) ) . '">فتح اللوحة</a></p>';
	}
}
