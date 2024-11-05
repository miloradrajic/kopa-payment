let $ = jQuery.noConflict();
$(document).ready(async function() {
  // Initial state on page load
  toggleAdditionalFields();

  // Toggle fields when checkbox is clicked
  $('#woocommerce_kopa-payment_kopa_enable_fiscalization').on('change', function() {
    toggleAdditionalFields();
  });
});

function toggleAdditionalFields() {
  if ($('#woocommerce_kopa-payment_kopa_enable_fiscalization').is(':checked')) {
    $('.toggle-additional-fields').closest('tr').show();
  } else {
    $('.toggle-additional-fields').closest('tr').hide();
  }
}