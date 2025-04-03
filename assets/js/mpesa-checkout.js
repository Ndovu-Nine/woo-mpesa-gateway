jQuery(function($) {
    // Format phone number as user types
    $('#mpesa_phone').on('input', function() {
        let phone = $(this).val().replace(/\D/g, '');
        if (phone.startsWith('0')) {
            phone = '254' + phone.substring(1);
        } else if (phone.startsWith('7')) {
            phone = '254' + phone;
        }
        $(this).val(phone);
    });
    
    // Additional client-side validation
    $('form.checkout').on('checkout_place_order', function() {
        const phone = $('#mpesa_phone').val();
        if (!phone.match(/^254[17][0-9]{8}$/)) {
            alert('Please enter a valid Kenyan phone number in format 2547XXXXXXXX');
            return false;
        }
        return true;
    });
});