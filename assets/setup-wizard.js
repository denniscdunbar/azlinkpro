(function($){'use strict';
// AzlinkPRO setup wizard: one step at a time. Each step saves through admin-ajax, and the
// Amazon connection test reuses the Settings handler. Paid builds add the AI step through
// window.azpSetupWizard (includes/premium/setup-ai.js).
const cfg=window.azpSetup||{},$steps=$('.azp-setup-step'),$progress=$('.azp-setup-progress li');
if(!$steps.length)return;
let status=cfg.status||{},finished=false;
const hooks={summary:[],afterSave:[]};

function post(data){return $.ajax({url:cfg.ajax,type:'POST',dataType:'json',timeout:60000,data:data});}
function setup(op,data){return post($.extend({action:'azlinkpro_setup',nonce:cfg.nonce,op:op},data||{}));}
function failMessage(x,fallback){const d=x&&x.responseJSON&&x.responseJSON.data;return (d&&d.message)||fallback;}
function rejectWith(message){return $.Deferred().reject(message).promise();}
function message($step,text,kind){$step.find('.azp-setup-message').removeClass('is-error').addClass(kind?'is-'+kind:'').text(text||'');}
function result($box,text,kind){$box.prop('hidden',false).removeClass('is-success is-error').addClass(kind?'is-'+kind:'').text(text||'');}

function renderSummary(){
  const rows={tag:[status.tag,'Saved'],api:[status.api,'Saved']};
  hooks.summary.forEach(function(add){$.extend(rows,add(status));});
  $('.azp-setup-summary li').each(function(){
    const row=rows[$(this).data('key')];if(!row)return;
    $(this).toggleClass('is-ok',!!row[0]).find('em').text(row[0]?row[1]:'Not added yet');
  });
}
function show(step){
  step=Math.max(1,Math.min(5,Number(step)||1));
  $steps.each(function(){this.hidden=Number($(this).data('step'))!==step;});
  $progress.each(function(){
    const n=Number($(this).data('step'));
    $(this).toggleClass('is-done',n<step).toggleClass('is-current',n===step);
    if(n===step)$(this).attr('aria-current','step');else $(this).removeAttr('aria-current');
  });
  if(step===5){renderSummary();if(!finished){finished=true;setup('finish',{state:'done'});}}
  if(window.history&&history.replaceState)history.replaceState(null,'','#step-'+step);
  window.scrollTo(0,0);
  $steps.filter('[data-step="'+step+'"]').find('h2').first().trigger('focus');
}

// Clear a saved secret from its field and refresh the Configured labels.
function afterSave(r,kind){
  status=(r&&r.data&&r.data.status)||status;
  const clear=function($input){if($input.length&&$input.val()){$input.val('').attr('placeholder','Saved. Leave empty to keep it');}};
  if(kind==='amazon-keys')clear($('#azp-setup-secret'));
  hooks.afterSave.forEach(function(after){after(kind,status,clear);});
}
const savers={
  'amazon-tag':function(){return {op:'amazon',data:{tag:$('#azp-setup-tag').val(),marketplace:$('#azp-setup-marketplace').val()}};},
  'amazon-keys':function(){
    const data={};
    if($('#azp-setup-client').length)data.client=$('#azp-setup-client').val();
    if($('#azp-setup-secret').length)data.secret=$('#azp-setup-secret').val();
    return {op:'amazon',data:data};
  }
};
function save(kind,only){
  const job=savers[kind]();
  if(only){job.data={};job.data[only]=$('#azp-setup-'+only).val();}
  return setup(job.op,job.data).then(function(r){
    if(!r||!r.success)return rejectWith((r&&r.data&&r.data.message)||'Could not save.');
    afterSave(r,only?kind+'-'+only:kind);
    return r;
  },function(x){return rejectWith(failMessage(x,'Could not save. Check your connection and try again.'));});
}

$('.azp-setup').on('click','[data-go]',function(){show($(this).data('go'));});
$('.azp-setup').on('click','[data-save]',function(){
  const $button=$(this),$step=$button.closest('.azp-setup-step');
  $button.prop('disabled',true);message($step,'Saving…');
  save(String($button.data('save'))).done(function(){message($step,'');show($button.data('next'));})
    .fail(function(text){message($step,text,'error');})
    .always(function(){$button.prop('disabled',false);});
});
// Enter in a field acts like the step's main button.
$('.azp-setup').on('keydown','.azp-setup-step input',function(e){
  if(e.key!=='Enter')return;
  e.preventDefault();
  const $inline=$(this).closest('.azp-setup-inline').find('button');
  ($inline.length?$inline:$(this).closest('.azp-setup-step').find('[data-save]')).first().trigger('click');
});
$('.azp-setup-skip').on('click',function(){
  $(this).prop('disabled',true);
  setup('finish',{state:'skipped'}).always(function(){window.location.href=cfg.overview;});
});

// Associates Central links follow the chosen Amazon store.
function syncAssociatesLinks(){
  const key=$('#azp-setup-marketplace').val()==='www.amazon.ca'?'ca':'us';
  $('.azp-setup-associates').each(function(){
    const url=String($(this).data(key)||'');if(!url)return;
    $(this).attr('href',url);
    if($(this).text().indexOf('.')>-1)$(this).text(url.replace(/^https:\/\//,'').replace(/\/$/,''));
  });
}
$('#azp-setup-marketplace').on('change',syncAssociatesLinks);

$('#azp-setup-test-amazon').on('click',function(){
  const $button=$(this),$box=$('#azp-setup-amazon-result');
  const asin=String($('#azp-setup-asin').val()||'').trim().toUpperCase();
  if(!/^[A-Z0-9]{10}$/.test(asin)){result($box,'Enter a valid 10-character ASIN first.','error');return;}
  $button.prop('disabled',true).text('Testing…');
  result($box,'Saving your keys and contacting Amazon…');
  save('amazon-keys').then(function(){
    return post({action:'azlinkpro_test_amazon',nonce:cfg.amazonNonce,asin:asin}).then(null,function(x){
      return rejectWith(failMessage(x,'Could not reach Amazon. Check your keys and the Amazon store you chose.'));
    });
  }).done(function(r){
    const d=(r&&r.data)||{};
    if(!r||!r.success){result($box,d.message||'Amazon did not accept the test.','error');return;}
    result($box,'','success');
    $('<strong/>').text(d.message||'Connected.').appendTo($box);
    const $product=$('<div/>').addClass('azp-test-product').appendTo($box);
    if(d.image&&/^https:\/\//i.test(d.image))$('<img/>').attr({src:d.image,alt:''}).appendTo($product);
    $('<span/>').text((d.title||'Product')+' (ASIN '+asin+')').appendTo($product);
  }).fail(function(text){result($box,typeof text==='string'?text:'The Amazon test failed.','error');})
    .always(function(){$button.prop('disabled',false).text('Save and test connection');});
});

// The paid build's AI step registers its saver and summary row here.
window.azpSetupWizard={savers:savers,save:save,post:post,result:result,rejectWith:rejectWith,failMessage:failMessage,hooks:hooks,status:function(){return status;}};

syncAssociatesLinks();
const hash=/^#step-([1-5])$/.exec(window.location.hash||'');
show(hash?Number(hash[1]):1);
})(jQuery);
