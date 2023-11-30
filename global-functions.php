<?php
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

// Function to validate CC number
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

/**
 * Custom function to modify radio buttons layout on checkout using woocommerce_form_field
 */
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
add_filter('woocommerce_form_field_radio', 'custom_radio_button_field', 10, 4);

// On logout clear session
function clearSessionOnLogout() {
  unset($_SESSION['kopaUserId']);
  unset($_SESSION['kopaAccessToken']);
  unset($_SESSION['kopaRefreshToken']);
  unset($_SESSION['kopaOrderId']);
}
add_action( 'wp_logout', 'clearSessionOnLogout' );

// Add custom fields to user profile to preview admin kopa registration code
function custom_user_profile_fields($contactmethods) {
  if (isDebugActive(Debug::BEFORE_PAYMENT)) {
    $contactmethods['kopa_user_registered_code'] = 'Kopa Registration code';
  }
  return $contactmethods;
}
add_filter('user_contactmethods', 'custom_user_profile_fields');


enum Debug: string {
  case BEFORE_PAYMENT = 'before_payment';
  case AFTER_PAYMENT = 'after_payment';
  case NO = 'no';
}
// Check if debug is active and current user ia admin
function isDebugActive(Debug $debug){
  if(
    current_user_can('administrator') && 
    in_array($debug, get_option('woocommerce_kopa-payment_settings')['kopa_debug'])
  ) return true;
  return false;
}