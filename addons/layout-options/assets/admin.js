/* AzlinkPRO 1.4.8 — table editor for the Full, Compact and Minimal layouts: the layout
   sample and the quick list to reorder or remove products. Paid builds load
   premium/assets/admin.js instead, which adds the comparison editors. */
(function () {
  'use strict';
  const table = document.querySelector('.azp-layout-admin[data-azp-type="table"]');
  if (!table) return;
  const selector = table.querySelector('#azp-table-layout');
  const samplePreview = table.querySelector('.azp-table-preview');
  const quickPanel = table.querySelector('#azp-quick-remove-panel');
  const quickItems = table.querySelector('#azp-quick-remove-items');
  const products = document.getElementById('atp-builder');
  const productJSON = document.getElementById('atp-rows-json');

  function productRows() {
    try {const list=JSON.parse(productJSON && productJSON.value || '[]');return Array.isArray(list)?list:[];}
    catch(e){return [];}
  }
  function validAsin(v){return /^[A-Z0-9]{10}$/.test(v);}
  function el(tag,className,txt){
    const node=document.createElement(tag);
    if(className)node.className=className;
    if(txt!==undefined)node.textContent=txt;
    return node;
  }
  function productTitle(p){return p.name || String(p.asin||'').trim().toUpperCase();}
  let photoIndex=null;
  function productPhoto(p){
    if(p.image_preview) return p.image_preview;
    if(!photoIndex){
      photoIndex=new Map();
      if(products){
        products.querySelectorAll('.atp-editor-item').forEach(row=>{
          const asin=row.querySelector('.atp-asin')?.textContent.trim().toUpperCase();
          const photo=row.querySelector('.atp-image-preview img');
          if(asin && photo?.src)photoIndex.set(asin,photo.src);
        });
      }
    }
    return photoIndex.get(String(p.asin||'').toUpperCase())||'';
  }
  function rowActions(p,position,total){
    const asin=String(p.asin||'').trim().toUpperCase();
    const group=el('span','azp-preview-row-actions');
    group.setAttribute('role','group');
    group.setAttribute('aria-label','Arrange '+productTitle(p));
    [['up','↑ Up'],['down','↓ Down']].forEach(([direction,label])=>{
      const button=el('button','button azp-preview-move',label);
      button.type='button';
      button.dataset.azpMoveAsin=asin;
      button.dataset.azpMoveDirection=direction;
      button.disabled=direction==='up'?position===0:position===total-1;
      button.setAttribute('aria-label','Move '+productTitle(p)+' '+direction+' one row');
      button.title='Changes remain a draft until Update / Publish';
      group.appendChild(button);
    });
    const remove=el('button','button-link-delete azp-preview-remove','Remove');
    remove.type='button';
    remove.dataset.azpRemoveAsin=asin;
    remove.setAttribute('aria-label','Remove '+productTitle(p)+' from this table');
    remove.title='Remove this product (save the table to publish changes)';
    group.appendChild(remove);
    return group;
  }
  // The standard Table builder owns product JSON, confirmation and removal;
  // these buttons only ask it to act.
  table.addEventListener('click',event=>{
    const button=event.target.closest('button[data-azp-remove-asin]');
    if(!button || !table.contains(button) || !products)return;
    event.preventDefault();
    const asin=button.dataset.azpRemoveAsin;
    if(!validAsin(asin))return;
    products.dispatchEvent(new CustomEvent('atp:remove-product',{bubbles:false,detail:{asin}}));
  });
  table.addEventListener('click',event=>{
    const button=event.target.closest('button[data-azp-move-asin]');
    if(!button || !table.contains(button) || !products || button.disabled)return;
    event.preventDefault();
    const asin=button.dataset.azpMoveAsin;
    const direction=button.dataset.azpMoveDirection;
    if(!validAsin(asin) || (direction!=='up' && direction!=='down'))return;
    products.dispatchEvent(new CustomEvent('atp:move-product',{bubbles:false,detail:{asin,direction}}));
    // Return keyboard focus to the same product after the list re-renders.
    requestAnimationFrame(()=>{
      let focus=table.querySelector('#azp-quick-remove-items button[data-azp-move-asin="'+asin+'"][data-azp-move-direction="'+direction+'"]');
      if(focus&&focus.disabled)focus=table.querySelector('#azp-quick-remove-items button[data-azp-move-asin="'+asin+'"]:not(:disabled)');
      if(focus)focus.focus({preventScroll:true});
    });
  });
  function renderQuickRemove(){
    photoIndex=null;
    if(!quickItems)return;
    quickItems.replaceChildren();
    const all=productRows().filter(p=>validAsin(String(p.asin||'').trim().toUpperCase()));
    if(!all.length){quickItems.appendChild(el('p','description','No products yet. Add an ASIN below.'));return;}
    // Match the published order so Up and Down are unambiguous.
    all.forEach((p,index)=>{
      const item=el('div','azp-quick-remove-item');
      const name=el('div','azp-quick-remove-name');
      const url=productPhoto(p);
      if(url){const photo=el('img','');photo.src=url;photo.alt='';photo.loading='lazy';name.appendChild(photo);}
      const labels=el('span','');labels.appendChild(el('strong','',p.name||p.asin));
      labels.appendChild(el('small','',String(p.asin||'').toUpperCase()));name.appendChild(labels);
      item.appendChild(name);item.appendChild(rowActions(p,index,all.length));quickItems.appendChild(item);
    });
  }
  function refresh(){
    if(samplePreview){samplePreview.className='azp-layout-preview azp-table-preview azp-table-preview--'+(selector?selector.value:'full');samplePreview.hidden=false;}
    if(quickPanel)quickPanel.hidden=false;
    renderQuickRemove();
  }
  // A restored table backup may carry its layout.
  table.addEventListener('azp:restore-draft-layout',event=>{
    const layout=(event.detail || {}).layout;
    if(selector && typeof layout==='string' && selector.querySelector('option[value="'+layout+'"]'))selector.value=layout;
    refresh();
  });
  if(selector)selector.addEventListener('change',refresh);
  if(products){
    new MutationObserver(renderQuickRemove).observe(products,{childList:true});
    products.addEventListener('input',renderQuickRemove);
    products.addEventListener('change',renderQuickRemove);
  }
  refresh();
})();
