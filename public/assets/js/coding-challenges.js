(() => {
  'use strict';

  const root = document.querySelector('[data-coding-editor]');
  if (!root) return;

  const frame = root.querySelector('[data-code-preview]');
  const runButton = root.querySelector('[data-run-preview]');
  const status = root.querySelector('[data-run-status]');
  const resultsBox = root.querySelector('[data-test-results]');
  const saveForm = root.querySelector('[data-attempt-form]');
  const saveButton = root.querySelector('[data-save-attempt]');
  const inputs = {
    html: root.querySelector('[data-code-html]'),
    css: root.querySelector('[data-code-css]'),
    js: root.querySelector('[data-code-js]'),
  };
  if (!frame || !runButton || !status || !resultsBox || !saveForm || !saveButton || Object.values(inputs).some((input) => !input)) return;

  let activeToken = '';
  let ready = false;
  let timeoutId = 0;
  let previewTimer = 0;
  let runId = 0;
  let checks = [];
  try {
    checks = JSON.parse(root.dataset.tests || '[]');
    if (!Array.isArray(checks) || checks.length > 20) checks = [];
  } catch (_) {
    checks = [];
  }

  const runner = `
    (() => {
      'use strict';
      const bootstrap = document.currentScript;
      const allowedCsp = document.createElement('meta');
      allowedCsp.httpEquiv = 'Content-Security-Policy';
      allowedCsp.content = "default-src 'none'; img-src data: blob:; media-src data: blob:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; font-src data:; connect-src 'none'; form-action 'none'; base-uri 'none'; object-src 'none'; frame-src 'none';";
      document.head.prepend(allowedCsp);
      const send = (message) => parent.postMessage(message, '*');
      send({ type: 'ready' });
      window.addEventListener('message', (event) => {
        if (event.source !== parent || !event.data || !['preview', 'run'].includes(event.data.type)) return;
        if (event.data.type === 'preview') {
          document.body.replaceChildren();
          document.head.querySelectorAll('[data-student-style], [data-student-script]').forEach((node) => node.remove());
          const parsed = new DOMParser().parseFromString(String(event.data.html || ''), 'text/html');
          parsed.querySelectorAll('script, iframe, object, embed, base, meta[http-equiv="refresh"]').forEach((node) => node.remove());
          parsed.querySelectorAll('*').forEach((node) => {
            [...node.attributes].forEach((attribute) => {
              if (/^on/i.test(attribute.name) || /^(href|src|xlink:href|action|formaction)$/i.test(attribute.name) && /^\s*javascript:/i.test(attribute.value)) {
                node.removeAttribute(attribute.name);
              }
            });
          });
          const content = document.createElement('div');
          content.setAttribute('data-student-content', '');
          content.append(...parsed.body.childNodes);
          document.body.append(content);
          const style = document.createElement('style');
          style.dataset.studentStyle = '';
          style.textContent = String(event.data.css || '');
          document.head.append(style);
          return;
        }
        const { token, id, html, css, js, tests } = event.data;
        if (typeof token !== 'string' || !Array.isArray(tests) || tests.length > 20) return;
        document.body.replaceChildren();
        document.head.querySelectorAll('[data-student-style], [data-student-script]').forEach((node) => node.remove());
        const content = document.createElement('div');
        content.setAttribute('data-student-content', '');
        content.innerHTML = String(html || '');
        document.body.append(content);
        const style = document.createElement('style');
        style.dataset.studentStyle = '';
        style.textContent = String(css || '');
        document.head.append(style);
        const script = document.createElement('script');
        script.dataset.studentScript = '';
        script.textContent = String(js || '');
        document.body.append(script);
        window.setTimeout(() => {
          const outcomes = tests.map((test) => {
            let passed = false;
            try {
              const node = document.querySelector(test.selector);
              switch (test.assertion) {
                case 'exists': passed = Boolean(node); break;
                case 'text_contains': passed = Boolean(node && node.textContent.includes(test.expected)); break;
                case 'attribute_equals': passed = Boolean(node && node.getAttribute(test.attribute) === test.expected); break;
                case 'style_equals': passed = Boolean(node && getComputedStyle(node).getPropertyValue(test.property).trim() === test.expected); break;
                default: passed = false;
              }
            } catch (_) { passed = false; }
            return { name: String(test.name || 'Check').slice(0, 160), passed };
          });
          send({ type: 'results', token, id, outcomes });
        }, 120);
      });
      void bootstrap;
    })();`;

  const frameDocument = `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data: blob:; media-src data: blob:; script-src 'unsafe-inline'; style-src 'unsafe-inline'; connect-src 'none'; form-action 'none'; base-uri 'none'; object-src 'none'; frame-src 'none';"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;padding:16px;font:16px/1.5 system-ui,sans-serif;color:#172033;background:#fff}*{box-sizing:border-box}</style><script>${runner}</script></head><body><p>Run preview to see your page.</p></body></html>`;
  frame.srcdoc = frameDocument;

  const setStatus = (message) => { status.textContent = message; };
  const renderOutcomes = (outcomes) => {
    resultsBox.replaceChildren();
    outcomes.forEach((outcome) => {
      const item = document.createElement('div');
      item.className = `coding-test-result ${outcome.passed ? 'is-pass' : 'is-fail'}`;
      const mark = document.createElement('span');
      mark.setAttribute('aria-hidden', 'true');
      mark.textContent = outcome.passed ? '✓' : '×';
      const label = document.createElement('span');
      label.textContent = outcome.name;
      const result = document.createElement('strong');
      result.textContent = outcome.passed ? 'Passed' : 'Needs work';
      item.append(mark, label, result);
      resultsBox.append(item);
    });
  };

  const invalidateResults = () => {
    // A result only describes the exact source snapshot that was run. Once the
    // learner edits any file, prevent saving that old result against new code.
    runId += 1;
    activeToken = '';
    window.clearTimeout(timeoutId);
    saveButton.disabled = true;
    resultsBox.replaceChildren();
    setStatus('Code changed. Run JavaScript and checks again before saving.');
  };

  const refreshPreview = () => {
    window.clearTimeout(previewTimer);
    previewTimer = window.setTimeout(() => {
      if (ready) frame.contentWindow.postMessage({ type: 'preview', html: inputs.html.value, css: inputs.css.value }, '*');
    }, 250);
  };
  Object.values(inputs).forEach((input) => input.addEventListener('input', () => {
    invalidateResults();
    refreshPreview();
  }));

  frame.addEventListener('load', () => {
    ready = true;
    setStatus('HTML and CSS preview updates as you edit. Run JavaScript and checks when ready.');
    refreshPreview();
  });

  window.addEventListener('message', (event) => {
    if (event.source !== frame.contentWindow || event.origin !== 'null' || !event.data || typeof event.data !== 'object') return;
    if (event.data.type === 'ready') {
      ready = true;
      return;
    }
    if (event.data.type !== 'results' || event.data.token !== activeToken || event.data.id !== runId || !Array.isArray(event.data.outcomes)) return;
    window.clearTimeout(timeoutId);
    const outcomes = event.data.outcomes.slice(0, checks.length).map((item, index) => ({
      name: checks[index]?.name || 'Check',
      passed: item?.passed === true,
    }));
    renderOutcomes(outcomes);
    const passed = outcomes.filter((item) => item.passed).length;
    const allPassed = outcomes.length === checks.length && outcomes.length > 0 && passed === outcomes.length;
    setStatus(`${passed} of ${outcomes.length} checks passed. ${allPassed ? 'All practice checks passed.' : 'Review the checks marked needs work.'}`);
    saveForm.elements.namedItem('html_code').value = inputs.html.value;
    saveForm.elements.namedItem('css_code').value = inputs.css.value;
    saveForm.elements.namedItem('js_code').value = inputs.js.value;
    saveForm.elements.namedItem('reported_status').value = allPassed ? 'pass' : 'fail';
    saveForm.elements.namedItem('test_results').value = JSON.stringify(outcomes);
    saveButton.disabled = false;
  });

  runButton.addEventListener('click', () => {
    if (!ready || checks.length === 0) {
      setStatus(checks.length === 0 ? 'This challenge has no valid checks yet.' : 'The preview is loading. Try again in a moment.');
      return;
    }
    window.clearTimeout(timeoutId);
    saveButton.disabled = true;
    resultsBox.replaceChildren();
    runId += 1;
    activeToken = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
    setStatus('Running your JavaScript and practice checks…');
    frame.contentWindow.postMessage({ type: 'run', token: activeToken, id: runId, html: inputs.html.value, css: inputs.css.value, js: inputs.js.value, tests: checks }, '*');
    const expectedRun = runId;
    timeoutId = window.setTimeout(() => {
      if (runId !== expectedRun) return;
      setStatus('The preview took too long. Check your JavaScript for a long-running loop, then try again.');
      saveButton.disabled = true;
    }, 3000);
  });

  saveForm.addEventListener('submit', (event) => {
    if (saveButton.disabled) event.preventDefault();
  });
})();
