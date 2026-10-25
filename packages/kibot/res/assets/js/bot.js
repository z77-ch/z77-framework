/*
 * bot.js — KiBot chat widget (ported from the poolmanufaktur chat.js).
 *
 * Include with:  <script src="bot.js" data-endpoint="bot.php" defer></script>
 *   data-endpoint  URL of the bot endpoint (POST = ask as SSE); default "bot.php"
 *   data-config    URL of the widget configuration (GET, JSON); default "<data-endpoint>?config".
 *                  The z77 adapter has two actions:
 *                  data-config="/frontend/main/kibot/config" data-endpoint="/frontend/main/kibot/ask"
 * Any element with the attribute data-kibot-open opens the chat on click.
 *
 * Security (z77 rule 7): the widget builds every node with createElement/textContent.
 * No innerHTML, no HTML strings, no eval. Links are created only for http(s) URLs and
 * site-relative paths; CTA URLs only for http(s), mailto, tel and site-relative paths.
 * Everything the user may do is re-checked on the server (CSRF, honeypot, limits).
 */
(function (document, window) {
    'use strict';

    var SVG_NS = 'http://www.w3.org/2000/svg';
    var ICONS = {
        chat:     ['M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z', 22],
        print:    ['M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z', 16],
        expand:   ['M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z', 16],
        collapse: ['M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z', 16],
        close:    ['M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z', 24],
        send:     ['M2 21l21-9L2 3v7l15 2-15 2z', 18]
    };

    var script   = document.currentScript;
    var ENDPOINT = (script && script.getAttribute('data-endpoint')) || 'bot.php';
    var CONFIG_URL = (script && script.getAttribute('data-config'))
        || ENDPOINT + (ENDPOINT.indexOf('?') < 0 ? '?' : '&') + 'config';
    var CONFIG   = {};
    var state    = { open: false, loading: false, messages: [], csrfToken: '', scrollY: 0 };
    var nodes    = {};

    function log() {
        if (CONFIG.debug && window.console) {
            window.console.log.apply(window.console, ['[KIBOT]'].concat(Array.prototype.slice.call(arguments)));
        }
    }

    function init() {
        fetch(CONFIG_URL, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                CONFIG = data || {};
                state.csrfToken = CONFIG.token || '';
                buildWidget();
            })
            .catch(function (err) {
                if (window.console) window.console.warn('[KIBOT] config fetch failed:', err);
            });
    }

    // ── DOM ──────────────────────────────────────────────────────────────────

    function el(tag, attrs, text) {
        var node = document.createElement(tag);
        if (attrs) {
            for (var k in attrs) {
                if (Object.prototype.hasOwnProperty.call(attrs, k) && attrs[k] !== undefined && attrs[k] !== null) {
                    node.setAttribute(k, String(attrs[k]));
                }
            }
        }
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    }

    function icon(name) {
        var def = ICONS[name];
        var svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'currentColor');
        svg.setAttribute('width', def[1]);
        svg.setAttribute('height', def[1]);
        svg.setAttribute('aria-hidden', 'true');
        var path = document.createElementNS(SVG_NS, 'path');
        path.setAttribute('d', def[0]);
        svg.appendChild(path);
        return svg;
    }

    function headerButton(id, label, iconName, handler) {
        var btn = el('button', { type: 'button', id: id, 'class': 'kibot-header-btn', 'aria-label': label, title: label });
        btn.appendChild(icon(iconName));
        btn.addEventListener('click', handler);
        return btn;
    }

    function buildWidget() {
        var widget = el('div', { id: 'kibot-widget', 'aria-label': CONFIG.widgetLabel });

        var toggle = el('button', { type: 'button', id: 'kibot-toggle', 'aria-label': CONFIG.toggleOpenLabel, title: CONFIG.toggleTitle });
        toggle.appendChild(icon('chat'));
        toggle.addEventListener('click', toggleChat);

        var overlay = el('div', { id: 'kibot-overlay' });
        var panel   = el('div', { id: 'kibot-panel', role: 'dialog', 'aria-label': CONFIG.botName });

        // Print header: shown only when printing
        var printHead = el('div', { 'class': 'kibot-print-header' });
        printHead.appendChild(el('strong', null, CONFIG.printCompany));
        printHead.appendChild(el('div', null, CONFIG.printAddress));
        printHead.appendChild(el('div', null, CONFIG.printContact));
        printHead.appendChild(el('div', { 'class': 'kibot-print-title' }, CONFIG.printTitle));
        nodes.printDate = el('div', { 'class': 'kibot-print-date' });
        printHead.appendChild(nodes.printDate);

        var header  = el('div', { id: 'kibot-header' });
        var hText   = el('div', { 'class': 'kibot-header-text' });
        hText.appendChild(el('span', { id: 'kibot-title' }, CONFIG.botName));
        hText.appendChild(el('span', { id: 'kibot-subtitle' }, CONFIG.heroTitle));
        var actions = el('div', { 'class': 'kibot-header-actions' });
        actions.appendChild(headerButton('kibot-print', CONFIG.printLabel, 'print', printChat));
        nodes.expand = headerButton('kibot-expand', CONFIG.expandLabel, 'expand', toggleExpand);
        actions.appendChild(nodes.expand);
        actions.appendChild(headerButton('kibot-close', CONFIG.closeLabel, 'close', toggleChat));
        header.appendChild(hText);
        header.appendChild(actions);

        nodes.messages = el('div', { id: 'kibot-messages', role: 'log', 'aria-live': 'polite' });

        var inputWrap = el('div', { id: 'kibot-input-wrap' });
        nodes.input = el('textarea', { id: 'kibot-input', rows: 1, placeholder: CONFIG.placeholder, 'aria-label': CONFIG.inputLabel, maxlength: CONFIG.maxLength || 2000 });
        nodes.send  = el('button', { type: 'button', id: 'kibot-send', 'aria-label': CONFIG.sendLabel, title: CONFIG.sendLabel });
        nodes.send.appendChild(icon('send'));
        inputWrap.appendChild(nodes.input);
        inputWrap.appendChild(nodes.send);

        panel.appendChild(printHead);
        panel.appendChild(header);
        panel.appendChild(nodes.messages);
        panel.appendChild(inputWrap);
        overlay.appendChild(panel);
        widget.appendChild(toggle);
        widget.appendChild(overlay);
        document.body.appendChild(widget);
        nodes.overlay = overlay;
        nodes.toggle  = toggle;

        nodes.send.addEventListener('click', handleSend);
        nodes.input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                handleSend();
            }
        });
        nodes.input.addEventListener('input', autoResize);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && state.open) toggleChat();
        });
        document.addEventListener('click', function (e) {
            var opener = e.target && e.target.closest ? e.target.closest('[data-kibot-open]') : null;
            if (opener && !state.open) {
                e.preventDefault();
                toggleChat();
            }
        });

        appendMessage('assistant', CONFIG.welcomeMsg || '');

        // Android: follow the visual viewport when the keyboard opens
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', function () {
                if (state.open && window.innerWidth <= 768) {
                    overlay.style.transition = 'none';
                    overlay.style.height     = window.visualViewport.height + 'px';
                    window.requestAnimationFrame(function () { overlay.style.transition = ''; });
                }
            });
        }
    }

    // ── Open / close / expand / print ────────────────────────────────────────

    function toggleChat() {
        state.open = !state.open;
        nodes.overlay.classList.toggle('kibot--open', state.open);
        nodes.toggle.classList.toggle('kibot--active', state.open);
        nodes.toggle.setAttribute('aria-label', state.open ? CONFIG.toggleCloseLabel : CONFIG.toggleOpenLabel);

        if (state.open) {
            if (window.innerWidth <= 768) {
                state.scrollY = window.scrollY;
                document.body.style.position = 'fixed';
                document.body.style.top      = '-' + state.scrollY + 'px';
                document.body.style.width    = '100%';
            }
            nodes.input.focus();
            scrollToBottom(true);
        } else {
            nodes.overlay.style.height = '';
            if (window.innerWidth <= 768) {
                document.body.style.position = '';
                document.body.style.top      = '';
                document.body.style.width    = '';
                window.scrollTo(0, state.scrollY || 0);
            }
        }
    }

    function toggleExpand() {
        var expanded = nodes.overlay.classList.toggle('kibot--expanded');
        var label    = expanded ? CONFIG.collapseLabel : CONFIG.expandLabel;
        nodes.expand.replaceChild(icon(expanded ? 'collapse' : 'expand'), nodes.expand.firstChild);
        nodes.expand.title = label;
        nodes.expand.setAttribute('aria-label', label);
    }

    // Prints the conversation through the print stylesheet in bot.css:
    // no new window, no HTML strings, nothing the page CSP has to allow.
    function printChat() {
        var now = new Date();
        nodes.printDate.textContent = now.toLocaleDateString('de-CH') + ', ' +
            now.toLocaleTimeString('de-CH', { hour: '2-digit', minute: '2-digit' });
        var root = document.documentElement;
        root.classList.add('kibot-printing');
        var done = function () {
            root.classList.remove('kibot-printing');
            window.removeEventListener('afterprint', done);
        };
        window.addEventListener('afterprint', done);
        window.print();
    }

    // ── Sending and streaming ────────────────────────────────────────────────

    function handleSend() {
        if (state.loading) return;
        var text = nodes.input.value.trim();
        if (!text) return;
        nodes.input.value = '';
        autoResize.call(nodes.input);
        state.messages.push({ role: 'user', content: text });
        appendMessage('user', text);
        streamResponse();
    }

    function streamResponse() {
        state.loading = true;
        setSendEnabled(false);

        var wrap   = appendMessage('assistant', '');
        var textEl = wrap.querySelector('.kibot-text');
        var typing = el('span', { 'class': 'kibot-typing', 'aria-hidden': 'true' });
        typing.appendChild(el('span'));
        typing.appendChild(el('span'));
        typing.appendChild(el('span'));
        textEl.appendChild(typing);
        scrollToBottom();

        var fullText = '';
        var finished = false;

        function finish(text) {
            if (finished) return;
            finished = true;
            finishStream(text, textEl);
        }

        function showError(message) {
            textEl.textContent = message;
            finish(null);
        }

        var payload = { messages: state.messages, token: state.csrfToken, website: '' };
        log('sending', payload.messages.length, 'messages');

        fetch(ENDPOINT, {
            method:      'POST',
            credentials: 'same-origin',
            // The token travels twice: in the body for Guard, and as X-CSRF-Token for
            // hosts that check every fetch POST centrally (z77 AccessGuard).
            headers:     { 'Content-Type': 'application/json', 'Accept': 'text/event-stream', 'X-CSRF-Token': state.csrfToken },
            body:        JSON.stringify(payload)
        })
        .then(function (res) {
            if (!res.ok || !res.body) throw new Error('HTTP ' + res.status);
            var reader  = res.body.getReader();
            var decoder = new TextDecoder();
            var buffer  = '';

            function read() {
                return reader.read().then(function (result) {
                    if (result.done) {
                        finish(fullText);
                        return;
                    }
                    buffer += decoder.decode(result.value, { stream: true });
                    var lines = buffer.split('\n');
                    buffer = lines.pop();

                    for (var i = 0; i < lines.length; i++) {
                        if (lines[i].indexOf('data: ') !== 0) continue;
                        var raw = lines[i].slice(6).trim();
                        if (raw === '[DONE]') {
                            finish(fullText);
                            return;
                        }
                        var evt;
                        try {
                            evt = JSON.parse(raw);
                        } catch (e) {
                            log('parse error', e.message);
                            continue;
                        }
                        if (evt.error) {
                            log('SSE error', evt.error);
                            showError(String(evt.error));
                            return;
                        }
                        if (typeof evt.text === 'string' && evt.text !== '') {
                            fullText += evt.text;
                            renderInto(textEl, fullText);
                            scrollToBottom();
                        }
                    }
                    return read();
                });
            }
            return read();
        })
        .catch(function (err) {
            log('fetch error', err);
            showError(CONFIG.connectionError || 'Verbindungsfehler.');
        });
    }

    function finishStream(text, textEl) {
        state.loading = false;
        setSendEnabled(true);
        if (text !== null && text !== '') {
            state.messages.push({ role: 'assistant', content: text });
            renderInto(textEl, text);
        } else if (text === '') {
            // Stream ended without text and without error event: drop the empty bubble.
            textEl.textContent = CONFIG.connectionError || '';
            state.messages.pop(); // the unanswered question would otherwise be sent twice
        } else {
            state.messages.pop();
        }
        nodes.input.focus();
    }

    // ── Rendering (text only, never HTML) ────────────────────────────────────

    function appendMessage(role, text) {
        var wrap   = el('div', { 'class': 'kibot-msg kibot-msg--' + role });
        var bubble = el('div', { 'class': 'kibot-bubble' });
        var textEl = el('span', { 'class': 'kibot-text' });
        if (text) renderInto(textEl, text);
        if (role === 'assistant' && CONFIG.avatarSrc) {
            wrap.appendChild(el('img', { 'class': 'kibot-avatar', src: CONFIG.avatarSrc, alt: CONFIG.avatarAlt || '' }));
        }
        bubble.appendChild(textEl);
        wrap.appendChild(bubble);
        nodes.messages.appendChild(wrap);
        scrollToBottom();
        return wrap;
    }

    // Renders text with clickable links and {{CONTACT_CTA}} buttons. Line breaks come
    // from white-space: pre-wrap in bot.css.
    function renderInto(node, text) {
        while (node.firstChild) node.removeChild(node.firstChild);
        var parts = String(text).split('{{CONTACT_CTA}}');
        for (var i = 0; i < parts.length; i++) {
            appendLinkified(node, parts[i]);
            if (i < parts.length - 1) node.appendChild(buildCtaButtons());
        }
    }

    var LINK_RE = /https?:\/\/[^\s<>"'()]+|(^|[\s(])(\/[a-z][a-z0-9_\-]*(?:\/[a-z0-9_\-]+)*\/?)/gi;

    function appendLinkified(node, text) {
        var last = 0;
        var m;
        LINK_RE.lastIndex = 0;
        while ((m = LINK_RE.exec(text)) !== null) {
            var prefix = m[2] ? m[1] : '';
            var url    = m[2] ? m[2] : m[0];
            var start  = m.index + prefix.length;
            var trail  = (url.match(/[.,;:!?]+$/) || [''])[0];
            url = url.slice(0, url.length - trail.length);
            if (start > last) node.appendChild(document.createTextNode(text.slice(last, start)));
            var a = el('a', { href: url }, url);
            if (/^https?:/i.test(url)) {
                a.setAttribute('target', '_blank');
                a.setAttribute('rel', 'noopener noreferrer');
            }
            node.appendChild(a);
            last = start + url.length;
            if (m[0].length === 0) LINK_RE.lastIndex++;
        }
        if (last < text.length) node.appendChild(document.createTextNode(text.slice(last)));
    }

    function buildCtaButtons() {
        var group = el('span', { 'class': 'kibot-cta-group' });
        var ctas  = CONFIG.contactCtas || [];
        for (var i = 0; i < ctas.length; i++) {
            var url = String(ctas[i].url || '');
            if (!/^(https?:|mailto:|tel:|\/(?!\/))/i.test(url)) continue;
            group.appendChild(el('a', { href: url, 'class': 'kibot-cta' }, ctas[i].label || url));
        }
        return group;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    function scrollToBottom(force) {
        var m = nodes.messages;
        if (!m) return;
        var atBottom = m.scrollHeight - m.scrollTop - m.clientHeight < 50;
        if (force || atBottom) m.scrollTop = m.scrollHeight;
    }

    function setSendEnabled(enabled) {
        nodes.send.disabled  = !enabled;
        nodes.input.disabled = !enabled;
    }

    function autoResize() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(document, window);
