<?php
class KOPA_Payment extends WC_Payment_Gateway {
  private $curl, $serverUrl; // Declare the $curl property
  public $errors;
  public function __construct() {
    $this->id = 'kopa-payment';
    $this->method_title = __('KOPA Payment Method', 'kopa-payment');
    $this->method_description = __('KOPA Payment Method description', 'kopa-payment');
    $this->has_fields = true;
    $this->init_form_fields();
    $this->init_settings();
    $this->title = $this->get_option('title');
    $this->curl = new KopaCurl();
    add_action( 'woocommerce_before_checkout_form', [$this, 'userLoginKopa']);
    add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    $this->errors = [];
    if(
      !isset(get_option('woocommerce_kopa-payment_settings')['kopa_server_url']) || 
      empty(get_option('woocommerce_kopa-payment_settings')['kopa_server_url'])
    ){
      $this->errors[] = __('Kopa server URL cannot be empty!', 'kopa-payment');
    }else{
      $this->serverUrl = trim(get_option('woocommerce_kopa-payment_settings')['kopa_server_url']);
    }
    if(
      !isset(get_option('woocommerce_kopa-payment_settings')['kopa_merchant_id']) ||
      empty(get_option('woocommerce_kopa-payment_settings')['kopa_merchant_id'])
    ){
      $this->errors[] = 'Kopa merchant ID cannot be empty!';      
    }
    if(!empty($this->errors)){
      add_action( 'admin_notices', array($this,'admin_warnings'));
    }
    add_action('after_setup_theme', array($this, 'load_textdomain'));
  }

   /*
	 * Function that will load translations from the language files in the /languages folder in the root folder of the plugin.
	 */
  public function load_textdomain(){
    load_plugin_textdomain('kopa-payment', false, KOPA_PLUGIN_PATH . 'languages/');
    
    // Find proper locale
    $locale = get_locale();
    if( is_user_logged_in() ) {
      if( $user_locale = get_user_locale( get_current_user_id() ) ) {
        $locale = $user_locale;
      }
    }
    
    // Get locale
    $locale = apply_filters( 'woo_kopa_payment_plugin_locale', $locale, 'kopa-payment');
    
    // We need standard file
    $mofile = sprintf( '%s-%s.mo', 'kopa-payment', $locale );
    
    // Check first inside `/wp-content/languages/plugins`
    $domain_path = path_join( WP_LANG_DIR, 'plugins' );
    $loaded = load_textdomain( 'kopa-payment', path_join( $domain_path, $mofile ) );
    // Or inside `/wp-content/languages`
    if ( ! $loaded ) {
      $loaded = load_textdomain( 'kopa-payment', path_join( WP_LANG_DIR, $mofile ) );
    }
    
    // Or inside `/wp-content/plugin/kopa-payment/languages`
    if ( ! $loaded ) {
      $domain_path = KOPA_PLUGIN_PATH . '/languages';
      $loaded = load_textdomain( 'kopa-payment', path_join( $domain_path, $mofile ) );
      
      // Or load with only locale without prefix
      if ( ! $loaded ) {
        $loaded = load_textdomain( 'kopa-payment', path_join( $domain_path, "{$locale}.mo" ) );
      }

      // Or old fashion way
      if ( ! $loaded && function_exists('load_plugin_textdomain') ) {
        load_plugin_textdomain( 'kopa-payment', false, $domain_path );
      }
    }
  }
  
  function admin_warnings($message) {
    foreach($this->errors as $error){
      echo '<div class="notice notice-error">
              <p>'.$error.' <a href="'.get_admin_url().'admin.php?page=wc-settings&tab=checkout&section=kopa_payment">Check here</a></p>
            </div>';
    }
  }
  /**
   * Login user to KOPA platform and save credentials in $_SESSION variable
   */
  public function userLoginKopa(){
    // Check if user is logged in woocommerce
    if(
      !isset($_SESSION['kopaAccessToken']) || 
      empty($_SESSION['kopaAccessToken'])
      ){
      $this->curl->login();
    }
  }

  /**
   * Adding addtional settings fields in woocommerce payment options for KOPA
   */
  public function init_form_fields() {
    $this->form_fields = 
    [
      'title' => [
        'title' => __('Title', 'kopa-payment'),
        'type' => 'text',
        'description' => __('This is the title that the user sees during checkout.', 'kopa-payment'),
        'default' => 'KOPA Payment',
        'desc_tip' => true,
      ],
      'kopa_merchant_id' => [
        'title' => __('Merchant id', 'kopa-payment'),
        'type' => 'text',
        'description' => __('Merchant ID. Without this, KOPA payment will not be active', 'kopa-payment'),
        'default' => '',
        'desc_tip' => false,
      ],
      'kopa_server_url' => [
        'title' => __('Server URL', 'kopa-payment'),
        'type' => 'text',
        'description' => __('Server URL to KOPA system', 'kopa-payment'),
        'default' => '',
        'desc_tip' => false,
      ],
      'kopa_api_username' => [
        'title' => __('API Username', 'kopa-payment'),
        'type' => 'text',
        'description' => __('API username for banking system', 'kopa-payment'),
        'default' => '',
        'desc_tip' => false,
      ],
      'kopa_api_password' => [
        'title' => __('API password', 'kopa-payment'),
        'type' => 'text',
        'description' => __('API password for banking system', 'kopa-payment'),
        'default' => '',
        'desc_tip' => false,
      ],
      'kopa_api_storekey' => [
        'title' => __('API storekey', 'kopa-payment'),
        'type' => 'text',
        'description' => __('API storekey for banking system', 'kopa-payment'),
        'default' => '',
        'desc_tip' => false,
      ],
      'title_payment_methods' => array(
        'title' => __('Banking payment methods:', 'kopa-payment'), // Title between inputs
        'type'  => 'title',
      ),
      'kopa_api_payment_methods_api' => [
        'title' => '',
        'type' => 'checkbox',
        'label' => 'API & 3D',
        'description' => __('API & 3D Payment method for banking system', 'kopa-payment'),
        'default' => '',
        'desc_tip' => false,
      ],
      'kopa_api_payment_methods_moto' => [
        'title' => '',
        'type' => 'checkbox',
        'label' => 'MOTO',
        'description' => __('MOTO Payment method for banking system', 'kopa-payment'),
        'default' => '',
        'desc_tip' => false,
      ],
      
    ];
    if(current_user_can('administrator')){
      $this->form_fields['kopa_debug'] = [
        'title'=> 'Debug KOPA',
        'type' => 'multiselect',
        'description' => __('Debug KOPA', 'kopa-payment'),
        'options' => array(
          'no' => __('Inactive', 'kopa-payment'),
          'after_payment' => __('After payment (3D)', 'kopa-payment'),
          'before_payment' => __('Global (payment will not work, it will only return sent values)', 'kopa-payment'),
          'save_cc' => __('Saving CC', 'kopa-payment'),
        ),
      ];
    };
  }

  /**
   * Adding additional fileds on checkout page
   */
  public function payment_fields() {
    if(
      !isset(get_option('woocommerce_kopa-payment_settings')['kopa_merchant_id']) ||
      empty(get_option('woocommerce_kopa-payment_settings')['kopa_merchant_id'])
    ){
      _e('Merchant ID needs to be entered for this option to be active', 'kopa-payment');
      return;
    }
    if(
      !isset(get_option('woocommerce_kopa-payment_settings')['kopa_server_url']) ||
      empty(get_option('woocommerce_kopa-payment_settings')['kopa_server_url'])
    ){
      _e('Server URL needs to be entered for this option to be active', 'kopa-payment');
      return;
    }
    if(!isset($_SESSION['kopaAccessToken']) || empty($_SESSION['kopaAccessToken'])){
      _e('There was problem with Kopa Payment method', 'kopa-payment');
      return;
    }
    ?>
    <div id="kopaPaymentIcons">
      <img class="logo-image" src="<?php echo KOPA_PLUGIN_URL; ?>/images/maestro.png" alt="maestro">
      <a href="https://www.mastercard.rs/sr-rs/korisnici/pronadite-karticu.html" target="_blank" rel="noopener noreferrer">
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
      <a href="https://www.mastercard.rs/sr-rs/korisnici/pronadite-karticu.html" target="_blank" rel="noopener noreferrer">
        <img class="logo-image" src="<?php echo KOPA_PLUGIN_URL; ?>/images/id-check.png" alt="id-check">
      </a>
    </div>
    
    <?php
    $userHaveSavedCcClass = '';
    if ( is_user_logged_in() ) {
      $savedCC = $this->curl->getSavedCC();
      if(is_array($savedCC) && !empty($savedCC)){
        // $userHaveSavedCcClass = ' optionalNewCcInputs';
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
          'default'     => 'new',
          'required'    => true
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
        'placeholder' => 'xxxx xxxx xxxx xxxx',
        'required' => true,
        'clear' => true,
      ),
    );
    woocommerce_form_field(
      'kopa_cc_exparation_date',
      array(
        'type' => 'text',
        'class' => array('form-row-wide', 'input-text'),
        'label' => __('Exparation Date', 'kopa-payment'),
        'placeholder' => 'xx/xx',
        'required' => true,
        'clear' => true,
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
        'clear' => true,
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
          'clear' => true,
        ),
      );
      echo '</div>';
    }
    ?>
    <h4><?php echo __('Payment details', 'kopa-payment'); ?></h4>
    <p><strong><?php echo __('Payment total:', 'kopa-payment'); ?></strong> <span id="kopaPaymentDetailsTotal"></span></p>
    <p><strong><?php echo __('Payment description:', 'kopa-payment'); ?></strong> <span id="kopaPaymentDetailsReferenceId"></span></p>
    <div class="kopaPciDssIcon"><img class="logo-image" src="<?php echo KOPA_PLUGIN_URL; ?>/images/pci-dss.svg" alt="id-check"></div>
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
    $_SESSION['orderId'] = $orderId;
    $_SESSION['kopaOrderId'] = $kopaOrderId;
    $physicalProducts = $this->isPhysicalProducts($order);

    update_post_meta($orderId, 'kopaIdReferenceId', $kopaOrderId);
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
    if (empty($kopa_ccv) && $savedCard['is3dAuth'] == false) {
      $errors[] = __('Please fill in a valid credit card CCV number.', 'kopa-payment');
    }
    // $roomId = $orderId . '_' . rand(1000,9999);
    if($kopaUseSavedCcId == false){
      if (empty($kopa_cc_number)) {
        $errors[] = __('Please fill in a valid credit card number.', 'kopa-payment');
      }else{
        if(validateCreditCard($kopa_cc_number) == false) $errors[] = __('Please fill in a valid credit card number.', 'kopa-payment');
      }
      if (empty($kopa_cc_exparation_date)) {
        $errors[] = __('Please fill in a valid credit card exparation date.', 'kopa-payment');
      }else{
        if (
          substr($kopa_cc_exparation_date, 0, 2) > 12 ||
          substr($kopa_cc_exparation_date, -2) < date('y') || 
          (substr($kopa_cc_exparation_date, -2) == date('y') && substr($kopa_cc_exparation_date, 0, 2) < date('m'))
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
        $apiPaymentStatus = $this->startApiPayment($_POST, [], $kopa_cc_type, $orderTotalAmount, $physicalProducts, $kopaOrderId);
    
        if($apiPaymentStatus['result'] == 'success'){
          // API PAYMENT SUCCESS
          $order->payment_complete();
          $order->update_meta_data( '_kopa_payment_method', $paymentMethod );
          // Add an order note
          $note = __('Order has been paid with KOPA system', 'kopa-payment');
          $order->add_order_note($note);
          
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
          
          // Check if CC needs to be saved 
          if($kopaSaveCc){
            if (isDebugActive(Debug::SAVE_CC)) {
              echo 'SAVING CC function API payment';
            }
            $savedCcResponce = $this->curl->saveCC($_POST['encodedCcNumber'], $_POST['encodedExpDate'], $kopa_cc_type, $kopaCcAlias);
            if($savedCcResponce != true){
              return false;
            }
          }
          // Redirect to the thank you page
          return [
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
          ];
        }
      }else{
        // 3d incognito cc payment
        $bankDetails = $this->curl->getBankDetails($kopaOrderId, $orderTotalAmount, $physicalProducts);
        $htmlCode = $this->generateHtmlFor3DPaymentForm(
          $bankDetails, 
          str_replace(' ', '', $_POST['kopa_cc_number']), 
          $_POST['kopa_cc_exparation_date'], 
          $kopaCcAlias, 
          $_POST['kopa_ccv'], 
          $orderId
        );
        $paymentMethod = '3d';
        $order->update_meta_data( '_kopa_payment_method', $paymentMethod );
        $order->save();
        if($kopaSaveCc){
          if (isDebugActive(Debug::SAVE_CC)) {
            echo 'SAVING CC function 3d payment';
          }
          $savedCcResponce = $this->curl->saveCC($_POST['encodedCcNumber'], $_POST['encodedExpDate'], $kopa_cc_type, $kopaCcAlias);
          if($savedCcResponce != true){
            return false;
          }
        }
        if (isDebugActive(Debug::BEFORE_PAYMENT)) {
          echo '<pre>' . print_r($htmlCode, true) . '</pre>';
          return;
        }
        return [
          'result'    => 'success',
          'messages'  => __('Starting 3D incognito payment', 'kopa-payment'),
          'htmlCode'  => $htmlCode,
          'orderId'   => $kopaOrderId,
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
        $apiPaymentStatus =  $this->startApiPayment($_POST, $savedCard, $kopa_cc_type, $orderTotalAmount, $physicalProducts, $kopaOrderId);
    
        if($apiPaymentStatus['result'] == 'success'){
          // API PAYMENT SUCCESS
          $order->payment_complete();
          $paymentMethod = 'api';
          $order->update_meta_data( '_kopa_payment_method', $paymentMethod );
          // Add an order note
          $note = __('Order has been paid with KOPA system', 'kopa-payment');
          $order->add_order_note($note);

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
          // Redirect to the thank you page
          return [
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
          ];
        }
        return [
          'result' => 'failure',
          'message' => __('Error with API payment', 'kopa-payment'),
        ];
      }
      
      if($savedCard['is3dAuth'] == false){
        // Init 3D payment
        $decodedCCNumber = $_POST['ccNumber'];
        $decodedExpDate = $_POST['ccExpDate'];
        $bankDetails = $this->curl->getBankDetails($kopaOrderId, $orderTotalAmount, $physicalProducts);
        $htmlCode = $this->generateHtmlFor3DPaymentForm(
          $bankDetails, 
          $decodedCCNumber, 
          $decodedExpDate, 
          $kopaCcAlias, 
          $_POST['kopa_ccv'], 
          $orderId);
        
        $paymentMethod = '3d';
        $order->update_meta_data( '_kopa_payment_method', $paymentMethod );
        
        $order->save();
        return [
          'result'    => 'success',
          'messages'  => __('Starting 3D payment', 'kopa-payment'),
          'htmlCode'  => $htmlCode,
          'orderId'   => $kopaOrderId,
        ];
      }else{
        // Init Moto payment
        $motoPaymentResult = $this->curl->motoPayment(
          $savedCard, 
          
          $kopaUseSavedCcId,
          $orderTotalAmount,
          $physicalProducts,
          $kopaOrderId,
        );
        
        if($motoPaymentResult['success'] == true && $motoPaymentResult['response'] == 'Approved'){
          // MOTO PAYMENT SUCCESS
          $order->payment_complete();
          $paymentMethod = 'moto';
          $order->update_meta_data( '_kopa_payment_method', $paymentMethod );
          // Add an order note
          $note = __('Order has been paid with KOPA system', 'kopa-payment');
          $order->add_order_note($note);

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
    error_log('[KOPA ERROR]: None of the payment methods were engaged '. $kopaOrderId);

    return false;
  }

  /**
   * Generating data for sending to 3D payment proccess
   */
  private function generateHtmlFor3DPaymentForm($bankDetails, $cardNumber, $cardExpDate, $alias, $ccv, $orderId){
    ob_start()
    ?>
    <form method="post" action="<?php echo $bankDetails['bankUrl']; ?>" id="paymentform" target="_self">
      <input type="hidden" name="clientid" value="<?php echo $bankDetails['payload']['clientid']; ?>"/>
      <input type="hidden" name="storetype" value="<?php echo $bankDetails['payload']['storetype']; ?>" />
      <input type="hidden" name="hash" value="<?php echo $bankDetails['payload']['hash']; ?>" />
      <input type="hidden" name="trantype" value="<?php echo $bankDetails['payload']['trantype']; ?>" />
      <input type="hidden" name="amount" value="<?php echo $bankDetails['payload']['amount']; ?>" />
      <input type="hidden" name="currency" value="<?php echo $bankDetails['payload']['currency']; ?>" />
      <input type="hidden" name="oid" value="<?php echo $bankDetails['payload']['oid']; ?>" />
      <input type="hidden" name="okUrl" value="<?php echo $bankDetails['payload']['okUrl']; ?>"/>
      <input type="hidden" name="failUrl" value="<?php echo $bankDetails['payload']['failUrl']; ?>" />
      <input type="hidden" name="lang" value="<?php echo $bankDetails['payload']['lang']; ?>" />
      <input type="hidden" name="rnd" value="<?php echo $bankDetails['payload']['rnd']; ?>" />
      <input type="hidden" name="encoding" value="<?php echo $bankDetails['payload']['encoding']; ?>">
      <input type="hidden" name="hashAlgorithm" value="<?php echo $bankDetails['payload']['hashAlgorithm']; ?>">
      <input type="hidden" name="bankMerchantId" value="<?php echo $bankDetails['payload']['bankMerchantId']; ?>">
      <input type="hidden" name="appname" value="<?php echo $bankDetails['payload']['appname']; ?>">
      <input type="hidden" name="kopaCycleId" value="<?php echo $bankDetails['payload']['kopaCycleId']; ?>">
      <input type="hidden" name="cardAlias" value="<?php echo $alias; ?>">
      <input type="hidden" name="physicalProduct" value="<?php echo $bankDetails['payload']['physicalProduct']; ?>">
      <input type="hidden" name="userId" value="<?php echo $bankDetails['payload']['userId']; ?>">
      <input type="hidden" name="pan" value="<?php echo $cardNumber; ?>">
      <input type="hidden" name="Ecom_Payment_Card_ExpDate_Year" value="<?php echo substr($cardExpDate, -2); ?>" >
      <input type="hidden" name="Ecom_Payment_Card_ExpDate_Month" value="<?php echo substr($cardExpDate, 0, 2); ?>">
      <input type="hidden" name="cv2" value="<?php echo $ccv; ?>">
      <input type="hidden" name="resURL" value="<?php echo get_home_url(get_current_blog_id(), 'kopa-payment-data/accept-order/'.$orderId); ?>">
      <input type="hidden" name="redirectURL" value="<?php echo get_home_url(get_current_blog_id(), 'kopa-payment-data/accept-order/'.$orderId); ?>">
    </form>
    <?php
    return ob_get_clean();
  }

  /**
   * Check if any of the products in cart is physical
   */
  public function isPhysicalProducts($order){
    foreach ($order->get_items() as $item) {
      $product = $item->get_product();
      // Check if the product is not virtual or not downloadable
      if (!$product->is_downloadable() && !$product->is_virtual()) {
        return true;
      }
    }
    return false;
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
        'messages' => __('API payment success', 'kopa-payment'),
      ];
    }else{
      if($apiPaymentResult['transaction']['errorCode'] == 'CORE-2507'){
        // Order has already successful transaction
        return [
          'result' => 'success',
          'messages' => __('API payment success', 'kopa-payment'),
        ]; 
      }
      wc_add_notice($apiPaymentResult['errMsg'], 'error');
      return [
        'result' => 'failure',
        'message' => __('Error with starting API payment', 'kopa-payment'),
      ];  
    }
  }
}