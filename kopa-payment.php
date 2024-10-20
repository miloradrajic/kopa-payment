<?php

/**
 * @wordpress-plugin
 *
 * Plugin Name: KOPA Payment
 * Description: Add a KOPA payment method with credit cards to WooCommerce.
 * Version:           1.1.14
 * Requires PHP:      7.4
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
if (!defined('WPINC')) {
  die("Don't mess with us.");
}
if (!defined('ABSPATH')) {
  exit;
}


// Start session
if (session_id() == '' || !isset($_SESSION) || session_status() === PHP_SESSION_NONE) {
  // session isn't started
  session_start();
}

define('KOPA_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('KOPA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_CUSTOM_ORDERS_TABLE', get_option('woocommerce_custom_orders_table_enabled', 'no'));

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
// $meta_key   = 'kopa_user_registered_code';
// $meta_value = ''; // Also ignored. The meta will be deleted regardless of value.
// $delete_all = true;

// delete_metadata( $meta_type, $user_id, $meta_key, $meta_value, $delete_all );

// }

/**
 * Check if woocommerce plugin is active, if not, disable plugin and display error notice
 */
add_action('plugins_loaded', 'check_for_woocommerce');
function check_for_woocommerce()
{
  if (!class_exists('WooCommerce')) {
    deactivate_plugins(plugin_basename(__FILE__));
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
  require_once KOPA_PLUGIN_PATH . '/inc/payment-endpoint.php';

  // Adding custom payment method
  function addKopaPaymentGateway($gateways)
  {
    $gateways[] = 'KOPA_Payment';
    return $gateways;
  }
  // Add a custom payment gateway
  add_filter('woocommerce_payment_gateways', 'addKopaPaymentGateway');
}


// Safe redirect users to checkout when trying to pay orders from my account
function redirect_unpaid_order_to_checkout()
{
  // Check if the 'pay_for_order' and 'key' parameters are set in the URL
  if (isset($_GET['pay_for_order']) && $_GET['pay_for_order'] === 'true' && isset($_GET['key'])) {
    $order_key = $_GET['key'];

    // Retrieve the order ID using the order key
    $order_id = wc_get_order_id_by_order_key($order_key);

    // Check if the order ID is valid
    if ($order_id) {
      // Redirect users to the checkout page with the specified order
      wp_safe_redirect(wc_get_checkout_url() . '?pay_for_order=true&order_id=' . $order_id);
      exit;
    }
  }
}
add_action('template_redirect', 'redirect_unpaid_order_to_checkout');

/**
 * If order was attempted to pay with kopa-payment, but changed after failed payment and completed with other payment method
 * remove kopaIdReferenceId meta field value from order
 */
function modifyOrderOnUpdate($orderId)
{
  $order = wc_get_order($orderId);
  $payment_method = $order->get_payment_method();
  // Check if payment method is not "Kopa payment"
  if ($payment_method !== 'kopa-payment') {
    // Check if custom meta 'kopaIdReferenceId' is set
    $kopaReferenceId = $order->get_meta('kopaIdReferenceId');
    if (!empty($kopaReferenceId)) {
      // Delete the custom meta field 'kopaIdReferenceId'
      $order->delete_meta_data('kopaIdReferenceId');
      $order->save(); // Save the changes
    }
  }
}
add_action('save_post_shop_order', 'modifyOrderOnUpdate');
