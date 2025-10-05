(function(){
  if (!window.foyerSchedulerData) { return; }
  var ajaxurl = String(foyerSchedulerData.ajaxurl||'');
  var nonce = String(foyerSchedulerData.nonce||'');
  var siteTz = String(foyerSchedulerData.siteTz||'UTC');
  var foyerCalChannels = Array.isArray(foyerSchedulerData.channels) ? foyerSchedulerData.channels : [];

  var __ovEscHandler = null;

  function escapeHTML(s){
    return String(s)
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#039;');
  }

  function getOffsetMinutesForTZ(dateUTC, timeZone){
    try {
      var fmt = new Intl.DateTimeFormat('en-US', {timeZone: timeZone, hour12:false, year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', second:'2-digit'});
      var parts = fmt.formatToParts(dateUTC);
      function v(t){ var p = parts.find(function(x){return x.type===t}); return p? p.value : '00'; }
      var asIfUTC = Date.UTC(parseInt(v('year'),10), parseInt(v('month'),10)-1, parseInt(v('day'),10), parseInt(v('hour'),10), parseInt(v('minute'),10), parseInt(v('second'),10));
      var diffMs = asIfUTC - dateUTC.getTime();
      return Math.round(diffMs/60000);
    } catch(e){ return -dateUTC.getTimezoneOffset(); }
  }
  function shiftUtcToSite(dateUTC){
    var browserOffset = -dateUTC.getTimezoneOffset();
    var siteOffset = getOffsetMinutesForTZ(dateUTC, siteTz);
    var deltaMin = siteOffset - browserOffset;
    return new Date(dateUTC.getTime() + deltaMin*60000);
  }
  function parseIsoUtc(value){ var t = Date.parse(value); return isNaN(t)? null : new Date(t); }
  function parseLocalDateTime(value){
    try {
      var s = String(value||'').trim();
      if (!s) return null;
      var isoish = s.replace(' ', 'T');
      var d = new Date(isoish);
      if (isNaN(d.getTime())) return null;
      return d;
    } catch(e){ return null; }
  }

  function getSelectedDisplays(){
    var out=[]; document.querySelectorAll('#foyerCalDisplays .foyerCalDisplay:checked').forEach(function(i){ out.push(parseInt(i.value,10)); });
    return out;
  }
  var selAll = document.getElementById('foyerCalSelectAll');
  var selAllRow = document.getElementById('foyerCalSelectAllRow');
  var dispList = document.getElementById('foyerCalDisplays');

  function makeLightColor(hsl){
    try{
      var m = /^hsl\(\s*(\d{1,3})\s*,\s*([\d\.]+)%\s*,\s*([\d\.]+)%\s*\)$/i.exec(hsl);
      if(!m) return '';
      var h = parseInt(m[1],10), s = parseFloat(m[2]), l = parseFloat(m[3]);
      var l2 = Math.min(95, l + 22);
      return 'hsl('+h+','+s+'%,'+l2+'%)';
    }catch(e){ return ''; }
  }
  function syncSelectAllFromItems(){
    try{
      var boxes = document.querySelectorAll('#foyerCalDisplays .foyerCalDisplay');
      var all = boxes.length > 0 && Array.prototype.every.call(boxes, function(cb){ return cb.checked; });
      if (selAll) selAll.checked = all;
    }catch(e){}
  }
  function updateDisplaySelectionStyles(){
    var items = document.querySelectorAll('#foyerCalDisplays .foyer-display-item');
    items.forEach(function(item){
      var cb = item.querySelector('.foyerCalDisplay');
      var base = item.getAttribute('data-color') || '';
      if (cb && cb.checked) {
        var light = makeLightColor(base) || base;
        item.style.backgroundColor = light;
        item.classList.add('is-selected');
        item.setAttribute('aria-pressed','true');
      } else {
        item.style.backgroundColor = '';
        item.classList.remove('is-selected');
        item.setAttribute('aria-pressed','false');
      }
    });
    if (typeof syncSelectAllFromItems === 'function') { syncSelectAllFromItems(); }
    if (selAllRow && selAll){
      var base = selAllRow.getAttribute('data-color') || 'hsl(210, 20%, 85%)';
      if (selAll.checked){
        var light = makeLightColor(base) || base;
        selAllRow.style.backgroundColor = light;
        selAllRow.classList.add('is-selected');
        selAllRow.setAttribute('aria-pressed','true');
      } else {
        selAllRow.style.backgroundColor = '';
        selAllRow.classList.remove('is-selected');
        selAllRow.setAttribute('aria-pressed','false');
      }
    }
  }

  if (selAll){ selAll.addEventListener('change', function(){ var c=this.checked; document.querySelectorAll('#foyerCalDisplays .foyerCalDisplay').forEach(function(i){ i.checked=c; }); updateDisplaySelectionStyles(); scheduleRefetch('display-select-all'); }); }
  document.addEventListener('change', function(e){ if(e.target && e.target.classList && e.target.classList.contains('foyerCalDisplay')){ updateDisplaySelectionStyles(); scheduleRefetch('display-change'); } });
  if (dispList){
    dispList.addEventListener('click', function(e){
      var item = e.target.closest('.foyer-display-item');
      if(!item || !dispList.contains(item)) return;
      var cb = item.querySelector('.foyerCalDisplay');
      if (cb){ cb.checked = !cb.checked; syncSelectAllFromItems(); updateDisplaySelectionStyles(); scheduleRefetch('display-change'); }
    });
    dispList.addEventListener('keydown', function(e){
      var item = e.target.closest('.foyer-display-item');
      if(!item || !dispList.contains(item)) return;
      if (e.key === ' ' || e.key === 'Enter'){
        e.preventDefault();
        var cb = item.querySelector('.foyerCalDisplay');
        if (cb){ cb.checked = !cb.checked; syncSelectAllFromItems(); updateDisplaySelectionStyles(); scheduleRefetch('display-change'); }
      }
    });
  }
  if (selAllRow){
    selAllRow.addEventListener('click', function(e){ e.preventDefault(); selAll.checked = !selAll.checked; document.querySelectorAll('#foyerCalDisplays .foyerCalDisplay').forEach(function(i){ i.checked = selAll.checked; }); updateDisplaySelectionStyles(); scheduleRefetch('display-select-all'); });
    selAllRow.addEventListener('keydown', function(e){ if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); selAllRow.click(); } });
  }

  var calEl = document.getElementById('foyerSchedulesCalendar');
  var ec = null;
  // track last pointer position inside calendar for overlay animation origin
  if (calEl){
    var __storePointer = function(e){ try{ if(e && typeof e.clientX==='number'){ window.__lastCalPointer = { x: e.clientX, y: e.clientY }; } }catch(err){} };
    calEl.addEventListener('mousedown', __storePointer, true);
    calEl.addEventListener('click', __storePointer, true);
    calEl.addEventListener('touchstart', function(e){ try{ var t = e && e.touches && e.touches[0]; if(t){ window.__lastCalPointer = { x: t.clientX, y: t.clientY }; } }catch(err){} }, true);
  }
  function ensureCalendar(){
    if (ec) return ec;
    try {
      if (!window.EventCalendar || !window.EventCalendar.create) { throw new Error('EventCalendar.create not found'); }
      ec = window.EventCalendar.create(calEl, {
        view: 'timeGridWeek',
        date: new Date(),
        editable: true,
        selectable: true,
        scrollTime: '07:00:00',
        events: [],
        headerToolbar: { start: 'title', center: '', end: 'today prev,next dayGridMonth,timeGridWeek' },
        views: {
          dayGridMonth: { dayMaxEvents: 3, displayEventEnd: false },
          timeGridWeek: { slotDuration: '00:30:00', nowIndicator: true, allDaySlot: false }
        },
        datesSet: function(info){ try { applyMonthStyling(); scheduleRefetch('datesSet', info.start, info.end); scrollToCoreTime(); } catch(e){} },
        loading: function(isLoading){ try { var dbg=document.getElementById('foyerCalDebug'); if(dbg){ dbg.innerHTML = isLoading ? 'Loading…' : dbg.innerHTML; } } catch(e){} },
        dateClick: handleDateClick,
        select: handleSelect,
        eventClick: handleEventClick,
        eventDrop: handleEventDrop,
        eventResize: handleEventResize,
        eventDidMount: function(info){
          try {
            var el = info.el || null;
            var ev = info.event || {};
            var xp = ev.extendedProps || {};
            var displays = Array.isArray(xp.displays) ? xp.displays : [];
            if (!el || !displays.length) return;
            var titleNode = el.querySelector('.ec-title') || el.querySelector('.ec-event-title') || null;
            var timeNode = el.querySelector('.ec-time') || el.querySelector('.ec-event-time') || null;
            var head = document.createElement('div');
            head.className = 'foyer-ev-head';
            var sw = document.createElement('div');
            sw.className = 'foyer-ev-swatches';
            displays.forEach(function(d){
              var s = document.createElement('span');
              s.className = 'foyer-display-swatch';
              s.style.background = d.color || '';
              s.title = d.title || ('Display #' + d.id);
              sw.appendChild(s);
            });
            head.appendChild(sw);
            // Clicking swatches: ensure all displays of this event are selected in the sidebar
            sw.addEventListener('click', function(e){
              try {
                e.preventDefault();
                e.stopPropagation();
                var ids = Array.isArray(xp.display_ids) ? xp.display_ids : (Array.isArray(xp.displays) ? xp.displays.map(function(d){ return d && d.id; }) : []);
                ids.forEach(function(id){
                  var cb = document.querySelector('#foyerCalDisplays .foyerCalDisplay[value="'+id+'"]');
                  if (cb) { cb.checked = true; }
                });
                if (typeof updateDisplaySelectionStyles === 'function') { updateDisplaySelectionStyles(); }
                if (typeof scheduleRefetch === 'function') { scheduleRefetch('display-change'); }
              } catch(err){}
            }, false);
            var anchor = timeNode || titleNode;
            if (anchor && anchor.parentNode) {
              anchor.parentNode.insertBefore(head, anchor);
            } else if (el.firstChild) {
              el.insertBefore(head, el.firstChild);
            } else {
              el.appendChild(head);
            }
          } catch(e){}
        },
        eventAllUpdated: function(info){}
      });
      applyMonthStyling();
      setTimeout(scrollToCoreTime, 60);
    } catch(e){
      if (calEl){ calEl.innerHTML = '<div style="padding:12px;">'+ escapeHTML(e && e.message ? e.message : 'Calendar failed to initialize') +'</div>'; }
    }
    return ec;
  }

  function applyMonthStyling(){
    try {
      var type = (ec && typeof ec.getView === 'function' && ec.getView()) ? ec.getView().type : '';
      if (!calEl) return;
      if (type === 'dayGridMonth') { calEl.classList.add('foyer-month-view'); }
      else { calEl.classList.remove('foyer-month-view'); }
    } catch(e){}
  }

  function findCalendarScroller(){
    try {
      var known = calEl.querySelector('.ec-scroll-y') || calEl.querySelector('.ec-timegrid-scroller') || calEl.querySelector('.ec-scroller');
      if (known && known.scrollHeight > known.clientHeight) { return known; }
      var all = calEl.querySelectorAll('*');
      for (var i=0;i<all.length;i++){
        var el = all[i];
        var cs = window.getComputedStyle(el);
        if (!cs) continue;
        var oy = cs.overflowY;
        if ((oy === 'auto' || oy === 'scroll') && (el.scrollHeight - el.clientHeight) > 40){ return el; }
      }
    } catch(e){}
    return null;
  }
  function scrollToHour(hour){
    try {
      var sc = findCalendarScroller();
      if (!sc) return;
      var ratio = Math.max(0, Math.min(1, hour/24));
      var maxScroll = Math.max(0, sc.scrollHeight - sc.clientHeight);
      sc.scrollTop = Math.round(maxScroll * ratio);
    } catch(e){}
  }
  function scrollToCoreTime(){
    try {
      if (!ec || typeof ec.getView !== 'function') return;
      var v = ec.getView();
      var t = v && v.type ? v.type : '';
      if (t && t.indexOf('timeGrid') === 0){ setTimeout(function(){ scrollToHour(7); }, 30); }
    } catch(e){}
  }

  function toSiteLocalString(date){
    try {
      var fmt = new Intl.DateTimeFormat('en-CA', { timeZone: siteTz, year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:false });
      var parts = fmt.formatToParts(date);
      var v = function(t){ var p=parts.find(function(x){return x.type===t}); return p?p.value:'00'; };
      return v('year')+'-'+v('month')+'-'+v('day')+' '+v('hour')+':'+v('minute')+':'+v('second');
    } catch(e) {
      var pad=function(n){ return (n<10?'0':'')+n; };
      return date.getFullYear()+'-'+pad(date.getMonth()+1)+'-'+pad(date.getDate())+' '+pad(date.getHours())+':'+pad(date.getMinutes())+':'+pad(date.getSeconds());
    }
  }

  function buildChannelSelect(selectedId){
    var sel = document.createElement('select');
    sel.id = 'foyerCalChannelSelect';
    var opt0 = document.createElement('option');
    opt0.value = '';
    opt0.textContent = '—';
    sel.appendChild(opt0);
    (foyerCalChannels||[]).forEach(function(ch){
      var o = document.createElement('option');
      o.value = String(ch.id);
      o.textContent = ch && ch.title ? String(ch.title) : ('Channel #'+String(ch.id));
      if (selectedId && String(selectedId) === String(ch.id)) { o.selected = true; }
      sel.appendChild(o);
    });
    return sel;
  }

  function openModal(contentNode){
    var existing = document.getElementById('foyerCalModal'); if (existing) existing.remove();
    var wrap = document.createElement('div');
    wrap.id = 'foyerCalModal';
    wrap.style.position = 'fixed'; wrap.style.left='0'; wrap.style.top='0'; wrap.style.right='0'; wrap.style.bottom='0'; wrap.style.background='rgba(0,0,0,0.4)'; wrap.style.zIndex='100000';
    var panel = document.createElement('div');
    panel.style.position='absolute'; panel.style.left='50%'; panel.style.top='10%'; panel.style.transform='translateX(-50%)'; panel.style.background='#fff'; panel.style.border='1px solid #ccd0d4'; panel.style.boxShadow='0 2px 12px rgba(0,0,0,.2)'; panel.style.padding='16px'; panel.style.minWidth='420px';
    panel.appendChild(contentNode);
    wrap.appendChild(panel);
    document.body.appendChild(wrap);
    return wrap;
  }
  function closeModal(){ var m=document.getElementById('foyerCalModal'); if(m) m.remove(); }

  function buildFormRow(labelText, input){
    var p = document.createElement('p');
    var label = document.createElement('label');
    label.textContent = labelText + ' ';
    label.appendChild(input);
    p.appendChild(label);
    return p;
  }

  function handleDateClick(info){
    var displays = getSelectedDisplays();
    if (!displays.length){ alert((window.foyerSchedulerI18n && foyerSchedulerI18n.selectDisplay) || 'Please select at least one display.'); return; }
    var base = info && info.date ? info.date : new Date();
    foyerOpenOverlayCreate(base);
  }

  function handleSelect(info){
    var displays = getSelectedDisplays();
    if (!displays.length){ alert((window.foyerSchedulerI18n && foyerSchedulerI18n.selectDisplay) || 'Please select at least one display.'); return; }
    var start = info && info.start ? info.start : new Date();
    var end = info && info.end ? info.end : new Date(start.getTime()+60*60*1000);
    foyerOpenOverlayCreate(start, end);
  }

  function handleEventClick(info){
    try { foyerOpenOverlayEdit(info && info.event ? info.event : null); } catch(e){}
  }
  function handleEventDrop(info){
    var ev = info.event; var xp = ev.extendedProps||{};
    var data = new FormData();
    data.append('action','foyer_schedules_update_event');
    data.append('nonce', nonce);
    data.append('schedule_post_id', xp.schedule_post_id);
    // no display_id needed; updates apply to all displays of this schedule
    data.append('occ_id', xp.occ_id);
    data.append('new_start_local', toSiteLocalString(ev.start));
    data.append('new_end_local', toSiteLocalString(ev.end));
    data.append('apply_to','occurrence');
    fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:data })
      .then(function(r){ return r.json(); })
      .then(function(resp){ if(!resp || !resp.success){ throw new Error((resp && resp.data && resp.data.message)||'Update failed'); } refetch(); })
      .catch(function(err){ if (info && typeof info.revert === 'function') info.revert(); alert(err && err.message ? err.message : String(err)); });
  }
  function handleEventResize(info){ handleEventDrop(info); }

  function setCalendarEvents(evs){
    var cal = ensureCalendar();
    if (!cal) return;
    if (typeof cal.setOption === 'function') { cal.setOption('events', evs); return; }
    if (typeof cal.setEvents === 'function') { cal.setEvents(evs); return; }
    if (typeof cal.setOptions === 'function') { cal.setOptions({ events: evs }); return; }
  }

  var lastFetch = { startIso: '', endIso: '', displaysKey: '', viewType: '' };
  var refetchTimer = null;

  function mapServerEvents(evs){
    return (evs||[]).map(function(e){
      var s = parseIsoUtc(e.startDate), en = parseIsoUtc(e.endDate);
      var startD = shiftUtcToSite(s);
      var endD = shiftUtcToSite(en);
      var t = e.title || (e.extendedProps && e.extendedProps.title) || 'Schedule';
      return {
        id: e.id,
        title: t,
        start: startD,
        end: endD,
        startDate: startD,
        endDate: endD,
        name: t,
        text: t,
        allDay: false,
        // keep event background neutral; colors are shown via swatches
        extendedProps: e.extendedProps || {}
      };
    });
  }

  function getVisibleRangeFromView(){
    if (!ec || typeof ec.getView !== 'function') return null;
    var v = ec.getView();
    return { start: v.activeStart, end: v.activeEnd, type: v.type };
  }

  function scheduleRefetch(trigger, startOpt, endOpt){
    var vr = (startOpt && endOpt) ? { start: startOpt, end: endOpt } : getVisibleRangeFromView();
    if (!vr || !vr.start || !vr.end) return;
    var displays = getSelectedDisplays();
    if (!displays.length){ setCalendarEvents([]); return; }
    var startIso = new Date(vr.start).toISOString();
    var endIso   = new Date(vr.end).toISOString();
    var viewType = (ec && typeof ec.getView === 'function' && ec.getView()) ? ec.getView().type : '';
    var displaysKey = displays.slice().sort(function(a,b){return a-b;}).join(',');

    var force = (trigger === 'create' || trigger === 'update' || trigger === 'delete' || trigger === 'manual');
    if (!force && lastFetch.startIso === startIso && lastFetch.endIso === endIso && lastFetch.displaysKey === displaysKey && lastFetch.viewType === viewType) {
      return;
    }

    clearTimeout(refetchTimer);
    refetchTimer = setTimeout(function(){ doRefetch(startIso, endIso, displays, viewType); }, 200);
  }

  function refetch(){ try { scheduleRefetch('manual'); } catch(e){} }

  function doRefetch(startIso, endIso, displayIds, viewType){
    lastFetch = { startIso: startIso, endIso: endIso, displaysKey: displayIds.slice().sort(function(a,b){return a-b;}).join(','), viewType: viewType };
    var data = new FormData();
    data.append('action', 'foyer_schedules_get_events');
    data.append('nonce', nonce);
    displayIds.forEach(function(id){ data.append('display_ids[]', String(id)); });
    data.append('start', startIso);
    data.append('end', endIso);
    fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:data })
      .then(function(r){ return r.json(); })
      .then(function(resp){
        if (!resp || !resp.success){ throw new Error((resp && resp.data && resp.data.message) || 'Fetch failed'); }
        var mapped = mapServerEvents(resp.data && resp.data.events ? resp.data.events : []);
        setCalendarEvents(mapped);
        setTimeout(function(){
          var calNode = document.getElementById('foyerSchedulesCalendar');
          var n1 = calNode ? calNode.querySelectorAll('.ec-event').length : 0;
          var n2 = calNode ? calNode.querySelectorAll('.ec .ec-event').length : 0;
          var n = Math.max(n1, n2);
          var dbg = document.getElementById('foyerCalDebug');
          if (dbg) {
            var first = mapped[0] ? { id: mapped[0].id, title: mapped[0].title, start: mapped[0].start, end: mapped[0].end } : null;
            try { dbg.innerHTML = 'Events: ' + mapped.length + '<br/>First: ' + (first ? escapeHTML(JSON.stringify(first)) : '-') + '<br/>Rendered: ' + n; } catch(e) { dbg.textContent = 'Events: ' + mapped.length + ' Rendered: ' + n; }
          }
          scrollToCoreTime();
        }, 300);
      })
      .catch(function(err){ var dbg=document.getElementById('foyerCalDebug'); if(dbg){ dbg.textContent = 'Error: ' + (err && err.message ? err.message : String(err)); } });
  }

  // Preselect displays from URL (?display=ID or ?displays=ID,ID2)
  function parsePreselectedDisplays(){
    try {
      var params = new URLSearchParams(window.location.search);
      var ids = [];
      if (params.has('display')) {
        var v = parseInt(params.get('display'), 10);
        if (!isNaN(v) && v > 0) ids.push(v);
      }
      if (params.has('displays')) {
        String(params.get('displays')||'').split(',').forEach(function(s){
          var v = parseInt(s, 10);
          if (!isNaN(v) && v > 0) ids.push(v);
        });
      }
      var seen = {};
      return ids.filter(function(x){ if(seen[x]) return false; seen[x]=true; return true; });
    } catch(e){ return []; }
  }
  function preselectDisplays(ids){
    if (!Array.isArray(ids) || !ids.length) return;
    ids.forEach(function(id){
      var cb = document.querySelector('#foyerCalDisplays .foyerCalDisplay[value="'+id+'"]');
      if (cb) { cb.checked = true; }
    });
    updateDisplaySelectionStyles();
  }
  var __pre = parsePreselectedDisplays();
  if (__pre && __pre.length) { preselectDisplays(__pre); }
  function getLastCalPointer(){ var p = window.__lastCalPointer; if(p && typeof p.x==='number' && typeof p.y==='number'){ return p; } return { x: Math.round(window.innerWidth/2), y: Math.round(window.innerHeight/2) }; }

  // Overlay CSS injector
  function foyerInjectOverlayCSS(){
    if (document.getElementById('foyerSchedulerOverlayStyle')) return;
    var css = ''+
      '#foyerSchedulerOverlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100001;display:flex;align-items:stretch;justify-content:center;will-change:clip-path;-webkit-clip-path:circle(150% at 50% 50%);clip-path:circle(150% at 50% 50%);transition:clip-path .32s ease-in-out,-webkit-clip-path .32s ease-in-out;}'+
      '#foyerSchedulerOverlay .foyer-ov-panel{background:#fff;width:96vw;height:90vh;margin:auto;box-shadow:0 4px 24px rgba(0,0,0,.3);display:grid;grid-template-rows:auto 1fr;grid-template-columns:1fr;gap:16px;padding:16px;box-sizing:border-box;}'+
      '#foyerSchedulerOverlay .foyer-ov-top{overflow:auto;padding:0 8px;}'+
      '#foyerSchedulerOverlay .foyer-ov-bottom{display:grid;grid-template-columns:4fr 1fr;gap:16px;height:100%;overflow:hidden;}'+
      '#foyerSchedulerOverlay .foyer-ov-channelsWrap{overflow:auto;padding-right:8px;}'+
      '#foyerSchedulerOverlay .foyer-ov-displaysWrap{overflow:auto;padding-left:8px;}'+
      '#foyerSchedulerOverlay .foyer-ov-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}'+
      '#foyerSchedulerOverlay .foyer-ov-head > div{display:flex;gap:8px;}'+
      '#foyerSchedulerOverlay h2{margin:0 0 8px 0;}' +
      '#foyerSchedulerOverlay .ov-form-row{margin:8px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;}' +
      '#foyerSchedulerOverlay .ov-actions{position:sticky;bottom:0;display:flex;gap:8px;justify-content:flex-end;padding-top:8px;margin-top:12px;background:#fff;}' +
      '#foyerSchedulerOverlay .foyer-ov-displays .ov-display-item{display:flex;align-items:center;gap:8px;padding:8px 10px;margin:4px 0;border-radius:4px;cursor:pointer;user-select:none;transition:background-color .15s ease,border-color .15s ease;border:1px solid transparent;}' +
      '#foyerSchedulerOverlay .foyer-ov-displays .ov-display-item input{display:none;}' +
      '#foyerSchedulerOverlay .foyer-ov-displays .ov-display-item:hover{background:#f6f7f7;}' +
      '#foyerSchedulerOverlay .foyer-ov-displays .ov-display-item.is-selected{border-color:rgba(0,0,0,.1);}' +
      '#foyerSchedulerOverlay .foyer-ov-displays .ov-display-swatch{display:inline-block;width:12px;height:12px;border:1px solid #999;border-radius:2px;}' +
      '#foyerSchedulerOverlay .foyer-ov-displays .ov-select-all .ov-display-swatch{background:repeating-linear-gradient(45deg,#bbb,#bbb 4px,#dedede 4px,#dedede 8px);border-color:#aaa;}' +
      '#foyerSchedulerOverlay .foyer-ov-displays .ov-display-item .foyer-display-title{flex:1;}' +
      '#foyerSchedulerOverlay input[type=checkbox].ovDisplay{display:none !important;}' +
      '#foyerSchedulerOverlay #ovSelectAll{display:none !important;}' +
      '#foyerSchedulerOverlay .foyer-ov-channels{display:grid;gap:12px;grid-template-columns:repeat(2, 1fr);}' +
      '@media(min-width:1100px){#foyerSchedulerOverlay .foyer-ov-channels{grid-template-columns:repeat(3, 1fr);}}' +
      '@media(min-width:1400px){#foyerSchedulerOverlay .foyer-ov-channels{grid-template-columns:repeat(4, 1fr);}}' +
      '#foyerSchedulerOverlay .foyer-channel-card{display:block;text-align:left;border:1px solid #ddd;border-radius:4px;overflow:hidden;background:#fafafa;cursor:pointer;}' +
      '#foyerSchedulerOverlay .foyer-channel-card.is-selected{outline:2px solid #2271b1; background:#eef6ff;}' +
      '#foyerSchedulerOverlay .foyer-channel-card__preview{position:relative;width:100%;padding-top:56.25%;background:#e0e0e0;}' +
      '#foyerSchedulerOverlay .foyer-channel-card__preview iframe{position:absolute;inset:0;width:100%;height:100%;border:0;}' +
      '#foyerSchedulerOverlay .foyer-channel-card__meta{padding:8px 10px;}' +
      '#foyerSchedulerOverlay .foyer-channel-card__title{font-weight:600;margin-bottom:4px;}' +
      '#foyerSchedulerOverlay .ov-group{padding:10px;border-radius:4px;margin-bottom:10px;}' +
      '#foyerSchedulerOverlay .ov-group legend{font-weight:600;}' +
      '#foyerSchedulerOverlay .ov-hidden{display:none !important;}' +
      '#foyerSchedulerOverlay .ov-datetime-grid{display:grid;grid-template-columns:1fr;gap:12px;align-items:end;}' +
      '@media(min-width:700px){#foyerSchedulerOverlay .ov-datetime-grid{grid-template-columns:1fr;}}' +
      '#foyerSchedulerOverlay .ov-field label{display:block;margin-bottom:4px;font-weight:600;}' +
      '#foyerSchedulerOverlay .ov-field input{width:100%;}' +
      '#foyerSchedulerOverlay #ovStartLocal, #foyerSchedulerOverlay #ovEndLocal{width:50%;}' +
      '#foyerSchedulerOverlay #ovUntil{width:70%;}' +
      '#foyerSchedulerOverlay .ov-end-row .ov-end-opt{display:inline-flex;align-items:center;white-space:nowrap;}' +
      '#foyerSchedulerOverlay .ov-end-row .ov-end-opt input[type=text]{margin-left:6px;}' +
      '#foyerSchedulerOverlay .ov-form-grid{display:grid;grid-template-columns:1fr;gap:16px;align-items:start;}' +
      '@media(min-width:700px){#foyerSchedulerOverlay .ov-form-grid{grid-template-columns:1fr 1fr;}}' +
      '#foyerSchedulerOverlay .ov-col-left,#foyerSchedulerOverlay .ov-col-right{min-width:0;}' +
      '#foyerSchedulerOverlay .ov-recur-summary{margin:6px 0 2px 0;font-size:12px;color:#555;}' +
      '#foyerSchedulerOverlay .ov-rrule{margin:4px 0;color:#666;font-size:11px;font-family:Menlo,Monaco,Consolas,monospace;word-break:break-all;}' +
      '#foyerSchedulerOverlay .ov-freq-group{display:flex;gap:8px;flex-wrap:wrap;}' +
      '#foyerSchedulerOverlay .ov-chip{border:1px solid #ccc;border-radius:16px;padding:4px 10px;cursor:pointer;user-select:none;}' +
      '#foyerSchedulerOverlay .ov-chip input{display:none;}' +
      '#foyerSchedulerOverlay .ov-chip.is-active{background:#2271b1;color:#fff;border-color:#1d5a8f;}' +
      '#foyerSchedulerOverlay #ovWeeklyOpts label{border:1px solid #ccc;border-radius:16px;padding:4px 10px;cursor:pointer;user-select:none;}' +
      '#foyerSchedulerOverlay #ovWeeklyOpts input{display:none;}' +
      '#foyerSchedulerOverlay #ovWeeklyOpts label.is-active{background:#2271b1;color:#fff;border-color:#1d5a8f;}' +
      '';
    var style = document.createElement('style'); style.id='foyerSchedulerOverlayStyle'; style.type='text/css'; style.appendChild(document.createTextNode(css)); document.head.appendChild(style);
  }

  function foyerOverlayCreateStructure(origin, titleText){
    foyerInjectOverlayCSS();
    var wrap = document.getElementById('foyerSchedulerOverlay');
    if (wrap) { try { if (window.__ovEscHandler) { document.removeEventListener('keydown', window.__ovEscHandler); window.__ovEscHandler = null; } } catch(e){} wrap.remove(); }
    wrap = document.createElement('div');
    wrap.id = 'foyerSchedulerOverlay';
    var ox = (origin && typeof origin.x === 'number') ? origin.x : Math.round(window.innerWidth/2);
    var oy = (origin && typeof origin.y === 'number') ? origin.y : Math.round(window.innerHeight/2);
    wrap.dataset.originX = String(ox);
    wrap.dataset.originY = String(oy);
    // start collapsed at origin (will expand via RAF)
    try { wrap.style.webkitClipPath = 'circle(0px at '+ox+'px '+oy+'px)'; } catch(e){}
    try { wrap.style.clipPath = 'circle(0px at '+ox+'px '+oy+'px)'; } catch(e){}
    var panel = document.createElement('div'); panel.className='foyer-ov-panel';
    var top = document.createElement('section'); top.className='foyer-ov-top'; top.innerHTML = ''+
      '<div class="foyer-ov-head"><h2>'+ escapeHTML(titleText || 'Schedule') +'</h2><div><button class="button button-primary" id="ovSave">'+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.save)||'Save'))+'</button><button class="button" id="ovCloseBtn">'+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.cancel)||'Cancel'))+'</button></div></div>'+
      '<div class="ov-form">'+
        '<div class="ov-form-grid">'+
          '<div class="ov-col-left">'+
            '<div class="ov-form-row ov-datetime-grid">'+
              '<div class="ov-field"><label>'+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.startLabel)||'Start'))+'</label><input type="text" id="ovStartLocal" class="regular-text" placeholder="YYYY-MM-DD HH:mm:ss"/></div>'+
              '<div class="ov-field"><label>'+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.endLabel)||'End'))+'</label><input type="text" id="ovEndLocal" class="regular-text" placeholder="YYYY-MM-DD HH:mm:ss"/></div>'+
            '</div>'+
            '<div class="ov-recur-summary" id="ovRecurSummary" aria-live="polite" style="opacity:.85;"></div>'+
          '</div>'+
          '<div class="ov-col-right">'+
            '<fieldset class="ov-recur" id="ovRecurBox">'+
              '<div class="ov-form-row ov-freq-row">'+
                '<div class="ov-freq-group" role="radiogroup" aria-label="Häufigkeit">'+
                  '<label class="ov-chip"><input type="radio" name="ovFreq" value="SINGLE" checked> '+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.freqSingle)||'Single'))+'</label>'+
                  '<label class="ov-chip"><input type="radio" name="ovFreq" value="DAILY"> '+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.freqDaily)||'Daily'))+'</label>'+
                  '<label class="ov-chip"><input type="radio" name="ovFreq" value="WEEKLY"> '+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.freqWeekly)||'Weekly'))+'</label>'+
                  '<label class="ov-chip"><input type="radio" name="ovFreq" value="MONTHLY"> '+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.freqMonthly)||'Monthly'))+'</label>'+
                '</div>'+
                '<div id="ovIntervalWrap" style="margin-left:auto;">'+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.every)||'every'))+' <input type="number" id="ovInterval" class="small-text" min="1" value="1"/> <span id="ovIntervalUnit">'+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.unitDay)||'day(s)'))+'</span></div>'+
              '</div>'+
                            '<div class="ov-form-row ov-weekly ov-hidden" id="ovWeeklyOpts">'+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.weekDaysLabel)||'Days:'))+' '
                +['MO','TU','WE','TH','FR','SA','SU'].map(function(d){return '<label style="margin-right:6px;"><input type="checkbox" class="ovByDay" value="'+d+'"/> '+d+'</label>';}).join(' ')
              +'</div>'+
              '<div class="ov-form-row ov-monthly ov-hidden" id="ovMonthlyOpts">'
                +'<label>'+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.monthDaysLabel)||'Month days (e.g. 1,15,31)'))+' <input type="text" id="ovByMonthDay" class="regular-text" placeholder="1,15,31"/></label>'+
              '</div>'+
                          '<div class="ov-form-row ov-end-row">'+
                '<label class="ov-end-opt"><input type="radio" name="ovEndMode" value="never" checked> '+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.endNever)||'Never ends'))+'</label>'+
                '<label class="ov-end-opt"><input type="radio" name="ovEndMode" value="until"> '+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.endUntil)||'Ends at'))+' <input type="text" id="ovUntil" class="regular-text" placeholder="YYYY-MM-DD HH:mm:ss"/></label>'+
                '<label class="ov-end-opt"><input type="radio" name="ovEndMode" value="count"> '+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.endCount)||'Ends after'))+' <input type="number" id="ovCount" class="small-text" min="1"/> '+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.termsWord)||'occurrences'))+'</label>'+
              '</div>'+
              '<div class="ov-rrule" id="ovRRulePreview" aria-live="polite"></div>'+
            '</fieldset>'+
          '</div>'+
        '</div>'+
      '</div>';
    var bottom = document.createElement('div'); bottom.className='foyer-ov-bottom';
    var channelsWrap = document.createElement('section'); channelsWrap.className='foyer-ov-channelsWrap'; channelsWrap.innerHTML = ''+
      '<div class="foyer-ov-head">'
        +'<div class="ov-chan-title" style="display:flex;gap:8px;align-items:center;">'
          +'<h2>'+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.channelsHeading)||'Channels'))+'</h2>'
          +'<input type="search" id="ovChanSearch" class="regular-text" placeholder="'+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.searchPlaceholder)||'Search…'))+'" style="max-width:220px;" />'
        +'</div>'
        +'<div class="ov-chan-toolbar" style="display:flex;gap:12px;align-items:center;">'
          +'<label>'+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.perPage)||'Per page'))+' '
            +'<select id="ovChanPerPage"><option value="12" selected>12</option><option value="24">24</option><option value="48">48</option></select>'
          +'</label>'
          +'<div id="ovChanPager" class="ov-chan-pager" style="display:flex;align-items:center;gap:8px;">'
            +'<button class="button" id="ovChanPrev" type="button">&laquo;</button>'
            +'<span id="ovChanPageInfo"></span>'
            +'<button class="button" id="ovChanNext" type="button">&raquo;</button>'
          +'</div>'
        +'</div>'
      +'</div>'
      +'<div class="foyer-ov-channels" id="ovChannels"></div>';
    var displaysWrap = document.createElement('aside'); displaysWrap.className='foyer-ov-displaysWrap'; displaysWrap.innerHTML = '<div class="foyer-ov-head"><h2>'+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.displaysHeading)||'Displays'))+'</h2></div><div class="foyer-ov-displays" id="ovDisplays"></div>';
    bottom.appendChild(channelsWrap); bottom.appendChild(displaysWrap);
    panel.appendChild(top); panel.appendChild(bottom); wrap.appendChild(panel); document.body.appendChild(wrap);
    // animate open (expand from origin)
    try {
      requestAnimationFrame(function(){
        requestAnimationFrame(function(){
          var _ox = parseInt(wrap.dataset.originX||'',10); if (isNaN(_ox)) _ox = Math.round(window.innerWidth/2);
          var _oy = parseInt(wrap.dataset.originY||'',10); if (isNaN(_oy)) _oy = Math.round(window.innerHeight/2);
          wrap.style.webkitClipPath = 'circle(150% at '+_ox+'px '+_oy+'px)';
          wrap.style.clipPath = 'circle(150% at '+_ox+'px '+_oy+'px)';
        });
      });
    } catch(e){}
    document.body.style.overflow='hidden';
    wrap.addEventListener('click', function(e){ if(e.target && e.target.id==='foyerSchedulerOverlay'){ foyerOverlayClose(); }});
    document.getElementById('ovCloseBtn').addEventListener('click', function(e){ e.preventDefault(); foyerOverlayClose(); });
    // ESC key to close overlay without saving
    try {
      var escHandler = function(e){ if ((e.key === 'Escape') || (e.key === 'Esc') || (e.keyCode === 27)) { e.preventDefault(); foyerOverlayClose(); } };
      window.__ovEscHandler = escHandler;
      document.addEventListener('keydown', escHandler);
    } catch(e){}
        // Recur UI ohne Checkbox
    var boxRecur = document.getElementById('ovRecurBox');
    var weekly = document.getElementById('ovWeeklyOpts'); var monthly = document.getElementById('ovMonthlyOpts');
    var endRow = document.querySelector('.ov-end-row');
    var intervalWrap = document.getElementById('ovIntervalWrap');
    var intervalInput = document.getElementById('ovInterval');
    function getCheckedFreq(){ var r = document.querySelector('#ovRecurBox input[name="ovFreq"]:checked'); return r ? r.value : 'SINGLE'; }
    function updateFreq(){
      var f = getCheckedFreq();
      weekly.classList.toggle('ov-hidden', f!=='WEEKLY');
      monthly.classList.toggle('ov-hidden', f!=='MONTHLY');
      if (endRow) endRow.classList.toggle('ov-hidden', f==='SINGLE');
      if (intervalWrap) intervalWrap.style.opacity = (f==='SINGLE') ? '.5' : '1';
      if (intervalInput) intervalInput.disabled = (f==='SINGLE');
      setIntervalUnit(); setActiveFreqChips(); if(f==='WEEKLY'){ ensureWeeklyDefaultDay(); } updateSummary();
    }
    document.getElementById('ovRecurBox').addEventListener('change', function(e){ if (e.target && e.target.name==='ovFreq'){ updateFreq(); }});

    function setIntervalUnit(){ try { var u=document.getElementById('ovIntervalUnit'); if(!u) return; var f=getCheckedFreq(); u.textContent = (f==='WEEKLY') ? (((window.foyerSchedulerI18n&&foyerSchedulerI18n.unitWeek)||'week(s)')) : (f==='MONTHLY' ? (((window.foyerSchedulerI18n&&foyerSchedulerI18n.unitMonth)||'month(s)')) : (f==='DAILY' ? (((window.foyerSchedulerI18n&&foyerSchedulerI18n.unitDay)||'day(s)')) : '')); } catch(e){} }
    function setActiveFreqChips(){ try { var chips=document.querySelectorAll('.ov-freq-group .ov-chip'); var f=getCheckedFreq(); chips.forEach(function(lbl){ var inp=lbl.querySelector('input'); lbl.classList.toggle('is-active', inp && inp.value===f && inp.checked); }); } catch(e){} }
    function setActiveWeekdayChips(){ try { document.querySelectorAll('#ovWeeklyOpts label').forEach(function(lbl){ var inp=lbl.querySelector('input'); lbl.classList.toggle('is-active', !!(inp && inp.checked)); }); } catch(e){} }
    function ensureWeeklyDefaultDay(){ try { var any=document.querySelector('#ovWeeklyOpts .ovByDay:checked'); if(any) return; var s=document.getElementById('ovStartLocal'); if(!s||!s.value) return; var d=parseLocalDateTime(s.value); if(!d) return; var map=['SU','MO','TU','WE','TH','FR','SA']; var code=map[d.getDay()]; var n=document.querySelector('#ovWeeklyOpts .ovByDay[value="'+code+'"]'); if(n){ n.checked=true; setActiveWeekdayChips(); } } catch(e){} }
    function getEndMode(){ var r=document.querySelector('input[name="ovEndMode"]:checked'); return r? r.value : 'never'; }
    function updateEndModeUI(){ try { var m=getEndMode(); var u=document.getElementById('ovUntil'); var c=document.getElementById('ovCount'); if(u){ u.disabled = (m!=='until'); if(m!=='until'){ u.value=''; } } if(c){ c.disabled = (m!=='count'); if(m!=='count'){ c.value=''; } } } catch(e){} }
    function buildRRuleStringFromUI(){
      try{
        var f=getCheckedFreq(); if(f==='SINGLE') return '';
        var parts=['FREQ='+f];
        var interval=parseInt(document.getElementById('ovInterval').value,10)||1; if(interval>1){ parts.push('INTERVAL='+interval); }
        if(f==='WEEKLY'){
          var days=[]; document.querySelectorAll('#ovWeeklyOpts .ovByDay:checked').forEach(function(i){ days.push(i.value); });
          if(days.length){ parts.push('BYDAY='+days.join(',')); }
        }
        if(f==='MONTHLY'){
          var md=(document.getElementById('ovByMonthDay').value||'').split(',').map(function(s){return parseInt(s.trim(),10);}).filter(function(n){return n>=1 && n<=31;});
          if(md.length){ parts.push('BYMONTHDAY='+md.join(',')); }
        }
        var endMode=getEndMode();
        if(endMode==='until'){
          var u=(document.getElementById('ovUntil').value||'').trim();
          if(u){ var d=parseLocalDateTime(u); if(d){ var pad=function(n){return (n<10?'0':'')+n;}; var y=d.getUTCFullYear(),M=pad(d.getUTCMonth()+1),D=pad(d.getUTCDate()),H=pad(d.getUTCHours()),m=pad(d.getUTCMinutes()),s=pad(d.getUTCSeconds()); parts.push('UNTIL='+y+M+D+'T'+H+m+s+'Z'); } }
        } else if(endMode==='count'){
          var c=parseInt((document.getElementById('ovCount').value||'').trim(),10); if(c>0){ parts.push('COUNT='+c); }
        }
        return parts.join(';');
      } catch(e){ return ''; }
    }
    function updateRRulePreview(){
      try{ var el=document.getElementById('ovRRulePreview'); if(!el) return; var s=buildRRuleStringFromUI(); el.textContent = s ? ('RRULE: '+s) : 'RRULE: —'; } catch(e){}
    }
    function parseRRuleString(rrule){
      try{
        var out={ FREQ:'', INTERVAL:1, BYDAY:[], BYMONTHDAY:[], UNTIL:'', COUNT:null };
        if(!rrule || typeof rrule!=='string'){ return out; }
        var parts = rrule.toUpperCase().split(';');
        parts.forEach(function(p){
          var kv = p.split('='); if(kv.length<2) return; var k=kv[0].trim(); var v=kv.slice(1).join('=').trim();
          if(k==='FREQ'){ out.FREQ=v; }
          else if(k==='INTERVAL'){ var n=parseInt(v,10); out.INTERVAL = isNaN(n)?1:Math.max(1,n); }
          else if(k==='BYDAY'){ out.BYDAY = v? v.split(',').map(function(x){return x.trim();}).filter(Boolean):[]; }
          else if(k==='BYMONTHDAY'){ out.BYMONTHDAY = v? v.split(',').map(function(x){ var n=parseInt(x.trim(),10); return (n>=1&&n<=31)?n:null; }).filter(function(x){return x!==null;}):[]; }
          else if(k==='UNTIL'){ out.UNTIL = v; }
          else if(k==='COUNT'){ var c=parseInt(v,10); out.COUNT = isNaN(c)?null:Math.max(1,c); }
        });
        return out;
      } catch(e){ return { FREQ:'', INTERVAL:1, BYDAY:[], BYMONTHDAY:[], UNTIL:'', COUNT:null }; }
    }
    function parseRRuleUntilToDate(val){
      try{
        if(!val||typeof val!=='string') return null;
        var m = /^([0-9]{4})([0-9]{2})([0-9]{2})T([0-9]{2})([0-9]{2})([0-9]{2})Z$/.exec(val);
        if(m){
          var y=parseInt(m[1],10), M=parseInt(m[2],10), D=parseInt(m[3],10), h=parseInt(m[4],10), mi=parseInt(m[5],10), s=parseInt(m[6],10);
          return new Date(Date.UTC(y, M-1, D, h, mi, s));
        }
        var d = new Date(val); if(isNaN(d.getTime())) return null; return d;
      } catch(e){ return null; }
    }
    function applyRRuleToUI(rule){
      try{
        if(!rule || typeof rule!=='object') return;
        var f = String(rule.FREQ||'');
        var freqInp = document.querySelector('#ovRecurBox input[name="ovFreq"][value="'+f+'"]');
        if(freqInp){ freqInp.checked = true; }
        else { var singleInp=document.querySelector('#ovRecurBox input[name="ovFreq"][value="SINGLE"]'); if(singleInp){ singleInp.checked=true; } }
        updateFreq(); // reveals proper sections
        var iv=document.getElementById('ovInterval'); if(iv){ iv.value = String(Math.max(1, parseInt(rule.INTERVAL||1,10)||1)); }
        // Weekly BYDAY
        document.querySelectorAll('#ovWeeklyOpts .ovByDay').forEach(function(cb){ cb.checked=false; });
        if(Array.isArray(rule.BYDAY)){
          rule.BYDAY.forEach(function(code){ var cb=document.querySelector('#ovWeeklyOpts .ovByDay[value="'+code+'"]'); if(cb){ cb.checked=true; } });
          setActiveWeekdayChips();
        }
        // Monthly BYMONTHDAY
        var md=document.getElementById('ovByMonthDay'); if(md){ md.value = (Array.isArray(rule.BYMONTHDAY) && rule.BYMONTHDAY.length)? rule.BYMONTHDAY.join(','):''; }
        // End mode
        var endMode='never';
        if(rule.UNTIL){ endMode='until'; var d=parseRRuleUntilToDate(rule.UNTIL); if(d){ var u=document.getElementById('ovUntil'); if(u){ u.value = toSiteLocalString(d); } } }
        else if(rule.COUNT && parseInt(rule.COUNT,10)>0){ endMode='count'; var c=document.getElementById('ovCount'); if(c){ c.value = String(parseInt(rule.COUNT,10)); } }
        var r=document.querySelector('input[name="ovEndMode"][value="'+endMode+'"]'); if(r){ r.checked = true; }
        updateEndModeUI(); updateSummary();
      } catch(e){}
    }
    function formatTimeRange(){ try { var s=document.getElementById('ovStartLocal').value.trim(); var e=document.getElementById('ovEndLocal').value.trim(); var out=''; var st=s.split(' ')[1]||''; var et=e.split(' ')[1]||''; if(st||et){ out = (st||'..')+'–'+(et||'..'); try { var sd=parseLocalDateTime(s), ed=parseLocalDateTime(e); if(sd && ed && ed.getTime()<sd.getTime()){ out += ' (+1)'; } } catch(err){} } return out; } catch(e){ return ''; } }
    function updateSummary(){ try {
      var sum=document.getElementById('ovRecurSummary'); if(!sum) return; var f=getCheckedFreq(); if(f==='SINGLE'){ sum.textContent = (((window.foyerSchedulerI18n&&foyerSchedulerI18n.freqSingle)||'Single'))+' '+ formatTimeRange(); updateRRulePreview(); return; }
      var inter=parseInt(document.getElementById('ovInterval').value,10)||1; var range=formatTimeRange(); var parts=[];
      if(f==='DAILY'){ parts.push(inter>1? ((((window.foyerSchedulerI18n&&foyerSchedulerI18n.every)||'every'))+' '+inter+' '+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.unitDay)||'day(s)'))) : (((window.foyerSchedulerI18n&&foyerSchedulerI18n.freqDaily)||'Daily'))); }
      else if(f==='WEEKLY'){ var days=[]; document.querySelectorAll('#ovWeeklyOpts .ovByDay:checked').forEach(function(i){ days.push(i.value); }); if(days.length===0){ ensureWeeklyDefaultDay(); document.querySelectorAll('#ovWeeklyOpts .ovByDay:checked').forEach(function(i){ days.push(i.value); }); }
        parts.push((inter>1? ((((window.foyerSchedulerI18n&&foyerSchedulerI18n.every)||'every'))+' '+inter+' '+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.unitWeek)||'week(s)'))) : (((window.foyerSchedulerI18n&&foyerSchedulerI18n.freqWeekly)||'Weekly')) ) + (days.length? (' on '+days.join(', ')) : '')); }
      else if(f==='MONTHLY'){ parts.push(inter>1? ((((window.foyerSchedulerI18n&&foyerSchedulerI18n.every)||'every'))+' '+inter+' '+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.unitMonth)||'month(s)'))) : (((window.foyerSchedulerI18n&&foyerSchedulerI18n.freqMonthly)||'Monthly'))); var md=(document.getElementById('ovByMonthDay').value||'').trim(); if(md){ parts.push(' on day(s) '+md); } }
      var endMode=getEndMode(); if(endMode==='until'){ var until=(document.getElementById('ovUntil').value||'').trim(); if(until){ parts.push(' '+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.endUntil)||'Ends at'))+' '+until); } }
      else if(endMode==='count'){ var cnt=parseInt((document.getElementById('ovCount').value||'').trim(),10); if(cnt>0){ parts.push(' '+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.endCount)||'Ends after'))+' '+cnt+' '+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.termsWord)||'occurrences'))) } }
      if(range){ parts.push(range); }
      sum.textContent = parts.join(' • '); updateRRulePreview();
    } catch(e){} }

    // Events für Chips/Inputs
    document.getElementById('ovRecurBox').addEventListener('change', function(e){ if(e.target && e.target.classList && e.target.classList.contains('ovByDay')){ setActiveWeekdayChips(); updateSummary(); }});
    var iv=document.getElementById('ovInterval'); if(iv){ iv.addEventListener('input', updateSummary); }
    var md=document.getElementById('ovByMonthDay'); if(md){ md.addEventListener('input', updateSummary); }
    var st=document.getElementById('ovStartLocal'); var et=document.getElementById('ovEndLocal'); if(st){ st.addEventListener('input', function(){ ensureWeeklyDefaultDay(); updateSummary(); }); } if(et){ et.addEventListener('input', updateSummary); }
    document.querySelectorAll('input[name="ovEndMode"]').forEach(function(r){ r.addEventListener('change', function(){ updateEndModeUI(); updateSummary(); }); });
    var u=document.getElementById('ovUntil'); if(u){ u.addEventListener('input', updateSummary); }
    var c=document.getElementById('ovCount'); if(c){ c.addEventListener('input', updateSummary); }

    // Initialzustand setzen
    try { updateFreq(); setActiveWeekdayChips(); updateEndModeUI(); updateSummary(); } catch(e){}
    return wrap;
  }
  function foyerOverlayClose(){
    var w=document.getElementById('foyerSchedulerOverlay');
    if(!w){ document.body.style.overflow=''; return; }
    var ox = parseInt(w.dataset.originX||'',10); if (isNaN(ox)) ox = Math.round(window.innerWidth/2);
    var oy = parseInt(w.dataset.originY||'',10); if (isNaN(oy)) oy = Math.round(window.innerHeight/2);
    var cleaned = false;
    function cleanup(){ if(cleaned) return; cleaned=true; try{ if (window.__ovEscHandler) { document.removeEventListener('keydown', window.__ovEscHandler); window.__ovEscHandler = null; } }catch(e){} if(w && w.parentNode){ w.parentNode.removeChild(w); } document.body.style.overflow=''; }
    try {
      var __onceTransition = function(ev){ if(ev && ev.propertyName && ev.propertyName.indexOf('clip-path')===-1 && ev.propertyName.indexOf('webkit-clip-path')===-1) return; cleanup(); try{ w.removeEventListener('transitionend', __onceTransition); }catch(e){} };
      w.addEventListener('transitionend', __onceTransition);
      setTimeout(cleanup, 420);
      w.style.webkitClipPath = 'circle(0px at '+ox+'px '+oy+'px)';
      w.style.clipPath = 'circle(0px at '+ox+'px '+oy+'px)';
    } catch(e) { cleanup(); }
  }

  function foyerOverlayRenderDisplays(){
    var host = document.getElementById('ovDisplays'); if(!host) return;
    host.innerHTML='';

    function syncOvSelectAllFromItems(){
      var boxes = host.querySelectorAll('.ovDisplay');
      var all = boxes.length > 0 && Array.prototype.every.call(boxes, function(cb){ return cb.checked; });
      var selAll = document.getElementById('ovSelectAll'); if (selAll) selAll.checked = all;
      var selAllRow = document.getElementById('ovSelectAllRow');
      if (selAllRow){
        var base = selAllRow.getAttribute('data-color') || 'hsl(210, 20%, 85%)';
        if (selAll && selAll.checked){ selAllRow.style.backgroundColor = makeLightColor(base) || base; selAllRow.classList.add('is-selected'); selAllRow.setAttribute('aria-pressed','true'); }
        else { selAllRow.style.backgroundColor = ''; selAllRow.classList.remove('is-selected'); selAllRow.setAttribute('aria-pressed','false'); }
      }
    }
    function updateOverlayDisplaySelectionStyles(){
      var items = host.querySelectorAll('.ov-display-item[data-id]');
      items.forEach(function(row){
        var cb = row.querySelector('.ovDisplay');
        var base = row.getAttribute('data-color') || '';
        if (cb && cb.checked){
          var light = makeLightColor(base) || base || '#eef6ff';
          row.style.backgroundColor = light;
          row.classList.add('is-selected');
          row.setAttribute('aria-pressed','true');
        } else {
          row.style.backgroundColor = '';
          row.classList.remove('is-selected');
          row.setAttribute('aria-pressed','false');
        }
      });
      syncOvSelectAllFromItems();
    }
    // expose for external calls (e.g., after programmatic checkbox changes)
    host.__updateStyles = updateOverlayDisplaySelectionStyles;

    // Select-All row
    var selAllRow = document.createElement('div'); selAllRow.className='ov-display-item ov-select-all'; selAllRow.id='ovSelectAllRow'; selAllRow.setAttribute('data-color','hsl(210, 20%, 85%)'); selAllRow.tabIndex = 0;
    var selAll = document.createElement('input'); selAll.type='checkbox'; selAll.id='ovSelectAll';
    var selAllSw = document.createElement('span'); selAllSw.className='ov-display-swatch';
    var selAllLabel = document.createElement('span'); selAllLabel.className='foyer-display-title'; selAllLabel.textContent = (window.foyerSchedulerI18n && foyerSchedulerI18n.selectAll) || 'Select all';
    selAllRow.appendChild(selAllSw); selAllRow.appendChild(selAllLabel); selAllRow.appendChild(selAll); host.appendChild(selAllRow);

    var srcList = document.querySelectorAll('#foyerCalDisplays .foyer-display-item');
    srcList.forEach(function(item){
      var id = parseInt(item.getAttribute('data-id'),10);
      var title = String(item.querySelector('.foyer-display-title') ? item.querySelector('.foyer-display-title').textContent : 'Display #'+id);
      var checked = !!(item.querySelector('.foyerCalDisplay') && item.querySelector('.foyerCalDisplay').checked);
      var color = String(item.getAttribute('data-color')||'');
      var row = document.createElement('div'); row.className='ov-display-item'; row.setAttribute('data-id', String(id)); if(color) row.setAttribute('data-color', color); row.tabIndex = 0;
      var cb = document.createElement('input'); cb.type='checkbox'; cb.className='ovDisplay'; cb.value=String(id); cb.checked = checked;
      var sw = document.createElement('span'); sw.className='ov-display-swatch'; if(color) sw.style.background = color;
      var span = document.createElement('span'); span.className = 'foyer-display-title'; span.textContent = title;
      row.appendChild(sw); row.appendChild(span); row.appendChild(cb); host.appendChild(row);
    });

    // interactions: click row toggles checkbox; keyboard space/enter
    host.addEventListener('click', function(e){
      var row = e.target.closest('.ov-display-item');
      if (!row || !host.contains(row)) return;
      var isSelectAll = row.id === 'ovSelectAllRow';
      if (isSelectAll){ var box = row.querySelector('#ovSelectAll'); if (box){ box.checked = !box.checked; var all = host.querySelectorAll('.ovDisplay'); all.forEach(function(cb){ cb.checked = box.checked; }); updateOverlayDisplaySelectionStyles(); } return; }
      var cb = row.querySelector('.ovDisplay'); if (cb){ cb.checked = !cb.checked; updateOverlayDisplaySelectionStyles(); }
    });
    host.addEventListener('keydown', function(e){
      var row = e.target.closest('.ov-display-item');
      if (!row || !host.contains(row)) return;
      if (e.key === ' ' || e.key === 'Enter'){ e.preventDefault(); row.click(); }
    });
    host.addEventListener('change', function(e){
      var t = e.target;
      if (t && t.id === 'ovSelectAll'){
        var all = host.querySelectorAll('.ovDisplay'); all.forEach(function(cb){ cb.checked = t.checked; }); updateOverlayDisplaySelectionStyles(); return;
      }
      if (t && t.classList && t.classList.contains('ovDisplay')){ updateOverlayDisplaySelectionStyles(); }
    });

    updateOverlayDisplaySelectionStyles();
  }
  function foyerOverlayGetSelectedDisplayIds(){
    var out=[]; document.querySelectorAll('#ovDisplays .ovDisplay:checked').forEach(function(i){ var v=parseInt(i.value,10); if(!isNaN(v)) out.push(v);}); return out;
  }

  var __ovSelectedChannelId = null;
  function foyerOverlayRenderChannels(){
    var host = document.getElementById('ovChannels'); if(!host) return;
    // State für Suche/Pagination
    if (!window.__ovChanState) { window.__ovChanState = { query: '', page: 1, perPage: 12, sorted: null }; }
    var state = window.__ovChanState;

    function getSortedChannels(){
      var arr = Array.isArray(foyerCalChannels) ? foyerCalChannels.slice() : [];
      arr.sort(function(a,b){
        var fa = (a && a.favorite) ? 1 : 0; var fb = (b && b.favorite) ? 1 : 0;
        if (fa !== fb) return fb - fa; // Favoriten zuerst
        var ca = parseInt(a && a.created_ts ? a.created_ts : 0, 10);
        var cb = parseInt(b && b.created_ts ? b.created_ts : 0, 10);
        if (cb !== ca) return cb - ca; // neueste zuerst
        var ta = String(a && a.title ? a.title : '').toLowerCase();
        var tb = String(b && b.title ? b.title : '').toLowerCase();
        if (ta < tb) return -1; if (ta > tb) return 1; return 0;
      });
      return arr;
    }
    function filterChannels(chans, q){
      var s = String(q||'').trim().toLowerCase(); if (!s) return chans;
      return chans.filter(function(ch){
        var t = String(ch && ch.title ? ch.title : '').toLowerCase();
        var a = String(ch && ch.author_name ? ch.author_name : '').toLowerCase();
        return t.indexOf(s) !== -1 || a.indexOf(s) !== -1;
      });
    }
    function getPaged(arr, page, per){
      var total = arr.length; var pages = Math.max(1, Math.ceil(total/Math.max(1,per)));
      var p = Math.min(Math.max(1, page), pages);
      var start = (p-1)*per; var end = Math.min(start+per, total);
      return { items: arr.slice(start,end), page: p, pages: pages, total: total };
    }
    function updatePager(p){
      var info = document.getElementById('ovChanPageInfo'); if (info) { info.textContent = (((window.foyerSchedulerI18n&&foyerSchedulerI18n.pageWord)||'Page'))+' '+p.page+' / '+p.pages+' ('+p.total+' '+(((window.foyerSchedulerI18n&&foyerSchedulerI18n.hitsWord)||'hits'))+')'; }
      var prev = document.getElementById('ovChanPrev'); if (prev) { prev.disabled = (p.page<=1); }
      var next = document.getElementById('ovChanNext'); if (next) { next.disabled = (p.page>=p.pages); }
    }
    function renderCards(list){
      host.innerHTML = '';
      list.forEach(function(ch){
        var card = document.createElement('button'); card.type='button'; card.className='foyer-channel-card'; card.dataset.id=String(ch.id);
        var prev = document.createElement('div'); prev.className='foyer-channel-card__preview';
        var iframe = document.createElement('iframe'); iframe.src = ch.preview_url || ''; prev.appendChild(iframe);
        var meta = document.createElement('div'); meta.className='foyer-channel-card__meta';
        var title = document.createElement('div'); title.className='foyer-channel-card__title'; title.textContent = ((ch && ch.favorite) ? '★ ' : '') + (ch.title || ('Channel #'+ch.id));
        var info = document.createElement('div'); info.className='foyer-channel-card__info'; info.textContent = (ch.slides_count||0)+' Slides • '+(ch.author_name||'');
        meta.appendChild(title); meta.appendChild(info);
        card.appendChild(prev); card.appendChild(meta);
        card.addEventListener('click', function(){ __ovSelectedChannelId = ch.id; updateSelectionStyles(); });
        if (String(ch.id) === String(__ovSelectedChannelId)) { card.classList.add('is-selected'); }
        host.appendChild(card);
      });
    }
    function updateSelectionStyles(){
      var nodes = host.querySelectorAll('.foyer-channel-card');
      nodes.forEach(function(n){ n.classList.toggle('is-selected', String(n.dataset.id)===String(__ovSelectedChannelId)); });
    }
    function doRender(){
      if (!state.sorted) { state.sorted = getSortedChannels(); }
      var filtered = filterChannels(state.sorted, state.query);
      var paged = getPaged(filtered, state.page, state.perPage);
      updatePager(paged);
      renderCards(paged.items);
    }
    // Toolbar-Events
    (function bindToolbar(){
      var search = document.getElementById('ovChanSearch');
      var perSel = document.getElementById('ovChanPerPage');
      var prev = document.getElementById('ovChanPrev');
      var next = document.getElementById('ovChanNext');
      if (search){
        search.value = state.query || '';
        var timer = null;
        search.addEventListener('input', function(){ clearTimeout(timer); var self=this; timer=setTimeout(function(){ state.query = String(self.value||''); state.page = 1; doRender(); }, 250); });
      }
      if (perSel){ perSel.value = String(state.perPage||12); perSel.addEventListener('change', function(){ var v=parseInt(this.value,10)||12; state.perPage = v; state.page = 1; doRender(); }); }
      if (prev){ prev.addEventListener('click', function(){ state.page = Math.max(1, (state.page||1)-1 ); doRender(); }); }
      if (next){ next.addEventListener('click', function(){ state.page = (state.page||1)+1; doRender(); }); }
    })();

    doRender();
  }

  function foyerOverlayFillDefaultsForCreate(startDate, endDateOpt){
    var startLocal = toSiteLocalString(startDate);
    var endLocal = toSiteLocalString(endDateOpt ? endDateOpt : new Date(startDate.getTime()+60*60*1000));
    var s = document.getElementById('ovStartLocal'); var e = document.getElementById('ovEndLocal'); if(s) s.value=startLocal; if(e) e.value=endLocal;
  }

  function foyerOverlayBindSaveCreate(){
    var save = document.getElementById('ovSave'); if(!save) return;
    save.addEventListener('click', function(e){ e.preventDefault();
      var displays = foyerOverlayGetSelectedDisplayIds(); if(!displays.length){ alert((window.foyerSchedulerI18n && foyerSchedulerI18n.selectDisplay) || 'Please select at least one display.'); return; }
      var ch = __ovSelectedChannelId; if(!ch){ alert((window.foyerSchedulerI18n && foyerSchedulerI18n.selectChannel) || 'Please select a channel.'); return; }
      var data = new FormData(); data.append('action','foyer_schedules_create_event'); data.append('nonce', nonce); data.append('channel_id', String(ch)); data.append('tz', siteTz);
      displays.forEach(function(id){ data.append('display_ids[]', String(id)); });
      var fSel = (function(){ var r=document.querySelector('#ovRecurBox input[name="ovFreq"]:checked'); return r? r.value : 'SINGLE'; })();
      if (fSel && fSel !== 'SINGLE'){
        data.append('mode','recur');
        var sVal = document.getElementById('ovStartLocal').value.trim();
        var eVal = document.getElementById('ovEndLocal').value.trim();
        if(!sVal){ alert((window.foyerSchedulerI18n && foyerSchedulerI18n.startRequired) || 'Start is required.'); return; }
        data.append('dtstart_local', sVal);
        var durSec = 3600;
        try {
          var sd = parseLocalDateTime(sVal);
          var ed = parseLocalDateTime(eVal);
          if (sd && ed) {
            var diff = Math.round((ed.getTime() - sd.getTime())/1000);
            if (diff >= 60) { durSec = diff; }
          }
        } catch(e){}
        data.append('duration', String(durSec));
        data.append('foyer_rrule_freq', fSel);
        var interval = parseInt(document.getElementById('ovInterval').value,10)||1; data.append('foyer_rrule_interval', String(Math.max(1,interval)));
        if (fSel==='WEEKLY'){
          document.querySelectorAll('#ovWeeklyOpts .ovByDay:checked').forEach(function(i){ data.append('foyer_rrule_byday[]', i.value); });
        }
        if (fSel==='MONTHLY'){
          var md = document.getElementById('ovByMonthDay').value.trim(); if(md) data.append('foyer_rrule_bymonthday', md);
        }
        var endMode = (document.querySelector('input[name="ovEndMode"]:checked')||{}).value || 'never';
        if (endMode === 'until') {
          var until = (document.getElementById('ovUntil').value||'').trim(); if(until){ data.append('foyer_rrule_until', until); }
        } else if (endMode === 'count') {
          var count = parseInt((document.getElementById('ovCount').value||'').trim(),10); if(count>0){ data.append('foyer_rrule_count', String(count)); }
        }
      } else {
        var s = document.getElementById('ovStartLocal').value.trim(); var en = document.getElementById('ovEndLocal').value.trim();
        if(!s){ alert((window.foyerSchedulerI18n && foyerSchedulerI18n.startRequired) || 'Start is required.'); return; }
        data.append('start_local', s); if(en){ data.append('end_local', en); }
      }
      fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:data })
        .then(function(r){ return r.json(); })
        .then(function(resp){ if(!resp || !resp.success){ throw new Error((resp && resp.data && resp.data.message) || 'Save failed'); }
          try {
            // Ensure sidebar filter includes the displays used for this new schedule
            (displays||[]).forEach(function(id){
              var cb = document.querySelector('#foyerCalDisplays .foyerCalDisplay[value="'+id+'"]');
              if (cb) { cb.checked = true; }
            });
            if (typeof updateDisplaySelectionStyles === 'function') { updateDisplaySelectionStyles(); }
          } catch(e){}
          foyerOverlayClose();
          try{ scheduleRefetch('create'); }catch(e){}
        })
        .catch(function(err){ alert(err && err.message ? err.message : String(err)); });
    });
  }

  function foyerOpenOverlayCreate(startDate, endDateOpt){
    var wrap = foyerOverlayCreateStructure(getLastCalPointer(), (window.foyerSchedulerI18n && foyerSchedulerI18n.createTitle) || 'Create new Schedule');
    foyerOverlayRenderDisplays();
    foyerOverlayRenderChannels();
    foyerOverlayFillDefaultsForCreate(startDate, endDateOpt);
    try { var s=document.getElementById('ovStartLocal'); if(s){ s.dispatchEvent(new Event('input', { bubbles:true })); } } catch(e){}
    foyerOverlayBindSaveCreate();
    return wrap;
  }

  function foyerOpenOverlayEdit(eventObj){
    // For now, reuse single-occurrence edit inside overlay; series editing remains basic (apply_to radios)
    var wrap = foyerOverlayCreateStructure(getLastCalPointer(), (window.foyerSchedulerI18n && foyerSchedulerI18n.editTitle) || 'Edit Schedule');
    var evtMeta = (eventObj && eventObj.extendedProps) ? eventObj.extendedProps : {};

    // Preselect channel before rendering grid so the correct card is highlighted
    try { __ovSelectedChannelId = evtMeta.channel_id ? evtMeta.channel_id : null; } catch(e) { __ovSelectedChannelId = null; }

    // Render displays and preselect those that belong to this schedule (evtMeta.display_ids)
    foyerOverlayRenderDisplays();
    try {
      var wanted = Array.isArray(evtMeta.display_ids) ? evtMeta.display_ids.map(function(x){ return parseInt(x,10); }) : [];
      var set = new Set(wanted);
      document.querySelectorAll('#ovDisplays .ovDisplay').forEach(function(cb){
        var v = parseInt(cb.value,10);
        cb.checked = set.has(v);
      });
      var ovHost = document.getElementById('ovDisplays'); if (ovHost && typeof ovHost.__updateStyles === 'function') { ovHost.__updateStyles(); }
    } catch(e){}

    // Render channels with the preselected channel highlighted
    foyerOverlayRenderChannels();

    // Fill singles by default
    var sLocal = toSiteLocalString(eventObj.start); var eLocal = toSiteLocalString(eventObj.end);
    var s = document.getElementById('ovStartLocal'); var e = document.getElementById('ovEndLocal'); if(s) s.value=sLocal; if(e) e.value=eLocal;
    try { if(s){ s.dispatchEvent(new Event('input', { bubbles:true })); } } catch(err){}
    // Pre-mark as recurring while loading, if event source indicates recurrence
    try {
      if (evtMeta && evtMeta.source && String(evtMeta.source).toUpperCase() !== 'SINGLE') {
        var tmp=document.querySelector('#ovRecurBox input[name="ovFreq"][value="DAILY"]');
        if(tmp){ tmp.checked = true; updateFreq(); updateSummary(); }
      }
    } catch(e){}
    // Load schedule meta to populate recurrence UI (RRULE) in edit
    try {
      var pid = parseInt(evtMeta.schedule_post_id,10);
      if(pid>0){
        var fd=new FormData(); fd.append('action','foyer_schedules_get_schedule'); fd.append('nonce', nonce); fd.append('post_id', String(pid));
        fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: fd })
          .then(function(r){ return r.json(); })
          .then(function(resp){ try {
            if(resp && resp.success && resp.data && resp.data.meta){
              var meta = resp.data.meta || {}; var rr = meta.rrule || '';
              if(rr){ var rule=parseRRuleString(rr); applyRRuleToUI(rule); }
              else { var singleInp=document.querySelector('#ovRecurBox input[name="ovFreq"][value="SINGLE"]'); if(singleInp){ singleInp.checked=true; } updateFreq(); updateSummary(); }
            }
          } catch(e){} })
          .catch(function(e){});
      }
    } catch(e){}
    // Force non-recur UI for edit for now
    // (no recur toggle in this UI; nothing to enforce)
    // Bind Save using update endpoint
    var save = document.getElementById('ovSave'); if(save){
      save.addEventListener('click', function(ev){ ev.preventDefault();
        var displays = foyerOverlayGetSelectedDisplayIds();
        if (!displays.length){ alert((window.foyerSchedulerI18n && foyerSchedulerI18n.selectDisplay) || 'Please select at least one display.'); return; }
        var ch = __ovSelectedChannelId || '';
        var data = new FormData(); data.append('action','foyer_schedules_update_event'); data.append('nonce', nonce);
        data.append('schedule_post_id', String(evtMeta.schedule_post_id||'')); data.append('occ_id', String(evtMeta.occ_id||''));
        data.append('new_start_local', document.getElementById('ovStartLocal').value.trim()); data.append('new_end_local', document.getElementById('ovEndLocal').value.trim());
        data.append('apply_to', (evtMeta.source && evtMeta.source!=='SINGLE') ? 'occurrence' : 'occurrence'); if(ch){ data.append('channel_id', String(ch)); }
        displays.forEach(function(id){ data.append('display_ids[]', String(id)); });
        fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:data })
          .then(function(r){ return r.json(); })
          .then(function(resp){ if(!resp || !resp.success){ throw new Error((resp && resp.data && resp.data.message) || 'Update failed'); } foyerOverlayClose(); try{ scheduleRefetch('update'); }catch(e){} })
          .catch(function(err){ alert(err && err.message ? err.message : String(err)); });
      });
    }
    return wrap;
  }

  // Initialization
  ensureCalendar();
  updateDisplaySelectionStyles();
  scheduleRefetch('init');
})();
