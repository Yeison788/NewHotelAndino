/* turismo.js — Hotel Andino
 * - Divide intereses por comas/enter/pegar
 * - Prioriza Nearby por type, luego keyword; fallback a Text Search
 * - Filtra por radio incluso si Text Search trae fuera
 * - Soporta "Buscar según mis intereses" y "Buscar con estas (sin guardar)"
 */

let map, places, info, hotelMarker;
let markers = [];
let paginator = null;
let radiusCircle = null;

/* ========== Utilidades UI / mapa ========== */

function smoothPanTo(map, target, zoom = 16, steps = 30, interval = 15) {
  const start = map.getCenter();
  const latStep = (target.lat() - start.lat()) / steps;
  const lngStep = (target.lng() - start.lng()) / steps;
  let i = 0;
  const pan = setInterval(() => {
    i++;
    map.setCenter({ lat: start.lat() + latStep * i, lng: start.lng() + lngStep * i });
    if (i >= steps) {
      clearInterval(pan);
      map.setZoom(zoom);
    }
  }, interval);
}

function getHotelCenter() {
  const el = document.getElementById("map");
  const lat = parseFloat(el?.dataset?.lat);
  const lng = parseFloat(el?.dataset?.lng);
  if (!isNaN(lat) && !isNaN(lng)) return { lat, lng };
  return { lat: 4.711, lng: -74.072 }; // Bogotá fallback
}

function addHotelMarker(center) {
  hotelMarker = new google.maps.Marker({
    position: center,
    map,
    title: "Hotel Andino",
    icon: { url: "https://maps.google.com/mapfiles/ms/icons/yellow-dot.png", scaledSize: new google.maps.Size(40, 40) }
  });
}

function clearMarkers() {
  markers.forEach(m => m.setMap(null));
  markers = [];
}

function addMarkers(results) {
  results.forEach(place => {
    if (!place.geometry || !place.geometry.location) return;
    const m = new google.maps.Marker({ map, position: place.geometry.location, title: place.name });
    m.addListener("click", () => showPlaceDetails(place.place_id));
    markers.push(m);
  });
}

function updateResultsList(results) {
  const list  = document.getElementById("results-list");
  const count = document.getElementById("results-count");

  // siempre resetea la lista para la nueva búsqueda
  list.innerHTML = "";

  results.forEach(p => {
    const item = document.createElement("div");
    item.className = "result-item";
    item.innerHTML = `
      <div class="result-name">${p.name || ""}</div>
      <div class="result-meta">${p.vicinity || p.formatted_address || ""}</div>
    `;
    item.addEventListener("click", () => {
      if (p.geometry?.location) smoothPanTo(map, p.geometry.location, 16);
      showPlaceDetails(p.place_id);
    });
    list.appendChild(item);
  });

  count.textContent = list.childElementCount;
}

/* ========== Places API calls ========== */

function doNearbySearchKeyword(radius, center, keyword) {
  return new Promise((resolve) => {
    const req = { location: center, radius, keyword };
    places.nearbySearch(req, (results, status, pagination) => {
      if (status !== google.maps.places.PlacesServiceStatus.OK || !results) {
        paginator = null; resolve([]); return;
      }
      paginator = (pagination && pagination.hasNextPage) ? () => pagination.nextPage() : null;
      resolve(results);
    });
  });
}

function doNearbySearchType(radius, center, type) {
  return new Promise((resolve) => {
    const req = { location: center, radius, type };
    places.nearbySearch(req, (results, status, pagination) => {
      if (status !== google.maps.places.PlacesServiceStatus.OK || !results) {
        paginator = null; resolve([]); return;
      }
      paginator = (pagination && pagination.hasNextPage) ? () => pagination.nextPage() : null;
      resolve(results);
    });
  });
}

function doTextSearch(radius, center, query) {
  return new Promise((resolve) => {
    const req = { query, location: center, radius };
    places.textSearch(req, (results, status) => {
      if (status !== google.maps.places.PlacesServiceStatus.OK || !results) {
        resolve([]); return;
      }
      resolve(results);
    });
  });
}

/* ========== Normalización de intereses ========== */

function stripAccents(s){
  return (s||'').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}

const keywordMap = {
  'cafe': ['cafe'], 'cafes': ['cafe'], 'cafeteria': ['cafe'],
  'restaurante': ['restaurant'], 'comida': ['restaurant','cafe'], 'gastronomia': ['restaurant','cafe'],
  'bar': ['bar'], 'pub': ['bar'], 'discoteca': ['night_club'], 'vida nocturna': ['bar','night_club'],
  'parque': ['park','tourist_attraction'], 'parques': ['park','tourist_attraction'],
  'museo': ['museum'], 'museos': ['museum'], 'arte': ['art_gallery','museum'],
  'foto': ['tourist_attraction','point_of_interest'], 'fotografia': ['tourist_attraction','point_of_interest'],
  'ninos': ['zoo','aquarium','amusement_park','park'], 'niños': ['zoo','aquarium','amusement_park','park'], 'familia': ['amusement_park','park','zoo'],
  'spa': ['spa'], 'gimnasio': ['gym'],
  'compras': ['shopping_mall','department_store'], 'shopping': ['shopping_mall','department_store'],
  'estadio': ['stadium'], 'deportes': ['stadium']
};

function splitInterests(arr){
  const out = [];
  (arr||[]).forEach(s => {
    String(s||'').split(/[,\n\|;\/]+/).forEach(p => {
      const t = p.trim(); if (t) out.push(t);
    });
  });
  return out;
}

function expandInterestsToQueries(interests){
  const queries = [];
  (interests || []).forEach(raw => {
    const t = String(raw||'').trim();
    if (!t) return;
    const key = stripAccents(t.toLowerCase());
    if (keywordMap[key]) {
      keywordMap[key].forEach(tp => queries.push({ kind: 'type', value: tp }));
    } else {
      queries.push({ kind: 'keyword', value: t });
    }
  });
  const seen = new Set();
  return queries.filter(q => {
    const k = q.kind+':'+q.value.toLowerCase();
    if (seen.has(k)) return false;
    seen.add(k); return true;
  });
}

/* ========== Geodistancia (filtro por radio duro) ========== */

function distanceMetersLL(a, b){
  const lat1 = (typeof a.lat === 'function') ? a.lat() : a.lat;
  const lon1 = (typeof a.lng === 'function') ? a.lng() : a.lng;
  const lat2 = (typeof b.lat === 'function') ? b.lat() : b.lat;
  const lon2 = (typeof b.lng === 'function') ? b.lng() : b.lng;
  const R=6371000, toRad = x => x*Math.PI/180;
  const dLat = toRad(lat2 - lat1), dLon = toRad(lon2 - lon1);
  const la1 = toRad(lat1), la2 = toRad(lat2);
  const x = Math.sin(dLat/2)**2 + Math.cos(la1)*Math.cos(la2)*Math.sin(dLon/2)**2;
  return 2*R*Math.asin(Math.sqrt(x));
}

/* ========== Búsqueda principal ========== */

async function runKeywordSearch(interests) {
  const hotelCenter = hotelMarker.getPosition();
  const requested = parseInt((document.getElementById("radiusSelect") || {}).value || "1000", 10);
  const radius = Math.min(requested, 50000); // Nearby efectivo hasta ~50km

  // círculo de radio
  if (radiusCircle) radiusCircle.setMap(null);
  radiusCircle = new google.maps.Circle({
    strokeColor: "#d4af37", strokeOpacity: 0.8, strokeWeight: 2,
    fillColor: "#d4af37", fillOpacity: 0.15,
    map, center: hotelCenter, radius
  });

  clearMarkers();
  paginator = null;

  // Expandir (split + map a types/keywords)
  const inputs  = splitInterests(interests);
  const queries = expandInterestsToQueries(inputs);

  let results = [];

  // 1) types primero
  for (const q of queries.filter(q => q.kind === 'type')) {
    const partial = await doNearbySearchType(radius, hotelCenter, q.value);
    results = results.concat(partial);
  }

  // 2) luego keywords (+ fallback a textSearch si viene vacío)
  for (const q of queries.filter(q => q.kind === 'keyword')) {
    let partial = await doNearbySearchKeyword(radius, hotelCenter, q.value);
    if (!partial.length) partial = await doTextSearch(radius, hotelCenter, q.value);
    results = results.concat(partial);
  }

  // 3) filtro por distancia (TextSearch puede ignorar radius)
  const centerLL = hotelCenter;
  results = results.filter(p => {
    if (!p.geometry || !p.geometry.location) return false;
    const d = distanceMetersLL(p.geometry.location, centerLL);
    return d <= radius + 2000; // margen 2km para sesgos
  });

  // 4) filtro por rating
  const minRating = parseFloat(document.getElementById("ratingFilter")?.value || "0");
  results = results.filter(p => (p.rating || 0) >= minRating);

  // 5) quitar duplicados por place_id
  const seen = new Set();
  results = results.filter(p => {
    if (seen.has(p.place_id)) return false;
    seen.add(p.place_id);
    return true;
  });

  addMarkers(results);
  updateResultsList(results);

  // Ajustar bounds
  const bounds = new google.maps.LatLngBounds();
  if (radiusCircle?.getBounds) bounds.union(radiusCircle.getBounds());
  markers.forEach(m => bounds.extend(m.getPosition()));
  if (!bounds.isEmpty()) map.fitBounds(bounds);
}

/* ========== Detalles ========== */

function showPlaceDetails(placeId) {
  places.getDetails(
    { placeId, fields: ["name","formatted_address","international_phone_number","opening_hours","photos","url","rating"] },
    (place, status) => {
      if (status !== google.maps.places.PlacesServiceStatus.OK || !place) return;

      const panel = document.getElementById("place-details");
      panel.querySelector("#details-name").textContent = place.name || "";
      panel.querySelector(".details-address").textContent = place.formatted_address || "";
      panel.querySelector(".details-phone").textContent = place.international_phone_number || "";
      panel.querySelector(".details-rating").textContent = place.rating ? `⭐ ${place.rating.toFixed(1)}/5` : "";
      panel.querySelector(".details-hours").textContent = place.opening_hours?.weekday_text?.join(" | ") || "";

      if (place.photos?.length) {
        panel.querySelector(".details-photo").style.backgroundImage =
          `url(${place.photos[0].getUrl({maxWidth:600})})`;
      } else {
        panel.querySelector(".details-photo").style.backgroundImage = "";
      }

      const linksEl = panel.querySelector(".details-links");
      linksEl.innerHTML = "";
      if (place.url) {
        const a = document.createElement("a");
        a.href = place.url; a.target = "_blank"; a.textContent = "Ver en Google Maps";
        linksEl.appendChild(a);
      }

      panel.classList.remove("hidden");
    }
  );
}

/* ========== Editor inline (opcional, recomendado) ========== */
/* Divide por comas/enter/pegar en el editor de Turismo y crea chips + inputs hidden */

function initPrefsEditorSplit() {
  if (window.__prefsEditorInit) return; // evitar doble init si hay otro script
  window.__prefsEditorInit = true;

  const wrap = document.getElementById('prefs-wrap');
  const input = document.getElementById('prefs-input');
  if (!wrap || !input) return;

  function addEditorTag(t){
    t = (t||'').trim();
    if (!t) return;
    const exists = Array.from(wrap.querySelectorAll('input[name="custom_interests[]"]'))
      .some(h => h.value.toLowerCase() === t.toLowerCase());
    if (exists) return;

    const span = document.createElement('span');
    span.className = 'prefs-chip';
    span.innerHTML = `
      <input type="hidden" name="custom_interests[]" value="${t}">
      <span>${t}</span>
      <button class="rm" type="button" aria-label="Quitar">&times;</button>
    `;
    wrap.insertBefore(span, input);
  }

  function addChunk(chunk){
    (chunk||'').split(/[,\n\|;\/]+/).forEach(part => addEditorTag(part.trim()));
  }

  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault();
      addChunk(input.value);
      input.value = '';
    } else if (e.key === 'Backspace' && input.value === '') {
      const last = wrap.querySelector('.prefs-chip:last-of-type');
      if (last) last.remove();
    }
  });

  input.addEventListener('paste', (e) => {
    const text = (e.clipboardData || window.clipboardData).getData('text') || '';
    if (/[,\n\|;\/]/.test(text)) {
      e.preventDefault();
      addChunk(text);
    }
  });

  wrap.addEventListener('click', (e) => {
    if (e.target.classList.contains('rm')) {
      e.target.closest('.prefs-chip')?.remove();
    }
  });
}

/* ========== Vínculos UI principales ========== */

function collectInlineChips() {
  const wrap = document.getElementById('prefs-wrap');
  const input = document.getElementById('prefs-input');
  const chips = Array.from(wrap?.querySelectorAll('input[name="custom_interests[]"]') || [])
    .map(h => (h.value || '').trim()).filter(Boolean);
  const pending = (input?.value || '').trim();
  if (pending) chips.push(pending);
  return chips;
}

function bindUI() {
  // Buscar según mis intereses (BD)
  document.getElementById("myInterestsBtn")?.addEventListener("click", async () => {
    const interests = (window.USER_INTERESTS || []).map(t => String(t).trim()).filter(Boolean);
    if (!interests.length) {
      const editor = document.getElementById('prefsEditor');
      const input = document.getElementById('prefs-input');
      try {
        if (typeof bootstrap !== 'undefined' && editor) {
          new bootstrap.Collapse(editor, { toggle: true });
        } else {
          editor?.classList.add('show');
        }
      } catch(e){}
      input?.focus();
      return;
    }
    await runKeywordSearch(interests);
  });

  // Buscar con estas (sin guardar)
  document.getElementById("manualSearchBtn")?.addEventListener("click", async () => {
    const manual = splitInterests(collectInlineChips());
    if (!manual.length) {
      alert("Añade al menos un interés o usa 'Buscar según mis intereses'.");
      return;
    }
    await runKeywordSearch(manual);
  });

  // Cerrar panel lateral
  document.getElementById("details-close")?.addEventListener("click", () => {
    document.getElementById("place-details").classList.add("hidden");
  });

  // Inicializar editor inline “opcional” (separación por comas)
  initPrefsEditorSplit();
}

/* ========== Bootstrap global ========== */

window.initMap = function () {
  const center = getHotelCenter();
  map = new google.maps.Map(document.getElementById("map"), { center, zoom: 15 });
  places = new google.maps.places.PlacesService(map);
  info = new google.maps.InfoWindow();

  addHotelMarker(center);
  bindUI();

  // Auto-búsqueda si ya hay intereses en BD
  if ((window.USER_INTERESTS || []).length) {
    runKeywordSearch(window.USER_INTERESTS);
  }
};
