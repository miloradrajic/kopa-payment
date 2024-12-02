<?php
/**
 * Getting encoding/decoding key
 * @return never
 */
function get_secret_key()
{
  include_once KOPA_PLUGIN_PATH . '/inc/curl.php';
  $kopaCurl = new KopaCurl();
  // Check the nonce for security
  check_ajax_referer('ajax-checkout-nonce', 'security');

  $secretKey = $kopaCurl->getPiKey();
  if ($secretKey) {
    wp_send_json_success(['key' => $secretKey]);
  } else {
    // If errors, return them to the JavaScript
    wp_send_json_error(['message' => __('Error getting pi key', 'kopa-payment')]);
  }

  exit;
}

add_action('wp_ajax_get_secret_key', 'get_secret_key');
add_action('wp_ajax_nopriv_get_secret_key', 'get_secret_key');

/**
 * Saving CC to KOPA Payment API
 * @return never
 */
function save_cc_ajax()
{
  include_once KOPA_PLUGIN_PATH . '/inc/curl.php';
  $kopaCurl = new KopaCurl();

  // Check the nonce for security
  check_ajax_referer('ajax-checkout-nonce', 'security');

  $ccType = detectCreditCardType($_POST['ccNumber'], $_POST['ccType']);
  $saved = $kopaCurl->saveCC($_POST['ccNumbEncoded'], $_POST['ccExpDateEncoded'], $ccType, $_POST['ccAlias']);

  if ($saved == true) {
    $response = array('success' => true, );
    wp_send_json_success(['message' => __('CC Saved', 'kopa-payment')]);
  } else {
    $response = array('success' => false, 'message' => __('Error saving CC'));
    wp_send_json_error(['message' => __('CC Saved', 'kopa-payment')]);
  }

  exit;
}
add_action('wp_ajax_save_cc', 'save_cc_ajax');

/**
 * Detecting CC type
 * @return never
 */
function detectCcType()
{
  // Check the nonce for security
  check_ajax_referer('ajax-checkout-nonce', 'security');

  $ccType = detectCreditCardType($_POST['ccNumber'], $_POST['ccType']);
  if ($ccType !== false)
    wp_send_json_success(['cardType' => $ccType]);
  if ($ccType == false)
    wp_send_json_success(['cardType' => false, 'message' => __('Please check CC number and selected CC type.', 'kopa-payment')]);
  exit;
}
add_action('wp_ajax_get_card_type', 'detectCcType');
add_action('wp_ajax_nopriv_get_card_type', 'detectCcType');

/**
 * Delete CC from KOPA Payment API 
 * @return never
 */
function deleteCc()
{
  include_once KOPA_PLUGIN_PATH . '/inc/curl.php';
  $kopaCurl = new KopaCurl();

  // Check the nonce for security
  check_ajax_referer('ajax-my-account-nonce', 'security');
  $deleted = $kopaCurl->deleteCc($_POST['ccId']);
  if ($deleted == true) {
    $deleted = array('success' => true, 'message' => __('CC Deleted', 'kopa-payment'));
  } else {
    $deleted = array('success' => false, 'message' => __('Error deleting CC', 'kopa-payment'));
  }

  wp_send_json_success($deleted);
  exit;
}
add_action('wp_ajax_delete_card', 'deleteCc');

/**
 * Getting saved CC details from KOPA Payment API
 * @return never
 */
function getSavedCardDetails()
{
  include_once KOPA_PLUGIN_PATH . '/inc/curl.php';
  $kopaCurl = new KopaCurl();

  // Check the nonce for security
  check_ajax_referer('ajax-checkout-nonce', 'security');

  $cardId = $_POST['ccId'];
  $card = $kopaCurl->getSavedCcDetails($cardId);

  if ($card) {
    wp_send_json_success(['card' => $card]);
  } else {
    wp_send_json_error(['message' => __('Error getting card details', 'kopa-payment')]);
  }

  exit;
}
add_action('wp_ajax_get_card_details', 'getSavedCardDetails');

/**
 * Check if CC is allowed payment with installments
 * @return wp_send_json_success | wp_send_json_error
 */
function checkCartInstallmentsSupport()
{
  include_once KOPA_PLUGIN_PATH . '/inc/curl.php';
  $kopaCurl = new KopaCurl();

  // Check the nonce for security
  check_ajax_referer('ajax-checkout-nonce', 'security');
  if (!isset($_POST['bin']) || empty($_POST['bin'])) {
    wp_send_json_error(['message' => __('No BIN provided', 'kopa-payment')]);
    exit;
  }
  $cardBin = $_POST['bin'];
  $suportInstallments = $kopaCurl->checkCcBinNumberForInstallments($cardBin);

  if ($suportInstallments) {
    wp_send_json_success($suportInstallments);
  } else {
    wp_send_json_error(['message' => __('Error getting installments details', 'kopa-payment')]);
  }

  exit;
}
add_action('wp_ajax_get_card_installments_support', 'checkCartInstallmentsSupport');

/**
 * Saving error logs
 * @return never
 */
function log3dPaymentError()
{
  $orderId = $_POST['orderId'];
  $kopaOrderId = $_POST['kopaOrderId'];
  $errorMessage = $_POST['errorMessage'];
  $order = wc_get_order($orderId);

  // Add an order note
  $order->add_order_note($errorMessage);
  // Save changes
  $order->save();

  kopaMessageLog('3D Payment', $orderId, get_current_user_id(), $_SESSION['kopaUserId'], $errorMessage, $kopaOrderId);

  wp_send_json_error(['message' => __('Error log has been updated', 'kopa-payment')]);
  exit;
}
add_action('wp_ajax_error_log_on_payment', 'log3dPaymentError');
add_action('wp_ajax_nopriv_error_log_on_payment', 'log3dPaymentError');
