<?php

/**
 * Adding KOPA payment reference ID to My Account/Orders preview
 */
function addKopaOrderIdToMyOrdersPage($order) {
  $custom_meta_field = $order->get_meta('kopaIdReferenceId');
  if(!empty($custom_meta_field)){ ?>
    <tr>
      <th><?php echo __('KOPA Reference ID:', 'kopa-payment'); ?></th>
      <td><?php echo esc_html($custom_meta_field); ?></td>
    </tr>
    <?php }
}
add_action('woocommerce_order_details_after_order_table_items', 'addKopaOrderIdToMyOrdersPage');



/**
 * Adding kopa payment reference ID to email notifications to Admin and User
 */
function addKopaOrderIdOnEmailTemplate($order, $sent_to_admin, $plain_text, $email) {
  if (in_array($email->id, ['new_order','customer_processing_order'])) {
    $custom_meta_field = $order->get_meta('kopaIdReferenceId');
    if ($custom_meta_field) {
      echo '<p>
              <strong>' . __('KOPA Reference ID:', 'kopa-payment') . ' </strong>
              <span>' . esc_html($custom_meta_field) . '</span>
            </p>';
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
 * Adding KOPA reference ID to Thank You Page
 */
function addKopaOrderIdOnThankYouPage($orderId) {
  $custom_meta_field = get_post_meta($orderId, 'kopaIdReferenceId', true);
  if(!empty($custom_meta_field)){ ?>
    <tr>
      <th><?php echo __('KOPA Reference ID:', 'kopa-payment'); ?></th>
      <td><?php echo esc_html($custom_meta_field); ?></td>
    </tr>
    <?php }
}
add_action('woocommerce_thankyou', 'addKopaOrderIdOnThankYouPage');



/**
 * ADMIN PANEL
 */

/**
 * Adding custom column for KOPA reference ID in admin panel
 */
function add_custom_column($columns) {
  $columns['kopaIdReferenceId'] = 'KOPA ID';
  return $columns;
}
add_filter('manage_edit-shop_order_columns', 'add_custom_column');


/**
 * Adding custom column for KOPA reference ID sortable in admin panel
 */
function makeKopaColumnSortable($sortable_columns) {
  $sortable_columns['kopaIdReferenceId'] = 'kopaIdReferenceId';
  return $sortable_columns;
}
add_filter('manage_edit-shop_order_sortable_columns', 'makeKopaColumnSortable');

/**
 * Display the custom input value in the column
 */
function display_custom_column_value($column, $post_id) {
  if ($column === 'kopaIdReferenceId') {
    $kopaIdReferenceId = get_post_meta($post_id, 'kopaIdReferenceId', true);
    echo $kopaIdReferenceId;
  }
}
add_action('manage_shop_order_posts_custom_column', 'display_custom_column_value', 10, 2);


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