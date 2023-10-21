<?php 
class KopaCurl {
  private $ch, $headers, $serverUrl, $merchantId, $errors; // cURL handle

  public function __construct() {
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
    $this->errors = [];
    if(isset(get_option('woocommerce_kopa_payment_settings')['kopa_server_url'])){
      $this->serverUrl = trim(get_option('woocommerce_kopa_payment_settings')['kopa_server_url']);
    }else{
      $this->errors[] = 'Kopa server URL cannot be empty!';
    }
    if(isset(get_option('woocommerce_kopa_payment_settings')['kopa_merchant_id'])){
      $this->merchantId = get_option('woocommerce_kopa_payment_settings')['kopa_merchant_id'];
    }else{
      $this->errors[] = 'Kopa merchant ID cannot be empty!';      
    }
    if(!empty($this->errors)){
      add_action( 'admin_notices', array($this,'admin_warnings'));
    }
  }
  /**
   * Adding admin warnings when data is not entered in KOPA settings
   */
  function admin_warnings($message) {
    foreach($this->errors as $error){
      echo '<div class="notice notice-error">
              <p>'.$error.' <a href="'.get_admin_url().'admin.php?page=wc-settings&tab=checkout&section=kopa_payment">Check here</a></p>
            </div>';
    }
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
      wc_add_notice(__('There was problem with Kopa Payment method', 'kopa_payment') .' - ' . $returnData['message'], 'error');
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
    
    $decodedReturn = json_decode($returnData, true);
    if($decodedReturn['response'] == 'Error'){
      $httpcode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
      $this->retryFunctionIfInvalidJwt($httpcode, $returnData, array($this, 'getPiKey'));
    }
    if(!in_array($httpcode, [200, 201])) {
      error_log('[KOPA ERROR]: Error getting pikey');
      wc_add_notice(__('There was problem with Kopa Payment method. Saving CC', 'kopa_payment'), 'error');
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
    // echo 'saveCC return data<pre>' . print_r($returnData, true) . '</pre>';
    $this->close();
    $httpcode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    // Remove the last added header, which is the "Authorization" header
    array_pop($this->headers);

    $this->retryFunctionIfInvalidJwt($httpcode, $returnData, array($this, 'saveCC'), $encCcNumber, $encCcExpDate, $ccType, $ccAlias);
    
    if(
      $httpcode == 409 && 
      $returnData == 'Card with this alias already exists'
    ){
      wc_add_notice(__('Credit card already saved', 'kopa_payment'), 'notice');
      return true;
    }

    if(json_decode($returnData, true)['resultCode'] == 'ok') return true;

    error_log('[KOPA ERROR]: Error saving CC ');
    wc_add_notice(__('There was problem with Kopa Payment method. Saving CC', 'kopa_payment'), 'error');
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
    $httpcode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    // Remove the last added header, which is the "Authorization" header
    array_pop($this->headers);

    $this->retryFunctionIfInvalidJwt($httpcode, $returnData, array($this, 'deleteCc'), $ccId);

    if(json_decode($returnData, true)['resultCode'] == 'ok') return true;

    error_log('[KOPA ERROR]: Error deleting CC ');
    wc_add_notice(__('There was problem with Kopa Payment method. DELETE CC', 'kopa_payment'), 'error');
    return false;
  }

  /**
   * Getting all user saved CCs from KOPA platform
   */
  public function getSavedCC(){
    $saveCcUrl = $this->serverUrl.'/api/cards?userId='.$_SESSION['userId'];
    $this->headers[] = 'Authorization: Bearer ' . $_SESSION['access_token']; 
    $returnData = $this->get($saveCcUrl);
    $this->close();
    
    array_pop($this->headers);
    $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);

    $this->retryFunctionIfInvalidJwt($httpCode, $returnData, array($this, 'getSavedCC'));
    return json_decode($returnData, true);
  }

  /**
   * Getting CC details by ID
   */
  public function getSavedCcDetails($ccCardId) {
    $cardDetailsUrl = $this->serverUrl.'/api/cards/'.$ccCardId;
    $this->headers[] = 'Authorization: Bearer ' . $_SESSION['access_token']; 
    $returnData = $this->get($cardDetailsUrl);
    $this->close();
    
    array_pop($this->headers);
    
    // Retry function if invalid JWT
    $httpcode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    $this->retryFunctionIfInvalidJwt($httpcode, $returnData, array($this, 'getSavedCcDetails'), $ccCardId);

    return json_decode($returnData, true);
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

    // Retry function if invalid JWT
    $httpcode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    $this->retryFunctionIfInvalidJwt($httpcode, $returnData, array($this, 'getBankDetails'), $orderId, $amount, $physicalProduct);
    // Log event
    kopaMessageLog(
      __METHOD__, 
      $orderId, 
      get_current_user_id(), 
      $_SESSION['userId'], 
      $decodedReturn['errMsg']
    );

    $returnDataDecoded = json_decode($returnData, true);
    return $returnDataDecoded;
  }

  /**
   * MOTO payment
   */
  public function motoPayment($card, $cardId, $amount, $physicalProduct, $orderId){
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
        'oid'             => $orderId,
        'ccv'             => null,
      ]
    );
    $returnData = $this->post($motoPaymentUrl, $data);
    $this->close();
    array_pop($this->headers);
    
    $decodedReturn = json_decode($returnData, true);
    if($decodedReturn['response'] == 'Error'){
      // Retry function if invalid JWT
      $httpcode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
      $this->retryFunctionIfInvalidJwt($httpcode, $returnData, array($this, 'motoPayment'), $card, $cardId, $amount, $physicalProduct, $orderId);
      // Log event
      kopaMessageLog(
        __METHOD__, 
        $orderId, 
        get_current_user_id(), 
        $_SESSION['userId'], 
        $decodedReturn['errMsg']
      );
    }

    $returnDataDecoded = json_decode($returnData, true);
    return $returnDataDecoded;
  }

  /**
   * API Payment
   */
  public function apiPayment($card, $amount, $physicalProduct, $orderId){
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
        'oid'             => $orderId,
      ]
    );
    $returnData = $this->post($apiPaymentUrl, $data);
    $this->close();
    array_pop($this->headers);
    
    $decodedReturn = json_decode($returnData, true);
    if($decodedReturn['response'] == 'Error'){
      $httpcode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
      $this->retryFunctionIfInvalidJwt($httpcode, $returnData, array($this, 'apiPayment'), $card, $amount, $physicalProduct, $orderId);
      // Log event
      kopaMessageLog(__METHOD__, $orderId, get_current_user_id(), $_SESSION['userId'], $decodedReturn['errMsg']);
    }
    return $decodedReturn;
  }

  /**
   * Changing payment status from PreAuth to PostAuth on MOTO or API payment methods
   * It is triggered on changing order status to "Completed"
   */
  public function postAuth($orderId, $userId){
    $loginResult = $this->loginUserByAdmin($userId);
    $postAuthUrl = $this->serverUrl.'/api/payment/postauth';
    $this->headers[] = 'Authorization: Bearer ' . $loginResult['access_token']; 
    $data = json_encode(
      [
        'oid'     => strval($orderId), 
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
  private function getOrderDetails($orderId, $userId){
    $loginResult = $this->loginUserByAdmin($userId);
    $orderDetails = $this->serverUrl.'/api/orders/'.$orderId;

    $this->headers[] = 'Authorization: Bearer ' . $loginResult['access_token']; 
    $data = json_encode(
      [
        'oid'     => $orderId, 
        'userId'  => $loginResult['userId'], 
      ]
    );
    $returnData = $this->get($orderDetails, $data);
    $this->close();
    array_pop($this->headers);

    $returnDataDecoded = json_decode($returnData, true);
    // Log event
    kopaMessageLog(__METHOD__, $orderId, $userId, $loginResult['userId'], $returnDataDecoded);

    return $returnDataDecoded;
  }

  /**
   * Checking if refund can be done on order and adding order notes if refunded
   */
  public function refundCheck($orderId, $userId){
    $custom_meta_field = get_post_meta($orderId, '_kopa_payment_method', true);

    // Check if order payment was done with MOTO or API method
    if (empty($custom_meta_field)) {
      return ['success' => false, 'message'=> __('Order was not payed with KOPA payment method.'), 'isKopa'=> false];
    }
    $orderDetails = $this->getOrderDetails($orderId, $userId);
    if($orderDetails['transaction'] == null){
      return ['success' => false, 'message'=> __('Transaction on this order was not completed with KOPA system'), 'isKopa'=> true];
    }
    if(isset($orderDetails['trantype']) && $orderDetails['trantype'] == 'PreAuth'){
      return ['success' => false, 'message'=> __('Status of the order is PreAuth'), 'isKopa'=> true]; 
    }
    if(isset($orderDetails['trantype']) && $orderDetails['trantype'] == 'Refund'){
      return ['success' => true, 'message'=> __('Order has been refunded'), 'isKopa'=> true]; 
    }
  }

  /**
   * Refunding process
   * Checking if payment is in PostAuth state, if not updating payment status
   * Check if order was already refunded
   * Running refund cURL
   */
  public function refundProcess($orderId, $userId){
    // check if order is in preAuth state
    $orderDetails = $this->getOrderDetails($orderId, $userId);
    
    // If transaction is not equal to NULL
    if($orderDetails['transaction'] == null) return ['success'=> true, 'response'=>'Approved'];

    if(isset($orderDetails['trantype']) && $orderDetails['trantype'] == 'PreAuth') {
      // Change order to PostAuth state before refund
      $postAuthResult = $this->postAuth($orderId, $userId);
      if($postAuthResult['success'] == true && $postAuthResult['response'] == 'Approved'){
        $refundResult = $this->refund($orderId, $userId);
        if($refundResult['success'] == true){
          return ['success'=> true, 'response'=>'Approved'];
        }
      }
    }

    // Check transaction status if it was already refunded 
    if(isset($orderDetails['trantype']) && $orderDetails['trantype'] == 'Refund') {
      // Already refunded
      return ['success'=> true, 'response'=>'Approved'];
    }
    
    // Refund function
    $refundResult = $this->refund($orderId, $userId);
    if($refundResult['success'] == true){
      return ['success'=> true, 'response'=>'Approved'];
    }
  }

  /**
   * Refund cURL
   */
  private function refund($orderId, $userId){
    $loginResult = $this->loginUserByAdmin($userId);
    $refundUrl = $this->serverUrl.'/api/payment/refund';
    $this->headers[] = 'Authorization: Bearer ' . $loginResult['access_token']; 
    $data = json_encode(
      [
        'oid'     => $orderId, 
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