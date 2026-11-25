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
		$meta_keys = array(
			'_mtal_blocked_dates',
			'_mtal_holiday_dates',
			'_mtal_manual_prices',
			'_mtal_regular_price',
			'_mtal_weekend_price',
			'_mtal_base_capacity',
			'_mtal_extra_guest_price',
		);
		$calendar_meta_query = array( 'relation' => 'OR' );
		foreach ( $meta_keys as $meta_key ) {
			$calendar_meta_query[] = array(
				'key'     => $meta_key,
				'compare' => 'EXISTS',
			);
		}

		$source_query = new WP_Query(
			array(
				'post_type'      => 'suites',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page' => 100,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => $calendar_meta_query,
			)
		);

		echo '<div class="notice notice-info" style="padding:12px 16px;margin:16px 0;">';
		echo '<h2 style="margin-top:0;">گزارش عیب‌یابی مهاجرت (فقط خواندنی)</h2>';
		echo '<p>این گزارش فقط برای مدیرکل نمایش داده می‌شود و هیچ داده یا تنظیمی را تغییر نمی‌دهد.</p>';
		echo '<ul style="list-style:disc;padding-right:20px;">';
		echo '<li>وضعیت مهاجرت v1: <code>' . esc_html( wp_json_encode( $state_v1 ) ) . '</code></li>';
		echo '<li>وضعیت مهاجرت v2: <code>' . esc_html( wp_json_encode( $state_v2 ) ) . '</code></li>';
		echo '<li>تعداد نوشته‌های نوع <code>suites</code> دارای حداقل یکی از ۷ کلید تقویم: <strong>' . absint( $source_query->found_posts ) . '</strong></li>';
		echo '<li>تعداد شناسه‌های نمایش‌داده‌شده در این گزارش: <strong>' . absint( count( $source_query->posts ) ) . '</strong> (حداکثر ۱۰۰)</li>';
		echo '</ul>';

		if ( ! empty( $source_query->posts ) ) {
			echo '<table class="widefat striped" style="margin-top:10px;"><thead><tr><th>شناسه نوشته</th><th>عنوان</th><th>کلیدهای تقویم موجود</th><th>شناسه رکورد مستقل</th></tr></thead><tbody>';
			foreach ( $source_query->posts as $source_post_id ) {
				$present_keys = array();
				foreach ( $meta_keys as $meta_key ) {
					if ( metadata_exists( 'post', $source_post_id, $meta_key ) ) {
						$present_keys[] = $meta_key;
					}
				}
				$suite_id = MTAL_Suite_Registry::get_suite_by_source( $source_post_id, 'suites' );
				echo '<tr>';
				echo '<td>' . absint( $source_post_id ) . '</td>';
				echo '<td>' . esc_html( get_the_title( $source_post_id ) ) . '</td>';
				echo '<td><code>' . esc_html( implode( ', ', $present_keys ) ) . '</code></td>';
				echo '<td>' . ( $suite_id ? absint( $suite_id ) : 'یافت نشد' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p><strong>هیچ نوشته‌ای با این شرایط پیدا نشد.</strong> اگر تقویم از کلیدهای دیگری استفاده می‌کند یا نوع نوشته متفاوت است، باید همان مورد را بررسی کنیم.</p>';
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
