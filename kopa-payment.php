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

/**
 * Require plugins.php for deactivate_plugins function if wocommerce is not active
 */
require_once( ABSPATH . 'wp-admin/includes/plugin.php' );


/**
 * Check if woocommerce plugin is active, if not, disable plugin and display error notice
 */
add_action('plugins_loaded', 'check_for_woocommerce');
function check_for_woocommerce() {
  if (class_exists('WooCommerce')) {
    init_kopa_payment_gateway();
  } else {
    deactivate_plugins( plugin_basename( __FILE__ ) );
    echo '<div class="error"><p>KOPA payment Plugin Checker requires WooCommerce to be active. Please activate WooCommerce to use this plugin.</p></div>';
  }
}

/**
 * KOPA Class
 */
function init_kopa_payment_gateway() {
  if (!class_exists('WC_Payment_Gateway')) return;

  class WC_KOPA_Payment_Gateway extends WC_Payment_Gateway {
    public function __construct() {
      $this->id = 'kopa_payment';
      $this->method_title = 'KOPA Payment Method';
      $this->has_fields = true;
      $this->init_form_fields();
      $this->init_settings();
      $this->title = $this->get_option('title');

      add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields() {
      $this->form_fields = 
      [
        'enabled' => [
          'title' => 'Enable/Disable',
          'type' => 'checkbox',
          'label' => 'Enable KOPA Payment Method',
          'default' => 'no',
        ],
        'title' => [
          'title' => 'Title',
          'type' => 'text',
          'description' => 'This is the title that the user sees during checkout.',
          'default' => 'KOPA Payment',
          'desc_tip' => true,
        ],
        'kopa_username' => [
          'title' => 'Merchant username',
          'type' => 'text',
          'description' => 'Merchant username',
          'default' => '',
          'desc_tip' => true,
        ],
        'kopa_password' => [
          'title' => 'Merchant password',
          'type' => 'password',
          'description' => 'Merchant password',
          'default' => '',
          'desc_tip' => true,
        ],
          // Add more custom settings fields as needed
      ];
    }

    public function payment_fields() {
      ob_start(); 
      // Display your custom credit card input fields here
      ?>
      <div class="kopa-credit-card-fields">
      <label for="kopa_cc_number">Credit card number</label>
      <input type="text" id="kopa_cc_number" name="kopa_credit_card_number" placeholder="Card Number">
      <label for="kopa_cc_expiration">Expiration Date</label>
      <input type="text" id="kopa_cc_expiration" name="kopa_credit_card_expiration" placeholder="Expiration Date">
      <label for="kopa_cc_ccv">CCV</label>
      <input type="text" id="kopa_cc_ccv" name="kopa_credit_card_ccv" placeholder="CCV">
      </div>
      <?php
      echo ob_get_clean();
    }
    public function process_payment($order_id) {
      // Implement the payment processing logic here, including handling the credit card data.
      // Retrieve credit card data from $_POST and validate it.
      // Use a payment gateway or API to process the payment.

      // If the payment is successful, mark the order as paid and return success.
      $order = wc_get_order($order_id);
      $order->payment_complete();

      // Redirect to the thank you page
      return [
        'result' => 'success',
        'redirect' => $this->get_return_url($order),
      ];
    }
  }

  add_filter('woocommerce_payment_gateways', 'add_kopa_payment_gateway');

  function add_kopa_payment_gateway($gateways) {
    $gateways[] = 'WC_KOPA_Payment_Gateway';
    return $gateways;
  }
}