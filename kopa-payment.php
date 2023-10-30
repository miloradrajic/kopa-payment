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
    echo '<div class="error"><p>KOPA payment Plugin Checker requires WooCommerce to be active. Please activate WooCommerce to use this plugin.</p></div>';
    return;
  }
  require_once KOPA_PLUGIN_PATH . '/inc/kopa-class.php';
  require_once KOPA_PLUGIN_PATH . '/inc/curl.php';
  require_once KOPA_PLUGIN_PATH . '/inc/ajax-functions.php';
  require_once KOPA_PLUGIN_PATH . '/inc/order-status-change.php';
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
    wp_enqueue_script('jquery-validate', plugin_dir_url(__FILE__) .'js/inc/jquery.validate.min.js', array('jquery'), '1.0', true);
    wp_enqueue_script('jquery-validate-additional', plugin_dir_url(__FILE__) .'js/inc/additional-validate-methods.min.js', array('jquery'), '1.0', true);
    wp_enqueue_script('crypto-js', plugin_dir_url(__FILE__) .'js/inc/crypto-js.js', array('jquery'), '1.0', true);
    wp_enqueue_script('socket-io', plugin_dir_url(__FILE__) .'js/inc/socket.io.js', array('jquery'), '1.0', true);
    wp_enqueue_script('ajax-checkout', plugin_dir_url(__FILE__) .'js/kopa-scripts.js', array('jquery'), '1.0', true);

    // Pass the necessary variables to the JavaScript file
    wp_localize_script('ajax-checkout', 'ajax_checkout_params', array(
      'ajaxurl'   => admin_url('admin-ajax.php'),
      'security'  => wp_create_nonce('ajax-checkout-nonce'),
      'loggedIn'  => is_user_logged_in(),
      'paymentError' => __('There was a problem with connection with payment', 'kopa-payment'),
      'validationCCDate' => __('Please enter a valid expiration date (MM/YY)', 'kopa-payment'),
      'validationCCNumber' => __('Please enter a valid credit card number', 'kopa-payment'),
      'validationCcvValid' => __('Please enter valid CCV number', 'kopa-payment'),
      'validationDigits' => __('Only digits are allowed', 'kopa-payment'),
      'validationCcAlias' => __('Please enter credit card alias', 'kopa-payment')
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

function validateCreditCard($creditCardNumber) {
  if (empty($creditCardNumber)) {
    return false;
  }
  if (!preg_match('/^[0-9 \-]+$/', $creditCardNumber)) {
    return false;
  }
  $creditCardNumber = preg_replace('/\D/', '', $creditCardNumber);
  if (strlen($creditCardNumber) < 13 || strlen($creditCardNumber) > 19) {
    return false;
  }
  $e = 0;
  $f = 0;
  $g = false;
  for ($c = strlen($creditCardNumber) - 1; $c >= 0; $c--) {
    $d = $creditCardNumber[$c];
    $f = intval($d, 10);
    if ($g) {
      $f *= 2;
      if ($f > 9) {
        $f -= 9;
      }
    }
    $e += $f;
    $g = !$g;
  }

  if ($e % 10 === 0) {
    return true;
  } else {
    return false;
  }
}

function translatablePlugin() {
  load_plugin_textdomain('kopa-payment', false, dirname(plugin_basename(__FILE__)) . '/languages/');
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
  $items['kopa-manage-cc'] = __('Manage Credit Cards', 'kopa-payment');
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
    echo __('There are no saved credit cards.', 'kopa-payment');
  }
}

add_action( 'woocommerce_account_kopa-manage-cc_endpoint', 'bbloomer_premium_support_content' );

/*
  Adding custom page under Woocommerce menu item
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

// Display log entries 
function displayKopaLogs() {
  ob_start(); ?>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" media="all">
  <div class="wrap">
    <h2><?php echo __('Kopa Logs Preview', 'kopa-payment') ?></h2>
    <p><?php echo __('Log preview content', 'kopa-payment') ?></p>
    
    <table id="tblReportResultsDemographics" class="display" width="100%"></table>
  </div>
  <?php $logEntries = get_option('kopa_log_messages', array()); ?>
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
          { "data": "message", "title": 'Message' },
          { "data": "userId", "title": "User ID (WP)" },
          { "data": "kopaUserId", "title": "User ID (KOPA)" },
          { "data": "orderId", "title": "Order ID" }
        ],
        "draw": 1, // A request identifier (used for paging)
        "order": [[0, 'desc']],
      });
    });
  </script>
  <?php
  echo ob_get_clean();
}

/**
 * Custom logging function
 */
function kopaMessageLog($function, $orderId = '', $userId = '', $kopaUserId = '', $response = '', $message = '') {
  // Load the existing log entries from the database
  $log_entries = get_option('kopa_log_messages', []);
  if(empty($log_entries) || !is_array($log_entries)) $log_entries = [];
  // Add the new log entry
  $log_entries[] = array(
    'timestamp' => current_time('timestamp'),
    'function'  => $function,
    'response'  => $response,
    'userId'    => $userId,
    'kopaUserId'=> $kopaUserId,
    'orderId'   => $orderId,
    'message'   => $message
  );

  // Keep only the last 50 entries
  $log_entries = array_slice($log_entries, -50);

  // Update the theme option with the updated log entries
  update_option('kopa_log_messages', $log_entries);
}



// Custom function to modify radio buttons using woocommerce_form_field
function custom_radio_button_field($field, $key, $args, $value) {
  $output = '<p class="form-row kopaPaymentTitle">';
  
  // Label for the field
  if (isset($args['label']) && $args['label']) {
    $output .= '<label for="' . esc_attr($key) . '">' . esc_html($args['label']) . '</label>';
  }
  
  // Wrap options in a div
  $output .= '<div class="kopaCheckoutRadioButtons">';
  
  foreach ($args['options'] as $option_key => $option_label) {
    $output .= '<div class="kopaCheckoutRadioButton">';
    $output .= '<input type="radio" name="' . esc_attr($key) . '" id="' . esc_attr($key . '_' . $option_key) . '" value="' . esc_attr($option_key) . '"';
    
    if ($value === $option_key) {
      $output .= ' checked="checked"';
    }
    $output .= ' />';
    $output .= '<label for="' . esc_attr($key . '_' . $option_key) . '">' . esc_html($option_label) . '</label>';
    $output .= '</div>';
  }
  
  $output .= '</div>'; // kopaCheckoutRadioButtons div
  $output .= '</p>';
  return $output;
}

// Hook into the woocommerce_form_field filter
add_filter('woocommerce_form_field_radio', 'custom_radio_button_field', 10, 4);


// Add a custom column
add_filter('manage_edit-shop_order_columns', 'add_custom_column');

function add_custom_column($columns) {
  $columns['kopaIdReferenceId'] = 'KOPA ID';
  return $columns;
}

// Display the custom input value in the column
add_action('manage_shop_order_posts_custom_column', 'display_custom_column_value', 10, 2);

function display_custom_column_value($column, $post_id) {
  if ($column === 'kopaIdReferenceId') {
    $kopaIdReferenceId = get_post_meta($post_id, 'kopaIdReferenceId', true);
    echo $kopaIdReferenceId;
  }
}

// Make the custom column sortable
add_filter('manage_edit-shop_order_sortable_columns', 'make_custom_column_sortable');

function make_custom_column_sortable($sortable_columns) {
  $sortable_columns['kopaIdReferenceId'] = 'kopaIdReferenceId';
  return $sortable_columns;
}

add_action('pre_get_posts', 'custom_column_sorting');

function custom_column_sorting($query) {
  if (!is_admin() || !$query->is_main_query()) {
    return;
  }

  if ($query->get('orderby') === 'kopaIdReferenceId') {
    $query->set('meta_key', 'kopaIdReferenceId');
    $query->set('orderby', 'meta_value');
  }
}

function addKopaOrderIdOnThankYouPage($orderId) {
  $custom_meta_field = get_post_meta($orderId, 'kopaIdReferenceId', true);
  if(!empty($custom_meta_field)){ ?>
    <tr>
      <th><?php echo __('KOPA Reference ID:', 'kopa-payment'); ?></th>
      <td><?php echo esc_html($custom_meta_field); ?></td>
    </tr>
    <?php }
}
add_action('woocommerce_thankyou', 'addKopaOrderIdOnThankYouPage');


function addKopaOrderIdToMyOrdersPage($order) {
  $custom_meta_field = $order->get_meta('kopaIdReferenceId');
  if(!empty($custom_meta_field)){ ?>
    <tr>
      <th><?php echo __('KOPA Reference ID:', 'kopa-payment'); ?></th>
      <td><?php echo esc_html($custom_meta_field); ?></td>
    </tr>
    <?php }
}
add_action('woocommerce_order_details_after_order_table_items', 'addKopaOrderIdToMyOrdersPage');




function addKopaOrderIdOnEmailTemplate($order, $sent_to_admin, $plain_text, $email) {
  if ($email->id === 'new_order') {
    $custom_meta_field = $order->get_meta('kopaIdReferenceId');
    if ($custom_meta_field) {
      echo '<p>
              <strong>' . __('KOPA Reference ID:', 'kopa-payment') . ' </strong>
              <span>' . esc_html($custom_meta_field) . '</span>
            </p>';
    }
  }
}
add_action('woocommerce_email_after_order_table', 'addKopaOrderIdOnEmailTemplate', 10, 4);