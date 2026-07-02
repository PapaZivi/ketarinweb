(function () {
    var selectedId = null;
    var selectedRow = null;
    var selectedIds = new Set();
    var lastSelectedRow = null;
    var menu = document.getElementById('contextMenu');
    var table = document.getElementById('appTable');
    var bulkDeleteIds = document.getElementById('bulkDeleteIds');
    var bulkDeleteButton = document.getElementById('bulkDeleteButton');
    var bulkUpdateIds = document.getElementById('bulkUpdateIds');
    var bulkUpdateButton = document.getElementById('bulkUpdateButton');
    var selectionHint = document.getElementById('selectionHint');
    var menuButtons = document.querySelectorAll('.kw-menu [data-menu]');
    var menuPopups = document.querySelectorAll('.kw-menu-popup');
    var i18n = window.KW_I18N || {};

    function t(key, replace) {
        var text = i18n[key] || key;
        Object.keys(replace || {}).forEach(function (name) {
            text = text.replaceAll('{' + name + '}', replace[name]);
        });
        return text;
    }

    function closeWindowMenus() {
        menuButtons.forEach(function (button) { button.classList.remove('active'); });
        menuPopups.forEach(function (popup) { popup.hidden = true; });
    }

    function clearNativeTextSelection() {
        var selection = window.getSelection && window.getSelection();
        if (selection && selection.removeAllRanges) {
            selection.removeAllRanges();
        }
    }

    menuButtons.forEach(function (button) {
        button.addEventListener('click', function (event) {
            var popup = document.getElementById('menu-' + button.dataset.menu);
            var wasOpen = popup && !popup.hidden;
            closeWindowMenus();
            if (popup && !wasOpen) {
                var rect = button.getBoundingClientRect();
                var navRect = button.closest('.kw-menu').getBoundingClientRect();
                popup.style.left = (rect.left - navRect.left) + 'px';
                popup.hidden = false;
                button.classList.add('active');
            }
            event.stopPropagation();
        });
    });

    function updateSelectionUi() {
        clearNativeTextSelection();
        selectedId = selectedIds.size ? Array.from(selectedIds).pop() : null;
        selectedRow = selectedId ? table.querySelector('.kw-app-row[data-id="' + selectedId + '"]') : null;
        if (bulkDeleteIds) bulkDeleteIds.value = Array.from(selectedIds).join(',');
        if (bulkDeleteButton) bulkDeleteButton.disabled = selectedIds.size === 0;
        if (bulkUpdateIds) bulkUpdateIds.value = Array.from(selectedIds).join(',');
        if (bulkUpdateButton) bulkUpdateButton.disabled = selectedIds.size === 0;
        if (selectionHint) {
            selectionHint.textContent = selectedIds.size > 0
                ? t('hint.selection_count', {count: selectedIds.size})
                : t('hint.selection_default');
        }
        if (menu && selectedId) {
            menu.querySelector('input[name="id"]').value = selectedId;
            var commandButton = menu.querySelector('[data-command-action]');
            if (commandButton && selectedRow) {
                commandButton.disabled = selectedRow.dataset.command !== '1';
                commandButton.title = commandButton.disabled ? t('js.no_bash_script') : '';
            }
            var websiteLink = menu.querySelector('[data-website-action]');
            if (websiteLink && selectedRow) {
                var website = (selectedRow.dataset.website || '').trim();
                websiteLink.hidden = website === '';
                websiteLink.href = website && !/^[a-z][a-z0-9+.-]*:/i.test(website) ? 'https://' + website : website;
            }
        }
    }

    function clearSelection() {
        selectedIds.clear();
        table.querySelectorAll('.kw-app-row.selected').forEach(function (row) { row.classList.remove('selected'); });
        updateSelectionUi();
    }

    function selectAllRows() {
        if (!table) return;
        table.querySelectorAll('.kw-app-row').forEach(addRowToSelection);
        updateSelectionUi();
    }

    function addRowToSelection(row) {
        selectedIds.add(row.getAttribute('data-id'));
        row.classList.add('selected');
        lastSelectedRow = row;
    }

    function selectRow(row, event) {
        var rows = Array.from(table.querySelectorAll('.kw-app-row'));
        if (event && event.shiftKey && lastSelectedRow) {
            var start = rows.indexOf(lastSelectedRow);
            var end = rows.indexOf(row);
            if (start > -1 && end > -1) {
                if (!event.ctrlKey && !event.metaKey) clearSelection();
                rows.slice(Math.min(start, end), Math.max(start, end) + 1).forEach(addRowToSelection);
            }
        } else if (event && (event.ctrlKey || event.metaKey)) {
            var id = row.getAttribute('data-id');
            if (selectedIds.has(id)) {
                selectedIds.delete(id);
                row.classList.remove('selected');
            } else {
                addRowToSelection(row);
            }
        } else {
            clearSelection();
            addRowToSelection(row);
        }
        updateSelectionUi();
    }

    if (table) {
        setupResizableColumns(table);
        setupCellTooltips(table);
        setupProgressPolling(table);
        table.addEventListener('mousedown', function (event) {
            if (event.target.closest('.kw-app-row')) {
                event.preventDefault();
            }
        });
        table.addEventListener('click', function (event) {
            var row = event.target.closest('.kw-app-row');
            if (row) selectRow(row, event);
        });
        table.addEventListener('dblclick', function (event) {
            var row = event.target.closest('.kw-app-row');
            if (row) window.location = row.getAttribute('data-edit');
        });
        table.addEventListener('contextmenu', function (event) {
            var row = event.target.closest('.kw-app-row');
            if (!row || !menu) return;
            event.preventDefault();
            if (!selectedIds.has(row.getAttribute('data-id'))) {
                selectRow(row, event);
            }
            menu.style.display = 'block';
            positionContextMenu(menu, event.clientX, event.clientY);
        });
    }

    function positionContextMenu(menuEl, x, y) {
        menuEl.style.left = '0px';
        menuEl.style.top = '0px';
        var width = menuEl.offsetWidth;
        var height = menuEl.offsetHeight;
        var padding = 4;
        var left = Math.min(x, window.innerWidth - width - padding);
        var top = Math.min(y, window.innerHeight - height - padding);
        menuEl.style.left = Math.max(padding, left) + 'px';
        menuEl.style.top = Math.max(padding, top) + 'px';
    }

    function setupResizableColumns(tableEl) {
        var storageKey = 'ketarinweb.columnWidths';
        var saved = {};
        try {
            saved = JSON.parse(localStorage.getItem(storageKey) || '{}');
        } catch (ignore) {
            saved = {};
        }
        tableEl.querySelectorAll('thead th[data-col]').forEach(function (th) {
            var col = th.dataset.col;
            if (saved[col]) {
                th.style.width = saved[col] + 'px';
            }
            var handle = document.createElement('span');
            handle.className = 'kw-col-resizer';
            th.appendChild(handle);

            handle.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
            });
            handle.addEventListener('mousedown', function (event) {
                event.preventDefault();
                event.stopPropagation();
                var startX = event.clientX;
                var startWidth = th.offsetWidth;
                document.body.classList.add('kw-resizing');

                function onMove(moveEvent) {
                    var width = Math.max(50, startWidth + moveEvent.clientX - startX);
                    th.style.width = width + 'px';
                    saved[col] = width;
                }

                function onUp() {
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    document.body.classList.remove('kw-resizing');
                    localStorage.setItem(storageKey, JSON.stringify(saved));
                }

                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
            });
        });
    }

    function setupCellTooltips(tableEl) {
        function updateTooltip(cell) {
            var text = cell.textContent.trim();
            if (text && cell.scrollWidth > cell.clientWidth) {
                cell.title = text;
            } else {
                cell.removeAttribute('title');
            }
        }
        tableEl.addEventListener('mouseover', function (event) {
            var cell = event.target.closest('td, th');
            if (cell && tableEl.contains(cell)) updateTooltip(cell);
        });
    }

    function setupProgressPolling(tableEl) {
        var previousProgress = {};

        function formatBytes(bytes) {
            bytes = Number(bytes || 0);
            var units = ['B', 'KB', 'MB', 'GB', 'TB'];
            var index = 0;
            while (bytes >= 1024 && index < units.length - 1) {
                bytes /= 1024;
                index++;
            }
            return (index === 0 ? bytes.toFixed(0) : bytes.toFixed(1)) + ' ' + units[index];
        }

        function formatDuration(seconds) {
            seconds = Math.max(0, Math.round(seconds));
            var minutes = Math.floor(seconds / 60);
            var rest = seconds % 60;
            if (minutes >= 60) {
                var hours = Math.floor(minutes / 60);
                minutes = minutes % 60;
                return hours + ':' + String(minutes).padStart(2, '0') + ':' + String(rest).padStart(2, '0');
            }
            return minutes + ':' + String(rest).padStart(2, '0');
        }

        function progressMarkup(app) {
            var status = app.error || app.status || '';
            var bytes = Number(app.download_bytes || 0);
            var total = Number(app.download_total || 0);
            if (app.status !== 'downloading') {
                return status;
            }
            if (total > 0) {
                var percent = Math.max(0, Math.min(100, Math.round((bytes / total) * 100)));
                var previous = previousProgress[app.id];
                var eta = '';
                var now = Date.now();
                if (previous && bytes > previous.bytes) {
                    var speed = (bytes - previous.bytes) / ((now - previous.time) / 1000);
                    if (speed > 0) {
                        eta = ', about ' + formatDuration((total - bytes) / speed) + ' ' + t('js.remaining');
                    }
                }
                previousProgress[app.id] = {bytes: bytes, time: now};
                return '<div class="kw-progress-line"><span>' + t('js.download') + ' ' + percent + '% (' + formatBytes(bytes) + ' / ' + formatBytes(total) + eta + ')</span><div class="kw-progress-bar"><i style="width:' + percent + '%"></i></div></div>';
            }
            previousProgress[app.id] = {bytes: bytes, time: Date.now()};
            return t('js.download_running') + ' (' + formatBytes(bytes) + ')';
        }

        function updateRow(app) {
            var row = tableEl.querySelector('.kw-app-row[data-id="' + app.id + '"]');
            if (!row) return;
            var progressCell = row.querySelector('[data-progress-cell]');
            if (progressCell) {
                progressCell.innerHTML = progressMarkup(app);
                progressCell.removeAttribute('title');
            }
            var updatedCell = row.querySelector('[data-updated-cell]');
            if (updatedCell) {
                updatedCell.textContent = formatDateTime(app.last_updated || '');
            }
            var targetCell = row.querySelector('[data-target-cell]');
            if (targetCell) {
                targetCell.textContent = app.current_target_path || app.target_path || '';
                targetCell.removeAttribute('title');
            }
            var categoryCell = row.querySelector('[data-category-cell]');
            if (categoryCell) {
                categoryCell.textContent = app.category || '';
                categoryCell.removeAttribute('title');
            }
            var versionCell = row.querySelector('[data-version-cell]');
            if (versionCell) {
                versionCell.textContent = app.current_version || '';
                versionCell.removeAttribute('title');
            }
            var icon = row.querySelector('[data-status-icon]');
            if (icon) {
                var iconStatus = app.error ? 'error' : (app.status || '');
                icon.className = 'kw-status kw-status-' + String(iconStatus).replace(/[^A-Za-z0-9_-]+/g, '-');
            }
        }

        function formatDateTime(value) {
            if (!value) return '';
            var normalized = String(value).replace(' ', 'T');
            var date = new Date(normalized);
            if (Number.isNaN(date.getTime())) return value;
            return String(date.getDate()).padStart(2, '0') + '.'
                + String(date.getMonth() + 1).padStart(2, '0') + '.'
                + date.getFullYear() + ' '
                + String(date.getHours()).padStart(2, '0') + ':'
                + String(date.getMinutes()).padStart(2, '0');
        }

        function poll() {
            if (document.hidden) return;
            fetch('index.php?action=progress', {cache: 'no-store'})
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    (data.apps || []).forEach(updateRow);
                })
                .catch(function () {});
        }

        poll();
        window.setInterval(poll, 1500);
    }

    if (bulkDeleteButton) {
        bulkDeleteButton.addEventListener('click', function (event) {
            if (selectedIds.size === 0 || !window.confirm(t('confirm.delete_selected', {count: selectedIds.size}))) {
                event.preventDefault();
            }
        });
    }

    function setupBackgroundActions() {
        var asyncActions = new Set(['download', 'check', 'force', 'run_all', 'bulk_update', 'run_command']);
        function markRowsUpdating(action, data) {
            if (!table) return;
            var ids = [];
            if (['download', 'check', 'force'].includes(action)) {
                var id = data.get('id');
                if (id) ids.push(id);
            } else if (action === 'bulk_update') {
                ids = String(data.get('ids') || '').split(',').filter(Boolean);
            } else if (action === 'run_all') {
                ids = Array.from(table.querySelectorAll('.kw-app-row:not(.kw-app-disabled)')).map(function (row) {
                    return row.getAttribute('data-id');
                });
            }
            ids.forEach(function (id) {
                var row = table.querySelector('.kw-app-row[data-id="' + id + '"]');
                var progressCell = row ? row.querySelector('[data-progress-cell]') : null;
                if (progressCell) {
                    progressCell.textContent = t('js.updating');
                    progressCell.removeAttribute('title');
                }
            });
        }
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || !form.matches('form')) return;
            var submitter = event.submitter || document.activeElement;
            var action = '';
            var actionInput = form.querySelector('input[name="action"]');
            if (submitter && submitter.name === 'action') {
                action = submitter.value;
            } else if (actionInput) {
                action = actionInput.value;
            }
            if (!asyncActions.has(action)) return;

            event.preventDefault();
            if (menu) menu.style.display = 'none';
            if (selectionHint) selectionHint.textContent = t('hint.action_running');
            var data = new FormData(form);
            if (submitter && submitter.name && !data.has(submitter.name)) {
                data.append(submitter.name, submitter.value);
            }
            markRowsUpdating(action, data);
            fetch(form.getAttribute('action') || 'index.php', {
                method: form.method || 'POST',
                body: data,
                redirect: 'follow'
            }).then(function () {
                if (selectionHint) selectionHint.textContent = t('hint.action_completed');
            }).catch(function (error) {
                if (selectionHint) selectionHint.textContent = t('hint.action_failed', {message: error.message});
            });
        });
    }
    setupBackgroundActions();

    document.addEventListener('click', function (event) {
        var panelButton = event.target.closest('[data-toggle-panel]');
        if (panelButton) {
            var selector = panelButton.getAttribute('data-toggle-panel');
            var panel = selector ? document.querySelector(selector) : document.getElementById('importBox');
            if (panel) panel.classList.toggle('open');
            closeWindowMenus();
        }
        if (menu && !event.target.closest('#contextMenu')) {
            menu.style.display = 'none';
        }
        if (!event.target.closest('.kw-menu')) {
            closeWindowMenus();
        }
        var cmd = event.target.closest('[data-cmd]');
        if (cmd && selectedRow) {
            var name = cmd.getAttribute('data-cmd');
            if (name === 'edit') window.location = selectedRow.getAttribute('data-edit');
            if (name === 'vars') window.location = selectedRow.getAttribute('data-vars');
        }
        if (event.target.closest('[data-website-action]') && menu) {
            menu.style.display = 'none';
        }
    });

    function setupVariableEditor() {
        var wrap = document.getElementById('variableRows');
        var list = document.getElementById('variableList');
        var add = document.getElementById('addVariable');
        var remove = document.getElementById('removeVariable');
        if (!wrap || !list) return;

        var activeIndex = 0;

        function rows() {
            return Array.from(wrap.querySelectorAll('[data-var-row]'));
        }

        function variableName(row) {
            var input = row.querySelector('input[name="variables[name][]"]');
            var value = input ? input.value.trim() : '';
            return value || '(new)';
        }

        function updateRowVisibility(row) {
            var select = row.querySelector('[data-var-kind]');
            var kind = select ? select.value : 'regex';
            row.querySelectorAll('.kw-var-mode').forEach(function (field) {
                var visible =
                    field.classList.contains('kw-var-textarea') ||
                    (kind !== 'text' && field.classList.contains('kw-var-url')) ||
                    (kind === 'startend' && field.classList.contains('kw-var-startend')) ||
                    (kind === 'regex' && field.classList.contains('kw-var-regex'));
                field.hidden = !visible;
            });
        }

        function updateRegexFlagControl(control) {
            var flags = '';
            ['g', 'm', 'i', 's'].forEach(function (flag) {
                var checkbox = control.querySelector('input[type="checkbox"][value="' + flag + '"]');
                if (checkbox && checkbox.checked) flags += flag;
            });
            var hidden = control.querySelector('[data-regex-flags-value]');
            var label = control.querySelector('[data-regex-flags-label]');
            if (hidden) hidden.value = flags;
            if (label) label.textContent = flags || '-';
        }

        function setupRegexFlagControls(root) {
            root.querySelectorAll('.kw-regex-line').forEach(updateRegexFlagControl);
        }

        function selectVariable(index) {
            var currentRows = rows();
            if (!currentRows.length) return;
            activeIndex = Math.max(0, Math.min(index, currentRows.length - 1));
            currentRows.forEach(function (row, rowIndex) {
                updateRowVisibility(row);
                row.classList.toggle('active', rowIndex === activeIndex);
            });
            list.querySelectorAll('button').forEach(function (button, buttonIndex) {
                button.classList.toggle('active', buttonIndex === activeIndex);
            });
        }

        function renderList() {
            list.innerHTML = '';
            rows().forEach(function (row, index) {
                var button = document.createElement('button');
                button.type = 'button';
                button.textContent = variableName(row);
                button.addEventListener('click', function () {
                    selectVariable(index);
                });
                list.appendChild(button);
            });
            selectVariable(activeIndex);
        }

        wrap.addEventListener('input', function (event) {
            if (event.target.matches('input[name="variables[name][]"]')) {
                renderList();
            }
        });
        wrap.addEventListener('change', function (event) {
            var select = event.target.closest('[data-var-kind]');
            if (select) {
                updateRowVisibility(select.closest('[data-var-row]'));
            }
            var flagControl = event.target.closest('.kw-regex-line');
            if (flagControl && event.target.matches('.kw-regex-flags input[type="checkbox"]')) {
                updateRegexFlagControl(flagControl);
            }
        });

        if (add) {
            add.addEventListener('click', function () {
                var currentRows = rows();
                var first = currentRows[0];
                if (!first) return;
                var clone = first.cloneNode(true);
                clone.querySelectorAll('input, textarea').forEach(function (el) {
                    if (el.type !== 'checkbox') el.value = '';
                });
                clone.querySelectorAll('.kw-regex-flags input[type="checkbox"]').forEach(function (checkbox) {
                    checkbox.checked = checkbox.value === 'i' || checkbox.value === 's';
                });
                var flags = clone.querySelector('[data-regex-flags-value]');
                if (flags) flags.value = 'is';
                clone.querySelectorAll('.kw-regex-flags').forEach(function (details) { details.open = false; });
                var select = clone.querySelector('[data-var-kind]');
                if (select) select.value = 'regex';
                wrap.appendChild(clone);
                setupRegexFlagControls(clone);
                activeIndex = rows().length - 1;
                renderList();
                var name = clone.querySelector('input[name="variables[name][]"]');
                if (name) name.focus();
            });
        }

        if (remove) {
            remove.addEventListener('click', function () {
                var currentRows = rows();
                if (currentRows.length <= 1) return;
                currentRows[activeIndex].remove();
                activeIndex = Math.max(0, activeIndex - 1);
                renderList();
            });
        }

        setupRegexFlagControls(wrap);
        renderList();
    }
    setupVariableEditor();

    var browser = document.getElementById('fileBrowser');
    var browserList = document.getElementById('browserList');
    var browserBookmarks = document.getElementById('browserBookmarks');
    var browserPath = document.getElementById('browserPath');
    var targetPath = document.getElementById('targetPath');
    var selectedPath = '';
    var currentPath = '';
    var fileBrowserAction = document.body.dataset.fileBrowserAction || 'double';

    function targetIsFolderMode() {
        var selected = document.querySelector('input[name="target_type"]:checked');
        return selected && selected.value === 'folder';
    }

    function loadBrowser(path) {
        if (!browserList) return;
        browserList.innerHTML = '<div class="kw-browser-entry">' + t('browser.loading') + '</div>';
        fetch('index.php?action=browse_path&path=' + encodeURIComponent(path || ''))
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.error) throw new Error(data.error);
                currentPath = data.path || '';
                selectedPath = currentPath;
                browserPath.textContent = currentPath || t('browser.computer');
                renderBookmarks(data.bookmarks || []);
                browserList.innerHTML = '';
                if (data.parent !== null) {
                    addBrowserEntry('..', data.parent, 'folder');
                }
                data.entries.forEach(function (entry) {
                    if (targetIsFolderMode() && entry.type !== 'folder') return;
                    addBrowserEntry(entry.name, entry.path, entry.type);
                });
            })
            .catch(function (error) {
                browserList.innerHTML = '<div class="kw-browser-entry">' + error.message + '</div>';
            });
    }

    function addBrowserEntry(name, path, type) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'kw-browser-entry';
        button.dataset.path = path;
        button.dataset.type = type;
        button.innerHTML = '<span>' + (type === 'folder' ? '&#128193;' : '&#128196;') + '</span><strong></strong><span></span>';
        button.querySelector('strong').textContent = name;
        button.querySelector('span:last-child').textContent = type;
        browserList.appendChild(button);
    }

    function activateBrowserEntry(entry) {
        selectedPath = entry.dataset.path;
        if (entry.dataset.type === 'folder') {
            loadBrowser(selectedPath);
            return;
        }
        if (targetPath) targetPath.value = selectedPath;
        browser.hidden = true;
    }

    function renderBookmarks(bookmarks) {
        if (!browserBookmarks) return;
        browserBookmarks.innerHTML = '';
        if (!bookmarks.length) {
            var empty = document.createElement('div');
            empty.className = 'kw-bookmark';
            empty.innerHTML = '<div class="kw-bookmark-main"><small>' + t('browser.no_bookmarks') + '</small></div>';
            browserBookmarks.appendChild(empty);
            return;
        }
        bookmarks.forEach(function (bookmark) {
            var row = document.createElement('div');
            row.className = 'kw-bookmark';
            row.innerHTML = '<button type="button" class="kw-bookmark-main"></button><button type="button" class="kw-bookmark-remove" title="' + t('browser.remove') + '">x</button>';
            row.querySelector('.kw-bookmark-main').innerHTML = '<strong></strong><small></small>';
            row.querySelector('strong').textContent = bookmark.name;
            row.querySelector('small').textContent = bookmark.path;
            row.querySelector('.kw-bookmark-main').addEventListener('click', function () {
                loadBrowser(bookmark.path);
            });
            row.querySelector('.kw-bookmark-remove').addEventListener('click', function () {
                deleteBookmark(bookmark.id);
            });
            browserBookmarks.appendChild(row);
        });
    }

    function postBrowserAction(action, data) {
        return fetch('index.php?action=' + action, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams(data)
        }).then(function (response) { return response.json(); });
    }

    function addBookmark() {
        if (!currentPath) return;
        var fallback = currentPath.split(/[\\/]/).filter(Boolean).pop() || currentPath;
        var name = window.prompt(t('browser.bookmark_name'), fallback);
        if (name === null) return;
        postBrowserAction('bookmark_add', {path: currentPath, name: name})
            .then(function (data) {
                if (data.error) throw new Error(data.error);
                renderBookmarks(data.bookmarks || []);
            })
            .catch(function (error) { window.alert(error.message); });
    }

    function deleteBookmark(id) {
        postBrowserAction('bookmark_delete', {id: id})
            .then(function (data) {
                if (data.error) throw new Error(data.error);
                renderBookmarks(data.bookmarks || []);
            })
            .catch(function (error) { window.alert(error.message); });
    }

    var browseTarget = document.getElementById('browseTarget');
    if (browseTarget && browser) {
        browseTarget.addEventListener('click', function () {
            browser.hidden = false;
            loadBrowser(targetPath && targetPath.value ? targetPath.value : '');
        });
    }
    if (browserList) {
        browserList.addEventListener('click', function (event) {
            var entry = event.target.closest('.kw-browser-entry');
            if (!entry || !entry.dataset.path) return;
            browserList.querySelectorAll('.selected').forEach(function (row) { row.classList.remove('selected'); });
            entry.classList.add('selected');
            selectedPath = entry.dataset.path;
            if (fileBrowserAction === 'single') {
                activateBrowserEntry(entry);
            }
        });
        browserList.addEventListener('dblclick', function (event) {
            var entry = event.target.closest('.kw-browser-entry');
            if (!entry || !entry.dataset.path) return;
            if (fileBrowserAction === 'double') {
                activateBrowserEntry(entry);
            }
        });
    }
    var addBookmarkButton = document.getElementById('addBookmark');
    if (addBookmarkButton) addBookmarkButton.addEventListener('click', addBookmark);
    ['closeBrowser', 'cancelBrowser'].forEach(function (id) {
        var button = document.getElementById(id);
        if (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                browser.hidden = true;
            });
        }
    });
    var chooseBrowserPath = document.getElementById('chooseBrowserPath');
    if (chooseBrowserPath) {
        chooseBrowserPath.addEventListener('click', function () {
            if (targetPath) targetPath.value = selectedPath || currentPath;
            browser.hidden = true;
        });
    }

    var aboutDialog = document.getElementById('aboutDialog');
    var documentationDialog = document.getElementById('documentationDialog');
    var settingsDialog = document.getElementById('settingsDialog');
    document.querySelectorAll('[data-open-documentation]').forEach(function (button) {
        button.addEventListener('click', function () {
            closeWindowMenus();
            if (documentationDialog) documentationDialog.hidden = false;
        });
    });
    document.querySelectorAll('[data-close-documentation]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            if (documentationDialog) documentationDialog.hidden = true;
        });
    });
    document.querySelectorAll('[data-open-about]').forEach(function (button) {
        button.addEventListener('click', function () {
            closeWindowMenus();
            if (aboutDialog) aboutDialog.hidden = false;
        });
    });
    document.querySelectorAll('[data-close-about]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            if (aboutDialog) aboutDialog.hidden = true;
        });
    });
    document.querySelectorAll('[data-open-settings]').forEach(function (button) {
        button.addEventListener('click', function () {
            closeWindowMenus();
            if (settingsDialog) settingsDialog.hidden = false;
        });
    });
    document.querySelectorAll('[data-close-settings]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            if (settingsDialog) settingsDialog.hidden = true;
        });
    });
    (function setupTestmailButton() {
        var button = document.getElementById('sendTestmailButton');
        var to = document.getElementById('emailTo');
        var from = document.getElementById('emailFrom');
        if (!button || !to || !from) return;
        function update() {
            button.disabled = !(to.value.trim() !== '' && from.value.trim() !== '' && to.checkValidity() && from.checkValidity());
        }
        ['input', 'change'].forEach(function (eventName) {
            to.addEventListener(eventName, update);
            from.addEventListener(eventName, update);
        });
        update();
    })();

    document.querySelectorAll('.kw-toast').forEach(function (toast) {
        function closeToast() {
            toast.classList.add('hiding');
            window.setTimeout(function () {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 220);
        }
        var close = toast.querySelector('.kw-toast-close');
        if (close) close.addEventListener('click', closeToast);
        window.setTimeout(closeToast, toast.classList.contains('kw-toast-danger') ? 9000 : 5200);
    });

    document.querySelectorAll('.kw-tabs').forEach(function (tabs) {
        var windowEl = tabs.closest('.kw-window');
        tabs.addEventListener('click', function (event) {
            var tab = event.target.closest('[data-tab]');
            if (!tab || !windowEl) return;
            tabs.querySelectorAll('.nav-link').forEach(function (item) { item.classList.remove('active'); });
            tab.classList.add('active');
            windowEl.querySelectorAll('.kw-tab-pane').forEach(function (pane) {
                pane.classList.toggle('active', pane.dataset.pane === tab.dataset.tab);
            });
        });
    });

    function updateDownloadLocationState() {
        var location = document.getElementById('downloadLocationFields');
        if (!location) return;
        var notify = document.querySelector('input[name="update_mode"][value="notify"]');
        var disabled = notify && notify.checked;
        location.classList.toggle('disabled', !!disabled);
        location.querySelectorAll('input, button').forEach(function (control) {
            if (control.name === 'target_path') {
                control.readOnly = !!disabled;
            } else if (control.name === 'target_type') {
                control.disabled = false;
            } else {
                control.disabled = !!disabled;
            }
        });
    }
    document.querySelectorAll('[data-update-mode]').forEach(function (radio) {
        radio.addEventListener('change', updateDownloadLocationState);
    });
    updateDownloadLocationState();

    document.addEventListener('keydown', function (event) {
        var active = document.activeElement;
        var isTyping = active && active.matches && active.matches('input, textarea, select, [contenteditable="true"]');
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'a' && !isTyping) {
            event.preventDefault();
            selectAllRows();
            return;
        }
        if (event.key !== 'Escape') return;
        closeWindowMenus();
        if (menu) menu.style.display = 'none';
        if (browser && !browser.hidden) {
            browser.hidden = true;
            return;
        }
        if (aboutDialog && !aboutDialog.hidden) {
            aboutDialog.hidden = true;
            return;
        }
        if (documentationDialog && !documentationDialog.hidden) {
            documentationDialog.hidden = true;
            return;
        }
        if (settingsDialog && !settingsDialog.hidden) {
            settingsDialog.hidden = true;
            return;
        }
        var openDialog = document.querySelector('.kw-dialog:not([hidden])');
        if (openDialog) {
            window.location = openDialog.dataset.closeUrl || 'index.php';
        }
    });

    document.querySelectorAll('.kw-window, .kw-file-browser-window').forEach(function (dialogWindow) {
        var title = dialogWindow.querySelector('.kw-window-title');
        if (!title) return;
        var startX = 0;
        var startY = 0;
        var startLeft = 0;
        var startTop = 0;
        var dragging = false;

        title.addEventListener('mousedown', function (event) {
            if (event.target.closest('a, button')) return;
            var rect = dialogWindow.getBoundingClientRect();
            dragging = true;
            startX = event.clientX;
            startY = event.clientY;
            startLeft = rect.left;
            startTop = rect.top;
            dialogWindow.classList.add('dragging');
            dialogWindow.style.position = 'fixed';
            dialogWindow.style.left = rect.left + 'px';
            dialogWindow.style.top = rect.top + 'px';
            dialogWindow.style.margin = '0';
            event.preventDefault();
        });

        document.addEventListener('mousemove', function (event) {
            if (!dragging) return;
            var width = dialogWindow.offsetWidth;
            var height = dialogWindow.offsetHeight;
            var nextLeft = startLeft + event.clientX - startX;
            var nextTop = startTop + event.clientY - startY;
            nextLeft = Math.max(0, Math.min(window.innerWidth - width, nextLeft));
            nextTop = Math.max(0, Math.min(window.innerHeight - height, nextTop));
            dialogWindow.style.left = nextLeft + 'px';
            dialogWindow.style.top = nextTop + 'px';
        });

        document.addEventListener('mouseup', function () {
            if (!dragging) return;
            dragging = false;
            dialogWindow.classList.remove('dragging');
        });
    });
})();
