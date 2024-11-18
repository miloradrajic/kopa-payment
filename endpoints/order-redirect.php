<?php

// Parse the requested URI to extract the ID
$requestUri = $_SERVER['REQUEST_URI'];
$scriptName = $_SERVER['SCRIPT_NAME'];

// Parse the requested URI to extract the ID
$requestUri = $_SERVER['REQUEST_URI'];

// Remove query parameters from the URI
$requestUri = strtok($requestUri, '?'); // Only the path part remains

// Remove the base path (script directory path)
$basePath = str_replace('/order-redirect.php', '', $_SERVER['SCRIPT_NAME']);
$dynamicPath = str_replace($basePath, '', $requestUri);

// Split the path into segments
$segments = array_filter(explode('/', trim($dynamicPath, '/')));

// Include WordPress core if needed
require_once dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/wp-load.php';
// Extract the ID
$orderId = isset($segments[1]) && is_numeric($segments[1]) ? absint($segments[1]) : null;

if (!$orderId) {
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Invalid or missing order ID in the URL.']);
  http_response_code(400); // 400 Bad Request
  exit;
}

// Retrieve additional GET variables
$authResult = isset($_GET['authResult']) ? sanitize_text_field($_GET['authResult']) : null;

$order = wc_get_order($orderId);
$kopaCurl = new KopaCurl();

if (!$order) {
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Order not found.']);
  http_response_code(404); // 404 Not Found
  exit;
}

if (
  isset($_GET['authResult']) &&
  !empty(isset($_GET['authResult'])) &&
  !isDebugActive(Debug::AFTER_PAYMENT)
) {
  $authResult = $_GET['authResult'];
  if (!empty($orderId)) {
    $order = wc_get_order($orderId);
    $kopaOrderId = $order->get_meta('kopaIdReferenceId');

    if (!$order) {
      $notice = __('Payment unsuccessful - your payment card account is not debited.', 'kopa-payment') . ' EC-866 <br>';
      $notice .= __('Order could not be found after redirection.', 'kopa-payment');
      // Add a notice to php session
      $_SESSION['custom_notice'] = $notice;

      // Get the order payment key
      $orderPayKey = $order->get_meta('_order_key');

      // Construct the query parameters
      $redirectParams = array(
        'pay_for_order' => 'true',
        'key' => $orderPayKey
      );

      // Use add_query_arg to append the parameters to the checkout URL
      $redirectUrl = add_query_arg($redirectParams, wc_get_checkout_url());
      echo '$redirectUrl<pre>' . print_r($redirectUrl, true) . '</pre>';
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
      $orderPayKey = $order->get_meta('_order_key');

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
  $order = wc_get_order($orderId);
  $kopaOrderId = $order->get_meta('kopaIdReferenceId');
  $kopaTransactionDetails = $order->get_meta('kopaOrderPaymentData');
  $orderDetailsKopa = $kopaCurl->getOrderDetails($kopaOrderId, $userId);

  echo 'order<pre>' . print_r($orderId, true) . '</pre>';
  echo 'REQUEST_METHOD<pre>' . print_r($_SERVER['REQUEST_METHOD'], true) . '</pre>';
  echo 'authResult<pre>' . print_r($_GET['authResult'], true) . '</pre>';
  echo 'saved transaction details<pre>' . print_r($kopaTransactionDetails, true) . '</pre>';
  echo 'order details kopa<pre>' . print_r($orderDetailsKopa, true) . '</pre>';
  echo 'error message<pre>' . print_r($orderDetailsKopa['errMsg'], true) . '</pre>';
  exit;
}
exit;
?>