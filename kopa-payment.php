<?php 
/*
Plugin Name: KOPA Payment Method
Description: Add a KOPA payment method to WooCommerce.
Version: 1.0
Author: MR
*/

if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Start session
if(session_id() == '' || !isset($_SESSION) || session_status() === PHP_SESSION_NONE) {
  // session isn't started
  session_start();
}

define('KOPA_PLUGIN_PATH', plugin_dir_path(__FILE__));

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
    echo '<div class="error"><p>KOPA payment Plugin Checker requires WooCommerce to be active. Please activate WooCommerce to use this plugin.</p></div>';
    return;
  }
  include_once KOPA_PLUGIN_PATH . '/inc/kopa-class.php';
  include_once KOPA_PLUGIN_PATH . '/inc/curl.php';
  include_once KOPA_PLUGIN_PATH . '/inc/ajax-functions.php';
}

// Add a custom payment gateway
add_filter('woocommerce_payment_gateways', 'addKopaPaymentGateway');
function addKopaPaymentGateway($gateways) {
  $gateways[] = 'WC_KOPA_Payment_Gateway';
  return $gateways;
}

/**
 * Adding scripts on checkout page 
 */
function enqueue_kopa_scripts() {
  if (is_checkout() && !is_wc_endpoint_url()) {
    // enqueue scripts and styles on checkout
    wp_enqueue_script('jquery-validate', plugin_dir_url(__FILE__) .'js/jquery.validate.min.js', array('jquery'), '1.0', true);
    wp_enqueue_script('jquery-validate-additional', plugin_dir_url(__FILE__) .'js/additional-validate-methods.min.js', array('jquery'), '1.0', true);
    wp_enqueue_script('crypto-js', plugin_dir_url(__FILE__) .'js/crypto-js.js', array('jquery'), '1.0', true);
    wp_enqueue_script('socket-io', plugin_dir_url(__FILE__) .'js/socket.io.js', array('jquery'), '1.0', true);
    wp_enqueue_script('ajax-checkout', plugin_dir_url(__FILE__) .'js/kopa-scripts.js', array('jquery'), '1.0', true);

    // Pass the necessary variables to the JavaScript file
    wp_localize_script('ajax-checkout', 'ajax_checkout_params', array(
      'ajaxurl'   => admin_url('admin-ajax.php'),
      'security'  => wp_create_nonce('ajax-checkout-nonce'),
      'loggedIn'  => is_user_logged_in(),
    ));

    wp_enqueue_style( 'kopa-styles', plugin_dir_url(__FILE__) .'/css/kopa-styles.css' );

  }
  if (is_account_page() && !is_wc_endpoint_url() && is_user_logged_in()) {
    wp_enqueue_script('kopa-my-account', plugin_dir_url(__FILE__) .'/js/kopa-my-account-scripts.js', array('jquery'), '1.0', true);
    wp_localize_script('kopa-my-account', 'ajax_my_account_params', array(
      'ajaxurl'   => admin_url('admin-ajax.php'),
      'security'  => wp_create_nonce('ajax-my-account-nonce'),
      'loggedIn'  => is_user_logged_in(),
    ));
  }
}
add_action('wp_enqueue_scripts', 'enqueue_kopa_scripts');


/**
 * Detecting CC type
 */
function detectCreditCardType($cardNumber, $sentType = '') {
  // Define regular expressions for different card types
  $patterns = array(
    'visa'       => '/^4\d{12}(\d{3})?$/',
    'master'     => '/^5[1-5]\d{14}$/',
    'amex'       => '/^3[47]\d{13}$/',
    'discover'   => '/^6(?:011|5\d{2})\d{12}$/',
    'diners'     => '/^3(?:0[0-5]|[68]\d)\d{11}$/',
    'jbc'        => '/^(?:2131|1800|35\d{3})\d{11}$/',
  );

  // Check the card number against each pattern
  foreach ($patterns as $type => $pattern) {
    if (preg_match($pattern, $cardNumber)) {
      return $type;
    }
  }
  if($sentType == 'dina'){
    return 'dina';
  }

  // If no match is found, return error
  return false;
}

function translatablePlugin() {
  load_plugin_textdomain('kopa_payment', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}
add_action('plugins_loaded', 'translatablePlugin');


/**
 * Registering new page on My account for managing CC
 */

function bbloomer_add_premium_support_endpoint() {
  add_rewrite_endpoint( 'kopa-manage-cc', EP_ROOT | EP_PAGES );
}

add_action( 'init', 'bbloomer_add_premium_support_endpoint' );

// ------------------
// 2. Add new query var

function bbloomer_premium_support_query_vars( $vars ) {
  $vars[] = 'kopa-manage-cc';
  return $vars;
}

add_filter( 'query_vars', 'bbloomer_premium_support_query_vars', 0 );

// ------------------
// 3. Insert the new endpoint into the My Account menu

function bbloomer_add_premium_support_link_my_account( $items ) {
  $items['kopa-manage-cc'] = __('Manage Credit Cards', 'kopa_payment');
  return $items;
}

add_filter( 'woocommerce_account_menu_items', 'bbloomer_add_premium_support_link_my_account' );

// ------------------
// 4. Add content to the new tab

function bbloomer_premium_support_content() {
  $kopaCurl = new KopaCurl();
  $savedCc = $kopaCurl->getSavedCC();

  echo '<h3>Premium WooCommerce Support</h3>';
  if (!empty($savedCc)) {
    ob_start(); ?>

    <table border="1">
      <thead>
        <tr>
          <th>Alias</th>
          <th>Type</th>
          <th>Last Four Digits</th>
          <th>Created At</th>
          <th>Delete</th>
        </tr>
      </thead>
      <tbody>
      <?php
      foreach ($savedCc as $row) { 
        $timestamp = strtotime($row['createdAt']);
        $formattedDate = date("d.m.Y", $timestamp);
        ?>
        <tr>
          <td><?php echo $row['alias']; ?></td>
          <td><?php echo $row['type']; ?></td>
          <td><?php echo $row['lastFourDigits']; ?></td>
          <td><?php echo $formattedDate; ?></td>
          <td><button class="kopaDeleteCC" data-cc-id="<?php echo htmlspecialchars($row['id']); ?>">Delete</button></td>
        </tr>          
          <?php
      }
      ?>
      </tbody>
    </table>
    <?php
  } else {
    echo __('There are no saved credit cards.', 'kopa_payment');
  }
}

add_action( 'woocommerce_account_kopa-manage-cc_endpoint', 'bbloomer_premium_support_content' );


// When order is completed change status to PostAuth on KOPA system
function kopaPostAuthOnOrderCompleted( $order_id ) {
  $order = wc_get_order($order_id);
  $user_id = $order->get_user_id();
  $custom_metadata = get_post_meta($order_id, '_kopa_payment_method', true);
  
	// Check if the custom metadata exists and if playment was done with MOTO or API payment
  if (!empty($custom_metadata) && in_array($custom_metadata, ['moto', 'api'])) {
    $kopaCurl = new KopaCurl();
    $postAuthResult = $kopaCurl->postAuth($order_id, $user_id);
    if($postAuthResult['success'] == true){
      $notice = sprintf(
        __('Order #%d has been completed, and postAuth has been completed', 'kopa-payment'),
        $order_id
      );

      // Add the admin notice
      add_action('admin_notices', function () use ($notice) {
        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', $notice);
      });
    }else{
      // Get the previous order status
      $previous_status = $order->get_status_before('completed');
      if (!empty($previous_status)) {
        // Set the order status back to the previous status
        $order->set_status($previous_status);
      }

      $notice = sprintf(
        __('Order #%d could not be completed because PostAuth has failed', 'kopa-payment'),
        $order_id
      );

      // Add the admin notice
      add_action('admin_notices', function () use ($notice) {
        printf('<div class="error is-dismissible"><p>%s</p></div>', $notice);
      });
    }
  }
	return;
}

add_action( 'woocommerce_order_status_completed', 'kopaPostAuthOnOrderCompleted', 1 );


// Ading custom KOPA refund option in dropdown on order preview
function addKopaRefundOnOrderActions($actions) {
  global $post; // Get the post object

  // Check if the post type is 'shop_order' (WooCommerce order)
  if ($post->post_type == 'shop_order') {
    $order = wc_get_order($post->ID); // Get the order object

    // Get the payment method from custom meta
    $custom_meta_field = $order->get_meta('_kopa_payment_method');

    // Check if order payment was done with MOTO or APY method
    if (!empty($custom_meta_field) && in_array($custom_meta_field, ['moto', 'api'])) {
      $actions['kopa_refund'] = 'KOPA Refund';
    }
  }

  return $actions;
}
add_filter('woocommerce_order_actions', 'addKopaRefundOnOrderActions');


// Calling refund function on KOPA refund and adding order note with result
function kopaRefundActionCallback($order) {
  $user_id = $order->get_user_id();
  $order_id = $order->get_id();
  
  // Refund function
  $kopaCurl = new KopaCurl();
  $refundResult = $kopaCurl->refundProcess($order_id, $user_id);
  
  if(!empty($refundResult) && isset($refundResult['success']) && $refundResult['success'] == true){
    // Set the order status back to the previous status
    $order->set_status('refunded');
  }

  // Recheck refund proccess and add note 
  $refundResult = $kopaCurl->refundCheck($order_id, $user_id);
  $note = $refundResult['message'];
  $order->add_order_note($note);
  
  // Save changes
  $order->save();
}
add_action('woocommerce_order_action_kopa_refund', 'kopaRefundActionCallback',);



/*
  Adding custom page under settings
*/
function add_custom_admin_menu_item() {
  add_submenu_page(
      'woocommerce',  // Slug of the parent menu (WooCommerce).
      'Kopa logs',  // Page title.
      'Kopa logs',  // Menu title.
      'manage_options',  // Capability required to access.
      'kopa_logs',  // Menu slug.
      'displayKopaLogs'  // Callback function to display the custom page.
  );
}
add_action('admin_menu', 'add_custom_admin_menu_item');

// Step 2: Define the callback function to display your custom page.
function displayKopaLogs() {
  ob_start(); ?>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" media="all">
  <div class="wrap">
    <h2>Kopa Logs Preview</h2>
    <p>This is your custom page content.</p>
    
    <table id="tblReportResultsDemographics" class="display" width="100%"></table>
  </div>
  <?php 
    $logEntries = get_option('kopa_log_messages', array()); 
    // echo 'logs<pre>' . print_r($logEntries, true) . '</pre>';
    // if(!empty($logEntries)){
    //   foreach
    // }
  ?>
  <script>
    let $ = jQuery.noConflict();

    $(document).ready(function() {
        
      //Load  datatable
      var oTblReport = $("#tblReportResultsDemographics")

      oTblReport.DataTable ({
        "data" : <?php echo json_encode($logEntries); ?>,
        "columns": [
          { "data": "timestamp", "title": "Timestramp", render: function(data, type, row) {
                    // Convert timestamp to a readable date format (e.g., YYYY-MM-DD)
                    if (type === 'display') {
                      const date = new Date(data * 1000);
                      const formattedDateTime = 
                        `${date.getHours().toString().padStart(2, '0')}:${date.getMinutes().toString().padStart(2, '0')}:${date.getSeconds().toString().padStart(2, '0')} 
                          ${date.getDate().toString().padStart(2, '0')}/${(date.getMonth() + 1).toString().padStart(2, '0')}/${date.getFullYear()}`;
                      return formattedDateTime;
                    }
                    return data;
                },},
          { "data": "function", "title": 'Function name' },
          { "data": "response", "title": 'Response data' },
          { "data": "userId", "title": "User ID (WP)" },
          { "data": "kopaUserId", "title": "User ID (KOPA)" },
          { "data": "orderId", "title": "Order ID" }
        ],
        "draw": 1, // A request identifier (used for paging)

      });
    });
  </script>
  <?php
  echo ob_get_clean();
}


function kopaMessageLog($function, $orderId = '', $userId = '', $kopaUserId = '', $response = '') {
  // Load the existing log entries from the database
  $log_entries = get_option('kopa_log_messages', array());

  // Add the new log entry
  $log_entries[] = array(
    'timestamp' => current_time('timestamp'),
    'function'  => $function,
    'response'  => $response,
    'userId'    => $userId,
    'kopaUserId'=> $kopaUserId,
    'orderId'   => $orderId,
  );

  // Keep only the last 50 entries
  $log_entries = array_slice($log_entries, -50);

  // Update the theme option with the updated log entries
  update_option('kopa_log_messages', $log_entries);
}