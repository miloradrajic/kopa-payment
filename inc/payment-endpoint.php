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
      $order = wc_get_order($orderId);
      
      // Retrieve JSON data from the request
      $jsonData = file_get_contents('php://input');
      $data = json_decode($jsonData, true);
      
      // Bank sending details of the transaction 
      // Saving transaction details
      if ($_SERVER['REQUEST_METHOD'] === 'POST' ) {
        
        $kopaOrderId = get_post_meta($orderId, 'kopaIdReferenceId', true);
        // Check if OrderId and kopa reference id match
        if($data['OrderId'] == $kopaOrderId) {
          // Update transaction meta data
          // update_post_meta($orderId, 'kopaOrderPaymentData', $data);
          $order->update_meta_data( 'kopaOrderPaymentData', $data );
          echo 'OK';
          exit;
        }else{
          
          // update_post_meta($orderId, 'kopaOrderPaymentData', json_encode([
          $order->update_meta_data( 'kopaOrderPaymentData', json_encode([
            'sentData' => $data, 
            'message' => 'kopaId Not the same', 
            'kopaId' => $kopaOrderId,
            'orderId' => $orderId
          ]) );
        }
        echo 'ERROR';
        exit;
      }else{
        // update_post_meta($orderId, 'kopaOrderPaymentData', json_encode([
        $order->update_meta_data( 'kopaOrderPaymentData', json_encode([
          'sentData' => $data, 
          'message' => 'Error recieving data',
          'orderId' => $orderId
        ]) );
      }

      // Bank redirection for order finalizing
      if (
        $_SERVER['REQUEST_METHOD'] === 'GET' &&
        isset($_GET['authResult']) &&
        !empty(isset($_GET['authResult'])) &&
        !isDebugActive(Debug::AFTER_PAYMENT)
      ){
        $authResult = $_GET['authResult'];
        $order = wc_get_order($orderId);
        $kopaClass = new KOPA_Payment();
        $kopaCurl = new KopaCurl();
        $kopaOrderId = get_post_meta($orderId, 'kopaIdReferenceId', true);
        if(!empty($order)) {
          if($authResult == 'AUTHORISED'){
            $physicalProducts = $kopaClass->isPhysicalProducts($order);
            $userId = $order->get_user_id();
            
            $kopaTransactionDetails = get_post_meta($orderId, 'kopaOrderPaymentData', true);
            $orderDetailsKopa = $kopaCurl->getOrderDetails($kopaOrderId, $userId);
            
           
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
              wc_add_notice(__('Your payment was canceled, please try again.', 'kopa-payment') . '<br>' . __('Payment unsuccessful - your payment card account is not debited.', 'kopa-payment'), 'error');
              
              // Redirect to the checkout page
              wp_redirect(wc_get_checkout_url());
              exit;
            }
          }
          if($authResult == 'CANCELLED'){
            $orderDetailsKopa = $kopaCurl->getOrderDetails($kopaOrderId, $userId);

            // Add a notice
            wc_add_notice(__('Your payment was canceled, please try again.', 'kopa-payment') . '<br>' .__('Payment unsuccessful - your payment card account is not debited.', 'kopa-payment') . '<br>' . $orderDetailsKopa['errMsg'], 'error');
            $order->update_status('pending');
            // $order->add_order_note(
            //   __('Order has failed CC transaction', 'kopa-payment'),
            //   true
            // );
            $order->save();
            // Redirect to the checkout page
            wp_redirect(wc_get_checkout_url().$orderId.'/?pay_for_order=true&key='.get_post_meta($orderId,'_order_key', true));
            exit;
          }
          if($authResult == 'REFUSED'){
            $orderDetailsKopa = $kopaCurl->getOrderDetails($kopaOrderId, $userId);
            // Add a notice
            wc_add_notice(__('Your payment was refused.', 'kopa-payment') . '<br>' . __('Payment unsuccessful - your payment card account is not debited.', 'kopa-payment') . $orderDetailsKopa['errMsg'], 'error');
            $order->update_status('pending');
            $order->add_order_note(
              __('Order has failed CC transaction', 'kopa-payment'),
              true
            );
            $order->save();
            // Redirect to the checkout page
            wp_redirect(wc_get_checkout_url().'/'.$orderId.'/?pay_for_order=true&key='.get_post_meta($orderId,'_order_key', true));
            exit;
          }
        }
      }

      if(isDebugActive(Debug::AFTER_PAYMENT)){
        $kopaCurl = new KopaCurl();
        $kopaOrderId = get_post_meta($orderId, 'kopaIdReferenceId', true);
        $kopaTransactionDetails = get_post_meta($orderId, 'kopaOrderPaymentData', true);
        $orderDetailsKopa = $kopaCurl->getOrderDetails($kopaOrderId, $userId);

        echo 'order<pre>' . print_r($orderId, true) . '</pre>';
        echo 'REQUEST_METHOD<pre>' . print_r($_SERVER['REQUEST_METHOD'], true) . '</pre>';
        echo 'authResult<pre>' . print_r($_GET['authResult'], true) . '</pre>';
        echo 'saved transaction details<pre>' . print_r($kopaTransactionDetails, true) . '</pre>';
        echo 'order details kopa<pre>' . print_r($orderDetailsKopa, true) . '</pre>';
        echo 'error message<pre>' . print_r($orderDetailsKopa['errMsg'], true) . '</pre>';
        exit;
      }
      if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['ErrMsg']) && 
        !empty($_POST['ErrMsg'])
      ){
        // Add a notice
        wc_add_notice(__('There was a problem with a payment - '. $_POST['ErrMsg'], 'kopa-payment') . '<br>' . __('Payment unsuccessful - your payment card account is not debited.', 'kopa-payment'), 'error');

        // Redirect to the checkout page
        wp_redirect(wc_get_checkout_url());
        exit;
      }
      
      // Add a notice
      wc_add_notice(__('There was a problem with a payment. Please contant shop administrator.', 'kopa-payment'). '<br>' . __('Payment unsuccessful - your payment card account is not debited.', 'kopa-payment'), 'error');

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
    // Save details about transaction from KOPA
    $order->update_meta_data('paymentCheckup', $orderDetailsKopa);
    $order->update_meta_data('kopaTranType', '3d_success');
    $order->save();
    
    if(!isDebugActive(Debug::AFTER_PAYMENT)){
      // Redirect the user to the thank you page
      wp_redirect($order->get_checkout_order_received_url());
    }
    exit;
  }
}