<?php
/*
  Adding custom page under Woocommerce menu item
*/
function add_custom_admin_menu_item()
{
  add_submenu_page(
    'woocommerce',  // Slug of the parent menu (WooCommerce).
    'Kopa logs',  // Page title.
    'Kopa logs',  // Menu title.
    'manage_options',  // Capability required to access.
    'kopa_logs',  // Menu slug.
    'displayKopaLogs'  // Callback function to display the custom page.
  );
}
add_action('admin_menu', 'add_custom_admin_menu_item');

/**
 * Display log entries 
 */
function displayKopaLogs()
{
  $logEntries = get_option('kopa_log_messages', array());
  // echo '<pre>' . print_r($logEntries, true) . '</pre>';
  ob_start(); ?>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" media="all">
  <style>
    .dataTables_wrapper .dataTables_length select {
      padding-right: 15px !important;
    }
  </style>
  <div class="wrap">
    <h2><?php echo __('Kopa Logs Preview', 'kopa-payment') ?></h2>
    <p><?php echo __('Log preview content', 'kopa-payment') ?></p>

    <table id="tblReportResultsDemographics" class="display" width="100%"></table>
  </div>
  <script>
    let $ = jQuery.noConflict();

    $(document).ready(function () {

      //Load  datatable
      var oTblReport = $("#tblReportResultsDemographics")

      oTblReport.DataTable({
        "data": <?php echo json_encode($logEntries); ?>,
        "columns": [
          {
            "data": "timestamp", "title": "Timestramp", render: function (data, type, row) {
              // Convert timestamp to a readable date format (e.g., YYYY-MM-DD)
              if (type === 'display') {
                const date = new Date(data * 1000);
                const formattedDateTime =
                  `${date.getHours().toString().padStart(2, '0')}:${date.getMinutes().toString().padStart(2, '0')}:${date.getSeconds().toString().padStart(2, '0')} 
                              ${date.getDate().toString().padStart(2, '0')}/${(date.getMonth() + 1).toString().padStart(2, '0')}/${date.getFullYear()}`;
                return formattedDateTime;
              }
              return data;
            },
          },
          { "data": "function", "title": 'Function name' },
          {
            "data": "response", "title": 'Response data', render: function (data, type, row) {
              if (type === 'display') {
                console.log(typeof data);
                if (typeof data == 'object') {
                  data = JSON.stringify(data);
                }
              }
              return data;
            }
          },
          { "data": "message", "title": 'Message' },
          { "data": "userId", "title": "User ID (WP)" },
          { "data": "kopaUserId", "title": "User ID (KOPA)" },
          { "data": "orderId", "title": "Order ID" },
          { "data": "kopaOrderId", "title": "Kopa Order ID" }
        ],
        "draw": 1, // A request identifier (used for paging)
        "order": [[0, 'desc']],
      });
    });
  </script>
  <?php
  echo ob_get_clean();
}

/**
 * Custom logging function
 */
function kopaMessageLog($function, $orderId = '', $userId = '', $kopaUserId = '', $response = '', $message = '', $kopaOrderId = '')
{

  if (is_array($message)) {
    $message = json_encode($message);
  }

  // Load the existing log entries from the database
  $log_entries = get_option('kopa_log_messages', []);
  if (empty($log_entries) || !is_array($log_entries))
    $log_entries = [];

  // Add the new log entry
  $log_entries[] = array(
    'timestamp' => current_time('timestamp'),
    'function' => $function,
    'response' => $response,
    'userId' => $userId,
    'kopaUserId' => $kopaUserId,
    'orderId' => $orderId,
    'message' => $message,
    'kopaOrderId' => $kopaOrderId,
  );

  // Keep only the last 50 entries
  $log_entries = array_slice($log_entries, -50);

  // Update the theme option with the updated log entries
  update_option('kopa_log_messages', $log_entries);
}
