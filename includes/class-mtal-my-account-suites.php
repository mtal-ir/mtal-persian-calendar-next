<?php
/**
 * Responsive suite list inside the WooCommerce My Account area.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MTAL_My_Account_Suites {

	const ENDPOINT = 'mtal-suites';
	const REWRITE_VERSION = '2';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_endpoint' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite_rules' ), 99 );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_account_menu_item' ), 20 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_endpoint' ) );
		add_action( 'template_redirect', array( __CLASS__, 'protect_endpoint' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_scripts', array( 'MTAL_Suite_Admin', 'enqueue_account_calendar_assets' ), 20 );
	}

	private static function can_manage() {
		return class_exists( 'MTAL_My_Account_Bookings' ) && MTAL_My_Account_Bookings::can_manage_bookings();
	}

	public static function activate() {
		self::register_endpoint();
		if ( class_exists( 'MTAL_My_Account_Bookings' ) ) {
			MTAL_My_Account_Bookings::register_endpoint();
		}
		flush_rewrite_rules();
		update_option( 'mtal_my_account_suites_rewrite_version', self::REWRITE_VERSION, false );
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
			'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => false,
		);
		$args['meta_query'] = $hidden
			? array( array( 'key' => MTAL_Suite_Registry::META_HIDDEN, 'value' => '1' ) )
			: array( 'relation' => 'OR', array( 'key' => MTAL_Suite_Registry::META_HIDDEN, 'compare' => 'NOT EXISTS' ), array( 'key' => MTAL_Suite_Registry::META_HIDDEN, 'value' => '1', 'compare' => '!=' ) );
		return new WP_Query( $args );
	}

	private static function get_suite_calendar_url( $suite_id ) {
		return add_query_arg(
			array(
				'mtal_suite_action' => 'calendar',
				'suite_id'          => absint( $suite_id ),
			),
			wc_get_account_endpoint_url( self::ENDPOINT )
		);
	}

	private static function get_suite_editor_url( $suite_id ) {
		return add_query_arg(
			array( 'mtal_suite_action' => 'edit', 'suite_id' => absint( $suite_id ) ),
			wc_get_account_endpoint_url( self::ENDPOINT )
		);
	}

	private static function get_suite_add_url() {
		return add_query_arg(
			'mtal_suite_action',
			'add',
			wc_get_account_endpoint_url( self::ENDPOINT )
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

	/** Keep the one-unlinked-suite explanation inside the WooCommerce account screen. */
	private static function render_unlinked_suite_notice( $suite_id ) {
		$display = MTAL_Suite_Registry::get_display_data( $suite_id );
		$title = $display ? $display['title'] : get_the_title( $suite_id );
		$edit_url = self::get_suite_editor_url( $suite_id );
		echo '<div class="mtal-account-suites__empty mtal-account-suites__notice" role="status"><button type="button" class="mtal-account-suites__notice-close" aria-label="بستن پیام" onclick="this.parentNode.hidden=true">× <span>بستن</span></button><strong>سوئیت مستقل آماده ویرایش است</strong>';
		echo '<p>در حال حاضر یک سوئیت مستقلِ بدون اتصال وجود دارد. برای جلوگیری از ایجاد سوئیت‌های بلااستفاده، امکان ساخت سوئیت مستقل دوم تا زمان اتصال سوئیت فعلی به نوشته منبع وجود ندارد.</p>';
		echo '<p><strong>' . esc_html( $title ) . '</strong> — شناسه MTAL: #' . absint( $suite_id ) . '</p>';
		if ( MTAL_Suite_Registry::is_hidden( $suite_id ) ) {
			echo '<p>این سوئیت در حال حاضر پنهان است؛ برای ویرایش نیازی به بازگردانی آن نیست.</p>';
		}
		echo '<a class="mtal-account-suites__add" href="' . esc_url( $edit_url ) . '">ویرایش سوئیت آماده</a></div>';
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
		$calendar_requested = isset( $_GET['mtal_suite_action'] ) && 'calendar' === sanitize_key( wp_unslash( $_GET['mtal_suite_action'] ) );
		if ( $calendar_requested ) {
			$suite_id = isset( $_GET['suite_id'] ) ? absint( $_GET['suite_id'] ) : 0;
			if ( ! $suite_id || ! MTAL_Suite_Registry::is_suite( $suite_id ) || ! current_user_can( 'edit_post', $suite_id ) ) {
				wp_die( 'شناسه سوئیت معتبر نیست یا اجازه مدیریت تقویم آن را ندارید.', '', array( 'response' => 403 ) );
			}
			echo '<section class="mtal-account-suites" dir="rtl"><header class="mtal-account-suites__header"><div><h2>مدیریت تقویم</h2><p>' . esc_html( get_the_title( $suite_id ) ) . ' — شناسه MTAL: #' . absint( $suite_id ) . '</p></div><a class="mtal-account-suites__add" href="' . esc_url( $account_url ) . '">بازگشت به مدیریت سوئیت‌ها</a></header>';
			MTAL_Suite_Admin::render_account_calendar( $suite_id, $account_url );
			echo '</section>';
			return;
		}

		echo '<section class="mtal-account-suites" dir="rtl">';
		echo '<header class="mtal-account-suites__header"><div><h2>مدیریت سوئیت‌ها</h2><p>مشاهده سوئیت‌های مستقل و دسترسی به تنظیمات آن‌ها</p></div>';
		echo '<a class="mtal-account-suites__add" href="' . esc_url( self::get_suite_add_url() ) . '">افزودن سوئیت جدید</a></header>';

		$add_requested = isset( $_GET['mtal_suite_action'] ) && 'add' === sanitize_key( wp_unslash( $_GET['mtal_suite_action'] ) );
		$edit_requested = isset( $_GET['mtal_suite_action'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['mtal_suite_action'] ) );
		if ( $edit_requested ) {
			$suite_id = isset( $_GET['suite_id'] ) ? absint( $_GET['suite_id'] ) : 0;
			if ( ! $suite_id || ! MTAL_Suite_Registry::is_suite( $suite_id ) || ! current_user_can( 'edit_post', $suite_id ) ) {
				wp_die( 'شناسه سوئیت معتبر نیست یا اجازه ویرایش آن را ندارید.', '', array( 'response' => 403 ) );
			}
			MTAL_Suite_Admin::render_account_form( $suite_id, $account_url );
			echo '</section>';
			return;
		}

		if ( $add_requested ) {
			$unlinked_suite_id = MTAL_Suite_Registry::get_unlinked_suite_id();
			if ( $unlinked_suite_id ) {
				self::render_unlinked_suite_notice( $unlinked_suite_id );
			} else {
				$add_admin_url = admin_url( 'admin.php?page=' . MTAL_Suite_Admin::PAGE_SLUG . '&action=add' );
				echo '<div class="mtal-account-suites__empty"><p>سوئیت مستقلِ بدون اتصال فعالی وجود ندارد؛ می‌توانید سوئیت جدید ایجاد کنید.</p><a class="mtal-account-suites__add" href="' . esc_url( $add_admin_url ) . '">ایجاد سوئیت جدید</a></div>';
			}
		}

		if ( ! $query->have_posts() && ! $add_requested ) {
			echo '<div class="mtal-account-suites__empty"><strong>هنوز سوئیتی ثبت نشده است.</strong><p>برای شروع، یک سوئیت مستقل ایجاد کنید.</p><a class="mtal-account-suites__add" href="' . esc_url( self::get_suite_add_url() ) . '">افزودن سوئیت</a></div>';
		} elseif ( $query->have_posts() ) {
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
				echo '<div class="mtal-account-suites__source"><span>نوشته منبع</span><strong>' . ( $source ? esc_html( get_the_title( $source ) ) . ' (#' . absint( $source->ID ) . ')' : 'بدون اتصال' ) . '</strong></div>';
				echo '</div>';
				echo '<div class="mtal-account-suites__actions"><a href="' . esc_url( self::get_suite_editor_url( $suite->ID ) ) . '">ویرایش</a><a href="' . esc_url( self::get_suite_calendar_url( $suite->ID ) ) . '">تقویم</a></div>';
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
				echo '<article class="mtal-account-suites__card"><h4>' . esc_html( $hidden_display ? $hidden_display['title'] : get_the_title( $hidden_suite ) ) . '</h4><span>شناسه MTAL: #' . absint( $hidden_suite->ID ) . '</span><div class="mtal-account-suites__actions"><a href="' . esc_url( self::get_suite_editor_url( $hidden_suite->ID ) ) . '">ویرایش</a><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
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
