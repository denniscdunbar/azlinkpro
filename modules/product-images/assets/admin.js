(function () {
  'use strict';
  function $(id) { return document.getElementById(id); }
  var asinInput = $('azp-img-asin');
  if (!asinInput || !window.azpImageAdmin) { return; }
  var selectedKey = $('azp-img-image-key');
  var notice = $('azp-img-notice');
  var gallery = $('azp-img-gallery');
  var results = $('azp-img-results');
  var loading = false;
  function message(text) { notice.textContent = text || ''; }
  function call(op, fields) {
    var body = new URLSearchParams({action:'azp_img_ajax', nonce:azpImageAdmin.nonce, op:op});
    Object.keys(fields).forEach(function (key) {body.append(key,fields[key]);});
    return fetch(azpImageAdmin.ajax, {method:'POST',credentials:'same-origin',body:body})
      .then(function (r) {return r.json();})
      .then(function (response) {
        if (!response.success) {
          throw new Error((response.data && response.data.message) || 'Amazon request failed.');
        }
        return response.data;
      });
  }
  function renderGallery(data, preserve) {
    gallery.replaceChildren();
    var images = Array.isArray(data.images) ? data.images : [];
    if (!images.length) { message('Amazon returned no available product images.');return; }
    var saved = preserve ? selectedKey.value : '';
    if (!images.some(function (im) {return im.key === saved;})) {
      saved = images[0].key;
    }
    selectedKey.value = saved;
    images.forEach(function (item, index) {
      if (!item.url || !item.key) {return;}
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'azp-img-choice' + (item.key === saved ? ' is-selected':'');
      button.setAttribute('aria-pressed', item.key === saved ? 'true':'false');
      var image = document.createElement('img');
      image.src = item.url;
      image.alt = item.label || 'Amazon product image';
      image.loading = 'lazy';
      var name = document.createElement('span');
      name.textContent = item.label || 'Amazon photo ' + (index+1);
      button.appendChild(image);button.appendChild(name);
      button.addEventListener('click',function () {
        selectedKey.value=item.key;
        gallery.querySelectorAll('.azp-img-choice').forEach(function (element) {
          var active=element === button;
          element.classList.toggle('is-selected',active);
          element.setAttribute('aria-pressed', active?'true':'false');
        });
        message('Image selected. Click Save Draft, Update or Publish to keep it.');
      });
      gallery.appendChild(button);
    });
    message(images.length+' Amazon image'+(images.length===1?'':'s')+' available. Choose one and save the post.');
  }
  function fetchProduct(preserve) {
    var asin=asinInput.value.trim().toUpperCase();
    if (!/^[A-Z0-9]{10}$/.test(asin)) {message('Enter a valid 10-character ASIN.');return;}
    if (loading) {return;}
    loading=true;
    message('Fetching available Amazon images…');
    call('fetch',{asin:asin}).then(function (data) {
      asinInput.value=data.asin;
      renderGallery(data,preserve);
    }).catch(function (err) {message(err.message);})
      .finally(function () {loading=false;});
  }
  $('azp-img-fetch').addEventListener('click',function () {fetchProduct(true);});
  // 1.5.2: Load more adds the next 10 results below the first ones, and the
  // chosen product stays highlighted in the list instead of the list closing.
  var moreButton = $('azp-img-search-more');
  var searchTerm = '', searchPage = 0, searchBusy = false, searchSeen = new Set(), searchToken = 0;
  function markChosen() {
    var current = asinInput.value.trim().toUpperCase();
    results.querySelectorAll('.azp-img-result').forEach(function (btn) {
      var chosen = btn.getAttribute('data-asin') === current;
      btn.classList.toggle('is-selected', chosen);
      btn.setAttribute('aria-pressed', chosen ? 'true' : 'false');
    });
  }
  function runSearch(loadMore) {
    var query = $('azp-img-search').value.trim();
    if (query.length < 2) { message('Enter at least two characters.'); return; }
    if (searchBusy) { return; }
    if (!loadMore || query !== searchTerm) {
      searchTerm = query; searchPage = 0; searchSeen = new Set(); searchToken++;
      results.replaceChildren();
    }
    var token = searchToken, nextPage = searchPage + 1;
    searchBusy = true;
    if (moreButton) { moreButton.hidden = true; }
    message(nextPage === 1 ? 'Searching Amazon…' : 'Loading 10 more Amazon products…');
    call('search', {query: searchTerm, page: nextPage}).then(function (data) {
      if (token !== searchToken) { return; }
      searchPage = nextPage;
      var items = Array.isArray(data.items) ? data.items : [];
      var firstNew = null;
      items.forEach(function (item) {
        if (searchSeen.has(item.asin)) { return; }
        searchSeen.add(item.asin);
        var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'azp-img-result';
        btn.setAttribute('data-asin', String(item.asin).toUpperCase());
        if (item.image) { var img = document.createElement('img'); img.src = item.image; img.alt = ''; img.loading = 'lazy'; btn.appendChild(img); }
        var title = document.createElement('strong'); title.textContent = item.title; btn.appendChild(title);
        var asin = document.createElement('span'); asin.textContent = item.asin; btn.appendChild(asin);
        btn.addEventListener('click', function () {
          asinInput.value = item.asin;
          selectedKey.value = '';
          markChosen();
          fetchProduct(false);
        });
        results.appendChild(btn);
        if (!firstNew) { firstNew = btn; }
      });
      markChosen();
      if (moreButton) { moreButton.hidden = !data.has_more; }
      if (!searchSeen.size) { message('No products found. Try a different search.'); return; }
      message(searchSeen.size + ' products loaded. Select one to load its image gallery' + (data.has_more ? ', or click Load 10 more.' : '.'));
      if (loadMore && firstNew) { results.scrollTop = firstNew.offsetTop - results.offsetTop; }
    }).catch(function (err) {
      if (token === searchToken) { message(err.message); if (moreButton) { moreButton.hidden = searchPage === 0; } }
    }).finally(function () { if (token === searchToken) { searchBusy = false; } });
  }
  $('azp-img-search-btn').addEventListener('click', function () { runSearch(false); });
  if (moreButton) { moreButton.addEventListener('click', function () { runSearch(true); }); }
  $('azp-img-search').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); runSearch(false); } });
  asinInput.addEventListener('input', markChosen);
  if (asinInput.value.trim()) {fetchProduct(true);}
})();
