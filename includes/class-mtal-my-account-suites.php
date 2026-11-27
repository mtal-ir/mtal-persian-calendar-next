<?php
/**
 * Responsive suite list inside the WooCommerce My Account area.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MTAL_My_Account_Suites {

	const ENDPOINT = 'mtal-suites';
	const REWRITE_VERSION = '1';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_endpoint' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite_rules' ), 99 );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_account_menu_item' ), 20 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_endpoint' ) );
		add_action( 'template_redirect', array( __CLASS__, 'protect_endpoint' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	private static function can_manage() {
		return class_exists( 'MTAL_My_Account_Bookings' ) && MTAL_My_Account_Bookings::can_manage_bookings();
	}

	public static function register_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public static function maybe_flush_rewrite_rules() {
		if ( self::REWRITE_VERSION === get_option( 'mtal_my_account_suites_rewrite_version' ) ) {
			return;
		}
		self::register_endpoint();
		flush_rewrite_rules( false );
		update_option( 'mtal_my_account_suites_rewrite_version', self::REWRITE_VERSION, false );
	}

	public static function add_account_menu_item( $items ) {
		if ( ! self::can_manage() ) {
			return $items;
		}

		$logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : null;
		unset( $items['customer-logout'] );
		$items[ self::ENDPOINT ] = 'مدیریت سوئیت‌ها';
		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}

	public static function protect_endpoint() {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::ENDPOINT ) && ! self::can_manage() ) {
			wp_die(
				esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', 'mtal-persian-calendar' ),
				esc_html__( 'دسترسی غیرمجاز', 'mtal-persian-calendar' ),
				array( 'response' => 403 )
			);
		}
	}

	public static function enqueue_assets() {
		global $wp;
		$is_endpoint = function_exists( 'is_account_page' )
			&& is_account_page()
			&& isset( $wp->query_vars )
			&& is_array( $wp->query_vars )
			&& array_key_exists( self::ENDPOINT, $wp->query_vars );

		if ( ! $is_endpoint || ! self::can_manage() ) {
			return;
		}

		$path = MTAL_PC_PATH . 'assets/css/mtal-my-account-suites.css';
		$version = file_exists( $path ) ? (string) filemtime( $path ) : MTAL_PC_VERSION;
		wp_enqueue_style( 'mtal-my-account-suites', MTAL_PC_URL . 'assets/css/mtal-my-account-suites.css', array(), $version );
	}

	private static function get_suites( $page, $limit = 12, $hidden = false ) {
		$args = array(
			'post_type' => MTAL_Suite_Registry::POST_TYPE,
			'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future' ),
			'posts_per_page' => $limit, 'paged' => max( 1, absint( $page ) ),
			'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => false,
		);
		$args['meta_query'] = $hidden
			? array( array( 'key' => MTAL_Suite_Registry::META_HIDDEN, 'value' => '1' ) )
			: array( 'relation' => 'OR', array( 'key' => MTAL_Suite_Registry::META_HIDDEN, 'compare' => 'NOT EXISTS' ), array( 'key' => MTAL_Suite_Registry::META_HIDDEN, 'value' => '1', 'compare' => '!=' ) );
		return new WP_Query( $args );
	}

	private static function get_suite_calendar_url( $suite_id ) {
		return add_query_arg(
			array(
				'page'     => MTAL_Suite_Admin::CALENDAR_PAGE_SLUG,
				'suite_id' => absint( $suite_id ),
			),
			admin_url( 'admin.php' )
		);
	}

	private static function get_suite_edit_url( $suite_id ) {
		return add_query_arg(
			array(
				'page'     => MTAL_Suite_Admin::PAGE_SLUG,
				'action'   => $suite_id ? 'edit' : 'add',
				'suite_id' => absint( $suite_id ),
			),
			admin_url( 'admin.php' )
		);
	}

	public static function render_endpoint() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', 'mtal-persian-calendar' ), '', array( 'response' => 403 ) );
		}
		if ( ! class_exists( 'MTAL_Suite_Registry' ) || ! class_exists( 'MTAL_Suite_Admin' ) ) {
			echo '<p>مدیریت سوئیت‌های MTAL در دسترس نیست.</p>';
			return;
		}

		$page = isset( $_GET['mtal_suites_page'] ) ? max( 1, absint( $_GET['mtal_suites_page'] ) ) : 1;
		$query = self::get_suites( $page );
		$hidden_query = self::get_suites( 1, 50, true );
		$account_url = wc_get_account_endpoint_url( self::ENDPOINT );

		echo '<section class="mtal-account-suites" dir="rtl">';
		echo '<header class="mtal-account-suites__header"><div><h2>مدیریت سوئیت‌ها</h2><p>مشاهده سوئیت‌های مستقل و دسترسی به تنظیمات آن‌ها</p></div>';
		echo '<a class="mtal-account-suites__add" href="' . esc_url( self::get_suite_edit_url( 0 ) ) . '">افزودن سوئیت جدید</a></header>';

		if ( ! $query->have_posts() ) {
			echo '<div class="mtal-account-suites__empty"><strong>هنوز سوئیتی ثبت نشده است.</strong><p>برای شروع، یک سوئیت مستقل ایجاد کنید.</p><a class="mtal-account-suites__add" href="' . esc_url( self::get_suite_edit_url( 0 ) ) . '">افزودن سوئیت</a></div>';
		} else {
			echo '<div class="mtal-account-suites__grid">';
			foreach ( $query->posts as $suite ) {
				$display = MTAL_Suite_Registry::get_display_data( $suite->ID );
				if ( ! is_array( $display ) ) {
					continue;
				}

				$image = ! empty( $display['imageId'] ) ? wp_get_attachment_image( $display['imageId'], 'medium', false, array( 'class' => 'mtal-account-suites__image', 'alt' => $display['title'] ) ) : '<span class="mtal-account-suites__image-placeholder" aria-hidden="true">▧</span>';
				$base = absint( get_post_meta( $suite->ID, '_mtal_base_capacity', true ) );
				$base = $base > 0 ? $base : 2;
				$max = absint( get_post_meta( $suite->ID, '_mtal_suite_max_capacity', true ) );
				$max = $max > 0 ? $max : 5;
				$regular = absint( get_post_meta( $suite->ID, '_mtal_regular_price', true ) );
				$weekend = absint( get_post_meta( $suite->ID, '_mtal_weekend_price', true ) );
				$extra = absint( get_post_meta( $suite->ID, '_mtal_extra_guest_price', true ) );
				$status = get_post_status_object( $suite->post_status );
				$status_label = $status && ! empty( $status->label ) ? $status->label : $suite->post_status;
				$source = MTAL_Suite_Registry::get_source_post( $suite->ID );

				echo '<article class="mtal-account-suites__card">';
				echo '<div class="mtal-account-suites__identity">' . $image . '<div class="mtal-account-suites__identity-text"><h3>' . esc_html( $display['title'] ) . '</h3><span>شناسه مستقل MTAL: #' . absint( $suite->ID ) . '</span><span class="mtal-account-suites__status">' . esc_html( $status_label ) . '</span></div></div>';
				echo '<div class="mtal-account-suites__details">';
				echo '<div><span>ظرفیت پایه</span><strong>' . esc_html( number_format_i18n( $base ) ) . ' نفر</strong></div>';
				echo '<div><span>حداکثر گنجایش</span><strong>' . esc_html( number_format_i18n( $max ) ) . ' نفر</strong></div>';
				echo '<div><span>قیمت عادی هر شب</span><strong>' . esc_html( number_format_i18n( $regular ) ) . '</strong></div>';
				echo '<div><span>قیمت آخر هفته</span><strong>' . esc_html( number_format_i18n( $weekend ) ) . '</strong></div>';
				echo '<div><span>هزینه نفر اضافه</span><strong>' . esc_html( number_format_i18n( $extra ) ) . '</strong></div>';
				echo '</div>';
				echo '<div class="mtal-account-suites__source"><span>نوشته منبع</span><strong>' . ( $source ? esc_html( get_the_title( $source ) ) . ' (#' . absint( $source->ID ) . ')' : 'بدون اتصال' ) . '</strong></div>';
				echo '<div class="mtal-account-suites__actions"><a href="' . esc_url( self::get_suite_edit_url( $suite->ID ) ) . '">ویرایش سوئیت</a><a href="' . esc_url( self::get_suite_calendar_url( $suite->ID ) ) . '">مدیریت تقویم</a></div>';
				if ( ! $source ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mtal-account-suites__hide-form">';
					wp_nonce_field( 'mtal_suite_hidden_' . $suite->ID . '_hide' );
					echo '<input type="hidden" name="action" value="mtal_toggle_suite_hidden"><input type="hidden" name="suite_id" value="' . absint( $suite->ID ) . '"><input type="hidden" name="hidden_mode" value="hide"><input type="hidden" name="redirect_to" value="' . esc_attr( $account_url ) . '"><button type="submit">پنهان‌کردن سوئیت</button></form>';
				}
				echo '</article>';
			}
			echo '</div>';
		}

		if ( $query->max_num_pages > 1 ) {
			echo '<nav class="mtal-account-suites__pagination" aria-label="صفحه‌بندی سوئیت‌ها">';
			for ( $i = 1; $i <= (int) $query->max_num_pages; $i++ ) {
				$url = add_query_arg( 'mtal_suites_page', $i );
				if ( $i === $page ) {
					echo '<span aria-current="page">' . esc_html( number_format_i18n( $i ) ) . '</span>';
				} else {
					echo '<a href="' . esc_url( $url ) . '">' . esc_html( number_format_i18n( $i ) ) . '</a>';
				}
			}
			echo '</nav>';
		}

		if ( $hidden_query->have_posts() ) {
			echo '<section class="mtal-account-suites__hidden"><h3>سوئیت‌های پنهان</h3><p>این سوئیت‌ها در مسیر رزرو جدید نمایش داده نمی‌شوند. اطلاعات و سوابق آن‌ها محفوظ است.</p><div class="mtal-account-suites__grid">';
			foreach ( $hidden_query->posts as $hidden_suite ) {
				$hidden_display = MTAL_Suite_Registry::get_display_data( $hidden_suite->ID );
				echo '<article class="mtal-account-suites__card"><h4>' . esc_html( $hidden_display ? $hidden_display['title'] : get_the_title( $hidden_suite ) ) . '</h4><span>شناسه MTAL: #' . absint( $hidden_suite->ID ) . '</span><div class="mtal-account-suites__actions"><a href="' . esc_url( self::get_suite_edit_url( $hidden_suite->ID ) ) . '">ویرایش</a><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'mtal_suite_hidden_' . $hidden_suite->ID . '_restore' );
				echo '<input type="hidden" name="action" value="mtal_toggle_suite_hidden"><input type="hidden" name="suite_id" value="' . absint( $hidden_suite->ID ) . '"><input type="hidden" name="hidden_mode" value="restore"><input type="hidden" name="redirect_to" value="' . esc_attr( $account_url ) . '"><button type="submit">بازگردانی به پنل‌ها</button></form></div></article>';
			}
			echo '</div></section>';
		}
		echo '</section>';
		wp_reset_postdata();
	}
}

MTAL_My_Account_Suites::init();
