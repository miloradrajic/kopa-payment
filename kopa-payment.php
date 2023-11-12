<?php 
/**
 * @wordpress-plugin
 *
 * Plugin Name: KOPA Payment
 * Description: Add a KOPA payment method with credit cards to WooCommerce.
 * Version: 1.0.0
 * Requires PHP:      7.3
 * Requires at least: 6.0
 * Author:            Tehnološko Partnerstvo
 * Author URI:        kopa.rs
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       kopa-payment
 * Domain Path:       /languages
 * Contributors:      tehnoloskopartnerstvo, miloradrajic
 * Developed By:      Milorad Rajic <rajic.milorad@gmail.com>
 */

// If someone try to called this file directly via URL, abort.
if ( ! defined( 'WPINC' ) ) { die( "Don't mess with us." ); }
if ( ! defined( 'ABSPATH' ) ) { exit; }


// Start session
if(session_id() == '' || !isset($_SESSION) || session_status() === PHP_SESSION_NONE) {
  // session isn't started
  session_start();
}

define('KOPA_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('KOPA_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Require plugins.php for deactivate_plugins function if wocommerce is not active
 */
require_once ABSPATH . 'wp-admin/includes/plugin.php';

// // Register the uninstall hook
// register_uninstall_hook(__FILE__, 'custom_plugin_uninstall');

// // Define the uninstall callback function
// function custom_plugin_uninstall() {
  // $meta_type  = 'user';
  // $user_id    = 0; // This will be ignored, since we are deleting for all users.
  // $meta_key   = 'kopa_user_registered';
  // $meta_value = ''; // Also ignored. The meta will be deleted regardless of value.
  // $delete_all = true;
  
  // delete_metadata( $meta_type, $user_id, $meta_key, $meta_value, $delete_all );

// }

/**
 * Check if woocommerce plugin is active, if not, disable plugin and display error notice
 */
add_action('plugins_loaded', 'check_for_woocommerce');
function check_for_woocommerce() {
  if (!class_exists('WooCommerce')) {
    deactivate_plugins( plugin_basename( __FILE__ ) );
    echo '<div class="error"><p>KOPA payment Plugin requires WooCommerce to be active. Please activate WooCommerce to use this plugin.</p></div>';
    return;
  }
  require_once KOPA_PLUGIN_PATH . '/global-functions.php';
  require_once KOPA_PLUGIN_PATH . '/inc/kopa-class.php';
  require_once KOPA_PLUGIN_PATH . '/inc/curl.php';
  require_once KOPA_PLUGIN_PATH . '/inc/ajax-functions.php';
  require_once KOPA_PLUGIN_PATH . '/inc/order-status-change.php';
  require_once KOPA_PLUGIN_PATH . '/inc/my-account-functions.php';
  require_once KOPA_PLUGIN_PATH . '/inc/enqueue-scripts.php';
  require_once KOPA_PLUGIN_PATH . '/inc/log-functions.php';
  require_once KOPA_PLUGIN_PATH . '/inc/kopa-reference-id.php';

  // Adding custom payment method
  function addKopaPaymentGateway($gateways) {
    $gateways[] = 'KOPA_Payment';
    return $gateways;
  }
  // Add a custom payment gateway
  add_filter('woocommerce_payment_gateways', 'addKopaPaymentGateway');
}