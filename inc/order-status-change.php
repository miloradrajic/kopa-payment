<?php

// Calling refund function on KOPA refund and adding order note with result
function kopaRefundActionCallback($order_id) {
  $order = wc_get_order($order_id);
  $user_id = $order->get_user_id();
  // $order_id = $order->get_id();
  $custom_metadata = get_post_meta($order_id, '_kopa_payment_method', true);
  // Check if the custom metadata exists and if payment was done with MOTO or API payment
  if (!empty($custom_metadata)) {
    // Refund function
    $kopaCurl = new KopaCurl();
    $refundResult = $kopaCurl->refundProcess($order_id, $user_id);
    
    if(
      !empty($refundResult) && 
      isset($refundResult['success']) && 
      $refundResult['success'] == true  
      ){
      // Set the order status back to the previous status
      $order->set_status('refunded');
    }

    // Recheck refund proccess and add note 
    // $refundCheck = $kopaCurl->refundCheck($order_id, $user_id);

    $note = $refundResult['response'];
    $order->add_order_note($note);
    $order_notes = $order->get_customer_order_notes();
    $note_to_remove = 'Order status set to refunded. To return funds to the customer you will need to issue a refund through your payment gateway.';
    foreach ($order_notes as $note_id => $note) {
      if (strpos($note->comment_content, $note_to_remove) !== false) {
        // Remove the order note
        wc_delete_order_note($note_id);
      }
    }
    // Save changes
    $order->save();
  }
}
add_action( 'woocommerce_order_status_refunded', 'kopaRefundActionCallback', 999 );



// When order is completed change status to PostAuth on KOPA system
function kopaPostAuthOnOrderCompleted( $order_id ) {
  $order = wc_get_order($order_id);
  $user_id = $order->get_user_id();
  $custom_metadata = get_post_meta($order_id, '_kopa_payment_method', true);
  $note = '';

	// Check if the custom metadata exists and if payment was done with MOTO or API payment
  if (!empty($custom_metadata)) {
    $kopaCurl = new KopaCurl();
    $postAuthResult = $kopaCurl->postAuth($order_id, $user_id);
    if($postAuthResult['success'] == true && $postAuthResult['response'] == 'Approved'){
      // Add an order note
      $note = __('Order has been completed on KOPA system.', 'kopa-payment');
      $order->add_order_note($note);
    }else{
      // Get the previous order status
      $previous_status = $order->get_status_before('completed');
      if (!empty($previous_status)) {
        // Set the order status back to the previous status
        $order->set_status($previous_status);
      }

      // Check transaction type on order
      $tranType = $kopaCurl->orderTrantypeStatusCheck($order_id, $user_id);
      if($tranType == 'Void'){
        $note = __('Order could not be completed on KOPA because order transaction was set to Void', 'kopa-payment');
      }
      if($tranType == 'Refund'){
        $note = __('Order could not be completed on KOPA because order transaction was set to Refund', 'kopa-payment');
      }
      $note = __('Order could not be completed on KOPA because PostAuth has failed', 'kopa-payment');
      $order->add_order_note($note);
    }
    // Save changes for notes
    $order->save();
  }
	return;
}
add_action( 'woocommerce_order_status_completed', 'kopaPostAuthOnOrderCompleted' );


// Calling VOID function on KOPA if order is in PreAuth state
function kopaCancelFunction($order_id) {
  $order = wc_get_order($order_id);
  $user_id = $order->get_user_id();
  $custom_metadata = get_post_meta($order_id, '_kopa_payment_method', true);
  // Check if the custom metadata exists and if payment was done with KOPA
  if (!empty($custom_metadata)) {
    $kopaCurl = new KopaCurl();

    // Check transaction type on order
    $tranType = $kopaCurl->orderTrantypeStatusCheck($order_id, $user_id);
    if($tranType == 'PreAuth'){
      // VOID function, canceling last step on order
      $voidResult = $kopaCurl->orderVoidLastFunction($order_id, $user_id);

      // VOID function was a success
      if($voidResult['success'] == true){
        $note = __('Order was set to be refunded in KOPA system.', 'kopa-payment');
        $order->add_order_note($note);
      }else{
        // VOID function failed
        $note = __('There was an error canceling order in KOPA system.', 'kopa-payment');
        $order->add_order_note($note);
      }

      // Save note changes on order
      $order->save();  
    }
    if($tranType == 'Void'){
      $note = __('Order was already set to be refunded in KOPA system.', 'kopa-payment');
      $order->add_order_note($note);

      // Save note changes on order
      $order->save();  
    }
  }
}
add_action( 'woocommerce_order_status_cancelled', 'kopaCancelFunction' );