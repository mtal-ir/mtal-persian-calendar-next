<?php
/**
 * Admin booking-management screen for MTAL Persian Calendar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MTAL_Booking_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	/**
	 * Administrators and WooCommerce managers may manage bookings.
	 */
	public static function current_user_can_manage_bookings() {
		return current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' );
	}

	public static function register_menu() {
		if ( ! self::current_user_can_manage_bookings() ) {
			return;
		}

		add_menu_page(
			'مدیریت رزروها',
			'تقویم فارسی MTAL',
			'read',
			'mtal-booking-management',
			array( __CLASS__, 'render_page' ),
			'dashicons-calendar-alt',
			56
		);
	}

	public static function render_page() {
		if ( ! self::current_user_can_manage_bookings() ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', 'mtal-persian-calendar' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>مدیریت رزروها</h1>';
		echo '<p>درخواست‌های رزرو ثبت‌شده از طریق تقویم MTAL در این صفحه نمایش داده می‌شوند.</p>';

		if ( ! function_exists( 'wc_get_orders' ) ) {
			echo '<div class="notice notice-warning"><p>برای مشاهده و مدیریت رزروها، ووکامرس باید فعال باشد.</p></div>';
			echo '</div>';
			return;
		}

		$status_options = array(
			'all'               => 'همه وضعیت‌ها',
			'mtal-pending' => 'در انتظار تأیید مدیر',
			'pending'           => 'در انتظار پرداخت',
			'processing'        => 'در حال انجام',
			'completed'         => 'تکمیل‌شده',
			'on-hold'           => 'در انتظار بررسی',
			'cancelled'         => 'لغوشده',
			'failed'            => 'ناموفق',
			'refunded'          => 'مستردشده',
		);
		$selected_status = isset( $_GET['booking_status'] )
			? sanitize_key( wp_unslash( $_GET['booking_status'] ) )
			: 'all';
		if ( ! isset( $status_options[ $selected_status ] ) ) {
			$selected_status = 'all';
		}

		$all_statuses = array_keys( $status_options );
		unset( $all_statuses[ array_search( 'all', $all_statuses, true ) ] );
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$args = array(
			'type'       => 'shop_order',
			'limit'      => 20,
			'page'       => $paged,
			'paginate'   => true,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'status'     => 'all' === $selected_status ? array_values( $all_statuses ) : array( $selected_status ),
			'meta_query' => array(
				array(
					'key'     => '_mtal_booking_suite_id',
					'compare' => 'EXISTS',
				),
			),
		);

		$results = wc_get_orders( $args );
		$orders  = is_object( $results ) && isset( $results->orders ) ? $results->orders : array();
		$total_pages = is_object( $results ) && isset( $results->max_num_pages ) ? (int) $results->max_num_pages : 1;

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="margin:16px 0;">';
		echo '<input type="hidden" name="page" value="mtal-booking-management">';
		echo '<label for="mtal-booking-status">فیلتر وضعیت: </label> ';
		echo '<select id="mtal-booking-status" name="booking_status">';
		foreach ( $status_options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected_status, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		submit_button( 'اعمال فیلتر', 'secondary', '', false );
		echo '</form>';

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>شماره سفارش</th><th>مشتری و تماس</th><th>سوئیت</th><th>تاریخ اقامت</th><th>نفرات</th><th>مبلغ</th><th>وضعیت / مهلت</th><th>عملیات</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $orders ) ) {
			echo '<tr><td colspan="8">درخواستی برای نمایش پیدا نشد.</td></tr>';
		} else {
			foreach ( $orders as $order ) {
				if ( ! $order instanceof WC_Order || ! $order->get_meta( '_mtal_booking_suite_id' ) ) {
					continue;
				}
				$order_id = $order->get_id();
				$suite_id = absint( $order->get_meta( '_mtal_booking_suite_id' ) );
				$suite_name = get_the_title( $suite_id );
				$suite_name = $suite_name ? $suite_name : 'سوئیت #' . $suite_id;
				$customer = trim( $order->get_formatted_billing_full_name() );
				$customer = $customer ? $customer : 'مهمان';
				$phone = $order->get_billing_phone();
				$check_in = $order->get_meta( '_mtal_booking_check_in' );
				$check_out = $order->get_meta( '_mtal_booking_check_out' );
				$guests = absint( $order->get_meta( '_mtal_booking_guests' ) );
				$status = $order->get_status();
				$expires = absint( $order->get_meta( '_mtal_booking_expires_at' ) );
				$created = $order->get_date_created();
				$edit_url = method_exists( $order, 'get_edit_order_url' )
					? $order->get_edit_order_url()
					: admin_url( 'post.php?post=' . $order_id . '&action=edit' );

				echo '<tr>';
				echo '<td><a href="' . esc_url( $edit_url ) . '">#' . esc_html( $order->get_order_number() ) . '</a>';
				if ( $created ) {
					echo '<br><small>' . esc_html( wc_format_datetime( $created ) ) . '</small>';
				}
				echo '</td>';
				echo '<td>' . esc_html( $customer ) . '<br><small>' . esc_html( $phone ? $phone : '—' ) . '</small></td>';
				echo '<td>' . esc_html( $suite_name ) . '</td>';
				echo '<td>' . esc_html( $check_in . ' تا ' . $check_out ) . '</td>';
				echo '<td>' . esc_html( $guests ) . '</td>';
				echo '<td>' . wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) . '</td>';
				echo '<td>' . esc_html( wc_get_order_status_name( $status ) );
				if ( $expires && in_array( $status, array( 'mtal-pending', 'pending' ), true ) ) {
					echo '<br><small>';
					echo $expires > time()
						? esc_html( 'مهلت باقی‌مانده: ' . human_time_diff( time(), $expires ) )
						: esc_html( 'مهلت پایان یافته' );
					echo '</small>';
				}
				echo '</td>';
				echo '<td><a class="button button-small" href="' . esc_url( $edit_url ) . '">جزئیات</a>';
				if ( 'mtal-pending' === $status ) {
					$decision_url = add_query_arg(
						array(
							'action'   => 'mtal_booking_decision',
							'order_id' => $order_id,
						),
						admin_url( 'admin-post.php' )
					);
					$nonce = 'mtal_booking_decision_' . $order_id;
					$approve_url = wp_nonce_url( add_query_arg( 'decision', 'approve', $decision_url ), $nonce );
					$reject_url  = wp_nonce_url( add_query_arg( 'decision', 'reject', $decision_url ), $nonce );
					echo '<br><a class="button button-primary button-small" style="margin-top:5px;" href="' . esc_url( $approve_url ) . '">تأیید</a> ';
					echo '<a class="button button-small" style="margin-top:5px;" href="' . esc_url( $reject_url ) . '" onclick="return confirm(\'درخواست رد و تاریخ‌ها آزاد شوند؟\')">رد</a>';
				}
				echo '</td></tr>';
			}
		}

		echo '</tbody></table>';

		if ( $total_pages > 1 ) {
			$base_url = add_query_arg(
				array(
					'page'           => 'mtal-booking-management',
					'booking_status' => $selected_status,
					'paged'          => '%#%',
				),
				admin_url( 'admin.php' )
			);
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post( paginate_links( array(
				'base'      => $base_url,
				'format'    => '',
				'current'   => $paged,
				'total'     => $total_pages,
				'prev_text' => '« قبلی',
				'next_text' => 'بعدی »',
			) ) );
			echo '</div></div>';
		}

		echo '</div>';
	}
}

MTAL_Booking_Admin::init();
