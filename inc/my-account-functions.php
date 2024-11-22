<?php
/**
 * Registering new page on My account for managing CC
 */

function myAccountAddManageCcPage()
{
  add_rewrite_endpoint('kopa-manage-cc', EP_ROOT | EP_PAGES);
}

add_action('init', 'myAccountAddManageCcPage');

// ------------------
// 2. Add new query var

function supportQueryVarsMyAccountManageCc($vars)
{
  $vars[] = 'kopa-manage-cc';
  return $vars;
}

add_filter('query_vars', 'supportQueryVarsMyAccountManageCc', 0);

// ------------------
// 3. Insert the new endpoint into the My Account menu

function addLinkToMyAccountManageCc($items)
{
  $items['kopa-manage-cc'] = __('Manage Credit Cards', 'kopa-payment');
  return $items;
}

add_filter('woocommerce_account_menu_items', 'addLinkToMyAccountManageCc');

// ------------------
// 4. Add content to the new tab

function contentMyAccountManageCc()
{
  $kopaCurl = new KopaCurl();
  $savedCc = $kopaCurl->getSavedCC();

  echo '<h3>' . __('Manage Credit Cards', 'kopa-payment') . '</h3>';
  if (!empty($savedCc)) {
    ob_start(); ?>

    <table border="1">
      <thead>
        <tr>
          <th><?php _e('Alias', 'kopa-payment'); ?></th>
          <th><?php _e('Type', 'kopa-payment'); ?></th>
          <th><?php _e('Last Four Digits', 'kopa-payment'); ?></th>
          <th><?php _e('Created At', 'kopa-payment'); ?></th>
          <th><?php _e('Delete', 'kopa-payment'); ?></th>
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
            <td><button class="kopaDeleteCC"
                data-cc-id="<?php echo htmlspecialchars($row['id']); ?>"><?php _e('Delete', 'kopa-payment'); ?></button></td>
          </tr>
          <?php
        }
        ?>
      </tbody>
    </table>
    <?php
    echo ob_get_clean();
  } else {
    echo __('There are no saved credit cards.', 'kopa-payment');
  }
  return;
}

add_action('woocommerce_account_kopa-manage-cc_endpoint', 'contentMyAccountManageCc');