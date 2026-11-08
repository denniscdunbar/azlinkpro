/* AzlinkPRO 1.3.30 — local backup export/restore for comparison tables. */
(function(){
 'use strict';
 const form=document.getElementById('post'), products=document.getElementById('atp-builder');
 const save=document.getElementById('atp-rows-json');
 const exportButton=document.getElementById('azp-export-table'), importFile=document.getElementById('azp-import-table');
 const status=document.getElementById('azp-table-transfer-status');
 // The Custom Comparison field (azp-custom-grid-data) exists only on paid plans; backups work without it.
 if(!form||!products||!save||!exportButton||!importFile||!status)return;
 const read=id=>document.getElementById(id)?.value||'';
 const maxBytes=1100000, textMax=3000;
 function note(message,error=false){status.textContent=message;status.classList.toggle('is-error',error);}
 function parseJSON(s){try{return JSON.parse(s);}catch(e){return null;}}
 function validRows(rows){
   if(!Array.isArray(rows)||rows.length>50)return false;
   const seen=new Set();
   for(const row of rows){
     if(!row||typeof row!=='object'||Array.isArray(row))return false;
     if(typeof row.asin!=='string'||!/^[A-Z0-9]{10}$/.test(row.asin.toUpperCase()))return false;
     const key=row.asin.toUpperCase();if(seen.has(key))return false;seen.add(key);
     for(const k of ['name','details','review','cta','amazon_image_key','image_size']){
       if(row[k]!==undefined && (typeof row[k]!=='string'||row[k].length>(k==='details'?10000:2000)))return false;
     }
     if(row.image_id!==undefined && (!Number.isSafeInteger(Number(row.image_id))||Number(row.image_id)<0))return false;
     if(row.image_custom_px!==undefined && !Number.isFinite(Number(row.image_custom_px)))return false;
   }
   return true;
 }
 function validGrid(grid,rows){
   if(!grid||typeof grid!=='object'||Array.isArray(grid))return false;
   if(!Number.isInteger(grid.columns)||grid.columns<1||grid.columns>8||!Number.isInteger(grid.rows)||grid.rows<1||grid.rows>20)return false;
   if(!Array.isArray(grid.headers)||grid.headers.length>8||grid.headers.some(v=>typeof v!=='string'||v.length>150))return false;
   if(!grid.product_cells||typeof grid.product_cells!=='object'||Array.isArray(grid.product_cells)||!grid.display_titles||typeof grid.display_titles!=='object'||Array.isArray(grid.display_titles))return false;
   // Preserve notes from products removed before saving: they may be re-added or restored via Undo.
   if(Object.keys(grid.product_cells).length>150 || Object.keys(grid.display_titles).length>150)return false;
   for(const [asin,cells] of Object.entries(grid.product_cells)){
     if(!/^[A-Z0-9]{10}$/.test(asin)||!Array.isArray(cells)||cells.length>8||cells.some(v=>v!==undefined&&(typeof v!=='string'||v.length>textMax)))return false;
   }
   for(const [asin,title] of Object.entries(grid.display_titles)){
     if(!/^[A-Z0-9]{10}$/.test(asin)||typeof title!=='string'||title.length>160)return false;
   }
   return true;
 }
 // Export only durable WordPress product settings. Do not archive temporary
 // Amazon photo URLs, gallery results, or other API-derived preview metadata.
 function snapshot(){
   const sourceRows=parseJSON(read('atp-rows-json'));
   const stableFields=['asin','name','details','review','featured','image_id',
                       'image_size','image_custom_px','amazon_image_key','cta'];
   const rows=Array.isArray(sourceRows)?sourceRows.map(row=>{
     const stable={};if(!row||typeof row!=='object')return stable;
     stableFields.forEach(key=>{if(Object.prototype.hasOwnProperty.call(row,key))stable[key]=row[key];});
     return stable;
   }):sourceRows;
   return {
     title:read('title'),rows,
     grid:document.getElementById('azp-custom-grid-data')?parseJSON(read('azp-custom-grid-data')):null,
     layout:read('azp-table-layout'),headerColor:read('atp-header-color'),
     ribbon:read('atp-ribbon'),disclosure:form.querySelector('[name="atp_disclosure"]')?.value||''
   };
 }
 function validState(state){
   if(!state||typeof state!=='object'||Array.isArray(state)||!validRows(state.rows)||(state.grid!==null&&!validGrid(state.grid,state.rows)))return false;
   if(!['full','compact','minimal','custom'].includes(state.layout))return false;
   if(typeof state.title!=='string'||state.title.length>1000||
      typeof state.headerColor!=='string'||!/^#[0-9a-f]{6}$/i.test(state.headerColor)||
      typeof state.ribbon!=='string'||state.ribbon.length>200||
      typeof state.disclosure!=='string'||state.disclosure.length>2000)return false;
   return true;
 }
 exportButton.addEventListener('click',()=>{
   const state=snapshot();
   if(!validState(state)){note('Table data could not be validated for backup. Review the product ASINs and column settings.',true);return;}
   const payload={format:'azlinkpro-table-backup',schema:1,createdAt:new Date().toISOString(),
     sourceOrigin:window.location.origin,sourcePostId:Number(form.querySelector('[name="post_ID"]')?.value||0),state};
   const json=JSON.stringify(payload,null,2);
   if(json.length>maxBytes){note('This table exceeds the 1.1 MB browser backup limit.',true);return;}
   let url;
   try{
     url=URL.createObjectURL(new Blob([json],{type:'application/json'}));
     const link=document.createElement('a');link.href=url;link.download='azlinkpro-table-'+(payload.sourcePostId||'draft')+'-'+new Date().toISOString().slice(0,10)+'.json';
     document.body.appendChild(link);link.click();link.remove();
     note('Backup downloaded. It contains table products and layout settings, not API credentials or affiliate account secrets.');
   }catch(e){note('The browser could not create a download.',true);}
   finally{if(url)setTimeout(()=>URL.revokeObjectURL(url),1000);}
 });
 importFile.addEventListener('change',async()=>{
   const file=importFile.files?.[0];importFile.value='';if(!file)return;
   if(file.size>maxBytes){note('Backup is too large (maximum 1.1 MB).',true);return;}
   let payload;
   try{payload=JSON.parse(await file.text());}catch(e){note('The selected file is not valid JSON.',true);return;}
   if(!payload||payload.format!=='azlinkpro-table-backup'||payload.schema!==1||!validState(payload.state)){
     note('This is not a supported AzlinkPRO table backup or it contains invalid product data.',true);return;
   }
   // WordPress media attachment IDs cannot be trusted on another installation.
   if(payload.sourceOrigin!==window.location.origin){note('This backup belongs to a different website. Restore it on its original site so WordPress images remain correctly linked.',true);return;}
   if(!window.confirm('Replace the current editor contents with this table backup?\n\nNothing is published until Update / Publish. Any unsaved edits in the open editor will be replaced.')){
     note('Restore canceled. Your table has not changed.');return;
   }
   const state=payload.state;
   try{
     const title=document.getElementById('title');if(title)title.value=state.title;
     const header=document.getElementById('atp-header-color');if(header)header.value=state.headerColor;
     const ribbon=document.getElementById('atp-ribbon');if(ribbon)ribbon.value=state.ribbon;
     const disclosure=form.querySelector('[name="atp_disclosure"]');if(disclosure)disclosure.value=state.disclosure;
     products.dispatchEvent(new CustomEvent('atp:restore-draft-rows',{detail:{rows:state.rows}}));
     const layout=form.querySelector('.azp-layout-admin[data-azp-type="table"]');
     layout?.dispatchEvent(new CustomEvent('azp:restore-draft-layout',{detail:{layout:state.layout,grid:state.grid}}));
     header?.dispatchEvent(new Event('input',{bubbles:true}));
     title?.dispatchEvent(new Event('input',{bubbles:true}));
     note('Backup restored into this editor. Review products and comparison values, then click Update / Publish to save.');
     title?.focus();
   }catch(e){note('Restore was interrupted. Reload the editor before saving.',true);}
 });
})();
