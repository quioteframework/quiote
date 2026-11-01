/*
 * Quiote developer overlay.
 *
 * Injected by DevOverlayMiddleware into HTML pages while core.developer_exceptions is on. Shows a
 * badge in the bottom-left corner and collects an exception report for the page itself and for
 * every same-origin fetch() or XMLHttpRequest whose response carries an X-Quiote-Exception header.
 * A report holds the exception the request ended in, the exceptions the application handled
 * itself, or both.
 *
 * Everything renders inside a shadow root styled through a constructable stylesheet, so the page's
 * CSS cannot reach in, the overlay's cannot leak out, and a `style-src 'self'` policy does not
 * block it. Report content is only ever set through textContent: exception messages routinely
 * contain request input.
 */
(() => {
    'use strict';

    if (window.__quioteDevOverlay) {
        return;
    }
    window.__quioteDevOverlay = true;

    const HEADER = 'X-Quiote-Exception';
    const ID_PATTERN = /^[0-9a-f]{32}$/;
    const script = document.currentScript || document.querySelector('script[data-quiote-overlay]');
    const settings = (script && script.dataset) || {};
    const endpoint = settings.endpoint || '/_quiote/overlay';
    const editorTemplate = /^\s*javascript:/i.test(settings.editor || '') ? '' : (settings.editor || '');

    const originalFetch = typeof window.fetch === 'function' ? window.fetch : null;
    const seen = new Set();
    const reports = [];
    let open = false;
    let unseen = 0;

    // ---- Picking reports up -------------------------------------------------------------------

    function sameOrigin(url) {
        if (!url) {
            return true;
        }
        try {
            return new URL(url, window.location.href).origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    function inspect(id, url) {
        if (!id || !ID_PATTERN.test(id) || seen.has(id) || !sameOrigin(url)) {
            return;
        }
        seen.add(id);
        load(id);
    }

    function load(id) {
        if (!originalFetch) {
            return;
        }
        originalFetch.call(window, endpoint + '/' + id, {
            credentials: 'same-origin',
            headers: {Accept: 'application/json'},
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('HTTP ' + response.status))))
            .then((report) => add(report))
            .catch((error) => add({id: id, unavailable: String(error && error.message ? error.message : error)}));
    }

    if (originalFetch) {
        window.fetch = function (...args) {
            return originalFetch.apply(this, args).then((response) => {
                try {
                    inspect(response.headers.get(HEADER), response.url);
                } catch (e) {
                    // An opaque or otherwise unreadable response carries no report to pick up.
                }
                return response;
            });
        };
    }

    if (typeof XMLHttpRequest === 'function') {
        const originalSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send = function (...args) {
            this.addEventListener('loadend', () => {
                try {
                    inspect(this.getResponseHeader(HEADER), this.responseURL);
                } catch (e) {
                    // A request that never got a response carries no report to pick up.
                }
            });
            return originalSend.apply(this, args);
        };
    }

    // ---- Rendering ----------------------------------------------------------------------------

    const CSS = `
        :host { all: initial; position: fixed; left: 16px; bottom: 16px; z-index: 2147483647;
            font: 13px/1.45 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            --bg: #ffffff; --fg: #1d1d1f; --muted: #6b6b73; --line: #e3e3e8; --soft: #f5f5f7;
            --accent: #d92d20; --accent-soft: #fdecea; --code-hl: #fff3c4; --badge: #18181b;
            --handled: #b54708; --handled-soft: #fef0c7; }
        @media (prefers-color-scheme: dark) {
            :host { --bg: #1c1c1f; --fg: #ececf1; --muted: #9b9ba6; --line: #34343a; --soft: #26262b;
                --accent: #ff6b5e; --accent-soft: #3a1f1d; --code-hl: #4a3f12; --badge: #f4f4f5;
                --handled: #fdb022; --handled-soft: #3b2a10; }
        }
        * { box-sizing: border-box; }
        button { font: inherit; color: inherit; cursor: pointer; }
        .badge { width: 40px; height: 40px; border-radius: 50%; border: 0; position: relative;
            background: var(--badge); color: var(--bg); font-weight: 700; font-size: 18px;
            box-shadow: 0 4px 14px rgba(0,0,0,.25); display: grid; place-items: center; }
        .badge:focus-visible, .panel button:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        .badge.has-errors { background: var(--accent); color: #fff; }
        .badge.pulse { animation: pulse .6s ease-out 2; }
        @keyframes pulse { 50% { transform: scale(1.15); } }
        .count { position: absolute; top: -4px; right: -4px; min-width: 18px; height: 18px; padding: 0 5px;
            border-radius: 9px; background: var(--bg); color: var(--accent); font-size: 11px; font-weight: 700;
            display: grid; place-items: center; border: 1px solid var(--accent); }
        .count[hidden] { display: none; }
        .panel { position: absolute; left: 0; bottom: 52px; width: min(760px, calc(100vw - 32px));
            max-height: min(75vh, 720px); display: flex; flex-direction: column; background: var(--bg);
            color: var(--fg); border: 1px solid var(--line); border-radius: 10px;
            box-shadow: 0 12px 40px rgba(0,0,0,.28); overflow: hidden; }
        .panel[hidden] { display: none; }
        header { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-bottom: 1px solid var(--line); }
        header h2 { font-size: 13px; margin: 0; flex: 1; font-weight: 600; }
        header button { background: var(--soft); border: 1px solid var(--line); border-radius: 6px; padding: 3px 10px; }
        .list { overflow: auto; flex: 1; }
        .empty { padding: 24px 12px; color: var(--muted); text-align: center; }
        .item { border-bottom: 1px solid var(--line); }
        .summary { width: 100%; text-align: left; background: none; border: 0; padding: 10px 12px;
            display: grid; grid-template-columns: auto 1fr auto; gap: 2px 10px; align-items: baseline; }
        .summary:hover { background: var(--soft); }
        .status { font-weight: 700; color: var(--accent); font-variant-numeric: tabular-nums; }
        .status.handled { color: var(--handled); }
        .where { color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .when { color: var(--muted); font-size: 12px; }
        .what { grid-column: 2 / 4; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .what b { font-weight: 600; }
        .tag { display: inline-block; margin-right: 6px; padding: 0 6px; border-radius: 4px; font-size: 11px;
            font-weight: 600; text-transform: uppercase; letter-spacing: .04em; background: var(--handled-soft); color: var(--handled); }
        .group { margin-top: 12px; }
        .group-title { display: flex; align-items: baseline; gap: 6px; flex-wrap: wrap; }
        .note { color: var(--muted); }
        .group .message { background: var(--handled-soft); }
        .details { padding: 4px 12px 14px; }
        .details[hidden] { display: none; }
        .exception { margin-top: 10px; }
        .exception + .exception { border-top: 1px dashed var(--line); padding-top: 10px; }
        .caused { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        .class { font-weight: 700; word-break: break-all; }
        .message { margin: 4px 0 6px; padding: 8px 10px; background: var(--accent-soft); border-radius: 6px;
            white-space: pre-wrap; word-break: break-word; }
        .loc, .loc a { font: 12px/1.4 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; color: var(--muted); word-break: break-all; }
        .loc a { color: inherit; text-decoration: underline; }
        pre { margin: 6px 0 0; padding: 8px 0; background: var(--soft); border-radius: 6px; overflow: auto;
            font: 12px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        pre span { display: block; padding: 0 10px; white-space: pre; }
        pre span.hl { background: var(--code-hl); }
        pre i { font-style: normal; color: var(--muted); display: inline-block; min-width: 4ch; margin-right: 1.5ch; text-align: right; user-select: none; }
        .frames { margin: 10px 0 0; padding: 0; list-style: none; }
        .frames li { padding: 4px 0; border-top: 1px solid var(--line); }
        .frames li.vendor { opacity: .7; }
        .frames li.vendor[hidden] { display: none; }
        .call { font: 12px/1.4 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; word-break: break-all; }
        .toggle { margin-top: 6px; background: none; border: 0; padding: 0; color: var(--muted); text-decoration: underline; font-size: 12px; }
    `;

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }
        return node;
    }

    function locationLine(file, line) {
        const label = file ? file + (line ? ':' + line : '') : '[internal function]';
        if (!file || !editorTemplate) {
            return el('div', 'loc', label);
        }
        const wrapper = el('div', 'loc');
        const link = el('a', '', label);
        link.href = editorTemplate.replace('{file}', encodeURI(file)).replace('{line}', String(line || 1));
        wrapper.append(link);
        return wrapper;
    }

    function source(excerpt) {
        const pre = el('pre');
        excerpt.lines.forEach((text, index) => {
            const number = excerpt.start + index;
            const row = el('span', number === excerpt.highlight ? 'hl' : '');
            row.append(el('i', '', number), document.createTextNode(text));
            pre.append(row);
        });
        return pre;
    }

    function exceptionBlock(entry, index) {
        const block = el('section', 'exception');
        if (index > 0) {
            block.append(el('div', 'caused', 'Caused by'));
        }
        block.append(el('div', 'class', entry.class));
        if (entry.message) {
            block.append(el('div', 'message', entry.message));
        }
        block.append(locationLine(entry.file, entry.line));
        if (entry.source) {
            block.append(source(entry.source));
        }

        const frames = Array.isArray(entry.frames) ? entry.frames : [];
        const list = el('ol', 'frames');
        let hiddenCount = 0;
        frames.forEach((frame, frameIndex) => {
            if (frameIndex === 0) {
                return; // The throw site, already shown above.
            }
            const item = el('li', frame.vendor ? 'vendor' : '');
            item.append(el('div', 'call', frame.call), locationLine(frame.file, frame.line));
            if (frame.source) {
                item.append(source(frame.source));
            }
            if (frame.vendor) {
                item.hidden = true;
                hiddenCount++;
            }
            list.append(item);
        });
        if (list.childElementCount > 0) {
            block.append(list);
        }
        if (hiddenCount > 0) {
            const toggle = el('button', 'toggle', 'Show ' + hiddenCount + ' library frame' + (hiddenCount === 1 ? '' : 's'));
            toggle.type = 'button';
            toggle.addEventListener('click', () => {
                const show = toggle.dataset.shown !== '1';
                toggle.dataset.shown = show ? '1' : '';
                list.querySelectorAll('li.vendor').forEach((item) => { item.hidden = !show; });
                toggle.textContent = (show ? 'Hide ' : 'Show ') + hiddenCount + ' library frame' + (hiddenCount === 1 ? '' : 's');
            });
            block.append(toggle);
        }
        return block;
    }

    function displayPath(uri) {
        try {
            const url = new URL(uri, window.location.href);
            return url.pathname + url.search;
        } catch (e) {
            return String(uri || '');
        }
    }

    function handledGroup(handled) {
        const group = el('section', 'group');
        const title = el('div', 'group-title');
        title.append(el('span', 'tag', 'Handled'));
        if (handled.note) {
            title.append(el('span', 'note', handled.note));
        }
        group.append(title);
        (handled.exceptions || []).forEach((entry, index) => group.append(exceptionBlock(entry, index)));
        return group;
    }

    function reportItem(report) {
        const item = el('div', 'item');
        const summary = el('button', 'summary');
        summary.type = 'button';
        const details = el('div', 'details');
        details.hidden = true;

        if (report.unavailable) {
            summary.append(el('span', 'status', '?'), el('span', 'where', 'Report ' + report.id), el('span', 'when', ''));
            summary.append(el('span', 'what', 'The report could not be loaded (' + report.unavailable + ').'));
        } else {
            const caught = Array.isArray(report.exceptions) ? report.exceptions : [];
            const handled = Array.isArray(report.handled) ? report.handled : [];
            const firstHandled = (handled[0] && handled[0].exceptions && handled[0].exceptions[0]) || null;
            const first = caught[0] || firstHandled || {class: 'Exception', message: ''};
            const when = report.time ? new Date(report.time).toLocaleTimeString() : '';
            summary.append(
                el('span', caught.length === 0 ? 'status handled' : 'status', report.status),
                el('span', 'where', (report.request ? report.request.method + ' ' + displayPath(report.request.uri) : '')),
                el('span', 'when', when),
            );
            const what = el('span', 'what');
            if (caught.length === 0) {
                what.append(el('span', 'tag', handled.length === 1 ? 'Handled' : handled.length + ' handled'));
            }
            what.append(el('b', '', first.class.split('\\').pop()), document.createTextNode(first.message ? ': ' + first.message : ''));
            if (caught.length > 0 && handled.length > 0) {
                what.append(document.createTextNode(' (+' + handled.length + ' handled)'));
            }
            summary.append(what);
            caught.forEach((entry, index) => details.append(exceptionBlock(entry, index)));
            handled.forEach((entry) => details.append(handledGroup(entry)));
        }

        summary.setAttribute('aria-expanded', 'false');
        summary.addEventListener('click', () => {
            details.hidden = !details.hidden;
            summary.setAttribute('aria-expanded', details.hidden ? 'false' : 'true');
        });
        item.append(summary, details);
        return item;
    }

    const host = document.createElement('div');
    host.setAttribute('data-quiote-overlay-root', '');
    const root = host.attachShadow({mode: 'open'});
    if ('adoptedStyleSheets' in root && typeof CSSStyleSheet === 'function' && 'replaceSync' in CSSStyleSheet.prototype) {
        const sheet = new CSSStyleSheet();
        sheet.replaceSync(CSS);
        root.adoptedStyleSheets = [sheet];
    } else {
        root.append(el('style', '', CSS));
    }

    const panel = el('div', 'panel');
    panel.hidden = true;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Quiote exceptions');
    const heading = el('h2', '', '');
    const clear = el('button', '', 'Clear');
    clear.type = 'button';
    const close = el('button', '', 'Close');
    close.type = 'button';
    const header = el('header');
    header.append(heading, clear, close);
    const list = el('div', 'list');
    panel.append(header, list);

    const badge = el('button', 'badge', 'Q');
    badge.type = 'button';
    const count = el('span', 'count');
    count.hidden = true;
    badge.append(count);

    root.append(panel, badge);

    function render() {
        heading.textContent = reports.length === 0
            ? 'Quiote · no exceptions'
            : 'Quiote · ' + reports.length + ' exception' + (reports.length === 1 ? '' : 's');
        badge.classList.toggle('has-errors', reports.length > 0);
        badge.setAttribute('aria-label', heading.textContent + (open ? '' : ', open the developer overlay'));
        count.hidden = unseen === 0;
        count.textContent = String(unseen);
        if (list.childElementCount === 0 && reports.length === 0) {
            list.append(el('div', 'empty', 'Exceptions from this page and its fetch()/XHR requests will show up here.'));
        }
    }

    function add(report) {
        if (reports.length === 0) {
            list.replaceChildren();
        }
        reports.unshift(report);
        list.prepend(reportItem(report));
        if (!open) {
            unseen++;
        }
        badge.classList.remove('pulse');
        void badge.offsetWidth; // Restart the animation for a report arriving mid-pulse.
        badge.classList.add('pulse');
        render();
    }

    function setOpen(value) {
        open = value;
        panel.hidden = !open;
        if (open) {
            unseen = 0;
        }
        render();
    }

    badge.addEventListener('click', () => setOpen(!open));
    close.addEventListener('click', () => setOpen(false));
    clear.addEventListener('click', () => {
        reports.length = 0;
        unseen = 0;
        list.replaceChildren();
        render();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && open) {
            setOpen(false);
        }
    });

    render();
    (document.body || document.documentElement).append(host);

    if (settings.exception) {
        inspect(settings.exception, null);
    }
})();
