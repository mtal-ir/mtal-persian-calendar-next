<?php
/**
 * Plugin Name: MTAL Persian Calendar
 * Description: افزونه مستقل تقویم جلالی برای انتخاب بازه دو تاریخی، مدیریت روزهای بسته/تعطیلات و قیمت‌گذاری در المنتور.
 * Version: 0.4.0
 * Author: MTAL
 * Text Domain: mtal-persian-calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MTAL_PC_VERSION', '0.4.0' );
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

	public function ajax_get_suite_calendar_rules() {
		$post_id = isset( $_REQUEST['post_id'] ) ? absint( $_REQUEST['post_id'] ) : 0;
		wp_send_json_success( self::get_suite_calendar_data( $post_id ) );
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

MTAL_Persian_Calendar::instance();