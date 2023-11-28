<?php 
class KopaCurl {
  private $ch, $headers, $serverUrl, $merchantId, $errors; // cURL handle

  public function __construct() {
    if(
      !isset(get_option('woocommerce_kopa-payment_settings')['kopa_server_url']) ||
      empty(get_option('woocommerce_kopa-payment_settings')['kopa_server_url']) ||
      !isset(get_option('woocommerce_kopa-payment_settings')['kopa_merchant_id']) ||
      empty(get_option('woocommerce_kopa-payment_settings')['kopa_merchant_id'])
    ) return;
    $this->ch = curl_init();
    $this->headers = array(
      'Content-Type: application/json'
    );
    curl_setopt_array($this->ch, array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ));
    $this->serverUrl = trim(get_option('woocommerce_kopa-payment_settings')['kopa_server_url']);
    $this->merchantId = get_option('woocommerce_kopa-payment_settings')['kopa_merchant_id'];
  }
  /**
   * cURL GET function
   */
  public function get($url) {
    curl_setopt($this->ch, CURLOPT_URL, $url);
    curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->headers);

    return $this->execute();
  }

  /**
   *  cURL POST function
   */
  public function post($url, $data) {
    curl_setopt($this->ch, CURLOPT_URL, $url);
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->headers);
    curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($this->ch, CURLOPT_POSTFIELDS, $data);

    return $this->execute();
  }

  /**
   * cURL DELETE function
   */
  public function delete($url) {
    curl_setopt($this->ch, CURLOPT_URL, $url);
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->headers);
    curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

    return $this->execute();
  }

  /**
   * cURL Execute function
   */
  private function execute() {
    $response = curl_exec($this->ch);
    if (curl_errno($this->ch)) {
      return 'cURL Error: ' . curl_error($this->ch);
    }
    return $response;
  }

  /**
   * Closing cURL 
   */
  public function close() {
    curl_close($this->ch);
  }

  /**
   * Function to login end user trough admin pannel
   * Used when admin initialize PostAuth, GetOrderDetails or Refund functions
   */
  private function loginUserByAdmin($userId){
    if(empty($userId) && !is_admin()) return;
    // If not logged in user use anonymous user
    if ($userId == 0){
      $username = 'anonymous';
      $password = 'anonymous';
    }else{
      $user = get_user_by('ID', $userId);
      $username = $user->user_login;
      $password = base64_encode($username.$userId);
    }
    $loginUrl = $this->serverUrl.'/api/auth/login';
    $merchantID = $this->merchantId;

    $data = json_encode([
      'username' => $username, 
      'password' => $password, 
      'socialMedia' => null, 
      'merchantId' => $merchantID
    ]);
    $returnData = json_decode($this->post($loginUrl, $data), true);
    
    return $returnData;
  }

  /**
   * Login/Register user and add user meta that user is using KOPA platform
   */
  public function login() {
    if(empty($this->serverUrl)){return;}
    if(empty($this->merchantId)){return;}
    $loginUrl = $this->serverUrl.'/api/auth/login';
    $merchantID = $this->merchantId;
    
    if ( is_user_logged_in() ) {
      $current_user = wp_get_current_user();
      $userId = $current_user->ID;
      $username = $current_user->user_login;
      // Check user metafield if user is already registered on KOPA 
      if(get_user_meta($userId, 'kopa_user_registered', true) ){
        // if user is registered on KOPA, login user and get access_token
        $data = json_encode([
          'username' => $username, 
          'password' => base64_encode($username.$userId), 
          'socialMedia' => null, 
          'merchantId' => $merchantID
        ]);
      }else{
        // Register user to KOPA and save user meta that user is registered
        $this->register($username, $userId);
        update_user_meta( $userId, 'kopa_user_registered', true );
        $this->login();
        return;
      }
    }else{
      // If user is not logged in woocommerce, use anonymus credentials for KOPA platform
      $data = json_encode([
        'username' => 'anonymous', 
        'password' => 'anonymous', 
        'socialMedia' => null, 
        'merchantId' => $merchantID
      ]);
    }
    $returnData = json_decode($this->post($loginUrl, $data), true);

    $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    $this->close();

    if(!in_array($httpCode, [200, 201])) {
      error_log('[KOPA ERROR]: Login error for user with ID '. $userId);
      return;
    }

    // Save KOPA user datails in SESSION
    $_SESSION['userId'] = $returnData['userId'];
    $_SESSION['access_token'] = $returnData['access_token'];
    $_SESSION['refresh_token'] = $returnData['refresh_token'];

    return $returnData; 
  }

  /**
   * Register function on KOPA platform
   */
  public function register($username, $userId) {
    $registerUrl = $this->serverUrl.'/api/auth/register';
    $merchantID = $this->merchantId;

    $data = json_encode([
      'username' => $username, 
      'password' => base64_encode($username.$userId), 
      'socialMedia' => null, 
      'merchantId' => $merchantID
    ]);

    $returnData = json_decode($this->post($registerUrl, $data), true);
    $httpcode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    $this->close();
    if(!in_array($httpcode, [200, 201])) {
      error_log('[KOPA ERROR]: Register error for user with ID '. $userId);
      // Log event
      kopaMessageLog(__METHOD__, '', $userId, '', $returnData);
      // wc_add_notice(__('There was problem with Kopa Payment method', 'kopa-payment') .' - ' . $returnData['message'], 'error');
      return;
    }
    return; 
  }

  /**
   * Getting secret key for encoding
   */
  public function getPiKey(){
    $encodingKeyUrl = $this->serverUrl.'/api/pikey';
    // Add authorization header
    $this->headers[] = 'Authorization: Bearer ' . $_SESSION['access_token']; 
    $returnData = $this->get($encodingKeyUrl);
    $this->close();
    array_pop($this->headers);
    
    $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    if(!in_array($httpCode, [200, 201])) {
      $this->retryFunctionIfInvalidJwt($httpCode, $returnData, array($this, 'getPiKey'));
      error_log('[KOPA ERROR]: Error getting pikey');
      wc_add_notice(__('There was problem with Kopa Payment method.', 'kopa-payment'), 'error');
      return;
    }
    return $returnData;
  }
  /**
   * Function to save CC on KOPA platform
   */
  public function saveCC($encCcNumber, $encCcExpDate, $ccType, $ccAlias) {   
    $saveCcUrl = $this->serverUrl.'/api/cards';
    $data = json_encode([
      'alias' => $ccAlias, 
      'type' => $ccType, 
      'userId' => $_SESSION['userId'], 
      'cardNo' => $encCcNumber, 
      'expirationDate' => $encCcExpDate
    ]);
    $this->headers[] = 'Authorization: Bearer ' . $_SESSION['access_token']; 
    $returnData = $this->post($saveCcUrl, $data);
    $decodedReturn = json_decode($returnData, true);

    $this->close();

    // Remove the last added header, which is the "Authorization" header
    array_pop($this->headers);

    if($decodedReturn['response'] == 'Error'){
      $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
      $this->retryFunctionIfInvalidJwt($httpCode, $returnData, array($this, 'saveCC'), $encCcNumber, $encCcExpDate, $ccType, $ccAlias);
    }
    if(
      $httpCode == 409 && 
      $returnData == 'Card with this alias already exists'
    ){
      wc_add_notice(__('Credit card already saved', 'kopa-payment'), 'notice');
      return true;
    }

    if($decodedReturn['resultCode'] == 'ok') return true;

    error_log('[KOPA ERROR]: Error saving CC ');
    wc_add_notice(__('There was problem with Kopa Payment method. Saving CC', 'kopa-payment'), 'error');
    return false;
  }

  /**
   * Deleting CC from KOPA platform
   */
  function deleteCc($ccId){
    $deleteCcUrl = $this->serverUrl.'/api/cards/'.$ccId;
    $this->headers[] = 'Authorization: Bearer ' . $_SESSION['access_token']; 
    $returnData = $this->delete($deleteCcUrl);
    $this->close();
    // Remove the last added header, which is the "Authorization" header
    array_pop($this->headers);

    $decodedReturn = json_decode($returnData, true);

    if(isset($decodedReturn['response']) && $decodedReturn['response'] == 'Error'){
      $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
      $this->retryFunctionIfInvalidJwt($httpCode, $returnData, array($this, 'deleteCc'), $ccId);
    }

    if($decodedReturn['resultCode'] == 'ok') return true;

    error_log('[KOPA ERROR]: Error deleting CC ');
    wc_add_notice(__('There was problem with Kopa Payment method. DELETE CC', 'kopa-payment'), 'error');
    return false;
  }

  /**
   * Getting all user saved CCs from KOPA platform
   */
  public function getSavedCC(){
    $saveCcUrl = $this->serverUrl.'/api/cards?userId='.$_SESSION['userId'];
    $this->headers[] = 'Authorization: Bearer ' . $_SESSION['access_token']; 
    $returnData = $this->get($saveCcUrl);
    $decodedReturn = json_decode($returnData, true);
    $this->close();
    
    array_pop($this->headers);
    if(isset($decodedReturn['response']) && $decodedReturn['response'] == 'Error'){
      $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
      $this->retryFunctionIfInvalidJwt($httpCode, $returnData, array($this, 'getSavedCC'));
    }
    return $decodedReturn;
  }

  /**
   * Getting CC details by ID
   */
  public function getSavedCcDetails($ccCardId) {
    $cardDetailsUrl = $this->serverUrl.'/api/cards/'.$ccCardId;
    $this->headers[] = 'Authorization: Bearer ' . $_SESSION['access_token']; 
    $returnData = $this->get($cardDetailsUrl);
    $decodedReturn = json_decode($returnData, true);
    $this->close();
    
    array_pop($this->headers);
    
    // Retry function if invalid JWT
    if(!isset($decodedReturn['resultCode']) || $decodedReturn['resultCode'] !== 'ok'){
      $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
      $this->retryFunctionIfInvalidJwt($httpCode, $returnData, array($this, 'getSavedCcDetails'), $ccCardId);
    }
    return $decodedReturn;
  }

  /**
   * When JWT expires, reset it with refresh token
   */
  private function resetAuthToken(){
    $successTokenRefresh = true;
    if(isset($_SESSION['refresh_token']) && !empty($_SESSION['refresh_token'])){
      $refreshTokenUrl = $this->serverUrl.'/api/auth/refresh_token';
      $data = json_encode([
        'refresh' => $_SESSION['refresh_token']
      ]);
      $returnData = $this->post($refreshTokenUrl, $data);
      $this->close();
      $httpcode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
      if(in_array($httpcode, [200, 201])){
        $_SESSION['access_token'] = json_decode($returnData, true)['access_token'];
      }else{
        $successTokenRefresh = false;
      }
    }
    if($successTokenRefresh == false) {
      $this->login();
    }
    return;
  }

  /**
   * Check if cURL request failed do to expired JWT
   * If httpCode is 401, refresh JWT token
   */
  private function retryFunctionIfInvalidJwt($httpCode, $returnData, $callbackFunction, ...$args){
    if (
      $httpCode == 401 && 
      json_decode($returnData, true)['message'] == 'Invalid JWT token' &&
      is_callable($callbackFunction)
    ) {
      if(isset($_SESSION['refresh_token']) && !empty($_SESSION['refresh_token'])){
        $this->resetAuthToken();
      }else{
        $this->login();
      }
      return call_user_func_array($callbackFunction, $args);
    }

    $current_user = wp_get_current_user();
    $userId = $current_user->ID;
    // Log event
    if(isset(json_decode($returnData, true)['message'])){
      kopaMessageLog($callbackFunction[1], $_SESSION['orderId'], $userId, $_SESSION['userId'], $returnData, json_decode($returnData, true)['message'], $_SESSION['kopaOrderId']);
    }else{
      kopaMessageLog($callbackFunction[1], $_SESSION['orderId'], $userId, $_SESSION['userId'], $returnData, 'ERROR - '.$httpCode, $_SESSION['kopaOrderId']);
    }
  }

  /**
   * Get bank details for payment
   */
  public function getBankDetails($orderId, $amount, $physicalProduct){
    $bankDetailsUrl = $this->serverUrl.'/api/payment/bank_details';
    $this->headers[] = 'Authorization: Bearer ' . $_SESSION['access_token']; 
    
    $data = json_encode([
      'oid' => $orderId, 
      'amount' => $amount, 
      'physicalProduct' => $physicalProduct,
      'userId' => $_SESSION['userId']]
    );

    $returnData = $this->post($bankDetailsUrl, $data);
    $decodedReturn = json_decode($returnData, true);
    $this->close();

    array_pop($this->headers);

    if(isset($decodedReturn['response']) && $decodedReturn['response'] == 'Error'){
      // Retry function if invalid JWT
      $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
      $this->retryFunctionIfInvalidJwt($httpCode, $returnData, array($this, 'getBankDetails'), $orderId, $amount, $physicalProduct);
    }

    $returnDataDecoded = json_decode($returnData, true);
    return $returnDataDecoded;
  }

  /**
   * MOTO payment
   */
  public function motoPayment($card, $cardId, $amount, $physicalProduct, $kopaOrderId){
    $motoPaymentUrl = $this->serverUrl.'/api/payment/moto';
    $this->headers[] = 'Authorization: Bearer ' . $_SESSION['access_token']; 
    $data = json_encode(
      [
        'alias'           => $card['alias'], 
        'expirationDate'  => $card['expirationDate'], 
        'type'            => $card['type'],
        'cardNo'          => $card['cardNo'], 
        'cardId'          => $cardId, 
        'userId'          => $_SESSION['userId'],
        'amount'          => $amount, 
        'physicalProduct' => $physicalProduct,
        'oid'             => $kopaOrderId,
        'ccv'             => null,
      ]
    );
    $returnData = $this->post($motoPaymentUrl, $data);
    $this->close();
    array_pop($this->headers);
    
    $decodedReturn = json_decode($returnData, true);
    if($decodedReturn['response'] == 'Error'){
      // Retry function if invalid JWT
      $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
      $this->retryFunctionIfInvalidJwt($httpCode, $returnData, array($this, 'motoPayment'), $card, $cardId, $amount, $physicalProduct, $kopaOrderId);
    }

    $returnDataDecoded = json_decode($returnData, true);
    return $returnDataDecoded;
  }

  /**
   * API Payment
   */
  public function apiPayment($card, $amount, $physicalProduct, $kopaOrderId){
    $apiPaymentUrl = $this->serverUrl.'/api/payment/api';
    $this->headers[] = 'Authorization: Bearer ' . $_SESSION['access_token']; 
    $data = json_encode(
      [
        'alias'           => $card['alias'], 
        'expirationDate'  => $card['expirationDate'], 
        'type'            => $card['type'],
        'cardNo'          => $card['cardNo'], 
        'userId'          => $_SESSION['userId'],
        'ccv'             => $card['ccv'],
        'amount'          => $amount, 
        'physicalProduct' => $physicalProduct,
        'oid'             => $kopaOrderId,
      ]
    );
    $returnData = $this->post($apiPaymentUrl, $data);
    $this->close();
    array_pop($this->headers);
    $decodedReturn = json_decode($returnData, true);
    if($decodedReturn['response'] == 'Error'){
      $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
      $this->retryFunctionIfInvalidJwt($httpCode, $returnData, array($this, 'apiPayment'), $card, $amount, $physicalProduct, $kopaOrderId);
    }
    return $decodedReturn;
  }

  /**
   * Changing payment status from PreAuth to PostAuth on MOTO or API payment methods
   * It is triggered on changing order status to "Completed"
   */
  public function postAuth($orderId, $userId){
    $kopaOrderId = get_post_meta($orderId, 'kopaIdReferenceId', true);

    $loginResult = $this->loginUserByAdmin($userId);
    $postAuthUrl = $this->serverUrl.'/api/payment/postauth';
    $this->headers[] = 'Authorization: Bearer ' . $loginResult['access_token']; 
    $data = json_encode(
      [
        'oid'     => strval($kopaOrderId), 
        'userId'  => $loginResult['userId'], 
      ]
    );
    $returnData = $this->post($postAuthUrl, $data);
    $this->close();
    array_pop($this->headers);
    
    $decodedReturn = json_decode($returnData, true);
    if($decodedReturn['response'] == 'Error'){
      // Log event
      kopaMessageLog(__METHOD__, $orderId, $userId, $loginResult['userId'], $decodedReturn['errMsg']);
    }

    return $decodedReturn;
  }

  /**
   * Get order details from KOPA platform
   */
  public function getOrderDetails($kopaOrderId, $userId){
    $loginResult = $this->loginUserByAdmin($userId);
    $orderDetails = $this->serverUrl.'/api/orders/'.$kopaOrderId;

    $this->headers[] = 'Authorization: Bearer ' . $loginResult['access_token']; 
    $data = json_encode(
      [
        'oid'     => $kopaOrderId, 
        'userId'  => $loginResult['userId'], 
      ]
    );
    $returnData = $this->get($orderDetails, $data);
    $this->close();
    array_pop($this->headers);

    $returnDataDecoded = json_decode($returnData, true);
    return $returnDataDecoded;
  }

  /**
   * Get Void last step on order KOPA platform
   */
  private function voidLastStepOnOrder($orderId, $userId){
    $kopaOrderId = get_post_meta($orderId, 'kopaIdReferenceId', true);

    $loginResult = $this->loginUserByAdmin($userId);
    $orderDetails = $this->serverUrl.'/api/payment/void';

    $this->headers[] = 'Authorization: Bearer ' . $loginResult['access_token']; 
    $data = json_encode(
      [
        'oid'     => $kopaOrderId, 
        'userId'  => $loginResult['userId'], 
      ]
    );
    $returnData = $this->post($orderDetails, $data);
    $this->close();
    array_pop($this->headers);

    $returnDataDecoded = json_decode($returnData, true);
    return $returnDataDecoded;
  }

  /**
   * Checking if refund can be done on order and adding order notes if refunded
   */
  public function refundCheck($orderId, $userId){
    $custom_meta_field = get_post_meta($orderId, '_kopa_payment_method', true);
    $kopaOrderId = get_post_meta($orderId, 'kopaIdReferenceId', true);
    // Check if order payment was done with KOPA system
    if (empty($custom_meta_field)) {
      return ['success' => false, 'message'=> __('Order was not paid with KOPA payment method.','kopa-payment'), 'isKopa'=> false];
    }
    $orderDetails = $this->getOrderDetails($kopaOrderId, $userId);
    if($orderDetails['transaction'] == null){
      return ['success' => false, 'message'=> __('Transaction on this order was not completed with KOPA system', 'kopa-payment'), 'isKopa'=> true];
    }
    if(isset($orderDetails['trantype']) && $orderDetails['trantype'] == 'PreAuth'){
      return ['success' => false, 'message'=> __('Status of the order is PreAuth', 'kopa-payment'), 'isKopa'=> true]; 
    }
    if(isset($orderDetails['trantype']) && $orderDetails['trantype'] == 'Refund'){
      return ['success' => true, 'message'=> __('Order has been refunded', 'kopa-payment'), 'isKopa'=> true]; 
    }
  }

  public function orderTrantypeStatusCheck($orderId, $userId){
    $kopaOrderId = get_post_meta($orderId, 'kopaIdReferenceId', true);
    
    $orderDetails = $this->getOrderDetails($kopaOrderId, $userId);
    if(isset($orderDetails['trantype'])){
      return $orderDetails['trantype'];
    }
    return false;
  }

  public function orderVoidLastFunction($orderId, $userId){
    $kopaOrderId = get_post_meta($orderId, 'kopaIdReferenceId', true);
    $returnData = $this->voidLastStepOnOrder($kopaOrderId, $userId);
    if($returnData['response'] == 'Approved'){
      return ['success'=> true, 'response'=>'Approved'];
    }
    return ['success'=> false, 'response'=> $returnData['errMsg']];
  }

  /**
   * Refunding process
   * Checking if payment is in PostAuth state, if not updating payment status
   * Check if order was already refunded
   * Running refund cURL
   */
  public function refundProcess($orderId, $userId){
    $kopaOrderId = get_post_meta($orderId, 'kopaIdReferenceId', true);
    
    // check if order is in preAuth state
    $orderDetails = $this->getOrderDetails($kopaOrderId, $userId);
    
    // If transaction is not equal to NULL
    if($orderDetails['transaction'] == null) return ['success'=> true, 'response'=> __('Order payment was not completed on KOPA', 'kopa-payment')];

    if(isset($orderDetails['trantype']) && $orderDetails['trantype'] == 'PreAuth') {
      
      // if Order is in PreAuth state, Void last order action will initiate refund
      $returnData = $this->voidLastStepOnOrder($orderId, $userId);
      if($returnData['success'] == true ) {
        return ['success'=> true, 'response'=> __('Canceled payment with KOPA system', 'kopa-payment')];
      }
    }

    // Check transaction status if it was already refunded 
    if(isset($orderDetails['trantype']) && $orderDetails['trantype'] == 'Refund') {
      // Already refunded
      return ['success'=> true, 'response'=> __('Already refunded with KOPA', 'kopa-payment')];
    }
    
    
    if(isset($orderDetails['trantype']) && in_array($orderDetails['trantype'], ['PostAuth', 'Void']) ) {
      // Refund function
      $refundResult = $this->refund($kopaOrderId, $userId, $orderId);

      if($refundResult['success'] == true && $refundResult['response'] == 'Approved'){
        return ['success'=> true, 'response'=> __('Refunded completed on KOPA system', 'kopa-payment')];
      }
      if($refundResult['response'] == 'Error' && $refundResult['transaction']['errorCode'] == 'CORE-2504') {
        return ['success'=> true, 'response'=> __('Already refunded with KOPA system', 'kopa-payment')];
      }
    }
    return ['success' => false, 'response' => __('There was a problem with KOPA refund process', 'kopa-payment')];
  }

  /**
   * Refund cURL
   */
  private function refund($kopaOrderId, $userId, $orderId){
    $loginResult = $this->loginUserByAdmin($userId);
    $refundUrl = $this->serverUrl.'/api/payment/refund';
    $this->headers[] = 'Authorization: Bearer ' . $loginResult['access_token']; 
    $data = json_encode(
      [
        'oid'     => $kopaOrderId, 
        'userId'  => $loginResult['userId'], 
      ]
    );
    $returnData = $this->post($refundUrl, $data);
    $this->close();
    array_pop($this->headers);
    
    $returnDataDecoded = json_decode($returnData, true);
    // Log event
    kopaMessageLog(__METHOD__, $orderId, $userId, $loginResult['userId'], $returnDataDecoded);
    
    return $returnDataDecoded;
  }

}
?>