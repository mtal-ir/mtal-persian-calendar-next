<?php
/**
 * WooCommerce booking approval workflow for MTAL Persian Calendar.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MTAL_Booking_Workflow {
	const HOLD_MINUTES = 30;
	const CRON_HOOK = 'mtal_booking_expire_order';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_order_statuses' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_order_statuses' ) );
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'handle_elementor_submission' ), 20, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_metabox' ) );
		add_action( 'admin_post_mtal_booking_decision', array( __CLASS__, 'handle_admin_decision' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'expire_order' ), 10, 1 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'confirm_paid_booking' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'confirm_paid_booking' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'confirm_paid_booking' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'release_order_holds' ) );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'release_order_holds' ) );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'release_order_holds' ) );
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mtal_booking_holds';
	}

	public static function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			suite_id bigint(20) unsigned NOT NULL,
			stay_date char(10) NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (suite_id,stay_date),
			KEY order_id (order_id),
			KEY expires_at (expires_at)
		) {$charset};";
		dbDelta( $sql );
	}

	public static function register_order_statuses() {
		register_post_status( 'wc-mtal-pending-admin', array(
			'label' => 'در انتظار تأیید مدیر',
			'public' => false,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
			'label_count' => _n_noop( 'در انتظار تأیید مدیر <span class="count">(%s)</span>', 'در انتظار تأیید مدیر <span class="count">(%s)</span>' ),
		) );
		register_post_status( 'wc-mtal-awaiting-payment', array(
			'label' => 'در انتظار پرداخت رزرو',
			'public' => false,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
			'label_count' => _n_noop( 'در انتظار پرداخت رزرو <span class="count">(%s)</span>', 'در انتظار پرداخت رزرو <span class="count">(%s)</span>' ),
		) );
	}

	public static function add_order_statuses( $statuses ) {
		$statuses['wc-mtal-pending-admin'] = 'در انتظار تأیید مدیر';
		$statuses['wc-mtal-awaiting-payment'] = 'در انتظار پرداخت رزرو';
		return $statuses;
	}

	public static function is_date_held( $suite_id, $jalali_date ) {
		global $wpdb;
		$table = self::table_name();
		$now = time();
		$held = $wpdb->get_var( $wpdb->prepare(
			"SELECT order_id FROM {$table} WHERE suite_id = %d AND stay_date = %s AND (expires_at = 0 OR expires_at > %d) LIMIT 1",
			absint( $suite_id ), $jalali_date, $now
		) );
		return ! empty( $held );
	}

	public static function active_held_dates( $suite_id ) {
		global $wpdb;
		$table = self::table_name();
		$now = time();
		$dates = $wpdb->get_col( $wpdb->prepare(
			"SELECT stay_date FROM {$table} WHERE suite_id = %d AND (expires_at = 0 OR expires_at > %d)",
			absint( $suite_id ), $now
		) );
		return is_array( $dates ) ? $dates : array();
	}

	private static function claim_dates( $suite_id, $dates, $order_id, $expires_at ) {
		global $wpdb;
		$table = self::table_name();
		$now = time();
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE suite_id = %d AND expires_at > 0 AND expires_at <= %d", $suite_id, $now ) );
		foreach ( $dates as $date ) {
			$inserted = $wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$table} (suite_id, stay_date, order_id, expires_at) VALUES (%d, %s, %d, %d)",
				$suite_id, $date, $order_id, $expires_at
			) );
			if ( 1 !== (int) $inserted ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'mtal_date_unavailable', 'بخشی از تاریخ‌های انتخابی در همین فاصله توسط درخواست دیگری رزرو موقت شده است. لطفاً تاریخ دیگری انتخاب کنید.' );
			}
		}
		$wpdb->query( 'COMMIT' );
		return true;
	}

	private static function extract_fields( $record ) {
		$raw = $record->get( 'fields' );
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( $raw as $id => $field ) {
			$value = is_array( $field ) && isset( $field['value'] ) ? $field['value'] : '';
			$out[ sanitize_key( $id ) ] = is_scalar( $value ) ? trim( (string) $value ) : '';
		}
		return $out;
	}

	private static function find_date_range( $fields ) {
		foreach ( $fields as $value ) {
			if ( preg_match_all( '/\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}/u', $value, $matches ) && count( $matches[0] ) >= 2 ) {
				return array( str_replace( '-', '/', $matches[0][0] ), str_replace( '-', '/', $matches[0][1] ) );
			}
		}
		return false;
	}

	private static function find_field_value( $fields, $pattern ) {
		foreach ( $fields as $key => $value ) {
			if ( preg_match( $pattern, $key ) && '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	public static function handle_elementor_submission( $record, $handler ) {
		if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'MTAL_Persian_Calendar' ) ) {
			return;
		}
		$fields = self::extract_fields( $record );
		$suite_id = isset( $fields['mtal_post_id'] ) ? absint( $fields['mtal_post_id'] ) : 0;
		$range = self::find_date_range( $fields );
		$guests = isset( $fields['mtal_qty'] ) ? absint( $fields['mtal_qty'] ) : 0;
		if ( ! $suite_id || ! $range || $guests < 1 ) {
			return;
		}
		$price = MTAL_Persian_Calendar::calculate_stay_price( $suite_id, $range[0], $range[1], $guests );
		if ( is_wp_error( $price ) ) {
			if ( is_object( $handler ) && method_exists( $handler, 'add_error_message' ) ) {
				$handler->add_error_message( $price->get_error_message() );
			}
			return;
		}

		$expires = time() + ( self::HOLD_MINUTES * MINUTE_IN_SECONDS );
		try {
			$order = wc_create_order( array( 'customer_id' => get_current_user_id() ) );
		} catch ( Exception $e ) {
			return;
		}
		if ( is_wp_error( $order ) || ! $order ) {
			return;
		}

		$item = new WC_Order_Item_Fee();
		$item->set_name( 'درخواست رزرو - ' . get_the_title( $suite_id ) );
		$item->set_amount( (float) $price['totalPrice'] );
		$item->set_total( (float) $price['totalPrice'] );
		$item->set_tax_status( 'none' );
		$order->add_item( $item );

		$name = self::find_field_value( $fields, '/(name|fullname|full_name|first_name)/i' );
		$phone = self::find_field_value( $fields, '/(phone|mobile|tel|cell)/i' );
		$email = self::find_field_value( $fields, '/email/i' );
		if ( $name ) {
			$order->set_billing_first_name( sanitize_text_field( $name ) );
		}
		if ( $phone ) {
			$order->set_billing_phone( sanitize_text_field( $phone ) );
		}
		if ( $email && is_email( $email ) ) {
			$order->set_billing_email( sanitize_email( $email ) );
		}
		$order->set_currency( get_woocommerce_currency() );
		$order->calculate_totals( false );
		$order->update_meta_data( '_mtal_booking_suite_id', $suite_id );
		$order->update_meta_data( '_mtal_booking_check_in', $range[0] );
		$order->update_meta_data( '_mtal_booking_check_out', $range[1] );
		$order->update_meta_data( '_mtal_booking_guests', $guests );
		$order->update_meta_data( '_mtal_booking_expires_at', $expires );
		$order->update_meta_data( '_mtal_booking_total', (int) $price['totalPrice'] );
		$order->save();

		$dates = array();
		$start = MTAL_Persian_Calendar::normalize_booking_date( $range[0] );
		$end = MTAL_Persian_Calendar::normalize_booking_date( $range[1] );
		if ( ! $start || ! $end ) {
			$order->update_status( 'cancelled', 'تاریخ‌های درخواست رزرو معتبر نبودند.' );
			return;
		}
		for ( $jdn = $start['jdn']; $jdn < $end['jdn']; $jdn++ ) {
			$day = MTAL_Persian_Calendar::booking_jdn_to_jalali( $jdn );
			$dates[] = sprintf( '%04d/%02d/%02d', $day[0], $day[1], $day[2] );
		}
		$claimed = self::claim_dates( $suite_id, $dates, $order->get_id(), $expires );
		if ( is_wp_error( $claimed ) ) {
			$order->update_status( 'cancelled', $claimed->get_error_message() );
			if ( is_object( $handler ) && method_exists( $handler, 'add_error_message' ) ) {
				$handler->add_error_message( $claimed->get_error_message() );
			}
			return;
		}
		$order->update_status( 'mtal-pending-admin', 'درخواست رزرو ثبت شد؛ مهلت تصمیم‌گیری و پرداخت ۳۰ دقیقه است.' );
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( $expires, self::CRON_HOOK, array( $order->get_id() ) );
		}
		if ( is_object( $handler ) && method_exists( $handler, 'add_response_data' ) ) {
			$handler->add_response_data( 'mtal_booking_order_id', $order->get_id() );
			$handler->add_response_data( 'mtal_booking_expires_at', $expires );
		}
	}

	public static function add_order_metabox() {
		add_meta_box(
			'mtal-booking-decision',
			'مدیریت درخواست رزرو',
			array( __CLASS__, 'render_order_metabox' ),
			'shop_order',
			'side',
			'high'
		);
	}

	public static function render_order_metabox( $post ) {
		$order = wc_get_order( $post->ID );
		if ( ! $order || ! $order->get_meta( '_mtal_booking_suite_id' ) ) {
			echo '<p>این سفارش مربوط به رزرو تقویم MTAL نیست.</p>';
			return;
		}
		$status = $order->get_status();
		$expires = (int) $order->get_meta( '_mtal_booking_expires_at' );
		echo '<p>تاریخ: ' . esc_html( $order->get_meta( '_mtal_booking_check_in' ) . ' تا ' . $order->get_meta( '_mtal_booking_check_out' ) ) . '</p>';
		echo '<p>مهلت: ' . esc_html( $expires ? wp_date( 'Y-m-d H:i:s', $expires ) : 'پایان یافته' ) . '</p>';
		if ( 'mtal-pending-admin' !== $status ) {
			echo '<p>وضعیت فعلی: ' . esc_html( wc_get_order_status_name( $status ) ) . '</p>';
			return;
		}
		$url = admin_url( 'admin-post.php?action=mtal_booking_decision&order_id=' . $order->get_id() );
		$nonce = wp_create_nonce( 'mtal_booking_decision_' . $order->get_id() );
		echo '<p><a class="button button-primary" href="' . esc_url( add_query_arg( array( 'decision' => 'approve', '_wpnonce' => $nonce ), $url ) ) . '">تأیید درخواست و ارسال لینک پرداخت</a></p>';
		echo '<p><a class="button" href="' . esc_url( add_query_arg( array( 'decision' => 'reject', '_wpnonce' => $nonce ), $url ) ) . '" onclick="return confirm(\'درخواست رد و تاریخ‌ها آزاد شوند؟\')">رد درخواست و آزادسازی تاریخ‌ها</a></p>';
	}

	public static function handle_admin_decision() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$decision = isset( $_GET['decision'] ) ? sanitize_key( wp_unslash( $_GET['decision'] ) ) : '';
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $order_id || ! in_array( $decision, array( 'approve', 'reject' ), true ) ) {
			wp_die( 'دسترسی غیرمجاز است.' );
		}
		check_admin_referer( 'mtal_booking_decision_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order || 'mtal-pending-admin' !== $order->get_status() ) {
			wp_die( 'این درخواست دیگر در انتظار تصمیم مدیر نیست.' );
		}
		$expires = (int) $order->get_meta( '_mtal_booking_expires_at' );
		if ( $expires <= time() ) {
			$order->update_status( 'cancelled', 'مهلت ۳۰ دقیقه‌ای درخواست به پایان رسید.' );
			self::release_order_holds( $order_id );
			wp_safe_redirect( admin_url( 'post.php?post=' . $order_id . '&action=edit&mtal_booking_expired=1' ) );
			exit;
		}
		if ( 'reject' === $decision ) {
			$order->update_status( 'cancelled', 'درخواست رزرو توسط مدیر رد شد؛ تاریخ‌ها آزاد شدند.' );
			self::release_order_holds( $order_id );
		} else {
			$order->update_status( 'mtal-awaiting-payment', 'درخواست توسط مدیر تأیید شد؛ لینک پرداخت برای مشتری فعال است.' );
			$order->add_order_note( 'لینک پرداخت: ' . $order->get_checkout_payment_url() );
		}
		wp_safe_redirect( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) );
		exit;
	}

	public static function expire_order( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order ) {
			return;
		}
		if ( ! in_array( $order->get_status(), array( 'mtal-pending-admin', 'mtal-awaiting-payment' ), true ) ) {
			return;
		}
		$expires = (int) $order->get_meta( '_mtal_booking_expires_at' );
		if ( $expires > time() ) {
			wp_schedule_single_event( $expires, self::CRON_HOOK, array( $order->get_id() ) );
			return;
		}
		$order->update_status( 'cancelled', 'مهلت ۳۰ دقیقه‌ای درخواست/پرداخت به پایان رسید؛ تاریخ‌ها آزاد شدند.' );
		self::release_order_holds( $order->get_id() );
	}

	public static function confirm_paid_booking( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order || ! $order->get_meta( '_mtal_booking_suite_id' ) ) {
			return;
		}
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array( 'expires_at' => 0 ),
			array( 'order_id' => $order->get_id() ),
			array( '%d' ),
			array( '%d' )
		);
		$order->delete_meta_data( '_mtal_booking_expires_at' );
		$order->save();
	}

	public static function release_order_holds( $order_id ) {
		global $wpdb;
		$wpdb->delete( self::table_name(), array( 'order_id' => absint( $order_id ) ), array( '%d' ) );
	}
}
MTAL_Booking_Workflow::init();
