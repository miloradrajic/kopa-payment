<?php
add_action('woocommerce_order_status_completed', 'kopaFiscalizationOnOrderCompleted');
function kopaFiscalizationOnOrderCompleted($orderId)
{
  $order = wc_get_order($orderId);
  $kopaPaymentMethod = $order->get_meta('kopa_payment_method');
  $kopaOrderId = $order->get_meta('kopaIdReferenceId');
  $kopaOrderTranType = $order->get_meta('kopaTranType');
  $kopaFiscalizationType = $order->get_meta('kopaFiscalizationType');
  $kopaFiscalizationAuthId = get_option('woocommerce_kopa-payment_settings')['kopa_fiscalization_auth_id'];
  $kopaFiscalizationInternalAuth = get_option('woocommerce_kopa-payment_settings')['kopa_fiscalization_internal_id'];

  if (
    !empty($kopaPaymentMethod) && !empty($kopaOrderId) &&
    isset(get_option('woocommerce_kopa-payment_settings')['kopa_enable_fiscalization']) &&
    get_option('woocommerce_kopa-payment_settings')['kopa_enable_fiscalization'] == 'yes' &&
    !empty($kopaFiscalizationAuthId) &&
    !empty($kopaFiscalizationInternalAuth) &&
    $kopaOrderTranType !== 'refund_success' &&
    (empty($kopaFiscalizationType) || $kopaFiscalizationType == 'invoice_failed')
  ) {
    $fiscalizationData = prepareDataForFiscalization($order);
    $kopaCurl = new KopaCurl();
    $fiscalizationResult = $kopaCurl->fiscalization(
      $fiscalizationData,
      $kopaFiscalizationInternalAuth,
      $kopaFiscalizationAuthId
    );

    if (
      isset($fiscalizationResult['success']) &&
      !empty($fiscalizationResult['success']) &&
      $fiscalizationResult['success'] == true
    ) {
      $order->update_meta_data('kopaFiscalizationType', 'invoice_success');
      $order->update_meta_data('kopaFiscalizationInvoiceNumber', $fiscalizationResult['invoiceNumber']);
      $order->update_meta_data('kopaFiscalizationVerificationUrl', $fiscalizationResult['verificationUrl']);
      // Add an order note
      $order->add_order_note(
        __('Kopa fiscalization success.', 'kopa-payment'),
        true
      );
    } else {
      if ($fiscalizationResult['message'] !== 'Invoice with this orderId already exists') {
        // TODO here i need to get the status of the order and from it, update status and notes on order
        $fiscalizationResult = $kopaCurl->fiscalizationStatus(
          $orderId,
          $kopaFiscalizationInternalAuth,
          $kopaFiscalizationAuthId
        );

        if (isset($fiscalizationResult['order']) && !empty($fiscalizationResult['order'])) {
          $order->update_meta_data('kopaFiscalizationInvoiceNumber', $fiscalizationResult['order']['invoiceNumber']);
          $order->update_meta_data('kopaFiscalizationVerificationUrl', $fiscalizationResult['order']['verificationUrl']);
          switch ($fiscalizationResult['order']['transactionType']) {
            case 0: // Invoice
              $order->update_meta_data('kopaFiscalizationType', 'invoice_success');
              break;

            case 1: // Refund
              $order->update_meta_data('kopaFiscalizationType', 'refund_success');
              break;
          }
        }
      } else {

        // TODO save error from $fiscalizationResult['message'] and display it in fiscalization metabox on order
        $order->update_meta_data('kopaFiscalizationType', 'invoice_failed');
        // Add an order note
        $order->add_order_note(
          __('Kopa fiscalization failed.', 'kopa-payment') . ' - ' . $fiscalizationResult['message'],
          true
        );
      }
    }

    // Save changes
    $order->save();
    return;
  }
}

add_action('woocommerce_order_status_refunded', 'kopaFiscalizationRefund');
add_action('woocommerce_order_status_cancelled', 'kopaFiscalizationRefund');
function kopaFiscalizationRefund($orderId)
{
  $order = wc_get_order($orderId);
  $kopaFiscalizationStatus = $order->get_meta('kopaFiscalizationType');
  if ($kopaFiscalizationStatus == 'invoice_success') {
    $kopaFiscalizationAuthId = get_option('woocommerce_kopa-payment_settings')['kopa_fiscalization_auth_id'];
    $kopaFiscalizationInternalAuth = get_option('woocommerce_kopa-payment_settings')['kopa_fiscalization_internal_id'];
    // TODO Send refund fiscalization request
    $kopaCurl = new KopaCurl();
    $fiscalizationResult = $kopaCurl->fiscalizationRefund(
      $order->get_meta('kopaIdReferenceId'),
      $kopaFiscalizationInternalAuth,
      $kopaFiscalizationAuthId
    );
    // Add an order note

    if (
      isset($fiscalizationResult['success']) &&
      !empty($fiscalizationResult['success']) &&
      $fiscalizationResult['success'] == true
    ) {
      $order->add_order_note(
        __('Fiscalization refund sent.', 'kopa-payment'),
        true
      );
      $order->update_meta_data('kopaFiscalizationType', 'refund_success');
      $order->update_meta_data('kopaFiscalizationRefundNumber', $fiscalizationResult['invoiceNumber']);
      $order->update_meta_data('kopaFiscalizationRefundVerificationUrl', $fiscalizationResult['verificationUrl']);
      // Add an order note
    } else {
      $order->update_meta_data('kopaFiscalizationType', 'refund_failed');
      $order->add_order_note(
        __('Fiscalization refund error. Something went wrong', 'kopa-payment'),
        true
      );
    }
    // Save notes on order
    $order->save();
  }
  return;
}


/**
 * Serializing data for fiscalization
 * @param mixed $order
 * @return array
 */
function prepareDataForFiscalization($order)
{
  // Order total
  $orderTotal = $order->get_total();

  // Buyer details
  $buyerName = $order->get_formatted_billing_full_name();
  $buyerEmail = $order->get_billing_email();

  // Items
  $items = [];
  foreach ($order->get_items() as $item_id => $item) {
    // Product details
    $product = $item->get_product();
    $productId = $item_id;
    $productName = $item->get_name();
    $quantity = $item->get_quantity();

    // Unit price including tax
    $unitPrice = wc_get_price_including_tax($product);

    // Total amount (already includes tax)
    $totalAmount = $item->get_total();

    // Add item details to the items array
    $items[] = [
      "gtin" => $productId,
      "name" => $productName,
      "unitLabel" => "kom",
      "quantity" => $quantity,
      "unitPrice" => $unitPrice,
      "totalAmount" => $totalAmount,
      "labels" => getProductTaxLabel($product),
    ];
  }

  return [
    "orderId" => $order->get_meta('kopaIdReferenceId'),
    "payment" => [
      [
        "amount" => $orderTotal,
        "paymentType" => 2
      ]
    ],
    "cashier" => get_option('woocommerce_kopa-payment_settings')['kopa_fiscalization_cashier'],
    "options" => [
      "nazivKupca" => $buyerName,
      "emailToBuyer" => 1,
      "buyerEmailAddress" => $buyerEmail
    ],
    "items" => $items
  ];
}

// Hook into WooCommerce kopa-payment saving action
add_action('woocommerce_update_options_payment_gateways_kopa-payment', 'check_and_set_tax_on_fiscalization_enable');
/**
 * This will check if there are any tax rates, and add default ones that custommer can use on product
 * Tax rate are later used for fiscalization
 * @return void
 */
function check_and_set_tax_on_fiscalization_enable()
{
  if (
    isset(get_option('woocommerce_kopa-payment_settings')['kopa_enable_fiscalization']) &&
    get_option('woocommerce_kopa-payment_settings')['kopa_enable_fiscalization'] == 'yes'
  ) {
    // echo 'ENABLED<pre>' . print_r('kopa_enable_fiscalization', true) . '</pre>';
    // Run the tax rate setup function
    checkAndAddStandardTaxRate();
  }
}

/**
 * Adding default tax rates for products that will be used in fiscalization
 * @return void
 */
function checkAndAddStandardTaxRate()
{
  global $wpdb;

  // Check if any standard tax rates are already set
  $standard_rate_exists = $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class = '' AND tax_rate_name = 'KOPA 20%'"
  );

  // Check if any reduced-rate tax rates are set
  $reduced_rate_exists = $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class = 'reduced-rate' AND tax_rate_name = 'KOPA 10%'"
  );

  // Check if any zero-rate tax rates are set
  $zero_rate_exists = $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class = 'zero-rate' AND tax_rate_name = 'KOPA 0%'"
  );

  // If no standard tax rate is found, add a new one
  if ($standard_rate_exists == 0) {
    $new_standard_rate_data = [
      'tax_rate_country' => '',       // Apply to all countries
      'tax_rate_state' => '',         // Apply to all states
      'tax_rate' => '20.0000',        // 20% tax rate
      'tax_rate_name' => 'KOPA 20%',
      'tax_rate_priority' => 1,
      'tax_rate_compound' => 0,
      'tax_rate_shipping' => 0,       // Do not apply to shipping
      'tax_rate_order' => 1,
      'tax_rate_class' => '',         // Standard rate class
    ];
    $wpdb->insert("{$wpdb->prefix}woocommerce_tax_rates", $new_standard_rate_data);
  }

  // If no reduced-rate tax rate is found, add a new one
  if ($reduced_rate_exists == 0) {
    $new_reduced_rate_data = [
      'tax_rate_country' => '',       // Apply to all countries
      'tax_rate_state' => '',         // Apply to all states
      'tax_rate' => '10.0000',        // 10% tax rate
      'tax_rate_name' => 'Reduced 10%',
      'tax_rate_priority' => 1,
      'tax_rate_compound' => 0,
      'tax_rate_shipping' => 0,       // Do not apply to shipping
      'tax_rate_order' => 1,
      'tax_rate_class' => 'reduced-rate', // Reduced-rate class
    ];
    $wpdb->insert("{$wpdb->prefix}woocommerce_tax_rates", $new_reduced_rate_data);
  }

  // If no zero-rate tax rate is found, add a new one
  if ($zero_rate_exists == 0) {
    $new_zero_rate_data = [
      'tax_rate_country' => '',       // Apply to all countries
      'tax_rate_state' => '',         // Apply to all states
      'tax_rate' => '0.0000',         // 0% tax rate
      'tax_rate_name' => 'Zero Rate',
      'tax_rate_priority' => 1,
      'tax_rate_compound' => 0,
      'tax_rate_shipping' => 0,       // Do not apply to shipping
      'tax_rate_order' => 1,
      'tax_rate_class' => 'zero-rate', // Zero-rate class
    ];
    $wpdb->insert("{$wpdb->prefix}woocommerce_tax_rates", $new_zero_rate_data);
  }
}

/**
 * Setting correct tax label accordint to tax rate
 * @param mixed $product
 * @return array{label: string[]}
 */
function getProductTaxLabel($product)
{
  // Check if taxes are enabled globally
  $taxes_enabled = get_option('woocommerce_calc_taxes') === 'yes';

  $testModeActive = false;
  if (
    isset(get_option('woocommerce_kopa-payment_settings')['kopa_enable_test_mode']) &&
    get_option('woocommerce_kopa-payment_settings')['kopa_enable_test_mode'] == 'yes'
  ) {
    $testModeActive = true;
  }
  // Default label in Serbian Cyrillic UTF-8 for when taxes are disabled
  // Default label in test mode is regular letter A
  $label = $testModeActive ? 'A' : 'А';

  if ($taxes_enabled) {
    // Get the product's tax class (empty for standard rate)
    $tax_class = $product->get_tax_class();
    if ($testModeActive) {
      // A, B, F, E, T, Ж
      switch ($tax_class) {
        case '':
          // Standard rate
          $label = 'A';
          break;
        case 'reduced-rate':
          $label = 'F';
          break;
        case 'zero-rate':
          $label = 'B';
          break;
      }
    } else {
      // Set the label based on the tax class
      switch ($tax_class) {
        case '':
          // Standard rate
          $label = 'Ђ';
          break;
        case 'reduced-rate':
          $label = 'Е';
          break;
        case 'zero-rate':
          $label = 'Г';
          break;
      }
    }
  }

  return [
    [
      "label" => $label
    ]
  ];
}
?>