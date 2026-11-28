<?php
/**
 * Customer-facing, read-only booking history in WooCommerce My Account.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MTAL_Customer_Account_Bookings {

	const ENDPOINT       = 'mtal-customer-bookings';
	const REWRITE_VERSION = '1';
	const PER_PAGE       = 10;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_endpoint' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite_rules' ), 99 );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_account_menu_item' ), 40 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_endpoint' ) );
	}

	public static function register_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Flush once after this endpoint is first introduced or its rewrite version changes.
	 */
	public static function maybe_flush_rewrite_rules() {
		if ( self::REWRITE_VERSION === get_option( 'mtal_customer_bookings_rewrite_version' ) ) {
			return;
		}

		self::register_endpoint();
		flush_rewrite_rules( false );
		update_option( 'mtal_customer_bookings_rewrite_version', self::REWRITE_VERSION, false );
	}

	public static function activate() {
		self::register_endpoint();
		flush_rewrite_rules();
		update_option( 'mtal_customer_bookings_rewrite_version', self::REWRITE_VERSION, false );
	}

	public static function add_account_menu_item( $items ) {
		if ( ! is_user_logged_in() ) {
			return $items;
		}

		$logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : null;
		unset( $items['customer-logout'] );

		$items[ self::ENDPOINT ] = 'رزروها';

		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}

		return $items;
	}

	/**
	 * Fetch only this customer's MTAL booking orders.
	 *
	 * @param int $page Current page.
	 * @return object|array
	 */
	private static function get_customer_orders( $page ) {
		return wc_get_orders(
			array(
				'type'       => 'shop_order',
				'customer_id' => get_current_user_id(),
				'status'     => 'any',
				'limit'      => self::PER_PAGE,
				'page'       => max( 1, absint( $page ) ),
				'paginate'   => true,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'meta_query' => array(
					array(
						'key'     => '_mtal_booking_suite_id',
						'compare' => 'EXISTS',
					),
				),
			)
		);
	}

	private static function is_customer_booking( $order ) {
		return $order instanceof WC_Order
			&& get_current_user_id() === absint( $order->get_customer_id() )
			&& absint( $order->get_meta( '_mtal_booking_suite_id' ) ) > 0;
	}

	private static function get_suite_display( $suite_id ) {
		$display = class_exists( 'MTAL_Suite_Registry' )
			? MTAL_Suite_Registry::get_display_data( $suite_id )
			: false;

		if ( ! is_array( $display ) ) {
			$title = get_the_title( $suite_id );
			$display = array(
				'title'    => $title ? $title : 'سوئیت #' . absint( $suite_id ),
				'imageUrl' => '',
				'sourcePostId' => 0,
			);
		}

		$source_post = ! empty( $display['sourcePostId'] )
			? get_post( absint( $display['sourcePostId'] ) )
			: null;

		$display['description'] = '';
		if ( $source_post && ! in_array( $source_post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			$description = trim( (string) $source_post->post_excerpt );
			if ( '' === $description ) {
				$description = (string) $source_post->post_content;
			}
			$display['description'] = wpautop( wp_kses_post( $description ) );
		}

		$display['regularPrice'] = absint( get_post_meta( $suite_id, '_mtal_regular_price', true ) );
		$display['weekendPrice'] = absint( get_post_meta( $suite_id, '_mtal_weekend_price', true ) );
		$display['baseCapacity'] = absint( get_post_meta( $suite_id, '_mtal_base_capacity', true ) );
		$display['maxCapacity'] = absint( get_post_meta( $suite_id, '_mtal_suite_max_capacity', true ) );
		$display['extraGuestPrice'] = absint( get_post_meta( $suite_id, '_mtal_extra_guest_price', true ) );

		return $display;
	}

	private static function render_row( $label, $value ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . wp_kses_post( $value ) . '</td></tr>';
	}

	private static function format_booking_date_with_weekday( $date ) {
		$date = (string) $date;
		if ( '' === $date || ! class_exists( 'MTAL_Persian_Calendar' ) ) {
			return $date;
		}

		$normalized = MTAL_Persian_Calendar::normalize_booking_date( $date );
		if ( ! is_array( $normalized ) || ! isset( $normalized['jdn'] ) ) {
			return $date;
		}

		$weekdays = array( 'شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه' );
		$weekday_index = ( (int) $normalized['jdn'] + 2 ) % 7;
		if ( $weekday_index < 0 ) {
			$weekday_index += 7;
		}

		return $date . ' (' . $weekdays[ $weekday_index ] . ')';
	}

	private static function render_status( $order ) {
		return esc_html( wc_get_order_status_name( $order->get_status() ) );
	}

	private static function render_booking_details( $order ) {
		if ( ! self::is_customer_booking( $order ) ) {
			echo '<div class="woocommerce-error" role="alert">رزرو موردنظر پیدا نشد یا اجازه مشاهده آن را ندارید.</div>';
			return;
		}

		$suite_id = absint( $order->get_meta( '_mtal_booking_suite_id' ) );
		$suite = self::get_suite_display( $suite_id );
		$check_in = (string) $order->get_meta( '_mtal_booking_check_in' );
		$check_out = (string) $order->get_meta( '_mtal_booking_check_out' );
		$guests = absint( $order->get_meta( '_mtal_booking_guests' ) );
		$created = $order->get_date_created();

		echo '<div class="mtal-customer-booking-details">';
		echo '<p><a href="' . esc_url( wc_get_endpoint_url( self::ENDPOINT, '', wc_get_page_permalink( 'myaccount' ) ) ) . '">بازگشت به فهرست رزروها</a></p>';
		echo '<h3>جزئیات رزرو شماره ' . esc_html( $order->get_order_number() ) . '</h3>';

		if ( ! empty( $suite['imageUrl'] ) ) {
			echo '<p><img src="' . esc_url( $suite['imageUrl'] ) . '" alt="' . esc_attr( $suite['title'] ) . '" style="max-width:320px;height:auto;" /></p>';
		}
		echo '<h4>' . esc_html( $suite['title'] ) . '</h4>';
		if ( ! empty( $suite['description'] ) ) {
			echo '<div class="mtal-customer-booking-details__description">' . wp_kses_post( $suite['description'] ) . '</div>';
		}

		echo '<table class="woocommerce-table shop_table"><tbody>';
		self::render_row( 'شماره رزرو', esc_html( $order->get_order_number() ) );
		self::render_row( 'وضعیت', self::render_status( $order ) );
		self::render_row( 'تاریخ ورود', $check_in ? esc_html( $check_in ) : '—' );
		self::render_row( 'تاریخ خروج', $check_out ? esc_html( $check_out ) : '—' );
		self::render_row( 'تعداد مسافران', $guests ? esc_html( number_format_i18n( $guests ) . ' نفر' ) : '—' );
		self::render_row( 'مبلغ کل', wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) );
		self::render_row( 'تاریخ ثبت درخواست', $created ? esc_html( class_exists( 'MTAL_Persian_Calendar' ) ? MTAL_Persian_Calendar::format_jalali_datetime( ( new DateTimeImmutable( '@' . $created->getTimestamp() ) )->setTimezone( wp_timezone() ) ) : wc_format_datetime( $created ) ) : '—' );
		if ( $order->get_customer_note() ) {
			self::render_row( 'یادداشت ثبت‌شده', esc_html( $order->get_customer_note() ) );
		}
		echo '</tbody></table>';

		echo '<h4>اطلاعات سوئیت</h4>';
		echo '<table class="woocommerce-table shop_table"><tbody>';
		if ( $suite['baseCapacity'] ) {
			self::render_row( 'ظرفیت پایه', esc_html( number_format_i18n( $suite['baseCapacity'] ) . ' نفر' ) );
		}
		if ( $suite['maxCapacity'] ) {
			self::render_row( 'حداکثر ظرفیت', esc_html( number_format_i18n( $suite['maxCapacity'] ) . ' نفر' ) );
		}
		if ( $suite['regularPrice'] ) {
			self::render_row( 'قیمت عادی هر شب', wc_price( $suite['regularPrice'] ) );
		}
		if ( $suite['weekendPrice'] ) {
			self::render_row( 'قیمت آخر هفته هر شب', wc_price( $suite['weekendPrice'] ) );
		}
		if ( $suite['extraGuestPrice'] ) {
			self::render_row( 'هزینه هر نفر اضافه در هر شب', wc_price( $suite['extraGuestPrice'] ) );
		}
		echo '</tbody></table></div>';
	}

	private static function render_booking_list() {
		$page = isset( $_GET['booking_page'] ) ? max( 1, absint( wp_unslash( $_GET['booking_page'] ) ) ) : 1;
		$results = self::get_customer_orders( $page );
		$orders = is_object( $results ) && isset( $results->orders ) ? $results->orders : array();
		$total_pages = is_object( $results ) && isset( $results->max_num_pages ) ? max( 1, absint( $results->max_num_pages ) ) : 1;

		echo '<div class="mtal-customer-bookings">';
		echo '<h3>رزروهای من</h3>';
		if ( empty( $orders ) ) {
			echo '<p>هنوز رزروی برای حساب کاربری شما ثبت نشده است.</p></div>';
			return;
		}

		echo '<style>
			.mtal-customer-bookings__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:18px;margin:18px 0}
			.mtal-customer-booking-card{display:flex;flex-direction:column;min-width:0;overflow:hidden;border:1px solid #e5e7eb;border-radius:12px;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.045)}
			.mtal-customer-booking-card__top{display:flex;align-items:center;gap:14px;padding:16px;border-bottom:1px solid #f0f0f0}
			.mtal-customer-booking-card__image{width:104px;height:88px;flex:0 0 104px;object-fit:cover;border-radius:8px;background:#f4f4f4}
			.mtal-customer-booking-card__title{margin:0 0 7px;font-size:16px;font-weight:700}
			.mtal-customer-booking-card__number{font-size:13px;color:#777}
			.mtal-customer-booking-card__status{display:inline-block;margin-top:8px;padding:4px 9px;border-radius:20px;background:#f1f5f9;color:#334155;font-size:12px}
			.mtal-customer-booking-card__body{display:grid;grid-template-columns:1fr 1fr;gap:13px;padding:16px}
			.mtal-customer-booking-card__item{min-width:0}
			.mtal-customer-booking-card__label{display:block;margin-bottom:4px;color:#777;font-size:12px}
			.mtal-customer-booking-card__value{display:block;font-size:14px;font-weight:600;overflow-wrap:anywhere}
			.mtal-customer-booking-card__footer{padding:0 16px 16px}
			.mtal-customer-booking-card__footer .button{display:block;text-align:center;width:100%;box-sizing:border-box}
			@media(max-width:480px){.mtal-customer-booking-card__top{gap:10px;padding:12px}.mtal-customer-booking-card__image{width:82px;height:76px;flex-basis:82px}.mtal-customer-booking-card__body{padding:12px;gap:11px}.mtal-customer-booking-card__footer{padding:0 12px 12px}}
		</style>';
		echo '<div class="mtal-customer-bookings__grid">';

		foreach ( $orders as $order ) {
			if ( ! self::is_customer_booking( $order ) ) {
				continue;
			}

			$suite_id = absint( $order->get_meta( '_mtal_booking_suite_id' ) );
			$suite = self::get_suite_display( $suite_id );
			$detail_url = wc_get_endpoint_url( self::ENDPOINT, $order->get_id(), wc_get_page_permalink( 'myaccount' ) );
			$image_url = ! empty( $suite['imageUrl'] ) ? $suite['imageUrl'] : '';
			$check_in = (string) $order->get_meta( '_mtal_booking_check_in' );
			$check_out = (string) $order->get_meta( '_mtal_booking_check_out' );
			$guests = absint( $order->get_meta( '_mtal_booking_guests' ) );

			echo '<article class="mtal-customer-booking-card">';
			echo '<div class="mtal-customer-booking-card__top">';
			if ( $image_url ) {
				echo '<img class="mtal-customer-booking-card__image" src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $suite['title'] ) . '" loading="lazy" />';
			}
			echo '<div><h4 class="mtal-customer-booking-card__title">' . esc_html( $suite['title'] ) . '</h4>';
			echo '<span class="mtal-customer-booking-card__number">شماره رزرو: #' . esc_html( $order->get_order_number() ) . '</span>';
			echo '<span class="mtal-customer-booking-card__status">' . self::render_status( $order ) . '</span></div>';
			echo '</div>';

			echo '<div class="mtal-customer-booking-card__body">';
			echo '<div class="mtal-customer-booking-card__item"><span class="mtal-customer-booking-card__label">تاریخ ورود</span><span class="mtal-customer-booking-card__value">' . ( $check_in ? esc_html( self::format_booking_date_with_weekday( $check_in ) ) : '—' ) . '</span></div>';
			echo '<div class="mtal-customer-booking-card__item"><span class="mtal-customer-booking-card__label">تاریخ خروج</span><span class="mtal-customer-booking-card__value">' . ( $check_out ? esc_html( self::format_booking_date_with_weekday( $check_out ) ) : '—' ) . '</span></div>';
			echo '<div class="mtal-customer-booking-card__item"><span class="mtal-customer-booking-card__label">تعداد مسافران</span><span class="mtal-customer-booking-card__value">' . esc_html( number_format_i18n( $guests ) ) . ' نفر</span></div>';
			echo '<div class="mtal-customer-booking-card__item"><span class="mtal-customer-booking-card__label">مبلغ کل</span><span class="mtal-customer-booking-card__value">' . wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) . '</span></div>';
			echo '</div>';

			echo '<div class="mtal-customer-booking-card__footer"><a class="woocommerce-button button" href="' . esc_url( $detail_url ) . '">مشاهده جزئیات رزرو</a></div>';
			echo '</article>';
		}

		echo '</div>';

		if ( $total_pages > 1 ) {
			echo '<nav class="woocommerce-pagination" aria-label="صفحه‌بندی رزروها">';
			for ( $i = 1; $i <= $total_pages; $i++ ) {
				$url = add_query_arg( 'booking_page', $i, wc_get_endpoint_url( self::ENDPOINT, '', wc_get_page_permalink( 'myaccount' ) ) );
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
	public static function render_endpoint() {
		if ( ! is_user_logged_in() ) {
			echo '<div class="woocommerce-info">برای مشاهده رزروها ابتدا وارد حساب کاربری شوید.</div>';
			return;
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			echo '<div class="woocommerce-info">برای مشاهده رزروها، ووکامرس باید فعال باشد.</div>';
			return;
		}

		$requested_order_id = absint( get_query_var( self::ENDPOINT, 0 ) );
		if ( $requested_order_id ) {
			$order = wc_get_order( $requested_order_id );
			self::render_booking_details( $order );
			return;
		}

		self::render_booking_list();
	}
}

MTAL_Customer_Account_Bookings::init();
