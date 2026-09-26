(() => {
  'use strict';

  const ABSOLUTE_MAX_BYTES = 40 * 1024 * 1024;
  const ABSOLUTE_MAX_PIXELS = 40_000_000;
  const ABSOLUTE_MAX_EDGE = 12_000;
  const PREFERRED_BYTES = 4 * 1024 * 1024;
  const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

  function ensureBusyButtonStyles() {
    if (document.getElementById('itour-image-button-styles')) return;
    const style = document.createElement('style');
    style.id = 'itour-image-button-styles';
    style.textContent = `
      .itour-image-button-busy{display:inline-flex!important;align-items:center;justify-content:center;gap:8px;cursor:wait!important}
      .itour-image-button-spinner{width:15px;height:15px;flex:0 0 15px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:itourImageButtonSpin .7s linear infinite}
      @keyframes itourImageButtonSpin{to{transform:rotate(360deg)}}
      @media (prefers-reduced-motion:reduce){.itour-image-button-spinner{animation-duration:1.4s}}
    `;
    document.head.appendChild(style);
  }

  function setButtonBusy(button, busy, label = 'Processing image...', disabledWhenIdle = false) {
    if (!(button instanceof HTMLElement)) return;
    if (!button.dataset.itourImageIdleLabel) {
      button.dataset.itourImageIdleLabel = button.textContent.trim() || 'Done';
    }
    if (!busy) {
      button.classList.remove('itour-image-button-busy');
      button.textContent = button.dataset.itourImageIdleLabel;
      button.disabled = disabledWhenIdle;
      button.removeAttribute('aria-busy');
      return;
    }
    ensureBusyButtonStyles();
    button.disabled = true;
    button.classList.add('itour-image-button-busy');
    button.setAttribute('aria-busy', 'true');
    button.replaceChildren();
    const spinner = document.createElement('span');
    spinner.className = 'itour-image-button-spinner';
    spinner.setAttribute('aria-hidden', 'true');
    const text = document.createElement('span');
    text.textContent = label;
    button.append(spinner, text);
  }

  function error(message, code) {
    const exception = new Error(message);
    exception.code = code;
    return exception;
  }

  function validateFile(file) {
    if (!(file instanceof File) || file.size < 1) throw error('This file is empty or unavailable.', 'empty');
    if (!allowedTypes.includes(file.type)) throw error('This file is not a valid JPG, PNG, or WebP image.', 'type');
    if (file.size > ABSOLUTE_MAX_BYTES) throw error('This image exceeds the safe 40 MB processing limit. Please use a smaller photo.', 'size');
  }

  async function decode(file) {
    validateFile(file);
    let bitmap;
    try {
      if ('createImageBitmap' in window) bitmap = await createImageBitmap(file, {imageOrientation: 'from-image'});
    } catch (_error) {}
    if (bitmap) return {source: bitmap, width: bitmap.width, height: bitmap.height, close: () => bitmap.close()};

    const url = URL.createObjectURL(file);
    try {
      const image = new Image();
      image.decoding = 'async';
      await new Promise((resolve, reject) => {
        image.onload = resolve;
        image.onerror = () => reject(error('The image could not be processed. Please try another photo.', 'decode'));
        image.src = url;
      });
      return {source: image, width: image.naturalWidth, height: image.naturalHeight, close: () => {}};
    } finally {
      URL.revokeObjectURL(url);
    }
  }

  function validateDimensions(width, height) {
    if (width < 1 || height < 1 || width > ABSOLUTE_MAX_EDGE || height > ABSOLUTE_MAX_EDGE || width * height > ABSOLUTE_MAX_PIXELS) {
      throw error('This image exceeds the safe processing dimensions. Please use a smaller photo.', 'dimensions');
    }
  }

  function canvasBlob(canvas, type, quality) {
    return new Promise((resolve, reject) => canvas.toBlob(blob => {
      if (!blob || blob.size < 1) reject(error('The image could not be processed. Please try another photo.', 'encode'));
      else resolve(blob);
    }, type, quality));
  }

  async function encodeAdaptive(canvas, type) {
    if (type === 'image/png') return canvasBlob(canvas, type);
    let blob = await canvasBlob(canvas, type, 0.90);
    if (blob.size > PREFERRED_BYTES) blob = await canvasBlob(canvas, type, 0.86);
    if (blob.size > PREFERRED_BYTES * 1.5) blob = await canvasBlob(canvas, type, 0.82);
    return blob;
  }

  async function optimizeSource(file, maxLongEdge = 4096) {
    const decoded = await decode(file);
    try {
      validateDimensions(decoded.width, decoded.height);
      const scale = Math.min(1, maxLongEdge / Math.max(decoded.width, decoded.height));
      if (scale === 1 && file.size <= PREFERRED_BYTES) return file;
      const width = Math.max(1, Math.round(decoded.width * scale));
      const height = Math.max(1, Math.round(decoded.height * scale));
      const canvas = document.createElement('canvas');
      canvas.width = width; canvas.height = height;
      const context = canvas.getContext('2d', {alpha: file.type !== 'image/jpeg'});
      context.imageSmoothingEnabled = true;
      context.imageSmoothingQuality = 'high';
      context.drawImage(decoded.source, 0, 0, width, height);
      const blob = await encodeAdaptive(canvas, file.type);
      const extension = file.type === 'image/jpeg' ? 'jpg' : file.type.split('/')[1];
      return new File([blob], `optimized.${extension}`, {type: file.type, lastModified: Date.now()});
    } finally {
      decoded.close();
    }
  }

  async function exportCrop(cropper, sourceType, options = {}) {
    if (!cropper) throw error('The cropper is not ready.', 'cropper');
    const maxWidth = options.maxWidth || 2400;
    const maxHeight = options.maxHeight || 2400;
    const canvas = cropper.getCroppedCanvas({
      maxWidth,
      maxHeight,
      imageSmoothingEnabled: true,
      imageSmoothingQuality: 'high',
      fillColor: sourceType === 'image/jpeg' ? '#fff' : undefined
    });
    if (!canvas) throw error('The image crop could not be created.', 'crop');
    const type = allowedTypes.includes(sourceType) ? sourceType : 'image/jpeg';
    return encodeAdaptive(canvas, type);
  }

  window.ItourImageOptimizer = Object.freeze({
    ABSOLUTE_MAX_BYTES,
    ABSOLUTE_MAX_PIXELS,
    ABSOLUTE_MAX_EDGE,
    validateFile,
    optimizeSource,
    exportCrop,
    setButtonBusy
  });
})();
