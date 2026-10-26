// PRODUKTE: Werden beim Laden der Seite aus der Datenbank geholt (products.php).
// Namen, Preise und Filtereigenschaften in phpMyAdmin in der Tabelle products ändern.
let products = [];
// WARENKORB: Als Gast auf diesem Gerät (localStorage), nach dem Login in der Datenbank.
let basket = {};
try {
  basket = JSON.parse(localStorage.getItem('tierfutter-cart') || '{}')
} catch {};
let currentUser = null; // {name} wenn eingeloggt
let authMode = 'login';
let cartQueue = Promise.resolve(); // Warenkorb-Anfragen nacheinander senden
const money = n => 'CHF ' + n.toFixed(2);
// Text aus der Datenbank sicher ins HTML einsetzen (< > & " ' werden unschädlich).
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const ids = ['animal', 'type', 'special', 'taste', 'weight', 'age'];

// SERVER: PHP-Datei im Ordner api/ aufrufen, z. B. api('cart.php'). Mit data als POST (JSON), sonst GET.
async function api(file, data) {
  let res, json;
  try {
    res = await fetch('api/' + file, data ? {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    } : {});
    json = await res.json()
  } catch {
    throw new Error('Server nicht erreichbar. Seite über XAMPP öffnen (http://localhost/...).')
  }
  if (!res.ok) throw new Error(json.error || 'Unbekannter Fehler.');
  return json
}

// PRODUKTLISTE: Auswahl anhand der Filter anzeigen.
function render() {
  const vals = Object.fromEntries(ids.map(id => [id, document.getElementById(id).value]));

  const list = products.filter(p => (!vals.animal || p.animal === vals.animal) && (!vals.type || p.food_type === vals.type) && (!vals.special || p.special === vals.special) && (!vals.taste || p.taste === vals.taste) && (!vals.age || (+vals.age >= p.age_min && +vals.age <= p.age_max)) && (!vals.weight || (+vals.weight >= p.weight_min && +vals.weight <= p.weight_max)));

  document.getElementById('resultCount').textContent = list.length + ' Produkte entdecken';

  document.getElementById('products').innerHTML = list.map(p => `<article class="card" data-product-id="${p.product_id}">
    <div class="product-art" style="--tint:${esc(p.tint)};--bag:${esc(p.color)}">
    <span class="tag">${esc(p.tag)}</span><div class="bag">
    <small>TIERFUTTER-SHOP
    </small>
    <div class="animal">${p.animal==='Hund'?'🐕':p.animal==='Katze'?'🐈':'🐇'}</div>
    <b>${esc(p.taste)}</b>
    <div class="flavour">NATUR IM NAPF</div>
    <small>${esc(p.size)}</small>
    </div>
    </div>
    <div class="card-body">
    <div class="meta">${esc(p.animal)} · ${esc(p.food_type)}</div>
    <h3>${esc(p.name)}</h3>
    <p class="desc">${esc(p.description)}</p>
    <div class="price-line">
    <strong>${money(p.price)}</strong>
    <button class="add" onclick="add(${p.product_id})" aria-label="${esc(p.name)} zum Warenkorb hinzufügen">+</button>
    </div>
    </div>
    </article>`).join('') || '<div class="empty">Für diese Auswahl gibt es noch kein Beispielprodukt.<br>Probiere andere Filter oder setze die Auswahl zurück.</div>';

  document.getElementById('filterInfo').textContent = vals.age || vals.weight ? 'Beispielauswahl nach Alter und Gewicht – keine Fütterungsempfehlung.' : 'Dein nächstes Lieblingsfutter wartet schon.'
}
ids.forEach(id => document.getElementById(id).addEventListener('input', render));

// FILTER: Alle Eingaben zurücksetzen.
function resetFilters() {
  ids.forEach(id => document.getElementById(id).value = '');
  render()
}

// Gast-Warenkorb speichern und Anzahl im Header anzeigen.
function save() {
  if (!currentUser) try {
    localStorage.setItem('tierfutter-cart', JSON.stringify(basket))
  } catch {}
  document.getElementById('count').textContent = '(' + Object.values(basket).reduce((a, b) => a + b, 0) + ')'
}

function toast(text) {
  const t = document.getElementById('toast');
  t.textContent = text;
  t.style.display = 'block';
  clearTimeout(window.toastTimer);
  window.toastTimer = setTimeout(() => t.style.display = 'none', 2200)
}

// Eingeloggt: Änderung an cart.php senden, Antwort ist der neue Warenkorb.
function cartRequest(data) {
  cartQueue = cartQueue.catch(() => {}).then(() => api('cart.php', data)).then(res => {
    basket = res.items;
    save();
    if (document.getElementById('cart').open) renderCart()
  });
  return cartQueue
}

async function add(id) {
  if (currentUser) {
    try {
      await cartRequest({ action: 'add', product_id: id })
    } catch (err) {
      return toast(err.message)
    }
  } else {
    basket[id] = Math.min((basket[id] || 0) + 1, 99);
    save()
  }
  toast('♡ Zum Warenkorb hinzugefügt')
}

function change(id, n) {
  if (currentUser) return cartRequest({ action: 'change', product_id: id, delta: n }).catch(err => toast(err.message));
  basket[id] = Math.min((basket[id] || 0) + n, 99);
  if (basket[id] <= 0) delete basket[id];
  save();
  renderCart()
}

// WARENKORBFENSTER: Mengen und Gesamtpreis anzeigen.
function renderCart() {
  const items = products.filter(p => basket[p.product_id]);

  document.getElementById('cartItems').innerHTML = items.length ? items.map(p => `<div class="cart-row"><div><strong>${esc(p.name)}</strong><br><small>${money(p.price)} · ${esc(p.size)}</small></div><div class="qty"><button onclick="change(${p.product_id},-1)" aria-label="Menge reduzieren">−</button>${basket[p.product_id]}<button onclick="change(${p.product_id},1)" aria-label="Menge erhöhen">+</button></div></div>`).join('') : 'Dein Warenkorb ist noch leer.';

  document.getElementById('total').textContent = 'Gesamt: ' + money(items.reduce((s, p) => s + p.price * basket[p.product_id], 0))
}

function openCart() {
  renderCart();

  document.getElementById('cart').showModal()
}

// LOGIN UND REGISTRIERUNG: Formular im Dialog, Prüfung in register.php / login.php.
function openAuth(mode) {
  authMode = mode;
  document.getElementById('authForm').reset();

  document.getElementById('authMessage').textContent = '';

  const reg = mode === 'register';

  document.getElementById('authTitle').textContent = reg ? 'Schön, dass du da bist.' : 'Willkommen zurück.';
  document.getElementById('authNote').textContent = reg ? 'Erstelle ein Konto mit Name, E-Mail und Passwort. Dein Warenkorb wird danach in deinem Konto gespeichert.' : 'Melde dich mit deiner E-Mail und deinem Passwort an.';
  document.getElementById('nameLabel').style.display = reg ? 'block' : 'none';
  document.getElementById('name').required = reg;
  document.getElementById('password').autocomplete = reg ? 'new-password' : 'current-password';
  document.getElementById('authSubmit').textContent = reg ? 'Registrieren' : 'Einloggen';
  document.getElementById('auth').showModal()
}
document.getElementById('authForm').addEventListener('submit', async e => {
  e.preventDefault();
  const reg = authMode === 'register';
  const button = document.getElementById('authSubmit');
  const data = {
    email: document.getElementById('email').value,
    password: document.getElementById('password').value
  };
  if (reg) data.name = document.getElementById('name').value;
  button.disabled = true;
  try {
    const res = await api(reg ? 'register.php' : 'login.php', data);
    await loggedIn(res.user, true);
    document.getElementById('auth').close();
    toast('♡ Hallo ' + res.user.name + '!')
  } catch (err) {
    document.getElementById('authMessage').textContent = err.message
  } finally {
    button.disabled = false
  }
});

// Nach dem Login: Gast-Warenkorb in die Datenbank übernehmen und Header anpassen.
async function loggedIn(user, mergeGuestCart) {
  currentUser = user;
  showUser();
  const guest = mergeGuestCart ? basket : {};
  basket = (Object.keys(guest).length ? await api('cart.php', { action: 'merge', items: guest }) : await api('cart.php')).items;
  try {
    localStorage.removeItem('tierfutter-cart')
  } catch {}
  showUser();
  save()
}

async function logout() {
  try {
    await api('logout.php', {})
  } catch (err) {
    return toast(err.message)
  }
  currentUser = null;
  basket = {};
  showUser();
  save();
  toast('Bis bald!')
}

// HEADER: Name des eingeloggten Users oder Login-Buttons anzeigen.
function showUser() {
  const info = document.getElementById('userInfo');
  info.textContent = currentUser ? 'Hallo, ' + currentUser.name : '';
  info.hidden = !currentUser;
  document.getElementById('logoutBtn').hidden = !currentUser;
  document.getElementById('registerBtn').hidden = !!currentUser;
  document.getElementById('loginBtn').hidden = !!currentUser;
  document.getElementById('cartNote').textContent = currentUser ? 'Dein Warenkorb ist in deinem Konto gespeichert. Eine Bestellung ist noch nicht möglich.' : 'Als Gast wird der Warenkorb auf diesem Gerät gespeichert. Nach dem Login wird er in dein Konto übernommen. Eine Bestellung ist noch nicht möglich.'
}
document.querySelectorAll('dialog').forEach(d => d.addEventListener('click', e => {
  if (e.target === d) {
    const r = d.getBoundingClientRect();
    if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) d.close()
  }
}));
// START: Produkte laden, dann prüfen, ob noch eine Session besteht.
async function loadProducts() {
  try {
    products = (await api('products.php')).products;
    // Gast-Warenkorb: Produkte entfernen, die es nicht mehr gibt.
    Object.keys(basket).forEach(id => products.some(p => p.product_id === +id) || delete basket[id]);
    render()
  } catch (err) {
    document.getElementById('resultCount').textContent = '';
    document.getElementById('products').innerHTML = '<div class="empty">Die Produkte konnten nicht geladen werden.<br>' + esc(err.message) + '</div>'
  }
  save()
}
save();
loadProducts().then(() => api('session.php')).then(res => res.user && loggedIn(res.user, false)).catch(() => {
  // z. B. User in der Datenbank gelöscht: wieder als Gast anzeigen.
  currentUser = null;
  showUser()
});
