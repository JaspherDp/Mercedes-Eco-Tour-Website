(function(){
  'use strict';
  let openMenu = null;
  function closeCancellationMenu() {
    if (!openMenu) return;
    openMenu.menu.hidden = true;
    openMenu.button.setAttribute('aria-expanded', 'false');
    openMenu.owner.appendChild(openMenu.menu);
    openMenu = null;
  }
  document.querySelectorAll('[data-hc-actions]').forEach(button => button.addEventListener('click', event => {
    event.stopPropagation();
    const menu = document.getElementById(button.getAttribute('aria-controls'));
    const wasOpen = openMenu && openMenu.menu === menu;
    closeCancellationMenu();
    if (wasOpen || !menu) return;
    const owner = menu.parentNode;
    document.body.appendChild(menu);
    menu.hidden = false;
    button.setAttribute('aria-expanded', 'true');
    openMenu = { menu, button, owner };
    const bounds = button.getBoundingClientRect();
    const width = menu.getBoundingClientRect().width;
    const height = menu.getBoundingClientRect().height;
    menu.style.left = Math.max(8, Math.min(bounds.right - width, window.innerWidth - width - 8)) + 'px';
    menu.style.top = Math.max(8, bounds.bottom + height + 6 < window.innerHeight ? bounds.bottom + 6 : bounds.top - height - 6) + 'px';
    menu.querySelector('button')?.focus({ preventScroll: true });
  }));
  document.addEventListener('click', event => { if (openMenu && !openMenu.menu.contains(event.target)) closeCancellationMenu(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && openMenu) { const button = openMenu.button; closeCancellationMenu(); button.focus(); } });
  window.addEventListener('resize', closeCancellationMenu);
  document.addEventListener('scroll', event => { if (openMenu && event.target !== openMenu.menu) closeCancellationMenu(); }, true);
  document.querySelectorAll('[data-hc-reason]').forEach(button => button.addEventListener('click', () => {
    closeCancellationMenu();
    const data = JSON.parse(button.dataset.hcReason);
    const text = String(data.reference || 'Booking') + '\n\nTourist cancellation reason\n' + String(data.reason || 'No reason provided.')
      + (data.review_note ? '\n\nReview reason\n' + String(data.review_note) : '')
      + '\n\nNon-refundable amount: PHP ' + Number(data.non_refundable || 0).toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2});
    if (window.Swal) Swal.fire({ title: 'Cancellation reason', text, icon: 'info', confirmButtonColor: '#176b58', confirmButtonText: 'Close', customClass: {popup:'hc-reason-modal'} });
    else window.alert(text);
  }));
  document.querySelectorAll('[data-hotel-cancellation-decision]').forEach(form=>form.addEventListener('submit',async event=>{
    event.preventDefault();if(form.dataset.submitting==='1')return;closeCancellationMenu();
    const reject=form.dataset.hotelCancellationDecision==='reject';let note='';
    if(window.Swal){const result=await Swal.fire({icon:'warning',title:reject?'Reject cancellation request?':'Approve cancellation request?',text:reject?'The reservation will remain active. Enter the reason shared with the guest.':'The room reservation will be cancelled. Main admin will handle any eligible refund.',input:reject?'textarea':undefined,inputLabel:reject?'Reason for rejection':undefined,inputAttributes:{maxlength:'1500'},inputValidator:reject?(value=>!String(value||'').trim()?'A rejection reason is required.':String(value).trim().length>1500?'Use 1,500 characters or fewer.':undefined):undefined,showCancelButton:true,confirmButtonText:reject?'Reject request':'Approve cancellation',cancelButtonText:'Go back',confirmButtonColor:'#176b58'});if(!result.isConfirmed)return;note=reject?String(result.value||'').trim():'';}
    else if(reject){const value=window.prompt('Reason for rejecting this cancellation request (required):');if(value===null)return;note=value.trim();if(!note||note.length>1500){window.alert('A reason of 1 to 1,500 characters is required.');return;}}
    else if(!window.confirm('Approve cancellation? The room reservation will be cancelled and main admin will handle any eligible refund.'))return;
    form.querySelector('[name="review_note"]').value=note;form.dataset.submitting='1';form.querySelectorAll('button').forEach(button=>button.disabled=true);if(window.showBookingDecisionProcessing) await window.showBookingDecisionProcessing(reject?'Rejecting cancellation request':'Approving cancellation request','Please wait while your decision is saved and the guest email is sent.');
    else if(window.Swal){Swal.fire({title:'Saving cancellation review',text:'Please wait while the guest email is sent.',allowOutsideClick:false,allowEscapeKey:false,showConfirmButton:false,didOpen:()=>Swal.showLoading()});await new Promise(resolve=>window.setTimeout(resolve,80));}
    HTMLFormElement.prototype.submit.call(form);
  }));
  const flash=window.hotelCancellationFlash;if(flash&&flash.message){if(window.Swal)Swal.fire({icon:flash.icon||'info',title:flash.icon==='error'?'Review not saved':'Cancellation review',text:flash.message,confirmButtonColor:'#176b58',confirmButtonText:'OK'});else window.alert(flash.message);}
})();
