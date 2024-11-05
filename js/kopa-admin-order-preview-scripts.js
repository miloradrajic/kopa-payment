let $ = jQuery.noConflict();
$(document).ready(async function() {
  // Initial state on page load
  toggleAdditionalFields();

  // Toggle fields when checkbox is clicked
  $('#woocommerce_kopa-payment_kopa_enable_fiscalization').on('change', function() {
    toggleAdditionalFields();
  });

  $('.kopaRefundQuantity').on('change', function(e){
    const quantity = $(this).val();
    const maxQuantity = $(this).prop('max');
    const refundTotalElement = $(this).closest('.kopaRefundItem').find('.kopaItemRefundTotal');
    const id = $(this).closest('.kopaRefundItem').data('prod-id');
    const alreadyRefunded = $('#kopa_already_refunded_'+id).val();
    if(quantity > maxQuantity - alreadyRefunded){
      $(this).val(0);
      $(this).closest('span').append('<div class="kopaInvalidRefundQuantityValue">'+orderKopaParam.invalidQuantityForRefund+'</div>');
      refundTotalElement.text('')
      return false;
    }
    
    const price = $(this).closest('.kopaRefundItem').data('prod-price');
    refundTotalElement.text(quantity * price) 
  });
});

function toggleAdditionalFields() {
  if ($('#woocommerce_kopa-payment_kopa_enable_fiscalization').is(':checked')) {
    $('.toggle-additional-fields').closest('tr').show();
  } else {
    $('.toggle-additional-fields').closest('tr').hide();
  }
}