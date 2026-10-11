(() => {
  const $ = (id) => document.getElementById(id);
  const gamesBox = $('games');
  const productsBox = $('products');
  const search = $('search');
  let games = [];
  let banners = [];
  let bannerIndex = 0;
  let bannerTimer = null;

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const rupiah = (value) => new Intl.NumberFormat('id-ID', {style:'currency',currency:'IDR',maximumFractionDigits:0}).format(Number(value) || 0);

  async function getJson(url) {
    const response = await fetch(url, {headers:{'Accept':'application/json'}, credentials:'same-origin'});
    if (!response.ok) throw new Error('request_failed');
    return response.json();
  }

  function renderGames() {
    const query = search.value.trim().toLocaleLowerCase('id-ID');
    const filtered = games.filter((game) => String(game.name).toLocaleLowerCase('id-ID').includes(query));
    $('game-count').textContent = filtered.length + ' game';
    if (!filtered.length) {
      gamesBox.innerHTML = '<div class="notice">Game tidak ditemukan.</div>';
      return;
    }
    gamesBox.innerHTML = filtered.map((game) => {
      const image = game.icon_url
        ? '<img loading="lazy" src="' + escapeHtml(game.icon_url) + '" alt="">'
        : '<img alt="" aria-hidden="true">';
      return '<button class="game" type="button" data-slug="' + escapeHtml(game.slug) + '">' + image + '<span class="game-name">' + escapeHtml(game.name) + '</span></button>';
    }).join('');
  }

  function showCatalog() {
    $('catalog-view').classList.remove('hidden');
    $('product-view').classList.add('hidden');
    search.disabled = false;
    renderGames();
  }

  async function showProducts(game) {
    $('catalog-view').classList.add('hidden');
    $('product-view').classList.remove('hidden');
    $('selected-game').textContent = game.name;
    search.disabled = true;
    productsBox.innerHTML = '<div class="notice">Memuat produk...</div>';
    try {
      const response = await getJson('/api/games/' + encodeURIComponent(game.slug) + '/products');
      const items = Array.isArray(response.data) ? response.data : [];
      if (!items.length) {
        productsBox.innerHTML = '<div class="notice">Belum ada produk untuk game ini.</div>';
        return;
      }
      productsBox.innerHTML = items.map((product) =>
        '<article class="product"><strong>' + escapeHtml(product.name) + '</strong><div class="price">' + rupiah(product.selling_price) + '</div>' +
        (product.is_available ? '<div class="muted">Tersedia di katalog</div>' : '<div class="unavailable">Tidak tersedia saat ini</div>') +
        '</article>'
      ).join('');
    } catch (_) {
      productsBox.innerHTML = '<div class="notice">Katalog produk belum dapat diakses. Silakan coba lagi nanti.</div>';
    }
  }

  function setBanner() {
    if (!banners.length) return;
    const banner = banners[bannerIndex % banners.length];
    bannerIndex++;
    const image = $('banner-image');
    image.src = banner.image_url;
    image.alt = banner.title || 'Banner promosi';
    image.style.display = 'block';
    const hero = image.parentElement;
    if (banner.target_url) {
      hero.style.cursor = 'pointer';
      hero.onclick = () => {
        try {
          const target = new URL(banner.target_url, location.href);
          if (target.protocol === 'https:' || target.protocol === 'http:') location.href = target.href;
        } catch (_) {}
      };
    } else {
      hero.style.cursor = '';
      hero.onclick = null;
    }
  }

  search.addEventListener('input', renderGames);
  gamesBox.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-slug]');
    if (!button) return;
    const game = games.find((item) => item.slug === button.dataset.slug);
    if (game) showProducts(game);
  });
  $('back').addEventListener('click', showCatalog);

  Promise.all([getJson('/api/games'), getJson('/api/banners')]).then(([gameResult, bannerResult]) => {
    games = Array.isArray(gameResult.data) ? gameResult.data : [];
    banners = Array.isArray(bannerResult.data) ? bannerResult.data : [];
    renderGames();
    if (banners.length) {
      setBanner();
      if (banners.length > 1) bannerTimer = setInterval(setBanner, 3000);
    }
  }).catch(() => {
    gamesBox.innerHTML = '<div class="notice">Katalog belum siap. Pastikan database dan migrasi sudah dipasang.</div>';
    $('game-count').textContent = '';
  });

  window.addEventListener('pagehide', () => {
    if (bannerTimer) clearInterval(bannerTimer);
  });
})();