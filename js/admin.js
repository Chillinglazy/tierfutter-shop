// ADMIN: Datenbankverwaltung. Spricht nur mit api/admin/admin_db.php.
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
    res = await fetch('api/admin/admin_db.php' + (params ? '?' + new URLSearchParams(params) : ''), data ? {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(data)
    } : {});
    json = await res.json()
  } catch {
    throw new Error('Server nicht erreichbar. Bitte Seite neu laden.')
  }
  if (res.status === 401) return denied(json.error);
  if (!res.ok) throw new Error(json.error || 'Unbekannter Fehler.');
  return json
}

// ADMIN-LOGIN: Ohne gültigen Admin-Login (401) erscheint das Login-Formular.
function denied(text) {
  showLogin();
  throw new Error(text)
}

function showLogin() {
  $('adminMain').hidden = true;
  $('logoutAdmin').hidden = true;
  $('denied').hidden = false;
  $('adminUser').textContent = '';
  $('loginEmail').focus()
}

// Anmelden über api/admin/admin_login.php: prüft Passwort UND Rolle admin.
$('adminLogin').addEventListener('submit', async e => {
  e.preventDefault();
  $('loginMessage').textContent = '';
  try {
    const res = await fetch('api/admin/admin_login.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: $('loginEmail').value, password: $('loginPassword').value })
    });
    const json = await res.json();
    if (!res.ok) throw new Error(json.error || 'Login fehlgeschlagen.');
    e.target.reset();
    $('denied').hidden = true;
    start()
  } catch (err) {
    $('loginMessage').textContent = err.message
  }
});

// Admin abmelden: beendet nur den Zugang zur Datenbankverwaltung.
$('logoutAdmin').addEventListener('click', async () => {
  try {
    await fetch('api/admin/admin_login.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'logout' }) })
  } catch {}
  showLogin()
});

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
  $('sqlView').hidden = true;
  $('sqlOpen').classList.remove('active');
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

// SQL-EDITOR: Stored Procedures mit CALL aufrufen. Spricht nur mit api/admin/sql_editor.php,
// das genau einen Befehl CALL sp_...(...) annimmt und alle Ergebnisse als Tabellen zurückgibt.
let procs = []; // [{name, params: [{mode, name, type}]}]

async function sqlApi(data) {
  let res, json;
  try {
    res = await fetch('api/admin/sql_editor.php', data ? {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(data)
    } : {});
    json = await res.json()
  } catch {
    throw new Error('Server nicht erreichbar. Bitte Seite neu laden.')
  }
  if (res.status === 401) return denied(json.error);
  if (!res.ok) throw Object.assign(new Error(json.error || 'Unbekannter Fehler.'), { sql: json.sql });
  return json
}

async function openSqlEditor() {
  current = null;
  $('welcome').hidden = true;
  $('tableView').hidden = true;
  $('sqlView').hidden = false;
  $('sqlOpen').classList.add('active');
  document.querySelectorAll('#tableList button').forEach(b => b.classList.remove('active'));
  $('sqlInput').focus();
  try {
    const res = await sqlApi();
    csrf = res.csrf;
    procs = res.procedures;
    $('sqlProcCount').textContent = procs.length;
    $('sqlProcList').innerHTML = procs.map((p, i) => `<li><button type="button" data-proc="${i}" title="In den Editor einfügen"><b>${esc(p.name)}</b><small>(${p.params.map(a => esc((a.mode === 'IN' ? '' : a.mode + ' ') + a.name + ' ' + a.type)).join(', ')})</small></button></li>`).join('') || '<li class="sql-hint">Keine Prozeduren gefunden.</li>';
    // Hinweis: InfinityFree erlaubt keine Prozeduren, bei alwaysdata muss 2_procedures-online.sql importiert sein.
    const mode = $('sqlMode');
    mode.hidden = !!procs.length && res.procedures_mode;
    mode.className = 'sql-mode' + (procs.length ? '' : ' warn');
    mode.textContent = !procs.length
      ? '⚠ In dieser Datenbank gibt es keine Stored Procedures. Auf InfinityFree sind sie nicht erlaubt; bei alwaysdata zuerst 2_procedures-online.sql in phpMyAdmin importieren.'
      : 'ℹ Die Prozeduren sind vorhanden, die Website selbst nutzt sie aber nicht (DB_PROCEDURES ist in config.local.php nicht auf true). Aufrufen kannst du sie hier trotzdem.'
  } catch (err) {
    showSqlError(err.message)
  }
}

// Klick auf eine Prozedur: Vorlage mit Beispielwerten einfügen (OUT-Parameter als @variable).
$('sqlProcList').addEventListener('click', e => {
  const button = e.target.closest('button[data-proc]');
  if (!button) return;
  const p = procs[button.dataset.proc];
  const args = p.params.map(a => {
    if (a.mode !== 'IN') return '@' + a.name.replace(/^p_/, '');
    if (/int|decimal|float|double|numeric/.test(a.type)) return '1';
    if (/^(date|datetime|timestamp)/.test(a.type)) return "'2026-01-01'";
    return `'${a.name.replace(/^p_/, '')}'`
  });
  const input = $('sqlInput');
  input.value = `CALL ${p.name}(${args.join(', ')});`;
  input.focus();
  // ersten Wert markieren, damit man ihn gleich überschreiben kann
  const open = input.value.indexOf('(') + 1;
  const first = args[0] || '';
  input.setSelectionRange(open, open + first.length)
});

$('sqlRun').addEventListener('click', runSql);
$('sqlClear').addEventListener('click', () => {
  $('sqlInput').value = '';
  $('sqlInput').focus()
});
$('sqlInput').addEventListener('keydown', e => {
  if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
    e.preventDefault();
    runSql()
  }
});

async function runSql() {
  const sql = $('sqlInput').value;
  if (!sql.trim()) return $('sqlInput').focus();
  $('sqlRun').disabled = true;
  $('sqlOutput').innerHTML = '<p class="sql-hint">Wird ausgeführt …</p>';
  try {
    const res = await sqlApi({ sql });
    showSqlResults(res)
  } catch (err) {
    showSqlError(err.message, err.sql)
  } finally {
    $('sqlRun').disabled = false
  }
}

// AUSGABE: jedes Ergebnis (Result Set) als eigene Tabelle, OUT-Variablen zuletzt.
function showSqlResults(res) {
  const time = new Date().toLocaleTimeString('de-CH');
  const sets = res.results.filter(r => !r.variables);
  let html = `<p class="sql-ok">✔ Ausgeführt um ${time} in ${res.ms} ms · ${sets.length ? sets.length + ' Ergebnis' + (sets.length > 1 ? 'se' : '') : 'kein Ergebnis (Befehl ohne Ausgabe)'}</p>`;
  html += `<p class="sql-sent">Gesendet: <code>${esc(res.sql)}</code>${res.params.length ? ' mit Werten <code>' + esc(res.params.map(v => v === null ? 'NULL' : JSON.stringify(v)).join(', ')) + '</code>' : ''}</p>`;
  html += res.results.map((r, i) => {
    const title = r.variables ? 'OUT-Variablen' : `Ergebnis ${i + 1}`;
    const info = r.variables ? '' : ` <small>${r.total} Zeile${r.total === 1 ? '' : 'n'}${r.total > r.rows.length ? ` · die ersten ${r.rows.length} angezeigt` : ''}</small>`;
    const head = '<tr>' + r.columns.map(c => `<th>${esc(c)}</th>`).join('') + '</tr>';
    const body = r.rows.map(row => '<tr>' + row.map(v => `<td title="${v === null ? '' : esc(v)}">${v === null ? '<i class="null">NULL</i>' : esc(short(v))}</td>`).join('') + '</tr>').join('')
      || `<tr><td colspan="${r.columns.length}">Keine Zeilen.</td></tr>`;
    return `<h3>${title}${info}</h3><div class="admin-scroll"><table class="admin-table">${head}${body}</table></div>`
  }).join('');
  $('sqlOutput').innerHTML = html
}

function showSqlError(text, sql) {
  $('sqlOutput').innerHTML = `<p class="sql-error">✖ ${esc(text)}</p>` + (sql ? `<p class="sql-sent">Gesendet: <code>${esc(sql)}</code></p>` : '')
}

// START: Zugriff prüfen und Tabellen laden.
function start() {
  fetch('api/konto/session.php').then(r => r.json()).then(res => {
    if (res.user?.admin) $('adminUser').textContent = 'Admin: ' + res.user.name
  }).catch(() => {});
  loadTables().then(() => {
    $('adminMain').hidden = false;
    $('logoutAdmin').hidden = false
  }).catch(err => {
    if ($('denied').hidden) toast(err.message)
  })
}
start()
