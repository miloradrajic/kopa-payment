<?php
/**
 * KOPA Class
 * MC                   5443584545004639         12/24    002
 * VISA                 4841878700002912         12/23    003
 * Dina                 9891040400002365         10/23    001
 * AMEX                 377522900011006          08/23    0000
 */

class WC_KOPA_Payment_Gateway extends WC_Payment_Gateway {
  private $curl, $serverUrl; // Declare the $curl property
  public $errors;
  public function __construct() {
    $this->id = 'kopa_payment';
    $this->method_title = 'KOPA Payment Method';
    $this->method_description = 'KOPA Payment Method description';
    $this->has_fields = true;
    $this->init_form_fields();
    $this->init_settings();
    $this->title = $this->get_option('title');
    $this->curl = new KopaCurl();
    $this->userLoginKopa();
    add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    $this->errors = [];
    if(isset(get_option('woocommerce_kopa_payment_settings')['kopa_server_url'])){
      $this->serverUrl = trim(get_option('woocommerce_kopa_payment_settings')['kopa_server_url']);
    }else{
      $this->errors[] = 'Kopa server URL cannot be empty!';
    }
    if(!empty($this->errors)){
      add_action( 'admin_notices', array($this,'admin_warnings'));
    }
  }
  function admin_warnings($message) {
    // Write some conditional logic here
    foreach($this->errors as $error){
      echo '<div class="notice notice-error">
              <p>'.$error.' <a href="'.get_admin_url().'admin.php?page=wc-settings&tab=checkout&section=kopa_payment">Check here</a></p>
            </div>';
    }
  }
  /**
   * Login user to KOPA platform and save credentials in $_SESSION variable
   * $_SESSION['access_token']
   * $_SESSION['userId']
   * $_SESSION['refresh_token']
   */
  private function userLoginKopa(){
    // Check if user is logged in woocommerce
    if(!empty($this->curl))$this->curl->login();
  }

  /**
   * Adding addtional settings fields in woocommerce payment options for KOPA
   */
  public function init_form_fields() {
    $this->form_fields = 
    [
      'enabled' => [
        'title' => 'Enable/Disable',
        'type' => 'checkbox',
        'label' => 'Enable KOPA Payment Method',
        'default' => 'no',
      ],
      'title' => [
        'title' => 'Title',
        'type' => 'text',
        'description' => 'This is the title that the user sees during checkout.',
        'default' => 'KOPA Payment',
        'desc_tip' => true,
      ],
      'kopa_merchant_id' => [
        'title' => 'Merchant id',
        'type' => 'text',
        'description' => 'Merchant ID. Without this, KOPA payment will not be active',
        'default' => '',
        'desc_tip' => false,
      ],
      'kopa_server_url' => [
        'title' => 'Server URL',
        'type' => 'text',
        'description' => 'Server URL to KOPA system',
        'default' => '',
        'desc_tip' => false,
      ],
      'kopa_api_username' => [
        'title' => 'API Username',
        'type' => 'text',
        'description' => 'API username for banking system',
        'default' => '',
        'desc_tip' => false,
      ],
      'kopa_api_password' => [
        'title' => 'API password',
        'type' => 'text',
        'description' => 'API password for banking system',
        'default' => '',
        'desc_tip' => false,
      ],
      'kopa_api_storekey' => [
        'title' => 'API storekey',
        'type' => 'text',
        'description' => 'API storekey for banking system',
        'default' => '',
        'desc_tip' => false,
      ],
      'title_payment_methods' => array(
        'title' => 'Banking payment methods:', // Title between inputs
        'type'  => 'title',
      ),
      'kopa_api_payment_methods_api' => [
        'title' => '',
        'type' => 'checkbox',
        'label' => 'API & 3D',
        'description' => 'API & 3D Payment method for banking system',
        'default' => '',
        'desc_tip' => false,
      ],
      'kopa_api_payment_methods_moto' => [
        'title' => '',
        'type' => 'checkbox',
        'label' => 'MOTO',
        'description' => 'MOTO Payment method for banking system',
        'default' => '',
        'desc_tip' => false,
      ],
    ];
  }

  /**
   * Adding additional fileds on checkout page
   */
  public function payment_fields() {
    if(
      !isset(get_option('woocommerce_kopa_payment_settings')['kopa_merchant_id']) ||
      empty(get_option('woocommerce_kopa_payment_settings')['kopa_merchant_id'])
    ){
      echo "Merchant ID needs to be entered for this option to be active";
      return;
    }
    if(
      !isset(get_option('woocommerce_kopa_payment_settings')['kopa_server_url']) ||
      empty(get_option('woocommerce_kopa_payment_settings')['kopa_server_url'])
    ){
      echo "Server URL needs to be entered for this option to be active";
      return;
    }
    ?>
    <div id="kopaPaymentIcons">
      <img class="logo-image" src="<?php echo KOPA_PLUGIN_URL; ?>/images/maestro.png" alt="maestro">
      <a href="http://www.mastercard.com/rs/consumer/credit-cards.html" target="_blank" rel="noopener noreferrer">
        <img class="logo-image" src="<?php echo KOPA_PLUGIN_URL; ?>/images/master.png" alt="master">
      </a>
      <img class="logo-image" src="<?php echo KOPA_PLUGIN_URL; ?>/images/dina.png" alt="dina">
      <a href="https://rs.visa.com/pay-with-visa/security-and-assistance/protected-everywhere.html" target="_blank" rel="noopener noreferrer">
        <img class="logo-image" src="<?php echo KOPA_PLUGIN_URL; ?>/images/visa.png" alt="visa">
      </a>
      <a href="https://ledpay.rs" target="_blank" rel="noopener noreferrer">
        <img class="logo-image" src="<?php echo KOPA_PLUGIN_URL; ?>/images/ladPay.png" alt="ladPay">
      </a>
      <a href="https://www.bancaintesa.rs" target="_blank" rel="noopener noreferrer">
        <img class="logo-image" src="<?php echo KOPA_PLUGIN_URL; ?>/images/intesaLogo.png" alt="intesa">
      </a>
      <img class="logo-image" src="<?php echo KOPA_PLUGIN_URL; ?>/images/amex.png" alt="amex">
      <a href="https://rs.visa.com/pay-with-visa/security-and-assistance/protected-everywhere.html" target="_blank" rel="noopener noreferrer">
        <img class="logo-image" src="<?php echo KOPA_PLUGIN_URL; ?>/images/visa-secure.png" alt="visa-secure">
      </a>
      <a href="http://www.mastercard.com/rs/consumer/credit-cards.html" target="_blank" rel="noopener noreferrer">
        <img class="logo-image" src="<?php echo KOPA_PLUGIN_URL; ?>/images/id-check.png" alt="id-check">
      </a>
    </div>
    
    <?php
    $userHaveSavedCcClass = '';
    if ( is_user_logged_in() ) {
      $savedCC = $this->curl->getSavedCC();
      if(is_array($savedCC) && !empty($savedCC)){
        $userHaveSavedCcClass = ' optionalNewCcInputs';
        $ccOptions = ['new' => __('New credit card', 'kopa-payment')];
        foreach($savedCC as $cc){
          $ccOptions[$cc['id']] = $cc['alias'];
        }
        woocommerce_form_field( 
          'kopa_use_saved_cc', array(
          'type'        => 'radio',
          'class'       => array('input-text'),
          'label'       => __('Use saved credit cards', 'kopa-payment'),
          'options'     => $ccOptions,
          'default'     => $savedCC[0]['id'],
          'required' => true,
          ), 
        );
      }
    }
    echo '<div class="kopaCcPaymentInput '.$userHaveSavedCcClass.'">';
    woocommerce_form_field( 
      'kopa_cc_type', array(
      'type'        => 'radio',
      'class'       => array('input-text'),
      'label'       => __('CC Type', 'kopa-payment'),
      'options'     => array(
                        'dynamic' => 'Master Card, Visa, American Express',
                        'dina' => 'Dina'
                      ),
      'default' => 'dynamic'
      ), 
    );
    woocommerce_form_field(
      'kopa_cc_number',
      array(
        'type' => 'text',
        'class' => array('form-row-wide', 'input-text'),
        'label' => __('Credit card number', 'kopa-payment'),
        'placeholder' => 'xxxx-xxxx-xxxx-xxxx',
        'required' => true,
        'clear' => false,
      ),
    );
    woocommerce_form_field(
      'kopa_cc_exparation_date',
      array(
        'type' => 'text',
        'class' => array('form-row-wide', 'input-text'),
        'label' => __('Expiration Date', 'kopa-payment'),
        'placeholder' => 'xx/xx',
        'required' => true,
        'clear' => false,
      ),
    );
    echo '</div>';
    woocommerce_form_field(
      'kopa_ccv',
      array(
        'type' => 'text',
        'class' => array('input-text'),
        'label' => __('CCV', 'kopa-payment'),
        'placeholder' => 'xxx',
        'required' => true,
        'clear' => false,
      ),
    );
    if ( is_user_logged_in() ) {
      echo '<div class="kopaCcPaymentInput '.$userHaveSavedCcClass.'">';
      woocommerce_form_field(
        'kopa_save_cc',
        array(
          'type' => 'checkbox',
          'class' => array('input-checkbox'),
          'label' => __('Save credit card', 'kopa-payment'),
          'required' => false,
        ),
      );
      woocommerce_form_field(
        'kopa_cc_alias',
        array(
          'type' => 'text',
          'class' => array('input-text hidden'),
          'label' => __('CC Alias', 'kopa-payment'),
          'placeholder' => '',
          'required' => true,
          'clear' => false,
        ),
      );
      echo '</div>';
    }
    ?>
    <h4>Payment details</h4>
    <p><strong>Payment total:</strong> <span id="kopaPaymentDetailsTotal"></span></p>
    <p><strong>Payment ID Reference:</strong> <span id="kopaPaymentDetailsReferenceId"></span></p>
    <?php
  }

  /**
   * Processing all function after user completing the order
   */
  public function process_payment($orderId) {
    // Use a payment gateway or API to process the payment.
    $paymentMethod = '';

    $kopaOrderId = $_POST['kopaIdReferenceId'];
    $order = wc_get_order($orderId);
    $_SESSION['orderID'] = $kopaOrderId;
    $physicalProducts = $this->physicalProductsCheck($order);
    $errors = [];

    // Get the custom payment fields value
    $kopa_cc_number = isset($_POST['kopa_cc_number']) ? preg_replace('/\D/', '',sanitize_text_field($_POST['kopa_cc_number'])) : '';
    $kopa_cc_type = isset($_POST['kopa_cc_type']) ? sanitize_text_field($_POST['kopa_cc_type']) : '';

    $kopa_cc_exparation_date = isset($_POST['kopa_cc_exparation_date']) ? preg_replace('/\D/', '',sanitize_text_field($_POST['kopa_cc_exparation_date'])) : '';
    $kopa_ccv = isset($_POST['kopa_ccv']) ? sanitize_text_field($_POST['kopa_ccv']) : '';
    $kopaUseSavedCcId = (isset($_POST['kopa_use_saved_cc']) && $_POST['kopa_use_saved_cc'] !== 'new')? $_POST['kopa_use_saved_cc'] : false;
    
    $kopaSaveCc = (isset($_POST['kopa_save_cc']) && $_POST['kopa_save_cc']== true)? true : false;
    $kopaCcAlias = (isset($_POST['kopa_cc_alias']) )? $_POST['kopa_cc_alias'] : '';

    $orderTotalAmount = $order->get_total();
    
    $savedCard = '';
    if(!empty($kopaUseSavedCcId)){
      $savedCard = $this->curl->getSavedCcDetails($kopaUseSavedCcId);
      $kopa_cc_type = $savedCard['type'];
    }

    if($kopa_cc_type == 'dynamic' && $kopaUseSavedCcId == false){
      $cardTypeCheck = detectCreditCardType($kopa_cc_number, $_POST['kopa_cc_type']);
      if(!$cardTypeCheck) $errors[] = __('Please check CC number and selected CC type.', 'kopa-payment');
      $kopa_cc_type = $cardTypeCheck;
    }
    // Performing validation of custom payment fields
    // Check if using already saved CC
    if (empty($kopa_ccv)) {
      $errors[] = __('Please fill in a valid credit card CCV number.', 'kopa-payment');
    }
    $roomId = $orderId . '_' . rand(1000,9999);
    if($kopaUseSavedCcId == false){
      if (empty($kopa_cc_number)) {
        $errors[] = __('Please fill in a valid credit card number.', 'kopa-payment');
      }else{
        // echo 'validate cc <pre>' . print_r(validateCreditCard($kopa_cc_number), true) . '</pre>';
        if(validateCreditCard($kopa_cc_number) == false) $errors[] = __('Please fill in a valid credit card number.', 'kopa-payment');
      }
      if (empty($kopa_cc_exparation_date)) {
        $errors[] = __('Please fill in a valid credit card exparation date.', 'kopa-payment');
      }else{
        if (
          !preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $kopa_cc_exparation_date) ||
          explode('/',$kopa_cc_exparation_date)[1] < date('y')
          ){
          $errors[] = __('Please fill in a valid credit card exparation date.', 'kopa-payment');
        }
      }
      if (empty($kopa_ccv)) {
        $errors[] = __('Please fill in a valid credit card CCV number.', 'kopa-payment');
      }
      if($kopaSaveCc == true && empty($kopaCcAlias)){
        $errors[] = __('Please enter credit card alias.', 'kopa-payment');
      }

      if(!$this->errorsCheck($errors)){
        return false;
      }
      if(in_array($kopa_cc_type, ['dina', 'amex'])){
        // Start API incognito cc payment
        $paymentMethod = 'api';
        $apiPaymentStatus = $this->startApiPayment($_POST, [], $kopa_cc_type, $orderTotalAmount, $physicalProducts, $orderId);
    
        if($apiPaymentStatus['result'] == 'success'){
          // API PAYMENT SUCCESS
          $order->payment_complete();
          $order->update_meta_data( '_kopa_payment_method', $paymentMethod );
          $order->save();
          // Check if CC needs to be saved 

          if($kopaSaveCc){
            $savedCcResponce = $this->curl->saveCC($_POST['encodedCcNumber'], $_POST['encodedExpDate'], $kopa_cc_type, $kopaCcAlias);
            // if($savedCcResponce) 
          }
          // Redirect to the thank you page
          return [
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
          ];
        }
      }else{
        // 3d incognito cc payment
        $bankDetails = $this->curl->getBankDetails($orderId, $orderTotalAmount, $physicalProducts);
        $htmlCode = $this->generateHtmlFor3DPaymentForm(
          $bankDetails, 
          $_POST['kopa_cc_number'], 
          $_POST['kopa_cc_exparation_date'], 
          $kopaCcAlias, 
          $_POST['kopa_ccv'], 
          $roomId
        );
        $paymentMethod = '3d';
        $order->update_meta_data( '_kopa_payment_method', $paymentMethod );
        $order->save();
        if($kopaSaveCc){
          $savedCcResponce = $this->curl->saveCC($_POST['encodedCcNumber'], $_POST['encodedExpDate'], $kopa_cc_type, $kopaCcAlias);
          // if($savedCcResponce) 
        }

        return [
          'result'    => 'success',
          'messages'  => __('Starting 3D incognito payment', 'kopa-payment'),
          'htmlCode'  => $htmlCode,
          'socketUrl' => $this->serverUrl,
          'roomId'    => $roomId,
          'orderId'   => $orderId,
        ];
      }
    }else{
      // If user selected already saved CC
      
      // Check for errors before any payment
      if(!$this->errorsCheck($errors)){
        return false;
      }
      // Check for CC Type, if amex or dina use API payment
      if(in_array($kopa_cc_type, ['dina', 'amex'])){
        // Start API payment
        $apiPaymentStatus =  $this->startApiPayment($_POST, $savedCard, $kopa_cc_type, $orderTotalAmount, $physicalProducts, $orderId);
    
        if($apiPaymentStatus['result'] == 'success'){
          // API PAYMENT SUCCESS
          $order->payment_complete();
          $paymentMethod = 'api';
          $order->update_meta_data( '_kopa_payment_method', $paymentMethod );
          $order->save();
          // Redirect to the thank you page
          return [
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
          ];
        }
        return [
          'result' => 'failure',
          'message' => 'Error with API payment',
        ];
      }
      
      $decodedCCNumber = $_POST['ccNumber'];
      $decodedExpDate = $_POST['ccExpDate'];
      if($savedCard['is3dAuth'] == false){
        // Init 3D payment
        $bankDetails = $this->curl->getBankDetails($orderId, $orderTotalAmount, $physicalProducts);
        $htmlCode = $this->generateHtmlFor3DPaymentForm($bankDetails, $decodedCCNumber, $decodedExpDate, $kopaCcAlias, $_POST['kopa_ccv'], $roomId);

        $paymentMethod = '3d';
        $order->update_meta_data( '_kopa_payment_method', $paymentMethod );
        $order->save();
        return [
          'result'    => 'success',
          'messages'  => __('Starting 3D payment', 'kopa-payment'),
          'htmlCode'  => $htmlCode,
          'socketUrl' => $this->serverUrl,
          'roomId'    => $roomId,
          'orderId'   => $orderId,
        ];
      }else{
        // Init Moto payment
        $motoPaymentResult = $this->curl->motoPayment(
          $savedCard, 
          
          $kopaUseSavedCcId,
          $orderTotalAmount,
          $physicalProducts,
          $orderId,
        );
        
        if($motoPaymentResult['success'] == true && $motoPaymentResult['response'] == 'Approved'){
          // MOTO PAYMENT SUCCESS
          $order->payment_complete();
          $paymentMethod = 'moto';
          $order->update_meta_data( '_kopa_payment_method', $paymentMethod );
          $order->save();
          return [
            'result' => 'success',
            'messages' => __('Starting moto payment', 'kopa-payment'),
            'redirect' => $this->get_return_url($order),
          ];
        }else{
          wc_add_notice($motoPaymentResult['errMsg'], 'error');
          return false;  
        }
      }
    }
    // NONE OF PAYMENTS WERE ENGAGED
    error_log('[KOPA ERROR]: None of the payment methods were engaged '. $orderId);

    return false;
  }

  /**
   * Generating data for sending to 3D payment proccess
   */
  private function generateHtmlFor3DPaymentForm($bankDetails, $cardNumber, $cardExpDate, $alias, $ccv, $roomId){
    ob_start()
    ?>
    <!DOCTYPE html>
    <html>
    <head><script> window.onload=function(){document.forms["paymentform"].submit();}</script></head>
      <body>
        <form method="post" action="<?php echo $bankDetails['bankUrl']; ?>" id="paymentform">
          <input type="hidden" name="clientid" value="<?php echo $bankDetails['payload']['clientid']; ?>"/>
          <input type="hidden" name="storetype" value="<?php echo $bankDetails['payload']['storetype']; ?>" />
          <input type="hidden" name="hash" value="<?php echo $bankDetails['payload']['hash']; ?>" />
          <input type="hidden" name="trantype" value="<?php echo $bankDetails['payload']['trantype']; ?>" />
          <input type="hidden" name="amount" value="<?php echo $bankDetails['payload']['amount']; ?>" />
          <input type="hidden" name="currency" value="<?php echo $bankDetails['payload']['currency']; ?>" />
          <input type="hidden" name="oid" value="<?php echo $bankDetails['payload']['oid']; ?>" />
          <input type="hidden" name="roomId" value="<?php echo $roomId; ?>" />
          <input type="hidden" name="okUrl" value="<?php echo $bankDetails['payload']['okUrl']; ?>"/>
          <input type="hidden" name="failUrl" value="<?php echo $bankDetails['payload']['failUrl']; ?>" />
          <input type="hidden" name="lang" value="<?php echo $bankDetails['payload']['lang']; ?>" />
          <input type="hidden" name="rnd" value="<?php echo $bankDetails['payload']['rnd']; ?>" />
          <input type="hidden" name="encoding" value="<?php echo $bankDetails['payload']['encoding']; ?>">
          <input type="hidden" name="hashAlgorithm" value="<?php echo $bankDetails['payload']['hashAlgorithm']; ?>">
          <input type="hidden" name="bankMerchantId" value="<?php echo $bankDetails['payload']['bankMerchantId']; ?>">
          <input type="hidden" name="appname" value="<?php echo $bankDetails['payload']['appname']; ?>">
          <input type="hidden" name="kopaCycleId" value="<?php echo $bankDetails['payload']['kopaCycleId']; ?>">
          <input type="hidden" name="physicalProduct" value="<?php echo $bankDetails['payload']['physicalProduct']; ?>">
          <input type="hidden" name="userId" value="<?php echo $bankDetails['payload']['userId']; ?>">
          <input type="hidden" name="cardAlias" value="<?php echo $alias; ?>">
          <input type="hidden" name="pan" value="<?php echo $cardNumber; ?>">
          <input type="hidden" name="Ecom_Payment_Card_ExpDate_Year" value="<?php echo substr($cardExpDate, -2); ?>" >
          <input type="hidden" name="Ecom_Payment_Card_ExpDate_Month" value="<?php echo substr($cardExpDate, 0, 2); ?>">
          <input type="hidden" name="cv2" value="<?php echo $ccv; ?>">
        </form>
      </body>
    </html>

    <?php
    return ob_get_clean();
  }

  /**
   * Check if any of the products in cart is physical
   */
  private function physicalProductsCheck($order){
    foreach ($order->get_items() as $item) {
      $product = $item->get_product();
      // Check if the product is not virtual or not downloadable
      if (!$product->is_downloadable() && !$product->is_virtual()) {
        return 'true';
      }
    }
    return 'false';
  }

  /**
   * Adding frontend notices if any input has validation error
   */
  private function errorsCheck($errors){
  // If there are any errors, stop prosses before payment_complete()
    if(!empty($errors)){
      foreach($errors as $error){
        wc_add_notice($error, 'error');
      }
      return false;
    }
    return true;
  }

  /**
   * API Payment proccess 
   */
  private function startApiPayment($post, $card, $type, $orderTotalAmount, $physicalProducts, $orderId){
    if(empty($card)){
      $apiEncodedCcNumber = $post['encodedCcNumber'];
      $apiEncodedExpDate = $post['encodedExpDate'];
    }else{
      $apiEncodedCcNumber = $card['cardNo'];
      $apiEncodedExpDate = $card['expirationDate'];
    }
    $apiEncodedCcv = $post['encodedCcv'];
    $data = [
      'alias' => $post['kopa_cc_alias'],
      'expirationDate' =>$apiEncodedExpDate,
      'type' => $type,
      'cardNo' => $apiEncodedCcNumber, 
      'ccv' => $apiEncodedCcv,
    ];

    $apiPaymentResult = $this->curl->apiPayment(
      $data,
      $orderTotalAmount,
      $physicalProducts,
      $orderId,
    );

    if($apiPaymentResult['success'] == true && $apiPaymentResult['response'] == 'Approved'){
      return [
        'result' => 'success',
        'messages' => 'API payment success',
      ];
    }else{
      if($apiPaymentResult['transaction']['errorCode'] == 'CORE-2507'){
        // Order has already successful transaction
        return [
          'result' => 'success',
          'messages' => 'API payment success',
        ]; 
      }
      wc_add_notice($apiPaymentResult['errMsg'], 'error');
      return [
        'result' => 'failure',
        'message' => 'Error with starting API payment',
      ];  
    }
  }
}