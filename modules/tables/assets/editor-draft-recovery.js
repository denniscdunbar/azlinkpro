/* AzlinkPRO 1.3.29 — Opt-in browser draft recovery for saved comparison tables. */
(function () {
 'use strict';
 const form=document.getElementById('post');
 const products=document.getElementById('atp-builder');
 const productJSON=document.getElementById('atp-rows-json');
 const opts=typeof azpDraftRecovery==='object' && azpDraftRecovery ? azpDraftRecovery : {};
 const postId=Number(form?.querySelector('[name="post_ID"]')?.value || opts.postId || 0);
 const userId=Number(opts.userId || 0);
 if(!form || !products || !productJSON || !Number.isSafeInteger(postId) || postId<=0 || !Number.isSafeInteger(userId) || userId<=0) return;
 const key='azp-table-draft:1:'+location.pathname+':'+userId+':'+postId;
 const maxAge=Number(opts.maxAgeMs)||604800000; // 7 days
 const maxBytes=850000; // below 1.2 MB WordPress payload ceiling
 let storage;
 try {storage=window.localStorage;const k='azp:storage-check';storage.setItem(k,'1');storage.removeItem(k);}catch(e){return;}
 const $=id=>document.getElementById(id);
 const read=id=>$(id)?.value||'';
 const normalJSON=value=>{try{return JSON.stringify(JSON.parse(value));}catch(_){return value;}};
 function collect(){
   return {
     title:read('title'),
     products:normalJSON(read('atp-rows-json')),
     customComparison:normalJSON(read('azp-custom-grid-data')),
     tableLayout:read('azp-table-layout'),
     headerColor:read('atp-header-color'),
     ribbon:read('atp-ribbon'),
     disclosure:form.querySelector('[name="atp_disclosure"]')?.value || ''
   };
 }
 function stamp(data){return JSON.stringify(data);}
 function storageRead(){try{const val=storage.getItem(key);return val?JSON.parse(val):null;}catch(e){return null;}}
 function storageDelete(){try{storage.removeItem(key);}catch(e){/* storage unavailable */}}
 function isDraft(d){return d && d.schema===1 && d.postId===postId && d.userId===userId &&
   typeof d.baseline==='string' && typeof d.state==='string' &&
   typeof d.savedAt==='number' && d.savedAt<=Date.now()+60000 && Date.now()-d.savedAt<maxAge &&
   d.state.length<maxBytes && d.baseline.length<maxBytes;}
 function validState(s){
   if(!s || typeof s!=='object' || Array.isArray(s))return false;
   if(!['full','compact','minimal','custom'].includes(s.tableLayout))return false;
   if(typeof s.title!=='string' || typeof s.products!=='string' || typeof s.customComparison!=='string' ||
      typeof s.headerColor!=='string' || typeof s.ribbon!=='string' || typeof s.disclosure!=='string')return false;
   if(s.title.length>1000 || s.ribbon.length>200 || s.disclosure.length>2000)return false;
   let rows,grid;
   try{rows=JSON.parse(s.products);grid=JSON.parse(s.customComparison);}catch(e){return false;}
   if(!Array.isArray(rows) || rows.length>50 || rows.some(r=>!r || typeof r!=='object' ||
       !/^[A-Z0-9]{10}$/.test(String(r.asin||'').toUpperCase())))return false;
   if(new Set(rows.map(r=>String(r.asin).toUpperCase())).size!==rows.length)return false;
   if(!grid || typeof grid!=='object' || Array.isArray(grid) ||
      !Number.isInteger(grid.columns) || grid.columns<1 || grid.columns>8 ||
      !Number.isInteger(grid.rows) || grid.rows<1 || grid.rows>20 ||
      !Array.isArray(grid.headers) || grid.headers.length>8 ||
      !grid.product_cells || typeof grid.product_cells!=='object' || Array.isArray(grid.product_cells) ||
      !grid.display_titles || typeof grid.display_titles!=='object' || Array.isArray(grid.display_titles))return false;
   return {rows,grid};
 }
 const anchor=$('azp-unsaved-status')?.closest('.azp-save-guard');
 if(!anchor)return;
 const box=document.createElement('section');box.className='azp-draft-recovery';box.hidden=true;
 box.setAttribute('role','region');box.setAttribute('aria-label','Browser draft recovery');
 const heading=document.createElement('strong');heading.textContent='Unsaved browser draft available';
 const info=document.createElement('p');
 const actions=document.createElement('div');actions.className='azp-draft-recovery-actions';
 const restore=document.createElement('button');restore.type='button';restore.className='button button-primary';restore.textContent='Restore draft';
 const discard=document.createElement('button');discard.type='button';discard.className='button';discard.textContent='Discard saved draft';
 const status=document.createElement('span');status.setAttribute('role','status');status.setAttribute('aria-live','polite');
 actions.append(restore,discard);box.append(heading,info,actions,status);anchor.after(box);
 let baseline='', candidate=null, suspend=false, pending=null;
 function show(d,stale){
   candidate=d;
   suspend=true; // Preserve the older recovery copy until Restore or Discard.
   const when=new Date(d.savedAt);
   const date=Number.isFinite(when.getTime())?when.toLocaleString():'an earlier session';
   box.hidden=false;
   heading.textContent=stale?'Saved table changed since the browser draft':'Unsaved browser draft available';
   info.textContent=stale
     ?'A browser draft from '+date+' does not match the current saved table. Restore is blocked to avoid overwriting newer content. You can discard this old draft.'
     :'Found unsaved table edits from '+date+'. Review them before saving. This copy stays only in this browser.';
   restore.hidden=stale;restore.disabled=stale;
   status.textContent='';
 }
 function persist(){
   if(suspend || !baseline)return;
   const state=stamp(collect());
   if(state===baseline){storageDelete();return;}
   if(state.length>maxBytes)return;
   try{storage.setItem(key,JSON.stringify({schema:1,postId,userId,baseline,state,savedAt:Date.now()}));}catch(e){/* quota */}
 }
 function debounce(){if(suspend || !baseline)return;if(pending)clearTimeout(pending);pending=setTimeout(()=>{pending=null;persist();},550);}
 function flush(){if(pending){clearTimeout(pending);pending=null;}persist();}
 // Editor row mutations and hidden custom cells are recorded together.
 form.addEventListener('input',debounce,true);
 form.addEventListener('change',debounce,true);
 products.addEventListener('atp:editor-change',debounce);
 // Keep the last draft if the save fails; a successful reload will compare and clear it.
 document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='hidden')flush();});
 window.addEventListener('pagehide',flush);
 restore.addEventListener('click',()=>{
   if(!candidate || candidate.baseline!==baseline)return;
   const current=stamp(collect());
   if(current!==baseline && !window.confirm('Replace edits in this open tab with the recovered browser draft?'))return;
   let data,parsed;
   try{data=JSON.parse(candidate.state);parsed=validState(data);}catch(e){return;}
   if(!parsed){status.textContent='This recovery copy cannot be read safely.';return;}
   suspend=true;
   $('title').value=data.title;
   if($('atp-header-color'))$('atp-header-color').value=data.headerColor;
   if($('atp-ribbon'))$('atp-ribbon').value=data.ribbon;
   const disc=form.querySelector('[name="atp_disclosure"]');if(disc)disc.value=data.disclosure;
   products.dispatchEvent(new CustomEvent('atp:restore-draft-rows',{detail:{rows:parsed.rows}}));
   const layout=form.querySelector('.azp-layout-admin[data-azp-type="table"]');
   layout?.dispatchEvent(new CustomEvent('azp:restore-draft-layout',{detail:{layout:data.tableLayout,grid:parsed.grid}}));
   // Re-run other live display updates; do not post anything to WordPress.
   $('title')?.dispatchEvent(new Event('input',{bubbles:true}));
   $('atp-header-color')?.dispatchEvent(new Event('input',{bubbles:true}));
   suspend=false;
   box.hidden=true;
   candidate=null;
   persist();
   status.textContent='Recovered browser draft. Review it and use Update / Publish to save.';
   $('title')?.focus();
 });
 discard.addEventListener('click',()=>{
   storageDelete();candidate=null;box.hidden=true;suspend=false;
   debounce();
   status.textContent='Browser draft discarded; saved WordPress content was not changed.';
 });
 function initialize(){
   baseline=stamp(collect());
   const old=storageRead();
   if(!isDraft(old)){if(old)storageDelete();return;}
   if(old.state===baseline){storageDelete();return;} // saved successfully
   let valid;try{valid=validState(JSON.parse(old.state));}catch(e){valid=null;}
   if(!valid){storageDelete();return;}
   show(old,old.baseline!==baseline);
 }
 // Table and custom-layout editors and unsaved-change guard initialize on load.
 if(document.readyState==='complete')setTimeout(initialize,150);
 else window.addEventListener('load',()=>setTimeout(initialize,150),{once:true});
})();
