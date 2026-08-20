(function () {
    'use strict';

    function qs(root, selector) { return root.querySelector(selector); }
    function qsa(root, selector) { return Array.prototype.slice.call(root.querySelectorAll(selector)); }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function bytesToMB(bytes) {
        return (bytes / 1024 / 1024).toFixed(1);
    }

    function initApp(app) {
        var dropzone = qs(app, '.pge-ai-dropzone');
        var input = qs(app, '.pge-ai-files');
        var fileList = qs(app, '.pge-ai-file-list');
        var command = qs(app, '.pge-ai-command');
        var run = qs(app, '.pge-ai-run');
        var reset = qs(app, '.pge-ai-reset');
        var zipButton = qs(app, '.pge-ai-zip');
        var status = qs(app, '.pge-ai-status');
        var results = qs(app, '.pge-ai-results');
        var files = [];
        var resultTokens = [];
        var objectUrls = [];

        function setStatus(text, type) {
            status.textContent = text || '';
            status.className = 'pge-ai-status' + (type ? ' is-' + type : '');
        }

        function clearObjectUrls() {
            objectUrls.forEach(function (url) {
                try { URL.revokeObjectURL(url); } catch (e) {}
            });
            objectUrls = [];
        }

        function updateFiles(newFiles) {
            clearObjectUrls();
            files = Array.prototype.slice.call(newFiles || []);
            if (files.length > Number(PGE_AI.maxFiles || 10)) {
                files = files.slice(0, Number(PGE_AI.maxFiles || 10));
                setStatus(PGE_AI.i18n.tooMany, 'error');
            }

            var maxBytes = Number(PGE_AI.maxMB || 10) * 1024 * 1024;
            files = files.filter(function (file) {
                return /^image\/(jpeg|png|webp)$/i.test(file.type) && file.size <= maxBytes;
            });

            fileList.innerHTML = '';
            files.forEach(function (file, index) {
                var item = document.createElement('div');
                item.className = 'pge-ai-file';
                var name = document.createElement('span');
                name.textContent = (index + 1) + '. ' + file.name;
                var meta = document.createElement('small');
                meta.textContent = bytesToMB(file.size) + ' MB';
                item.appendChild(name);
                item.appendChild(meta);
                fileList.appendChild(item);
            });

            if (files.length) {
                setStatus(files.length + ' image(s) ready. Enter a command and press Process Photos.', 'success');
            }
        }

        dropzone.addEventListener('click', function () { input.click(); });
        dropzone.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                input.click();
            }
        });
        input.addEventListener('change', function () { updateFiles(input.files); });

        ['dragenter', 'dragover'].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function (event) {
                event.preventDefault();
                dropzone.classList.add('is-dragging');
            });
        });
        ['dragleave', 'drop'].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function (event) {
                event.preventDefault();
                dropzone.classList.remove('is-dragging');
            });
        });
        dropzone.addEventListener('drop', function (event) {
            updateFiles(event.dataTransfer.files);
        });

        qsa(app, '.pge-ai-presets button').forEach(function (button) {
            button.addEventListener('click', function () {
                command.value = button.getAttribute('data-command') || '';
                command.focus();
            });
        });

        function addCompareImage(wrap, labelText, src, alt) {
            var box = document.createElement('div');
            box.className = 'pge-ai-compare-item';
            var label = document.createElement('b');
            label.textContent = labelText;
            var image = document.createElement('img');
            image.src = src;
            image.alt = alt;
            image.loading = 'lazy';
            box.appendChild(label);
            box.appendChild(image);
            wrap.appendChild(box);
        }

        function addResult(file, payload) {
            var card = document.createElement('article');
            card.className = 'pge-ai-result';

            var compare = document.createElement('div');
            compare.className = 'pge-ai-compare';
            var beforeUrl = URL.createObjectURL(file);
            objectUrls.push(beforeUrl);
            addCompareImage(compare, 'Before', beforeUrl, 'Original ' + file.name);
            addCompareImage(compare, 'Processed', payload.url, 'Processed ' + file.name);

            var body = document.createElement('div');
            body.className = 'pge-ai-result-body';

            var title = document.createElement('strong');
            title.textContent = payload.filename || file.name;

            var meta = document.createElement('span');
            var inputDimensions = payload.input_width && payload.input_height ? payload.input_width + 'x' + payload.input_height : '?';
            var outputDimensions = payload.width && payload.height ? payload.width + 'x' + payload.height : '?';
            meta.textContent = inputDimensions + ' → ' + outputDimensions + ' | ' + String(payload.format || '').toUpperCase() + (payload.engine ? ' | ' + payload.engine : '');

            var applied = document.createElement('div');
            applied.className = 'pge-ai-applied';
            applied.textContent = 'Applied: ' + (payload.operation_summary || 'local processing');

            if (payload.notice) {
                var notice = document.createElement('div');
                notice.className = 'pge-ai-result-notice';
                notice.textContent = payload.notice;
                body.appendChild(notice);
            }

            var link = document.createElement('a');
            link.href = payload.url;
            link.download = payload.filename || 'pge-photo';
            link.rel = 'noopener';
            link.textContent = 'Download Processed Photo';

            body.insertBefore(title, body.firstChild);
            body.appendChild(meta);
            body.appendChild(applied);
            body.appendChild(link);
            card.appendChild(compare);
            card.appendChild(body);
            results.appendChild(card);
        }

        function parseJsonResponse(response) {
            return response.text().then(function (text) {
                var json;
                try {
                    json = JSON.parse(text);
                } catch (e) {
                    throw new Error('Server returned an invalid response. Check WordPress/PHP error logs.');
                }
                if (!response.ok && (!json || !json.data)) {
                    throw new Error('Server error (' + response.status + ').');
                }
                return json;
            });
        }

        function processOne(file, index, total) {
            var data = new FormData();
            data.append('action', 'pge_process_image');
            data.append('nonce', PGE_AI.nonce);
            data.append('command', command.value || 'clear photo');
            data.append('image', file, file.name);

            setStatus(PGE_AI.i18n.processing + ' ' + (index + 1) + '/' + total + ': ' + file.name, 'working');

            return fetch(PGE_AI.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            }).then(parseJsonResponse).then(function (json) {
                if (!json || !json.success) {
                    throw new Error(json && json.data && json.data.message ? json.data.message : PGE_AI.i18n.failed);
                }
                addResult(file, json.data);
                if (json.data.token) {
                    resultTokens.push(json.data.token);
                }
                return true;
            });
        }

        run.addEventListener('click', function () {
            if (!files.length) {
                setStatus(PGE_AI.i18n.noFiles, 'error');
                return;
            }

            run.disabled = true;
            clearObjectUrls();
            results.innerHTML = '';
            resultTokens = [];
            zipButton.hidden = true;

            var chain = Promise.resolve();
            var failures = 0;
            files.forEach(function (file, index) {
                chain = chain.then(function () {
                    return processOne(file, index, files.length).catch(function (error) {
                        failures++;
                        var row = document.createElement('div');
                        row.className = 'pge-ai-error-row';
                        row.textContent = file.name + ': ' + error.message;
                        results.appendChild(row);
                    });
                });
            });

            chain.then(function () {
                run.disabled = false;
                if (resultTokens.length > 1) {
                    zipButton.hidden = false;
                }
                if (failures) {
                    setStatus((files.length - failures) + ' completed, ' + failures + ' failed.', 'error');
                } else {
                    setStatus(PGE_AI.i18n.done + ': ' + files.length + ' image(s). Compare Before vs Processed below.', 'success');
                }
            });
        });

        zipButton.addEventListener('click', function () {
            if (resultTokens.length < 2) { return; }
            var data = new FormData();
            data.append('action', 'pge_create_zip');
            data.append('nonce', PGE_AI.nonce);
            resultTokens.forEach(function (token) { data.append('tokens[]', token); });
            zipButton.disabled = true;
            setStatus('Creating ZIP...', 'working');

            fetch(PGE_AI.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
                .then(parseJsonResponse)
                .then(function (json) {
                    if (!json || !json.success) {
                        throw new Error(json && json.data && json.data.message ? json.data.message : 'ZIP failed');
                    }
                    var link = document.createElement('a');
                    link.href = json.data.url;
                    link.download = json.data.filename || 'pge-photos.zip';
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                    setStatus('ZIP ready.', 'success');
                })
                .catch(function (error) { setStatus(error.message, 'error'); })
                .finally(function () { zipButton.disabled = false; });
        });

        reset.addEventListener('click', function () {
            clearObjectUrls();
            files = [];
            resultTokens = [];
            input.value = '';
            command.value = '';
            fileList.innerHTML = '';
            results.innerHTML = '';
            zipButton.hidden = true;
            setStatus('');
        });

        initCvBuilder(app, escapeHtml);
    }

    function initCvBuilder(app, escapeHtml) {
        var button = qs(app, '.pge-cv-generate');
        if (!button) { return; }
        var photoInput = qs(app, '.pge-cv-photo');
        var photoData = '';

        photoInput.addEventListener('change', function () {
            var file = photoInput.files && photoInput.files[0];
            photoData = '';
            if (!file || !/^image\/(jpeg|png|webp)$/i.test(file.type)) { return; }
            var reader = new FileReader();
            reader.onload = function (event) {
                var value = String(event.target.result || '');
                if (/^data:image\/(jpeg|png|webp);base64,/i.test(value)) {
                    photoData = value;
                }
            };
            reader.readAsDataURL(file);
        });

        button.addEventListener('click', function () {
            var value = function (selector) { return qs(app, selector).value || ''; };
            var name = escapeHtml(value('.pge-cv-name'));
            var title = escapeHtml(value('.pge-cv-title'));
            var email = escapeHtml(value('.pge-cv-email'));
            var phone = escapeHtml(value('.pge-cv-phone'));
            var summary = escapeHtml(value('.pge-cv-summary')).replace(/\n/g, '<br>');
            var skills = value('.pge-cv-skills').split(/\n+/).filter(Boolean).map(function (s) { return '<li>' + escapeHtml(s) + '</li>'; }).join('');
            var experience = escapeHtml(value('.pge-cv-experience')).replace(/\n/g, '<br>');
            var education = escapeHtml(value('.pge-cv-education')).replace(/\n/g, '<br>');
            var photo = photoData ? '<img class="photo" src="' + photoData + '" alt="CV photo">' : '';

            var html = '<!doctype html><html><head><meta charset="utf-8"><title>' + (name || 'CV') + '</title>' +
                '<style>@page{size:A4;margin:14mm}*{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#1f2937;margin:0;font-size:12px;line-height:1.55}.top{display:grid;grid-template-columns:1fr auto;gap:20px;border-bottom:3px solid #111827;padding-bottom:14px}.photo{width:110px;height:110px;object-fit:cover;border-radius:8px}h1{font-size:28px;margin:0}h2{font-size:14px;text-transform:uppercase;letter-spacing:.08em;margin:18px 0 7px;border-bottom:1px solid #d1d5db;padding-bottom:4px}.title{font-size:15px;margin:3px 0}.contact{color:#4b5563}.skills{display:flex;flex-wrap:wrap;gap:6px;padding:0;list-style:none}.skills li{border:1px solid #d1d5db;border-radius:999px;padding:3px 8px}.section{break-inside:avoid}.print{position:fixed;right:12px;top:12px}@media print{.print{display:none}}</style></head><body>' +
                '<button class="print" onclick="window.print()">Print / Save PDF</button>' +
                '<div class="top"><div><h1>' + name + '</h1><div class="title">' + title + '</div><div class="contact">' + email + (email && phone ? ' | ' : '') + phone + '</div></div>' + photo + '</div>' +
                (summary ? '<div class="section"><h2>Profile</h2><div>' + summary + '</div></div>' : '') +
                (skills ? '<div class="section"><h2>Skills</h2><ul class="skills">' + skills + '</ul></div>' : '') +
                (experience ? '<div class="section"><h2>Experience</h2><div>' + experience + '</div></div>' : '') +
                (education ? '<div class="section"><h2>Education</h2><div>' + education + '</div></div>' : '') +
                '</body></html>';

            var win = window.open('', '_blank');
            if (!win) { return; }
            try { win.opener = null; } catch (e) {}
            win.document.open();
            win.document.write(html);
            win.document.close();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        qsa(document, '.pge-ai-app').forEach(initApp);
    });
}());
