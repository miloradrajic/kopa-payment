<?php
/**
 * Detecting CC type. Sent type can be 'dynamic' or 'dina'
 */
function detectCreditCardType($cardNumber, $sentType = 'dynamic')
{
  // Define regular expressions for different card types
  $patterns = array(
    'visa' => '/^4\d{12}(\d{3})?$/',
    'master' => '/^5[1-5]\d{14}$/',
    'amex' => '/^3[47]\d{13}$/',
    'discover' => '/^6(?:011|5\d{2})\d{12}$/',
    'diners' => '/^3(?:0[0-5]|[68]\d)\d{11}$/',
    'jbc' => '/^(?:2131|1800|35\d{3})\d{11}$/',
  );

  // Check the card number against each pattern
  $foundTypeMatch = '';
  foreach ($patterns as $type => $pattern) {
    if (preg_match($pattern, $cardNumber)) {
      $foundTypeMatch = $type;
    }
  }

  // If dynamic CC number check, but no match was found, returning error
  // if( $sentType == 'dynamic' && empty($foundTypeMatch) ) {
  //   return false;
  // }

  // If there were no matches and sent type is 'dina', returning 'dina'
  // if(
  //   $sentType == 'dina' &&
  //   empty($foundTypeMatch)
  // ){
  //   return 'dina';
  // }

  // if(
  //   $sentType == 'dina' &&
  //   !empty($foundTypeMatch) &&
  //   $foundTypeMatch !== 'dina'
  // ){
  //   return false;
  // }

  // Requested fallback wihtout checking for dina card
  if (empty($foundTypeMatch)) {
    return 'dina';
  }

  return $foundTypeMatch;
}

// Function to validate CC number
function validateCreditCard($creditCardNumber)
{
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

/**
 * Custom function to modify radio buttons layout on checkout using woocommerce_form_field
 */
function custom_radio_button_field($field, $key, $args, $value)
{
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
add_filter('woocommerce_form_field_radio', 'custom_radio_button_field', 10, 4);

// On logout clear session
function clearSessionOnLogout()
{
  unset($_SESSION['kopaUserId']);
  unset($_SESSION['kopaAccessToken']);
  unset($_SESSION['kopaRefreshToken']);
  unset($_SESSION['kopaOrderId']);
}
add_action('wp_logout', 'clearSessionOnLogout');

// Add custom fields to user profile to preview admin kopa registration code
function custom_user_profile_fields($contactmethods)
{
  if (isDebugActive(Debug::BEFORE_PAYMENT)) {
    $contactmethods['kopa_user_registered_code'] = 'Kopa Registration code';
  }
  return $contactmethods;
}
add_filter('user_contactmethods', 'custom_user_profile_fields');


class Debug
{
  const BEFORE_PAYMENT = 'before_payment';
  const AFTER_PAYMENT = 'after_payment';
  const SAVE_CC = 'save_cc';
  const NO = 'no';
}

// Check if debug is active and current user ia admin
function isDebugActive(string $debug)
{
  if (
    current_user_can('administrator') &&
    !empty($debug) &&
    isset(get_option('woocommerce_kopa-payment_settings')['kopa_debug']) &&
    is_array(get_option('woocommerce_kopa-payment_settings')['kopa_debug']) &&
    in_array($debug, get_option('woocommerce_kopa-payment_settings')['kopa_debug'])
  )
    return true;
  return false;
}

/**
 * UNIT PRICE DISPLAY
 */

// Show product unit price on the Thank You Page, Emails, and order view in My Account.
// function ecommercehints_return_unit_price( $product ) {
//   $unit_price = wc_price($product->get_price());
//   if (!empty($unit_price )) {
//     return $unit_price;
//   } else {
//     return '';
//   }
// }

add_filter('woocommerce_cart_item_name', 'checkoutSingleItemAddUnitPrice', 10, 3);
function checkoutSingleItemAddUnitPrice($itemName, $item, $itemKey)
{
  if (is_cart()) {
    echo $itemName;
  } else {
    $unit_price = wc_price(get_post_meta($item['product_id'], '_price', true));
    echo $itemName . ' - ' . $unit_price;
  }
}

// Adding unit price on thank you page
add_filter('woocommerce_order_item_name', 'displayUnitPriceOnOrderRecievedPage', 10, 3);
function displayUnitPriceOnOrderRecievedPage($itemName, $item, $itemKey)
{
  $unit_price = wc_price(get_post_meta($item['product_id'], '_price', true));
  return $itemName . ' - ' . $unit_price;
}


// Show unit price on Email
// add_filter( 'woocommerce_order_formatted_line_subtotal', 'orderShowSubtotal', 10, 3 );
// function orderShowSubtotal( $subtotal, $item, $order ){
//   if(empty( is_wc_endpoint_url('order-received') )){
//     $product = $item->get_product();
//     return ecommercehints_return_unit_price( $product );
//   }else{
//     return $subtotal;
//   }
// }

/**
 * UNIT PRICE DISPLAY END
 */


add_action('init', 'load_kopa_textdomain');

/*
 * Function that will load translations from the language files in the /languages folder in the root folder of the plugin.
 */
function load_kopa_textdomain()
{
  load_plugin_textdomain('kopa-payment', false, basename(dirname(__FILE__)) . '/languages');

  // Find proper locale
  $locale = get_locale();
  if (is_user_logged_in()) {
    if ($user_locale = get_user_locale(get_current_user_id())) {
      $locale = $user_locale;
    }
  }

  // Get locale
  $locale = apply_filters('woo_kopa_payment_plugin_locale', $locale, 'kopa-payment');

  // We need standard file
  $mofile = sprintf('%s-%s.mo', 'kopa-payment', $locale);

  // Check first inside `/wp-content/languages/plugins`
  $domain_path = path_join(WP_LANG_DIR, 'plugins');
  $loaded = load_textdomain('kopa-payment', path_join($domain_path, $mofile));
  // Or inside `/wp-content/languages`
  if (!$loaded) {
    $loaded = load_textdomain('kopa-payment', path_join(WP_LANG_DIR, $mofile));
  }

  // Or inside `/wp-content/plugin/kopa-payment/languages`
  if (!$loaded) {
    $domain_path = KOPA_PLUGIN_PATH . '/languages';
    $loaded = load_textdomain('kopa-payment', path_join($domain_path, $mofile));

    // Or load with only locale without prefix
    if (!$loaded) {
      $loaded = load_textdomain('kopa-payment', path_join($domain_path, "{$locale}.mo"));
    }

    // Or old fashion way
    if (!$loaded && function_exists('load_plugin_textdomain')) {
      load_plugin_textdomain('kopa-payment', false, $domain_path);
    }
  }
}
