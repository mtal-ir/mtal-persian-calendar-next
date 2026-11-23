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
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ), 5 );
		add_filter( 'woocommerce_order_needs_payment', array( __CLASS__, 'prevent_expired_payment' ), 10, 3 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'render_hpos_order_actions' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_order_statuses' ) );
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'handle_elementor_submission' ), 20, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_metabox' ) );
		add_action( 'admin_post_mtal_booking_decision', array( __CLASS__, 'handle_admin_decision' ) );
		add_action( 'wp_ajax_mtal_booking_status', array( __CLASS__, 'ajax_booking_status' ) );
		add_action( 'wp_ajax_nopriv_mtal_booking_status', array( __CLASS__, 'ajax_booking_status' ) );
		add_action( 'wp_ajax_mtal_booking_cancel', array( __CLASS__, 'ajax_cancel_booking' ) );
		add_action( 'wp_ajax_nopriv_mtal_booking_cancel', array( __CLASS__, 'ajax_cancel_booking' ) );
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
		update_option( 'mtal_pc_booking_db_version', '1', false );
	}

	public static function maybe_upgrade() {
		if ( '1' !== get_option( 'mtal_pc_booking_db_version' ) ) {
			self::activate();
		}
	}

	public static function register_order_statuses() {
		register_post_status( 'wc-mtal-pending', array(
			'label' => 'در انتظار تأیید مدیر',
			'public' => false,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
			'label_count' => _n_noop( 'در انتظار تأیید مدیر <span class="count">(%s)</span>', 'در انتظار تأیید مدیر <span class="count">(%s)</span>' ),
		) );
	}

	public static function add_order_statuses( $statuses ) {
		$statuses['wc-mtal-pending'] = 'در انتظار تأیید مدیر';
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
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			$value = strtr( $value, array( '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9' ) );
			$out[ sanitize_key( $id ) ] = $value;
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
		if ( ! $suite_id && isset( $_POST['post_id'] ) ) {
			$suite_id = absint( wp_unslash( $_POST['post_id'] ) );
		}
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
		$access_token = wp_generate_password( 48, false, false );
		$order->update_meta_data( '_mtal_booking_access_token', wp_hash( $access_token ) );
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
		$order->update_status( 'mtal-pending', 'درخواست رزرو ثبت شد؛ مهلت تصمیم‌گیری و پرداخت ۳۰ دقیقه است.' );
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( $expires, self::CRON_HOOK, array( $order->get_id() ) );
		}
		if ( is_object( $handler ) && method_exists( $handler, 'add_response_data' ) ) {
			$handler->add_response_data( 'mtal_booking_order_id', $order->get_id() );
			$handler->add_response_data( 'mtal_booking_expires_at', $expires );
			$handler->add_response_data( 'mtal_booking_access_token', $access_token );
			$handler->add_response_data( 'mtal_booking_check_in', $range[0] );
			$handler->add_response_data( 'mtal_booking_check_out', $range[1] );
			$handler->add_response_data( 'mtal_booking_guests', $guests );
		}
	}

	public static function ajax_cancel_booking() {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$order = $order_id ? wc_get_order( $order_id ) : false;
		$stored = $order ? (string) $order->get_meta( '_mtal_booking_access_token' ) : '';
		if ( ! $order || ! $stored || ! $token || ! hash_equals( $stored, wp_hash( $token ) ) ) {
			wp_send_json_error( array( 'message' => 'درخواست رزرو پیدا نشد یا دسترسی معتبر نیست.' ), 403 );
		}
		// حداکثر سه تلاش لغو برای هر درخواست؛ شمارنده سمت سرور قابل دور زدن با رفرش صفحه نیست.
		$attempt_key = 'mtal_cancel_attempts_' . $order_id . '_' . substr( $stored, 0, 12 );
		$attempts = (int) get_transient( $attempt_key );
		if ( $attempts >= 3 ) {
			wp_send_json_error( array( 'message' => 'حداکثر ۳ بار تلاش برای لغو این درخواست مجاز است.' ), 429 );
		}
		set_transient( $attempt_key, $attempts + 1, DAY_IN_SECONDS );

		if ( 'mtal-pending' !== $order->get_status() ) {
			wp_send_json_error( array( 'message' => 'این درخواست دیگر در وضعیت قابل لغو توسط کاربر نیست.' ), 409 );
		}
		$order->update_status( 'cancelled', 'درخواست توسط کاربر از طریق صفحه رزرو لغو شد؛ تاریخ‌ها آزاد شدند.' );
		self::release_order_holds( $order->get_id() );
		wp_send_json_success( array( 'message' => 'درخواست شما لغو شد و تاریخ‌های انتخابی آزاد شدند.' ) );
	}

	public static function ajax_booking_status() {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$order = $order_id ? wc_get_order( $order_id ) : false;
		$stored = $order ? (string) $order->get_meta( '_mtal_booking_access_token' ) : '';
		if ( ! $order || ! $stored || ! $token || ! hash_equals( $stored, wp_hash( $token ) ) ) {
			wp_send_json_error( array( 'message' => 'درخواست رزرو پیدا نشد یا دسترسی معتبر نیست.' ), 403 );
		}
		$status = $order->get_status();
		$messages = array(
			'mtal-pending' => 'درخواست شما ثبت شده و در انتظار بررسی مدیر است.',
			'pending' => 'درخواست شما تأیید شد؛ برای تکمیل رزرو، پرداخت را انجام دهید.',
			'cancelled' => 'درخواست رزرو شما رد یا منقضی شد و تاریخ‌ها آزاد شدند.',
			'processing' => 'پرداخت شما دریافت شد و رزرو تأیید شده است.',
			'completed' => 'رزرو شما با موفقیت تأیید شد.',
			'on-hold' => 'پرداخت/رزرو شما نیازمند بررسی مدیر است.',
		);
		wp_send_json_success( array(
			'status' => $status,
			'statusLabel' => wc_get_order_status_name( $status ),
			'message' => isset( $messages[ $status ] ) ? $messages[ $status ] : 'وضعیت درخواست شما: ' . wc_get_order_status_name( $status ),
			'expiresAt' => (int) $order->get_meta( '_mtal_booking_expires_at' ),
			'isFinal' => ! in_array( $status, array( 'mtal-pending', 'pending' ), true ),
		) );
	}

	private static function email_booking_decision( $order, $approved ) {
		$email = $order->get_billing_email();
		if ( ! $email || ! is_email( $email ) || ! function_exists( 'WC' ) || ! WC()->mailer() ) return;
		$subject = $approved ? 'درخواست رزرو شما تأیید شد' : 'وضعیت درخواست رزرو شما';
		$message = $approved
			? 'درخواست رزرو شما تأیید شد. برای تکمیل رزرو، از طریق لینک زیر پرداخت را انجام دهید: ' . $order->get_checkout_payment_url()
			: 'متأسفانه درخواست رزرو شما تأیید نشد و تاریخ‌های انتخابی آزاد شدند. برای اطلاعات بیشتر با پشتیبانی تماس بگیرید.';
		wp_mail( $email, $subject, $message, array( 'Content-Type: text/plain; charset=UTF-8' ) );
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
		if ( 'mtal-pending' !== $status ) {
			echo '<p>وضعیت فعلی: ' . esc_html( wc_get_order_status_name( $status ) ) . '</p>';
			return;
		}
		$url = admin_url( 'admin-post.php?action=mtal_booking_decision&order_id=' . $order->get_id() );
		$nonce = wp_create_nonce( 'mtal_booking_decision_' . $order->get_id() );
		echo '<p><a class="button button-primary" href="' . esc_url( add_query_arg( array( 'decision' => 'approve', '_wpnonce' => $nonce ), $url ) ) . '">تأیید درخواست و ارسال لینک پرداخت</a></p>';
		echo '<p><a class="button" href="' . esc_url( add_query_arg( array( 'decision' => 'reject', '_wpnonce' => $nonce ), $url ) ) . '" onclick="return confirm(\'درخواست رد و تاریخ‌ها آزاد شوند؟\')">رد درخواست و آزادسازی تاریخ‌ها</a></p>';
	}


	public static function render_hpos_order_actions( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) || ! $order->get_meta( '_mtal_booking_suite_id' ) || 'mtal-pending' !== $order->get_status() ) {
			return;
		}
		$order_id = $order->get_id();
		$base = admin_url( 'admin-post.php?action=mtal_booking_decision&order_id=' . $order_id );
		$nonce = wp_create_nonce( 'mtal_booking_decision_' . $order_id );
		echo '<p><strong>درخواست رزرو MTAL</strong></p>';
		echo '<p><a class="button button-primary" href="' . esc_url( add_query_arg( array( 'decision' => 'approve', '_wpnonce' => $nonce ), $base ) ) . '">تأیید و ارسال لینک پرداخت</a> ';
		echo '<a class="button" href="' . esc_url( add_query_arg( array( 'decision' => 'reject', '_wpnonce' => $nonce ), $base ) ) . '">رد و آزادسازی تاریخ‌ها</a></p>';
	}

	public static function prevent_expired_payment( $needs_payment, $order, $valid_statuses ) {
		if ( $order && $order->get_meta( '_mtal_booking_suite_id' ) ) {
			$expires = (int) $order->get_meta( '_mtal_booking_expires_at' );
			if ( $expires && $expires <= time() ) {
				return false;
			}
		}
		return $needs_payment;
	}

	public static function handle_admin_decision() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$decision = isset( $_GET['decision'] ) ? sanitize_key( wp_unslash( $_GET['decision'] ) ) : '';
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $order_id || ! in_array( $decision, array( 'approve', 'reject' ), true ) ) {
			wp_die( 'دسترسی غیرمجاز است.' );
		}
		check_admin_referer( 'mtal_booking_decision_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order || 'mtal-pending' !== $order->get_status() ) {
			wp_die( 'این درخواست دیگر در انتظار تصمیم مدیر نیست.' );
		}
		$expires = (int) $order->get_meta( '_mtal_booking_expires_at' );
		if ( $expires <= time() ) {
			$order->update_status( 'cancelled', 'مهلت ۳۰ دقیقه‌ای درخواست به پایان رسید.' );
			self::release_order_holds( $order_id );
			$redirect = wp_get_referer();
			if ( ! $redirect ) {
				$redirect = admin_url( 'post.php?post=' . $order_id . '&action=edit&mtal_booking_expired=1' );
			}
			wp_safe_redirect( $redirect );
			exit;
		}
		if ( 'reject' === $decision ) {
			$order->update_status( 'cancelled', 'درخواست رزرو توسط مدیر رد شد؛ تاریخ‌ها آزاد شدند.' );
			self::release_order_holds( $order_id );
			self::email_booking_decision( $order, false );
		} else {
			$order->update_status( 'pending', 'درخواست توسط مدیر تأیید شد؛ لینک پرداخت برای مشتری فعال است.' );
			$order->add_order_note( 'لینک پرداخت: ' . $order->get_checkout_payment_url() );
			self::email_booking_decision( $order, true );
		}
		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	public static function expire_order( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order ) {
			return;
		}
		if ( ! in_array( $order->get_status(), array( 'mtal-pending', 'pending' ), true ) ) {
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
		$expires = (int) $order->get_meta( '_mtal_booking_expires_at' );
		if ( $expires && $expires <= time() ) {
			$order->add_order_note( 'پرداخت پس از پایان مهلت ۳۰ دقیقه‌ای دریافت شد؛ رزرو به‌صورت خودکار قطعی نشد و نیازمند بررسی مدیر است.' );
			$order->update_status( 'on-hold' );
			self::release_order_holds( $order->get_id() );
			$order->update_meta_data( '_mtal_booking_late_payment', 'yes' );
			$order->save();
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
