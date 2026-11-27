<?php
/**
 * Admin list screen for MTAL suite entities.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MTAL_Suite_Admin {

	const PAGE_SLUG = 'mtal-suite-management';
	const CALENDAR_PAGE_SLUG = 'mtal-suite-calendar';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_calendar_assets' ) );
	}

	public static function register_menu() {
		add_management_page(
			'عیب‌یابی MTAL',
			'عیب‌یابی MTAL',
			'manage_options',
			'mtal-diagnostics',
			array( __CLASS__, 'render_diagnostics_page' )
		);

		if (
			! class_exists( 'MTAL_Booking_Admin' ) ||
			! MTAL_Booking_Admin::current_user_can_manage_bookings()
		) {
			return;
		}

		add_submenu_page(
			'mtal-booking-management',
			'مدیریت سوئیت‌ها',
			'مدیریت سوئیت‌ها',
			'read',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);

		// Hidden screen: each suite links directly to its own calendar manager.
		add_submenu_page(
			'',
			'مدیریت تقویم سوئیت',
			'',
			'read',
			self::CALENDAR_PAGE_SLUG,
			array( __CLASS__, 'render_calendar_page' )
		);
	}

	public static function enqueue_calendar_assets( $hook_suffix ) {
		if ( ! isset( $_GET['page'] ) || self::CALENDAR_PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		$suite_id = isset( $_GET['suite_id'] ) ? absint( $_GET['suite_id'] ) : 0;
		if ( ! $suite_id || ! class_exists( 'MTAL_Suite_Registry' ) || ! MTAL_Suite_Registry::is_suite( $suite_id ) ) {
			return;
		}
		$data = MTAL_Persian_Calendar::get_suite_calendar_data( $suite_id );
		wp_enqueue_style( 'mtal-persian-calendar-css', MTAL_PC_URL . 'assets/css/mtal-calendar.css', array(), MTAL_PC_VERSION );
		wp_enqueue_script( 'mtal-persian-calendar-engine', MTAL_PC_URL . 'assets/js/persian-calendar-standalone.js', array(), MTAL_PC_VERSION, true );
		wp_enqueue_script( 'mtal-persian-calendar-init', MTAL_PC_URL . 'assets/js/datepicker-init.js', array( 'mtal-persian-calendar-engine' ), MTAL_PC_VERSION, true );
		wp_localize_script(
			'mtal-persian-calendar-init',
			'mtalCalendarConfig',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'isUserLoggedIn'  => true,
				'nonce'           => wp_create_nonce( 'mtal_calendar_rules_nonce' ),
				'maxMonths'       => 3,
				'postId'          => $suite_id,
				'suiteId'         => $suite_id,
				'blockedDates'    => $data['blockedDates'],
				'holidayDates'    => $data['holidayDates'],
				'manualPrices'    => $data['manualPrices'],
				'regularPrice'    => $data['regularPrice'],
				'weekendPrice'    => $data['weekendPrice'],
				'baseCapacity'    => $data['baseCapacity'],
				'extraGuestPrice' => $data['extraGuestPrice'],
			)
		);
	}

	public static function render_calendar_page() {
		if ( ! class_exists( 'MTAL_Booking_Admin' ) || ! MTAL_Booking_Admin::current_user_can_manage_bookings() ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', 'mtal-persian-calendar' ), '', array( 'response' => 403 ) );
		}
		$suite_id = isset( $_GET['suite_id'] ) ? absint( $_GET['suite_id'] ) : 0;
		if ( ! $suite_id || ! MTAL_Suite_Registry::is_suite( $suite_id ) ) {
			wp_die( esc_html__( 'شناسه سوئیت معتبر نیست.', 'mtal-persian-calendar' ), '', array( 'response' => 404 ) );
		}
		$display = MTAL_Suite_Registry::get_display_data( $suite_id );
		if ( ! $display ) {
			wp_die( esc_html__( 'اطلاعات سوئیت در دسترس نیست.', 'mtal-persian-calendar' ), '', array( 'response' => 404 ) );
		}
		$data = MTAL_Persian_Calendar::get_suite_calendar_data( $suite_id );
		$source_post = MTAL_Suite_Registry::get_source_post( $suite_id );
		$back_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		echo '<div class="wrap" dir="rtl">';
		echo '<h1>مدیریت تقویم و قیمت‌ها: ' . esc_html( $display['title'] ) . '</h1>';
		echo '<p>شناسه مستقل MTAL: <strong>' . absint( $suite_id ) . '</strong>';
		if ( $source_post ) {
			echo ' | نوشته منبع: ' . esc_html( get_the_title( $source_post ) ) . ' (#' . absint( $source_post->ID ) . ')';
		}
		echo '</p><p><a class="button" href="' . esc_url( $back_url ) . '">بازگشت به مدیریت سوئیت‌ها</a></p>';
		echo '<div id="mtal-admin-cal-suite-' . absint( $suite_id ) . '" class="mtal-admin-cal-host" data-mtal-suite-id="' . absint( $suite_id ) . '" data-mtal-post-id="' . absint( $suite_id ) . '"';
		echo ' data-mtal-regular-price="' . esc_attr( $data['regularPrice'] ) . '" data-mtal-weekend-price="' . esc_attr( $data['weekendPrice'] ) . '"';
		echo ' data-mtal-base-capacity="' . esc_attr( $data['baseCapacity'] ) . '" data-mtal-extra-guest-price="' . esc_attr( $data['extraGuestPrice'] ) . '"';
		echo ' data-mtal-blocked-dates="' . esc_attr( wp_json_encode( $data['blockedDates'] ) ) . '" data-mtal-holiday-dates="' . esc_attr( wp_json_encode( $data['holidayDates'] ) ) . '"';
		echo ' data-mtal-manual-prices="' . esc_attr( wp_json_encode( $data['manualPrices'] ) ) . '"><div class="mtal-admin-cal-mount"></div></div></div>';
	}

	/**
	 * Render the dedicated, read-only MTAL diagnostics screen.
	 */
	public static function render_diagnostics_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', 'mtal-persian-calendar' ), '', array( 'response' => 403 ) );
		}

		if ( ! class_exists( 'MTAL_Suite_Registry' ) ) {
			wp_die( esc_html__( 'مدل سوئیت‌های MTAL در دسترس نیست.', 'mtal-persian-calendar' ) );
		}

		echo '<div class="wrap" dir="rtl">';
		echo '<h1>عیب‌یابی MTAL</h1>';
		echo '<p>این صفحه فقط وضعیت داده‌ها و مهاجرت را نمایش می‌دهد؛ هیچ تنظیم یا داده‌ای را تغییر نمی‌دهد و مقادیر متا یا اطلاعات شخصی مشتریان را نشان نمی‌دهد.</p>';
		self::render_migration_diagnostics();
		echo '</div>';
	}

	/**
	 * Show read-only migration diagnostics to site administrators.
	 */
	private static function render_migration_diagnostics() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state_v1 = get_option( '_mtal_suite_calendar_migration_v1', array() );
		$state_v2 = get_option( '_mtal_suite_calendar_migration_v2', array() );
		$state_v3 = get_option( '_mtal_suite_calendar_migration_v3', array() );
		$statuses = array( 'publish', 'private', 'draft', 'pending', 'future' );


		$source_status_counts = wp_count_posts( 'suites' );
		$all_sources_count = 0;
		foreach ( $statuses as $status ) {
			if ( isset( $source_status_counts->$status ) ) {
				$all_sources_count += (int) $source_status_counts->$status;
			}
		}



		$mtal_status_counts = wp_count_posts( MTAL_Suite_Registry::POST_TYPE );
		$mtal_count = 0;
		foreach ( $statuses as $status ) {
			if ( isset( $mtal_status_counts->$status ) ) {
				$mtal_count += (int) $mtal_status_counts->$status;
			}
		}

		$source_samples = new WP_Query(
			array(
				'post_type'              => 'suites',
				'post_status'            => $statuses,
				'posts_per_page'         => 10,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		echo '<div class="notice notice-info" style="padding:12px 16px;margin:16px 0;">';
		echo '<h2 style="margin-top:0;">گزارش وضعیت مهاجرت (فقط خواندنی)</h2>';
		echo '<p>این گزارش فقط برای مدیرکل نمایش داده می‌شود. از مقادیر متاهای ذخیره‌شده هیچ‌کدام نمایش داده نمی‌شود.</p>';
		echo '<ul style="list-style:disc;padding-right:20px;">';
		echo '<li>وضعیت ثبت نوع نوشته <code>suites</code>: <strong>' . ( post_type_exists( 'suites' ) ? 'ثبت شده' : 'ثبت نشده' ) . '</strong></li>';
		echo '<li>تعداد کل نوشته‌های <code>suites</code> در وضعیت‌های بررسی‌شده: <strong>' . absint( $all_sources_count ) . '</strong></li>';

		echo '<li>تعداد رکوردهای مستقل <code>' . esc_html( MTAL_Suite_Registry::POST_TYPE ) . '</code>: <strong>' . absint( $mtal_count ) . '</strong></li>';
		echo '<li>وضعیت مهاجرت v1: <code>' . esc_html( wp_json_encode( $state_v1 ) ) . '</code></li>';
		echo '<li>وضعیت مهاجرت v2: <code>' . esc_html( wp_json_encode( $state_v2 ) ) . '</code></li>';
		echo '<li>وضعیت مهاجرت v3: <code>' . esc_html( wp_json_encode( $state_v3 ) ) . '</code></li>';
		echo '</ul>';

		if ( ! empty( $source_samples->posts ) ) {
			echo '<h3>نمونه نوشته‌های suites و نام کلیدهای متا (حداکثر ۱۰ مورد)</h3>';
			echo '<table class="widefat striped"><thead><tr><th>شناسه</th><th>عنوان</th><th>نام کلیدهای متا</th></tr></thead><tbody>';
			foreach ( $source_samples->posts as $source_post_id ) {
				$all_meta = get_post_meta( $source_post_id );
				$keys = array_keys( $all_meta );
				sort( $keys );
				echo '<tr>';
				echo '<td>' . absint( $source_post_id ) . '</td>';
				echo '<td>' . esc_html( get_the_title( $source_post_id ) ) . '</td>';
				echo '<td><code>' . esc_html( implode( ', ', $keys ) ) . '</code></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p><strong>هیچ نوشته‌ای از نوع <code>suites</code> در وضعیت‌های بررسی‌شده پیدا نشد.</strong></p>';
		}

		echo '</div>';
	}

	public static function render_page() {
		if (
			! class_exists( 'MTAL_Booking_Admin' ) ||
			! MTAL_Booking_Admin::current_user_can_manage_bookings()
		) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', 'mtal-persian-calendar' ) );
		}

		if ( ! class_exists( 'MTAL_Suite_Registry' ) ) {
			wp_die( esc_html__( 'مدل سوئیت‌های MTAL در دسترس نیست.', 'mtal-persian-calendar' ) );
		}

		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$suites_query = new WP_Query(
			array(
				'post_type'      => MTAL_Suite_Registry::POST_TYPE,
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => 20,
				'paged'          => $paged,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => false,
			)
		);

		echo '<div class="wrap">';
		echo '<h1>مدیریت سوئیت‌ها</h1>';
		echo '<p>این فهرست، سوئیت‌هایی را نشان می‌دهد که در مدل مستقل MTAL ثبت شده‌اند. اطلاعات نوشته منبع فقط برای نمایش عنوان و تصویر جایگزین استفاده می‌شود.</p>';

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th style="width:80px;">شناسه</th>';
		echo '<th>عنوان سوئیت</th>';
		echo '<th>نوشته منبع</th>';
		echo '<th style="width:100px;">تصویر</th>';
		echo '<th style="width:120px;">وضعیت</th>';
		echo '</tr></thead><tbody>';

		if ( ! $suites_query->have_posts() ) {
			echo '<tr><td colspan="5">هنوز سوئیتی در مدل مستقل MTAL ثبت نشده است. در این مرحله فقط نمایش فهرست فعال شده و امکان افزودن سوئیت در مرحله جداگانه پیاده‌سازی خواهد شد.</td></tr>';
		} else {
			foreach ( $suites_query->posts as $suite ) {
				$display = MTAL_Suite_Registry::get_display_data( $suite->ID );
				if ( ! $display ) {
					continue;
				}

				$source_post = MTAL_Suite_Registry::get_source_post( $suite->ID );
				$source_label = '—';
				if ( $source_post ) {
					$source_type = get_post_type_object( $source_post->post_type );
					$source_name = $source_type && isset( $source_type->labels->singular_name )
						? $source_type->labels->singular_name
						: $source_post->post_type;
					$source_title = get_the_title( $source_post );
					$source_edit_url = get_edit_post_link( $source_post->ID, 'raw' );
					$source_label = esc_html( $source_name ) . ' #' . absint( $source_post->ID );
					if ( $source_edit_url && current_user_can( 'edit_post', $source_post->ID ) ) {
						$source_label = '<a href="' . esc_url( $source_edit_url ) . '">' . esc_html( $source_title ? $source_title : $source_name ) . '</a><br><small>' . esc_html( $source_name ) . ' #' . absint( $source_post->ID ) . '</small>';
					}
				}

				$status_object = get_post_status_object( $suite->post_status );
				$status_label = $status_object && ! empty( $status_object->label )
					? $status_object->label
					: $suite->post_status;

				echo '<tr>';
				echo '<td>' . absint( $suite->ID ) . '</td>';
				echo '<td><strong>' . esc_html( $display['title'] ) . '</strong><br><small>عنوان از ' . esc_html( 'override' === $display['titleSource'] ? 'تنظیم اختصاصی' : ( 'source' === $display['titleSource'] ? 'نوشته منبع' : 'رکورد MTAL' ) ) . '</small></td>';
				$calendar_url = add_query_arg( array( 'page' => self::CALENDAR_PAGE_SLUG, 'suite_id' => absint( $suite->ID ) ), admin_url( 'admin.php' ) );
				echo '<td>' . $source_label . '<br><a class="button button-small" href="' . esc_url( $calendar_url ) . '">مدیریت تقویم و قیمت‌ها</a></td>';
				echo '<td>';
				if ( ! empty( $display['imageId'] ) ) {
					echo wp_kses_post( wp_get_attachment_image( $display['imageId'], array( 56, 56 ), false, array( 'style' => 'width:56px;height:56px;object-fit:cover;' ) ) );
				} else {
					echo '—';
				}
				echo '</td>';
				echo '<td>' . esc_html( $status_label ) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		if ( $suites_query->max_num_pages > 1 ) {
			$base_url = add_query_arg(
				array(
					'page'  => self::PAGE_SLUG,
					'paged' => '%#%',
				),
				admin_url( 'admin.php' )
			);
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => $base_url,
						'format'    => '',
						'current'   => $paged,
						'total'     => (int) $suites_query->max_num_pages,
						'prev_text' => '« قبلی',
						'next_text' => 'بعدی »',
					)
				)
			);
			echo '</div></div>';
		}

		echo '</div>';
		wp_reset_postdata();
	}
}

MTAL_Suite_Admin::init();
