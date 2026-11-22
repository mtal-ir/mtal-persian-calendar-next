<?php
/**
 * Plugin Name: MTAL Persian Calendar
 * Description: افزونه مستقل تقویم جلالی برای انتخاب بازه دو تاریخی، مدیریت روزهای بسته/تعطیلات و قیمت‌گذاری در المنتور.
 * Version: 0.6.0
 * Author: MTAL
 * Text Domain: mtal-persian-calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MTAL_PC_VERSION', '0.6.0' );
define( 'MTAL_PC_PATH', plugin_dir_path( __FILE__ ) );
define( 'MTAL_PC_URL', plugin_dir_url( __FILE__ ) );

final class MTAL_Persian_Calendar {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_elementor_widgets' ) );
		add_action( 'elementor_pro/forms/fields/register', array( $this, 'register_elementor_form_field' ) );

		add_action( 'wp_ajax_mtal_save_suite_calendar_rules', array( $this, 'ajax_save_suite_calendar_rules' ) );
		add_action( 'wp_ajax_mtal_get_suite_calendar_rules', array( $this, 'ajax_get_suite_calendar_rules' ) );
		add_action( 'wp_ajax_nopriv_mtal_get_suite_calendar_rules', array( $this, 'ajax_get_suite_calendar_rules' ) );
		add_action( 'wp_ajax_mtal_calculate_suite_price', array( $this, 'ajax_calculate_suite_price' ) );
		add_action( 'wp_ajax_nopriv_mtal_calculate_suite_price', array( $this, 'ajax_calculate_suite_price' ) );
	}

	public static function normalize_date_list( $raw ) {
		$items = array();

		if ( is_array( $raw ) ) {
			$items = $raw;
		} elseif ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$items = $decoded;
			} else {
				$items = preg_split( '/[\s,،\r\n]+/u', $raw );
			}
		}

		$clean = array();
		foreach ( $items as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$str = trim( $item );
			if ( preg_match( '/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $str, $m ) ) {
				$jy = intval( $m[1] );
				$jm = intval( $m[2] );
				$jd = intval( $m[3] );
				if ( $jy >= 1300 && $jy <= 1500 && $jm >= 1 && $jm <= 12 && $jd >= 1 && $jd <= 31 ) {
					$formatted = sprintf( '%04d/%02d/%02d', $jy, $jm, $jd );
					$clean[ $formatted ] = $formatted;
				}
			}
		}

		$result = array_values( $clean );
		sort( $result );
		return $result;
	}

	public static function normalize_manual_prices( $raw ) {
		$map = array();
		if ( is_array( $raw ) ) {
			$map = $raw;
		} elseif ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$map = $decoded;
			}
		}

		$clean = array();
		foreach ( $map as $date_key => $price_val ) {
			if ( ! is_string( $date_key ) ) {
				continue;
			}
			$k = trim( $date_key );
			if ( preg_match( '/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $k, $m ) ) {
				$formatted = sprintf( '%04d/%02d/%02d', intval( $m[1] ), intval( $m[2] ), intval( $m[3] ) );
				$price_num = max( 0, intval( preg_replace( '/[^\d]/', '', (string) $price_val ) ) );
				if ( $price_num > 0 ) {
					$clean[ $formatted ] = $price_num;
				}
			}
		}
		ksort( $clean );
		return $clean;
	}

	public static function get_suite_calendar_data( $post_id ) {
		$post_id = absint( $post_id );

		$blocked_meta      = $post_id ? get_post_meta( $post_id, '_mtal_blocked_dates', true ) : array();
		$holiday_meta      = $post_id ? get_post_meta( $post_id, '_mtal_holiday_dates', true ) : array();
		$manual_meta       = $post_id ? get_post_meta( $post_id, '_mtal_manual_prices', true ) : array();
		$regular_price     = $post_id ? intval( get_post_meta( $post_id, '_mtal_regular_price', true ) ) : 0;
		$weekend_price     = $post_id ? intval( get_post_meta( $post_id, '_mtal_weekend_price', true ) ) : 0;
		$base_capacity_raw = $post_id ? get_post_meta( $post_id, '_mtal_base_capacity', true ) : '';
		$base_capacity     = '' !== $base_capacity_raw ? max( 1, intval( $base_capacity_raw ) ) : 2;
		$extra_guest_price = $post_id ? intval( get_post_meta( $post_id, '_mtal_extra_guest_price', true ) ) : 0;

		return array(
			'postId'          => $post_id,
			'blockedDates'    => self::normalize_date_list( $blocked_meta ),
			'holidayDates'    => self::normalize_date_list( $holiday_meta ),
			'manualPrices'    => self::normalize_manual_prices( $manual_meta ),
			'regularPrice'    => max( 0, $regular_price ),
			'weekendPrice'    => max( 0, $weekend_price ),
			'baseCapacity'    => $base_capacity,
			'extraGuestPrice' => max( 0, $extra_guest_price ),
		);
	}

	public function enqueue_assets() {
		wp_register_style(
			'mtal-persian-calendar-css',
			MTAL_PC_URL . 'assets/css/mtal-calendar.css',
			array(),
			MTAL_PC_VERSION
		);

		wp_register_script(
			'mtal-persian-calendar-engine',
			MTAL_PC_URL . 'assets/js/persian-calendar-standalone.js',
			array(),
			MTAL_PC_VERSION,
			true
		);

		wp_register_script(
			'mtal-persian-calendar-init',
			MTAL_PC_URL . 'assets/js/datepicker-init.js',
			array( 'mtal-persian-calendar-engine' ),
			MTAL_PC_VERSION,
			true
		);

		$current_post_id = is_singular() ? get_the_ID() : 0;
		$suite_data      = self::get_suite_calendar_data( $current_post_id );

		wp_localize_script(
			'mtal-persian-calendar-init',
			'mtalCalendarConfig',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'mtal_calendar_rules_nonce' ),
				'maxMonths'       => 3,
				'postId'          => $current_post_id,
				'blockedDates'    => $suite_data['blockedDates'],
				'holidayDates'    => $suite_data['holidayDates'],
				'manualPrices'    => $suite_data['manualPrices'],
				'regularPrice'    => $suite_data['regularPrice'],
				'weekendPrice'    => $suite_data['weekendPrice'],
				'baseCapacity'    => $suite_data['baseCapacity'],
				'extraGuestPrice' => $suite_data['extraGuestPrice'],
			)
		);
	}

	/**
	 * Convert a valid Jalali date to the same JDN used by the JavaScript calendar.
	 */
	private static function jalali_to_jdn( $jy, $jm, $jd ) {
		$epbase = $jy - ( $jy >= 0 ? 474 : 473 );
		$epyear = 474 + ( ( $epbase % 2820 ) + 2820 ) % 2820;
		return $jd
			+ ( $jm <= 7 ? ( $jm - 1 ) * 31 : ( $jm - 1 ) * 30 + 6 )
			+ intdiv( $epyear * 682 - 110, 2816 )
			+ ( $epyear - 1 ) * 365
			+ intdiv( $epbase, 2820 ) * 1029983
			+ 1948320;
	}

	private static function gregorian_to_jdn( $year, $month, $day ) {
		$a = intdiv( 14 - $month, 12 );
		$y = $year + 4800 - $a;
		$m = $month + 12 * $a - 3;
		return $day + intdiv( 153 * $m + 2, 5 ) + 365 * $y
			+ intdiv( $y, 4 ) - intdiv( $y, 100 ) + intdiv( $y, 400 ) - 32045;
	}

	private static function jdn_to_jalali( $jdn ) {
		$a = $jdn + 32044;
		$b = intdiv( 4 * $a + 3, 146097 );
		$c = $a - intdiv( 146097 * $b, 4 );
		$d = intdiv( 4 * $c + 3, 1461 );
		$e = $c - intdiv( 1461 * $d, 4 );
		$m = intdiv( 5 * $e + 2, 153 );
		$gy = 100 * $b + $d - 4800 + intdiv( $m, 10 );
		$jy = $gy - 621;
		if ( $jdn < self::jalali_to_jdn( $jy, 1, 1 ) ) {
			--$jy;
		} elseif ( $jdn >= self::jalali_to_jdn( $jy + 1, 1, 1 ) ) {
			++$jy;
		}
		$offset = $jdn - self::jalali_to_jdn( $jy, 1, 1 );
		if ( $offset < 186 ) {
			$jm = 1 + intdiv( $offset, 31 );
			$jd = ( $offset % 31 ) + 1;
		} else {
			$offset -= 186;
			$jm = 7 + intdiv( $offset, 30 );
			$jd = ( $offset % 30 ) + 1;
		}
		return array( $jy, $jm, $jd );
	}

	private static function normalize_jalali_input( $value ) {
		$value = strtr( (string) $value, array(
			'۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
			'٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
		) );
		if ( ! preg_match( '/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', trim( $value ), $m ) ) {
			return false;
		}
		$jy = (int) $m[1]; $jm = (int) $m[2]; $jd = (int) $m[3];
		if ( $jy < 1300 || $jy > 1500 || $jm < 1 || $jm > 12 ) {
			return false;
		}
		$leap = self::jalali_to_jdn( $jy + 1, 1, 1 ) - self::jalali_to_jdn( $jy, 1, 1 ) === 366;
		$month_days = $jm <= 6 ? 31 : ( $jm <= 11 ? 30 : ( $leap ? 30 : 29 ) );
		if ( $jd < 1 || $jd > $month_days ) {
			return false;
		}
		return array( 'jy' => $jy, 'jm' => $jm, 'jd' => $jd, 'jdn' => self::jalali_to_jdn( $jy, $jm, $jd ), 'slash' => sprintf( '%04d/%02d/%02d', $jy, $jm, $jd ) );
	}


	/**
	 * Public date helpers used by the booking workflow.
	 */
	public static function normalize_booking_date( $value ) {
		return self::normalize_jalali_input( $value );
	}

	public static function booking_jdn_to_jalali( $jdn ) {
		return self::jdn_to_jalali( $jdn );
	}

	/**
	 * Recalculate a stay on the server before creating a WooCommerce order.
	 *
	 * @return array|WP_Error
	 */
	public static function calculate_stay_price( $post_id, $check_in, $check_out, $guests ) {
		$post_id = absint( $post_id );
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || 'publish' !== $post->post_status || absint( $guests ) < 1 ) {
			return new WP_Error( 'mtal_invalid_booking', 'سوئیت یا تعداد مسافران معتبر نیست.' );
		}
		$start = self::normalize_jalali_input( $check_in );
		$end = self::normalize_jalali_input( $check_out );
		if ( ! $start || ! $end || $end['jdn'] <= $start['jdn'] || ( $end['jdn'] - $start['jdn'] ) > 94 ) {
			return new WP_Error( 'mtal_invalid_dates', 'تاریخ ورود و خروج معتبر نیست.' );
		}
		$today = current_datetime();
		$today_jdn = self::gregorian_to_jdn( (int) $today->format( 'Y' ), (int) $today->format( 'n' ), (int) $today->format( 'j' ) );
		$today_jalali = self::jdn_to_jalali( $today_jdn );
		$month_index = ( $today_jalali[0] * 12 ) + ( $today_jalali[1] - 1 ) + 2;
		$horizon_year = (int) floor( $month_index / 12 );
		$horizon_month = ( $month_index % 12 ) + 1;
		$leap = self::jalali_to_jdn( $horizon_year + 1, 1, 1 ) - self::jalali_to_jdn( $horizon_year, 1, 1 ) === 366;
		$last_day = $horizon_month <= 6 ? 31 : ( $horizon_month <= 11 ? 30 : ( $leap ? 30 : 29 ) );
		$max_jdn = self::jalali_to_jdn( $horizon_year, $horizon_month, $last_day );
		if ( $start['jdn'] < $today_jdn || $end['jdn'] > $max_jdn ) {
			return new WP_Error( 'mtal_dates_outside_horizon', 'بازه انتخابی خارج از محدوده مجاز تقویم است.' );
		}
		$data = self::get_suite_calendar_data( $post_id );
		$blocked = array_fill_keys( $data['blockedDates'], true );
		$base_total = 0;
		$breakdown = array();
		for ( $jdn = $start['jdn']; $jdn < $end['jdn']; $jdn++ ) {
			$day = self::jdn_to_jalali( $jdn );
			$key = sprintf( '%04d/%02d/%02d', $day[0], $day[1], $day[2] );
			if ( isset( $blocked[ $key ] ) || ( class_exists( 'MTAL_Booking_Workflow' ) && MTAL_Booking_Workflow::is_date_held( $post_id, $key ) ) ) {
				return new WP_Error( 'mtal_date_unavailable', 'یکی از تاریخ‌های انتخابی در حال حاضر مسدود یا در انتظار درخواست دیگری است: ' . $key );
			}
			$weekday = ( ( $jdn + 2 ) % 7 + 7 ) % 7;
			if ( isset( $data['manualPrices'][ $key ] ) ) {
				$night_price = (int) $data['manualPrices'][ $key ];
				$source = 'manual';
			} elseif ( ( 4 === $weekday || 5 === $weekday ) && $data['weekendPrice'] > 0 ) {
				$night_price = (int) $data['weekendPrice'];
				$source = 'weekend';
			} else {
				$night_price = (int) $data['regularPrice'];
				$source = 'regular';
			}
			$base_total += $night_price;
			$breakdown[] = array( 'date' => $key, 'price' => $night_price, 'source' => $source );
		}
		$nights = $end['jdn'] - $start['jdn'];
		$extra_guests = max( 0, absint( $guests ) - (int) $data['baseCapacity'] );
		$extra_per_night = $extra_guests * (int) $data['extraGuestPrice'];
		$extra_total = $nights * $extra_per_night;
		return array(
			'postId' => $post_id,
			'nights' => $nights,
			'guests' => absint( $guests ),
			'baseCapacity' => (int) $data['baseCapacity'],
			'extraGuests' => $extra_guests,
			'nightsBaseTotal' => $base_total,
			'extraGuestsPerNightTotal' => $extra_per_night,
			'extraGuestsTotal' => $extra_total,
			'totalPrice' => $base_total + $extra_total,
			'breakdown' => $breakdown,
		);
	}

	public function ajax_calculate_suite_price() {
		check_ajax_referer( 'mtal_calendar_rules_nonce', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $post_id ) ) ) {
			wp_send_json_error( array( 'message' => 'سوئیت معتبر یا در دسترس نیست.' ), 404 );
		}

		$start = self::normalize_jalali_input( isset( $_POST['check_in'] ) ? wp_unslash( $_POST['check_in'] ) : '' );
		$end   = self::normalize_jalali_input( isset( $_POST['check_out'] ) ? wp_unslash( $_POST['check_out'] ) : '' );
		$guests = isset( $_POST['guests'] ) ? absint( $_POST['guests'] ) : 0;
		if ( ! $start || ! $end || $guests < 1 || $end['jdn'] <= $start['jdn'] ) {
			wp_send_json_error( array( 'message' => 'تاریخ ورود، خروج یا تعداد مهمان معتبر نیست.' ), 400 );
		}

		$today = current_datetime();
		$today_jdn = self::gregorian_to_jdn( (int) $today->format( 'Y' ), (int) $today->format( 'n' ), (int) $today->format( 'j' ) );
		$max_months = isset( $_POST['max_months'] ) ? min( 3, max( 1, absint( $_POST['max_months'] ) ) ) : 3;
		$today_jalali = self::jdn_to_jalali( $today_jdn );
		$month_index = ( $today_jalali[0] * 12 ) + ( $today_jalali[1] - 1 ) + ( $max_months - 1 );
		$horizon_year = (int) floor( $month_index / 12 );
		$horizon_month = ( $month_index % 12 ) + 1;
		$horizon_leap = self::jalali_to_jdn( $horizon_year + 1, 1, 1 ) - self::jalali_to_jdn( $horizon_year, 1, 1 ) === 366;
		$horizon_last_day = $horizon_month <= 6 ? 31 : ( $horizon_month <= 11 ? 30 : ( $horizon_leap ? 30 : 29 ) );
		$max_jdn = self::jalali_to_jdn( $horizon_year, $horizon_month, $horizon_last_day );
		if ( $start['jdn'] < $today_jdn || $end['jdn'] < $today_jdn || $start['jdn'] > $max_jdn || $end['jdn'] > $max_jdn ) {
			wp_send_json_error( array( 'message' => 'بازه انتخابی خارج از محدوده مجاز تقویم است.' ), 400 );
		}

		$data = self::get_suite_calendar_data( $post_id );
		$blocked = array_fill_keys( $data['blockedDates'], true );
		if ( isset( $blocked[ $start['slash'] ] ) ) {
			wp_send_json_error( array( 'message' => 'تاریخ ورود مسدود است.' ), 400 );
		}

		$nights = $end['jdn'] - $start['jdn'];
		if ( $nights > 94 ) {
			wp_send_json_error( array( 'message' => 'بازه اقامت بیش از حد مجاز است.' ), 400 );
		}
		$base_total = 0;
		$breakdown = array();
		for ( $jdn = $start['jdn']; $jdn < $end['jdn']; $jdn++ ) {
			$day = self::jdn_to_jalali( $jdn );
			$key = sprintf( '%04d/%02d/%02d', $day[0], $day[1], $day[2] );
			if ( isset( $blocked[ $key ] ) || ( class_exists( 'MTAL_Booking_Workflow' ) && MTAL_Booking_Workflow::is_date_held( $post_id, $key ) ) ) {
				wp_send_json_error( array( 'message' => 'در بازه انتخابی روز مسدود وجود دارد: ' . $key ), 400 );
			}
			$weekday = ( ( $jdn + 2 ) % 7 + 7 ) % 7;
			if ( isset( $data['manualPrices'][ $key ] ) ) {
				$price = (int) $data['manualPrices'][ $key ]; $source = 'manual';
			} elseif ( ( 4 === $weekday || 5 === $weekday ) && $data['weekendPrice'] > 0 ) {
				$price = (int) $data['weekendPrice']; $source = 'weekend';
			} else {
				$price = (int) $data['regularPrice']; $source = 'regular';
			}
			$base_total += $price;
			$breakdown[] = array( 'date' => $key, 'price' => $price, 'source' => $source );
		}

		$extra_guests = max( 0, $guests - (int) $data['baseCapacity'] );
		$extra_per_night = $extra_guests * (int) $data['extraGuestPrice'];
		$extra_total = $nights * $extra_per_night;
		wp_send_json_success( array(
			'postId' => $post_id, 'nights' => $nights, 'guests' => $guests,
			'baseCapacity' => (int) $data['baseCapacity'], 'extraGuests' => $extra_guests,
			'nightsBaseTotal' => $base_total, 'extraGuestsPerNightTotal' => $extra_per_night,
			'extraGuestsTotal' => $extra_total, 'totalPrice' => $base_total + $extra_total,
			'breakdown' => $breakdown,
		) );
	}

	public function ajax_get_suite_calendar_rules() {
		$post_id = isset( $_REQUEST['post_id'] ) ? absint( $_REQUEST['post_id'] ) : 0;
		$data = self::get_suite_calendar_data( $post_id );
		if ( class_exists( 'MTAL_Booking_Workflow' ) ) {
			$data['blockedDates'] = array_values( array_unique( array_merge( $data['blockedDates'], MTAL_Booking_Workflow::active_held_dates( $post_id ) ) ) );
		}
		wp_send_json_success( $data );
	}

	public function ajax_save_suite_calendar_rules() {
		check_ajax_referer( 'mtal_calendar_rules_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز است.' ), 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'شناسه سوئیت نامعتبر یا بدون مجوز است.' ), 400 );
		}

		$blocked_dates     = self::normalize_date_list( isset( $_POST['blocked_dates'] ) ? wp_unslash( $_POST['blocked_dates'] ) : array() );
		$holiday_dates     = self::normalize_date_list( isset( $_POST['holiday_dates'] ) ? wp_unslash( $_POST['holiday_dates'] ) : array() );
		$manual_prices     = self::normalize_manual_prices( isset( $_POST['manual_prices'] ) ? wp_unslash( $_POST['manual_prices'] ) : array() );
		$regular_price     = isset( $_POST['regular_price'] ) ? max( 0, intval( $_POST['regular_price'] ) ) : 0;
		$weekend_price     = isset( $_POST['weekend_price'] ) ? max( 0, intval( $_POST['weekend_price'] ) ) : 0;
		$base_capacity     = isset( $_POST['base_capacity'] ) ? max( 1, intval( $_POST['base_capacity'] ) ) : 2;
		$extra_guest_price = isset( $_POST['extra_guest_price'] ) ? max( 0, intval( $_POST['extra_guest_price'] ) ) : 0;

		update_post_meta( $post_id, '_mtal_blocked_dates', $blocked_dates );
		update_post_meta( $post_id, '_mtal_holiday_dates', $holiday_dates );
		update_post_meta( $post_id, '_mtal_manual_prices', $manual_prices );
		update_post_meta( $post_id, '_mtal_regular_price', $regular_price );
		update_post_meta( $post_id, '_mtal_weekend_price', $weekend_price );
		update_post_meta( $post_id, '_mtal_base_capacity', $base_capacity );
		update_post_meta( $post_id, '_mtal_extra_guest_price', $extra_guest_price );

		wp_send_json_success(
			array(
				'message' => 'تنظیمات تقویم و روزها با موفقیت ذخیره شد.',
				'data'    => self::get_suite_calendar_data( $post_id ),
			)
		);
	}

	public function register_elementor_widgets( $widgets_manager ) {
		$widget_file = MTAL_PC_PATH . 'includes/class-mtal-inline-calendar-widget.php';
		if ( file_exists( $widget_file ) ) {
			require_once $widget_file;
			if ( class_exists( '\MTAL_Inline_Calendar_Widget' ) ) {
				$widgets_manager->register( new \MTAL_Inline_Calendar_Widget() );
			}
		}

		$admin_widget_file = MTAL_PC_PATH . 'includes/class-mtal-admin-calendar-widget.php';
		if ( file_exists( $admin_widget_file ) ) {
			require_once $admin_widget_file;
			if ( class_exists( '\MTAL_Admin_Calendar_Widget' ) ) {
				$widgets_manager->register( new \MTAL_Admin_Calendar_Widget() );
			}
		}
	}

	public function register_elementor_form_field( $form_fields_registrar ) {
		$field_file = MTAL_PC_PATH . 'includes/class-mtal-elementor-form-field.php';
		if ( file_exists( $field_file ) ) {
			require_once $field_file;
			if ( class_exists( '\MTAL_Elementor_Form_Field' ) ) {
				$form_fields_registrar->register( new \MTAL_Elementor_Form_Field() );
			}
		}
	}
}

require_once MTAL_PC_PATH . 'includes/class-mtal-booking-workflow.php';
require_once MTAL_PC_PATH . 'includes/class-mtal-booking-admin.php';
register_activation_hook( __FILE__, array( 'MTAL_Booking_Workflow', 'activate' ) );
MTAL_Persian_Calendar::instance();