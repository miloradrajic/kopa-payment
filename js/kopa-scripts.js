let $ = jQuery.noConflict();

$(document).ready(async function() {
  const kopaIdReferenceId = generateUUID();
  // Format CC number 
  $("body").on("input", "#kopa_cc_number", function() {
    // Continue with the rest of the input handling logic
    if ($(this).val().length >= 19) {
      $(this).val($(this).val().substring(0, 19));
      return;
    }
  
    // Remove any non-digit characters
    var creditCardValue = $(this).val().replace(/\D/g, '');
  
    // Add a space every 4 digits
    var formattedValue = creditCardValue.replace(/(\d{4})/g, '$1 ');
  
    // Update the input field with the formatted value
    $(this).val(formattedValue);
  });
  
  // Handle the keydown event to capture the backspace key
  $("body").on("keydown", "#kopa_cc_number", function(e) {
    console.log(e)
    if (e.key === 'Backspace') {
      // Remove the last character and the preceding space, if any
      var currentValue = $(this).val();
      var trimmedValue = currentValue.replace(/(\d)(\s)?$/, '');
  
      // Update the input field with the modified value
      $(this).val(trimmedValue);
  
      // Prevent the default backspace behavior (navigating back in the browser)
      e.preventDefault();
  
      return false;
    }
  });
  
  
  
  // Add a slash after the first two digits in the expiration date field
  $('body').on('keydown', '#kopa_cc_exparation_date', function (e) {
    var key = e.keyCode || e.charCode;
    if(key != 8 && key != 46){
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
    }
  });

  // Validate the expiration date using the jQuery Validation plugin
  $('form.checkout').validate({
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
    highlight: function(element, errorClass, validClass) {
      // Remove the 'woocommerce-invalid' class when an error is fixed
      $(element).closest('p').removeClass('woocommerce-invalid');
    },
    unhighlight: function(element, errorClass, validClass) {
      // Remove the 'woocommerce-invalid' class when the field is valid
      $(element).closest('p').removeClass('woocommerce-invalid');
    },
    success: function (label, element) {
      // hide the tooltip
      $(element).removeClass('error');
      $(element).siblings('.label').remove();
    },
    onkeyup: function(element, event) {
      this.element(element);
      if (!this.valid()) {
        $(element).closest('p').addClass('woocommerce-invalid');
        $(element).addClass('error');
      } else {
        $(element).closest('p').removeClass('woocommerce-invalid');
        $(element).remove('error');
      }
    },
  });

  // Custom validation method for MM/YY format
  $.validator.addMethod('dateMMYY', function (value, element) {
    const currMonth = parseInt(new Date().getMonth().toString()) +1;
    const currYear = parseInt(new Date().getFullYear().toString().substr(-2));
    const month = parseInt(value.substr(0, 2));
    const year = parseInt(value.substr(-2)); 

    if(/^(0[1-9]|1[0-2])\/\d{2}$/.test(value)){
      if(month > 12) return false;
      if(year < currYear) return false;
      if( year == currYear && month < currMonth ) return false;
      return true;
    }
    return false;
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
  let startingCcValue = $('input[name="kopa_use_saved_cc"]:checked').val();
  $('body').on('change','input[name="kopa_use_saved_cc"]', async function(e){
    if($('input[name="kopa_use_saved_cc"]:checked').val() == startingCcValue ) return;
    startingCcValue = $('input[name="kopa_use_saved_cc"]:checked').val();
    if($('input[name="kopa_use_saved_cc"]:checked').val() == 'new'){
      $('.kopaCcPaymentInput.optionalNewCcInputs').removeClass('optionalNewCcInputs');
      $('#kopa_ccv_field').show();
    }else{
      if(!$('.kopaCcPaymentInput').hasClass("optionalNewCcInputs")) {
        $('.kopaCcPaymentInput').addClass("optionalNewCcInputs");
      }
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

  $(".woocommerce-checkout").submit(function () {
    // Remove a class to the submit button
    $('#place_order').removeClass('disabled');

    // Proceed with form submission
    return true;
});
  /**
   * Starts payment process
   */
  $('body').on('click', '#place_order', async function(e){
    if($('input[name=payment_method]:checked').val() == 'kopa-payment'){
      e.preventDefault();
      // Disable checkout button
      $('#place_order').addClass('disabled');
      // Remove all previous displayed errors 
      $('.customKopaError').remove();

      const form = $(this).closest('form');
      const usingSavedOrNew = $('input[name="kopa_use_saved_cc"]:checked').val();
      const $noticesMessageWrapper = $('.woocommerce-notices-wrapper').first();
      // If there are saved cards and "NEW" card is selected, or there are no saved cards
      if(usingSavedOrNew == 'new' || typeof usingSavedOrNew == "undefined"){

        let ccNumber = $('#kopa_cc_number').val().replace(/\D/g, '');
        let ccExpDate = $('#kopa_cc_exparation_date').val().replace(/\D/g, '');
        let ccv = $('#kopa_ccv').val().replace(/\D/g, '');
        // const cardType = await getCardType(ccNumber, $('input[name="kopa_cc_type"]:checked').val());
        // Requested no CC checkup 
        const cardType = await getCardType(ccNumber, 'dynamic');

        if(cardType.success == false){
          // Display error message
          $noticesMessageWrapper.html('<div class="woocommerce-error wc-block-components-notice-banner is-error customKopaError">'
          +'<span>' + cardType.message + '</span></div>');
          $('#place_order').addClass('disabled');
          $('html, body').animate({
            scrollTop: $('.woocommerce-notices-wrapper').offset().top - 50
          }, 500);

          // Enable order button
          $('#place_order').removeClass('disabled');
          return;
        }

        // use API payment
        if(cardType.cardType == 'dina' || cardType.cardType == 'amex'){
          let secretKey = await getPiKey();
          let encodedCC = encodeCcDetails(ccNumber, ccExpDate, ccv, secretKey);
          form.append(' <input type="hidden" class="additionalKopaInput" name="paymentType" value="api">'
                      +'<input type="hidden" class="additionalKopaInput" name="kopaIdReferenceId" value="'+kopaIdReferenceId+'">'
                      +'<input type="hidden" class="additionalKopaInput" name="encodedCcNumber" value="'+encodedCC.ccEncoded+'">'
                      +'<input type="hidden" class="additionalKopaInput" name="encodedExpDate" value="'+encodedCC.ccExpDateEncoded+'">'
                      +'<input type="hidden" class="additionalKopaInput" name="encodedCcv" value="'+encodedCC.ccvEncoded+'">'
                      +'<input type="hidden" class="additionalKopaInput" name="kopa_cc_type" value="'+cardType.cardType+'">'
                      );
          form.submit();
          form.find('.additionalKopaInput').remove();
          return;
        }
        // If incognito card and type != dina
        if(cardType.cardType != 'dina') {
          // use 3D incognito CC payment
          form.append('<input type="hidden" name="paymentType" value="3d"><input type="hidden" name="kopaIdReferenceId" value="'+kopaIdReferenceId+'">');
          if($('#kopa_save_cc').is(':checked')){
            let ccNumber = $('#kopa_cc_number').val().replace(/\D/g, '');
            let ccExpDate = $('#kopa_cc_exparation_date').val().replace(/\D/g, '');
            let ccv = $('#kopa_ccv').val().replace(/\D/g, '');
            let secretKey = await getPiKey();
            let encodedCC = encodeCcDetails(ccNumber, ccExpDate, ccv, secretKey);
            form.append('<input type="hidden" class="additionalKopaInput" name="encodedCcNumber" value="'+encodedCC.ccEncoded+'">'
                      +'<input type="hidden" class="additionalKopaInput" name="encodedExpDate" value="'+encodedCC.ccExpDateEncoded+'">'
                      +'<input type="hidden" class="additionalKopaInput" name="encodedCcv" value="'+encodedCC.ccvEncoded+'">'
                      +'<input type="hidden" class="additionalKopaInput" name="kopa_cc_type" value="'+cardType.cardType+'">'
                      );
          }
          form.submit();
          form.find('.additionalKopaInput').remove();
          return;
        }

      }else{
        // Payment with saved card
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

            form.append(' <input type="hidden" class="additionalKopaInput" name="ccNumber" value="'+decodedData.ccDecoded+'">'
                        +'<input type="hidden" class="additionalKopaInput" name="kopaIdReferenceId" value="'+kopaIdReferenceId+'">'
                        +'<input type="hidden" class="additionalKopaInput" name="ccExpDate" value="'+decodedData.ccExpDateDecoded+'">'
                        +'<input type="hidden" class="additionalKopaInput" name="kopa_cc_alias" value="'+cardAlias+'">'
                        +'<input type="hidden" class="additionalKopaInput" name="is3dAuth" value="'+cardParsed.is3dAuth+'">'
                        +'<input type="hidden" class="additionalKopaInput" name="paymentType" value="3d">'
                        +'<input type="hidden" class="additionalKopaInput" name="kopa_cc_type" value="'+cardParsed.type+'">'
                        );
            form.submit();
            form.find('.additionalKopaInput').remove();
            return;
          }else if(
            cardParsed.is3dAuth == true &&
            cardParsed.type !== 'dina' &&
            cardParsed.type !== 'amex'
          ){
            //use MOTO payment 
            form.append(' <input type="hidden" class="additionalKopaInput" name="kopa_cc_alias" value="'+cardAlias+'">'
                        +'<input type="hidden" class="additionalKopaInput" name="kopaIdReferenceId" value="'+kopaIdReferenceId+'">'
                        +'<input type="hidden" class="additionalKopaInput" name="is3dAuth" value="'+cardParsed.is3dAuth+'">'
                        +'<input type="hidden" class="additionalKopaInput" name="paymentType" value="moto">'
                        +'<input type="hidden" class="additionalKopaInput" name="kopa_cc_type" value="'+cardParsed.type+'">'
                      );
            form.submit();
            form.find('.additionalKopaInput').remove();
            return;
          }else if(cardParsed.type == 'dina' || cardParsed.type == 'amex'){
            // use API payment
            let ccNumber = $('#kopa_cc_number').val().replace(/\D/g, '');
            let ccExpDate = $('#kopa_cc_exparation_date').val().replace(/\D/g, '');
            let ccv = $('#kopa_ccv').val().replace(/\D/g, '');
            let secretKey = await getPiKey();
            let encodedCC = encodeCcDetails(ccNumber, ccExpDate, ccv, secretKey);
            form.append(' <input type="hidden" class="additionalKopaInput" name="paymentType" value="api">'
                        +'<input type="hidden" class="additionalKopaInput" name="kopaIdReferenceId" value="'+kopaIdReferenceId+'">'
                        +'<input type="hidden" class="additionalKopaInput" name="encodedCcNumber" value="'+encodedCC.ccEncoded+'">'
                        +'<input type="hidden" class="additionalKopaInput" name="encodedExpDate" value="'+encodedCC.ccExpDateEncoded+'">'
                        +'<input type="hidden" class="additionalKopaInput" name="encodedCcv" value="'+encodedCC.ccvEncoded+'">'
                        +'<input type="hidden" class="additionalKopaInput" name="kopa_cc_alias" value="'+cardAlias+'">'
                        +'<input type="hidden" class="additionalKopaInput" name="kopa_cc_type" value="'+cardParsed.type+'">'
                        );
            form.submit();
            form.find('.additionalKopaInput').remove();
            return;
          }
        } catch (error) {
          console.error('An error occurred:', error);
          // Enable order button
          $('#place_order').removeClass('disabled');
        }
      }
    }
  });

  /**
   * Intercept checkout ajax if 3D payment needs to be completed first
   */
  $(document).ajaxSuccess(function (event, xhr, settings) {
    if(
      settings.url.indexOf("?wc-ajax=checkout") >= 0 && 
      typeof(xhr.responseJSON.htmlCode) != "undefined" && 
      xhr.responseJSON.htmlCode !== null
    ){
      // Call the function to establish the initial socket connection
      $('body').append('<div id="overflowKopaLoader"><div id="kopaLoaderIcon"></div></div>')
      $('body').append(xhr.responseJSON.htmlCode);
      $('body').find('#paymentform').submit();
    }

    if( settings.url.indexOf("?wc-ajax=update_order_review") >= 0 ){
      updateOrderTotalForKopaPaymentDetails();
      $('body').find('#kopaPaymentDetailsReferenceId').text(kopaIdReferenceId);
      $('form.checkout').find('.payment_method_kopa-payment p').removeClass('woocommerce-invalid');
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
    console.error(error);
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
 * Check for confirming CC type
 * @param {number} ccNumber 
 * @param {string} ccType 
 * @returns 
 */
async function getCardType(ccNumber, ccType){
  try {
    const response = await $.ajax({
      type: 'POST',
      url: ajax_checkout_params.ajaxurl,
      data: {
        action: 'get_card_type',
        security: ajax_checkout_params.security,
        dataType: 'json',
        ccNumber,
        ccType
      },
    });
    const decoded = $.parseJSON(response);

    if(decoded.cardType) {
      return { 'success': true, 'cardType': decoded.cardType };
    } else {
      return { 'success': false, 'message': decoded.message };
    }
  } catch (error) {
    // Handle AJAX or JSON parsing error.message
    return { 'success': false, 'message': error.message };
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
async function logErrorOnOrderPayment(orderId, errorMessage, kopaOrderId){
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
        kopaOrderId,
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