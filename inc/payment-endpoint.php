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

      // Bank sending details of the transaction 
      // Saving transaction details
      if ($_SERVER['REQUEST_METHOD'] === 'POST' ) {
        update_post_meta($orderId, 'kopaOrderPaymentPOST', true);

        // Retrieve JSON data from the request
        $jsonData = file_get_contents('php://input');
        $data = json_decode($jsonData, true);
        if (json_last_error() === JSON_ERROR_NONE) {
          // Check if the required keys are present
          // $requiredKeys = ['OrderId', 'TransStatus', 'TransDate', 'TansId', 'TansErrorMsg', 'TransErrorCode', 'TransNumCode'];
          // if (count(array_diff($requiredKeys, array_keys($data))) === 0) {
            update_post_meta($orderId, 'kopaOrderPaymentData', $jsonData);
            exit;
          // }
        } 
      }   

      // Bank redirection for order finalizing
      if (
        $_SERVER['REQUEST_METHOD'] === 'GET' &&
        isset($_GET['authResult']) &&
        !empty(isset($_GET['authResult'])) 
      ){
        $authResult = $_GET['authResult'];
        $order = wc_get_order($orderId);
        update_post_meta($orderId, 'kopaOrderPaymentGETAuthResult', $authResult);
        if(!empty($order)) {
          if($authResult == 'AUTHORISED'){
            $kopaClass = new KOPA_Payment();
            $kopaCurl = new KopaCurl();
            $physicalProducts = $kopaClass->isPhysicalProducts($order);
            $userId = $order->get_user_id();
            
            $kopaOrderId = get_post_meta($orderId, 'kopaIdReferenceId', true);
            $kopaTransactionDetails = get_post_meta($orderId, 'kopaOrderPaymentData', true);
            $orderDetailsKopa = $kopaCurl->getOrderDetails($kopaOrderId, $userId);
            
            if(isDebugActive(Debug::AFTER_PAYMENT)){
              echo 'userId<pre>' . print_r($userId, true) . '</pre>';
              echo 'kopaOrderId<pre>' . print_r($kopaOrderId, true) . '</pre>';
              echo 'order details kopa<pre>' . print_r($orderDetailsKopa, true) . '</pre>';
            }
            // Check for payment transaction details on KOPA
            paymentCheckup($order, $orderDetailsKopa, $physicalProducts);
            

            // There might be some delay when checking with KOPA transaction details
            // No transaction details on KOPA but bank sent transaction details prior to redirect URL
            if(
              (
                !isset($orderDetailsKopa['transaction']) || 
                empty($orderDetailsKopa['transaction']) ||
                isset($orderDetailsKopa['transaction']['errorCode']) || 
                !empty($orderDetailsKopa['transaction']['errorCode'])
              ) &&
              !empty($kopaTransactionDetails)
            ){
              // Recheck transaction detail on KOPA if bank already sent details to the website 
              paymentCheckup($order, $orderDetailsKopa, $physicalProducts, true); // Payment checkup with delay
            }

            // Transaction details on KOPA is empty, meaning that transaction was probably canceled
            if(
              !isset($orderDetailsKopa['transaction']) || 
              empty($orderDetailsKopa['transaction']) ||
              isset($orderDetailsKopa['transaction']['errorCode']) || 
              !empty($orderDetailsKopa['transaction']['errorCode'])
            ){
              // Add a notice
              wc_add_notice(__('Your payment was canceled, please try again.', 'kopa-payment'), 'error');
              
              // Redirect to the checkout page
              wp_redirect(wc_get_checkout_url());
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
        exit;
      }
      if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['ErrMsg']) && 
        !empty($_POST['ErrMsg'])
      ){
        // Add a notice
        wc_add_notice(__('There was a problem with a payment - '. $_POST['ErrMsg'], 'kopa-payment'), 'error');

        // Redirect to the checkout page
        wp_redirect(wc_get_checkout_url());
        exit;
      }
      
      // Add a notice
      wc_add_notice(__('There was a problem with a payment. Please contant shop administrator.', 'kopa-payment'), 'error');

      // Redirect to the checkout page
      wp_redirect(wc_get_checkout_url());
      exit;
    }
  }
}
add_action('parse_request', 'handle_custom_endpoint');

function paymentCheckup($order, $orderDetailsKopa, $physicalProducts, $delay = false){
  if($delay == true){
    sleep(5);
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
    if(!isDebugActive(Debug::AFTER_PAYMENT)){
      // Redirect the user to the thank you page
      wp_redirect($order->get_checkout_order_received_url());
    }
    exit;
  }
}