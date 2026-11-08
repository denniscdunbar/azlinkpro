/* AzlinkPRO 1.4.0-beta.21 — table product names: rename every product to
   "Brand | Model" or back to Amazon's full title, and keep an empty table
   title filled in from the products ("3D MAXpider vs TAPTES vs Snuuzu"). */
(function(){
 'use strict';
 const names=window.azpProductNames;
 const products=document.getElementById('atp-builder');
 const rowsField=document.getElementById('atp-rows-json');
 if(!names||!products||!rowsField)return;
 const status=document.getElementById('azp-names-status');
 const shortButton=document.getElementById('azp-names-short');
 const fullButton=document.getElementById('azp-names-full');
 const buttons=[shortButton,fullButton].filter(Boolean);
 const asinOf=row=>String(row.asin||'').trim().toUpperCase();
 const validAsin=asin=>/^[A-Z0-9]{10}$/.test(asin);
 function rows(){
  try{const list=JSON.parse(rowsField.value||'[]');return Array.isArray(list)?list.filter(r=>r&&typeof r==='object'&&!Array.isArray(r)):[];}
  catch(e){return [];}
 }
 function say(text,error){
  if(!status)return;
  status.textContent=text;
  status.classList.toggle('is-error',!!error);
 }

 function rename(kind){
  const list=rows();
  if(!list.length){say('Add a product first.',true);return;}
  // The editor only accepts a complete list back, so a half-added row must be finished first.
  if(list.some(row=>!validAsin(asinOf(row)))){say('One product has no ASIN yet. Add its ASIN or remove it, then try again.',true);return;}
  buttons.forEach(b=>{b.disabled=true;});
  say('Getting names from Amazon…');
  names.lookup(list.map(asinOf)).then(data=>{
   const found=data.names||{};
   const current=rows();
   let changed=0,typed=0;
   const updated=current.map(row=>{
    const name=found[asinOf(row)];
    if(!name)return row;
    const next=kind==='short'?name.short:name.full;
    const now=String(row.name||'').trim();
    if(!next||now===next)return row;
    if(now&&now!==name.full&&now!==name.short)typed++;
    changed++;
    return Object.assign({},row,{name:next});
   });
   const missing=(data.missing||[]).length;
   const kept=missing?' Amazon did not return '+missing+' product'+(missing===1?'':'s')+', so '+(missing===1?'its name was':'their names were')+' kept.':'';
   if(!changed){say((kind==='short'?'All names are already short.':'All names already match Amazon.')+kept);return;}
   if(typed&&!window.confirm(typed+' product name'+(typed===1?'':'s')+' you typed yourself will be replaced. Continue?\n\nNothing is saved until you click Update / Publish.')){say('Names unchanged.');return;}
   products.dispatchEvent(new CustomEvent('atp:restore-draft-rows',{detail:{rows:updated,
    message:'Product names updated. Review them, then click Update / Publish to save.'}}));
   say('Renamed '+changed+' product'+(changed===1?'':'s')+(kind==='short'?' to Brand | Model.':' to the full Amazon title.')+kept+' Nothing is saved until you click Update / Publish.');
  }).catch(error=>say(error.message,true))
   .finally(()=>buttons.forEach(b=>{b.disabled=false;}));
 }
 if(shortButton)shortButton.addEventListener('click',()=>rename('short'));
 if(fullButton)fullButton.addEventListener('click',()=>rename('full'));

 // An untitled table is named after its products as they are added.
 let pending=0;
 products.addEventListener('atp:editor-change',()=>{
  window.clearTimeout(pending);
  pending=window.setTimeout(()=>{
   const title=names.tableTitle(rows().map(row=>row.name||''));
   if(title)names.setTitle(title);
  },300);
 });
})();
