/**
 * WebMCP Initializer — CSP-safe external script.
 *
 * Reads configuration from data-webmcp-config attribute (JSON),
 * then registers tools with document.modelContext and/or the WebMCP
 * bridge widget. Zero inline JavaScript required.
 */
(function () {
  var el = document.getElementById('webmcp-init');
  if (!el) return;
  var raw = el.getAttribute('data-webmcp-config');
  if (!raw) return;
  var cfg;
  try { cfg = JSON.parse(raw); } catch { return; }

  var api = cfg.api || '';
  var nonce = cfg.nonce || '';
  var tools = cfg.tools || [];
  var contexts = cfg.contexts || [];
  var consent = cfg.consent || {};
  var bridge = cfg.bridge || {};
  var debug = !!cfg.debug;

  // WebMCP tool name regex per W3C Community Draft (2026-07-21):
  // 1-128 chars, ASCII alphanumerics + U+005F (_), U+002D (-), U+002E (.).
  // Defensive client-side check — backend already validates, but invalid
  // names would otherwise hit InvalidStateError on registerTool() and the
  // tool would silently disappear from the discoverable set.
  var TOOL_NAME_RX = /^[A-Za-z0-9_.-]{1,128}$/;

  function warn(message, error) {
    if (debug && typeof console !== 'undefined' && console.warn) {
      console.warn('[webmcp] ' + message, error);
    }
  }

  function exec(name, input) {
    return fetch(api + '/' + encodeURIComponent(name), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WebMcp-Nonce': nonce },
      body: JSON.stringify(input)
    }).then(function (r) {
      if (!r.ok) throw new Error(name + ': ' + r.status);
      return r.json();
    });
  }

  // Derived tool name -> context data. Contexts are already in this payload,
  // so `get-*` tools answer locally instead of round-tripping to the API.
  // That also sidesteps the API middleware running before page-resolver, where
  // page-scoped contexts could not be rebuilt server-side at all.
  var contextData = {};
  for (var c = 0; c < contexts.length; c++) {
    if (contexts[c] && contexts[c].name) {
      contextData['get-' + contexts[c].name] = contexts[c].data;
    }
  }

  function run(name, input) {
    if (Object.prototype.hasOwnProperty.call(contextData, name)) {
      return Promise.resolve({ success: true, data: contextData[name] });
    }
    return exec(name, input);
  }

  function allowed(name) {
    var reqs = consent.requirements || {};
    var req = reqs[name];
    if (!req || !req.required) return true;
    var cat = req.category;
    switch (consent.provider) {
      case 'klaro':
        return typeof klaro !== 'undefined' && klaro.getManager && klaro.getManager().getConsent(cat);
      case 'cookiebot':
        return typeof Cookiebot !== 'undefined' && Cookiebot.consent && Cookiebot.consent[cat];
      default:
        return window.__webmcp_consent && window.__webmcp_consent[cat];
    }
  }

  // Native WebMCP entry point. The 2026-07-21 Community Draft moved the
  // ModelContext from navigator to document; navigator stays as fallback for
  // Chrome 149, which shipped the origin trial with the old location.
  // Contexts are no longer pushed via provideContext() (dropped from the IDL) —
  // the server exposes them as read-only `get-*` tools instead, so they arrive
  // here as regular entries in `tools`.
  var mc = document.modelContext || navigator.modelContext;

  if (mc && typeof mc.registerTool === 'function') {
    // name -> AbortController. Aborting a signal unregisters the tool; this is
    // the only path to honour a consent withdrawal at runtime.
    var controllers = {};

    var register = function (t) {
      if (controllers[t.name]) return;
      var ann = t.annotations || {};
      var manifest = {
        name: t.name,
        description: t.description,
        inputSchema: t.inputSchema,
        // ToolExecuteCallback = Promise<any> (object input) — a single argument
        // per the IDL. There is no second `client` parameter.
        execute: function (input) { return run(t.name, input); },
        annotations: {
          readOnlyHint: ann.readOnlyHint !== false,
          untrustedContentHint: !!ann.untrustedContentHint
        }
      };
      if (t.title) manifest.title = t.title;

      var controller = new AbortController();
      controllers[t.name] = controller;

      // registerTool() returns Promise<undefined> and rejects with e.g.
      // InvalidStateError on a name collision — without this catch that would
      // surface only as an unhandled rejection.
      try {
        Promise.resolve(mc.registerTool(manifest, { signal: controller.signal }))
          .catch(function (e) {
            delete controllers[t.name];
            warn('registerTool rejected for "' + t.name + '"', e);
          });
      } catch (e) {
        delete controllers[t.name];
        warn('registerTool threw for "' + t.name + '"', e);
      }
    };

    var unregister = function (name) {
      var controller = controllers[name];
      if (!controller) return;
      delete controllers[name];
      controller.abort();
    };

    var reevaluate = function () {
      for (var n = 0; n < tools.length; n++) {
        var t = tools[n];
        if (!t || !TOOL_NAME_RX.test(t.name)) continue;
        if (allowed(t.name)) {
          register(t);
        } else {
          unregister(t.name);
        }
      }
    };

    reevaluate();

    // Consent can change after page load — re-run registration so newly
    // granted tools appear and withdrawn ones get aborted.
    if (consent.provider === 'klaro') {
      if (typeof klaro !== 'undefined' && klaro.getManager) {
        klaro.getManager().watch({ update: reevaluate });
      }
    } else if (consent.provider === 'cookiebot') {
      window.addEventListener('CookiebotOnConsentReady', reevaluate);
      window.addEventListener('CookiebotOnAccept', reevaluate);
      window.addEventListener('CookiebotOnDecline', reevaluate);
    } else {
      // Native provider: the site updates window.__webmcp_consent and then
      // dispatches this event (see README).
      window.addEventListener('webmcp:consent-changed', reevaluate);
    }
  }

  // WebMCP bridge widget
  if (bridge.enabled && typeof WebMCP !== 'undefined') {
    var W = new WebMCP(bridge.widget || {});
    // Reachable as window.webmcp so a site running the widget opt-in can
    // reveal the trigger on demand: window.webmcp.showWidget().
    window.webmcp = W;
    for (var k = 0; k < tools.length; k++) {
      (function (t) {
        if (!t || !TOOL_NAME_RX.test(t.name)) return;
        if (!allowed(t.name)) return;
        W.registerTool(t.name, t.description, t.inputSchema, function (input) {
          return run(t.name, input);
        });
      })(tools[k]);
    }
    for (var m = 0; m < contexts.length; m++) {
      (function (c) {
        W.registerResource(c.name, c.description, { uri: 'webmcp://context/' + c.name }, function () { return c.data; });
      })(contexts[m]);
    }
    var trigger = document.querySelector('[data-webmcp-widget] .webmcp-trigger');
    if (trigger) {
      var img = document.createElement('img');
      img.src = "data:image/svg+xml," + encodeURIComponent('<svg fill="white" fill-rule="evenodd" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M15.688 2.343a2.588 2.588 0 00-3.61 0l-9.626 9.44a.863.863 0 01-1.203 0 .823.823 0 010-1.18l9.626-9.44a4.313 4.313 0 016.016 0 4.116 4.116 0 011.204 3.54 4.3 4.3 0 013.609 1.18l.05.05a4.115 4.115 0 010 5.9l-8.706 8.537a.274.274 0 000 .393l1.788 1.754a.823.823 0 010 1.18.863.863 0 01-1.203 0l-1.788-1.753a1.92 1.92 0 010-2.754l8.706-8.538a2.47 2.47 0 000-3.54l-.05-.049a2.588 2.588 0 00-3.607-.003l-7.172 7.034-.002.002-.098.097a.863.863 0 01-1.204 0 .823.823 0 010-1.18l7.273-7.133a2.47 2.47 0 00-.003-3.537z"/><path d="M14.485 4.703a.823.823 0 000-1.18.863.863 0 00-1.204 0l-7.119 6.982a4.115 4.115 0 000 5.9 4.314 4.314 0 006.016 0l7.12-6.982a.823.823 0 000-1.18.863.863 0 00-1.204 0l-7.119 6.982a2.588 2.588 0 01-3.61 0 2.47 2.47 0 010-3.54l7.12-6.982z"/></svg>');
      img.alt = 'AI Access';
      img.width = 18;
      img.height = 18;
      trigger.innerHTML = '';
      trigger.appendChild(img);
      trigger.setAttribute('aria-label', 'AI Access');
    }
  }
})();
