(function () {
  'use strict';
  if (!window.itourMobileScrollReady) {
    const script = document.createElement('script');
    script.src = new URL('mobile-scroll.js', document.currentScript.src).href;
    document.head.appendChild(script);
  }

  const legacyButton = document.getElementById('scroll-to-top-btn');
  if (legacyButton) legacyButton.remove();

  const button = document.createElement('button');
  button.className = 'back-to-top';
  button.type = 'button';
  button.setAttribute('aria-label', 'Back to top');
  button.setAttribute('title', 'Back to top');
  button.innerHTML = `
    <span class="back-to-top__surface" aria-hidden="true">
      <svg class="back-to-top__arrow" viewBox="0 0 24 24">
        <path d="m6 10 6-6 6 6"></path>
        <path d="M12 4v16"></path>
      </svg>
    </span>
    <svg class="back-to-top__meter" viewBox="0 0 64 64" aria-hidden="true">
      <defs>
        <linearGradient id="backToTopProgressGradient" x1="8" y1="8" x2="56" y2="56" gradientUnits="userSpaceOnUse">
          <stop offset="0" stop-color="#69d2ad"></stop>
          <stop offset=".5" stop-color="#2aa17c"></stop>
          <stop offset="1" stop-color="#137457"></stop>
        </linearGradient>
      </defs>
      <circle class="back-to-top__track" cx="32" cy="32" r="27"></circle>
      <circle class="back-to-top__progress" cx="32" cy="32" r="27"></circle>
      <g class="back-to-top__boat">
        <g class="back-to-top__boat-vessel">
          <path class="back-to-top__boat-wave" d="M-11 6.2c2.4-1.45 4.8 1.45 7.2 0s4.8 1.45 7.2 0 4.8 1.45 7.2 0"></path>
          <path class="back-to-top__boat-hull" d="M-10 0h20L6 5.5H-6z"></path>
          <path class="back-to-top__boat-mast" d="M0 0v-8.5"></path>
          <path class="back-to-top__boat-sail" d="M-1-.5v-8l-6.4 8z"></path>
          <path class="back-to-top__boat-sail" d="M1-7.7v7.2h5.7z"></path>
        </g>
      </g>
    </svg>`;

  document.body.appendChild(button);

  const progressCircle = button.querySelector('.back-to-top__progress');
  const boat = button.querySelector('.back-to-top__boat');
  const radius = 27;
  const circumference = 2 * Math.PI * radius;
  let animationFrame = 0;
  let targetProgress = 0;
  let displayedProgress = 0;

  progressCircle.style.strokeDasharray = String(circumference);
  progressCircle.style.strokeDashoffset = String(circumference);

  function render(progress) {
    const angle = progress * 360;
    const radians = angle * Math.PI / 180;
    const boatX = 32 + radius * Math.sin(radians);
    const boatY = 32 - radius * Math.cos(radians);
    progressCircle.style.strokeDashoffset = String(circumference * (1 - progress));
    boat.setAttribute('transform', `translate(${boatX} ${boatY})`);
  }

  function animateProgress() {
    const difference = targetProgress - displayedProgress;
    displayedProgress += difference * .14;

    if (Math.abs(difference) < .00035) displayedProgress = targetProgress;
    render(displayedProgress);

    if (displayedProgress !== targetProgress) {
      animationFrame = window.requestAnimationFrame(animateProgress);
    } else {
      animationFrame = 0;
    }
  }

  function updateTarget() {
    const scrollable = Math.max(document.documentElement.scrollHeight - window.innerHeight, 0);
    targetProgress = scrollable ? Math.min(Math.max(window.scrollY / scrollable, 0), 1) : 0;
    const isVisible = window.scrollY > 180 && scrollable > 0;
    button.classList.toggle('is-visible', isVisible);
    document.body.classList.toggle('back-to-top-visible', isVisible);
    if (!animationFrame) animationFrame = window.requestAnimationFrame(animateProgress);
  }

  button.addEventListener('click', function () {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
  });

  window.addEventListener('scroll', updateTarget, { passive: true });
  window.addEventListener('resize', updateTarget, { passive: true });
  window.addEventListener('load', updateTarget, { once: true });
  render(0);
  updateTarget();
})();

(function () {
  'use strict';
  if (document.querySelector('.itour-ai-launcher')) return;

  const scriptUrl = document.currentScript?.src || document.baseURI;
  const endpoint = new URL('../php/itour_ai_chat.php', scriptUrl).href;
  const launcher = document.createElement('button');
  launcher.type = 'button';
  launcher.className = 'itour-ai-launcher';
  launcher.setAttribute('aria-label', 'Open iTour AI Assistant');
  launcher.setAttribute('aria-expanded', 'false');
  launcher.setAttribute('aria-controls', 'itourAiPanel');
  launcher.innerHTML = `
    <span class="itour-ai-launcher__assistant" aria-hidden="true">
      <svg viewBox="0 0 24 24"><path d="M5 13v-2a7 7 0 0 1 14 0v2"></path><path d="M5 12H4a2 2 0 0 0-2 2v2a2 2 0 0 0 2 2h2v-6H5Zm14 0h1a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2h-2v-6h1Z"></path><path d="M18 18c0 2-2 3-5 3"></path><circle cx="11.5" cy="21" r="1"></circle></svg>
    </span>
    <span class="itour-ai-launcher__label">Ask iTour AI</span>
    <span class="itour-ai-launcher__close" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"></path></svg></span>`;

  const panel = document.createElement('section');
  panel.id = 'itourAiPanel';
  panel.className = 'itour-ai-panel';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-modal', 'false');
  panel.setAttribute('aria-labelledby', 'itourAiTitle');
  panel.hidden = true;
  panel.innerHTML = `
    <header class="itour-ai-panel__header">
      <span class="itour-ai-panel__avatar" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M5 13v-2a7 7 0 0 1 14 0v2"></path><path d="M5 12H4a2 2 0 0 0-2 2v2a2 2 0 0 0 2 2h2v-6H5Zm14 0h1a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2h-2v-6h1Z"></path><path d="M18 18c0 2-2 3-5 3"></path><circle cx="11.5" cy="21" r="1"></circle></svg>
      </span>
      <div><h2 id="itourAiTitle">iTour AI Assistant</h2><p><i></i>Your virtual tourism guide</p></div>
      <span class="itour-ai-panel__powered">
        <svg class="itour-ai-gemini-logo" viewBox="0 0 24 24" aria-hidden="true">
          <defs><linearGradient id="itourAiGeminiGradient" x1="3" y1="21" x2="21" y2="3" gradientUnits="userSpaceOnUse"><stop stop-color="#4285f4"></stop><stop offset=".48" stop-color="#9b72cb"></stop><stop offset="1" stop-color="#d96570"></stop></linearGradient></defs>
          <path d="M12 2.3c.75 5.12 4.58 8.95 9.7 9.7-5.12.75-8.95 4.58-9.7 9.7-.75-5.12-4.58-8.95-9.7-9.7 5.12-.75 8.95-4.58 9.7-9.7Z"></path>
        </svg>
        <span>Powered by Gemini</span>
      </span>
    </header>
    <div class="itour-ai-panel__messages" data-itour-ai-messages aria-live="polite"></div>
    <form class="itour-ai-panel__form" data-itour-ai-form>
      <label class="itour-ai-panel__input-wrap">
        <span class="itour-ai-sr-only">Ask a tourism question</span>
        <textarea rows="1" maxlength="700" placeholder="Ask me anything..." data-itour-ai-input></textarea>
        <button type="submit" aria-label="Send question" data-itour-ai-send>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 11 18-8-8 18-2-8-8-2Z"></path><path d="m11 13 4-4"></path></svg>
        </button>
      </label>
      <small>AI responses may contain errors. Verify availability and prices before booking.</small>
    </form>`;

  document.body.append(panel, launcher);

  const messagesElement = panel.querySelector('[data-itour-ai-messages]');
  const form = panel.querySelector('[data-itour-ai-form]');
  const input = panel.querySelector('[data-itour-ai-input]');
  const sendButton = panel.querySelector('[data-itour-ai-send]');
  let conversation = [];
  let requestController = null;

  const welcomeMarkup = `
    <article class="itour-ai-welcome">
      <strong>Hello! Welcome to iTour Mercedes! <span aria-hidden="true">👋</span></strong>
      <p>I'm your iTour Mercedes website guide. Ask me about destinations, Hotels &amp; Resorts, tours, guides, boats, bookings, or where to find something.</p>
    </article>
    <div class="itour-ai-suggestions" data-itour-ai-suggestions>
      <span>Suggested questions</span>
      <button type="button">What are the best places to visit in Mercedes?</button>
      <button type="button">How can I book a tour package?</button>
      <button type="button">Where can I find hotels and resorts?</button>
    </div>`;

  function resetConversation() {
    requestController?.abort();
    requestController = null;
    conversation = [];
    messagesElement.innerHTML = welcomeMarkup;
    input.value = '';
    input.style.height = '';
    sendButton.disabled = false;
    messagesElement.scrollTop = 0;
  }

  function setOpen(open) {
    document.body.classList.toggle('itour-ai-chat-open', open);
    launcher.classList.toggle('is-open', open);
    launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
    launcher.setAttribute('aria-label', open ? 'Close iTour AI Assistant' : 'Open iTour AI Assistant');
    panel.hidden = !open;
    panel.classList.toggle('is-open', open);
    if (open) {
      input.focus();
    } else {
      resetConversation();
      launcher.focus();
    }
  }

  function appendInlineFormatting(container, value) {
    const text = String(value || '');
    const pattern = /(\*\*[^*]+\*\*|__[^_]+__|\*[^*\n]+\*)/g;
    let cursor = 0;
    let match;

    while ((match = pattern.exec(text)) !== null) {
      if (match.index > cursor) container.append(document.createTextNode(text.slice(cursor, match.index)));
      const token = match[0];
      const element = document.createElement(token.startsWith('**') || token.startsWith('__') ? 'strong' : 'em');
      const trimBy = token.startsWith('**') || token.startsWith('__') ? 2 : 1;
      element.textContent = token.slice(trimBy, -trimBy);
      container.append(element);
      cursor = match.index + token.length;
    }

    if (cursor < text.length) container.append(document.createTextNode(text.slice(cursor)));
  }

  function appendFormattedAnswer(container, value) {
    let list = null;
    String(value || '').replace(/\r/g, '').split('\n').forEach(line => {
      const bullet = line.match(/^\s*[-*]\s+(.+)$/);
      if (bullet) {
        if (!list) {
          list = document.createElement('ul');
          container.append(list);
        }
        const item = document.createElement('li');
        appendInlineFormatting(item, bullet[1]);
        list.append(item);
        return;
      }

      list = null;
      if (!line.trim()) return;
      const paragraph = document.createElement('p');
      appendInlineFormatting(paragraph, line);
      container.append(paragraph);
    });
  }

  function addMessage(role, text, pending = false) {
    const message = document.createElement('div');
    message.className = `itour-ai-message itour-ai-message--${role}${pending ? ' is-pending' : ''}`;
    const bubble = document.createElement('div');
    bubble.className = 'itour-ai-message__bubble';
    if (pending) {
      bubble.innerHTML = '<span></span><span></span><span></span>';
    } else if (role === 'assistant') {
      bubble.classList.add('is-formatted');
      appendFormattedAnswer(bubble, text);
    } else {
      bubble.textContent = text;
    }
    message.appendChild(bubble);
    messagesElement.appendChild(message);
    messagesElement.scrollTop = messagesElement.scrollHeight;
    return message;
  }

  async function ask(question) {
    const text = String(question || '').trim();
    if (!text || requestController) return;

    messagesElement.querySelector('[data-itour-ai-suggestions]')?.remove();
    addMessage('user', text);
    const previousHistory = conversation.slice(-8);
    conversation.push({ role: 'user', text });
    input.value = '';
    input.style.height = '';
    sendButton.disabled = true;
    const pending = addMessage('assistant', '', true);
    requestController = new AbortController();

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          message: text,
          history: previousHistory,
          page: `${window.location.pathname}${window.location.search}`
        }),
        signal: requestController.signal
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.ok || !data.answer) throw new Error(data.message || 'iTour AI could not answer right now.');
      pending.remove();
      conversation.push({ role: 'assistant', text: data.answer });
      addMessage('assistant', data.answer);
    } catch (error) {
      pending.remove();
      if (error.name !== 'AbortError') {
        addMessage('error', error.message || 'iTour AI could not answer right now. Please try again.');
      }
    } finally {
      requestController = null;
      sendButton.disabled = false;
      if (!panel.hidden) input.focus();
    }
  }

  launcher.addEventListener('click', () => setOpen(panel.hidden));
  messagesElement.addEventListener('click', event => {
    const suggestion = event.target.closest('.itour-ai-suggestions button');
    if (suggestion) ask(suggestion.textContent);
  });
  form.addEventListener('submit', event => {
    event.preventDefault();
    ask(input.value);
  });
  input.addEventListener('keydown', event => {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      form.requestSubmit();
    }
  });
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = `${Math.min(input.scrollHeight, 104)}px`;
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !panel.hidden) setOpen(false);
  });

  resetConversation();
})();
