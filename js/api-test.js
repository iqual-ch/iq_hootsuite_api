/**
 * @file
 * JavaScript for the Hootsuite API Test Console.
 *
 * Provides interactive request building, AJAX execution, and
 * formatted response display with JSON syntax highlighting.
 */
(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.iqHootsuiteApiTest = {
    attach: function (context) {
      var config = drupalSettings.iqHootsuiteApiTest;
      if (!config) {
        return;
      }

      var console = context.querySelector('.api-test-console');
      if (!console || console.dataset.initialized) {
        return;
      }
      console.dataset.initialized = 'true';

      // DOM references.
      var methodSelect = document.getElementById('api-method');
      var urlInput = document.getElementById('api-url');
      var queryTextarea = document.getElementById('api-query');
      var bodyTextarea = document.getElementById('api-body');
      var headersTextarea = document.getElementById('api-headers');
      var useAuthCheckbox = document.getElementById('api-use-auth');
      var bodyTypeRadios = document.querySelectorAll('input[name="body-type"]');
      var bodyHint = document.getElementById('body-hint');
      var executeBtn = document.getElementById('api-execute');
      var responseSection = document.getElementById('response-section');
      var responseStatus = document.getElementById('response-status');
      var responseTime = document.getElementById('response-time');
      var responseHeaders = document.getElementById('response-headers');
      var responseBody = document.getElementById('response-body');
      var responseRequest = document.getElementById('response-request');
      var loadingOverlay = document.getElementById('loading-overlay');

      // KV editors.
      var queryKvEditor = document.getElementById('query-kv-editor');
      var headersKvEditor = document.getElementById('headers-kv-editor');

      // -----------------------------------------------------------------
      // Panel tabs (Query / Body / Headers).
      // -----------------------------------------------------------------
      var panelTabs = document.querySelectorAll('.panel-tab');
      panelTabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
          panelTabs.forEach(function (t) { t.classList.remove('is-active'); });
          tab.classList.add('is-active');
          var panels = document.querySelectorAll('.panel-content');
          panels.forEach(function (p) { p.style.display = 'none'; });
          var target = document.getElementById('panel-' + tab.dataset.panel);
          if (target) {
            target.style.display = '';
          }
        });
      });

      // Response tabs (Body / Headers).
      var respTabs = document.querySelectorAll('.resp-tab');
      respTabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
          respTabs.forEach(function (t) { t.classList.remove('is-active'); });
          tab.classList.add('is-active');
          var panels = document.querySelectorAll('.resp-panel');
          panels.forEach(function (p) { p.style.display = 'none'; });
          var target = document.getElementById(tab.dataset.respPanel);
          if (target) {
            target.style.display = '';
          }
        });
      });

      // -----------------------------------------------------------------
      // Key-Value editor helpers.
      // -----------------------------------------------------------------
      function addKvRow(editor, key, value) {
        var template = editor.querySelector('.kv-template');
        var row = template.cloneNode(true);
        row.classList.remove('kv-template');
        row.style.display = '';
        if (key) {
          row.querySelector('.kv-key').value = key;
        }
        if (value) {
          row.querySelector('.kv-value').value = value;
        }
        row.querySelector('.kv-remove').addEventListener('click', function () {
          row.remove();
          syncKvToTextarea(editor);
        });
        // Sync on change.
        row.querySelector('.kv-key').addEventListener('input', function () {
          syncKvToTextarea(editor);
        });
        row.querySelector('.kv-value').addEventListener('input', function () {
          syncKvToTextarea(editor);
        });
        editor.appendChild(row);
      }

      function getKvData(editor) {
        var obj = {};
        var rows = editor.querySelectorAll('.kv-row:not(.kv-template)');
        rows.forEach(function (row) {
          var key = row.querySelector('.kv-key').value.trim();
          var value = row.querySelector('.kv-value').value;
          if (key) {
            obj[key] = value;
          }
        });
        return obj;
      }

      function setKvData(editor, obj) {
        // Remove existing rows (except template).
        var existing = editor.querySelectorAll('.kv-row:not(.kv-template)');
        existing.forEach(function (row) { row.remove(); });
        if (obj && typeof obj === 'object') {
          Object.keys(obj).forEach(function (key) {
            addKvRow(editor, key, obj[key]);
          });
        }
      }

      function syncKvToTextarea(editor) {
        var data = getKvData(editor);
        var textareaId = editor.id === 'query-kv-editor' ? 'api-query' : 'api-headers';
        var textarea = document.getElementById(textareaId);
        if (textarea && Object.keys(data).length > 0) {
          textarea.value = JSON.stringify(data, null, 2);
        }
        else if (textarea) {
          textarea.value = '';
        }
      }

      // "Add" buttons for KV editors.
      var addButtons = document.querySelectorAll('.kv-add');
      addButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
          var editor = document.getElementById(btn.dataset.target);
          if (editor) {
            addKvRow(editor, '', '');
          }
        });
      });

      // Sync textareas to KV editors on change.
      if (queryTextarea) {
        queryTextarea.addEventListener('blur', function () {
          try {
            var obj = JSON.parse(queryTextarea.value);
            setKvData(queryKvEditor, obj);
          }
          catch (e) {
            // Invalid JSON, ignore.
          }
        });
      }
      if (headersTextarea) {
        headersTextarea.addEventListener('blur', function () {
          try {
            var obj = JSON.parse(headersTextarea.value);
            setKvData(headersKvEditor, obj);
          }
          catch (e) {
            // Invalid JSON, ignore.
          }
        });
      }

      // -----------------------------------------------------------------
      // Preset buttons.
      // -----------------------------------------------------------------
      var presetBtns = document.querySelectorAll('.preset-btn');
      presetBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
          var index = parseInt(btn.dataset.index, 10);

          // Remove active class from all.
          presetBtns.forEach(function (b) { b.classList.remove('is-active'); });
          btn.classList.add('is-active');

          if (index === -1) {
            // Custom — clear fields.
            methodSelect.value = 'GET';
            urlInput.value = config.baseUrl + '/';
            setKvData(queryKvEditor, {});
            queryTextarea.value = '';
            bodyTextarea.value = '';
            setKvData(headersKvEditor, {});
            headersTextarea.value = '';
            urlInput.focus();
            return;
          }

          var preset = config.presets[index];
          if (!preset) {
            return;
          }

          methodSelect.value = preset.method;
          urlInput.value = preset.url;

          // Query params.
          if (preset.query) {
            queryTextarea.value = JSON.stringify(preset.query, null, 2);
            setKvData(queryKvEditor, preset.query);
          }
          else {
            queryTextarea.value = '';
            setKvData(queryKvEditor, {});
          }

          // Body.
          if (preset.body) {
            bodyTextarea.value = JSON.stringify(preset.body, null, 2);
          }
          else {
            bodyTextarea.value = '';
          }

          // Body type (json or form).
          setBodyType(preset.body_type || 'json');

          // Custom headers.
          if (preset.headers) {
            headersTextarea.value = JSON.stringify(preset.headers, null, 2);
            setKvData(headersKvEditor, preset.headers);
          }
          else {
            headersTextarea.value = '';
            setKvData(headersKvEditor, {});
          }

          // Auth checkbox.
          if (preset.use_auth !== undefined) {
            useAuthCheckbox.checked = preset.use_auth;
          }
          else {
            useAuthCheckbox.checked = true;
          }

          // Auto-switch to body tab for POST/PUT/PATCH.
          if (['POST', 'PUT', 'PATCH'].indexOf(preset.method) !== -1) {
            activatePanel('body');
          }
          else {
            activatePanel('query');
          }
        });
      });

      function activatePanel(panelName) {
        panelTabs.forEach(function (t) {
          t.classList.toggle('is-active', t.dataset.panel === panelName);
        });
        var panels = document.querySelectorAll('.panel-content');
        panels.forEach(function (p) {
          p.style.display = p.id === 'panel-' + panelName ? '' : 'none';
        });
      }

      function getBodyType() {
        var checked = document.querySelector('input[name="body-type"]:checked');
        return checked ? checked.value : 'json';
      }

      function setBodyType(type) {
        bodyTypeRadios.forEach(function (r) {
          r.checked = r.value === type;
        });
        updateBodyHint();
      }

      function updateBodyHint() {
        if (bodyHint) {
          bodyHint.textContent = getBodyType() === 'form'
            ? 'Key/value pairs sent as application/x-www-form-urlencoded.'
            : 'JSON body for POST, PUT, and PATCH requests.';
        }
      }

      bodyTypeRadios.forEach(function (r) {
        r.addEventListener('change', updateBodyHint);
      });

      // -----------------------------------------------------------------
      // Execute request.
      // -----------------------------------------------------------------
      executeBtn.addEventListener('click', executeRequest);

      // Ctrl+Enter shortcut.
      document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
          e.preventDefault();
          executeRequest();
        }
      });

      function executeRequest() {
        var url = urlInput.value.trim();
        if (!url) {
          urlInput.focus();
          return;
        }

        // Parse query from KV editor or textarea.
        var query = null;
        var queryData = getKvData(queryKvEditor);
        if (Object.keys(queryData).length > 0) {
          query = queryData;
        }
        else if (queryTextarea.value.trim()) {
          try {
            query = JSON.parse(queryTextarea.value);
          }
          catch (e) {
            showError('Invalid JSON in Query Parameters: ' + e.message);
            return;
          }
        }

        // Parse body.
        var body = null;
        if (bodyTextarea.value.trim()) {
          try {
            body = JSON.parse(bodyTextarea.value);
          }
          catch (e) {
            showError('Invalid JSON in Request Body: ' + e.message);
            return;
          }
        }

        // Parse headers from KV editor or textarea.
        var headers = {};
        var headerData = getKvData(headersKvEditor);
        if (Object.keys(headerData).length > 0) {
          headers = headerData;
        }
        else if (headersTextarea.value.trim()) {
          try {
            headers = JSON.parse(headersTextarea.value);
          }
          catch (e) {
            showError('Invalid JSON in Custom Headers: ' + e.message);
            return;
          }
        }

        var payload = {
          method: methodSelect.value,
          url: url,
          query: query,
          body: body,
          body_type: getBodyType(),
          headers: headers,
          use_auth: useAuthCheckbox.checked
        };

        // Show loading state.
        executeBtn.disabled = true;
        executeBtn.textContent = 'Executing...';
        loadingOverlay.style.display = 'flex';

        fetch(config.executeUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': config.csrfToken
          },
          body: JSON.stringify(payload)
        })
        .then(function (response) {
          return response.json();
        })
        .then(function (result) {
          displayResponse(result);
        })
        .catch(function (e) {
          displayResponse({
            error: 'Failed to execute request: ' + e.message,
            status_code: 0,
            time_ms: 0,
            headers: {},
            body: null,
            body_raw: '',
            is_json: false
          });
        })
        .finally(function () {
          executeBtn.disabled = false;
          executeBtn.textContent = 'Execute';
          loadingOverlay.style.display = 'none';
        });
      }

      function showError(message) {
        displayResponse({
          error: message,
          status_code: 0,
          reason: 'Client Error',
          time_ms: 0,
          headers: {},
          body: null,
          body_raw: '',
          is_json: false
        });
      }

      // -----------------------------------------------------------------
      // Display response.
      // -----------------------------------------------------------------
      function displayResponse(result) {
        responseSection.style.display = '';

        // Scroll to response.
        responseSection.scrollIntoView({ behavior: 'smooth', block: 'start' });

        // Reset to body tab.
        respTabs.forEach(function (t) {
          t.classList.toggle('is-active', t.dataset.respPanel === 'resp-body');
        });
        document.querySelectorAll('.resp-panel').forEach(function (p) {
          p.style.display = p.id === 'resp-body' ? '' : 'none';
        });

        // Status badge.
        if (result.error && result.status_code === 0) {
          responseStatus.innerHTML = '<span class="status-badge status-error">ERROR</span>';
          responseStatus.title = result.error;
        }
        else {
          var code = result.status_code;
          var cls = code < 300 ? 'status-success' : code < 400 ? 'status-redirect' : 'status-error';
          responseStatus.innerHTML = '<span class="status-badge ' + cls + '">' + code + ' ' + (result.reason || '') + '</span>';
        }

        // Timing.
        responseTime.textContent = result.time_ms ? result.time_ms + ' ms' : '';

        // Response headers.
        if (result.headers && Object.keys(result.headers).length > 0) {
          responseHeaders.innerHTML = syntaxHighlightJson(JSON.stringify(result.headers, null, 2));
        }
        else {
          responseHeaders.textContent = '(no headers)';
        }

        // Response body.
        if (result.error && result.status_code === 0) {
          responseBody.innerHTML = '';
          responseBody.textContent = result.error;
        }
        else if (result.is_json && result.body !== null) {
          responseBody.innerHTML = syntaxHighlightJson(JSON.stringify(result.body, null, 2));
        }
        else if (result.body_raw) {
          responseBody.innerHTML = '';
          responseBody.textContent = result.body_raw;
        }
        else {
          responseBody.innerHTML = '<span class="json-null">(empty response)</span>';
        }

        // Request sent.
        if (result.request) {
          var reqDisplay = result.request.method + ' ' + result.request.url + '\n\n';
          reqDisplay += '--- Headers ---\n';
          if (result.request.headers && typeof result.request.headers === 'object') {
            Object.keys(result.request.headers).forEach(function (key) {
              var val = result.request.headers[key];
              // Mask Bearer token for readability (show first/last 6 chars).
              if (key === 'Authorization' && typeof val === 'string' && val.indexOf('Bearer ') === 0) {
                var token = val.substring(7);
                if (token.length > 12) {
                  val = 'Bearer ' + token.substring(0, 6) + '...' + token.substring(token.length - 6);
                }
              }
              reqDisplay += key + ': ' + val + '\n';
            });
          }
          if (result.request.body) {
            var bodyLabel = result.request.body_type === 'form' ? '--- Body (form-urlencoded) ---' : '--- Body (JSON) ---';
            reqDisplay += '\n' + bodyLabel + '\n';
            try {
              var parsed = typeof result.request.body === 'string' ? JSON.parse(result.request.body) : result.request.body;
              reqDisplay += JSON.stringify(parsed, null, 2);
            }
            catch (e) {
              reqDisplay += result.request.body;
            }
          }
          responseRequest.innerHTML = '';
          responseRequest.textContent = reqDisplay;
        }
        else {
          responseRequest.innerHTML = '<span class="json-null">(no request data)</span>';
        }
      }

      // -----------------------------------------------------------------
      // JSON syntax highlighting.
      // -----------------------------------------------------------------
      function syntaxHighlightJson(json) {
        json = json.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        return json.replace(
          /("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g,
          function (match) {
            var cls = 'json-number';
            if (/^"/.test(match)) {
              if (/:$/.test(match)) {
                cls = 'json-key';
              }
              else {
                cls = 'json-string';
              }
            }
            else if (/true|false/.test(match)) {
              cls = 'json-boolean';
            }
            else if (/null/.test(match)) {
              cls = 'json-null';
            }
            return '<span class="' + cls + '">' + match + '</span>';
          }
        );
      }

      // -----------------------------------------------------------------
      // Copy buttons.
      // -----------------------------------------------------------------
      var copyBodyBtn = document.getElementById('copy-body');
      var copyHeadersBtn = document.getElementById('copy-headers');
      var copyRequestBtn = document.getElementById('copy-request');

      if (copyBodyBtn) {
        copyBodyBtn.addEventListener('click', function () {
          copyToClipboard(responseBody.textContent, copyBodyBtn);
        });
      }
      if (copyHeadersBtn) {
        copyHeadersBtn.addEventListener('click', function () {
          copyToClipboard(responseHeaders.textContent, copyHeadersBtn);
        });
      }
      if (copyRequestBtn) {
        copyRequestBtn.addEventListener('click', function () {
          copyToClipboard(responseRequest.textContent, copyRequestBtn);
        });
      }

      function copyToClipboard(text, btn) {
        navigator.clipboard.writeText(text).then(function () {
          var original = btn.textContent;
          btn.textContent = 'Copied!';
          setTimeout(function () { btn.textContent = original; }, 1500);
        });
      }
    }
  };

})(Drupal, drupalSettings);
