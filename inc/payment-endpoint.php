<?php

// Register custom endpoint
function custom_kopa_payment_endpoint()
{
  add_rewrite_rule('^kopa-payment-data/accept-order/([^/]+)/?', 'index.php?accept_order_id=$matches[1]', 'top');
  add_filter('query_vars', function ($vars) {
    $vars[] = 'accept_order_id';
    return $vars;
  });
}
add_action('init', 'custom_kopa_payment_endpoint', 999);


// Handle POST requests to the custom endpoint
function handle_kopa_payment_endpoint($wp)
{
  // Check if the 'accept_order_id' query variable is set
  if (array_key_exists('accept_order_id', $wp->query_vars) && !empty($wp->query_vars['accept_order_id'])) {
    $orderId = absint($wp->query_vars['accept_order_id']);
    if ($orderId) {
      $kopaClass = new KOPA_Payment();
      $kopaCurl = new KopaCurl();
      $order = wc_get_order($orderId);
      $userId = $order->get_user_id();
      $physicalProducts = $kopaClass->isPhysicalProducts($order);

      // Retrieve JSON data from the request
      $jsonData = file_get_contents('php://input');
      $data = json_decode($jsonData, true);

      // Bank sending details of the transaction 
      // Saving transaction details
      if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $kopaOrderId = get_post_meta($orderId, 'kopaIdReferenceId', true);
        // Check if OrderId and kopa reference id match
        if ($data['OrderId'] === $kopaOrderId) {
          // Update transaction meta data
          update_post_meta($orderId, 'kopaOrderPaymentData', $data);

          // Check for payment transaction details on KOPA
          $orderDetailsKopa = $kopaCurl->getOrderDetails($kopaOrderId, $userId);

          // Update Status on order and return transaction status BOOLEAN
          $successPayment = paymentSuccessCheckup($order, $orderDetailsKopa, $physicalProducts);

          // successPayment is false, meaning that transaction was unsuccessful
          if ($successPayment === false) {
            echo 'FAILED TRANSACTION';
            exit;
          }

          echo 'OK';
          exit;
        } else {
          update_post_meta($orderId, 'kopaOrderPaymentData', json_encode([
            'sentData' => $data,
            'message' => 'kopaId Not the same',
            'kopaId' => $kopaOrderId,
            'orderId' => $orderId
          ]));
        }
        echo 'ERROR';
        exit;
      }

      // Bank redirection for order finalizing
      if (
        $_SERVER['REQUEST_METHOD'] === 'GET' &&
        isset($_GET['authResult']) &&
        !empty(isset($_GET['authResult'])) &&
        !isDebugActive(Debug::AFTER_PAYMENT)
      ) {
        $authResult = $_GET['authResult'];
        $kopaOrderId = get_post_meta($orderId, 'kopaIdReferenceId', true);
        if (!empty($order)) {
          if ($authResult == 'AUTHORISED') {
            wp_redirect($order->get_checkout_order_received_url());
            exit;
          }
          $orderDetailsKopa = $kopaCurl->getOrderDetails($kopaOrderId, $userId);
          if ($authResult == 'CANCELLED') {
            $notice = __('Payment unsuccessful - your payment card account is not debited.', 'kopa-payment') . ' EC-886 <br>';

            if (
              !isset(get_option('woocommerce_kopa-payment_settings')['kopa_enable_test_mode']) ||
              get_option('woocommerce_kopa-payment_settings')['kopa_enable_test_mode'] == 'no'
            ) {
              $notice .= json_encode($orderDetailsKopa);
            }
            // Add a notice
            wc_add_notice($notice, 'error');

            $order->update_status('pending');
            $order->save();
            // Redirect to the checkout page
            wp_redirect(wc_get_checkout_url() . $orderId . '/?pay_for_order=true&key=' . get_post_meta($orderId, '_order_key', true));
            exit;
          }
          if ($authResult == 'REFUSED') {
            $notice = __('Payment unsuccessful - your payment card account is not debited.', 'kopa-payment') . ' EC-843 <br>';

            if (
              !isset(get_option('woocommerce_kopa-payment_settings')['kopa_enable_test_mode']) ||
              get_option('woocommerce_kopa-payment_settings')['kopa_enable_test_mode'] == 'no'
            ) {
              $notice .= __('Your payment was refused.', 'kopa-payment') . '<br>' . json_encode($orderDetailsKopa);
            }
            // Add a notice
            wc_add_notice($notice, 'error');

            $order->update_status('pending');
            $order->add_order_note(
              __('Order has failed CC transaction', 'kopa-payment'),
              true
            );
            $order->save();
            // Redirect to the checkout page
            wp_redirect(wc_get_checkout_url() . '/' . $orderId . '/?pay_for_order=true&key=' . get_post_meta($orderId, '_order_key', true));
            exit;
          }
        }
      }

      $orderDetailsKopa = $kopaCurl->getOrderDetails($kopaOrderId, $userId);

      if (isDebugActive(Debug::AFTER_PAYMENT)) {
        $kopaCurl = new KopaCurl();
        $kopaOrderId = get_post_meta($orderId, 'kopaIdReferenceId', true);
        $kopaTransactionDetails = get_post_meta($orderId, 'kopaOrderPaymentData', true);

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
      ) {
        $notice = __('Payment unsuccessful - your payment card account is not debited.', 'kopa-payment') . ' EC-951 <br>';

        if (
          !isset(get_option('woocommerce_kopa-payment_settings')['kopa_enable_test_mode']) ||
          get_option('woocommerce_kopa-payment_settings')['kopa_enable_test_mode'] == 'no'
        ) {
          $notice .= __('There was a problem with a payment - ' . $_POST['ErrMsg'], 'kopa-payment') . '<br>' . json_encode($orderDetailsKopa);
        }
        // Add a notice
        wc_add_notice($notice, 'error');

        // Redirect to the checkout page
        wp_redirect(wc_get_checkout_url());
        exit;
      }

      $notice = __('Payment unsuccessful - your payment card account is not debited.', 'kopa-payment') . ' EC-684 <br>';

      if (
        !isset(get_option('woocommerce_kopa-payment_settings')['kopa_enable_test_mode']) ||
        get_option('woocommerce_kopa-payment_settings')['kopa_enable_test_mode'] == 'no'
      ) {
        $notice .= __('There was a problem with a payment. Please contant shop administrator.', 'kopa-payment') . '<br>' . json_encode($orderDetailsKopa);
      }
      // Add a notice
      wc_add_notice($notice, 'error');

      // Redirect to the checkout page
      wp_redirect(wc_get_checkout_url());
      exit;
    }
  }
}
add_action('parse_request', 'handle_kopa_payment_endpoint');



function custom_kopa_payment_rest_endpoint()
{
  register_rest_route('kopa-payment/v1', '/payment-data/accept-order/(?P<id>\d+)', array(
    'methods' => 'POST',
    'callback' => 'handle_kopa_payment_rest_endpoint',
    'permission_callback' => '__return_true',
    'args' => [
      'id' => [
        'required' => true, // ID is required
        'validate_callback' => function ($param, $request, $key) {
          return is_numeric($param); // Make sure ID is a number
        },
        'sanitize_callback' => 'absint', // Sanitize ID as an integer
      ],
    ]
  ));
  register_rest_route('kopa-payment/v1', '/test', array(
    'methods' => 'GET',
    'callback' => 'handle_test_endpoint',
    'permission_callback' => '__return_true',
  ));
  register_rest_route('kopa-payment/v1', '/payment-redirect/(?P<id>\d+)', array(
    'methods' => 'GET',
    'callback' => 'handle_kopa_payment_rest_redirect',
    'permission_callback' => '__return_true',
    'args' => [
      'id' => [
        'required' => true, // ID is required
        'validate_callback' => function ($param, $request, $key) {
          return is_numeric($param); // Make sure ID is a number
        },
        'sanitize_callback' => 'absint', // Sanitize ID as an integer
      ],
    ]
  ));
}
add_action('rest_api_init', 'custom_kopa_payment_rest_endpoint', 999);

function handle_test_endpoint()
{
  return new WP_REST_Response('REST API working correctly', 200);
}
function handle_kopa_payment_rest_redirect($data)
{
  $orderId = $data->get_param('id');
  $kopaCurl = new KopaCurl();

  if (
    isset($_GET['authResult']) &&
    !empty(isset($_GET['authResult'])) &&
    !isDebugActive(Debug::AFTER_PAYMENT)
  ) {
    $authResult = $_GET['authResult'];
    $kopaOrderId = get_post_meta($orderId, 'kopaIdReferenceId', true);
    if (!empty($orderId)) {
      $order = wc_get_order($orderId);

      if (!$order) {
        $notice = __('Payment unsuccessful - your payment card account is not debited.', 'kopa-payment') . ' EC-866 <br>';
        $notice .= __('Order could not be found after redirection.', 'kopa-payment');
        // Add a notice to php session
        $_SESSION['custom_notice'] = $notice;

        // Get the order payment key
        $orderPayKey = get_post_meta($orderId, '_order_key', true);

        // Construct the query parameters
        $redirectParams = array(
          'pay_for_order' => 'true',
          'key' => $orderPayKey
        );

        // Use add_query_arg to append the parameters to the checkout URL
        $redirectUrl = add_query_arg($redirectParams, wc_get_checkout_url());

        wp_redirect($redirectUrl);

        exit;
      }
      $userId = $order->get_user_id();

      if ($authResult == 'AUTHORISED') {
        wp_redirect($order->get_checkout_order_received_url());
        exit;
      }
      if (in_array($authResult, ['CANCELLED', 'REFUSED'])) {
        $orderDetailsKopa = $kopaCurl->getOrderDetails($kopaOrderId, $userId);
        $notice = __('Payment unsuccessful - your payment card account is not debited.', 'kopa-payment');

        if ($authResult == 'CANCELLED') {
          $notice .= ' EC-886 <br>';
        } else {
          $notice .= ' EC-843 <br>';
        }

        if (
          !isset(get_option('woocommerce_kopa-payment_settings')['kopa_enable_test_mode']) ||
          get_option('woocommerce_kopa-payment_settings')['kopa_enable_test_mode'] == 'no'
        ) {
          $notice .= json_encode($orderDetailsKopa);
        }
        // Add a notice to php session
        $_SESSION['custom_notice'] = $notice;

        $order->update_status('pending');
        $order->save();

        // Get the order payment key
        $orderPayKey = get_post_meta($orderId, '_order_key', true);

        // Construct the query parameters
        $redirectParams = array(
          'pay_for_order' => 'true',
          'key' => $orderPayKey
        );

        // Use add_query_arg to append the parameters to the checkout URL
        $redirectUrl = add_query_arg($redirectParams, wc_get_checkout_url());
        // Redirect to the new URL
        wp_redirect($redirectUrl);
        exit;
      }
    }
  }

  if (isDebugActive(Debug::AFTER_PAYMENT)) {
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
}

function handle_kopa_payment_rest_endpoint($data)
{
  $orderId = $data->get_param('id');
  $kopaClass = new KOPA_Payment();
  $kopaCurl = new KopaCurl();
  $order = wc_get_order($orderId);
  $userId = $order->get_user_id();
  $physicalProducts = $kopaClass->isPhysicalProducts($order);

  // Retrieve JSON data from the request
  $jsonData = file_get_contents('php://input');
  $data = json_decode($jsonData, true);

  $kopaOrderId = get_post_meta($orderId, 'kopaIdReferenceId', true);
  // Check if OrderId and kopa reference id match
  if ($data['OrderId'] == $kopaOrderId) {
    // Update transaction meta data
    update_post_meta($orderId, 'kopaOrderPaymentData', $data);

    // Check for payment transaction details on KOPA
    $orderDetailsKopa = $kopaCurl->getOrderDetails($kopaOrderId, $userId);

    // Update Status on order and return transaction status BOOLEAN
    $successPayment = paymentSuccessCheckup($order, $orderDetailsKopa, $physicalProducts);

    // successPayment is false, meaning that transaction was unsuccessful
    if ($successPayment === false) {
      return new WP_REST_Response('Data received: Failed transaction', 200);
    }
    return new WP_REST_Response('Data received: Success transaction', 200);
  } else {
    update_post_meta($orderId, 'kopaOrderPaymentData', json_encode([
      'sentData' => $data,
      'message' => 'kopaId Not the same',
      'kopaId' => $kopaOrderId,
      'orderId' => $orderId
    ]));
  }
  return new WP_REST_Response('ERROR-OU409: Data for this order could not be recieved', 409);
}

/**
 * Summary of paymentSuccessCheckup
 * @param object $order
 * @param array $orderDetailsKopa
 * @param bool $physicalProducts
 * @return bool
 */
function paymentSuccessCheckup($order, $orderDetailsKopa, $physicalProducts)
{
  // Check on KOPA system if order was actually paid and has no errors
  if (
    isset($orderDetailsKopa['response']) && $orderDetailsKopa['response'] == 'Approved' &&
    isset($orderDetailsKopa['trantype']) && in_array($orderDetailsKopa['trantype'], ['Auth', 'PreAuth', 'PostAuth']) &&
    isset($orderDetailsKopa['transaction']) && !empty($orderDetailsKopa['transaction']) &&
    isset($orderDetailsKopa['transaction']['errorCode']) && empty($orderDetailsKopa['transaction']['errorCode'])
  ) {
    if ($physicalProducts == true) {
      // Change the order status to 'processing'
      $order->update_meta_data('isPhysicalProducts', 'true');
      $order->update_status('processing');
    } else {
      // Change the order status to 'completed'
      $order->update_meta_data('isPhysicalProducts', 'false');
      $order->update_status('completed');
    }
    // Save details about transaction from KOPA
    $order->update_meta_data('paymentCheckup', $orderDetailsKopa);
    $order->update_meta_data('kopaTranType', '3d_success');
    $order->save();
    return true;
  }
  return false;
}
