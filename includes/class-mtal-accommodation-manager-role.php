<?php
/**
 * MTAL accommodation manager role and focused admin experience.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MTAL_Accommodation_Manager_Role {

	const ROLE = 'mtal_accommodation_manager';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_role' ), 30 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ), 99 );
		add_action( 'admin_init', array( __CLASS__, 'redirect_dashboard' ) );
		add_action( 'admin_menu', array( __CLASS__, 'remove_dashboard_menu' ), 999 );
	}

	/**
	 * Mirror WooCommerce Shop Manager capabilities without granting site-admin
	 * capabilities such as manage_options.
	 */
	public static function register_role() {
		$shop_manager = get_role( 'shop_manager' );
		$role         = get_role( self::ROLE );

		if ( ! $role ) {
			$role = add_role( self::ROLE, 'مدیر اقامتگاه', array( 'read' => true ) );
		}

		if ( ! $role ) {
			return;
		}

		if ( $shop_manager ) {
			foreach ( $shop_manager->capabilities as $capability => $grant ) {
				if ( $grant ) {
					$role->add_cap( $capability );
				}
			}
		}

		// MTAL suite records use WordPress post meta capabilities internally.
		$suite_caps = array(
			'read',
			'upload_files',
			'edit_posts',
			'edit_others_posts',
			'edit_published_posts',
			'edit_private_posts',
			'publish_posts',
			'read_private_posts',
			'delete_posts',
			'delete_others_posts',
			'delete_published_posts',
			'delete_private_posts',
		);

		foreach ( $suite_caps as $capability ) {
			$role->add_cap( $capability );
		}

		// Explicitly keep site-wide administration privileges out of this role.
		$role->remove_cap( 'manage_options' );
		$role->remove_cap( 'activate_plugins' );
		$role->remove_cap( 'install_plugins' );
		$role->remove_cap( 'update_plugins' );
		$role->remove_cap( 'delete_plugins' );
		$role->remove_cap( 'switch_themes' );
		$role->remove_cap( 'edit_theme_options' );
		$role->remove_cap( 'update_themes' );
	}

	public static function is_role_user() {
		$user = wp_get_current_user();
		return $user && in_array( self::ROLE, (array) $user->roles, true );
	}

	public static function hide_admin_bar( $show ) {
		return self::is_role_user() ? false : $show;
	}

	/**
	 * Send the role to WooCommerce orders instead of the WordPress dashboard.
	 */
	public static function redirect_dashboard() {
		if ( ! self::is_role_user() || ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		global $pagenow;
		if ( 'index.php' !== $pagenow ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$hpos_enabled = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
			&& \\Automattic\\WooCommerce\\Utilities\\OrderUtil::custom_orders_table_usage_is_enabled();
		$orders_url = $hpos_enabled
			? admin_url( 'admin.php?page=wc-orders' )
			: admin_url( 'edit.php?post_type=shop_order' );

		wp_safe_redirect( $orders_url );
		exit;
	}

	public static function remove_dashboard_menu() {
		if ( self::is_role_user() ) {
			remove_menu_page( 'index.php' );
		}
	}
}

MTAL_Accommodation_Manager_Role::init();
