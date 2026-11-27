<?php
/**
 * Privileged MTAL booking management inside the WooCommerce My Account area.
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
			'.mtal-account-bookings-grid{display:grid;grid-template-columns:1fr;gap:12px;width:100%;direction:rtl}.mtal-account-booking-row{width:100%;min-width:0;box-sizing:border-box;border:1px solid #e2e8f0;border-radius:12px;background:#fff;padding:12px;box-shadow:0 2px 8px rgba(15,23,42,.04)}.mtal-account-booking-row__fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px 12px}.mtal-account-booking-row__fields .mtal-account-booking__field{min-width:0;padding:9px 10px;border:1px solid #edf2f7;border-radius:8px;background:#f8fafc}.mtal-account-booking-row__actions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:10px;padding-top:10px;border-top:1px solid #edf2f7}.mtal-account-booking-row__actions form{display:flex;min-width:0;margin:0}.mtal-account-booking-row__actions button{width:100%;min-height:40px;box-sizing:border-box;border:0;border-radius:8px;padding:8px 10px;font-size:13px;font-weight:700;cursor:pointer;white-space:normal}.mtal-account-booking-row__approve{background:#15803d!important;color:#fff!important}.mtal-account-booking-row__reject{background:#b91c1c!important;color:#fff!important}.mtal-account-booking-row__details{background:#eff6ff!important;color:#1d4ed8!important;border:1px solid #bfdbfe!important}'.mtal-account-booking.mtal-account-booking{border:1px solid #e2e8f0;border-radius:14px;padding:18px;background:#fff;box-shadow:0 3px 14px rgba(15,23,42,.045);direction:rtl}.mtal-account-booking__head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #edf2f7}.mtal-account-booking__title{font-weight:800;color:#172554;font-size:16px}.mtal-account-booking__status{display:inline-flex;align-items:center;border-radius:999px;padding:6px 12px;font-size:12px;font-weight:700;background:#e2e8f0;color:#334155}.mtal-account-booking__status--mtal-pending{background:#fff1d6;color:#9a5800}.mtal-account-booking__status--pending{background:#dbeafe;color:#1d4ed8}.mtal-account-booking__status--processing,.mtal-account-booking__status--completed{background:#dcfce7;color:#166534}.mtal-account-booking__status--on-hold{background:#fef3c7;color:#92400e}.mtal-account-booking__status--cancelled,.mtal-account-booking__status--failed{background:#fee2e2;color:#991b1b}.mtal-account-booking__status--refunded{background:#f3e8ff;color:#7e22ce}.mtal-account-booking__summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px 18px}.mtal-account-booking__field{min-width:0;padding:4px 0}.mtal-account-booking__label{display:block;color:#64748b;font-size:12px;font-weight:600;margin-bottom:6px}.mtal-account-booking__value{display:block;color:#1e293b;font-size:14px;font-weight:600;line-height:1.8;overflow-wrap:anywhere}.mtal-account-booking__notice{margin:14px 0 0;padding:9px 12px;border-radius:9px;background:#fff7ed;color:#9a3412;font-size:13px;font-weight:600}.mtal-account-booking__notice--expired{background:#fee2e2;color:#991b1b}.mtal-account-booking__details{margin-top:14px}.mtal-account-booking-dialog{position:fixed;inset:0;z-index:99999;width:min(720px,calc(100% - 32px));max-width:720px;max-height:90vh;overflow:auto;margin:auto;border:1px solid #e2e8f0;border-radius:16px;padding:22px;background:#fff;box-shadow:0 20px 60px rgba(0,0,0,.25);direction:rtl;color:#1e293b}.mtal-account-booking-dialog::backdrop{background:rgba(15,23,42,.62)}.mtal-account-booking-modal__head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid #e2e8f0}.mtal-account-booking-modal__head h3{margin:0;font-size:18px}.mtal-account-booking-modal__close{border:0;background:#f1f5f9;color:#334155;border-radius:8px;padding:8px 12px;cursor:pointer}.mtal-account-booking__table-wrap{width:100%;overflow-x:auto;border:1px solid #e2e8f0;border-radius:10px}.mtal-account-booking__table{width:100%;border-collapse:collapse;margin:0;min-width:0}.mtal-account-booking__table th,.mtal-account-booking__table td{padding:11px 14px;text-align:right;border-bottom:1px solid #edf2f7;line-height:1.8;vertical-align:top}.mtal-account-booking__table th{width:34%;background:#f8fafc;color:#475569;font-size:13px;font-weight:700;white-space:nowrap}.mtal-account-booking__table td{color:#1e293b;font-size:14px;overflow-wrap:anywhere}.mtal-account-booking__table tr:last-child th,.mtal-account-booking__table tr:last-child td{border-bottom:0}.mtal-account-booking-modal__actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb}.mtal-account-booking-modal__actions form{margin:0}.mtal-account-booking-modal__actions button{border:0;border-radius:9px;padding:11px 16px;font-weight:700;cursor:pointer}.mtal-account-booking-modal__approve{background:#15803d;color:#fff}.mtal-account-booking-modal__reject{background:#b91c1c;color:#fff}.mtal-account-booking__details-button{border:1px solid #cbd5e1;background:#f8fafc;color:#1e40af;border-radius:9px;padding:9px 15px;font-weight:700;cursor:pointer}.mtal-account-bookings-notice{padding:11px 14px;border-radius:9px;background:#eff6ff;color:#1d4ed8;margin:12px 0}@media(max-width:700px){.mtal-account-booking-row{padding:9px}.mtal-account-booking-row__fields{grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.mtal-account-booking-row__fields .mtal-account-booking__field{padding:7px}.mtal-account-booking-row__actions{gap:6px}.mtal-account-booking-row__actions button{padding:8px 5px;font-size:12px}.mtal-account-booking__summary{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px 12px}.mtal-account-booking{padding:14px}.mtal-account-booking-dialog{width:calc(100% - 16px);max-height:88vh;padding:14px;border-radius:13px}.mtal-account-booking__table th,.mtal-account-booking__table td{padding:9px 10px}.mtal-account-booking__table th{width:38%;white-space:normal}}@media(max-width:420px){.mtal-account-booking-row__fields{grid-template-columns:repeat(2,minmax(0,1fr))}.mtal-account-booking-row__actions{grid-template-columns:repeat(3,minmax(0,1fr))}.mtal-account-booking__summary{grid-template-columns:1fr}.mtal-account-booking-modal__actions{display:grid}.mtal-account-booking-modal__actions form,.mtal-account-booking-modal__actions button{width:100%}}'
		);


	}


	private static function get_status_options() {
		return array(
			'all'          => 'همه وضعیت‌ها',
			'mtal-pending' => 'در انتظار تأیید مدیر',
			'pending'      => 'در انتظار پرداخت',
			'processing'   => 'در حال انجام',
			'completed'    => 'تکمیل‌شده',
			'on-hold'      => 'در انتظار بررسی',
			'cancelled'    => 'لغوشده',
			'failed'       => 'ناموفق',
			'refunded'     => 'مستردشده',
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

	private static function render_table_row( $label, $value ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . wp_kses_post( $value ) . '</td></tr>';
	}

	private static function render_status_badge( $status, $label ) {
		$status_class = sanitize_html_class( $status );
		return '<span class="mtal-account-booking__status mtal-account-booking__status--' . esc_attr( $status_class ) . '">' . esc_html( $label ) . '</span>';
	}

	private static function get_suite_name( $suite_id ) {
		if ( class_exists( 'MTAL_Suite_Registry' ) ) {
			$display = MTAL_Suite_Registry::get_display_data( $suite_id );
			if ( is_array( $display ) && ! empty( $display['title'] ) ) {
				return $display['title'];
			}
		}
		$title = get_the_title( $suite_id );
		return $title ? $title : 'سوئیت #' . absint( $suite_id );
	}

	private static function render_decision_form( $order_id, $decision, $label, $class_name ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="mtal_my_account_booking_decision">';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( absint( $order_id ) ) . '">';
		echo '<input type="hidden" name="decision" value="' . esc_attr( $decision ) . '">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'mtal_my_account_booking_decision_' . absint( $order_id ) ) ) . '">';
		echo '<button type="submit" class="' . esc_attr( $class_name ) . '"';
		if ( 'reject' === $decision ) {
			echo ' onclick="return confirm(\'درخواست رد و تاریخ‌ها آزاد شوند؟\')"';
		}
		echo '>' . esc_html( $label ) . '</button></form>';
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

		$notice = isset( $_GET['mtal_booking_notice'] ) ? sanitize_key( wp_unslash( $_GET['mtal_booking_notice'] ) ) : '';
		$notice_messages = array(
			'decision_saved'        => 'تصمیم مدیر ثبت شد.',
			'mtal_booking_expired'  => 'مهلت درخواست به پایان رسیده و تاریخ‌ها آزاد شدند.',
			'mtal_booking_not_pending' => 'این درخواست دیگر در انتظار تصمیم مدیر نیست.',
		);
		if ( isset( $notice_messages[ $notice ] ) ) {
			echo '<p class="mtal-account-bookings-notice" role="status">' . esc_html( $notice_messages[ $notice ] ) . '</p>';
		}

		echo '<form method="get" class="mtal-account-bookings-filter">';
		foreach ( $_GET as $key => $value ) {
			if ( in_array( $key, array( 'mtal_booking_status', 'mtal_booking_page', 'mtal_booking_notice' ), true ) || ! is_scalar( $value ) ) {
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
			$booking_dialogs = '';
			echo '<div class="mtal-account-bookings-grid">';
			foreach ( $orders as $order ) {
				if ( ! $order instanceof WC_Order || ! $order->get_meta( '_mtal_booking_suite_id' ) ) {
					continue;
				}
				$order_id = $order->get_id();
				$suite_id = absint( $order->get_meta( '_mtal_booking_suite_id' ) );
				$suite_name = self::get_suite_name( $suite_id );
				$name = trim( $order->get_formatted_billing_full_name() );
				$name = $name ? $name : 'مهمان';
				$phone = $order->get_billing_phone();
				$email = $order->get_billing_email();
				$check_in = (string) $order->get_meta( '_mtal_booking_check_in' );
				$check_out = (string) $order->get_meta( '_mtal_booking_check_out' );
				$guests = absint( $order->get_meta( '_mtal_booking_guests' ) );
				$order_status = $order->get_status();
				$status_label = wc_get_order_status_name( $order_status );
				$expires = absint( $order->get_meta( '_mtal_booking_expires_at' ) );
				$is_actionable = 'mtal-pending' === $order_status && $expires > time();
				$template_id = 'mtal-booking-details-' . absint( $order_id );

				echo '<article class="mtal-account-booking-row"><div class="mtal-account-booking-row__fields">';
				self::render_field( 'شماره سفارش', '#' . esc_html( $order->get_order_number() ) );
				self::render_field( 'نام مشتری', esc_html( $name ) );
				self::render_field( 'نام سوئیت', esc_html( $suite_name ) );
				self::render_field( 'تاریخ اقامت', esc_html( ( $check_in ? $check_in : '—' ) . ' تا ' . ( $check_out ? $check_out : '—' ) ) );
				self::render_field( 'مبلغ', wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) );
				self::render_field( 'وضعیت', self::render_status_badge( $order_status, $status_label ) );
				echo '</div><div class="mtal-account-booking-row__actions">';
				if ( $is_actionable ) {
					self::render_decision_form( $order_id, 'approve', 'تأیید', 'mtal-account-booking-row__approve' );
					self::render_decision_form( $order_id, 'reject', 'رد', 'mtal-account-booking-row__reject' );
				}
				echo '<button type="button" class="mtal-account-booking-row__details" onclick="document.getElementById(\'' . esc_attr( $template_id ) . '\').showModal();">جزئیات</button></div></article>';

				ob_start();
				echo '<dialog id="' . esc_attr( $template_id ) . '" class="mtal-account-booking-dialog">';
				echo '<div class="mtal-account-booking-modal__head"><h3>جزئیات رزرو #' . esc_html( $order->get_order_number() ) . '</h3><button type="button" class="mtal-account-booking-modal__close" onclick="this.closest(\'dialog\').close();" aria-label="بستن">بستن ✕</button></div>';
				echo '<div class="mtal-account-booking__table-wrap"><table class="mtal-account-booking__table"><tbody>';
				self::render_table_row( 'نام مشتری', esc_html( $name ) );
				self::render_table_row( 'شماره تماس', $phone ? esc_html( $phone ) : '—' );
				self::render_table_row( 'ایمیل', $email ? esc_html( $email ) : '—' );
				self::render_table_row( 'سوئیت', esc_html( $suite_name ) );
				self::render_table_row( 'شناسه سفارش', esc_html( $order_id ) );
				self::render_table_row( 'تاریخ ورود', esc_html( $check_in ? $check_in : '—' ) );
				self::render_table_row( 'تاریخ خروج', esc_html( $check_out ? $check_out : '—' ) );
				self::render_table_row( 'تعداد مسافران', $guests ? esc_html( $guests . ' نفر' ) : '—' );
				self::render_table_row( 'مبلغ سفارش', wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) );
				self::render_table_row( 'وضعیت سفارش', self::render_status_badge( $order_status, $status_label ) );
				self::render_table_row( 'تاریخ ثبت', $order->get_date_created() ? esc_html( class_exists( 'MTAL_Persian_Calendar' ) ? MTAL_Persian_Calendar::format_jalali_datetime( ( new DateTimeImmutable( '@' . $order->get_date_created()->getTimestamp() ) )->setTimezone( wp_timezone() ) ) : wc_format_datetime( $order->get_date_created() ) ) : '—' );
				self::render_table_row( 'مهلت درخواست', $expires ? esc_html( class_exists( 'MTAL_Persian_Calendar' ) ? MTAL_Persian_Calendar::format_jalali_datetime( ( new DateTimeImmutable( '@' . $expires ) )->setTimezone( wp_timezone() ) ) : wp_date( 'Y-m-d H:i:s', $expires, wp_timezone() ) ) : '—' );
				if ( $order->get_customer_note() ) {
					self::render_table_row( 'یادداشت مشتری', esc_html( $order->get_customer_note() ) );
				}
				echo '</tbody></table></div>';
				if ( $is_actionable ) {
					echo '<div class="mtal-account-booking-modal__actions">';
					self::render_decision_form( $order_id, 'approve', 'تأیید و ارسال لینک پرداخت', 'mtal-account-booking-modal__approve' );
					self::render_decision_form( $order_id, 'reject', 'رد درخواست و آزادسازی تاریخ‌ها', 'mtal-account-booking-modal__reject' );
					echo '</div>';
				} elseif ( 'mtal-pending' === $order_status ) {
					echo '<p class="mtal-account-booking__notice">مهلت این درخواست به پایان رسیده است و امکان تصمیم‌گیری ندارد.</p>';
				}
				echo '</dialog>';
				$booking_dialogs .= ob_get_clean();
			}
			echo '</div>';
			echo $booking_dialogs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Dialog markup is generated with escaped order data.
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
