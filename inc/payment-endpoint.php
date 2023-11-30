<?php

// Register custom endpoint
function custom_endpoint() {
  add_rewrite_rule('^kopa-payment-data/accept-order/([^/]+)/?', 'index.php?accept_order_id=$matches[1]', 'top');
  add_filter('query_vars', function ($vars) {
    $vars[] = 'accept_order_id';
    return $vars;
  });
}
add_action('init', 'custom_endpoint');


// Handle POST requests to the custom endpoint
function handle_custom_endpoint($wp) {
  // Check if the 'accept_order_id' query variable is set
  if (array_key_exists('accept_order_id', $wp->query_vars) && !empty($wp->query_vars['accept_order_id'])) {
    $orderId = absint($wp->query_vars['accept_order_id']);
    if ($orderId) {
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
        !empty(isset($_GET['authResult'])) 
      ){
        $authResult = $_GET['authResult'];
        $order = wc_get_order($orderId);

        if(!empty($order)) {
          if($authResult == 'AUTHORISED'){
            $kopaClass = new KOPA_Payment();
            $kopaCurl = new KopaCurl();
            $physicalProducts = $kopaClass->isPhysicalProducts($order);
            $userId = $order->get_user_id();
            
            $kopaOrderId = get_post_meta($orderId, 'kopaIdReferenceId', true);
            $orderDetailsKopa = $kopaCurl->getOrderDetails($kopaOrderId, $userId);
            
            if(isDebugActive(Debug::AFTER_PAYMENT)){
              echo 'userId<pre>' . print_r($userId, true) . '</pre>';
              echo 'kopaOrderId<pre>' . print_r($kopaOrderId, true) . '</pre>';
              echo 'order details kopa<pre>' . print_r($orderDetailsKopa, true) . '</pre>';
            }
            // Check on KOPA system if order was actually paid and has no errors
            if(
              isset($orderDetailsKopa['response']) && $orderDetailsKopa['response'] == 'Approved' &&
              isset($orderDetailsKopa['trantype']) && in_array($orderDetailsKopa['trantype'], ['Auth', 'PreAuth', 'PostAuth']) &&
              isset($orderDetailsKopa['transaction']) && !empty($orderDetailsKopa['transaction']) &&
              isset($orderDetailsKopa['transaction']['errorCode']) && empty($orderDetailsKopa['transaction']['errorCode']) 
            ) {
              if($physicalProducts == true) {
                // Change the order status to 'processing'
                $order->update_meta_data('isPhysicalProducts', 'true');
                $order->update_status('processing');
              }else{
                // Change the order status to 'completed'
                $order->update_meta_data('isPhysicalProducts', 'false');
                $order->update_status('completed');
              }
              $order->save();
              // Redirect the user to the thank you page
              wp_redirect($order->get_checkout_order_received_url());
              exit;
            }
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
      if(isDebugActive(Debug::AFTER_PAYMENT)){
        echo 'REQUEST_METHOD<pre>' . print_r($_SERVER['REQUEST_METHOD'], true) . '</pre>';
        echo 'authResult<pre>' . print_r($_GET['authResult'], true) . '</pre>';
      }
      wp_send_json_error(['message' => __('You are not allowed', 'kopa-payment')]);
      exit;
    }
  }
}
add_action('parse_request', 'handle_custom_endpoint');