<?php
/**
 * Admin list screen for MTAL suite entities.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MTAL_Suite_Admin {

	const PAGE_SLUG = 'mtal-suite-management';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
	}

	public static function register_menu() {
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
		$meta_keys = array(
			'_mtal_blocked_dates',
			'_mtal_holiday_dates',
			'_mtal_manual_prices',
			'_mtal_regular_price',
			'_mtal_weekend_price',
			'_mtal_base_capacity',
			'_mtal_extra_guest_price',
		);

		$all_sources_count = new WP_Query(
			array(
				'post_type'      => 'suites',
				'post_status'    => $statuses,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);
		$source_samples = new WP_Query(
			array(
				'post_type'      => 'suites',
				'post_status'    => $statuses,
				'posts_per_page' => 10,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$calendar_source_ids = new WP_Query(
			array(
				'post_type'              => 'suites',
				'post_status'            => $statuses,
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$calendar_sources_count = 0;
		foreach ( $calendar_source_ids->posts as $source_post_id ) {
			foreach ( $meta_keys as $meta_key ) {
				if ( metadata_exists( 'post', $source_post_id, $meta_key ) ) {
					$calendar_sources_count++;
					break;
				}
			}
		}
		$mtal_count = new WP_Query(
			array(
				'post_type'      => MTAL_Suite_Registry::POST_TYPE,
				'post_status'    => $statuses,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);

		echo '<div class="notice notice-info" style="padding:12px 16px;margin:16px 0;">';
		echo '<h2 style="margin-top:0;">گزارش عیب‌یابی مهاجرت (فقط خواندنی)</h2>';
		echo '<p>این گزارش فقط برای مدیرکل نمایش داده می‌شود و هیچ داده یا تنظیمی را تغییر نمی‌دهد. از مقادیر متاهای ذخیره‌شده هیچ‌کدام نمایش داده نمی‌شود.</p>';
		echo '<ul style="list-style:disc;padding-right:20px;">';
		echo '<li>وضعیت ثبت نوع نوشته <code>suites</code>: <strong>' . ( post_type_exists( 'suites' ) ? 'ثبت شده' : 'ثبت نشده' ) . '</strong></li>';
		echo '<li>تعداد کل نوشته‌های <code>suites</code> در وضعیت‌های بررسی‌شده: <strong>' . absint( $all_sources_count->found_posts ) . '</strong></li>';
		echo '<li>تعداد نوشته‌های <code>suites</code> دارای حداقل یکی از ۷ کلید تقویم: <strong>' . absint( $calendar_sources_count ) . '</strong></li>';
		echo '<li>تعداد رکوردهای مستقل <code>' . esc_html( MTAL_Suite_Registry::POST_TYPE ) . '</code>: <strong>' . absint( $mtal_count->found_posts ) . '</strong></li>';
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
		self::render_migration_diagnostics();

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
				echo '<td>' . $source_label . '</td>';
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
