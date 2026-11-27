<?php
/**
 * Privileged MTAL booking list inside the WooCommerce My Account area.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MTAL_My_Account_Bookings {

	const ENDPOINT = 'mtal-bookings';
	const REWRITE_VERSION = '1';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_endpoint' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite_rules' ), 99 );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_account_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_endpoint' ) );
		add_action( 'template_redirect', array( __CLASS__, 'protect_endpoint' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function can_manage_bookings() {
		if ( class_exists( 'MTAL_Booking_Admin' ) ) {
			return MTAL_Booking_Admin::current_user_can_manage_bookings();
		}
		return current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' );
	}

	public static function register_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public static function maybe_flush_rewrite_rules() {
		if ( self::REWRITE_VERSION === get_option( 'mtal_my_account_bookings_rewrite_version' ) ) {
			return;
		}
		self::register_endpoint();
		flush_rewrite_rules( false );
		update_option( 'mtal_my_account_bookings_rewrite_version', self::REWRITE_VERSION, false );
	}

	public static function activate() {
		if ( class_exists( 'MTAL_Booking_Workflow' ) ) {
			MTAL_Booking_Workflow::activate();
		}
		self::register_endpoint();
		flush_rewrite_rules();
		update_option( 'mtal_my_account_bookings_rewrite_version', self::REWRITE_VERSION, false );
	}

	public static function add_account_menu_item( $items ) {
		if ( ! self::can_manage_bookings() ) {
			return $items;
		}

		$logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : null;
		unset( $items['customer-logout'] );
		$items[ self::ENDPOINT ] = 'مدیریت رزروها';
		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}

	public static function protect_endpoint() {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::ENDPOINT ) && ! self::can_manage_bookings() ) {
			wp_die(
				esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', 'mtal-persian-calendar' ),
				esc_html__( 'دسترسی غیرمجاز', 'mtal-persian-calendar' ),
				array( 'response' => 403 )
			);
		}
	}

	public static function enqueue_assets() {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( self::ENDPOINT ) || ! self::can_manage_bookings() ) {
			return;
		}

		wp_register_style( 'mtal-my-account-bookings', false, array(), MTAL_PC_VERSION );
		wp_enqueue_style( 'mtal-my-account-bookings' );
		wp_add_inline_style(
			'mtal-my-account-bookings',
			'.mtal-account-bookings{display:grid;gap:14px}.mtal-account-booking{border:1px solid #e5e7eb;border-radius:12px;padding:16px;background:#fff}.mtal-account-booking__head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:12px}.mtal-account-booking__title{font-weight:700}.mtal-account-booking__status{display:inline-block;border-radius:20px;padding:4px 10px;background:#f3f4f6;font-size:12px}.mtal-account-booking__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 18px}.mtal-account-booking__field{min-width:0}.mtal-account-booking__label{display:block;color:#6b7280;font-size:12px;margin-bottom:3px}.mtal-account-booking__value{overflow-wrap:anywhere}.mtal-account-booking__notice{margin-top:12px;color:#92400e;font-size:13px}@media(max-width:600px){.mtal-account-booking__grid{grid-template-columns:1fr}.mtal-account-booking{padding:13px}}'
		);
	}

	private static function get_status_options() {
		return array(
			'all'         => 'همه وضعیت‌ها',
			'mtal-pending'=> 'در انتظار تأیید مدیر',
			'pending'     => 'در انتظار پرداخت',
			'processing'  => 'در حال انجام',
			'completed'   => 'تکمیل‌شده',
			'on-hold'     => 'در انتظار بررسی',
			'cancelled'   => 'لغوشده',
			'failed'      => 'ناموفق',
			'refunded'    => 'مستردشده',
		);
	}

	private static function get_orders( $status, $page, $limit = 10 ) {
		$statuses = array_keys( self::get_status_options() );
		$statuses = array_values( array_diff( $statuses, array( 'all' ) ) );
		return wc_get_orders(
			array(
				'type'       => 'shop_order',
				'limit'      => $limit,
				'page'       => max( 1, absint( $page ) ),
				'paginate'   => true,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'status'     => 'all' === $status ? $statuses : array( $status ),
				'meta_query' => array(
					array(
						'key'     => '_mtal_booking_suite_id',
						'compare' => 'EXISTS',
					),
				),
			)
		);
	}

	private static function render_field( $label, $value ) {
		echo '<div class="mtal-account-booking__field"><span class="mtal-account-booking__label">' . esc_html( $label ) . '</span><span class="mtal-account-booking__value">' . wp_kses_post( $value ) . '</span></div>';
	}

	public static function render_endpoint() {
		if ( ! self::can_manage_bookings() ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', 'mtal-persian-calendar' ), '', array( 'response' => 403 ) );
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			echo '<p>برای مشاهده رزروها، ووکامرس باید فعال باشد.</p>';
			return;
		}

		$options = self::get_status_options();
		$status = isset( $_GET['mtal_booking_status'] ) ? sanitize_key( wp_unslash( $_GET['mtal_booking_status'] ) ) : 'all';
		if ( ! isset( $options[ $status ] ) ) {
			$status = 'all';
		}
		$page = isset( $_GET['mtal_booking_page'] ) ? max( 1, absint( $_GET['mtal_booking_page'] ) ) : 1;

		$pending = wc_get_orders(
			array(
				'type'       => 'shop_order',
				'status'     => array( 'mtal-pending' ),
				'limit'      => 1,
				'paginate'   => true,
				'return'     => 'ids',
				'meta_query' => array(
					array( 'key' => '_mtal_booking_suite_id', 'compare' => 'EXISTS' ),
				),
			)
		);
		$pending_count = is_object( $pending ) && isset( $pending->total ) ? absint( $pending->total ) : 0;

		echo '<div class="mtal-account-bookings-page">';
		echo '<h3>مدیریت رزروها</h3>';
		echo '<p>درخواست‌های ثبت‌شده از طریق تقویم MTAL</p>';
		echo '<p><strong>در انتظار تأیید مدیر: ' . esc_html( number_format_i18n( $pending_count ) ) . '</strong></p>';

		echo '<form method="get" class="mtal-account-bookings-filter">';
		foreach ( $_GET as $key => $value ) {
			if ( in_array( $key, array( 'mtal_booking_status', 'mtal_booking_page' ), true ) || ! is_scalar( $value ) ) {
				continue;
			}
			echo '<input type="hidden" name="' . esc_attr( sanitize_key( $key ) ) . '" value="' . esc_attr( wp_unslash( $value ) ) . '">';
		}
		echo '<label for="mtal-booking-status">فیلتر وضعیت </label> ';
		echo '<select id="mtal-booking-status" name="mtal_booking_status">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $status, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <button type="submit">اعمال فیلتر</button></form>';

		$results = self::get_orders( $status, $page );
		$orders = is_object( $results ) && isset( $results->orders ) ? $results->orders : array();
		$total_pages = is_object( $results ) && isset( $results->max_num_pages ) ? max( 1, absint( $results->max_num_pages ) ) : 1;

		if ( empty( $orders ) ) {
			echo '<p>رزروی با این فیلتر پیدا نشد.</p>';
		} else {
			echo '<div class="mtal-account-bookings">';
			foreach ( $orders as $order ) {
				if ( ! $order instanceof WC_Order || ! $order->get_meta( '_mtal_booking_suite_id' ) ) {
					continue;
				}
				$suite_id = absint( $order->get_meta( '_mtal_booking_suite_id' ) );
				$suite_name = get_the_title( $suite_id );
				$suite_name = $suite_name ? $suite_name : 'سوئیت #' . $suite_id;
				$name = trim( $order->get_formatted_billing_full_name() );
				$name = $name ? $name : 'مهمان';
				$phone = $order->get_billing_phone();
				$check_in = (string) $order->get_meta( '_mtal_booking_check_in' );
				$check_out = (string) $order->get_meta( '_mtal_booking_check_out' );
				$guests = absint( $order->get_meta( '_mtal_booking_guests' ) );
				$status_label = wc_get_order_status_name( $order->get_status() );
				$expires = absint( $order->get_meta( '_mtal_booking_expires_at' ) );

				echo '<article class="mtal-account-booking">';
				echo '<div class="mtal-account-booking__head"><span class="mtal-account-booking__title">رزرو #' . esc_html( $order->get_order_number() ) . '</span><span class="mtal-account-booking__status">' . esc_html( $status_label ) . '</span></div>';
				echo '<div class="mtal-account-booking__grid">';
				self::render_field( 'مشتری', esc_html( $name ) );
				self::render_field( 'شماره تماس', $phone ? esc_html( $phone ) : '—' );
				self::render_field( 'سوئیت', esc_html( $suite_name ) );
				self::render_field( 'تاریخ اقامت', esc_html( ( $check_in ? $check_in : '—' ) . ' تا ' . ( $check_out ? $check_out : '—' ) ) );
				self::render_field( 'تعداد مسافران', $guests ? esc_html( $guests . ' نفر' ) : '—' );
				self::render_field( 'مبلغ', wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) );
				self::render_field( 'تاریخ ثبت', $order->get_date_created() ? esc_html( wc_format_datetime( $order->get_date_created() ) ) : '—' );
				echo '</div>';
				if ( $expires && in_array( $order->get_status(), array( 'mtal-pending', 'pending' ), true ) ) {
					$remaining = $expires > time() ? 'مهلت باقی‌مانده: ' . human_time_diff( time(), $expires ) : 'مهلت درخواست پایان یافته است';
					echo '<p class="mtal-account-booking__notice">' . esc_html( $remaining ) . '</p>';
				}
				echo '</article>';
			}
			echo '</div>';
		}

		if ( $total_pages > 1 ) {
			echo '<nav class="woocommerce-pagination mtal-account-bookings-pagination" aria-label="صفحه‌بندی رزروها">';
			for ( $i = 1; $i <= $total_pages; $i++ ) {
				$url = add_query_arg( array( 'mtal_booking_status' => $status, 'mtal_booking_page' => $i ) );
				if ( $i === $page ) {
					echo '<span class="page-numbers current" aria-current="page">' . esc_html( $i ) . '</span> ';
				} else {
					echo '<a class="page-numbers" href="' . esc_url( $url ) . '">' . esc_html( $i ) . '</a> ';
				}
			}
			echo '</nav>';
		}
		echo '</div>';
	}
}

MTAL_My_Account_Bookings::init();
