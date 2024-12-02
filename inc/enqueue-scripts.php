<?php

/**
 * Adding scripts and styles
 */
function enqueue_kopa_scripts()
{
  // Enqueue scripts and styles on checkout
  if (is_checkout() && !is_wc_endpoint_url()) {
    wp_enqueue_script('jquery-validate', KOPA_PLUGIN_URL . 'js/inc/jquery.validate.min.js', array('jquery'), '1.0', true);
    wp_enqueue_script('jquery-validate-additional', KOPA_PLUGIN_URL . 'js/inc/additional-methods.min.js', array('jquery', 'jquery-validate'), '1.0', true);
    wp_enqueue_script('crypto-js', KOPA_PLUGIN_URL . 'js/inc/crypto-js.js', array('jquery'), '1.0', true);
    wp_enqueue_script('socket-io', KOPA_PLUGIN_URL . 'js/inc/socket.io.js', array('jquery'), '1.0', true);
    wp_enqueue_script('ajax-checkout', KOPA_PLUGIN_URL . 'js/kopa-scripts.js', array('jquery'), '1.0', true);

    // Pass the necessary variables to the JavaScript file
    wp_localize_script('ajax-checkout', 'ajax_checkout_params', array(
      'ajaxurl' => admin_url('admin-ajax.php'),
      'security' => wp_create_nonce('ajax-checkout-nonce'),
      'loggedIn' => is_user_logged_in(),
      'paymentError' => __('There was a problem with connection with payment', 'kopa-payment'),
      'validationCCDate' => __('Please enter a valid exparation date (MM/YY)', 'kopa-payment'),
      'validationCCNumber' => __('Please enter a valid credit card number', 'kopa-payment'),
      'validationCcvValid' => __('Please enter valid CCV number', 'kopa-payment'),
      'validationDigits' => __('Only digits are allowed', 'kopa-payment'),
      'validationCcAlias' => __('Please enter credit card alias', 'kopa-payment'),
      'paymentErrorMessageFor3D' => __('There has been an error with payment', 'kopa-payment'),
    ));

    wp_enqueue_style('kopa-styles', KOPA_PLUGIN_URL . 'css/kopa-styles.css');
  }
  // Enqueue script on My Account page
  if (is_account_page() && !is_wc_endpoint_url() && is_user_logged_in()) {
    wp_enqueue_script('kopa-my-account', KOPA_PLUGIN_URL . 'js/kopa-my-account-scripts.js', array('jquery'), '1.0', true);
    wp_localize_script('kopa-my-account', 'ajax_my_account_params', array(
      'ajaxurl' => admin_url('admin-ajax.php'),
      'security' => wp_create_nonce('ajax-my-account-nonce'),
      'loggedIn' => is_user_logged_in(),
      'confirmCardDelete' => __('Are you sure you want to remove saved credit card', 'kopa-payment'),
    ));
  }
}
add_action('wp_enqueue_scripts', 'enqueue_kopa_scripts');

function kopaCustomOrderScripts($hook)
{
  // Only enqueue on the WooCommerce Kopa payment settings page
  if ($hook !== 'woocommerce_page_wc-settings' && $hook !== 'post.php' && $hook !== 'woocommerce_page_wc-orders') {
    return;
  }

  // Woocommerce payment settings page script
  if ($hook === 'woocommerce_page_wc-settings') {
    wp_enqueue_script(
      'kopa_custom_admin_order_scrips',
      KOPA_PLUGIN_URL . 'js/kopa-admin-payment-settings-scripts.js',
      ['jquery'],
      '',
      true
    );
  }

  // Woocommerce order preview styles
  if ($hook === 'post.php' || $hook == 'woocommerce_page_wc-orders') {
    wp_enqueue_style('kopa-admin-styles', KOPA_PLUGIN_URL . 'css/kopa-admin-styles.css');

    // Woocommerce order preview script
    wp_enqueue_script(
      'kopa_custom_admin_order_scrips',
      KOPA_PLUGIN_URL . 'js/kopa-admin-order-preview-scripts.js',
      ['jquery'],
      '',
      true
    );
    wp_localize_script('kopa_custom_admin_order_scrips', 'orderKopaParam', array(
      'ajaxurl' => admin_url('admin-ajax.php'),
      'security' => wp_create_nonce('ajax-my-account-nonce'),
      'invalidQuantityForRefund' => __('Invalid refund quantity', 'kopa-payment'),
    ));
  }
}
add_action('admin_enqueue_scripts', 'kopaCustomOrderScripts');
