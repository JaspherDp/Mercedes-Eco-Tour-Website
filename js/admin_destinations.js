(() => {
  const editor = document.getElementById('destinationEditor');
  const form = document.getElementById('destinationForm');
  const data = window.destinationAdminData || {};
  const byId = (id) => document.getElementById(id);
  const set = (id, value) => { if (byId(id)) byId(id).value = value ?? ''; };
  const mediaPicker = byId('mediaPicker');
  let cropper = null;
  let mediaKind = 'card';
  let sourceObjectUrl = '';
  const editorSteps = ['details', 'media', 'location', 'publishing'];

  function updateEditorNavigation(name) {
    const stepIndex = Math.max(0, editorSteps.indexOf(name));
    const isAddMode = editor?.dataset.mode === 'add';
    document.querySelectorAll('[data-tab]').forEach((button, index) => {
      button.classList.toggle('completed', isAddMode && index < stepIndex);
      if (index === stepIndex) button.setAttribute('aria-current', 'step');
      else button.removeAttribute('aria-current');
    });
    const previous = byId('editorPrevious');
    const next = byId('editorNext');
    const save = byId('editorSave');
    if (previous) previous.hidden = !isAddMode || stepIndex === 0;
    if (next) next.hidden = !isAddMode || stepIndex === editorSteps.length - 1;
    if (save) {
      save.hidden = isAddMode && stepIndex !== editorSteps.length - 1;
      save.textContent = isAddMode ? 'Create destination' : 'Save changes';
    }
  }

  function switchTab(name) {
    document.querySelectorAll('[data-tab]').forEach((button) => button.classList.toggle('active', button.dataset.tab === name));
    document.querySelectorAll('[data-pane]').forEach((pane) => pane.classList.toggle('active', pane.dataset.pane === name));
    updateEditorNavigation(name);
  }

  function preview(previewId, path) {
    const image = byId(previewId);
    if (!image) return;
    image.hidden = !path;
    image.src = path || '';
  }

  function resetEditor() {
    form.reset();
    delete form.dataset.archiveConfirmed;
    editor.dataset.originalStatus = '';
    set('destinationId', ''); set('cardImageCurrent', ''); set('heroImageCurrent', '');
    set('fieldLocation', 'Mercedes, Camarines Norte'); set('fieldOrder', Object.keys(data).length + 1);
    preview('cardPreview', ''); preview('heroPreview', '');
    byId('existingGallery').innerHTML = ''; byId('newGallery').innerHTML = '';
    byId('editorTitle').textContent = 'Add destination';
    byId('descriptionCount').textContent = '0';
    switchTab('details');
  }

  function openEditor(id) {
    const isEditMode = Boolean(id && data[id]);
    editor.dataset.mode = isEditMode ? 'edit' : 'add';
    resetEditor();
    if (isEditMode) {
      const item = data[id];
      editor.dataset.originalStatus = item.status || '';
      byId('editorTitle').textContent = 'Edit destination';
      byId('editorEyebrow').textContent = 'DESTINATION EDITOR';
      byId('editorSubtitle').textContent = 'Update the card and visitor-facing destination story.';
      set('destinationId', item.destination_id); set('fieldTitle', item.title); set('fieldSlug', item.slug);
      set('fieldType', item.destination_type); set('fieldTagline', item.tagline); set('fieldDescription', item.description);
      set('fieldLocation', item.location); set('fieldLatitude', item.latitude); set('fieldLongitude', item.longitude);
      set('fieldActivities', item.activities_text); set('fieldStatus', item.status); set('fieldOrder', item.sort_order);
      byId('fieldFeatured').checked = Number(item.is_featured) === 1;
      set('cardImageCurrent', item.card_image); set('heroImageCurrent', item.hero_image);
      preview('cardPreview', item.card_image); preview('heroPreview', item.hero_image);
      byId('descriptionCount').textContent = String((item.description || '').length);
      byId('existingGallery').innerHTML = (item.gallery || []).map((photo) => `<label class="gallery-thumb" title="Uncheck to remove"><img src="${photo.image_path}" alt=""><input type="checkbox" name="keep_gallery[]" value="${photo.gallery_id}" checked></label>`).join('');
    } else {
      byId('editorEyebrow').textContent = 'NEW DESTINATION';
      byId('editorSubtitle').textContent = 'Complete the four steps to create a visitor-ready destination.';
    }
    editor.showModal();
  }

  document.querySelector('[data-open-editor]')?.addEventListener('click', () => openEditor(null));
  document.querySelectorAll('[data-edit-id]').forEach((button) => button.addEventListener('click', () => openEditor(button.dataset.editId)));
  document.querySelectorAll('[data-close-editor]').forEach((button) => button.addEventListener('click', () => editor.close()));
  editor?.addEventListener('click', (event) => { if (event.target === editor) editor.close(); });
  document.querySelectorAll('[data-tab]').forEach((button) => button.addEventListener('click', () => switchTab(button.dataset.tab)));
  byId('editorPrevious')?.addEventListener('click', () => {
    const current = editorSteps.indexOf(document.querySelector('[data-tab].active')?.dataset.tab || 'details');
    switchTab(editorSteps[Math.max(0, current - 1)]);
  });
  byId('editorNext')?.addEventListener('click', () => {
    const currentName = document.querySelector('[data-tab].active')?.dataset.tab || 'details';
    const activePane = document.querySelector(`[data-pane="${currentName}"]`);
    const invalidField = [...(activePane?.querySelectorAll('[required]') || [])].find((field) => !field.checkValidity());
    if (invalidField) { invalidField.reportValidity(); return; }
    if (currentName === 'media') {
      const hasCard = byId('cardImageCurrent').value || byId('cardImage').files.length;
      const hasHero = byId('heroImageCurrent').value || byId('heroImage').files.length;
      if (!hasCard || !hasHero) {
        if (window.Swal) Swal.fire('Images required','Choose both a card profile image and a wide hero cover before continuing.','info');
        return;
      }
    }
    const current = editorSteps.indexOf(currentName);
    switchTab(editorSteps[Math.min(editorSteps.length - 1, current + 1)]);
  });

  byId('fieldTitle')?.addEventListener('input', (event) => {
    if (!byId('destinationId').value || !byId('fieldSlug').value) byId('fieldSlug').value = event.target.value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  });
  byId('fieldDescription')?.addEventListener('input', (event) => byId('descriptionCount').textContent = String(event.target.value.length));

  function renderNewGallery() {
    byId('newGallery').innerHTML = '';
    [...(byId('galleryImages').files || [])].forEach((file) => {
      const image = document.createElement('img'); image.src = URL.createObjectURL(file); image.alt = file.name; byId('newGallery').appendChild(image);
    });
  }

  const mediaSettings = {
    card: {title:'Crop card profile image', subtitle:'Create the compact image shown on destination cards.', ratio:4/3, width:1200, height:900, hint:'Card photos use a boxy 4:3 crop.'},
    hero: {title:'Crop hero cover image', subtitle:'Create the wide banner shown at the top of destination details.', width:1920, hint:'Hero covers preserve the uploaded banner proportions.'},
    gallery: {title:'Crop gallery photo', subtitle:'Prepare a supporting landscape photo for the destination gallery.', ratio:3/2, width:1200, height:800, hint:'Gallery photos use a landscape 3:2 crop.'}
  };

  function resetMediaPicker() {
    cropper?.destroy(); cropper = null;
    if (sourceObjectUrl) URL.revokeObjectURL(sourceObjectUrl);
    sourceObjectUrl = '';
    byId('mediaSourceInput').value = '';
    byId('mediaCropImage').removeAttribute('src');
    byId('mediaSelectStep').hidden = false; byId('mediaCropStep').hidden = true;
    byId('mediaBackButton').hidden = true; byId('applyCropButton').hidden = true;
  }

  function openMediaPicker(kind) {
    mediaKind = kind;
    resetMediaPicker();
    const setting = mediaSettings[kind];
    mediaPicker.dataset.mediaKind = kind;
    byId('mediaPickerTitle').textContent = setting.title;
    byId('mediaPickerSubtitle').textContent = setting.subtitle;
    byId('cropHint').textContent = setting.hint + ' Drag to reposition and use the handles to resize.';
    mediaPicker.showModal();
  }

  function rejectMedia(message) {
    if (window.Swal) Swal.fire('Photo not accepted', message, 'info');
    else alert(message);
  }

  function loadMediaFile(file) {
    if (!file) return;
    if (!['image/jpeg','image/png','image/webp'].includes(file.type)) return rejectMedia('Choose a JPG, PNG, or WEBP image.');
    if (file.size > 8 * 1024 * 1024) return rejectMedia('The image must be 8 MB or smaller.');
    if (typeof window.Cropper !== 'function') return rejectMedia('The cropper could not load. Please refresh and try again.');
    cropper?.destroy();
    if (sourceObjectUrl) URL.revokeObjectURL(sourceObjectUrl);
    sourceObjectUrl = URL.createObjectURL(file);
    const cropImage = byId('mediaCropImage');
    cropImage.onload = () => {
      const setting = mediaSettings[mediaKind];
      const cropRatio = mediaKind === 'hero'
        ? cropImage.naturalWidth / cropImage.naturalHeight
        : setting.ratio;
      cropper = new Cropper(cropImage, {
        aspectRatio: cropRatio,
        viewMode: mediaKind === 'hero' ? 0 : 1,
        dragMode: 'move',
        autoCropArea: mediaKind === 'hero' ? 1 : .9,
        responsive: true,
        background: false,
        guides: true,
        center: true,
        movable: true,
        zoomable: true,
        rotatable: true,
        scalable: false
      });
    };
    cropImage.src = sourceObjectUrl;
    byId('mediaSelectStep').hidden = true; byId('mediaCropStep').hidden = false;
    byId('mediaBackButton').hidden = false; byId('applyCropButton').hidden = false;
  }

  function putFileInInput(input, file, append = false) {
    const transfer = new DataTransfer();
    if (append) [...input.files].forEach((existing) => transfer.items.add(existing));
    transfer.items.add(file);
    input.files = transfer.files;
  }

  document.querySelectorAll('[data-media-trigger]').forEach((button) => button.addEventListener('click', () => openMediaPicker(button.dataset.mediaTrigger)));
  document.querySelectorAll('[data-close-media]').forEach((button) => button.addEventListener('click', () => mediaPicker.close()));
  mediaPicker?.addEventListener('close', resetMediaPicker);
  byId('mediaDropzone')?.addEventListener('click', () => byId('mediaSourceInput').click());
  byId('mediaSourceInput')?.addEventListener('change', (event) => loadMediaFile(event.target.files?.[0]));
  ['dragenter','dragover'].forEach((name) => byId('mediaDropzone')?.addEventListener(name, (event) => { event.preventDefault(); byId('mediaDropzone').classList.add('drag-over'); }));
  ['dragleave','drop'].forEach((name) => byId('mediaDropzone')?.addEventListener(name, (event) => { event.preventDefault(); byId('mediaDropzone').classList.remove('drag-over'); }));
  byId('mediaDropzone')?.addEventListener('drop', (event) => loadMediaFile(event.dataTransfer?.files?.[0]));
  byId('mediaBackButton')?.addEventListener('click', resetMediaPicker);
  document.querySelectorAll('[data-crop-action]').forEach((button) => button.addEventListener('click', () => {
    if (!cropper) return;
    const actions = {'zoom-in':()=>cropper.zoom(.1),'zoom-out':()=>cropper.zoom(-.1),'rotate-left':()=>cropper.rotate(-90),'rotate-right':()=>cropper.rotate(90),'reset':()=>cropper.reset()};
    actions[button.dataset.cropAction]?.();
  }));
  byId('applyCropButton')?.addEventListener('click', () => {
    if (!cropper) return;
    const setting = mediaSettings[mediaKind];
    const canvasOptions = {width:setting.width,imageSmoothingEnabled:true,imageSmoothingQuality:'high',fillColor:'#fff'};
    if (setting.height) canvasOptions.height = setting.height;
    const canvas = cropper.getCroppedCanvas(canvasOptions);
    byId('applyCropButton').disabled = true;
    canvas.toBlob((blob) => {
      byId('applyCropButton').disabled = false;
      if (!blob) return rejectMedia('The cropped image could not be prepared.');
      const file = new File([blob], `${mediaKind}_${Date.now()}.jpg`, {type:'image/jpeg'});
      if (mediaKind === 'gallery') {
        putFileInInput(byId('galleryImages'), file, true); renderNewGallery();
      } else {
        const inputId = mediaKind === 'card' ? 'cardImage' : 'heroImage';
        const previewId = mediaKind === 'card' ? 'cardPreview' : 'heroPreview';
        putFileInInput(byId(inputId), file); preview(previewId, URL.createObjectURL(file));
      }
      mediaPicker.close();
    }, 'image/jpeg', .9);
  });

  function filterDestinations() {
    const query = byId('destinationFilter')?.value.trim().toLowerCase() || '';
    const filter = byId('destinationStatusFilter')?.value || 'all';
    const typeFilter = byId('destinationTypeFilter')?.value || 'all';
    let visible = 0;
    document.querySelectorAll('.destination-admin-card').forEach((card) => {
      const statusMatches = filter === 'all' || card.dataset.status === filter || (filter === 'featured' && card.dataset.featured === '1');
      const typeMatches = typeFilter === 'all' || card.dataset.type === typeFilter;
      const show = statusMatches && typeMatches && card.dataset.search.includes(query);
      card.hidden = !show;
      if (show) visible++;
    });
    byId('destinationEmpty').hidden = visible > 0;
  }
  byId('destinationFilter')?.addEventListener('input', filterDestinations);
  byId('destinationStatusFilter')?.addEventListener('change', filterDestinations);
  byId('destinationTypeFilter')?.addEventListener('change', filterDestinations);
  document.querySelectorAll('[data-menu-id]').forEach((button) => button.addEventListener('click', (event) => {
    event.stopPropagation(); const menu = document.querySelector(`[data-card-menu="${button.dataset.menuId}"]`);
    document.querySelectorAll('.card-menu').forEach((item) => { if (item !== menu) item.classList.remove('open'); }); menu?.classList.toggle('open');
  }));
  document.addEventListener('click', () => document.querySelectorAll('.card-menu').forEach((menu) => menu.classList.remove('open')));

  document.querySelectorAll('[data-archive-id]').forEach((button) => button.addEventListener('click', () => {
    const execute = () => { set('archiveId', button.dataset.archiveId); byId('archiveForm').submit(); };
    if (window.Swal) Swal.fire({title:'Archive destination?',text:`${button.dataset.archiveName} will be hidden from the public website.`,icon:'warning',showCancelButton:true,confirmButtonText:'Archive',confirmButtonColor:'#a43e3e'}).then((result) => { if (result.isConfirmed) execute(); });
    else if (confirm(`Archive ${button.dataset.archiveName}?`)) execute();
  }));

  document.querySelectorAll('.destination-card-actions form').forEach((visibilityForm) => {
    const button = visibilityForm.querySelector('.visibility-action');
    const card = visibilityForm.closest('.destination-admin-card');
    if (!button || card?.dataset.status === 'archived') return;

    visibilityForm.addEventListener('submit', (event) => {
      event.preventDefault();
      const destinationName = card.querySelector('h3')?.textContent?.trim() || 'This destination';
      const execute = () => HTMLFormElement.prototype.submit.call(visibilityForm);
      if (window.Swal) {
        Swal.fire({
          title: 'Archive destination?',
          text: `${destinationName} will be hidden from the public website.`,
          icon: 'warning',
          showCancelButton: true,
          confirmButtonText: 'Yes, archive it',
          cancelButtonText: 'Cancel',
          confirmButtonColor: '#a43e3e'
        }).then((result) => { if (result.isConfirmed) execute(); });
      } else if (confirm(`Archive ${destinationName}?`)) execute();
    });
  });

  form?.addEventListener('submit', (event) => {
    const activeStep = document.querySelector('[data-tab].active')?.dataset.tab || 'details';
    if (editor.dataset.mode === 'add' && activeStep !== 'publishing') {
      event.preventDefault();
      byId('editorNext')?.click();
      return;
    }
    const hasCard = byId('cardImageCurrent').value || byId('cardImage').files.length;
    const hasHero = byId('heroImageCurrent').value || byId('heroImage').files.length;
    if (!hasCard || !hasHero) {
      event.preventDefault();
      switchTab('media');
      if (window.Swal) Swal.fire('Images required','Choose both a card profile image and a wide hero cover.','info');
      return;
    }

    const isArchiving = editor.dataset.mode === 'edit'
      && editor.dataset.originalStatus === 'published'
      && byId('fieldStatus').value === 'archived'
      && form.dataset.archiveConfirmed !== 'true';
    if (!isArchiving) return;

    event.preventDefault();
    const destinationName = byId('fieldTitle').value.trim() || 'This destination';
    const execute = () => {
      form.dataset.archiveConfirmed = 'true';
      form.requestSubmit();
    };
    if (window.Swal) {
      Swal.fire({
        title: 'Archive destination?',
        text: `${destinationName} will be hidden from the public website.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, archive it',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#a43e3e'
      }).then((result) => { if (result.isConfirmed) execute(); });
    } else if (confirm(`Archive ${destinationName}?`)) execute();
  });
  if (Array.isArray(window.destinationNotice) && window.Swal) {
    const [icon, message] = window.destinationNotice;
    const archived = icon === 'success' && /archiv/i.test(message || '');
    Swal.fire({
      icon,
      title: icon === 'success' ? (archived ? 'Destination archived' : 'Changes saved') : 'Action failed',
      text: message,
      confirmButtonText: 'Okay',
      confirmButtonColor: '#176b58'
    });
  }
})();
