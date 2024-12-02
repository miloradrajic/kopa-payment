<?php
class KopaCurl
{
  private $ch, $headers, $serverUrl, $merchantId, $errors, $initialized; // cURL handle

  public function __construct()
  {
    if (
      isset(get_option('woocommerce_kopa-payment_settings')['kopa_enable_test_mode']) &&
      get_option('woocommerce_kopa-payment_settings')['kopa_enable_test_mode'] == 'yes' &&
      isset(get_option('woocommerce_kopa-payment_settings')['kopa_server_test_url']) &&
      !empty(get_option('woocommerce_kopa-payment_settings')['kopa_server_test_url']) &&
      isset(get_option('woocommerce_kopa-payment_settings')['kopa_test_merchant_id']) &&
      !empty(get_option('woocommerce_kopa-payment_settings')['kopa_test_merchant_id'])
    ) {
      $this->curlInit();
      $this->serverUrl = trim(get_option('woocommerce_kopa-payment_settings')['kopa_server_test_url']);
      $this->merchantId = get_option('woocommerce_kopa-payment_settings')['kopa_test_merchant_id'];
    } else {
      if (
        !isset(get_option('woocommerce_kopa-payment_settings')['kopa_server_url']) ||
        empty(get_option('woocommerce_kopa-payment_settings')['kopa_server_url']) ||
        !isset(get_option('woocommerce_kopa-payment_settings')['kopa_merchant_id']) ||
        empty(get_option('woocommerce_kopa-payment_settings')['kopa_merchant_id'])
      )
        return;

      $this->curlInit();
      $this->serverUrl = trim(get_option('woocommerce_kopa-payment_settings')['kopa_server_url']);
      $this->merchantId = get_option('woocommerce_kopa-payment_settings')['kopa_merchant_id'];
    }
  }

  private function curlInit()
  {
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

    $this->initialized = true;
  }
  /**
   * cURL GET function
   */
  public function get($url)
  {
    curl_setopt($this->ch, CURLOPT_URL, $url);
    curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->headers);

    return $this->execute();
  }

  /**
   *  cURL POST function
   */
  public function post($url, $data)
  {
    curl_setopt($this->ch, CURLOPT_URL, $url);
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->headers);
    curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($this->ch, CURLOPT_POSTFIELDS, $data);

    return $this->execute();
  }

  /**
   * cURL DELETE function
   */
  public function delete($url)
  {
    curl_setopt($this->ch, CURLOPT_URL, $url);
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->headers);
    curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

    return $this->execute();
  }

  /**
   * cURL Execute function
   */
  private function execute()
  {
    $response = curl_exec($this->ch);
    if (curl_errno($this->ch)) {
      return 'cURL Error: ' . curl_error($this->ch);
    }
    return $response;
  }

  public function isInitialized()
  {
    return $this->initialized;
  }

  /**
   * Closing cURL 
   */
  public function close()
  {
    if ($this->initialized) {
      curl_close($this->ch);
      $this->initialized = false; // Set the flag to indicate cURL is closed
    }
  }

  /**
   * Function to login end user trough admin pannel
   * Used when admin initialize PostAuth, GetOrderDetails or Refund functions
   */
  private function loginUserByAdmin($userId)
  {
    // If not logged in user use anonymous user
    if ($userId == 0) {
      $username = 'anonymous';
      $password = 'anonymous';
    } else {
      $user = get_user_by('ID', $userId);
      $registerCode = get_user_meta($userId, 'kopa_user_registered_code', true);
      $username = $user->user_login . '_' . $userId . '_' . $registerCode;
      $password = base64_encode($user->user_login . $userId);
    }
    $loginUrl = $this->serverUrl . '/api/auth/login';
    $merchantID = $this->merchantId;

    $data = json_encode([
      'username' => $username,
      'password' => $password,
      'socialMedia' => null,
      'merchantId' => $merchantID
    ]);
    $returnData = json_decode($this->post($loginUrl, $data), true);
    if (isDebugActive(Debug::AFTER_PAYMENT)) {
      echo 'LOGIN ADMIN userId<pre>' . print_r($userId, true) . '</pre>';
      echo 'data<pre>' . print_r($data, true) . '</pre>';
      echo 'returnData<pre>' . print_r($returnData, true) . '</pre>';
    }
    return $returnData;
  }

  /**
   * Login/Register user and add user meta that user is using KOPA platform
   */
  public function login($retry = false)
  {
    if (empty($this->serverUrl)) {
      return;
    }
    if (empty($this->merchantId)) {
      return;
    }
    if ($this->isInitialized() == false) {
      $this->curlInit();
    }

    $loginUrl = $this->serverUrl . '/api/auth/login';
    $merchantID = $this->merchantId;

    if (is_user_logged_in()) {
      $current_user = wp_get_current_user();
      $userId = $current_user->ID;
      $username = $current_user->user_login;
      $registerCode = get_user_meta($userId, 'kopa_user_registered_code', true);
      // Check user metafield if user is already registered on KOPA 
      if (!empty($registerCode)) {
        // if user is registered on KOPA, login user and get access_token
        $data = json_encode([
          'username' => $username . '_' . $userId . '_' . $registerCode,
          'password' => base64_encode($username . $userId),
          'socialMedia' => null,
          'merchantId' => $merchantID
        ]);
      } else {
        // Register user to KOPA and save user meta that user is registered
        $registerCode = rand(10000, 99999);
        $registrationResult = $this->register($username, $userId, $registerCode);
        if ($registrationResult != false) {
          update_user_meta($userId, 'kopa_user_registered_code', $registerCode);
          $this->login();
        }
        return;
      }
    } else {
      // If user is not logged in woocommerce, use anonymus credentials for KOPA platform
      $data = json_encode([
        'username' => 'anonymous',
        'password' => 'anonymous',
        'socialMedia' => null,
        'merchantId' => $merchantID
      ]);
    }

    // POST login data to KOPA
    $returnData = json_decode($this->post($loginUrl, $data), true);

    if (isDebugActive(Debug::BEFORE_PAYMENT)) {
      echo 'LOGIN';
      echo 'data<pre>' . print_r($data, true) . '</pre>';
      echo 'return data<pre>' . print_r($returnData, true) . '</pre>';
      echo 'cURL error<pre>' . print_r(curl_error($this->ch), true) . '</pre>';
    }
    $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    $this->close();

    if (!in_array($httpCode, [200, 201]) && $retry == true) {
      error_log('[KOPA ERROR]: Login error for user with ID ' . $userId);
      return;
    } else if ($httpCode == 401) {
      // Fallback if some data (ex. merchant_id) was changed and session is not valid anymore
      clearSessionOnLogout();
      $registerCode = get_user_meta($userId, 'kopa_user_registered_code', true);
      // Check user metafield if user is already registered on KOPA 
      if (empty($registerCode)) {
        $registerCode = rand(10000, 99999);
        update_user_meta($userId, 'kopa_user_registered_code', $registerCode);
      }
      $registrationResult = $this->register($username, $userId, $registerCode);
      if ($registrationResult != false) {
        $this->login(true);
        exit;
      }
    }

    // Save KOPA user datails in SESSION
    $_SESSION['kopaUserId'] = $returnData['userId'];
    $_SESSION['kopaAccessToken'] = $returnData['access_token'];
    $_SESSION['kopaRefreshToken'] = $returnData['refresh_token'];

    if (isDebugActive(Debug::BEFORE_PAYMENT)) {
      echo 'SESSION<pre>' . print_r($_SESSION, true) . '</pre>';
    }
    return $returnData;
  }

  /**
   * Register function on KOPA platform
   */
  public function register($username, $userId, $registerCode)
  {
    $registerUrl = $this->serverUrl . '/api/auth/register';
    $merchantID = $this->merchantId;

    if ($this->isInitialized() == false) {
      $this->curlInit();
    }

    $data = json_encode([
      'username' => $username . '_' . $userId . '_' . $registerCode,
      'password' => base64_encode($username . $userId),
      'socialMedia' => null,
      'merchantId' => $merchantID
    ]);

    $returnData = json_decode($this->post($registerUrl, $data), true);
    if (isDebugActive(Debug::BEFORE_PAYMENT)) {
      echo 'REGISTER';
      echo 'data<pre>' . print_r($data, true) . '</pre>';
      echo 'return data<pre>' . print_r($returnData, true) . '</pre>';
      echo 'cURL error<pre>' . print_r(curl_error($this->ch), true) . '</pre>';
    }
    $httpcode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    $this->close();
    if (!in_array($httpcode, [200, 201])) {
      error_log('[KOPA ERROR]: Register error for user with ID ' . $userId);
      // Log event
      kopaMessageLog(__METHOD__, '', $userId, '', $returnData);
      // wc_add_notice(__('There was problem with Kopa Payment method', 'kopa-payment') .' - ' . $returnData['message'], 'error');
      return false;
    }
    return true;
  }

  /**
   * Getting secret key for encoding
   */
  public function getPiKey()
  {
    if ($this->isInitialized() == false) {
      $this->curlInit();
    }
    $encodingKeyUrl = $this->serverUrl . '/api/pikey';
    // Add authorization header
    $this->headers[] = 'Authorization: Bearer ' . $_SESSION['kopaAccessToken'];
    $returnData = $this->get($encodingKeyUrl);
    $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    $this->close();
    array_pop($this->headers);

    if (isDebugActive(Debug::BEFORE_PAYMENT)) {
      echo 'GetPiKey';
      echo 'return data<pre>' . print_r($returnData, true) . '</pre>';
      echo 'cURL error<pre>' . print_r(curl_error($this->ch), true) . '</pre>';
      echo 'http Code<pre>' . print_r($httpCode, true) . '</pre>';
    }
    if (!in_array($httpCode, [200, 201]) && !empty(curl_error($this->ch))) {
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
  public function saveCC($encCcNumber, $encCcExpDate, $ccType, $ccAlias)
  {
    if ($this->isInitialized() == false) {
      $this->curlInit();
    }
    $saveCcUrl = $this->serverUrl . '/api/cards';
    $data = json_encode([
      'alias' => $ccAlias,
      'type' => $ccType,
      'userId' => $_SESSION['kopaUserId'],
      'cardNo' => $encCcNumber,
      'expirationDate' => $encCcExpDate
    ]);
    $this->headers[] = 'Authorization: Bearer ' . $_SESSION['kopaAccessToken'];
    $returnData = $this->post($saveCcUrl, $data);
    $decodedReturn = json_decode($returnData, true);
    $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    $this->close();


    if (isDebugActive(Debug::SAVE_CC)) {
      echo 'SAVE CC data<pre>' . print_r($data, true) . '</pre>';
      echo 'cc URL<pre>' . print_r($saveCcUrl, true) . '</pre>';
      echo 'Return data<pre>' . print_r($returnData, true) . '</pre>';
      echo 'headers<pre>' . print_r($this->headers, true) . '</pre>';
      die;
    }

    // Remove the last added header, which is the "Authorization" header
    array_pop($this->headers);

    if (isset($decodedReturn['response']) && $decodedReturn['response'] == 'Error') {
      $this->retryFunctionIfInvalidJwt($httpCode, $returnData, array($this, 'saveCC'), $encCcNumber, $encCcExpDate, $ccType, $ccAlias);
    }
    if (
      $httpCode == 409 &&
      $returnData == 'Card with this alias already exists'
    ) {
      wc_add_notice(__('Card with this alias already exists', 'kopa-payment'), 'notice');
      return false;
    }

    if (isset($decodedReturn['resultCode']) && $decodedReturn['resultCode'] == 'ok')
      return true;
    error_log('[KOPA ERROR]: Error saving CC ');
    wc_add_notice(__('There was problem with Kopa Payment method. Saving CC', 'kopa-payment'), 'error');
    return false;
  }

  /**
   * Deleting CC from KOPA platform
   */
  function deleteCc($ccId)
  {
    if ($this->isInitialized() == false) {
      $this->curlInit();
    }
    $deleteCcUrl = $this->serverUrl . '/api/cards/' . $ccId;
    $this->headers[] = 'Authorization: Bearer ' . $_SESSION['kopaAccessToken'];
    $returnData = $this->delete($deleteCcUrl);
    $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    $this->close();
    // Remove the last added header, which is the "Authorization" header
    array_pop($this->headers);

    $decodedReturn = json_decode($returnData, true);

    if (isset($decodedReturn['response']) && $decodedReturn['response'] == 'Error') {
      $this->retryFunctionIfInvalidJwt($httpCode, $returnData, array($this, 'deleteCc'), $ccId);
    }

    if ($decodedReturn['resultCode'] == 'ok')
      return true;

    error_log('[KOPA ERROR]: Error deleting CC ');
    wc_add_notice(__('There was problem with Kopa Payment method. DELETE CC', 'kopa-payment'), 'error');
    return false;
  }

  /**
   * Getting all user saved CCs from KOPA platform
   */
  public function getSavedCC()
  {
    if ($this->isInitialized() == false) {
      $this->curlInit();
    }
    if (!isset($_SESSION['kopaUserId']))
      return;
    $saveCcUrl = $this->serverUrl . '/api/cards?userId=' . $_SESSION['kopaUserId'];
    $this->headers[] = 'Authorization: Bearer ' . $_SESSION['kopaAccessToken'];
    $returnData = $this->get($saveCcUrl);
    $decodedReturn = json_decode($returnData, true);
    $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    $this->close();

    array_pop($this->headers);
    if (isset($decodedReturn['response']) && $decodedReturn['response'] == 'Error') {
      $this->retryFunctionIfInvalidJwt($httpCode, $returnData, array($this, 'getSavedCC'));
    }
    return $decodedReturn;
  }

  /**
   * Getting CC details by ID
   */
  public function getSavedCcDetails($ccCardId)
  {
    if ($this->isInitialized() == false) {
      $this->curlInit();
    }
    if (!isset($_SESSION['kopaUserId']))
      return;
    $cardDetailsUrl = $this->serverUrl . '/api/cards/' . $ccCardId;
    $this->headers[] = 'Authorization: Bearer ' . $_SESSION['kopaAccessToken'];
    $returnData = $this->get($cardDetailsUrl);
    $decodedReturn = json_decode($returnData, true);
    $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    $this->close();

    array_pop($this->headers);

    // Retry function if invalid JWT
    if (!isset($decodedReturn['resultCode']) || $decodedReturn['resultCode'] !== 'ok') {
      $this->retryFunctionIfInvalidJwt($httpCode, $returnData, array($this, 'getSavedCcDetails'), $ccCardId);
    }
    return $decodedReturn;
  }

  /**
   * When JWT expires, reset it with refresh token
   */
  private function resetAuthToken()
  {
    if ($this->isInitialized() == false) {
      $this->curlInit();
    }
    $successTokenRefresh = true;
    if (isset($_SESSION['kopaRefreshToken']) && !empty($_SESSION['kopaRefreshToken'])) {
      $refreshTokenUrl = $this->serverUrl . '/api/auth/refresh_token';
      $data = json_encode([
        'refresh' => $_SESSION['kopaRefreshToken']
      ]);
      $returnData = $this->post($refreshTokenUrl, $data);
      $httpcode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
      $this->close();
      if (in_array($httpcode, [200, 201])) {
        $_SESSION['kopaAccessToken'] = json_decode($returnData, true)['access_token'];
      } else {
        $successTokenRefresh = false;
      }
    }
    if ($successTokenRefresh == false) {
      $this->login();
    }
    return;
  }

  /**
   * Check if cURL request failed do to expired JWT
   * If httpCode is 401, refresh JWT token
   */
  private function retryFunctionIfInvalidJwt($httpCode, $returnData, $callbackFunction, ...$args)
  {
    if (
      $httpCode == 401 &&
      json_decode($returnData, true)['message'] == 'Invalid JWT token' &&
      is_callable($callbackFunction)
    ) {
      if (isset($_SESSION['kopaRefreshToken']) && !empty($_SESSION['kopaRefreshToken'])) {
        $this->resetAuthToken();
      } else {
        $this->login();
      }
      return call_user_func_array($callbackFunction, $args);
    }

    $current_user = wp_get_current_user();
    $userId = $current_user->ID;
    // Log event
    if (isset(json_decode($returnData, true)['message'])) {
      kopaMessageLog($callbackFunction[1], $_SESSION['orderId'], $userId, $_SESSION['kopaUserId'], $returnData, json_decode($returnData, true)['message'], $_SESSION['kopaOrderId']);
    } else {
      kopaMessageLog($callbackFunction[1], $_SESSION['orderId'], $userId, $_SESSION['kopaUserId'], $returnData, 'ERROR - ' . $httpCode, $_SESSION['kopaOrderId']);
    }
  }

  /**
   * Get bank details for payment
   */
  public function getBankDetails($orderId, $amount, $physicalProduct)
  {
    if ($this->isInitialized() == false) {
      $this->curlInit();
    }
    $bankDetailsUrl = $this->serverUrl . '/api/payment/bank_details';
    $this->headers[] = 'Authorization: Bearer ' . $_SESSION['kopaAccessToken'];

    $data = json_encode(
      [
        'oid' => $orderId,
        'amount' => $amount,
        'physicalProduct' => $physicalProduct,
        'userId' => $_SESSION['kopaUserId']
      ]
    );

    $returnData = $this->post($bankDetailsUrl, $data);
    $decodedReturn = json_decode($returnData, true);
    $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    $this->close();

    array_pop($this->headers);

    if (isset($decodedReturn['response']) && $decodedReturn['response'] == 'Error') {
      // Retry function if invalid JWT
      $this->retryFunctionIfInvalidJwt($httpCode, $returnData, array($this, 'getBankDetails'), $orderId, $amount, $physicalProduct);
    }

    $returnDataDecoded = json_decode($returnData, true);
    return $returnDataDecoded;
  }

  /**
   * MOTO payment
   */
  public function motoPayment($card, $cardId, $amount, $physicalProduct, $kopaOrderId, $traceId, $flagingActive, $installments)
  {
    if ($this->isInitialized() == false) {
      $this->curlInit();
    }
    $motoPaymentUrl = $this->serverUrl . '/api/payment/moto';
    $this->headers[] = 'Authorization: Bearer ' . $_SESSION['kopaAccessToken'];
    $data = [
      'alias' => $card['alias'],
      'expirationDate' => $card['expirationDate'],
      'type' => $card['type'],
      'cardNo' => $card['cardNo'],
      'cardId' => $cardId,
      'userId' => $_SESSION['kopaUserId'],
      'amount' => $amount,
      'physicalProduct' => $physicalProduct,
      'oid' => $kopaOrderId,
      'ccv' => null,
    ];

    if ($flagingActive == true) {
      $data['exemptionFlag'] = '6';
      $data['exemptionSubflag'] = 'C';
      $data['traceId'] = $traceId;
    }
    if ($installments > 0) {
      if ($flagingActive == true)
        $data['exemptionSubflag'] = 'I';
      $data['instalment'] = $installments;
    }

    $dataEncoded = json_encode($data);
    if (isDebugActive(Debug::BEFORE_PAYMENT)) {
      echo 'MOTO Data<pre>' . print_r($dataEncoded, true) . '</pre>';
      return;
    }
    $returnData = $this->post($motoPaymentUrl, $dataEncoded);
    $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    $this->close();
    array_pop($this->headers);

    $decodedReturn = json_decode($returnData, true);
    if ($decodedReturn['response'] == 'Error') {
      // Retry function if invalid JWT
      $this->retryFunctionIfInvalidJwt($httpCode, $returnData, array($this, 'motoPayment'), $card, $cardId, $amount, $physicalProduct, $kopaOrderId);
    }

    $returnDataDecoded = json_decode($returnData, true);
    return $returnDataDecoded;
  }

  /**
   * API Payment
   */
  public function apiPayment($card, $amount, $physicalProduct, $kopaOrderId)
  {
    if ($this->isInitialized() == false) {
      $this->curlInit();
    }
    $apiPaymentUrl = $this->serverUrl . '/api/payment/api';
    $this->headers[] = 'Authorization: Bearer ' . $_SESSION['kopaAccessToken'];
    $data = json_encode(
      [
        'alias' => $card['alias'],
        'expirationDate' => $card['expirationDate'],
        'type' => $card['type'],
        'cardNo' => $card['cardNo'],
        'userId' => $_SESSION['kopaUserId'],
        'ccv' => $card['ccv'],
        'amount' => $amount,
        'physicalProduct' => $physicalProduct,
        'oid' => $kopaOrderId,
      ]
    );
    $returnData = $this->post($apiPaymentUrl, $data);
    $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    $this->close();
    array_pop($this->headers);
    $decodedReturn = json_decode($returnData, true);
    if ($decodedReturn['response'] == 'Error') {
      $this->retryFunctionIfInvalidJwt($httpCode, $returnData, array($this, 'apiPayment'), $card, $amount, $physicalProduct, $kopaOrderId);
    }
    return $decodedReturn;
  }

  /**
   * Changing payment status from PreAuth to PostAuth on MOTO or API payment methods
   * It is triggered on changing order status to "Completed"
   */
  public function postAuth($orderId, $userId)
  {
    if ($this->isInitialized() == false) {
      $this->curlInit();
    }
    $order = wc_get_order($orderId);
    $kopaOrderId = $order->get_meta('kopaIdReferenceId');

    $loginResult = $this->loginUserByAdmin($userId);
    $postAuthUrl = $this->serverUrl . '/api/payment/postauth';
    $this->headers[] = 'Authorization: Bearer ' . $loginResult['access_token'];
    $data = json_encode(
      [
        'oid' => strval($kopaOrderId),
        'userId' => $loginResult['userId'],
      ]
    );
    $returnData = $this->post($postAuthUrl, $data);
    $this->close();
    array_pop($this->headers);

    $decodedReturn = json_decode($returnData, true);
    if ($decodedReturn !== null && json_last_error() === JSON_ERROR_NONE) {
      if ($decodedReturn['response'] == 'Error') {
        // Log event
        kopaMessageLog(__METHOD__, $orderId, $userId, $loginResult['userId'], $decodedReturn['errMsg']);
      }
    } elseif ($returnData === 'Order not found' || $returnData === 'Order is already in PostAuth') {
      $decodedReturn = $returnData;
    }

    return $decodedReturn;
  }


  /**
   * 
   * Getting merchant details about installments and grace period
   * 
   */
  public function getMerchantDetails($userId = 0)
  {
    if ($this->isInitialized() == false) {
      $this->curlInit();
    }

    $loginResult = $this->loginUserByAdmin($userId);
    $merchantDetailsUrl = $this->serverUrl . '/api/merchant/details';
    $this->headers[] = 'Authorization: Bearer ' . $loginResult['access_token'];

    $returnData = $this->get($merchantDetailsUrl);
    $this->close();
    array_pop($this->headers);

    $decodedReturn = json_decode($returnData, true);
    if ($decodedReturn !== null && json_last_error() === JSON_ERROR_NONE) {
      if (isset($decodedReturn['response']) && $decodedReturn['response'] == 'Error') {
        // Log event
        kopaMessageLog(__METHOD__, '', $userId, $loginResult['userId'], $decodedReturn['errMsg']);
      }
    } else {
      $decodedReturn = $returnData;
    }

    return $decodedReturn;
  }


  /**
   * 
   * Checking if card supporsts installments payment
   * 
   */
  public function checkCcBinNumberForInstallments($bin, $userId = 0)
  {
    if ($this->isInitialized() == false) {
      $this->curlInit();
    }

    $loginResult = $this->loginUserByAdmin($userId);
    $merchantDetailsUrl = $this->serverUrl . '/api/payment/check_installments';
    $this->headers[] = 'Authorization: Bearer ' . $loginResult['access_token'];
    $data = json_encode(
      [
        'bin' => $bin,
      ]
    );
    $returnData = $this->post($merchantDetailsUrl, $data);
    $this->close();
    array_pop($this->headers);

    $decodedReturn = json_decode($returnData, true);
    if ($decodedReturn !== null && json_last_error() === JSON_ERROR_NONE) {
      if (isset($decodedReturn['response']) && $decodedReturn['response'] == 'Error') {
        // Log event
        kopaMessageLog(__METHOD__, '', $userId, $loginResult['userId'], $decodedReturn['errMsg']);
      }
    } else {
      $decodedReturn = $returnData;
    }

    return $decodedReturn;
  }

  /**
   * Get order details from KOPA platform
   */
  public function getOrderDetails($kopaOrderId, $userId)
  {
    if ($this->isInitialized() == false) {
      $this->curlInit();
    }
    $loginResult = $this->loginUserByAdmin($userId);
    $orderDetails = $this->serverUrl . '/api/orders/' . $kopaOrderId;

    $this->headers[] = 'Authorization: Bearer ' . $loginResult['access_token'];

    $returnData = $this->get($orderDetails);
    $this->close();
    array_pop($this->headers);

    $returnDataDecoded = json_decode($returnData, true);
    return $returnDataDecoded;
  }

  /**
   * Get Void last step on order KOPA platform
   */
  private function voidLastStepOnOrder($orderId, $userId)
  {
    if ($this->isInitialized() == false) {
      $this->curlInit();
    }
    $order = wc_get_order($orderId);
    $kopaOrderId = $order->get_meta('kopaIdReferenceId');

    $loginResult = $this->loginUserByAdmin($userId);
    $orderDetails = $this->serverUrl . '/api/payment/void';

    $this->headers[] = 'Authorization: Bearer ' . $loginResult['access_token'];
    $data = json_encode(
      [
        'oid' => $kopaOrderId,
        'userId' => $loginResult['userId'],
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
  public function refundCheck($orderId, $userId)
  {
    if ($this->isInitialized() == false) {
      $this->curlInit();
    }
    $order = wc_get_order($orderId);
    $custom_meta_field = $order->get_meta('kopa_payment_method');
    $kopaOrderId = $order->get_meta('kopaIdReferenceId');

    // Check if order payment was done with KOPA system
    if (empty($custom_meta_field)) {
      return ['success' => false, 'message' => __('Order was not paid with KOPA payment method.', 'kopa-payment'), 'isKopa' => false];
    }
    $orderDetails = $this->getOrderDetails($kopaOrderId, $userId);
    if ($orderDetails['transaction'] == null || $orderDetails == "Order not found") {
      return ['success' => false, 'message' => __('Transaction on this order was not completed with KOPA system', 'kopa-payment'), 'isKopa' => true];
    }
    if (isset($orderDetails['trantype']) && $orderDetails['trantype'] == 'PreAuth') {
      return ['success' => false, 'message' => __('Status of the order is PreAuth', 'kopa-payment'), 'isKopa' => true];
    }
    if (isset($orderDetails['trantype']) && $orderDetails['trantype'] == 'Refund') {
      return ['success' => true, 'message' => __('Order has been refunded', 'kopa-payment'), 'isKopa' => true];
    }
  }

  /**
   * Summary of orderTrantypeStatusCheck
   * @param string $orderId
   * @param int $userId
   * @return string|null Returns one of ['PreAuth', 'PostAuth', 'Auth'] or null if no status is found.
   */
  public function orderTrantypeStatusCheck($orderId, $userId)
  {
    $order = wc_get_order($orderId);
    $kopaOrderId = $order->get_meta('kopaIdReferenceId');

    $orderDetails = $this->getOrderDetails($kopaOrderId, $userId);
    if (isset($orderDetails['trantype'])) {
      return $orderDetails['trantype'];
    }
    return false;
  }

  /**
   * Void function for payment, removing last executed action on payment
   * @param string $orderId
   * @param int $userId
   * @return array
   */
  public function orderVoidLastFunction($orderId, $userId)
  {
    $returnData = $this->voidLastStepOnOrder($orderId, $userId);
    if ($returnData['response'] == 'Approved') {
      return ['success' => true, 'response' => 'Approved'];
    }
    return ['success' => false, 'response' => $returnData['errMsg']];
  }

  /**
   * Refunding process
   * Checking if payment is in PostAuth state, if not updating payment status
   * Check if order was already refunded
   * Running refund cURL
   */
  public function refundProcess($orderId, $userId)
  {
    $order = wc_get_order($orderId);
    $kopaOrderId = $order->get_meta('kopaIdReferenceId');

    // check if order is in preAuth state
    $orderDetails = $this->getOrderDetails($kopaOrderId, $userId);
    // If transaction is not equal to NULL
    if ($orderDetails['transaction'] == null || $orderDetails == "Order not found") {
      return ['success' => true, 'response' => __('Order payment was not completed on KOPA', 'kopa-payment')];
    }
    if (isset($orderDetails['trantype']) && $orderDetails['trantype'] == 'PreAuth') {
      // if Order is in PreAuth state, Void last order action will initiate refund
      $returnData = $this->voidLastStepOnOrder($orderId, $userId);
      if ($returnData['success'] == true) {
        $order->update_meta_data('kopaTranType', 'void_success');
        $order->save();
        return ['success' => 'true', 'response' => __('Canceled payment with KOPA system', 'kopa-payment')];
      } else {
        $order->update_meta_data('kopaTranType', 'void_failed');
        $order->save();
        return ['success' => 'false', 'response' => __('Failed voiding payment with KOPA system', 'kopa-payment')];
      }
    }

    // Check transaction status if it was already refunded 
    if (isset($orderDetails['trantype']) && $orderDetails['trantype'] == 'Refund') {
      // Already refunded
      $order->update_meta_data('kopaTranType', 'refund_success');
      $order->save();
      return ['success' => 'true', 'response' => __('Already refunded with KOPA', 'kopa-payment')];
    }

    // Check transaction status if it was already voided 
    if (isset($orderDetails['trantype']) && $orderDetails['trantype'] == 'Void') {
      // Already refunded
      $order->update_meta_data('kopaTranType', 'void_success');
      $order->save();
      return ['success' => 'true', 'response' => __('Already voided with KOPA', 'kopa-payment')];
    }

    if (isset($orderDetails['trantype']) && in_array($orderDetails['trantype'], ['PostAuth', 'Auth'])) {
      // Refund function
      $refundResult = $this->refund($kopaOrderId, $userId, $orderId);
      if ($refundResult['success'] == true && $refundResult['response'] == 'Approved') {
        $order->update_meta_data('kopaTranType', 'refund_success');
        $order->save();
        return ['success' => 'true', 'response' => __('Refunded completed on KOPA system', 'kopa-payment')];
      }
      if ($refundResult['response'] == 'Error' && $refundResult['transaction']['errorCode'] == 'CORE-2504') {
        $order->update_meta_data('kopaTranType', 'refund_success');
        $order->save();
        return ['success' => 'true', 'response' => __('Already refunded with KOPA system', 'kopa-payment')];
      }
    }
    $order->update_meta_data('kopaTranType', 'refund_failed');
    $order->save();
    return ['success' => 'false', 'response' => __('There was a problem with KOPA refund process', 'kopa-payment')];
  }

  /**
   * Refund cURL
   */
  private function refund($kopaOrderId, $userId, $orderId)
  {
    $loginResult = $this->loginUserByAdmin($userId);
    if ($this->isInitialized() == false) {
      $this->curlInit();
    }
    $refundUrl = $this->serverUrl . '/api/payment/refund';
    $this->headers[] = 'Authorization: Bearer ' . $loginResult['access_token'];
    $data = json_encode(
      [
        'oid' => $kopaOrderId,
        'userId' => $loginResult['userId'],
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

  /**
   * *
   * @param mixed $fiscalizationData
   * @return mixed
   */
  public function fiscalization($fiscalizationData, $fiscalizationAuth, $authId)
  {
    if ($this->isInitialized() == false) {
      $this->curlInit();
    }

    $loginResult = $this->loginUserByAdmin(0);
    $fiscalizationUrl = $this->serverUrl . '/api/fiscalization/v2/invoice';
    $this->headers[] = 'Authorization: Bearer ' . $loginResult['access_token'];
    $this->headers[] = 'fiscalizationAuth: ' . $fiscalizationAuth;
    $this->headers[] = 'authId: ' . $authId;
    $data = json_encode($fiscalizationData);
    $returnData = $this->post($fiscalizationUrl, $data);
    $this->close();

    // Remove added Headers
    array_pop($this->headers);
    array_pop($this->headers);
    array_pop($this->headers);

    $decodedReturn = json_decode($returnData, true);

    if ($decodedReturn !== null && json_last_error() === JSON_ERROR_NONE) {
      return $decodedReturn;
    } elseif ($returnData === 'Order not found') {
      $decodedReturn = $returnData;
      return $decodedReturn;
    }
  }


  public function fiscalizationStatus($orderId, $fiscalizationAuth, $authId)
  {
    if ($this->isInitialized() == false) {
      $this->curlInit();
    }

    $loginResult = $this->loginUserByAdmin(0);
    $fiscalizationUrl = $this->serverUrl . '/api/fiscalization/check-order';
    $this->headers[] = 'Authorization: Bearer ' . $loginResult['access_token'];
    $this->headers[] = 'fiscalizationAuth: ' . $fiscalizationAuth;
    $this->headers[] = 'authId: ' . $authId;
    $data = json_encode(['orderId' => $orderId]);
    $returnData = $this->post($fiscalizationUrl, $data);
    $this->close();

    // Remove added Headers
    array_pop($this->headers);
    array_pop($this->headers);
    array_pop($this->headers);

    $decodedReturn = json_decode($returnData, true);

    if ($decodedReturn !== null && json_last_error() === JSON_ERROR_NONE) {
      return $decodedReturn;
    } elseif ($returnData === 'Order not found') {
      $decodedReturn = $returnData;
      return $decodedReturn;
    }
  }

  public function fiscalizationRefund($orderId, $fiscalizationAuth, $authId)
  {
    if ($this->isInitialized() == false) {
      $this->curlInit();
    }

    $loginResult = $this->loginUserByAdmin(0);
    $fiscalizationUrl = $this->serverUrl . '/api/fiscalization/refund';
    $this->headers[] = 'Authorization: Bearer ' . $loginResult['access_token'];
    $this->headers[] = 'fiscalizationAuth: ' . $fiscalizationAuth;
    $this->headers[] = 'authId: ' . $authId;
    $data = json_encode(['orderId' => $orderId]);
    $returnData = $this->post($fiscalizationUrl, $data);
    $this->close();

    // Remove added Headers
    array_pop($this->headers);
    array_pop($this->headers);
    array_pop($this->headers);

    $decodedReturn = json_decode($returnData, true);

    if ($decodedReturn !== null && json_last_error() === JSON_ERROR_NONE) {
      return $decodedReturn;
    } elseif ($returnData === 'Order not found') {
      $decodedReturn = $returnData;
      return $decodedReturn;
    }
  }
}
?>