<?php
/**
 * Elementor Persian Calendar Widget (Inline Single, Inline 2-Col Horizontal, 3-Month Stacked Modal).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

class MTAL_Inline_Calendar_Widget extends Widget_Base {

	public function get_name() {
		return 'mtal_inline_persian_calendar';
	}

	public function get_title() {
		return esc_html__( 'تقویم جلالی مستقل (MTAL)', 'mtal-persian-calendar' );
	}

	public function get_icon() {
		return 'eicon-calendar';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_style_depends() {
		return array( 'mtal-persian-calendar-css' );
	}

	public function get_script_depends() {
		return array(
			'mtal-persian-calendar-engine',
			'mtal-persian-calendar-init',
		);
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_calendar_settings',
			array(
				'label' => esc_html__( 'تنظیمات تقویم MTAL', 'mtal-persian-calendar' ),
			)
		);

		$this->add_control(
			'display_mode',
			array(
				'label'   => esc_html__( 'حالت نمایش تقویم', 'mtal-persian-calendar' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'inline',
				'options' => array(
					'inline'        => esc_html__( 'اینلاین تکی', 'mtal-persian-calendar' ),
					'inline_2col'   => esc_html__( 'اینلاین دوتایی افقی (دسکتاپ)', 'mtal-persian-calendar' ),
					'modal_3months' => esc_html__( 'مودال ۳ ماهه عمودی (زیر هم)', 'mtal-persian-calendar' ),
				),
			)
		);

		$this->add_control(
			'max_months',
			array(
				'label'   => esc_html__( 'حداکثر بازه ماه قابل انتخاب', 'mtal-persian-calendar' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 3,
				'min'     => 1,
				'max'     => 3,
			)
		);

		$this->add_control(
			'trigger_label',
			array(
				'label'     => esc_html__( 'متن دکمه باز کردن مودال', 'mtal-persian-calendar' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'انتخاب تاریخ سفر', 'mtal-persian-calendar' ),
				'condition' => array(
					'display_mode' => 'modal_3months',
				),
			)
		);

		$this->add_control(
			'target_input_selector',
			array(
				'label'       => esc_html__( 'سلکتور فیلد مخفی تاریخ ورود و خروج', 'mtal-persian-calendar' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => '#form-field-checkin_checkout',
				'description' => esc_html__( 'مقدار بازه انتخابی در این فیلد فرم المنتور ریخته می‌شود.', 'mtal-persian-calendar' ),
			)
		);

		$this->add_control(
			'target_total_price_selector',
			array(
				'label'       => esc_html__( 'سلکتور فیلد مخفی مبلغ کل (تومان)', 'mtal-persian-calendar' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => '#form-field-total_price',
				'description' => esc_html__( 'مبلغ نهایی رزرو (شامل شب‌ها + هزینه نفر اضافه) در این فیلد مخفی قرار می‌گیرد.', 'mtal-persian-calendar' ),
			)
		);

		$this->add_control(
			'target_extra_guest_selector',
			array(
				'label'       => esc_html__( 'سلکتور فیلد مخفی نفر اضافه (تعداد و هزینه)', 'mtal-persian-calendar' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => '#form-field-extra_guests',
				'description' => esc_html__( 'تعداد نفرات اضافه و مبلغ کل نفر اضافه در این فیلد مخفی قرار می‌گیرد.', 'mtal-persian-calendar' ),
			)
		);

		$this->end_controls_section();

		if ( class_exists( 'MTAL_Booking_Admin' ) && MTAL_Booking_Admin::current_user_can_manage_bookings() ) {
			$this->start_controls_section(
				'section_booking_management',
				array(
					'label' => esc_html__( 'مدیریت رزروها', 'mtal-persian-calendar' ),
				)
			);

			$this->add_control(
				'booking_management_link',
				array(
					'type' => Controls_Manager::RAW_HTML,
					'raw'  => '<a class="elementor-button elementor-button-default" href="' . esc_url( admin_url( 'admin.php?page=mtal-booking-management' ) ) . '" target="_blank" rel="noopener noreferrer">رفتن به مدیریت رزروها</a><p class="description">فهرست درخواست‌ها و عملیات تأیید یا رد رزروها در پیشخوان وردپرس باز می‌شود.</p>',
				)
			);

			$this->end_controls_section();
		}
	}

	protected function render() {
		$settings             = $this->get_settings_for_display();
		$raw_mode             = isset( $settings['display_mode'] ) ? $settings['display_mode'] : 'inline';
		$allowed_modes        = array( 'inline', 'inline_2col', 'modal_3months' );
		$mode                 = in_array( $raw_mode, $allowed_modes, true ) ? $raw_mode : 'inline';
		$max_months           = isset( $settings['max_months'] ) ? max( 1, min( 3, intval( $settings['max_months'] ) ) ) : 3;
		$target               = ! empty( $settings['target_input_selector'] ) ? esc_attr( $settings['target_input_selector'] ) : '';
		$target_total_price   = ! empty( $settings['target_total_price_selector'] ) ? esc_attr( $settings['target_total_price_selector'] ) : '';
		$target_extra_guest   = ! empty( $settings['target_extra_guest_selector'] ) ? esc_attr( $settings['target_extra_guest_selector'] ) : '';
		$trigger_label        = ! empty( $settings['trigger_label'] ) ? esc_html( $settings['trigger_label'] ) : esc_html__( 'انتخاب تاریخ سفر', 'mtal-persian-calendar' );
		$widget_id            = 'mtal-cal-widget-' . esc_attr( $this->get_id() );
		$post_id              = get_the_ID();
		$storage_scope        = 'suite_' . $post_id;
		$host_class           = 'modal_3months' === $mode ? 'mtal-cal-modal-host' : 'mtal-cal-inline-host';
		$suite_data           = \MTAL_Persian_Calendar::get_suite_calendar_data( $post_id );
		$visible_blocked_dates = $suite_data['blockedDates'];
		?>
		<div
			id="<?php echo $widget_id; ?>"
			class="mtal-cal-wrap <?php echo esc_attr( $host_class ); ?>"
			data-mtal-post-id="<?php echo esc_attr( $post_id ); ?>"
			data-mtal-mode="<?php echo esc_attr( $mode ); ?>"
			data-mtal-max-months="<?php echo esc_attr( $max_months ); ?>"
			data-mtal-target="<?php echo $target; ?>"
			data-mtal-target-total-price="<?php echo $target_total_price; ?>"
			data-mtal-target-extra-guest="<?php echo $target_extra_guest; ?>"
			data-mtal-storage-scope="<?php echo esc_attr( $storage_scope ); ?>"
			data-mtal-trigger-label="<?php echo $trigger_label; ?>"
			data-mtal-blocked-dates="<?php echo esc_attr( wp_json_encode( $visible_blocked_dates ) ); ?>"
			data-mtal-holiday-dates="<?php echo esc_attr( wp_json_encode( $suite_data['holidayDates'] ) ); ?>"
			data-mtal-manual-prices="<?php echo esc_attr( wp_json_encode( $suite_data['manualPrices'] ) ); ?>"
			data-mtal-regular-price="<?php echo esc_attr( $suite_data['regularPrice'] ); ?>"
			data-mtal-weekend-price="<?php echo esc_attr( $suite_data['weekendPrice'] ); ?>"
			data-mtal-base-capacity="<?php echo esc_attr( $suite_data['baseCapacity'] ); ?>"
			data-mtal-extra-guest-price="<?php echo esc_attr( $suite_data['extraGuestPrice'] ); ?>"
		>
			<div class="mtal-cal-inline-mount"></div>
		</div>
		<?php
	}
}