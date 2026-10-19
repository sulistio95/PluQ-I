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
  let gameMeta = {username_check_available: false};
  let usernameTimer = null;
  let usernameRequestId = 0;
  let selectedProduct = null;
  let accountDataValid = false;
  let lastQuote = null;
  let paymentRequestKey = null;
  const paymentButton = document.createElement('button');
  paymentButton.type = 'button';
  paymentButton.textContent = 'Lanjut ke pembayaran Duitku (sandbox)';
  paymentButton.hidden = true;
  $('checkout-note').after(paymentButton);
  paymentButton.addEventListener('click', async () => {
    if (!lastQuote || !lastQuote.payment_enabled || !selectedProduct || !activeGame) return;
    const email = String($('customer-email')?.value || '').trim();
    paymentRequestKey = paymentRequestKey || crypto.randomUUID();
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      showMessage('Masukkan email yang valid untuk pembayaran.', true);
      $('customer-email')?.focus();
      return;
    }
    paymentButton.disabled = true;
    paymentButton.textContent = 'Menghubungkan ke Duitku sandbox...';
    try {
      const account = {};
      for (const field of gameFields) {
        const input = $('account-form').elements.namedItem(field.field_key);
        if (input) account[field.field_key] = String(input.value || '').trim();
      }
      const payload = {
        game_slug: activeGame.slug,
        product_id: selectedProduct.id,
        account,
        email,
        idempotency_key: paymentRequestKey
      };
      const response = await fetch('/create-payment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json'},
        credentials: 'same-origin',
        body: new URLSearchParams({payload: JSON.stringify(payload)})
      });
      const result = await response.json();
      if (!response.ok || !result.data || !result.data.payment_url) {
        throw new Error(result.message || result.error || 'payment_unavailable');
      }
      const paymentUrl = new URL(result.data.payment_url);
      if (paymentUrl.protocol !== 'https:') throw new Error('invalid_payment_url');
      window.location.assign(paymentUrl.href);
    } catch (error) {
      showMessage(error.message || 'Pembayaran sandbox belum dapat dibuat. Periksa konfigurasi server.', true);
      paymentButton.disabled = false;
      paymentButton.textContent = 'Coba buka pembayaran sandbox';
    }
  });

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
    if (usernameTimer) clearTimeout(usernameTimer);
    usernameRequestId++;
    gameFields = [];
    gameMeta = {username_check_available:false,checkout_supported:false};
    $('catalog-view').classList.remove('hidden');
    $('product-view').classList.add('hidden');
    $('customer-email-wrap').classList.add('hidden');
    search.disabled = false;
    activeGame = null;
    selectedProduct = null;
    accountDataValid = false;
    renderGames();
  }

  function renderFields() {
    if (!gameFields.length) {
      fieldsBox.innerHTML = '<p class="muted">Skema akun otomatis belum tersedia untuk game ini.</p>';
      return;
    }
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

  function readAccount() {
    const account = {};
    for (const field of gameFields) {
      const input = $('account-form').elements.namedItem(field.field_key);
      account[field.field_key] = input ? String(input.value || '').trim() : '';
    }
    return account;
  }

  function localAccountIsReady(account) {
    for (const field of gameFields) {
      const value = String(account[field.field_key] || '');
      if (field.is_required && !value) return false;
      if (value && field.validation_regex) {
        try {
          if (!(new RegExp('^(?:' + field.validation_regex + ')$')).test(value)) return false;
        } catch (_) { return false; }
      }
    }
    return true;
  }

  function scheduleUsernameCheck() {
    if (usernameTimer) clearTimeout(usernameTimer);
    const requestId = ++usernameRequestId;
    accountDataValid = false;
    const account = readAccount();
    const resultBox = $('username-result');
    if (!localAccountIsReady(account)) {
      resultBox.textContent = 'Lengkapi semua kolom akun yang wajib diisi.';
      return;
    }
    if (!gameMeta.checkout_supported) {
      accountDataValid = true;
      resultBox.textContent = 'Form akun game ini belum dipetakan dengan aman. Transaksi dinonaktifkan sampai kebutuhan ID dan server/zone diverifikasi.';
      return;
    }
    if (!gameMeta.username_check_available) {
      accountDataValid = true;
      resultBox.textContent = 'Pengecekan username belum didukung oleh supplier untuk game ini. Pastikan ID dan server/zone benar.';
      return;
    }
    resultBox.textContent = 'Memeriksa username secara otomatis...';
    usernameTimer = setTimeout(async () => {
      try {
        const response = await fetch('/api/games/' + encodeURIComponent(activeGame.slug) + '/check-username', {
          method: 'POST',
          headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},
          credentials: 'same-origin',
          body: new URLSearchParams({payload: JSON.stringify({account})})
        });
        const data = await response.json();
        if (requestId !== usernameRequestId) return;
        if (!response.ok || !data.data || !data.data.username) {
          if (response.status === 409) {
            accountDataValid = true;
            resultBox.textContent = 'Pengecekan username tidak tersedia untuk game ini. Pastikan data akun benar.';
            return;
          }
          accountDataValid = false;
          resultBox.textContent = data.message || 'Username belum dapat diverifikasi. Periksa ID game dan server/zone.';
          return;
        }
        accountDataValid = true;
        resultBox.textContent = data.data.username;
        showMessage('Username berhasil diverifikasi.', false);
      } catch (_) {
        if (requestId !== usernameRequestId) return;
        accountDataValid = false;
        resultBox.textContent = 'Pengecekan username gagal terhubung. Coba lagi setelah beberapa saat.';
      }
    }, 800);
  }

  async function showProducts(game) {
    if (usernameTimer) clearTimeout(usernameTimer);
    usernameRequestId++;
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
    $('customer-email-wrap').classList.add('hidden');
    $('username-result').textContent = 'Isi data akun; username akan diperiksa otomatis jika API mendukungnya.';
    $('checkout-note').textContent = 'Pilih game dan isi data akun. Pembayaran sandbox belum dimulai.';
    paymentButton.hidden = true;
    paymentButton.disabled = false;
    lastQuote = null;
    paymentRequestKey = null;

    const [productsResult, fieldsResult] = await Promise.allSettled([
      getJson('/api/games/' + encodeURIComponent(game.slug) + '/products'),
      getJson('/api/games/' + encodeURIComponent(game.slug) + '/fields')
    ]);

    if (fieldsResult.status === 'fulfilled') {
      gameFields = Array.isArray(fieldsResult.value.data) ? fieldsResult.value.data : [];
      gameMeta = fieldsResult.value.meta || {username_check_available:false};
      renderFields();
    } else {
      gameFields = [];
      gameMeta = {username_check_available:false};
      fieldsBox.innerHTML = '<div class="notice">Formulir akun otomatis belum dapat dimuat.</div>';
    }

    if (productsResult.status !== 'fulfilled') {
      productsBox.innerHTML = '<div class="notice">Katalog produk belum dapat diakses. Silakan coba lagi nanti.</div>';
      return;
    }
    const items = Array.isArray(productsResult.value.data) ? productsResult.value.data : [];
    if (!items.length) {
      productsBox.innerHTML = '<div class="notice">Belum ada produk yang tersedia untuk game ini saat ini.</div>';
      return;
    }
    productsBox.innerHTML = items.map((product, index) =>
      '<article class="product"><strong>' + escapeHtml(product.name) + '</strong><div class="price">' + rupiah(product.selling_price) + '</div>' +
      (product.is_available && gameMeta.checkout_supported
        ? '<button type="button" class="select-product" data-product-index="' + index + '">Pilih nominal</button>'
        : (product.is_available
          ? '<div class="unavailable">Form akun otomatis belum diverifikasi untuk game ini</div>'
          : '<div class="unavailable">Habis</div>')) +
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
          lastQuote = quote;
          paymentRequestKey = crypto.randomUUID();
          $('customer-email-wrap').classList.remove('hidden');
          paymentButton.hidden = !quote.payment_enabled;
          paymentButton.disabled = false;
          showMessage(quote.payment_enabled
            ? 'Harga dihitung ulang oleh server. Pembayaran tersedia pada lingkungan yang diaktifkan.'
            : 'Harga dihitung ulang oleh server. Pembayaran masih dinonaktifkan; belum ada pesanan yang dibuat.', false);
          $('checkout-note').textContent = 'Rincian: ' + quote.product.name + ' — harga ' + rupiah(quote.price.selling_price) + ', biaya admin ' + rupiah(quote.price.admin_fee) + ', total ' + rupiah(quote.price.total) + '. ' + (quote.payment_enabled ? 'Pembayaran tersedia pada lingkungan yang diaktifkan.' : 'Ini hanya kutipan; pembayaran belum aktif.');
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
  $('account-form').addEventListener('submit', (event) => event.preventDefault());
  function invalidateQuoteOnAccountChange() {
    accountDataValid = false;
    selectedProduct = null;
    lastQuote = null;
    paymentRequestKey = null;
    paymentButton.hidden = true;
    paymentButton.disabled = false;
    paymentButton.textContent = 'Lanjut ke pembayaran Duitku (sandbox)';
    $('field-message').classList.add('hidden');
    productsBox.querySelectorAll('.select-product').forEach((button) => {
      button.disabled = false;
      button.textContent = 'Pilih nominal';
      button.setAttribute('aria-pressed', 'false');
    });
  }
  $('account-form').addEventListener('input', () => {
    invalidateQuoteOnAccountChange();
    scheduleUsernameCheck();
  });
  $('account-form').addEventListener('change', () => {
    invalidateQuoteOnAccountChange();
    scheduleUsernameCheck();
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