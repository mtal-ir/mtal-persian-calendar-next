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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_suite_form_assets' ) );
		add_action( 'admin_post_mtal_save_suite', array( __CLASS__, 'handle_save_suite' ) );
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

	/**
	 * Load the WordPress media picker only on the suite management screen.
	 */
	public static function enqueue_suite_form_assets( $hook_suffix ) {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( ! in_array( $action, array( 'add', 'edit' ), true ) ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script( 'jquery' );
		wp_add_inline_script(
			'jquery',
			<<<'JS'
(function($){$(function(){
var frame;
$('#mtal-suite-select-image').on('click',function(e){e.preventDefault();if(frame){frame.open();return;}frame=wp.media({title:'انتخاب تصویر سوئیت',button:{text:'استفاده از تصویر'},multiple:false,library:{type:'image'}});frame.on('select',function(){var item=frame.state().get('selection').first().toJSON();$('#mtal-suite-image-id').val(item.id);$('#mtal-suite-image-preview').empty().append($('<img>',{src:item.url,alt:'',css:{maxWidth:'180px',height:'auto'}}));$('#mtal-suite-remove-image').prop('hidden',false);});frame.open();});
$('#mtal-suite-remove-image').on('click',function(e){e.preventDefault();$('#mtal-suite-image-id').val('0');$('#mtal-suite-image-preview').empty();$(this).prop('hidden',true);});
function formatPrice(value){var digits=String(value).replace(/[^0-9]/g,'');return digits.replace(/\\B(?=(\\d{3})+(?!\\d))/g,',');}
$('.mtal-price-input').each(function(){this.value=formatPrice(this.value);}).on('input',function(){var start=this.selectionStart;var before=this.value;var digitsBefore=before.slice(0,start).replace(/[^0-9]/g,'').length;this.value=formatPrice(before);var pos=0,count=0;while(pos<this.value.length&&count<digitsBefore){if(/[0-9]/.test(this.value.charAt(pos)))count++;pos++;}this.setSelectionRange(pos,pos);});
$('form').on('submit',function(){$('.mtal-price-input').each(function(){this.value=this.value.replace(/,/g,'');});});
});})(jQuery);
JS
		);
	}

	/**
	 * Save a new or existing independent MTAL suite.
	 */
	public static function handle_save_suite() {
		if ( ! class_exists( 'MTAL_Booking_Admin' ) || ! MTAL_Booking_Admin::current_user_can_manage_bookings() ) {
			wp_die( esc_html__( 'شما اجازه انجام این عملیات را ندارید.', 'mtal-persian-calendar' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'mtal_save_suite', 'mtal_suite_nonce' );

		if ( ! class_exists( 'MTAL_Suite_Registry' ) ) {
			wp_die( esc_html__( 'مدل سوئیت‌های MTAL در دسترس نیست.', 'mtal-persian-calendar' ) );
		}

		$suite_id = isset( $_POST['suite_id'] ) ? absint( $_POST['suite_id'] ) : 0;
		$title = isset( $_POST['suite_title'] ) ? sanitize_text_field( wp_unslash( $_POST['suite_title'] ) ) : '';
		$status = isset( $_POST['suite_status'] ) ? sanitize_key( wp_unslash( $_POST['suite_status'] ) ) : 'draft';
		$image_id = isset( $_POST['suite_image_id'] ) ? absint( $_POST['suite_image_id'] ) : 0;
		$source_post_id = isset( $_POST['source_post_id'] ) ? absint( $_POST['source_post_id'] ) : 0;
		$max_capacity = isset( $_POST['suite_max_capacity'] ) ? absint( $_POST['suite_max_capacity'] ) : 5;
		$max_capacity = max( 1, min( 50, $max_capacity ) );
		$base_capacity = isset( $_POST['suite_base_capacity'] ) ? absint( $_POST['suite_base_capacity'] ) : 2;
		$base_capacity = max( 1, min( $max_capacity, $base_capacity ) );
		$regular_price = isset( $_POST['suite_regular_price'] ) ? absint( str_replace( ',', '', wp_unslash( $_POST['suite_regular_price'] ) ) ) : 0;
		$weekend_price = isset( $_POST['suite_weekend_price'] ) ? absint( str_replace( ',', '', wp_unslash( $_POST['suite_weekend_price'] ) ) ) : 0;
		$extra_guest_price = isset( $_POST['suite_extra_guest_price'] ) ? absint( str_replace( ',', '', wp_unslash( $_POST['suite_extra_guest_price'] ) ) ) : 0;

		if ( '' === $title ) {
			wp_die( esc_html__( 'عنوان سوئیت الزامی است.', 'mtal-persian-calendar' ), '', array( 'response' => 400 ) );
		}
		if ( ! in_array( $status, array( 'publish', 'draft' ), true ) ) {
			$status = 'draft';
		}
		if ( $image_id && ( 'attachment' !== get_post_type( $image_id ) || ! wp_attachment_is_image( $image_id ) ) ) {
			wp_die( esc_html__( 'تصویر انتخاب‌شده معتبر نیست.', 'mtal-persian-calendar' ), '', array( 'response' => 400 ) );
		}

		if ( $suite_id && ! MTAL_Suite_Registry::is_suite( $suite_id ) ) {
			wp_die( esc_html__( 'شناسه سوئیت معتبر نیست.', 'mtal-persian-calendar' ), '', array( 'response' => 404 ) );
		}
		if ( $suite_id && ! current_user_can( 'edit_post', $suite_id ) ) {
			wp_die( esc_html__( 'شما اجازه ویرایش این سوئیت را ندارید.', 'mtal-persian-calendar' ), '', array( 'response' => 403 ) );
		}
		if ( ! $suite_id && ! current_user_can( 'publish_posts' ) && 'publish' === $status ) {
			wp_die( esc_html__( 'شما اجازه انتشار سوئیت را ندارید.', 'mtal-persian-calendar' ), '', array( 'response' => 403 ) );
		}

		$source_post = null;
		if ( $source_post_id ) {
			$source_post = get_post( $source_post_id );
			if ( ! $source_post || 'suites' !== $source_post->post_type || in_array( $source_post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
				wp_die( esc_html__( 'نوشته منبع انتخاب‌شده معتبر نیست.', 'mtal-persian-calendar' ), '', array( 'response' => 400 ) );
			}
			$linked_suite_id = MTAL_Suite_Registry::get_suite_by_source( $source_post_id, 'suites' );
			if ( $linked_suite_id && $linked_suite_id !== $suite_id ) {
				wp_die( esc_html__( 'این نوشته منبع قبلاً به سوئیت دیگری متصل شده است.', 'mtal-persian-calendar' ), '', array( 'response' => 409 ) );
			}
		}

		$post_data = array(
			'post_type'   => MTAL_Suite_Registry::POST_TYPE,
			'post_status' => $status,
			'post_title'  => $title,
		);
		if ( $suite_id ) {
			$post_data['ID'] = $suite_id;
			$result = wp_update_post( $post_data, true );
		} else {
			$result = wp_insert_post( $post_data, true );
		}
		if ( is_wp_error( $result ) || ! $result ) {
			wp_die( esc_html__( 'ذخیره سوئیت انجام نشد.', 'mtal-persian-calendar' ), '', array( 'response' => 500 ) );
		}
		$suite_id = absint( $result );

		update_post_meta( $suite_id, MTAL_Suite_Registry::META_TITLE_OVERRIDE, $title );
		update_post_meta( $suite_id, '_mtal_suite_max_capacity', $max_capacity );
		update_post_meta( $suite_id, '_mtal_base_capacity', $base_capacity );
		update_post_meta( $suite_id, '_mtal_regular_price', $regular_price );
		update_post_meta( $suite_id, '_mtal_weekend_price', $weekend_price );
		update_post_meta( $suite_id, '_mtal_extra_guest_price', $extra_guest_price );
		if ( $image_id ) {
			update_post_meta( $suite_id, MTAL_Suite_Registry::META_IMAGE_OVERRIDE, $image_id );
		} else {
			delete_post_meta( $suite_id, MTAL_Suite_Registry::META_IMAGE_OVERRIDE );
		}

		if ( $source_post ) {
			update_post_meta( $suite_id, MTAL_Suite_Registry::META_SOURCE_POST_TYPE, 'suites' );
			update_post_meta( $suite_id, MTAL_Suite_Registry::META_SOURCE_POST_ID, $source_post_id );
		} else {
			delete_post_meta( $suite_id, MTAL_Suite_Registry::META_SOURCE_POST_TYPE );
			delete_post_meta( $suite_id, MTAL_Suite_Registry::META_SOURCE_POST_ID );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&updated=1' ) );
		exit;
	}

	/**
	 * Render the add/edit form for an independent MTAL suite.
	 */
	private static function render_suite_form( $suite_id = 0 ) {
		$suite = $suite_id ? get_post( $suite_id ) : null;
		if ( $suite_id && ( ! $suite || MTAL_Suite_Registry::POST_TYPE !== $suite->post_type ) ) {
			wp_die( esc_html__( 'شناسه سوئیت معتبر نیست.', 'mtal-persian-calendar' ), '', array( 'response' => 404 ) );
		}
		if ( $suite_id && ! current_user_can( 'edit_post', $suite_id ) ) {
			wp_die( esc_html__( 'شما اجازه ویرایش این سوئیت را ندارید.', 'mtal-persian-calendar' ), '', array( 'response' => 403 ) );
		}

		$title = $suite_id ? (string) get_post_meta( $suite_id, MTAL_Suite_Registry::META_TITLE_OVERRIDE, true ) : '';
		if ( '' === $title && $suite_id ) {
			$title = get_the_title( $suite_id );
		}
		$image_id = $suite_id ? absint( get_post_meta( $suite_id, MTAL_Suite_Registry::META_IMAGE_OVERRIDE, true ) ) : 0;
		$source = $suite_id ? MTAL_Suite_Registry::get_source_post( $suite_id ) : null;
		$source_id = $source ? absint( $source->ID ) : 0;
		$status = $suite_id ? $suite->post_status : 'draft';
		$max_capacity = $suite_id ? absint( get_post_meta( $suite_id, '_mtal_suite_max_capacity', true ) ) : 5;
		$max_capacity = $max_capacity > 0 ? min( 50, $max_capacity ) : 5;
		$base_capacity = $suite_id ? absint( get_post_meta( $suite_id, '_mtal_base_capacity', true ) ) : 2;
		$base_capacity = $base_capacity > 0 ? min( $max_capacity, $base_capacity ) : min( 2, $max_capacity );
		$regular_price = $suite_id ? absint( get_post_meta( $suite_id, '_mtal_regular_price', true ) ) : 0;
		$weekend_price = $suite_id ? absint( get_post_meta( $suite_id, '_mtal_weekend_price', true ) ) : 0;
		$extra_guest_price = $suite_id ? absint( get_post_meta( $suite_id, '_mtal_extra_guest_price', true ) ) : 0;
		$source_posts = get_posts(
			array(
				'post_type'      => 'suites',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$linked_source_ids = array();
		$linked_suites = get_posts(
			array(
				'post_type'      => MTAL_Suite_Registry::POST_TYPE,
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $linked_suites as $linked_suite_id ) {
			$linked_type = (string) get_post_meta( $linked_suite_id, MTAL_Suite_Registry::META_SOURCE_POST_TYPE, true );
			$linked_id = absint( get_post_meta( $linked_suite_id, MTAL_Suite_Registry::META_SOURCE_POST_ID, true ) );
			if ( 'suites' === $linked_type && $linked_id ) {
				$linked_source_ids[ $linked_id ] = absint( $linked_suite_id );
			}
		}

		echo '<div class="wrap" dir="rtl">';
		echo '<h1>' . ( $suite_id ? 'ویرایش سوئیت' : 'افزودن سوئیت جدید' ) . '</h1>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="mtal_save_suite">';
		echo '<input type="hidden" name="suite_id" value="' . absint( $suite_id ) . '">';
		wp_nonce_field( 'mtal_save_suite', 'mtal_suite_nonce' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="mtal-suite-title">عنوان سوئیت</label></th><td><input class="regular-text" required type="text" id="mtal-suite-title" name="suite_title" value="' . esc_attr( $title ) . '"></td></tr>';
		echo '<tr><th colspan="2"><h2>ظرفیت و قیمت‌گذاری</h2></th></tr>';
		echo '<tr><th><label for="mtal-suite-base-capacity">ظرفیت پایه (نفر)</label></th><td><input type="number" id="mtal-suite-base-capacity" name="suite_base_capacity" min="1" max="' . absint( $max_capacity ) . '" step="1" required value="' . absint( $base_capacity ) . '"><p class="description">تعداد نفراتی که قیمت عادی و آخر هفته برای آن‌ها محاسبه می‌شود.</p></td></tr>';
		echo '<tr><th><label for="mtal-suite-max-capacity">حداکثر گنجایش (نفر)</label></th><td><input type="number" id="mtal-suite-max-capacity" name="suite_max_capacity" min="1" max="50" step="1" required value="' . absint( $max_capacity ) . '"><p class="description">بیشترین تعداد مسافر مجاز برای این سوئیت؛ ظرفیت پایه نباید از این مقدار بیشتر باشد.</p></td></tr>';
		echo '<tr><th><label for="mtal-suite-regular-price">قیمت عادی هر شب</label></th><td><input class="regular-text mtal-price-input" inputmode="numeric" type="text" id="mtal-suite-regular-price" name="suite_regular_price" value="' . esc_attr( number_format( $regular_price, 0, '', ',' ) ) . '"><p class="description">مبلغ به واحد پولی فعلی افزونه.</p></td></tr>';
		echo '<tr><th><label for="mtal-suite-weekend-price">قیمت آخر هفته هر شب</label></th><td><input class="regular-text mtal-price-input" inputmode="numeric" type="text" id="mtal-suite-weekend-price" name="suite_weekend_price" value="' . esc_attr( number_format( $weekend_price, 0, '', ',' ) ) . '"><p class="description">در صورت صفر بودن، قیمت عادی اعمال می‌شود.</p></td></tr>';
		echo '<tr><th><label for="mtal-suite-extra-guest-price">هزینه هر نفر اضافه در هر شب</label></th><td><input class="regular-text mtal-price-input" inputmode="numeric" type="text" id="mtal-suite-extra-guest-price" name="suite_extra_guest_price" value="' . esc_attr( number_format( $extra_guest_price, 0, '', ',' ) ) . '"></td></tr>';
		echo '<tr><th>تصویر سوئیت</th><td><input type="hidden" id="mtal-suite-image-id" name="suite_image_id" value="' . absint( $image_id ) . '"><div id="mtal-suite-image-preview">';
		if ( $image_id && wp_attachment_is_image( $image_id ) ) {
			echo wp_kses_post( wp_get_attachment_image( $image_id, 'medium', false, array( 'style' => 'max-width:180px;height:auto;' ) ) );
		}
		echo '</div><p><button type="button" class="button" id="mtal-suite-select-image">انتخاب تصویر</button> <button type="button" class="button" id="mtal-suite-remove-image"' . ( $image_id ? '' : ' hidden' ) . '>حذف تصویر اختصاصی</button></p><p class="description">در صورت حذف تصویر اختصاصی، تصویر نوشته منبع (در صورت اتصال) قابل استفاده خواهد بود.</p></td></tr>';
		echo '<tr><th><label for="mtal-suite-source">نوشته منبع</label></th><td><select id="mtal-suite-source" name="source_post_id"><option value="0">بدون نوشته منبع</option>';
		foreach ( $source_posts as $source_post ) {
			$linked_suite_id = isset( $linked_source_ids[ $source_post->ID ] ) ? $linked_source_ids[ $source_post->ID ] : 0;
			if ( $linked_suite_id && $linked_suite_id !== $suite_id ) {
				continue;
			}
			echo '<option value="' . absint( $source_post->ID ) . '"' . selected( $source_id, $source_post->ID, false ) . '>' . esc_html( get_the_title( $source_post ) ) . ' (#' . absint( $source_post->ID ) . ')</option>';
		}
		echo '</select><p class="description">اتصال به نوشته منبع اختیاری است و هر نوشته فقط می‌تواند به یک سوئیت MTAL متصل باشد.</p></td></tr>';
		echo '<tr><th><label for="mtal-suite-status">وضعیت</label></th><td><select id="mtal-suite-status" name="suite_status"><option value="draft"' . selected( $status, 'draft', false ) . '>پیش‌نویس</option><option value="publish"' . selected( $status, 'publish', false ) . '>منتشرشده</option></select></td></tr>';
		echo '</tbody></table>';
		submit_button( $suite_id ? 'ذخیره تغییرات' : 'ایجاد سوئیت' );
		echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">انصراف</a>';
		echo '</form></div>';
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
				'maxGuests'       => $data['maxCapacity'],
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
		echo ' data-mtal-base-capacity="' . esc_attr( $data['baseCapacity'] ) . '" data-mtal-extra-guest-price="' . esc_attr( $data['extraGuestPrice'] ) . '" data-mtal-max-guests="' . esc_attr( $data['maxCapacity'] ) . '"';
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

		$screen_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( in_array( $screen_action, array( 'add', 'edit' ), true ) ) {
			$suite_id = isset( $_GET['suite_id'] ) ? absint( $_GET['suite_id'] ) : 0;
			self::render_suite_form( 'edit' === $screen_action ? $suite_id : 0 );
			return;
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
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=add' ) ) . '">افزودن سوئیت</a></p>';
		echo '<p>این فهرست، سوئیت‌هایی را نشان می‌دهد که در مدل مستقل MTAL ثبت شده‌اند. اطلاعات نوشته منبع فقط برای نمایش عنوان و تصویر جایگزین استفاده می‌شود.</p>';

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th style="width:80px;">شناسه</th>';
		echo '<th>عنوان سوئیت</th>';
		echo '<th>نوشته منبع</th>';
		echo '<th style="width:100px;">تصویر</th>';
		echo '<th style="width:120px;">وضعیت</th>';
		echo '<th style="width:100px;">عملیات</th>';
		echo '</tr></thead><tbody>';

		if ( ! $suites_query->have_posts() ) {
			echo '<tr><td colspan="6">هنوز سوئیتی در مدل مستقل MTAL ثبت نشده است. برای شروع از دکمه «افزودن سوئیت» استفاده کنید.</td></tr>';
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
				echo '<td><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=edit&suite_id=' . absint( $suite->ID ) ) ) . '">ویرایش</a></td>';
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
