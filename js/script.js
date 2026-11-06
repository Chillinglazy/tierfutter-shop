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

// PRODUKTKARTE: gleiche Darstellung im Sortiment und im Ernährungsplan.
function productCard(p) {
  return `<article class="card" data-product-id="${p.product_id}">
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
  </article>`
}

// PRODUKTLISTE: Auswahl anhand der Filter anzeigen.
function render() {
  const vals = Object.fromEntries(ids.map(id => [id, document.getElementById(id).value]));

  const list = products.filter(p => (!vals.animal || p.animal === vals.animal) && (!vals.type || p.food_type === vals.type) && (!vals.special || p.special === vals.special) && (!vals.taste || p.taste === vals.taste) && (!vals.age || (+vals.age >= p.age_min && +vals.age <= p.age_max)) && (!vals.weight || (+vals.weight >= p.weight_min && +vals.weight <= p.weight_max)));

  document.getElementById('resultCount').textContent = list.length + ' Produkte entdecken';

  document.getElementById('products').innerHTML = list.map(productCard).join('') || '<div class="empty">Für diese Auswahl gibt es noch kein Beispielprodukt.<br>Probiere andere Filter oder setze die Auswahl zurück.</div>';

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

  document.getElementById('total').textContent = 'Gesamt: ' + money(cartTotal());
  document.getElementById('orderBtn').hidden = !items.length
}

function cartTotal() {
  return products.filter(p => basket[p.product_id]).reduce((s, p) => s + p.price * basket[p.product_id], 0)
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
  document.getElementById('ordersBtn').hidden = !currentUser;
  document.getElementById('plansBtn').hidden = !currentUser;
  updatePlanSave();
  document.getElementById('registerBtn').hidden = !!currentUser;
  document.getElementById('loginBtn').hidden = !!currentUser;
  document.getElementById('cartNote').textContent = currentUser ? 'Dein Warenkorb ist in deinem Konto gespeichert.' : 'Als Gast wird der Warenkorb auf diesem Gerät gespeichert. Du kannst auch ohne Konto bestellen.'
}

// BESTELLEN: Formular öffnen, bei eingeloggten Usern Name und E-Mail vorausfüllen.
function openCheckout() {
  const form = document.getElementById('checkoutForm');
  form.reset();
  form.hidden = false;
  document.getElementById('checkoutDone').hidden = true;
  document.getElementById('checkoutMessage').textContent = '';
  document.getElementById('cardNote').hidden = true;
  if (currentUser) {
    const [first, ...rest] = currentUser.name.split(' ');
    document.getElementById('coFirst').value = first;
    document.getElementById('coLast').value = rest.join(' ');
    document.getElementById('coEmail').value = currentUser.email || ''
  }
  document.getElementById('checkoutTotal').textContent = 'Gesamt: ' + money(cartTotal());
  document.getElementById('cart').close();
  document.getElementById('checkout').showModal()
}

// Hinweis bei Kreditkarte: keine Kartendaten in diesem Shop
document.querySelectorAll('input[name="payment"]').forEach(r => r.addEventListener('change', () => {
  document.getElementById('cardNote').hidden = document.querySelector('input[name="payment"]:checked').value !== 'Kreditkarte'
}));

document.getElementById('checkoutForm').addEventListener('submit', async e => {
  e.preventDefault();
  const v = id => document.getElementById(id).value;
  const button = document.getElementById('checkoutSubmit');
  const data = {
    first_name: v('coFirst'),
    last_name: v('coLast'),
    email: v('coEmail'),
    phone: v('coPhone'),
    street: v('coStreet'),
    zip: v('coZip'),
    city: v('coCity'),
    payment_method: document.querySelector('input[name="payment"]:checked').value,
    terms: document.getElementById('coTerms').checked,
    privacy: document.getElementById('coPrivacy').checked,
    newsletter: document.getElementById('coNewsletter').checked,
    items: basket // nur für Gäste, Preise rechnet der Server
  };
  button.disabled = true;
  try {
    const res = await api('order.php', data);
    basket = {};
    save();
    e.target.hidden = true;
    document.getElementById('checkoutDoneText').textContent = '♡ Danke, ' + data.first_name + '! Deine Bestellung Nr. ' + res.order_id + ' über ' + money(res.total) + ' ist eingegangen.';
    document.getElementById('checkoutDone').hidden = false
  } catch (err) {
    document.getElementById('checkoutMessage').textContent = err.message
  } finally {
    button.disabled = false
  }
});

// MEINE BESTELLUNGEN: Liste aus orders.php
async function openOrders() {
  const list = document.getElementById('ordersList');
  list.textContent = 'Wird geladen …';
  document.getElementById('orders').showModal();
  try {
    const { orders } = await api('orders.php');
    list.innerHTML = orders.length ? orders.map(o => `<div class="order">
      <div class="order-head"><strong>Nr. ${o.order_id}</strong><span>${new Date(o.ordered_at.replace(' ', 'T')).toLocaleString('de-CH', { dateStyle: 'medium', timeStyle: 'short' })}</span></div>
      ${o.items.map(i => `<div class="order-line"><span>${i.quantity} × ${esc(i.product_name)}</span><span>${money(i.unit_price * i.quantity)}</span></div>`).join('')}
      <div class="order-line"><strong>Gesamt</strong><strong>${money(o.total)}</strong></div>
      <small>${esc(o.payment_method)}</small>
      </div>`).join('') : 'Du hast noch keine Bestellungen.'
  } catch (err) {
    list.textContent = err.message
  }
}
// ERNÄHRUNGSPLAN: Kategorien als Kacheln laden, Plan von diet_plan.php holen und anzeigen.
const stepIcons = { Futter: '🥣', Tagesablauf: '🕒', Leckerlis: '🍪', Vermeiden: '🚫', Kontrolle: '📋', Wichtig: '⚠️' };
let shownPlan = null; // {condition_id, animal, pet_name} des angezeigten Plans

async function loadConditions() {
  const box = document.getElementById('planConditions');
  try {
    const { conditions } = await api('diet_plan.php');
    box.innerHTML = conditions.map((c, i) => `<label class="choice">
      <input name="planCondition" type="radio" value="${c.condition_id}"${i ? '' : ' required'}>
      <span><b class="choice-icon">${esc(c.icon)}</b><strong>${esc(c.name)}</strong><small>${esc(c.goal)}</small></span>
      </label>`).join('')
  } catch (err) {
    box.innerHTML = '<p class="demo">' + esc(err.message) + '</p>'
  }
}

document.getElementById('planForm').addEventListener('submit', async e => {
  e.preventDefault();
  const checked = name => document.querySelector(`input[name="${name}"]:checked`)?.value || '';
  const button = document.getElementById('planSubmit');
  button.disabled = true;
  await showPlan(checked('planCondition'), checked('planAnimal'), document.getElementById('planPet').value.trim());
  button.disabled = false
});

async function showPlan(conditionId, animal, pet) {
  const result = document.getElementById('planResult');
  const message = document.getElementById('planMessage');
  message.textContent = '';
  try {
    const { plan } = await api('diet_plan.php?condition_id=' + encodeURIComponent(conditionId) + '&animal=' + encodeURIComponent(animal));
    shownPlan = { condition_id: plan.condition_id, animal: plan.animal, pet_name: pet };
    const list = plan.product_ids.map(id => products.find(p => p.product_id === id)).filter(Boolean);
    // Zeilen mit gleicher Überschrift (z. B. zweimal "Wichtig") in einer Karte zeigen
    const groups = [];
    plan.steps.forEach(s => {
      const g = groups.find(g => g.label === s.label);
      g ? g.texts.push(s.text) : groups.push({ label: s.label, texts: [s.text] })
    });
    result.innerHTML = `<div class="plan-head">
      <span class="plan-icon">${esc(plan.icon)}</span>
      <div class="plan-head-text">
      <div class="eyebrow">Ernährungsplan · ${esc(plan.animal)}</div>
      <h3>${pet ? 'Für ' + esc(pet) + ': ' : ''}${esc(plan.name)}</h3>
      <p><strong>Ziel:</strong> ${esc(plan.goal)}</p>
      </div>
      <div class="plan-actions">
      <button class="btn solid" type="button" id="planSave" onclick="savePlan()"></button>
      <button class="btn" type="button" onclick="window.print()">Drucken</button>
      </div>
      </div>
      <div class="plan-steps">${groups.map(g => `<div class="plan-step${g.label === 'Wichtig' ? ' important' : ''}">
        <span class="step-icon">${stepIcons[g.label] || '•'}</span>
        <div><strong>${esc(g.label)}</strong>${g.texts.map(t => `<p>${esc(t)}</p>`).join('')}</div>
        </div>`).join('')}</div>
      <h3 class="plan-products-title">Passende Produkte für ${esc(plan.animal === 'Hund' ? 'deinen Hund' : 'deine Katze')}</h3>
      <div class="grid">${list.map(productCard).join('') || '<div class="empty">Für diese Auswahl gibt es noch kein passendes Produkt.</div>'}</div>
      <p class="plan-warning"><strong>Tierarztpraxis kontaktieren:</strong> Die Produkte sind Beispiele aus unserem Sortiment. Welche Diät, welche Menge und wie lange, legst du gemeinsam mit deiner Tierarztpraxis fest.</p>`;
    result.hidden = false;
    updatePlanSave();
    result.scrollIntoView({ behavior: 'smooth', block: 'start' })
  } catch (err) {
    message.textContent = err.message
  }
}

// Speichern-Knopf: eingeloggt speichern, sonst zuerst einloggen.
function updatePlanSave() {
  const button = document.getElementById('planSave');
  if (button) button.textContent = currentUser ? '♡ Plan speichern' : 'Einloggen und Plan speichern';
}

async function savePlan() {
  if (!currentUser) return openAuth('login');
  try {
    await api('saved_plans.php', { action: 'save', ...shownPlan });
    toast('♡ Plan unter "Meine Pläne" gespeichert')
  } catch (err) {
    toast(err.message)
  }
}

// MEINE PLÄNE: gespeicherte Pläne des Kunden (saved_plans.php)
async function openPlans(data) {
  const list = document.getElementById('plansList');
  if (!data) {
    list.textContent = 'Wird geladen …';
    document.getElementById('plans').showModal()
  }
  try {
    const { plans } = await api('saved_plans.php', data);
    list.innerHTML = plans.length ? plans.map(p => `<div class="order saved-plan">
      <span class="plan-icon small">${esc(p.icon)}</span>
      <div><strong>${p.pet_name ? esc(p.pet_name) + ' · ' : ''}${esc(p.name)}</strong><br>
      <small>${esc(p.animal)} · gespeichert am ${new Date(p.created_at.replace(' ', 'T')).toLocaleDateString('de-CH')}</small></div>
      <div class="dialog-buttons">
      <button class="btn solid" onclick="openSavedPlan(${p.condition_id}, '${esc(p.animal)}', this.dataset.pet)" data-pet="${esc(p.pet_name)}">Anzeigen</button>
      <button class="btn" onclick="openPlans({ action: 'delete', plan_id: ${p.plan_id} })">Löschen</button>
      </div>
      </div>`).join('') : 'Du hast noch keine Pläne gespeichert. Erstelle einen Plan und klicke auf "Plan speichern".'
  } catch (err) {
    list.textContent = err.message
  }
}

function openSavedPlan(conditionId, animal, pet) {
  document.getElementById('plans').close();
  document.querySelectorAll('input[name="planAnimal"]').forEach(r => r.checked = r.value === animal);
  document.querySelectorAll('input[name="planCondition"]').forEach(r => r.checked = +r.value === conditionId);
  document.getElementById('planPet').value = pet || '';
  showPlan(conditionId, animal, pet || '')
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
loadConditions();
loadProducts().then(() => api('session.php')).then(res => res.user && loggedIn(res.user, false)).catch(() => {
  // z. B. User in der Datenbank gelöscht: wieder als Gast anzeigen.
  currentUser = null;
  showUser()
});
