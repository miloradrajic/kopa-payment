<?php
add_action('woocommerce_order_status_completed', 'kopaFiscalizationOnOrderCompleted');
function kopaFiscalizationOnOrderCompleted($orderId)
{
  $order = wc_get_order($orderId);
  $user_id = $order->get_user_id();
  $kopaPaymentMethod = $order->get_meta('kopa_payment_method');
  $kopaOrderId = $order->get_meta('kopaIdReferenceId');

  $fiscalizationData = prepareDataForFiscalization($order);
  $kopaCurl = new KopaCurl();
  $fiscalizationResult = $kopaCurl->fiscalization($fiscalizationData);

  echo 'fiscalizationResult<pre>' . print_r($fiscalizationResult, true) . '</pre>';
  die;
}


function prepareDataForFiscalization($order)
{
  return [
    "orderId" => "4ac20440-bf5e-11ee-a506-0242ac120093",
    "payment" => [
      [
        "amount" => 20,
        "paymentType" => 0
      ]
    ],
    "cashier" => "Pera Perić",
    "options" => [
      "nazivKupca" => "Milorad Rajic",
      "emailToBuyer" => 1,
      "buyerEmailAddress" => "milorad.rajic@tp.rs"
    ],
    "items" => [
      [
        "gtin" => "43831346692304",
        "name" => "Apples",
        "unitLabel" => "kg",
        "quantity" => 2,
        "unitPrice" => 10,
        "totalAmount" => 20,
        "labels" => [
          [
            "label" => "A"
          ]
        ]
      ]
    ]
  ];
}
?>