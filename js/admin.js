// ADMIN: Datenbankverwaltung. Spricht nur mit api/admin_db.php.
// Der Server prüft bei jeder Anfrage, ob der eingeloggte Benutzer Admin ist.
let csrf = '';
let types = {}; // erlaubte Datentypen für neue Spalten {int: 'Ganzzahl', ...}
let current = null; // aktuell angezeigte Tabelle (Antwort von action=table)
let editKey = null; // Primärschlüssel der Zeile, die bearbeitet wird (null = neue Zeile)

// Text aus der Datenbank sicher ins HTML einsetzen.
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const $ = id => document.getElementById(id);

// SERVER: GET mit Parametern oder POST (JSON + CSRF-Token).
async function adminApi(params, data) {
  let res, json;
  try {
    res = await fetch('api/admin_db.php' + (params ? '?' + new URLSearchParams(params) : ''), data ? {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(data)
    } : {});
    json = await res.json()
  } catch {
    throw new Error('Server nicht erreichbar. Bitte Seite neu laden.')
  }
  if (res.status === 401 || res.status === 403 && !data) return denied(json.error);
  if (!res.ok) throw new Error(json.error || 'Unbekannter Fehler.');
  return json
}

function denied(text) {
  $('adminMain').hidden = true;
  $('denied').hidden = false;
  $('deniedText').textContent = text === 'Bitte zuerst einloggen.' ? 'Bitte zuerst im Shop mit einem Admin-Konto einloggen.' : 'Dieser Bereich ist nur für Administratoren.';
  throw new Error(text)
}

function toast(text) {
  const t = $('toast');
  t.textContent = text;
  t.style.display = 'block';
  clearTimeout(window.toastTimer);
  window.toastTimer = setTimeout(() => t.style.display = 'none', 2600)
}

// TABELLENLISTE links
async function loadTables() {
  const res = await adminApi({ action: 'tables' });
  csrf = res.csrf;
  types = res.types;
  $('tableList').innerHTML = res.tables.map(t => `<li><button type="button" data-table="${esc(t.name)}" class="${current && current.name === t.name ? 'active' : ''}"><span>${t.core ? '🔒 ' : ''}${esc(t.name)}</span> <small>${t.rows}</small></button></li>`).join('');
  const options = Object.entries(types).map(([k, v]) => `<option value="${esc(k)}">${esc(v)}</option>`).join('');
  document.querySelectorAll('.type-select').forEach(s => {
    if (!s.options.length) s.innerHTML = '<option value="">Bitte wählen</option>' + options
  })
}

$('tableList').addEventListener('click', e => {
  const button = e.target.closest('button[data-table]');
  if (button) openTable(button.dataset.table, 1).catch(err => toast(err.message))
});

// TABELLE anzeigen: Spalten, Daten, Seiten
async function openTable(name, page) {
  current = await adminApi({ action: 'table', name, page });
  $('welcome').hidden = true;
  $('tableView').hidden = false;
  $('tableName').textContent = (current.core ? '🔒 ' : '') + current.name;
  $('dropTableBtn').hidden = current.core;
  document.querySelectorAll('#tableList button').forEach(b => b.classList.toggle('active', b.dataset.table === current.name));

  // SPALTEN
  $('columnTable').innerHTML = '<tr><th>Spalte</th><th>Datentyp</th><th>Leer erlaubt</th><th>Standardwert</th><th>Schlüssel</th><th></th></tr>' + current.columns.map(c => {
    const key = [c.primary ? 'Primärschlüssel' : '', current.references[c.name] ? '→ ' + current.references[c.name] : '', c.auto_increment ? 'zählt automatisch' : ''].filter(Boolean).join(', ');
    const locked = c.primary || current.core_columns.includes(c.name);
    return `<tr><td><b>${esc(c.name)}</b></td><td>${esc(c.type)}</td><td>${c.nullable ? 'ja' : 'nein'}</td><td>${c.has_default ? esc(c.default) : ''}</td><td>${esc(key)}</td><td>${locked ? '' : `<button class="link danger" type="button" data-col="${esc(c.name)}" onclick="dropColumn(this.dataset.col)">löschen</button>`}</td></tr>`
  }).join('');

  // DATEN
  const pk = current.primary_key;
  $('rowInfo').textContent = `${current.total} Zeilen` + (current.pages > 1 ? ` · Seite ${current.page} von ${current.pages}` : '');
  $('rowTable').innerHTML = '<tr>' + current.columns.map(c => `<th>${esc(c.name)}</th>`).join('') + '<th></th></tr>' + (current.rows.map((r, i) => '<tr>' + current.columns.map(c => `<td>${r[c.name] === null ? '<i class="null">NULL</i>' : esc(short(r[c.name]))}</td>`).join('') + `<td class="row-actions">${pk.length ? `<button class="link" type="button" onclick="openRow(${i})">bearbeiten</button> <button class="link danger" type="button" onclick="deleteRow(${i})">löschen</button>` : ''}</td></tr>`).join('') || `<tr><td colspan="${current.columns.length + 1}">Noch keine Daten.</td></tr>`);

  // SEITEN
  $('pager').innerHTML = current.pages > 1 ? Array.from({ length: current.pages }, (_, i) => `<button type="button" class="${i + 1 === current.page ? 'active' : ''}" onclick="openTable(current.name, ${i + 1})">${i + 1}</button>`).join('') : ''
}

// Lange Texte in der Übersicht kürzen (beim Bearbeiten steht der ganze Text im Feld).
const short = v => String(v).length > 80 ? String(v).slice(0, 80) + '…' : v;

// Werte der Primärschlüssel-Spalten einer Zeile
const rowKey = row => Object.fromEntries(current.primary_key.map(k => [k, row[k]]));

// ZEILE hinzufügen (index null) oder bearbeiten
function openRow(index) {
  const row = index === null ? null : current.rows[index];
  editKey = row ? rowKey(row) : null;
  $('rowTitle').textContent = (row ? 'Zeile bearbeiten · ' : 'Neue Zeile · ') + current.name;
  $('rowMessage').textContent = '';
  $('rowFields').innerHTML = current.columns.map(c => {
    const value = row ? row[c.name] : '';
    const hint = [c.type, c.nullable ? 'darf leer sein' : 'Pflichtfeld', c.auto_increment ? 'automatisch' : '', current.references[c.name] ? '→ ' + current.references[c.name] : ''].filter(Boolean).join(' · ');
    const enumValues = (c.type.match(/^enum\((.*)\)$/) || [])[1];
    let input;
    if (enumValues) {
      const options = enumValues.split(',').map(v => v.trim().slice(1, -1).replace(/''/g, "'"));
      input = `<select name="${esc(c.name)}">${c.nullable || !row ? '<option value=""></option>' : ''}${options.map(o => `<option${o === value ? ' selected' : ''}>${esc(o)}</option>`).join('')}</select>`
    } else if (/text$/.test(c.type)) {
      input = `<textarea name="${esc(c.name)}" rows="3">${esc(value)}</textarea>`
    } else {
      input = `<input name="${esc(c.name)}" value="${esc(value)}"${c.auto_increment && !row ? ' placeholder="automatisch"' : ''}>`
    }
    return `<label>${esc(c.name)} <small>${esc(hint)}</small>${input}</label>`
  }).join('');
  $('rowDialog').showModal()
}

$('rowForm').addEventListener('submit', async e => {
  e.preventDefault();
  const values = {};
  current.columns.forEach(c => {
    const field = e.target.elements[c.name];
    const value = field.value;
    // Beim Bearbeiten nur geänderte Felder senden
    if (editKey) {
      const old = current.rows.find(r => current.primary_key.every(k => String(r[k]) === String(editKey[k])))[c.name];
      if (value === String(old ?? '')) return
    }
    values[c.name] = value
  });
  try {
    if (editKey && !Object.keys(values).length) return $('rowDialog').close();
    const res = await adminApi(null, editKey ? { action: 'update', table: current.name, key: editKey, values } : { action: 'insert', table: current.name, values });
    $('rowDialog').close();
    toast(res.message);
    await refresh()
  } catch (err) {
    $('rowMessage').textContent = err.message
  }
});

async function deleteRow(index) {
  const key = rowKey(current.rows[index]);
  const text = Object.entries(key).map(([k, v]) => `${k} = ${v}`).join(', ');
  if (!confirm(`Zeile mit ${text} aus ${current.name} löschen?`)) return;
  try {
    toast((await adminApi(null, { action: 'delete', table: current.name, key })).message);
    await refresh()
  } catch (err) {
    toast(err.message)
  }
}

// SPALTEN ergänzen / löschen
$('addColumnForm').addEventListener('submit', async e => {
  e.preventDefault();
  try {
    const res = await adminApi(null, { action: 'add_column', table: current.name, column: { name: $('newColName').value, type: $('newColType').value, nullable: $('newColNull').checked } });
    e.target.reset();
    toast(res.message);
    await refresh()
  } catch (err) {
    toast(err.message)
  }
});

async function dropColumn(name) {
  if (!confirm(`Spalte ${name} mit allen Werten aus ${current.name} löschen?`)) return;
  try {
    toast((await adminApi(null, { action: 'drop_column', table: current.name, column: name })).message);
    await refresh()
  } catch (err) {
    toast(err.message)
  }
}

// NEUE TABELLE
function addCreateColumn() {
  const options = Object.entries(types).map(([k, v]) => `<option value="${esc(k)}">${esc(v)}</option>`).join('');
  $('createColumns').insertAdjacentHTML('beforeend', `<div class="create-col">
  <label>Spaltenname<input class="col-name" maxlength="64" placeholder="z. B. name"></label>
  <label>Datentyp<select class="col-type"><option value="">Bitte wählen</option>${options}</select></label>
  <label class="check"><input class="col-null" type="checkbox"> darf leer sein</label>
  <button class="link danger" type="button" onclick="this.parentElement.remove()" aria-label="Spalte entfernen">×</button>
  </div>`)
}

function openCreateTable() {
  $('createForm').reset();
  $('createColumns').innerHTML = '';
  $('createMessage').textContent = '';
  addCreateColumn();
  $('createDialog').showModal()
}

$('createForm').addEventListener('submit', async e => {
  e.preventDefault();
  // Zeilen ohne Spaltennamen werden ignoriert
  const columns = [...document.querySelectorAll('.create-col')].map(div => ({
    name: div.querySelector('.col-name').value.trim(),
    type: div.querySelector('.col-type').value,
    nullable: div.querySelector('.col-null').checked
  })).filter(c => c.name);
  try {
    const res = await adminApi(null, { action: 'create_table', name: $('createName').value, columns });
    $('createDialog').close();
    toast(res.message);
    await loadTables();
    await openTable(res.name, 1)
  } catch (err) {
    $('createMessage').textContent = err.message
  }
});

// TABELLE LÖSCHEN (nur eigene Tabellen, Name muss eingetippt werden)
function openDropTable() {
  $('dropForm').reset();
  $('dropName').textContent = current.name;
  $('dropMessage').textContent = '';
  $('dropDialog').showModal()
}

$('dropForm').addEventListener('submit', async e => {
  e.preventDefault();
  try {
    const res = await adminApi(null, { action: 'drop_table', table: current.name, confirm: $('dropConfirm').value });
    $('dropDialog').close();
    toast(res.message);
    current = null;
    $('tableView').hidden = true;
    $('welcome').hidden = false;
    await loadTables()
  } catch (err) {
    $('dropMessage').textContent = err.message
  }
});

// Nach jeder Änderung: aktuelle Tabelle und Zeilenzahlen neu laden.
async function refresh() {
  await openTable(current.name, current.page);
  await loadTables()
}

// START: Zugriff prüfen und Tabellen laden.
fetch('api/session.php').then(r => r.json()).then(res => {
  if (res.user) $('adminUser').textContent = 'Admin: ' + res.user.name
}).catch(() => {});
loadTables().then(() => $('adminMain').hidden = false).catch(err => {
  if ($('denied').hidden) toast(err.message)
})
