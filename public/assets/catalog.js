(() => {
  const $ = (id) => document.getElementById(id);
  const gamesBox = $('games');
  const productsBox = $('products');
  const fieldsBox = $('game-fields');
  const search = $('search');
  let games = [];
  let banners = [];
  let bannerIndex = 0;
  let bannerTimer = null;
  let activeGame = null;
  let gameFields = [];
  let selectedProduct = null;
  let accountDataValid = false;

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
    activeGame = null;
    selectedProduct = null;
    accountDataValid = false;
    renderGames();
  }

  function renderFields() {
    if (!gameFields.length) {
      fieldsBox.innerHTML = '<p class="muted">Formulir akun belum dikonfigurasi untuk game ini. Hubungi admin sebelum transaksi diaktifkan.</p>';
      $('validate-fields').disabled = true;
      return;
    }
    $('validate-fields').disabled = false;
    fieldsBox.innerHTML = gameFields.map((field) => {
      const id = 'game-field-' + String(field.field_key).replace(/[^a-zA-Z0-9_-]/g, '-');
      const required = field.is_required ? ' required' : '';
      const label = '<label for="' + escapeHtml(id) + '">' + escapeHtml(field.label) + (field.is_required ? ' <span aria-hidden="true">*</span>' : '') + '</label>';
      if (field.field_type === 'select') {
        const options = Array.isArray(field.options) ? field.options : [];
        return '<div class="field">' + label + '<select id="' + escapeHtml(id) + '" name="' + escapeHtml(field.field_key) + '"' + required + '><option value="">Pilih ' + escapeHtml(field.label) + '</option>' + options.map((option) => '<option value="' + escapeHtml(option.option_value) + '">' + escapeHtml(option.option_label) + '</option>').join('') + '</select></div>';
      }
      const type = field.field_type === 'number' ? 'text' : 'text';
      const inputMode = field.field_type === 'number' ? ' inputmode="numeric"' : '';
      return '<div class="field">' + label + '<input id="' + escapeHtml(id) + '" name="' + escapeHtml(field.field_key) + '" type="' + type + '"' + inputMode + ' placeholder="' + escapeHtml(field.placeholder || '') + '"' + required + ' autocomplete="off"></div>';
    }).join('');
  }

  function showMessage(message, isError) {
    const box = $('field-message');
    box.textContent = message;
    box.classList.remove('hidden');
    box.classList.toggle('error-message', Boolean(isError));
  }

  function validateAccountForm(event) {
    event.preventDefault();
    accountDataValid = false;
    selectedProduct = null;
    const form = $('account-form');
    if (!gameFields.length) {
      showMessage('Data akun belum bisa diperiksa karena formulir game belum tersedia.', true);
      return;
    }
    if (!form.reportValidity()) return;

    for (const field of gameFields) {
      const input = form.elements.namedItem(field.field_key);
      if (!input) continue;
      const value = String(input.value || '').trim();
      if (field.is_required && !value) {
        input.focus();
        showMessage('Lengkapi kolom ' + field.label + '.', true);
        return;
      }
      if (value && field.validation_regex) {
        try {
          if (!(new RegExp('^(?:' + field.validation_regex + ')$')).test(value)) {
            input.focus();
            showMessage('Format ' + field.label + ' tidak sesuai.', true);
            return;
          }
        } catch (_) {
          showMessage('Aturan validasi untuk ' + field.label + ' belum valid. Hubungi admin.', true);
          return;
        }
      }
    }
    accountDataValid = true;
    showMessage('Format data akun lolos pemeriksaan di browser. Ini bukan verifikasi username ke server game.', false);
    $('checkout-note').textContent = 'Data input siap. Checkout dan pembayaran belum tersedia; belum ada pesanan yang dibuat.';
  }

  async function showProducts(game) {
    activeGame = game;
    selectedProduct = null;
    accountDataValid = false;
    $('catalog-view').classList.add('hidden');
    $('product-view').classList.remove('hidden');
    $('selected-game').textContent = game.name;
    search.disabled = true;
    productsBox.innerHTML = '<div class="notice">Memuat produk...</div>';
    fieldsBox.innerHTML = '<p class="muted">Memuat formulir...</p>';
    $('field-message').classList.add('hidden');
    $('checkout-note').textContent = 'Pilih game dan isi data akun. Pembelian dan pembayaran online belum diaktifkan.';

    const [productsResult, fieldsResult] = await Promise.allSettled([
      getJson('/api/games/' + encodeURIComponent(game.slug) + '/products'),
      getJson('/api/games/' + encodeURIComponent(game.slug) + '/fields')
    ]);

    if (fieldsResult.status === 'fulfilled') {
      gameFields = Array.isArray(fieldsResult.value.data) ? fieldsResult.value.data : [];
      renderFields();
    } else {
      gameFields = [];
      fieldsBox.innerHTML = '<div class="notice">Formulir akun belum dapat dimuat.</div>';
      $('validate-fields').disabled = true;
    }

    if (productsResult.status !== 'fulfilled') {
      productsBox.innerHTML = '<div class="notice">Katalog produk belum dapat diakses. Silakan coba lagi nanti.</div>';
      return;
    }
    const items = Array.isArray(productsResult.value.data) ? productsResult.value.data : [];
    if (!items.length) {
      productsBox.innerHTML = '<div class="notice">Belum ada produk untuk game ini.</div>';
      return;
    }
    productsBox.innerHTML = items.map((product, index) =>
      '<article class="product"><strong>' + escapeHtml(product.name) + '</strong><div class="price">' + rupiah(product.selling_price) + '</div>' +
      (product.is_available
        ? '<button type="button" class="select-product" data-product-index="' + index + '">Pilih nominal</button>'
        : '<div class="unavailable">Tidak tersedia saat ini</div>') +
      '</article>'
    ).join('');
    productsBox.querySelectorAll('button[data-product-index]').forEach((button) => {
      button.addEventListener('click', async () => {
        if (!accountDataValid) {
          showMessage('Isi dan periksa data akun terlebih dahulu sebelum memilih nominal.', true);
          $('account-form').scrollIntoView({behavior:'smooth', block:'start'});
          return;
        }
        selectedProduct = items[Number(button.dataset.productIndex)];
        button.disabled = true;
        button.textContent = 'Menghitung...';
        try {
          const account = {};
          for (const field of gameFields) {
            const input = $('account-form').elements.namedItem(field.field_key);
            if (input) account[field.field_key] = String(input.value || '').trim();
          }
          const payload = {game_slug: activeGame.slug, product_id: selectedProduct.id, account};
          const response = await fetch('/checkout-quote.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json'},
            credentials: 'same-origin',
            body: new URLSearchParams({payload: JSON.stringify(payload)})
          });
          const result = await response.json();
          if (!response.ok || !result.data) throw new Error(result.message || result.error || 'quote_failed');
          const quote = result.data;
          showMessage('Harga dihitung ulang oleh server. Belum ada pesanan atau pembayaran yang dibuat.', false);
          $('checkout-note').textContent = 'Rincian: ' + quote.product.name + ' — harga ' + rupiah(quote.price.selling_price) + ', biaya admin ' + rupiah(quote.price.admin_fee) + ', total ' + rupiah(quote.price.total) + '. Ini hanya kutipan; pembayaran belum aktif.';
          productsBox.querySelectorAll('.select-product').forEach((item) => {
            item.textContent = item === button ? 'Harga dihitung' : 'Pilih nominal';
            item.setAttribute('aria-pressed', item === button ? 'true' : 'false');
          });
        } catch (_) {
          showMessage('Server belum dapat menghitung harga. Periksa konfigurasi database dan aturan harga.', true);
          button.disabled = false;
          button.textContent = 'Pilih nominal';
        }
      });
    });
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
  $('account-form').addEventListener('submit', validateAccountForm);
  $('account-form').addEventListener('input', () => {
    accountDataValid = false;
    selectedProduct = null;
    $('field-message').classList.add('hidden');
  });
  $('account-form').addEventListener('change', () => {
    accountDataValid = false;
    selectedProduct = null;
    $('field-message').classList.add('hidden');
  });

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