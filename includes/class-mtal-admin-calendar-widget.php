<?php
/**
 * Elementor Admin Calendar & Pricing Management Widget (MTAL).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

class MTAL_Admin_Calendar_Widget extends Widget_Base {

	public function get_name() {
		return 'mtal_admin_persian_calendar';
	}

	public function get_title() {
		return esc_html__( 'مدیریت تقویم و قیمت ادمین (MTAL)', 'mtal-persian-calendar' );
	}

	public function get_icon() {
		return 'eicon-cog';
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
			'section_admin_pricing_defaults',
			array(
				'label' => esc_html__( 'قیمت‌گذاری و تنظیمات پایه', 'mtal-persian-calendar' ),
			)
		);

		$this->add_control(
			'suite_post_id',
			array(
				'label'       => esc_html__( 'شناسه پست/سوئیت (خالی = پست فعلی)', 'mtal-persian-calendar' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 0,
				'min'         => 0,
				'description' => esc_html__( 'اگر این ویجت را داخل صفحه سوئیت قرار داده‌اید روی ۰ بگذارید تا شناسه همان سوئیت را بگیرد.', 'mtal-persian-calendar' ),
			)
		);

		$this->add_control(
			'regular_price',
			array(
				'label'   => esc_html__( 'قیمت معمولی (سایر روزها به جز چهارشنبه و پنجشنبه)', 'mtal-persian-calendar' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 0,
				'min'     => 0,
			)
		);

		$this->add_control(
			'weekend_price',
			array(
				'label'   => esc_html__( 'قیمت آخر هفته (فقط چهارشنبه و پنجشنبه)', 'mtal-persian-calendar' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 0,
				'min'     => 0,
			)
		);

		$this->add_control(
			'base_capacity',
			array(
				'label'   => esc_html__( 'ظرفیت پایه پیش‌فرض (نفر)', 'mtal-persian-calendar' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 2,
				'min'     => 1,
			)
		);

		$this->add_control(
			'extra_guest_price',
			array(
				'label'   => esc_html__( 'قیمت نفر اضافه پیش‌فرض (تومان / هر شب)', 'mtal-persian-calendar' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 0,
				'min'     => 0,
			)
		);

		$this->add_control(
			'default_holidays',
			array(
				'label'       => esc_html__( 'تعطیلات رسمی / پیک (هر تاریخ در یک خط یا با کاما: 1405/09/05)', 'mtal-persian-calendar' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 4,
				'placeholder' => "1405/09/05\n1405/09/12",
			)
		);

		$this->add_control(
			'default_blocked_dates',
			array(
				'label'       => esc_html__( 'روزهای مسدود اولیه (1405/09/02)', 'mtal-persian-calendar' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 3,
				'placeholder' => '1405/09/02',
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings    = $this->get_settings_for_display();
		$post_id     = ! empty( $settings['suite_post_id'] ) ? absint( $settings['suite_post_id'] ) : get_the_ID();
		$stored_data = \MTAL_Persian_Calendar::get_suite_calendar_data( $post_id );

		$regular_price     = $stored_data['regularPrice'] > 0 ? $stored_data['regularPrice'] : max( 0, intval( $settings['regular_price'] ?? 0 ) );
		$weekend_price     = $stored_data['weekendPrice'] > 0 ? $stored_data['weekendPrice'] : max( 0, intval( $settings['weekend_price'] ?? 0 ) );
		$base_capacity     = $stored_data['baseCapacity'] > 0 ? $stored_data['baseCapacity'] : max( 1, intval( $settings['base_capacity'] ?? 2 ) );
		$extra_guest_price = $stored_data['extraGuestPrice'] > 0 ? $stored_data['extraGuestPrice'] : max( 0, intval( $settings['extra_guest_price'] ?? 0 ) );

		$holidays = $stored_data['holidayDates'];
		if ( empty( $holidays ) && ! empty( $settings['default_holidays'] ) ) {
			$holidays = \MTAL_Persian_Calendar::normalize_date_list( $settings['default_holidays'] );
		}

		$blocked = $stored_data['blockedDates'];
		if ( empty( $blocked ) && ! empty( $settings['default_blocked_dates'] ) ) {
			$blocked = \MTAL_Persian_Calendar::normalize_date_list( $settings['default_blocked_dates'] );
		}

		$manual_prices = $stored_data['manualPrices'];
		$widget_id     = 'mtal-admin-cal-' . esc_attr( $this->get_id() );
		?>
		<div
			id="<?php echo $widget_id; ?>"
			class="mtal-admin-cal-host"
			data-mtal-post-id="<?php echo esc_attr( $post_id ); ?>"
			data-mtal-regular-price="<?php echo esc_attr( $regular_price ); ?>"
			data-mtal-weekend-price="<?php echo esc_attr( $weekend_price ); ?>"
			data-mtal-base-capacity="<?php echo esc_attr( $base_capacity ); ?>"
			data-mtal-extra-guest-price="<?php echo esc_attr( $extra_guest_price ); ?>"
			data-mtal-blocked-dates="<?php echo esc_attr( wp_json_encode( $blocked ) ); ?>"
			data-mtal-holiday-dates="<?php echo esc_attr( wp_json_encode( $holidays ) ); ?>"
			data-mtal-manual-prices="<?php echo esc_attr( wp_json_encode( $manual_prices ) ); ?>"
		>
			<div class="mtal-admin-cal-mount"></div>
		</div>
		<?php
	}
}