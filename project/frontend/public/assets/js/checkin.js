(function () {
  'use strict';
  var video = document.getElementById('scannerVideo');
  var statusBox = document.getElementById('scannerStatus');
  var startButton = document.getElementById('scannerStart');
  var stopButton = document.getElementById('scannerStop');
  var manualForm = document.getElementById('manualCheckin');
  var queueForm = document.getElementById('queueForm');
  var queuePayload = document.getElementById('queuePayload');
  var queueCount = document.getElementById('queueCount');
  var queueList = document.getElementById('queueList');
  var STORAGE_KEY = 'erooydad-checkin-queue';
  var stream = null;
  var timer = null;
  var detector = null;

  function toPersian(value) {
    return String(value).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
  }

  function readQueue() {
    try {
      var raw = window.localStorage.getItem(STORAGE_KEY);
      var parsed = raw ? JSON.parse(raw) : [];
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) { return []; }
  }

  function writeQueue(items) {
    try { window.localStorage.setItem(STORAGE_KEY, JSON.stringify(items)); } catch (e) {}
    renderQueue();
  }

  function renderQueue() {
    var items = readQueue();
    if (queueCount) queueCount.textContent = toPersian(items.length);
    if (queueList) {
      queueList.innerHTML = '';
      items.forEach(function (item) {
        var li = document.createElement('li');
        li.textContent = (item.at || '') + ' — ' + String(item.token || '').slice(0, 28);
        queueList.appendChild(li);
      });
    }
    if (queuePayload) queuePayload.value = JSON.stringify(items);
  }

  function submitToken(token) {
    if (!manualForm) return;
    var input = manualForm.querySelector('input[name="ticket_token"]');
    if (!input) return;
    input.value = token;
    if (navigator.onLine === false) {
      var items = readQueue();
      items.push({ token: token, ref: 'offline-' + Date.now(), at: new Date().toLocaleString('fa-IR') });
      writeQueue(items);
      setStatus('شبکه قطع است؛ اسکن در صف آفلاین ذخیره شد.');
      return;
    }
    manualForm.submit();
  }

  function setStatus(text) {
    if (statusBox) statusBox.textContent = text;
  }

  function stopScanner() {
    if (timer) { window.clearInterval(timer); timer = null; }
    if (stream) {
      stream.getTracks().forEach(function (track) { track.stop(); });
      stream = null;
    }
    if (video) video.srcObject = null;
    if (startButton) startButton.disabled = false;
    if (stopButton) stopButton.disabled = true;
  }

  function startScanner() {
    if (!video) return;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setStatus('دوربین در این مرورگر یا روی این دستگاه در دسترس نیست؛ از ورود دستی استفاده کنید.');
      return;
    }
    if (!('BarcodeDetector' in window)) {
      setStatus('این مرورگر از اسکن QR پشتیبانی نمی‌کند؛ از ورود دستی استفاده کنید.');
      return;
    }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
      .then(function (mediaStream) {
        stream = mediaStream;
        video.srcObject = mediaStream;
        video.play();
        detector = new window.BarcodeDetector({ formats: ['qr_code'] });
        if (startButton) startButton.disabled = true;
        if (stopButton) stopButton.disabled = false;
        setStatus('دوربین فعال است؛ کد QR را در کادر قرار دهید.');
        timer = window.setInterval(function () {
          if (!detector || video.readyState < 2) return;
          detector.detect(video).then(function (codes) {
            if (!codes || !codes.length) return;
            var value = codes[0].rawValue || '';
            stopScanner();
            setStatus('کد شناسایی شد.');
            submitToken(value);
          }).catch(function () {});
        }, 700);
      })
      .catch(function () {
        setStatus('دسترسی به دوربین ممکن نشد؛ از ورود دستی استفاده کنید.');
      });
  }

  if (startButton) startButton.addEventListener('click', startScanner);
  if (stopButton) stopButton.addEventListener('click', stopScanner);

  if (queueForm) {
    queueForm.addEventListener('submit', function (event) {
      var items = readQueue();
      if (!items.length) { event.preventDefault(); return; }
      event.preventDefault();
      var payload = new FormData();
      var csrf = queueForm.querySelector('input[name="_csrf"]');
      if (csrf) payload.append('_csrf', csrf.value);
      payload.append('queue', JSON.stringify(items));
      // آدرس از action فرم گرفته می‌شود تا نصب در زیرپوشه هم درست کار کند.
      window.fetch(queueForm.action, {
        method: 'POST',
        body: payload,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      })
        .then(function (response) {
          if (!response.ok) throw new Error('sync failed');
          return response.json();
        })
        .then(function (data) {
          // تنها ردیف‌هایی که سرور پردازش کرد از صف حذف می‌شوند؛
          // ردیف‌های پردازش‌نشده (خطای شبکه/دسترسی) برای تلاش دوباره می‌مانند.
          var processed = {};
          var results = (data && data.results) || [];
          results.forEach(function (item) {
            if (item && typeof item.ref === 'string') processed[item.ref] = item.ok ? 'ok' : 'rejected';
          });
          var keep = items.filter(function (item) { return processed[String(item.ref)] === undefined; });
          writeQueue(keep);
          var synced = (data && data.synced) || 0;
          var rejected = 0;
          Object.keys(processed).forEach(function (key) { if (processed[key] === 'rejected') rejected += 1; });
          setStatus('همگام‌سازی: ' + toPersian(synced) + ' ورود ثبت شد'
            + (rejected ? ' و ' + toPersian(rejected) + ' مورد رد شد.' : '.'));
          if (synced > 0) {
            window.setTimeout(function () { window.location.reload(); }, 1500);
          }
        })
        .catch(function () {
          setStatus('همگام‌سازی انجام نشد؛ صف آفلاین دست‌نخورده باقی ماند.');
        });
    });
  }

  window.addEventListener('online', function () { setStatus('شبکه برقرار شد؛ می‌توانید صف را همگام کنید.'); });
  window.addEventListener('offline', function () { setStatus('شبکه قطع شد؛ اسکن‌ها در صف آفلاین ذخیره می‌شوند.'); });

  renderQueue();
})();
