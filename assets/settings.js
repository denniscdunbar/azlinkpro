(function($){'use strict';
  $(function(){
    const $button=$('#azp-test-amazon'),$asin=$('#azp-test-asin'),$result=$('#azp-test-amazon-result');
    if(!$button.length)return;
    $button.on('click',function(){
      const asin=String($asin.val()||'').trim().toUpperCase();
      if(!/^[A-Z0-9]{10}$/.test(asin)){$result.removeClass('is-success').addClass('is-error').text('Enter a valid 10-character ASIN first.');return;}
      $button.prop('disabled',true).text('Testing connection…');
      $result.removeClass('is-success is-error').text('Contacting Amazon using saved credentials…');
      $.post(azlinkproTest.ajax,{action:'azlinkpro_test_amazon',nonce:azlinkproTest.nonce,asin:asin}).done(function(r){
        const d=r&&r.data?r.data:{};
        if(!r||!r.success){$result.addClass('is-error').text(d.message||'Amazon API test failed.');return;}
        $result.empty().addClass('is-success');
        $('<strong/>').text(d.message||'Amazon API connection passed.').appendTo($result);
        const $product=$('<div/>').addClass('azp-test-product').appendTo($result);
        if(d.image){$('<img/>').attr({src:d.image,alt:''}).appendTo($product);}
        $('<span/>').text((d.title||'Product')+' — ASIN '+asin).appendTo($product);
      }).fail(function(xhr){
        const d=xhr.responseJSON&&xhr.responseJSON.data;
        $result.addClass('is-error').text((d&&d.message)||'Unable to connect to Amazon. Check API access, marketplace, tracking ID and credentials.');
      }).always(function(){$button.prop('disabled',false).text('Test Amazon Connection');});
    });
  });
})(jQuery);
