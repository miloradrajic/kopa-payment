let $ = jQuery.noConflict();
$(document).ready(async function() {
  // Function to delete CC from KOPA system
  $('body').on('click', '.kopaDeleteCC', async function(e){
    let ccId = $(this).data('cc-id');
    if (confirm(ajax_my_account_params.confirmCardDelete + '?')) {
      let cardDeleteResponse = await deleteCard(ccId);
      if(cardDeleteResponse.success === true) {
        location.reload(true);
      }
    }
  });
});

/**
 * Delete saved CC 
 * @param {string} ccId 
 * @returns 
 */
async function deleteCard(ccId){
  return $.ajax({
    type: 'POST',
    url: ajax_my_account_params.ajaxurl,
    data: {
      action: 'delete_card',
      security: ajax_my_account_params.security,
      dataType: 'json',
      ccId,
    },
    success: function (response) {
      try{
        // console.log(response)
        return response;
      } catch(e) {
        // not valid JSON
      }
    }
  });
}