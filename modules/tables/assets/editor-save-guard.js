/* AzlinkPRO 1.3.26 — Unsaved table changes, native WordPress save, navigation warning. */
(function () {
  'use strict';
  const form = document.getElementById('post');
  const products = document.getElementById('atp-builder');
  if (!form || !products || !document.getElementById('atp-rows-json')) return;

  // Only serializable, intentionally editable content is tracked. Search inputs,
  // temporary previews, hidden UI panels and WordPress nonce fields are excluded.
  function read(id) {
    const field = document.getElementById(id);
    return field ? field.value : '';
  }
  function jsonValue(id) {
    const value = read(id);
    try { return JSON.stringify(JSON.parse(value)); } catch (_) { return value; }
  }
  function snapshot() {
    return JSON.stringify({
      title: read('title'),
      products: jsonValue('atp-rows-json'),
      customComparison: jsonValue('azp-custom-grid-data'),
      tableLayout: read('azp-table-layout'),
      headerColor: read('atp-header-color'),
      ribbon: read('atp-ribbon'),
      disclosure: form.querySelector('[name="atp_disclosure"]')?.value || ''
    });
  }

  const anchor = form.querySelector('.azp-layout-admin[data-azp-type="table"]') ||
    form.querySelector('#azp-standard-product-builder') || products;
  const bar = document.createElement('div');
  bar.className = 'azp-save-guard';
  bar.setAttribute('role', 'group');
  bar.setAttribute('aria-label', 'Comparison table save status');
  const dot = document.createElement('span'); dot.className='azp-save-dot'; dot.setAttribute('aria-hidden','true');
  const message = document.createElement('span'); message.id='azp-unsaved-status'; message.setAttribute('role','status'); message.setAttribute('aria-live','polite');
  const hint = document.createElement('span'); hint.className='azp-save-guard-help';
  hint.textContent='Changes are not public until you use WordPress Update / Publish.';
  const save = document.createElement('button'); save.type='button'; save.className='button button-primary azp-save-guard-action';
  const nativeSave = form.querySelector('#publish');
  const nativeLabel = (nativeSave && (nativeSave.value || nativeSave.textContent) || 'Save').trim();
  save.textContent = nativeLabel === 'Publish' ? 'Publish table' : nativeLabel === 'Update' ? 'Update table' : 'Save table';
  save.title='Uses the normal WordPress save action';
  if (!nativeSave) { save.hidden = true; }
  bar.append(dot,message,hint,save);
  anchor.parentNode.insertBefore(bar,anchor);

  // Defer the clean baseline until all plugins have initialized their editors.
  let baseline = null;
  let submitting = false;
  let rafPending = false;
  let lastDirty = null;
  function dirty() { return baseline !== null && snapshot() !== baseline; }
  function update() {
    rafPending=false;
    if (baseline===null) return;
    const changed = dirty();
    bar.classList.toggle('is-unsaved',changed);
    if(changed!==lastDirty) {
      message.textContent=changed ? 'Unsaved changes' : 'No unsaved changes';
      lastDirty=changed;
    }
    save.disabled=!changed || !nativeSave || !!nativeSave.disabled;
  }
  function scheduleUpdate() {
    if (rafPending) return;
    rafPending=true;
    window.requestAnimationFrame(update);
  }
  // Delegated listeners continue to work when rows, cells or headings re-render.
  form.addEventListener('input', scheduleUpdate, true);
  form.addEventListener('change', scheduleUpdate, true);
  products.addEventListener('atp:editor-change', scheduleUpdate);
  const observer = new MutationObserver(scheduleUpdate);
  observer.observe(products,{childList:true});
  save.addEventListener('click',()=>{
    if (!nativeSave || nativeSave.disabled) return;
    // The original WordPress button retains its validation, hooks and save flow.
    nativeSave.click();
  });
  form.addEventListener('submit',()=>{
    // Browsers may reject an invalid form without ever submitting it.
    if(typeof form.checkValidity==='function' && !form.checkValidity()) return;
    submitting=true;
    window.setTimeout(()=>{submitting=false;scheduleUpdate();},1500);
  });
  window.addEventListener('beforeunload',event=>{
    if (!submitting && dirty()) {
      event.preventDefault();
      event.returnValue=''; // Required for older browsers; browser supplies wording.
    }
  });
  function initialize() {
    baseline=snapshot();
    update();
  }
  if(document.readyState==='complete') window.setTimeout(initialize,0);
  else window.addEventListener('load',()=>window.setTimeout(initialize,0),{once:true});
})();
