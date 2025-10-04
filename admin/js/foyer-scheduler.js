(function(){
  if (!window.foyerSchedulerData) { return; }
  var ajaxurl = String(foyerSchedulerData.ajaxurl||'');
  var nonce = String(foyerSchedulerData.nonce||'');
  var siteTz = String(foyerSchedulerData.siteTz||'UTC');
  var foyerCalChannels = Array.isArray(foyerSchedulerData.channels) ? foyerSchedulerData.channels : [];

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
    if (!displays.length){ alert('Bitte mindestens ein Display auswählen.'); return; }
    var base = info && info.date ? info.date : new Date();
    var startLocal = toSiteLocalString(base);
    var endLocal = toSiteLocalString(new Date(base.getTime()+60*60*1000));

    var box = document.createElement('div');
    var h3 = document.createElement('h3'); h3.textContent = 'Neuen geplanten Channel erstellen'; box.appendChild(h3);
    var select = buildChannelSelect('');
    var startInput = document.createElement('input'); startInput.type='text'; startInput.id='foyerCalStartLocal'; startInput.value=startLocal;
    var endInput   = document.createElement('input'); endInput.type='text'; endInput.id='foyerCalEndLocal'; endInput.value=endLocal;
    box.appendChild(buildFormRow('Channel:', select));
    box.appendChild(buildFormRow('Start (Site-TZ):', startInput));
    box.appendChild(buildFormRow('Ende (Site-TZ):', endInput));
    var btnRow = document.createElement('div'); btnRow.style.display='flex'; btnRow.style.gap='8px'; btnRow.style.justifyContent='flex-end';
    var cancelBtn=document.createElement('button'); cancelBtn.className='button'; cancelBtn.id='foyerCalCancel'; cancelBtn.textContent='Cancel';
    var saveBtn=document.createElement('button'); saveBtn.className='button button-primary'; saveBtn.id='foyerCalSave'; saveBtn.textContent='Save';
    btnRow.appendChild(cancelBtn); btnRow.appendChild(saveBtn); box.appendChild(btnRow);
    var modal = openModal(box);

    modal.addEventListener('click', function(e){ if(e.target && e.target.id==='foyerCalCancel'){ e.preventDefault(); closeModal(); }});
    modal.addEventListener('click', function(e){ if(e.target && e.target.id==='foyerCalSave'){ e.preventDefault();
      var ch = select.value;
      var s  = startInput.value;
      var en = endInput.value;
      if (!ch){ alert('Bitte Channel auswählen.'); return; }
      var data = new FormData();
      data.append('action','foyer_schedules_create_event');
      data.append('nonce', nonce);
      data.append('channel_id', ch);
      data.append('start_local', s);
      data.append('end_local', en);
      data.append('tz', siteTz);
      displays.forEach(function(id){ data.append('display_ids[]', String(id)); });
      fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:data })
        .then(function(r){ return r.json(); })
        .then(function(resp){ if(!resp || !resp.success){ throw new Error((resp && resp.data && resp.data.message)||'Save failed'); } closeModal(); refetch(); })
        .catch(function(err){ alert(err && err.message ? err.message : String(err)); });
    }});
  }

  function handleSelect(info){
    var displays = getSelectedDisplays();
    if (!displays.length){ alert('Bitte mindestens ein Display auswählen.'); return; }
    var startLocal = toSiteLocalString(info.start);
    var endLocal = toSiteLocalString(info.end || new Date(info.start.getTime()+60*60*1000));

    var box = document.createElement('div');
    var h3 = document.createElement('h3'); h3.textContent = 'Neuen geplanten Channel erstellen'; box.appendChild(h3);
    var select = buildChannelSelect('');
    var startInput = document.createElement('input'); startInput.type='text'; startInput.id='foyerCalStartLocal'; startInput.value=startLocal;
    var endInput   = document.createElement('input'); endInput.type='text'; endInput.id='foyerCalEndLocal'; endInput.value=endLocal;
    box.appendChild(buildFormRow('Channel:', select));
    box.appendChild(buildFormRow('Start (Site-TZ):', startInput));
    box.appendChild(buildFormRow('Ende (Site-TZ):', endInput));
    var btnRow = document.createElement('div'); btnRow.style.display='flex'; btnRow.style.gap='8px'; btnRow.style.justifyContent='flex-end';
    var cancelBtn=document.createElement('button'); cancelBtn.className='button'; cancelBtn.id='foyerCalCancel'; cancelBtn.textContent='Cancel';
    var saveBtn=document.createElement('button'); saveBtn.className='button button-primary'; saveBtn.id='foyerCalSave'; saveBtn.textContent='Save';
    btnRow.appendChild(cancelBtn); btnRow.appendChild(saveBtn); box.appendChild(btnRow);
    var modal = openModal(box);

    modal.addEventListener('click', function(e){ if(e.target && e.target.id==='foyerCalCancel'){ e.preventDefault(); closeModal(); }});
    modal.addEventListener('click', function(e){ if(e.target && e.target.id==='foyerCalSave'){ e.preventDefault();
      var ch = select.value;
      var s  = startInput.value;
      var en = endInput.value;
      if (!ch){ alert('Bitte Channel auswählen.'); return; }
      var data = new FormData();
      data.append('action','foyer_schedules_create_event');
      data.append('nonce', nonce);
      data.append('channel_id', ch);
      data.append('start_local', s);
      data.append('end_local', en);
      data.append('tz', siteTz);
      displays.forEach(function(id){ data.append('display_ids[]', String(id)); });
      fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:data })
        .then(function(r){ return r.json(); })
        .then(function(resp){ if(!resp || !resp.success){ throw new Error((resp && resp.data && resp.data.message)||'Save failed'); } closeModal(); refetch(); })
        .catch(function(err){ alert(err && err.message ? err.message : String(err)); });
    }});
  }

  function handleEventClick(info){
    var ev = info.event; var xp = ev && ev.extendedProps ? ev.extendedProps : {};
    var title = ev && ev.text ? ev.text : (ev && ev.title ? ev.title : '');
    var sLocal = toSiteLocalString(ev.start); var eLocal = toSiteLocalString(ev.end);

    var box = document.createElement('div');
    var h3 = document.createElement('h3'); h3.textContent = 'Geplanten Channel bearbeiten'; box.appendChild(h3);
    var pTitle = document.createElement('p'); var strong=document.createElement('strong'); strong.textContent = String(title||''); pTitle.appendChild(strong); box.appendChild(pTitle);
    var select = buildChannelSelect(xp.channel_id||'');
    var startInput = document.createElement('input'); startInput.type='text'; startInput.id='foyerCalStartLocal'; startInput.value=sLocal;
    var endInput   = document.createElement('input'); endInput.type='text'; endInput.id='foyerCalEndLocal'; endInput.value=eLocal;
    box.appendChild(buildFormRow('Channel:', select));
    box.appendChild(buildFormRow('Start (Site-TZ):', startInput));
    box.appendChild(buildFormRow('Ende (Site-TZ):', endInput));
    if (xp.source && xp.source!=='SINGLE'){
      var pRad = document.createElement('p');
      var r1 = document.createElement('input'); r1.type='radio'; r1.name='foyer_apply_to'; r1.value='occurrence'; r1.checked=true; var l1=document.createElement('label'); l1.textContent=' Nur diesen Termin'; l1.prepend(r1);
      var r2 = document.createElement('input'); r2.type='radio'; r2.name='foyer_apply_to'; r2.value='series'; var l2=document.createElement('label'); l2.style.marginLeft='12px'; l2.textContent=' Serie'; l2.prepend(r2);
      pRad.appendChild(l1); pRad.appendChild(l2); box.appendChild(pRad);
    }
    var controlsRow = document.createElement('div'); controlsRow.style.display='flex'; controlsRow.style.gap='8px'; controlsRow.style.justifyContent='space-between';
    var left = document.createElement('div'); var right=document.createElement('div');
    if (xp.source && xp.source!=='SINGLE'){
      var delOcc = document.createElement('button'); delOcc.className='button'; delOcc.id='foyerCalDeleteOcc'; delOcc.textContent='Nur diesen Termin löschen'; left.appendChild(delOcc);
    }
    var cancelBtn=document.createElement('button'); cancelBtn.className='button'; cancelBtn.id='foyerCalCancel'; cancelBtn.textContent='Cancel';
    var saveBtn=document.createElement('button'); saveBtn.className='button button-primary'; saveBtn.id='foyerCalSave'; saveBtn.textContent='Save';
    var delBtn=document.createElement('button'); delBtn.className='button button-secondary'; delBtn.id='foyerCalDelete'; delBtn.textContent='Delete schedule';
    right.appendChild(cancelBtn); right.appendChild(saveBtn); right.appendChild(delBtn);
    controlsRow.appendChild(left); controlsRow.appendChild(right); box.appendChild(controlsRow);

    var modal = openModal(box);
    modal.addEventListener('click', function(e){ if(e.target && e.target.id==='foyerCalCancel'){ e.preventDefault(); closeModal(); }});
    modal.addEventListener('click', function(e){ if(e.target && e.target.id==='foyerCalSave'){ e.preventDefault();
      var ch = select.value || '';
      var s  = startInput.value;
      var en = endInput.value;
      var applyTo = (document.querySelector('input[name="foyer_apply_to"]:checked')||{}).value || 'occurrence';
      var data = new FormData();
      data.append('action','foyer_schedules_update_event');
      data.append('nonce', nonce);
      data.append('schedule_post_id', xp.schedule_post_id);
      // no display_id needed; updates apply to all displays of this schedule
      data.append('occ_id', xp.occ_id);
      data.append('new_start_local', s);
      data.append('new_end_local', en);
      data.append('apply_to', applyTo);
      if (ch) { data.append('channel_id', ch); }
      fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:data })
        .then(function(r){ return r.json(); })
        .then(function(resp){ if(!resp || !resp.success){ throw new Error((resp && resp.data && resp.data.message)||'Save failed'); } closeModal(); refetch(); })
        .catch(function(err){ alert(err && err.message ? err.message : String(err)); });
    }});
    modal.addEventListener('click', function(e){ if(e.target && e.target.id==='foyerCalDelete'){ e.preventDefault();
      if(!confirm('Gesamten Schedule löschen? Dies betrifft alle Displays.')) return;
      var data = new FormData();
      data.append('action','foyer_schedules_delete_event');
      data.append('nonce', nonce);
      data.append('schedule_post_id', xp.schedule_post_id);
      data.append('delete_mode','all');
      fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:data })
        .then(function(r){ return r.json(); })
        .then(function(resp){ if(!resp || !resp.success){ throw new Error((resp && resp.data && resp.data.message)||'Delete failed'); } closeModal(); refetch(); })
        .catch(function(err){ alert(err && err.message ? err.message : String(err)); });
    }});
    modal.addEventListener('click', function(e){ if(e.target && e.target.id==='foyerCalDeleteOcc'){ e.preventDefault();
      if(!confirm('Diesen einzelnen Termin (Serie) löschen? Dies betrifft alle Displays dieses Schedules.')) return;
      var data = new FormData();
      data.append('action','foyer_schedules_delete_event');
      data.append('nonce', nonce);
      data.append('schedule_post_id', xp.schedule_post_id);
      data.append('occ_id', xp.occ_id);
      data.append('delete_mode','occurrence');
      fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:data })
        .then(function(r){ return r.json(); })
        .then(function(resp){ if(!resp || !resp.success){ throw new Error((resp && resp.data && resp.data.message)||'Delete failed'); } closeModal(); refetch(); })
        .catch(function(err){ alert(err && err.message ? err.message : String(err)); });
    }});
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

  ensureCalendar();
  updateDisplaySelectionStyles();
  scheduleRefetch('init');
})();
