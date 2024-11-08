<?php

/**
 * @wordpress-plugin
 *
 * Plugin Name: KOPA Payment
 * Description: Add a KOPA payment method with credit cards to WooCommerce.
 * Version:           1.1.19
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
  require_once KOPA_PLUGIN_PATH . '/inc/fiscalization.php';

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
  if (!$order) {
    return; // Exit if order is not found
  }
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

/**
 * Custom notice was added when redirecting with REST API, because WC session cant be used
 * @return void
 */
function displayCustomNotice()
{
  if (isset($_SESSION['custom_notice'])) {
    $notice = $_SESSION['custom_notice'];
    // Display the notice using WooCommerce's wc_add_notice()
    wc_add_notice($notice, 'error');
    // Unset the notice from the session after displaying it
    unset($_SESSION['custom_notice']);
  }
}
add_action('woocommerce_before_checkout_form', 'displayCustomNotice');

/**
 * When changing redirecting type from "REST API" to "Regular", it is needed to flush rewrite rules automatically
 * @param string $old_value
 * @param string $value
 * @param mixed $option
 * @return void
 */
function flushKopaRevriteRulesOnRedirectPageTypeChange($old_value, $value, $option)
{
  if (isset($old_value['kopa_api_redirect_page_type']) && isset($value['kopa_api_redirect_page_type'])) {
    if ($old_value['kopa_api_redirect_page_type'] !== $value['kopa_api_redirect_page_type']) {
      // If the value has changed, flush rewrite rules
      flush_rewrite_rules();
    }
  }
}
add_action('update_option_woocommerce_kopa-payment_settings', 'flushKopaRevriteRulesOnRedirectPageTypeChange', 10, 3);

// Register custom endpoint
function custom_kopa_payment_endpoint()
{
  add_rewrite_rule('^kopa-payment-data/accept-order/([^/]+)/?', 'index.php?accept_order_id=$matches[1]', 'top');
  add_filter('query_vars', function ($vars) {
    $vars[] = 'accept_order_id';
    return $vars;
  });
}
add_action('init', 'custom_kopa_payment_endpoint', 999);

function custom_kopa_payment_rest_endpoint()
{
  register_rest_route('kopa-payment/v1', '/payment-data/accept-order/(?P<id>\d+)', array(
    'methods' => 'POST',
    'callback' => 'handle_kopa_payment_rest_endpoint',
    'permission_callback' => '__return_true',
    'args' => [
      'id' => [
        'required' => true, // ID is required
        'validate_callback' => function ($param, $request, $key) {
          return is_numeric($param); // Make sure ID is a number
        },
        'sanitize_callback' => 'absint', // Sanitize ID as an integer
      ],
    ]
  ));
  register_rest_route('kopa-payment/v1', '/test', array(
    'methods' => 'GET',
    'callback' => 'handle_test_endpoint',
    'permission_callback' => '__return_true',
  ));
  register_rest_route('kopa-payment/v1', '/payment-redirect/(?P<id>\d+)', array(
    'methods' => 'GET',
    'callback' => 'handle_kopa_payment_rest_redirect',
    'permission_callback' => '__return_true',
    'args' => [
      'id' => [
        'required' => true, // ID is required
        'validate_callback' => function ($param, $request, $key) {
          return is_numeric($param); // Make sure ID is a number
        },
        'sanitize_callback' => 'absint', // Sanitize ID as an integer
      ],
    ]
  ));
}
add_action('rest_api_init', 'custom_kopa_payment_rest_endpoint');

add_action('add_meta_boxes', 'kopaFiscalizationSection');
function kopaFiscalizationSection()
{
  if (
    isset(get_option('woocommerce_kopa-payment_settings')['kopa_enable_fiscalization']) &&
    get_option('woocommerce_kopa-payment_settings')['kopa_enable_fiscalization'] == 'yes'
  ) {
    add_meta_box(
      'kopa_fiscalization_metabox',           // Unique ID for the metabox
      __('Kopa Fiscalization', 'kopa-payment'), // Title of the metabox
      'custom_order_metabox_content',   // Callback function to display content
      'shop_order',                       // Post type to display the metabox on
      'normal',                          // Context: 'side' for the left column
      'default'                         // Priority
    );
  }
}

function custom_order_metabox_content($post)
{
  // Optional: Use nonce for verification
  wp_nonce_field('save_custom_order_metabox_data', 'custom_order_metabox_nonce');
  $order = wc_get_order($post->ID);

  $kopaReferenceId = $order->get_meta('kopaIdReferenceId');
  $paymentDataSerialized = serializeTransactionDetails($order->get_meta('kopaOrderPaymentData'));
  $fiscalizationDataSerialized = serializeTransactionDetails($order->get_meta('kopaOrderFiscalizationData'));


  $invoiceType = $order->get_meta('kopaFiscalizationType');
  $invoiceNumber = $order->get_meta('kopaFiscalizationInvoiceNumber');
  $verificationUrl = $order->get_meta('kopaFiscalizationVerificationUrl');

  $invoiceRefundNumber = $order->get_meta('kopaFiscalizationRefundNumber');
  $verificationUrlRefund = $order->get_meta('kopaFiscalizationRefundVerificationUrl');

  echo '<div class="fiscalizationStatusWrapper">';
  echo '<div class="fiscalizationStatus">';
  echo '<h4>' . __('Fiscalization status', 'kopa-payment') . '</h4><p>' . $invoiceType . '</p>';
  echo '</div>';
  echo '<div class="fiscalizationStatus">';
  echo '<h4>' . __('Fiscalization invoice number', 'kopa-payment') . '</h4><p>' . $invoiceNumber . '</p>';
  echo '</div>';
  echo '<div class="fiscalizationStatus">';
  echo '<h4>' . __('Fiscalization invoice verification URL', 'kopa-payment') . '</h4>
      <p><a href="' . $verificationUrl . '" target="_blank">' . __('Verify fiscalization', 'kopa-payment') . '</a></p>';
  echo '</div>';
  if ($invoiceType == 'refund_success') {
    echo '<div class="fiscalizationStatus">';
    echo '<h4>' . __('Fiscalization refund number', 'kopa-payment') . '</h4><p>' . $invoiceNumber . '</p>';
    echo '</div>';
    echo '<div class="fiscalizationStatus">';
    echo '<h4>' . __('Fiscalization refund verification URL', 'kopa-payment') . '</h4>
      <p><a href="' . $verificationUrl . '" target="_blank">' . __('Verify refund', 'kopa-payment') . '</a></p>';
    echo '</div>';
  }
  echo '</div>';

  $currency_symbol = get_woocommerce_currency_symbol();
  if (
    !empty($paymentDataSerialized) &&
    !empty($kopaReferenceId)
    // !empty($fiscalizationDataSerialized)
  ) {
    $alreadyRefunded = $order->get_meta('partialyRefundedItems');

    echo '<div class="kopaRefundItemWrapper">';
    foreach ($order->get_items() as $itemId => $item) {
      $product_name = $item->get_name(); // Product name
      $quantity = $item->get_quantity(); // Quantity of this item
      $total = $item->get_total(); // Line total for this item
      echo '<div class="kopaRefundItem" data-prod-id=' . $itemId . ' data-prod-price=' . $total . ' >';
      echo '<span>' . __('Product Name', 'kopa-payment') . ': ' . $product_name . '</span>';
      echo '<span>' . __('Quantity', 'kopa-payment') . ': ' . $quantity . '</span>';
      echo '<span>' . __('Total', 'kopa-payment') . ': ' . wc_price($total) . '</span>';
      echo '<span>' . __('Already refunded', 'kopa-payment') . ': 
        <input id="kopa_already_refunded_' . $itemId . '" type="number" readonly class="kopaAlreadyRefunded" value="0"/></span>';
      echo '<span class="relative">' . __('Refund quantity', 'kopa-payment') . ': 
        <input class="kopaRefundQuantity" type="number" min="0" max="' . $quantity . '"name="kopa_refund_item[' . $itemId . ']" value="0"/></span>';
      echo '<span>' . __('Total for refund', 'kopa-payment') . ':</br><span class="kopaItemRefundTotal">0</span> ' . $currency_symbol . '</span>';
      echo '</div>';
    }
    echo '</div>';
    echo '<p><label for="custom_order_field">' . __('Custom Field:', 'your-text-domain') . '</label></p>';
    echo '<input type="text" id="custom_order_field" name="custom_order_field" value="' . 'test' . '" style="width:100%;">';
  } else {
    echo '<div class="inline-notice">
      <p>' . __('Order hasn\'t been fiscalized with Kopa', 'kopa-payment') . '</p>
    </div>';
  }
}