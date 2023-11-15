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


// Register custom endpoint
function registerKopaPaymentEndpoint() {
  add_rewrite_rule('^kopa-payment-data/?', 'index.php?kopa_payment_data=1', 'top');
  add_filter('query_vars', function ($vars) {
    $vars[] = 'kopa_payment_data';
    return $vars;
  });
  flush_rewrite_rules(); // Flush rewrite rules to make sure the changes take effect
}
add_action('init', 'registerKopaPaymentEndpoint');

// Handle POST requests to the custom endpoint
function handle_custom_endpoint() {
  $custom_endpoint = get_query_var('kopa_payment_data');
  if ($custom_endpoint) {
    if (
      $_SERVER['REQUEST_METHOD'] === 'POST' &&
      !isset($_GET['authResult'])
      ) {
      // Retrieve JSON data from the request
      $jsonData = file_get_contents('php://input');
      $data = json_decode($jsonData, true);
      if (json_last_error() === JSON_ERROR_NONE) {
        // Check if the required keys are present
        $requiredKeys = ['OrderId', 'TransStatus', 'TransDate', 'TansId', 'TansErrorMsg', 'TransErrorCode', 'TransNumCode'];
        if (count(array_diff($requiredKeys, array_keys($data))) === 0) {
          $orders = wc_get_orders( array( 'kopaIdReferenceId' => $data['OrderId'] ) );
          if(!empty($orders)) {
            $order = $orders[0];
            update_post_meta($order, 'kopaOrderPaymentData', $jsonData);
          }
        } else {
          wp_send_json_error(['message' => __('Data sent is not a valid structure', 'kopa-payment')]);
          exit;
        }
      } 
    }   
    if (
      $_SERVER['REQUEST_METHOD'] === 'GET' &&
      isset($_GET['authResult']) &&
      !empty(isset($_GET['authResult'])) &&
      isset($_GET['orderId']) &&
      !empty(isset($_GET['orderId']))
    ){
      $authResult = $_GET['authResult'];
      $order = wc_get_order($_GET['orderId']);
      if(!empty($order)) {
        if($authResult == 'AUTHORISED'){

          // Change the order status to 'completed'
          $order->update_status('completed');

          // Redirect the user to the thank you page
          wp_redirect($order->get_checkout_order_received_url());
          exit;
        }
        if($authResult == 'CANCELLED'){
          // Add a notice
          wc_add_notice(__('Your payment was canceled, please try again.', 'kopa-payment'), 'error');
          
          // Redirect to the checkout page
          wp_redirect(wc_get_checkout_url());
          exit;
        }
        if($authResult == 'REFUSED'){
          // Add a notice
          wc_add_notice(__('Your payment was refused. Check with your bank and try again', 'kopa-payment'), 'error');
          
          // Redirect to the checkout page
          wp_redirect(wc_get_checkout_url());
          exit;
        }
      }
    }
    wp_send_json_error(['message' => __('You are not allowed', 'kopa-payment')]);
    exit;
  }
}
add_action('template_redirect', 'handle_custom_endpoint');


function handle_custom_query_var( $query, $query_vars ) {
	if ( ! empty( $query_vars['kopaIdReferenceId'] ) ) {
		$query['meta_query'][] = array(
			'key' => 'kopaIdReferenceId',
			'value' => esc_attr( $query_vars['kopaIdReferenceId'] ),
		);
	}

	return $query;
}
add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', 'handle_custom_query_var', 10, 2 );