<?php
/**
 * Registering new page on My account for managing CC
 */

function myAccountAddManageCcPage() {
  add_rewrite_endpoint( 'kopa-manage-cc', EP_ROOT | EP_PAGES );
}

add_action( 'init', 'myAccountAddManageCcPage' );

// ------------------
// 2. Add new query var

function supportQueryVarsMyAccountManageCc( $vars ) {
  $vars[] = 'kopa-manage-cc';
  return $vars;
}

add_filter( 'query_vars', 'supportQueryVarsMyAccountManageCc', 0 );

// ------------------
// 3. Insert the new endpoint into the My Account menu

function addLinkToMyAccountManageCc( $items ) {
  $items['kopa-manage-cc'] = __('Manage Credit Cards', 'kopa-payment');
  return $items;
}

add_filter( 'woocommerce_account_menu_items', 'addLinkToMyAccountManageCc' );

// ------------------
// 4. Add content to the new tab

function contentMyAccountManageCc() {
  $kopaCurl = new KopaCurl();
  $savedCc = $kopaCurl->getSavedCC();

  echo '<h3>Premium WooCommerce Support</h3>';
  if (!empty($savedCc)) {
    ob_start(); ?>

    <table border="1">
      <thead>
        <tr>
          <th>Alias</th>
          <th>Type</th>
          <th>Last Four Digits</th>
          <th>Created At</th>
          <th>Delete</th>
        </tr>
      </thead>
      <tbody>
      <?php
      foreach ($savedCc as $row) { 
        $timestamp = strtotime($row['createdAt']);
        $formattedDate = date("d.m.Y", $timestamp);
        ?>
        <tr>
          <td><?php echo $row['alias']; ?></td>
          <td><?php echo $row['type']; ?></td>
          <td><?php echo $row['lastFourDigits']; ?></td>
          <td><?php echo $formattedDate; ?></td>
          <td><button class="kopaDeleteCC" data-cc-id="<?php echo htmlspecialchars($row['id']); ?>">Delete</button></td>
        </tr>          
          <?php
      }
      ?>
      </tbody>
    </table>
    <?php
  } else {
    echo __('There are no saved credit cards.', 'kopa-payment');
  }
}

add_action( 'woocommerce_account_kopa-manage-cc_endpoint', 'contentMyAccountManageCc' );