let $ = jQuery.noConflict();

$(document).ready(async function() {
  const kopaIdReferenceId = generateUUID();
  // Format CC number 
  $("body").on("input", "#kopa_cc_number", function() {
    if ($(this).val().length >= 19) {
      $(this).val($(this).val().substring(0, 19))
      return;
    }
    // Remove any non-digit characters
    var creditCardValue = $(this).val().replace(/\D/g, '');

    // Add a minus sign every 4 digits
    var formattedValue = creditCardValue.replace(/(\d{4})/g, '$1 ');

    // Update the input field with the formatted value
    $(this).val(formattedValue);
  });
  
  
  // Add a slash after the first two digits in the expiration date field
  $('body').on('input', '#kopa_cc_exparation_date', function () {
      // Remove non numeric characters
      var value = $(this).val().replace(/\D/g, '');
      // Limit exparation date to 5 characters
      if ($(this).val().length >= 5) {
        $(this).val($(this).val().substring(0, 5))
        return;
      }
      // Add slash sign every 2 digits
      if (value.length >= 2) {
        value = value.substr(0, 2) + '/' + value.substr(2);
      }
      $(this).val(value);
  });

  // Validate the expiration date using the jQuery Validation plugin
  $('.checkout.woocommerce-checkout').validate({
    rules: {
      kopa_cc_exparation_date: {
        required: true,
        dateMMYY: true
      },
      kopa_cc_number: {
        required: true,
        creditcard: true
      },
      kopa_ccv: {
        required: true,
        digits: true,
        minlength: 3,
        maxlength: 4,
      },
      kopa_cc_alias: {
        required: true,
      },
    },
    messages: {
      kopa_cc_exparation_date: {
        required: ajax_checkout_params.validationCCDate,
        dateMMYY: ajax_checkout_params.validationCCDate,
      },
      kopa_cc_number: {
        required: ajax_checkout_params.validationCCNumber,
      },
      kopa_ccv: {
        required: ajax_checkout_params.validationCcvValid,
        digits: ajax_checkout_params.validationDigits,
        minlength: ajax_checkout_params.validationCcvValid,
        maxlength: ajax_checkout_params.validationCcvValid
      },
      kopa_cc_alias: {
        required: ajax_checkout_params.validationCcAlias,
      }
    },
  });

  // Custom validation method for MM/YY format
  $.validator.addMethod('dateMMYY', function (value, element) {
    return /^(0[1-9]|1[0-2])\/\d{2}$/.test(value);
  }, ajax_checkout_params.validationCCDate);


  // Validate CCV number
  $('body').on('input', '#kopa_ccv', function () {
    // Remove non numeric characters
    var value = $(this).val().replace(/\D/g, '');
    // Limit exparation date to 4 characters
    if ($(this).val().length >= 4) {
      $(this).val($(this).val().substring(0, 4))
      return;
    }

    $(this).val(value);
  });

  /**
   * Hiding input fields if user selects saved CC, and checking if card has is3dAuth == true
   */
  $('body').on('change','input[name="kopa_use_saved_cc"]', async function(){
    if($('input[name="kopa_use_saved_cc"]:checked').val() == 'new'){
      $('.kopaCcPaymentInput.optionalNewCcInputs').removeClass('optionalNewCcInputs');
      $('#kopa_ccv_field').show();
    }else if(!$('.kopaCcPaymentInput').hasClass("optionalNewCcInputs")) {
      $('.kopaCcPaymentInput').addClass("optionalNewCcInputs");
      let cardId = $('input[name="kopa_use_saved_cc"]:checked').val();
      let cardDetails = await getCardDetails(cardId);
      let is3dAuth = $.parseJSON(cardDetails).card.is3dAuth;

      if(is3dAuth !== true){
        $('#kopa_ccv_field').show();
      }else{
        $('#kopa_ccv_field').hide();
      }
    }
    
  });
  /**
   * Starts payment process
   */
  $('body').on('click', '#place_order', async function(e){
    e.preventDefault();
    const form = $(this).closest('form');
    // If incognito card and type == dina
    if(
      (
        $('input[name="kopa_use_saved_cc"]:checked').val() == 'new' ||
        typeof $('input[name="kopa_use_saved_cc"]:checked').val() == 'undefined'
      ) &&
      $('input[name="kopa_cc_type"]:checked').val() == 'dina'
    ){
      // use API payment
      let ccNumber = $('#kopa_cc_number').val().replace(/\D/g, '');
      let ccExpDate = $('#kopa_cc_exparation_date').val().replace(/\D/g, '');
      let ccv = $('#kopa_ccv').val().replace(/\D/g, '');
      let secretKey = await getPiKey();
      let encodedCC = encodeCcDetails(ccNumber, ccExpDate, ccv, secretKey);

      form.append(' <input type="hidden" name="paymentType" value="api">'
                  +'<input type="hidden" name="kopaIdReferenceId" value="'+kopaIdReferenceId+'">'
                  +'<input type="hidden" name="encodedCcNumber" value="'+encodedCC.ccEncoded+'">'
                  +'<input type="hidden" name="encodedExpDate" value="'+encodedCC.ccExpDateEncoded+'">'
                  +'<input type="hidden" name="encodedCcv" value="'+encodedCC.ccvEncoded+'">'
                  );
      form.submit();
      return;
    }
    // If incognito card and type != dina
    if(
      (
        $('input[name="kopa_use_saved_cc"]:checked').val() == 'new' ||
        typeof $('input[name="kopa_use_saved_cc"]:checked').val() == 'undefined'
      ) &&
      $('input[name="kopa_cc_type"]:checked').val() != 'dina'
    ){
      // use 3D incognito CC payment
      form.append('<input type="hidden" name="paymentType" value="3d incognito"><input type="hidden" name="kopaIdReferenceId" value="'+kopaIdReferenceId+'">');
      if($('#kopa_save_cc').is(':checked')){
        let ccNumber = $('#kopa_cc_number').val().replace(/\D/g, '');
        let ccExpDate = $('#kopa_cc_exparation_date').val().replace(/\D/g, '');
        let ccv = $('#kopa_ccv').val().replace(/\D/g, '');
        let secretKey = await getPiKey();
        let encodedCC = encodeCcDetails(ccNumber, ccExpDate, ccv, secretKey);
        form.append(' <input type="hidden" name="encodedCcNumber" value="'+encodedCC.ccEncoded+'">'
                  +'<input type="hidden" name="encodedExpDate" value="'+encodedCC.ccExpDateEncoded+'">'
                  +'<input type="hidden" name="encodedCcv" value="'+encodedCC.ccvEncoded+'">'
                  );
      }
      form.submit();
      return;
    }

    try {
      const cardId = $('input[name="kopa_use_saved_cc"]:checked').val();
      const cardDetailsResponse = await getCardDetails(cardId);
      const cardParsed = $.parseJSON(cardDetailsResponse).card;

      const cardAlias = $('label[for="kopa_use_saved_cc_'+cardId+'"]').text();
      const cardNo = cardParsed.cardNo;
      const expirationDate = cardParsed.expirationDate;

      if(
        cardParsed.is3dAuth == false &&
        cardParsed.type !== 'dina'&&
        cardParsed.type !== 'amex'
      ){
        // use 3D payment
        const secretKey = await getPiKey();
        const decodedData = decodeCcDetails(secretKey, cardNo, expirationDate);

        form.append(' <input type="hidden" name="ccNumber" value="'+decodedData.ccDecoded+'">'
                    +'<input type="hidden" name="kopaIdReferenceId" value="'+kopaIdReferenceId+'">'
                    +'<input type="hidden" name="ccExpDate" value="'+decodedData.ccExpDateDecoded+'">'
                    +'<input type="hidden" name="kopa_cc_alias" value="'+cardAlias+'">'
                    +'<input type="hidden" name="is3dAuth" value="'+cardParsed.is3dAuth+'">'
                    +'<input type="hidden" name="paymentType" value="3d">'
                    );
        form.submit();
        return;
      }else if(
        cardParsed.is3dAuth == true &&
        cardParsed.type !== 'dina' &&
        cardParsed.type !== 'amex'
      ){
        //use MOTO payment 
        form.append(' <input type="hidden" name="kopa_cc_alias" value="'+cardAlias+'">'
                    +'<input type="hidden" name="kopaIdReferenceId" value="'+kopaIdReferenceId+'">'
                    +'<input type="hidden" name="is3dAuth" value="'+cardParsed.is3dAuth+'">'
                    +'<input type="hidden" name="paymentType" value="moto">'
                  );
        form.submit();
        return;
      }else if(cardParsed.type == 'dina' || cardParsed.type == 'amex'){
        // use API payment
        let ccNumber = $('#kopa_cc_number').val().replace(/\D/g, '');
        let ccExpDate = $('#kopa_cc_exparation_date').val().replace(/\D/g, '');
        let ccv = $('#kopa_ccv').val().replace(/\D/g, '');
        let secretKey = await getPiKey();
        let encodedCC = encodeCcDetails(ccNumber, ccExpDate, ccv, secretKey);

        form.append(' <input type="hidden" name="paymentType" value="api">'
                    +'<input type="hidden" name="kopaIdReferenceId" value="'+kopaIdReferenceId+'">'
                    +'<input type="hidden" name="encodedCcv" value="'+encodedCC.ccvEncoded+'">'
                    +'<input type="hidden" name="kopa_cc_alias" value="'+cardAlias+'">'
                    );
        form.submit();
        return;
      }
    } catch (error) {
      console.error('An error occurred:', error);
    }
  });

  /**
   * Intercept checkout ajax if 3D payment needs to be completed first
   */
  $(document).ajaxSuccess(function (event, xhr, settings) {
    if(
      settings.url.indexOf("?wc-ajax=checkout") >= 0 && 
      typeof(xhr.responseJSON.socketUrl) != "undefined" && 
      xhr.responseJSON.socketUrl !== null
    ){
      // Call the function to establish the initial socket connection
      $('body').append('<div id="overflowKopaLoader"><div id="kopaLoaderIcon"></div></div>')
      establishSocketConnection(xhr.responseJSON.socketUrl, xhr.responseJSON.roomId, xhr.responseJSON.orderId);
      const browser = window.open('', '_blank');
      browser.document.write(xhr.responseJSON.htmlCode);
      browser.document.forms[0].submit();
    }

    if( settings.url.indexOf("?wc-ajax=update_order_review") >= 0 ){
      updateOrderTotalForKopaPaymentDetails();
      $('body').find('#kopaPaymentDetailsReferenceId').text(kopaIdReferenceId);
    }
  });

  // Show/hide CC alias input if SaveCC is selected
  $('body').on('change', 'input#kopa_save_cc', function() {
    if ($(this).is(':checked')) {
      $('#kopa_cc_alias_field').removeClass('hidden');
    } else {
      $('#kopa_cc_alias_field').addClass('hidden');
    }
  });
});

/**
 * Encoding CC details for payment
 * @param {number} ccNumber 
 * @param {string} ccExpDate 
 * @param {number} CCV 
 * @param {string} secretKey 
 * @returns 
 */
function encodeCcDetails(ccNumber, ccExpDate, CCV, secretKey) {
  const ccEncoded = CryptoJS.AES.encrypt(JSON.stringify(ccNumber), secretKey).toString();
  const ccExpDateEncoded = CryptoJS.AES.encrypt(JSON.stringify(ccExpDate), secretKey).toString();
  const ccvEncoded = CryptoJS.AES.encrypt(JSON.stringify(CCV), secretKey).toString();
  return {ccEncoded, ccExpDateEncoded, ccvEncoded};
}

/**
 * Decoding CC details
 * @param {string} secretKey 
 * @param {string} ccNumberEncoded 
 * @param {string} ccExpDateEncoded 
 * @returns 
 */
function decodeCcDetails(secretKey, ccNumberEncoded, ccExpDateEncoded) {
  let ccDecoded, ccExpDateDecoded; 
  try {
    ccDecoded = JSON.parse(CryptoJS.AES.decrypt(ccNumberEncoded, secretKey).toString(CryptoJS.enc.Utf8)).toString();
    ccExpDateDecoded = JSON.parse(CryptoJS.AES.decrypt(ccExpDateEncoded, secretKey).toString(CryptoJS.enc.Utf8)).toString(); 
    return {ccDecoded, ccExpDateDecoded};
  } catch (error) {
    console.log(error);
    return { ccDecoded: 'Decryption Error', ccExpDateDecoded: 'Decryption Error' };
  }
  
}

/**
 * Gets secret key used for encoding/decoding
 * @returns string
 */
async function getPiKey() {
  try {
    const response = await $.ajax({
      type: 'POST',
      url: ajax_checkout_params.ajaxurl,
      data: {
        action: 'get_secret_key',
        security: ajax_checkout_params.security,
        dataType: 'json',
      },
    });
    const decoded = $.parseJSON(response);
    if (decoded.success) {
      return decoded.key;
    } else {
      // Display error message
      $('.woocommerce-NoticeGroup-checkout').html('<ul class="woocommerce-error"><li>' + decoded.message + '</li></ul>');
    }
  } catch (error) {
    // Handle AJAX or JSON parsing error
    $('.woocommerce-NoticeGroup-checkout').html('<ul class="woocommerce-error"><li>' + error.message + '</li></ul>');
  }
}

/**
 * Getting encoded CC details from KOPA platform
 * @param {string} ccId 
 * @returns json
 */
async function getCardDetails(ccId){
  return $.ajax({
    type: 'POST',
    url: ajax_checkout_params.ajaxurl,
    data: {
      action: 'get_card_details',
      security: ajax_checkout_params.security,
      dataType: 'json',
      ccId,
    },
    success: function (response) {
      try{
        const resDecoded =  $.parseJSON(response);
        return resDecoded.card;
      } catch(e) {
        // not valid JSON
      }
    }
  });
}

/**
 * Sending error message to be saved on order and in error log
 * @param {number} orderId 
 * @param {string} errorMessage 
 */
async function logErrorOnOrderPayment(orderId, errorMessage){
  try {
    const response = await $.ajax({
      type: 'POST',
      url: ajax_checkout_params.ajaxurl,
      data: {
        action: 'error_log_on_payment',
        security: ajax_checkout_params.security,
        dataType: 'json',
        orderId,
        errorMessage,
      },
    });
    if (response.success) {
      $('.woocommerce-NoticeGroup-checkout').html('<ul class="woocommerce-error"><li>' + decoded.response + '</li></ul>');
    }
  } catch (error) {
    // Handle AJAX or JSON parsing error
    console.error(error);
  }
}

/**
 * Establish socket connection for 3D payment
 * @param {string} socketUrl 
 * @param {string} roomId 
 * @param {number} orderId 
 */
function establishSocketConnection(socketUrl, roomId, orderId) {
  let socket = io(socketUrl); // Initialize the socket connection
  socket.on('connect', () => {
    socket.emit('joinRoom', roomId);
  });

  socket.on('notification', async (msg) => {
    $('body').find('#overflowKopaLoader').remove();
    if(
      (msg.success == true && msg.response == 'Approved') ||
      (msg.success == true && msg.response == 'Error' && msg.transaction.errorCode == 'CORE-2507')
    ){
      try {
        const response = await $.ajax({
          type: 'POST',
          url: ajax_checkout_params.ajaxurl,
          data: {
            action: 'complete_3d_payment',
            security: ajax_checkout_params.security,
            dataType: 'json',
            orderId: orderId,
          },
        });
        if (response.success) {
          // Redirect to the thank you page
          window.location.href = response.data.redirect;
        } else {
          // Display error message
          $('.woocommerce-NoticeGroup-checkout').html('<ul class="woocommerce-error"><li>' + response.message + '</li></ul>');
        }
      } catch (error) {
        // Handle AJAX or JSON parsing error
        $('.woocommerce-NoticeGroup-checkout').html('<ul class="woocommerce-error"><li>' + error.message + '</li></ul>');
        logErrorOnOrderPayment(orderId, error);
      }
    }else{
      // Display error message
      $('.woocommerce-NoticeGroup-checkout').html('<ul class="woocommerce-error"><li>' + msg.errMsg + '</li></ul>');
      logErrorOnOrderPayment(orderId, msg.errMsg);
    }
  });
  socket.on('disconnect', function(){
    $('body').find('#overflowKopaLoader').remove();
    $('.woocommerce-NoticeGroup-checkout').html('<ul class="woocommerce-error"><li>'+ajax_checkout_params.paymentError+'</li></ul>');
  } );
  socket.on('connect_error', function(){
    $('body').find('#overflowKopaLoader').remove();
    $('.woocommerce-NoticeGroup-checkout').html('<ul class="woocommerce-error"><li>'+ajax_checkout_params.paymentError+'</li></ul>');
  } );
  socket.on('reconnect_error', function(){
    $('body').find('#overflowKopaLoader').remove();
    $('.woocommerce-NoticeGroup-checkout').html('<ul class="woocommerce-error"><li>'+ajax_checkout_params.paymentError+'</li></ul>');
  } );
}

function updateOrderTotalForKopaPaymentDetails(){
  var orderTotal = $('body').find('.order-total .woocommerce-Price-amount.amount').html();
  $('body').find('#kopaPaymentDetailsTotal').html(orderTotal);
}

function generateUUID() { // Public Domain/MIT
  var d = new Date().getTime();//Timestamp
  var d2 = ((typeof performance !== 'undefined') && performance.now && (performance.now()*1000)) || 0;//Time in microseconds since page-load or 0 if unsupported
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
      var r = Math.random() * 16;//random number between 0 and 16
      if(d > 0){//Use timestamp until depleted
          r = (d + r)%16 | 0;
          d = Math.floor(d/16);
      } else {//Use microseconds since page-load if supported
          r = (d2 + r)%16 | 0;
          d2 = Math.floor(d2/16);
      }
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
  });
}