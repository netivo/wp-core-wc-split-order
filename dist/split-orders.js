jQuery( document ).ready( function ( $ ) {

  $( document ).on( 'change', '#split_shipping', function () {

    let is_split = $( this ).is( ':checked' );
    let $updateAreas = $( '.woocommerce-checkout-review-order-table, .woocommerce-checkout-payment' );

    $.ajax( {
      type:    'POST',
      url:     nt_split_orders_params.ajax_url,
      data:    {
        action:         'update_split_shipping',
        split_shipping: is_split,
        nonce:          nt_split_orders_params.nonce
      },
      success: function ( response ) {
        if ( response && response.fragments ) {
          $.each( response.fragments, function ( key, value ) {
            $( key ).replaceWith( value );
          } );
          $updateAreas = $( '.woocommerce-checkout-review-order-table, .woocommerce-checkout-payment' );
          $( 'body' ).trigger( 'update_checkout' );
        }

        $updateAreas.unblock();
      },
      error:   function ( error ) {
        $updateAreas.unblock();
      }
    } );
  } );
} );