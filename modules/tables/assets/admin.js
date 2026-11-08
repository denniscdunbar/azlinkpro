(function($){
'use strict';
const root=document.getElementById('atp-builder');
if(!root)return;
const input=document.getElementById('atp-rows-json');
let rows=[];
// View-only navigation state: never serialized into atp_rows_json.
// Row-object identity survives reorder, removal/undo, and normal editor redraws.
const foldedRows=new WeakSet();
const finder=document.getElementById('atp-product-find');
const finderStatus=document.getElementById('atp-product-find-status');
const previousMatch=document.getElementById('atp-find-previous');
const nextMatch=document.getElementById('atp-find-next');
const collapseAll=document.getElementById('atp-collapse-all');
const expandAll=document.getElementById('atp-expand-all');
let activeMatchAsin='';
let hasNavigated=false;
function matchingRowIndices(){
 const term=String(finder?.value||'').trim().toLocaleLowerCase();
 if(!term)return [];
 return rows.reduce((indices,row,i)=>{
  if((String(row.name||'')+' '+String(row.asin||'')).toLocaleLowerCase().includes(term))indices.push(i);
  return indices;
 },[]);
}
function updateFinder(){
 if(!finderStatus)return;
 const term=String(finder?.value||'').trim();
 const matches=matchingRowIndices();
 if(!matches.some(i=>String(rows[i].asin).toUpperCase()===activeMatchAsin))activeMatchAsin=matches.length?String(rows[matches[0]].asin).toUpperCase():'';
 if(previousMatch)previousMatch.disabled=matches.length===0;
 if(nextMatch)nextMatch.disabled=matches.length===0;
 let selected=0;
 matches.forEach((i,k)=>{if(String(rows[i].asin).toUpperCase()===activeMatchAsin)selected=k+1;});
 finderStatus.textContent=!term?rows.length+' saved product rows. Search by name or ASIN to jump to a product.':matches.length?matches.length+' matching product'+(matches.length!==1?'s':'')+'; selected '+selected+' of '+matches.length+'.':'No products match "'+term+'".';
 root.querySelectorAll('.atp-editor-item').forEach((card,i)=>{
  card.classList.toggle('is-nav-match',matches.includes(i));
  card.classList.toggle('is-nav-selected',matches.includes(i) && String(rows[i].asin).toUpperCase()===activeMatchAsin);
 });
}
function setFolded(row,card,folded){
 if(folded)foldedRows.add(row);else foldedRows.delete(row);
 card.classList.toggle('is-collapsed',folded);
 const toggle=card.querySelector('[data-action="toggle-details"]');
 if(toggle){toggle.setAttribute('aria-expanded',String(!folded));toggle.textContent=folded?'Show details':'Hide details';}
}
function jumpToMatch(step){
 const indices=matchingRowIndices();if(!indices.length)return;
 let position=indices.findIndex(i=>String(rows[i].asin).toUpperCase()===activeMatchAsin);
 if(position<0)position=0;
 if(!hasNavigated)position=step<0?indices.length-1:0;
 else if(step!==0)position=(position+step+indices.length)%indices.length;
 hasNavigated=true;
 const i=indices[position],row=rows[i];
 activeMatchAsin=String(row.asin).toUpperCase();
 const card=root.querySelectorAll('.atp-editor-item')[i];
 if(card){setFolded(row,card,false);card.scrollIntoView({block:'center',behavior:'smooth'});const toggle=card.querySelector('[data-action="toggle-details"]');toggle?.focus({preventScroll:true});}
 updateFinder();
}
finder?.addEventListener('input',()=>{activeMatchAsin='';hasNavigated=false;updateFinder();});
finder?.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();jumpToMatch(event.shiftKey?-1:1);}});
previousMatch?.addEventListener('click',()=>jumpToMatch(-1));
nextMatch?.addEventListener('click',()=>jumpToMatch(1));
collapseAll?.addEventListener('click',()=>root.querySelectorAll('.atp-editor-item').forEach((card,i)=>setFolded(rows[i],card,true)));
expandAll?.addEventListener('click',()=>root.querySelectorAll('.atp-editor-item').forEach((card,i)=>setFolded(rows[i],card,false)));
// Keep only recent unsaved removals. All restored rows use their original
// objects, including image choice, review link and affiliate button label.
const removalHistory=[];
const normalizedAsin=value=>String(value||'').trim().toUpperCase();
const validAsin=value=>/^[A-Z0-9]{10}$/.test(value);
function announceEditor(message){
 ['azp-preview-remove-status','atp-editor-remove-status'].forEach(id=>{
  const target=document.getElementById(id);if(!target)return;
  target.replaceChildren();
  const span=document.createElement('span');span.textContent=message;target.appendChild(span);
  if(removalHistory.length){
   const button=document.createElement('button');button.type='button';
   button.className='button button-secondary atp-undo-remove';
   button.dataset.atpUndoRemove='1';button.textContent='Undo removal';
   button.setAttribute('aria-label','Undo last product removal');
   target.appendChild(button);
   const count=document.createElement('small');
   count.textContent=removalHistory.length+' can be undone before saving';
   target.appendChild(count);
  }
 });
}
function undoLastRemove(){
 if(!removalHistory.length)return;
 const item=removalHistory.pop();
 const asin=normalizedAsin(item.row.asin);
 if(rows.some(r=>normalizedAsin(r.asin)===asin)){
  announceEditor('That product is already in the table; it was not duplicated.');return;
 }
 rows.splice(Math.min(item.index,rows.length),0,item.row);
 render();
 announceEditor('Restored '+(item.row.name||asin)+'. Click Update / Publish to save.');
}
document.addEventListener('click',event=>{
 const button=event.target.closest('button[data-atp-undo-remove]');
 if(button && (button.closest('#azp-preview-remove-status') || button.closest('#atp-editor-remove-status'))){
  event.preventDefault();undoLastRemove();
 }
});
// Normalize older saved product rows on opening the editor. This creates a
// complete submission without dropping legacy content or changing the saved
// WordPress post until Update/Publish is clicked.
try{
 rows=JSON.parse(input.value||'[]');if(!Array.isArray(rows))rows=[];
 rows=rows.map(row=>Object.assign(emptyRow(String(row?.asin||'')),row));
}catch(e){rows=[];}
// Draft restoration is explicit; the regular WordPress post meta is untouched
// until the user presses Update / Publish. Restored rows use the normal editor.
root.addEventListener('atp:restore-draft-rows',event=>{
 const payload=event.detail && event.detail.rows;
 if(!Array.isArray(payload) || payload.length>50)return;
 const seen=new Set(), restored=[];
 for(const row of payload){
  if(!row || typeof row!=='object' || Array.isArray(row))return;
  const asin=normalizedAsin(row.asin);
  if(!validAsin(asin) || seen.has(asin))return;
  seen.add(asin);
  restored.push(Object.assign(emptyRow(asin),row,{asin}));
 }
 rows=restored;
 removalHistory.length=0;
 render();
 announceEditor(event.detail.message||'Recovered browser draft. Review the changes and click Update / Publish to save.');
 // Spreadsheet imports carry only ASINs: load each new product's Amazon name and
 // features one request at a time, like Add ASIN does, instead of all at once.
 if(Array.isArray(event.detail.fetchAmazon)){
  const wanted=new Set(event.detail.fetchAmazon.map(normalizedAsin));
  let queue=$.Deferred().resolve().promise();
  rows.filter(row=>wanted.has(row.asin)&&!String(row.details||'').trim()).forEach(row=>{
   queue=queue.then(()=>$.when(fetchAmazonSummary(row)).then(null,()=>null));
  });
 }
});
function safe(s){return String(s===undefined||s===null?'':s).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
function sync(){input.value=JSON.stringify(rows);const n=document.getElementById('atp-count');if(n)n.textContent='('+rows.length+')';markAddedHits();root.dispatchEvent(new CustomEvent('atp:editor-change'));}
// 1.5.2: search results already in the table are highlighted, so they are not picked twice.
function markAddedHits(){const list=document.getElementById('atp-search-results');if(!list)return;const inTable=new Set(rows.map(r=>normalizedAsin(r.asin)));list.querySelectorAll('.atp-hit[data-asin]').forEach(card=>{const added=inTable.has(card.dataset.asin),button=card.querySelector('button');card.classList.toggle('is-added',added);if(button){button.disabled=false;button.textContent=added?'Remove from table':'+ Add to table';button.setAttribute('aria-label',(added?'In this table. Remove from table: ':'Add to table: ')+(card.dataset.name||card.dataset.asin));}});}
function emptyRow(asin){return {asin:asin||'',name:'',details:'',review:'',featured:false,image_id:0,amazon_image_key:'',image_preview:'',image_size:'default',image_custom_px:180,cta:'Check Price'};}
function imageSizeSelect(r){const size=r.image_size||'default',options=[['default','Default (current size)'],['small','Small (120 px)'],['medium','Medium (180 px)'],['large','Large (260 px)'],['xlarge','Extra large (360 px)'],['custom','Custom size']];return '<div class="atp-size-controls"><label>Product image display size<select data-field="image_size">'+options.map(o=>'<option value="'+o[0]+'"'+(size===o[0]?' selected':'')+'>'+o[1]+'</option>').join('')+'</select></label><label class="atp-custom-size"'+(size==='custom'?'':' hidden')+'>Custom size (60–420 px)<input type="number" min="60" max="420" step="1" data-field="image_custom_px" value="'+safe(r.image_custom_px||180)+'"></label><span class="description">Display only; the Amazon photo itself is unchanged.</span></div>';}
function render(){
 root.innerHTML='';
 rows.forEach((r,i)=>{
  const div=document.createElement('div');div.className='atp-editor-item';div.dataset.index=i;
  const preview=r.image_preview?'<img alt="Preview" src="'+safe(r.image_preview)+'">':(r.amazon_image_key?'Alternate Amazon photo selected (open gallery to preview)':'Amazon API image / choose your own photo');
  div.innerHTML='<div class="atp-editor-top"><span class="atp-drag dashicons dashicons-move" title="Drag to reorder" aria-hidden="true"></span><strong>Product '+(i+1)+'</strong><span class="atp-product-short-name" title="'+safe(r.name||r.asin)+'">'+safe(r.name||r.asin)+'</span><button type="button" class="button button-small" data-action="toggle-details" aria-expanded="'+String(!foldedRows.has(r))+'" aria-controls="atp-product-fields-'+i+'">'+(foldedRows.has(r)?'Show details':'Hide details')+'</button><button type="button" class="button button-small" data-action="up">↑ Move up</button><button type="button" class="button button-small" data-action="down">↓ Move down</button><label style="display:inline;font-weight:normal;margin:0"><input type="checkbox" data-field="featured" '+(r.featured?'checked':'')+'> Featured / Top Pick</label><code class="atp-asin">'+safe(r.asin)+'</code><button type="button" class="button-link-delete" data-action="remove">Remove</button></div>'+
   '<div class="atp-form-grid" id="atp-product-fields-'+i+'"><div><div class="atp-image-preview">'+preview+'</div><div class="atp-image-options"><button class="button button-small" type="button" data-action="amazon-photos">Choose Amazon photo</button><button class="button button-small" type="button" data-action="media">WordPress Image</button><button class="button button-small" type="button" data-action="clear-image">Use main Amazon photo</button></div><div class="atp-gallery-feedback" role="status" aria-live="polite"></div>'+imageSizeSelect(r)+'</div>'+
   '<div><label>Amazon ASIN<input type="text" maxlength="10" data-field="asin" value="'+safe(r.asin)+'"></label><label>Product title<input type="text" data-field="name" value="'+safe(r.name)+'"></label><label>Internal review link (optional)<input type="url" data-field="review" placeholder="https://yourdomain.com/review/" value="'+safe(r.review)+'"></label></div>'+
   '<div><label>Product details / summary<textarea data-field="details">'+safe(r.details)+'</textarea></label><button type="button" class="button button-secondary atp-suggest-details" data-action="amazon-summary">Suggest Amazon highlights</button><p class="atp-summary-feedback description" role="status" aria-live="polite">Auto-generated FEATURE: DETAILS lines use Amazon listing information. Review facts and wording before publishing.</p><label>Affiliate button text<input type="text" data-field="cta" value="'+safe(r.cta||'Check Price')+'"></label></div></div><div class="atp-image-gallery" hidden></div>';
  if(foldedRows.has(r))div.classList.add('is-collapsed');
  root.appendChild(div);
 });
 sync();
 updateFinder();
}
// All editor previews use this same removal path. The saved WordPress post is
// untouched until Update/Publish; removing a row also preserves any unsaved
// custom comparison notes if the product is re-added before saving.
function removeAt(index){
 const row=rows[index];
 if(!row)return false;
 const title=String(row.name||row.asin||'this product').trim();
 if(!window.confirm('Remove "'+title+'" from this table?\n\nClick Update / Publish to save the change.'))return false;
 removalHistory.push({row,index});
 if(removalHistory.length>10)removalHistory.shift();
 rows.splice(index,1);
 render();
 announceEditor('Removed '+title+' from this table draft. Click Update / Publish to save.');
 return true;
}
root.addEventListener('atp:remove-product',event=>{
 const asin=String(event.detail&&event.detail.asin||'').trim().toUpperCase();
 if(!/^[A-Z0-9]{10}$/.test(asin))return;
 const index=rows.findIndex(p=>String(p.asin||'').trim().toUpperCase()===asin);
 if(index>=0)removeAt(index);
});
// Move commands dispatched from an admin preview use the exact same row array
// as the original editor; comparison text stays linked to ASIN, not row index.
function moveAt(index, direction){
 const next=index+(direction==='up'?-1:1);
 if(index<0 || next<0 || next>=rows.length || (direction!=='up' && direction!=='down'))return false;
 const current=rows[index];[rows[index],rows[next]]=[rows[next],rows[index]];
 render();
 announceEditor('Moved '+(current.name||current.asin)+' '+direction+' in this table. Click Update / Publish to save.');
 return true;
}
root.addEventListener('atp:move-product',event=>{
 const asin=normalizedAsin(event.detail&&event.detail.asin);
 const direction=String(event.detail&&event.detail.direction||'');
 if(!validAsin(asin) || !['up','down'].includes(direction))return;
 const index=rows.findIndex(p=>normalizedAsin(p.asin)===asin);
 moveAt(index,direction);
});
function add(row){
 if(rows.length>=50){window.alert('Maximum 50 products per table.');return;}
 const asin=String(row.asin||'').trim().toUpperCase();
 if(!/^[A-Z0-9]{10}$/.test(asin)){window.alert('Enter a valid 10-character Amazon ASIN.');return;}
 if(rows.some(r=>normalizedAsin(r.asin)===asin)){announceEditor('That product is already in the table. It is highlighted in the search results.');return;}
 const added=Object.assign(emptyRow(asin),row,{asin:asin});rows.push(added);render();
 if(!String(added.details||'').trim())fetchAmazonSummary(added);
 return added;
}
root.addEventListener('input',e=>{
 const node=e.target.closest('.atp-editor-item');if(!node||!e.target.dataset.field)return;
 const i=Number(node.dataset.index),field=e.target.dataset.field;
 // ASIN changes are committed on blur, after validation, not on each
 // keystroke. Otherwise typing can irreversibly clear the chosen product photo.
 if(field==='asin')return;
 rows[i][field]=e.target.type==='checkbox'?e.target.checked:e.target.value;
 if(field==='image_size'){const custom=node.querySelector('.atp-custom-size');if(custom)custom.hidden=rows[i].image_size!=='custom';}
 if(field==='image_custom_px'){const v=parseInt(rows[i][field],10);if(Number.isFinite(v)&&v>=60&&v<=420){rows[i][field]=v;}}
 sync();
});
root.addEventListener('change',e=>{
 if(e.target.dataset.field==='asin'){
  const node=e.target.closest('.atp-editor-item');if(!node)return;
  const i=Number(node.dataset.index),row=rows[i];if(!row)return;
  const previous=normalizedAsin(row.asin),next=normalizedAsin(e.target.value);
  if(!validAsin(next)){
   e.target.value=previous;
   announceEditor('ASIN must contain exactly 10 letters or numbers. The original product was preserved.');
   return;
  }
  if(next!==previous && rows.some((r,j)=>j!==i && normalizedAsin(r.asin)===next)){
   e.target.value=previous;
   announceEditor('That ASIN is already in this table. The original product was preserved.');
   return;
  }
  e.target.value=next;
  if(next!==previous){
   row.asin=next;row.amazon_image_key='';row.image_preview='';row.image_id=0;
   render();
   announceEditor('ASIN changed. Choose a new Amazon photo if needed, then click Update / Publish.');
  }else sync();
  return;
 }
 if(e.target.dataset.field==='featured'){
  const node=e.target.closest('.atp-editor-item');rows[Number(node.dataset.index)].featured=e.target.checked;sync();
 }
});
root.addEventListener('click',e=>{
 const btn=e.target.closest('button[data-action]');if(!btn)return;
 const node=btn.closest('.atp-editor-item'),i=Number(node.dataset.index),action=btn.dataset.action;
 if(action==='remove')removeAt(i);
 if(action==='toggle-details')setFolded(rows[i],node,!foldedRows.has(rows[i]));
 if(action==='up')moveAt(i,'up');
 if(action==='down')moveAt(i,'down');
 if(action==='amazon-summary'){
  const row=rows[i];
  if(String(row.details||'').trim() && !window.confirm('Replace your existing product details with a new Amazon summary? This will overwrite your unsaved text in this row.'))return;
  fetchAmazonSummary(row,true);
 }
 if(action==='clear-image'){rows[i].image_id=0;rows[i].amazon_image_key='';rows[i].image_preview='';render();}
 if(action==='amazon-photos'){openAmazonGallery(rows[i],node,btn);}
 if(action==='media'){
  if(!window.wp||!wp.media){window.alert('WordPress Media Library is unavailable.');return;}
  const frame=wp.media({title:'Choose an image you own or have licensed',button:{text:'Use this image'},multiple:false,library:{type:'image'}});
  frame.on('select',()=>{
   const attachment=frame.state().get('selection').first().toJSON();
   rows[i].amazon_image_key='';
   rows[i].image_id=attachment.id;
   rows[i].image_preview=(attachment.sizes&&attachment.sizes.thumbnail?attachment.sizes.thumbnail.url:attachment.url);
   render();
  });frame.open();
 }
});
// Product summaries are only fetched after a product is chosen, never for
// every search hit. Existing editorial text is never silently overwritten.
const amazonSummaryCache=new Map();
const pendingAmazonSummary=new WeakSet();
function fetchAmazonSummary(row,replaceExisting=false){
 const asin=normalizedAsin(row&&row.asin);
 if(!validAsin(asin)||!rows.includes(row)||pendingAmazonSummary.has(row))return;
 const previous=String(row.details||'');
 if(previous.trim() && !replaceExisting)return;
 const feedback=()=>{
  const i=rows.indexOf(row);
  return i<0?null:root.querySelectorAll('.atp-editor-item')[i]?.querySelector('.atp-summary-feedback');
 };
 function message(value){const node=feedback();if(node)node.textContent=value;}
 function apply(data){
  if(!rows.includes(row)||normalizedAsin(row.asin)!==asin)return;
  const i=rows.indexOf(row);
  const card=root.querySelectorAll('.atp-editor-item')[i];
  // Fill an empty name even when Amazon has no usable feature bullets.
  if(!String(row.name||'').trim() && data.title){
   row.name=String(data.title);
   const name=card?.querySelector('[data-field="name"]');
   if(name)name.value=row.name;
   const label=card?.querySelector('.atp-product-short-name');
   if(label){label.textContent=row.name;label.title=row.name;}
   sync();
  }
  if(String(row.details||'')!==previous){message('Your edited product details were preserved.');return;}
  if(!data.summary){message(data.message||'No suitable Amazon feature details were returned.');return;}
  row.details=String(data.summary);
  const details=card?.querySelector('[data-field="details"]');
  if(details)details.value=row.details;
  // Do not redraw every product row after an asynchronous fetch: doing so
  // would discard focus and any selection in another field being edited.
  sync();
  message('Labeled Amazon highlights added (FEATURE: DETAILS). Review and click Update / Publish to save.');
 }
 pendingAmazonSummary.add(row);
 message('Finding key Amazon features…');
 if(amazonSummaryCache.has(asin)){
  apply(amazonSummaryCache.get(asin));pendingAmazonSummary.delete(row);return;
 }
 return $.post(atpAdmin.ajax,{action:'atp_product_summary',nonce:atpAdmin.nonce,asin:asin})
  .done(response=>{
   if(!rows.includes(row)||normalizedAsin(row.asin)!==asin)return;
   if(!response||!response.success){message(response&&response.data&&response.data.message||'Amazon details unavailable. You can enter them manually.');return;}
   const data=response.data||{};
   amazonSummaryCache.set(asin,data);
   apply(data);
  })
  .fail(xhr=>{if(rows.includes(row))message(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message||'Amazon details unavailable. You can enter them manually.');})
  .always(()=>{pendingAmazonSummary.delete(row);});
}
const gallerySessionCache=new Map();
function openAmazonGallery(row,node,button){
 const asin=String(row.asin||'').trim().toUpperCase();
 const feedback=node.querySelector('.atp-gallery-feedback'),gallery=node.querySelector('.atp-image-gallery');
 if(!/^[A-Z0-9]{10}$/.test(asin)){feedback.textContent='Enter a valid 10-character ASIN first.';return;}
 function showImages(images){
  if(!rows.includes(row)||row.asin!==asin)return;
  gallery.replaceChildren();
  if(!images.length){feedback.textContent='Amazon did not provide any photos for this item.';gallery.hidden=true;return;}
  feedback.textContent=images.length>1?'Select a photo, then click Update to save it.':'Amazon provided only the main image for this item.';
  images.forEach((image,index)=>{
   if(!image.url||!/^[a-f0-9]{32}$/.test(image.key||''))return;
   const choice=document.createElement('button');choice.type='button';choice.className='atp-image-choice'+((row.amazon_image_key===image.key || (!row.amazon_image_key&&!index))?' is-selected':'');
   const pic=document.createElement('img');pic.src=image.url;pic.alt=image.label||'Amazon product photo';pic.loading='lazy';choice.appendChild(pic);
   const caption=document.createElement('span');caption.textContent=image.label||('Photo '+(index+1));choice.appendChild(caption);
   choice.addEventListener('click',()=>{
    if(!rows.includes(row)||row.asin!==asin)return;
    row.amazon_image_key=index===0?'':image.key;row.image_id=0;row.image_preview=image.url;
    render();
   });gallery.appendChild(choice);
  });
  gallery.hidden=false;
 }
 // Reopening the same photo chooser should not request the same gallery again
 // within this editor session. The backend's existing TTL remains authoritative.
 if(gallerySessionCache.has(asin)){
  showImages(gallerySessionCache.get(asin));
  return;
 }
 button.disabled=true;feedback.textContent='Loading available Amazon photos…';gallery.hidden=true;gallery.replaceChildren();
 $.post(atpAdmin.ajax,{action:'atp_product_images',nonce:atpAdmin.nonce,asin:asin})
 .done(response=>{
  if(!rows.includes(row)||row.asin!==asin)return;
  if(!response||!response.success){feedback.textContent=response&&response.data&&response.data.message||'Unable to fetch Amazon photos.';return;}
  const images=response.data&&Array.isArray(response.data.images)?response.data.images:[];
  if(gallerySessionCache.size>=30)gallerySessionCache.delete(gallerySessionCache.keys().next().value);
  gallerySessionCache.set(asin,images);
  showImages(images);
 }).fail(xhr=>{if(rows.includes(row))feedback.textContent=xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message||'Amazon image lookup failed. Check your API settings.';})
 .always(()=>{button.disabled=false;});
}
// jQuery Sortable provides drag/drop in addition to keyboard-accessible move buttons.
$(root).sortable({items:'.atp-editor-item',handle:'.atp-drag',update:function(){
 const next=[];$(root).children('.atp-editor-item').each(function(){next.push(rows[Number(this.dataset.index)]);});rows=next;render();
}});
document.getElementById('atp-add-blank').addEventListener('click',()=>{
 const a=window.prompt('Enter 10-character ASIN:');if(a!==null)add(emptyRow(a.trim().toUpperCase()));
});
document.getElementById('atp-add-asin').addEventListener('click',()=>{
 const q=document.getElementById('atp-query').value.trim().toUpperCase();
 if(!/^[A-Z0-9]{10}$/.test(q)){window.alert('For Add ASIN, paste a valid 10-character ASIN.');return;}
 add(emptyRow(q));
});
const results=document.getElementById('atp-search-results');
const searchStatus=document.getElementById('atp-search-status');
const moreButton=document.getElementById('atp-search-more');
const searchButton=document.getElementById('atp-search');
const searchField=document.getElementById('atp-query');
let term='',page=0,busy=false,more=false,seen=new Set(),requestId=0,activeSearch=null;
function runAmazonSearch(loadMore){
 const q=searchField.value.trim();
 if(!q){searchStatus.textContent='Enter keywords or an ASIN first.';return;}
 if(busy){
  if(loadMore || q===term)return; // Prevent concurrent requests for the same search.
  requestId++; // Invalidate callbacks before aborting a stale search.
  if(activeSearch && typeof activeSearch.abort==='function')activeSearch.abort();
  busy=false;
 }
 if(!loadMore || q!==term){term=q;page=0;more=false;seen=new Set();results.innerHTML='';requestId++;}
 if(loadMore&&!more)return;
 const nextPage=page+1,token=requestId;
 busy=true;searchButton.disabled=true;moreButton.disabled=true;
 searchStatus.textContent=nextPage===1?'Searching Amazon…':'Loading more Amazon products…';
 moreButton.hidden=true;
 activeSearch=$.post(atpAdmin.ajax,{action:'atp_search_products',nonce:atpAdmin.nonce,q:term,page:nextPage}).done(response=>{
  if(token!==requestId)return;
  if(!response||!response.success){searchStatus.textContent=response&&response.data&&response.data.message||'Search failed.';return;}
  const products=response.data.items||[];page=nextPage;more=!!response.data.has_more;
  let added=0;
  products.forEach(p=>{
   if(seen.has(p.asin))return;seen.add(p.asin);added++;
   const card=document.createElement('div');card.className='atp-hit';card.dataset.asin=normalizedAsin(p.asin);card.dataset.name=p.name||'';
   card.innerHTML=(p.image?'<img src="'+safe(p.image)+'" alt="">':'<span style="max-width:55px">No photo</span>')+'<span><strong>'+safe(p.name||p.asin)+'</strong><br><code>'+safe(p.asin)+'</code></span><button type="button" class="button button-secondary">+ Add to table</button>';
   // New rows get "Brand | Model" or the full Amazon title, as chosen in Settings.
   const rowName=window.azpProductNames?window.azpProductNames.pick(p.name,p.short):p.name;
   // 1.5.3: the same button adds the product, or removes it again once it is in the table.
   card.querySelector('button').addEventListener('click',()=>{const at=rows.findIndex(r=>normalizedAsin(r.asin)===normalizedAsin(p.asin));if(at>=0)removeAt(at);else add(Object.assign(emptyRow(p.asin),{name:rowName,image_preview:p.image}));});
   results.appendChild(card);
  });
  markAddedHits();
  searchStatus.textContent=seen.size ? seen.size+' products loaded. Scroll to browse'+(more?', or click Load 10 more.':'.') : (response.data.message||'No products found.');
  moreButton.hidden=!more;
  if(loadMore&&added)results.scrollTop=results.scrollHeight-results.clientHeight;
 }).fail(xhr=>{if(token!==requestId)return;searchStatus.textContent=xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message||'Amazon search failed. Check API settings.';})
 .always(()=>{if(token!==requestId)return;activeSearch=null;busy=false;searchButton.disabled=false;moreButton.disabled=false;});
}
searchButton.addEventListener('click',()=>runAmazonSearch(false));
moreButton.addEventListener('click',()=>runAmazonSearch(true));
searchField.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();runAmazonSearch(false);}});
const colorInput=document.getElementById('atp-header-color'),colorPreview=document.getElementById('atp-header-color-preview'),colorHex=document.getElementById('atp-header-hex'),swatches=[...document.querySelectorAll('.atp-color-swatch')];
function syncHeaderColorUI(value){
 if(colorPreview){colorPreview.style.backgroundColor=value;}
 if(colorHex){colorHex.value=value.toUpperCase();colorHex.removeAttribute('aria-invalid');}
 swatches.forEach(btn=>btn.classList.toggle('is-active', String(btn.dataset.color||'').toLowerCase()===String(value||'').toLowerCase()));
}
if(colorInput){
 colorInput.addEventListener('input',()=>syncHeaderColorUI(colorInput.value));
 syncHeaderColorUI(colorInput.value);
}
if(colorHex&&colorInput){
 const useTypedHex=()=>{
  let v=colorHex.value.trim();
  if(/^[a-f\d]{3}$/i.test(v.replace(/^#/,''))){const q=v.replace(/^#/,'');v='#'+q.split('').map(c=>c+c).join('');}
  else if(/^#?[a-f\d]{6}$/i.test(v)){v='#'+v.replace(/^#/,'');}
  else {colorHex.setAttribute('aria-invalid','true');return;}
  colorInput.value=v;syncHeaderColorUI(v);
 };
 colorHex.addEventListener('change',useTypedHex);
 colorHex.addEventListener('blur',()=>{if(colorHex.getAttribute('aria-invalid')==='true'){syncHeaderColorUI(colorInput.value);}});
 colorHex.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();useTypedHex();}});
}
swatches.forEach(btn=>btn.addEventListener('click',()=>{
 if(!colorInput)return;
 colorInput.value=btn.dataset.color||'#162337';
 syncHeaderColorUI(colorInput.value);
 colorInput.dispatchEvent(new Event('input',{bubbles:true}));
}));
render();
// 1.5.2: the Product Link Audit links here with ?azp_find=ASIN to jump to the product to replace.
(function(){const asin=normalizedAsin(new URLSearchParams(window.location.search).get('azp_find'));if(!finder||!validAsin(asin))return;setTimeout(()=>{if(!rows.some(r=>normalizedAsin(r.asin)===asin))return;finder.value=asin;activeMatchAsin='';hasNavigated=false;updateFinder();jumpToMatch(0);announceEditor('Product '+asin+' from the Link Audit is highlighted. Replace its ASIN or remove it, then click Update.');},400);})();
})(jQuery);
