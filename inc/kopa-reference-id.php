<?php

/**
 * Adding KOPA payment data to My Account/Orders preview
 * adding details to Thank you page
 */
function addKopaOrderIdToMyOrdersPage($order) {
  $kopaReferenceId = $order->get_meta('kopaIdReferenceId');
  $paymentDataSerialized = serializeTransactionDetails($order->get_meta('kopaOrderPaymentData'));
  $paymentCheckup = $order->get_meta('paymentCheckup');

  if(isDebugActive(Debug::AFTER_PAYMENT)){
    echo 'kopaReferenceId<pre>' . print_r($kopaReferenceId, true) . '</pre>';
    echo 'kopaOrderPaymentData<pre>' . print_r($order->get_meta('kopaOrderPaymentData'), true) . '</pre>';
    echo 'paymentDataSerialized<pre>' . print_r($paymentDataSerialized, true) . '</pre>';
    echo 'paymentCheckup<pre>' . print_r($paymentCheckup, true) . '</pre>';
  }
  if(!empty($kopaIdReferenceId) || !empty($paymentDataSerialized)){
    ?>
    <section class="woocommerce-transaction-details">
	    <h2 class="woocommerce-transaction-details__title"><?php _e('Transaction details','kopa-payment');?></h2>
	    <table class="woocommerce-table woocommerce-table--order-details shop_table order_details">
		    <tbody>
            <?php
          if(!empty($kopaReferenceId)){ ?>
            <tr>
              <td><?php echo __('KOPA Reference ID:', 'kopa-payment'); ?></td>
              <td><?php echo esc_html($kopaReferenceId); ?></td>
            </tr>
            <?php }

          if(is_array($paymentDataSerialized)) {
            foreach($paymentDataSerialized as $key => $tranData){
              ?>
                <tr>
                  <td><?php echo $key; ?></td>
                  <td><?php echo $tranData; ?></td>
                </tr>
              <?php
            }
          } ?>
          </tbody>
      </table>
    </section>
    <?php
  }
}
add_action('woocommerce_order_details_after_order_table', 'addKopaOrderIdToMyOrdersPage');



/**
 * Adding kopa payment details to email notifications to Admin and User
 */
function addKopaOrderIdOnEmailTemplate($order, $sent_to_admin, $plain_text, $email) {
  if (in_array($email->id, ['new_order','customer_processing_order', 'customer_completed_order', 'cancelled_order', 'customer_note'])) {
    $kopaReferenceId = $order->get_meta('kopaIdReferenceId');
    $paymentDataSerialized = serializeTransactionDetails($order->get_meta('kopaOrderPaymentData'));
    if (!empty($paymentDataSerialized) && !empty($kopaReferenceId)) {
      ?>
      <h2 style="color:#7f54b3;display:block;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;font-size:18px;font-weight:bold;line-height:130%;margin:0 0 18px;text-align:left"><?php __('Transaction details', 'kopa-payment') ?></h2>
      <table cellspacing="0" cellpadding="6" border="1" style="margin-bottom:20px;color:#636363;border:1px solid #e5e5e5;vertical-align:middle;width:100%;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif" width="100%">
        <tr>
          <td style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left"><?php _e('KOPA Reference ID:', 'kopa-payment') ?></td>
          <td style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left"><?php echo esc_html($kopaReferenceId); ?></td>
        </tr>
      <?php
        foreach($paymentDataSerialized as $key => $tranData){
          ?>
            <tr>
              <td style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left"><?php echo $key; ?></td>
              <td style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left"><?php echo $tranData; ?></td>
            </tr>
          <?php
        } ?>
      </table>
      <?php
    }
  }
}
add_action('woocommerce_email_after_order_table', 'addKopaOrderIdOnEmailTemplate', 10, 4);


// Extends admin panel order search functionality to include kopaIdReferenceId
function extendOrdersSearchWithKopaReferenceId($search_fields) {
  $search_fields[] = 'kopaIdReferenceId';
  return $search_fields;
}
add_filter('woocommerce_shop_order_search_fields', 'extendOrdersSearchWithKopaReferenceId');

/**
 * ADMIN PANEL
 */

/**
 * Adding custom column for KOPA reference ID in admin panel
 */
function add_custom_column($columns) {
  $columns['kopaIdReferenceId'] = 'KOPA ID';
  $columns['kopaPaymentMethod'] = 'KOPA Payment status';
  return $columns;
}
add_filter('manage_edit-shop_order_columns', 'add_custom_column');
add_filter('woocommerce_shop_order_list_table_columns', 'add_custom_column');


/**
 * Adding custom column for KOPA reference ID sortable in admin panel
 */
function makeKopaColumnSortable($sortable_columns) {
  $sortable_columns['kopaIdReferenceId'] = 'kopaIdReferenceId';
  return $sortable_columns;
}
add_filter('manage_edit-shop_order_sortable_columns', 'makeKopaColumnSortable');


if(WC_CUSTOM_ORDERS_TABLE === 'yes'){

  function display_custom_column_value_new($column, $order) {
    if ($column === 'kopaIdReferenceId') {
      $kopaIdReferenceId = $order->get_meta('kopaIdReferenceId', true);
      echo $kopaIdReferenceId;
    }
    if ($column === 'kopaPaymentMethod') {
      $kopaPaymentMethod = $order->get_meta('kopaTranType', true);
      echo $kopaPaymentMethod;
    }
  }
  add_action('woocommerce_shop_order_list_table_custom_column', 'display_custom_column_value_new', 10, 2);
}else{
  /**
   * Display the custom input value in the column
   */
  function display_custom_column_value($column, $post_id) {
    if ($column === 'kopaIdReferenceId') {
      $kopaIdReferenceId = get_post_meta($post_id, 'kopaIdReferenceId', true);
      echo $kopaIdReferenceId;
    }
    if ($column === 'kopaPaymentMethod') {
      $kopaPaymentMethod = get_post_meta($post_id, 'kopaTranType', true);
      echo $kopaPaymentMethod;
    }
  }
  add_action('manage_shop_order_posts_custom_column', 'display_custom_column_value', 10, 2);

}
/**
 * Adding sorting of additional KOPA column in admin panel
 */
function custom_column_sorting($query) {
  if (!is_admin() || !$query->is_main_query()) {
    return;
  }
  
  if ($query->get('orderby') === 'kopaIdReferenceId') {
    $query->set('meta_key', 'kopaIdReferenceId');
    $query->set('orderby', 'meta_value');
  }
}
add_action('pre_get_posts', 'custom_column_sorting');


function serializeTransactionDetails($paymentData){
  $serializedData = [];
  if(!empty($paymentData) && is_array($paymentData)){
    if(isset($paymentData['transaction'])){
      $serializedData[__('Transaction Status', 'kopa-payment')] = $paymentData['response'];
      $serializedData[__('Authorization Code', 'kopa-payment')] = ($paymentData['authCode'])? $paymentData['authCode'] : '-';
      $serializedData[__('Transaction Error Code', 'kopa-payment')] = ($paymentData['errMsg'])? $paymentData['errMsg'] : '-';
      
      if(isset($paymentData['transaction']['transaction'])){
        $serializedData[__('Transaction Status', 'kopa-payment')] = $paymentData['transaction']['response'];
        $serializedData[__('MD Status', 'kopa-payment')] = $paymentData['transaction']['mdStatus'];
        $serializedData[__('Transaction Error Code', 'kopa-payment')] = ($paymentData['transaction']['errMsg'])? $paymentData['transaction']['errMsg'] : '-';
        foreach($paymentData['transaction']['transaction'] as $key => $value){
          switch ($key) {
            case 'date':
              $serializedData[__('Transaction Date', 'kopa-payment')] = gmdate("d/m/Y - H:i:s", $value);
              break;
            case 'transId':
              $serializedData[__('Transaction Id', 'kopa-payment')] = $value;
              break;
            case 'numCode':
              $serializedData[__('Transaction Code', 'kopa-payment')] = $value;
              break;
          }
        }
      }else{
        $serializedData[__('MD Status', 'kopa-payment')] = $paymentData['transaction']['mdStatus'];
        foreach($paymentData['transaction'] as $key => $value){
          switch ($key) {
            case 'date':
              $serializedData[__('Transaction Date', 'kopa-payment')] = gmdate("d/m/Y - H:i:s", $value);
              break;
            case 'transId':
              $serializedData[__('Transaction Id', 'kopa-payment')] = $value;
              break;
            case 'numCode':
              $serializedData[__('Transaction Code', 'kopa-payment')] = $value;
              break;
          }
        }
      }
    }else{
      $serializedData[__('Transaction Status', 'kopa-payment')] = $paymentData['TransStatus'];
      $serializedData[__('Transaction Date', 'kopa-payment')] = gmdate("d/m/Y - H:i:s", $paymentData['TransDate']);
      $serializedData[__('Transaction Id', 'kopa-payment')] = $paymentData['TansId'];
      $serializedData[__('Transaction Code', 'kopa-payment')] = $paymentData['TransNumCode'];
      $serializedData[__('Transaction Error Code', 'kopa-payment')] = ($paymentData['TransErrorCode'])? $paymentData['TransDate'] : '-';
      $serializedData[__('Authorization Code', 'kopa-payment')] = ($paymentData['AuthCode'])? $paymentData['AuthCode'] : '-';;
    }
  }
  return $serializedData;
}

/**
 * Shortcode to be used on custom thank you page
 * [kopa-thank-you-page-details] 
 * or
 * [kopa-thank-you-page-details][order_number][/kopa-thank-you-page-details]
 * if theme provides order number only as shortcode without URL get variable
 */
function kopa_thank_you_page_shortcode($atts, $content = "") {
  if(!empty($content)) {
    $order = wc_get_order($content);
  }
  if(isset($_GET['order_id']) && !empty($_GET['order_id'])){
    $order = wc_get_order($_GET['order_id']);
  }
    
  if ($order) {
    ob_start();
    addKopaOrderIdToMyOrdersPage($order);
    $output = ob_get_clean();
    return $output;
  }
  return;
}
add_shortcode('kopa-thank-you-page-details', 'kopa_thank_you_page_shortcode');