(() => {
  const baseUrl = new URL('vendor/pdfjs/', document.currentScript.src);
  const pages = document.getElementById('touristProfilePdfPages');
  const error = document.getElementById('touristProfilePdfError');
  const body = pages.closest('.tourist-profile-pdf-body');
  let libraryPromise;
  let loadingTask;
  let renderTask;
  let generation = 0;
  let currentUrl = '';

  function clear() {
    generation++;
    renderTask?.cancel();
    renderTask = null;
    if (loadingTask) loadingTask.destroy().catch(() => {});
    loadingTask = null;
    pages.querySelectorAll('canvas').forEach(canvas => { canvas.width = canvas.height = 0; });
    pages.replaceChildren();
    error.hidden = true;
    body.classList.remove('is-loading');
  }

  async function open(url) {
    clear();
    currentUrl = url;
    const request = generation;
    body.classList.add('is-loading');
    body.scrollTop = 0;
    document.getElementById('touristProfilePdfOpen').href = url;
    try {
      libraryPromise ??= import(new URL('pdf.min.mjs', baseUrl).href).catch(cause => {
        libraryPromise = null;
        throw cause;
      });
      const pdfjs = await libraryPromise;
      if (request !== generation) return;
      pdfjs.GlobalWorkerOptions.workerSrc = new URL('pdf.worker.min.mjs', baseUrl).href;
      loadingTask = pdfjs.getDocument({
        url, withCredentials: true, isEvalSupported: false, useWasm: false,
        standardFontDataUrl: new URL('standard_fonts/', baseUrl).href
      });
      const pdf = await loadingTask.promise;
      if (request !== generation) return;
      for (let number = 1; number <= pdf.numPages; number++) {
        const page = await pdf.getPage(number);
        if (request !== generation) return;
        const natural = page.getViewport({ scale: 1 });
        const width = Math.max(240, Math.min(pages.clientWidth || body.clientWidth - 20, 1100));
        const scale = width / natural.width;
        const viewport = page.getViewport({ scale });
        // Bound canvas memory on phones while preserving readable text.
        const density = Math.min(window.devicePixelRatio || 1, 2, Math.sqrt(3000000 / (viewport.width * viewport.height)));
        const sheet = document.createElement('figure');
        sheet.className = 'tourist-pdf-page';
        const canvas = document.createElement('canvas');
        canvas.width = Math.ceil(viewport.width * density);
        canvas.height = Math.ceil(viewport.height * density);
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', `Passenger information, page ${number} of ${pdf.numPages}`);
        const caption = document.createElement('figcaption');
        caption.textContent = `Page ${number} of ${pdf.numPages}`;
        sheet.append(canvas, caption);
        pages.append(sheet);
        renderTask = page.render({
          canvasContext: canvas.getContext('2d'), viewport,
          transform: density === 1 ? null : [density, 0, 0, density, 0, 0]
        });
        await renderTask.promise;
        renderTask = null;
        if (request !== generation) return;
        body.classList.remove('is-loading');
        page.cleanup();
      }
    } catch (cause) {
      if (request !== generation) return;
      body.classList.remove('is-loading');
      error.hidden = false;
      console.error('Passenger PDF preview failed:', cause);
    }
  }

  document.getElementById('touristProfilePdfRetry').addEventListener('click', () => open(currentUrl));
  window.TouristPdfPreview = { open, close: clear };
})();
