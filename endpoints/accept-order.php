<?php
try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new Exception('Invalid request method. Only POST is allowed.');
  }

  require_once dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/wp-load.php';

  $orderId = isset($_GET['id']) ? absint($_GET['id']) : null;
  if (!$orderId) {
    throw new Exception('Invalid or missing ID.');
  }

  // header('Content-Type: application/json');
  // echo json_encode([
  //   'success' => true, 
  //   'message' => $_SERVER['REQUEST_METHOD'], 
  //   'orderId'=> $orderId
  // ]);
  // http_response_code(200); // 405 Method Not Allowed
  // exit;

  $kopaClass = new KOPA_Payment();
  $kopaCurl = new KopaCurl();
  $order = wc_get_order($orderId);

  if (!$order) {
    throw new Exception('Order not found.');
  }

  $jsonData = file_get_contents('php://input');
  $data = json_decode($jsonData, true);

  if (json_last_error() !== JSON_ERROR_NONE) {
    throw new Exception('Invalid JSON payload: ' . json_last_error_msg());
  }

  if (empty($data['OrderId'])) {
    throw new Exception('Missing OrderId in the request body.');
  }

  $kopaOrderId = $order->get_meta('kopaIdReferenceId');
  if ($data['OrderId'] !== $kopaOrderId) {
    $order->update_meta_data('kopaOrderPaymentData', json_encode([
      'sentData' => $data,
      'message' => 'kopaId Not the same',
      'kopaId' => $kopaOrderId,
      'orderId' => $orderId,
    ]));
    $order->save();
    throw new Exception('ERROR-OU409: Data for this order could not be received.');
  }

  $order->update_meta_data('kopaOrderPaymentData', $data);
  $order->save();

  $orderDetailsKopa = $kopaCurl->getOrderDetails($kopaOrderId, $order->get_user_id());
  $successPayment = paymentSuccessCheckup($order, $orderDetailsKopa, $kopaClass->isPhysicalProducts($order));

  if (!$successPayment) {
    throw new Exception('Failed transaction.');
  }

  header('Content-Type: application/json');
  echo json_encode(['success' => true, 'message' => 'Success transaction.']);
  http_response_code(200);
  exit;

} catch (Exception $e) {
  // Handle exceptions
  header('Content-Type: application/json');
  echo json_encode([
    'error' => $e->getMessage(),
    'trace' => $e->getTraceAsString(),
    'method' => $_SERVER['REQUEST_METHOD'],
    'postedData' => file_get_contents('php://input')
  ]);
  http_response_code(500);
  exit;
}
