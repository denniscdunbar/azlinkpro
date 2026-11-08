/* AzlinkPRO 1.4.0-beta.21 — shared "Brand | Model" helpers for the Table,
   Showcase and Pros & Cons editors: look up names from Amazon, and fill in an
   empty WordPress title. A title you type yourself is never replaced. */
(function(){
  'use strict';
  const config=window.azpProductNamesConfig||{};
  const style=config.style==='full'?'full':'short';
  let autoTitle=null; // the last title this script wrote

  function lookup(asins){
    const body=new URLSearchParams();
    body.append('action','azlinkpro_product_names');
    body.append('nonce',config.nonce||'');
    asins.forEach(asin=>body.append('asins[]',asin));
    return fetch(config.ajax,{method:'POST',credentials:'same-origin',body})
      .then(response=>response.json().catch(()=>null))
      .then(json=>{
        if(!json||!json.success)throw new Error((json&&json.data&&json.data.message)||'Amazon names could not be loaded. Try again.');
        return json.data;
      });
  }
  // The name to use under the Settings choice: short "Brand | Model" or Amazon's full title.
  function pick(full,short){
    return String((style==='short'&&short)?short:(full||'')).trim();
  }
  // Fill the title while it is empty, or still holds a title this script wrote.
  function setTitle(text){
    const input=document.getElementById('title');
    text=String(text||'').trim().slice(0,200);
    if(!input||!text)return false;
    const current=input.value.trim();
    if(current&&current!==autoTitle)return false;
    autoTitle=text;
    if(current===text)return true;
    input.value=text;
    const prompt=document.getElementById('title-prompt-text');
    if(prompt)prompt.classList.add('screen-reader-text');
    input.dispatchEvent(new Event('input',{bubbles:true}));
    return true;
  }
  // A table title from its product names: "3D MAXpider vs TAPTES vs Snuuzu".
  function brandOf(name){
    const split=name.indexOf(' | ');
    return split>0?name.slice(0,split).trim():name.split(/\s+/).slice(0,3).join(' ');
  }
  function tableTitle(names){
    names=names.map(name=>String(name||'').trim()).filter(Boolean);
    if(!names.length)return '';
    if(names.length===1)return names[0];
    const brands=[...new Set(names.map(brandOf))];
    if(brands.length===1)return brands[0]+' comparison';
    if(brands.length<=3)return brands.join(' vs ');
    return brands.slice(0,3).join(', ')+' + '+(brands.length-3)+' more';
  }
  window.azpProductNames={style,lookup,pick,setTitle,tableTitle};

  // Pros & Cons: typing the button's ASIN names an untitled card.
  if(config.postType==='atppc_card'){
    document.addEventListener('change',event=>{
      const field=event.target;
      if(!field||field.name!=='atppc[asin]')return;
      const asin=String(field.value||'').trim().toUpperCase();
      const title=document.getElementById('title');
      if(!/^[A-Z0-9]{10}$/.test(asin)||!title||(title.value.trim()&&title.value.trim()!==autoTitle))return;
      lookup([asin]).then(data=>{
        const name=data.names&&data.names[asin];
        if(name&&String(field.value||'').trim().toUpperCase()===asin)setTitle(pick(name.full,name.short));
      }).catch(()=>{});
    });
  }
})();
