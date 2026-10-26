<?php
/**
 *  * Plugin Name: کارت‌های قیمت لحظه‌ای آهن‌شهر
 * Plugin URI: https://github.com/moeinoa/
 * Description: اسلایدر کارتی قیمت لحظه‌ای؛ جداول افزونهٔ Ahan Price Tables را به کارت تبدیل می‌کند. شورتکد: [apc_slider]
 * Version: 1.6.0
 * Author: Moein Akbari
 * Author URI: https://moein-akbari.ir/
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Text Domain: ahan-price-cards
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'APC_VERSION', '1.6.0' );
define( 'APC_DIR', plugin_dir_path( __FILE__ ) );
define( 'APC_URL', plugin_dir_url( __FILE__ ) );
define( 'APC_OPTION', 'apc_settings' );

require_once APC_DIR . 'includes/class-apc-admin.php';
require_once APC_DIR . 'includes/class-apc-render.php';

add_action( 'plugins_loaded', function () {
    APC_Render::init();
    if ( is_admin() ) {
        APC_Admin::init();
    }
} );
