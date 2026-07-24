<?php
declare(strict_types=1);
require __DIR__ . '/inc/init.php';

session_start();
$database = new Database();
$repository = new AppRepository($database);
$settingsRepository = new SettingsRepository($database);
$updater = new Updater($repository, new HttpClient());
$importer = new KetarinImporter($repository);
$flashBag = new Flash();
$settings = $settingsRepository->all();
$i18n = new I18n($settings['language']);
$t = static fn (string $key, array $replace = []): string => $i18n->t($key, $replace);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'browse_path') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        echo json_encode((new FileBrowser($database))->list((string)($_GET['path'] ?? '')), JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

if ($action === 'bookmark_add') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        echo json_encode(['bookmarks' => (new FileBrowser($database))->addBookmark((string)($_POST['path'] ?? ''), (string)($_POST['name'] ?? ''))], JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

if ($action === 'bookmark_delete') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        echo json_encode(['bookmarks' => (new FileBrowser($database))->deleteBookmark((int)($_POST['id'] ?? 0))], JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

if ($action === 'progress') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['apps' => $repository->progressSnapshot()], JSON_THROW_ON_ERROR);
    exit;
}

if ($action === 'download_command_log') {
    $app = $repository->find((int)($_GET['id'] ?? 0));
    $log = $app ? $repository->commandLog((int)$app['id']) : null;
    if (!$app || !$log || (string)$log['log_json'] === '') {
        http_response_code(404);
        echo "Command log not found.\n";
        exit;
    }
    $slug = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string)$app['name']) ?: 'app';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $slug . '-' . (int)$app['id'] . '.json"');
    echo $log['log_json'];
    exit;
}

try {
    if ($action === 'save_app') {
        $repository->save($_POST);
        $flashBag->set($t('flash.application_saved'));
        Support::redirect('index.php');
    }
    if ($action === 'save_settings') {
        $settingsRepository->save($_POST['settings'] ?? []);
        $savedI18n = new I18n((string)(($_POST['settings'] ?? [])['language'] ?? $settings['language']));
        $flashBag->set($savedI18n->t('flash.settings_saved'));
        Support::redirect('index.php');
    }
    if ($action === 'send_testmail') {
        $settingsRepository->save($_POST['settings'] ?? []);
        $savedI18n = new I18n((string)(($_POST['settings'] ?? [])['language'] ?? $settings['language']));
        if ((new Mailer($settingsRepository))->sendTest()) {
            $flashBag->set($savedI18n->t('flash.testmail_sent'));
        } else {
            $flashBag->set($savedI18n->t('flash.testmail_failed'), 'danger');
        }
        Support::redirect('index.php');
    }
    if ($action === 'save_variables') {
        $appId = (int)$_POST['app_id'];
        $repository->saveVariables($appId, $_POST['variables'] ?? []);
        $flashBag->set($t('flash.variables_saved'));
        Support::redirect('index.php?edit=' . $appId);
    }
    if ($action === 'delete') {
        $repository->delete((int)$_POST['id']);
        $flashBag->set($t('flash.application_deleted'));
        Support::redirect();
    }
    if ($action === 'bulk_delete') {
        $ids = array_filter(explode(',', (string)($_POST['ids'] ?? '')));
        $deleted = $repository->deleteMany($ids);
        $flashBag->set($t('flash.applications_deleted', ['count' => $deleted]));
        Support::redirect();
    }
    if ($action === 'bulk_update') {
        session_write_close();
        $ids = array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))));
        $count = 0;
        foreach ($ids as $id) {
            $updater->run($id, true);
            $count++;
        }
        $flashBag->set($t('flash.applications_updated', ['count' => $count]));
        Support::redirect();
    }
    if ($action === 'copy') {
        $id = $repository->copy((int)$_POST['id']);
        $flashBag->set($t('flash.application_copied'));
        Support::redirect('index.php?edit=' . $id);
    }
    if ($action === 'check' || $action === 'download' || $action === 'force') {
        session_write_close();
        $updater->run((int)$_POST['id'], $action !== 'check', $action === 'force');
        $flashBag->set($action === 'check' ? $t('flash.check_completed') : $t('flash.download_completed'));
        Support::redirect();
    }
    if ($action === 'run_command') {
        $updater->queueCommandOnly((int)$_POST['id']);
        $flashBag->set($t('flash.command_queued'));
        Support::redirect();
    }
    if ($action === 'run_all') {
        session_write_close();
        $updater->runAll(true);
        $flashBag->set($t('flash.all_updated'));
        Support::redirect();
    }
    if ($action === 'import_xml' && isset($_FILES['xml'])) {
        $count = $importer->import($_FILES['xml']['tmp_name']);
        $flashBag->set($t('flash.applications_imported', ['count' => $count]));
        Support::redirect();
    }
} catch (Throwable $e) {
    $flashBag->set($e->getMessage(), 'danger');
    Support::redirect();
}

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$varId = isset($_GET['variables']) ? (int)$_GET['variables'] : 0;
$sort = (string)($_GET['sort'] ?? 'date');
$direction = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$apps = $repository->all($sort, $direction);
$flash = $flashBag->pull();
$languages = I18n::available();
$jsI18n = $i18n->subset([
    'hint.selection_default',
    'hint.selection_count',
    'hint.action_running',
    'hint.action_completed',
    'hint.action_failed',
    'confirm.delete_selected',
    'table.just_notify',
    'js.no_bash_script',
    'js.updating',
    'js.download',
    'js.remaining',
    'js.download_running',
    'browser.loading',
    'browser.computer',
    'browser.no_bookmarks',
    'browser.remove',
    'browser.bookmark_name',
]);
$editApp = $editId ? $repository->find($editId) : null;
$varApp = $varId ? $repository->find($varId) : null;
$newApp = ['id' => 0, 'name' => '', 'category' => '', 'enabled' => 1, 'download_url_template' => '{url}', 'target_path' => KW_DATA . '/downloads/', 'target_type' => 'folder', 'beta_policy' => 'default', 'update_mode' => 'download', 'http_referer' => '', 'http_user_agent' => '', 'ignore_missing_file' => 0, 'change_indicator' => 'version', 'website' => '', 'notes' => '', 'command_enabled' => 0, 'command_script' => ''];

$sortLink = static function (string $column, string $label) use ($sort, $direction): string {
    $next = $sort === $column && $direction === 'asc' ? 'desc' : 'asc';
    $mark = $sort === $column ? ($direction === 'asc' ? ' ▲' : ' ▼') : '';
    return '<a href="index.php?sort=' . rawurlencode($column) . '&dir=' . $next . '">' . Support::h($label . $mark) . '</a>';
};
?>
<!doctype html>
<html lang="<?= Support::h($i18n->meta('iso2') ?: 'en') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KetarinWeb</title>
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/ketarinweb.css">
</head>
<body data-file-browser-action="<?= Support::h($settings['file_browser_action']) ?>">
<header class="kw-titlebar">
    <span class="kw-appdot"></span>
    <strong>KetarinWeb</strong>
</header>
<nav class="kw-menu">
    <button type="button" data-menu="file"><?= Support::h($t('menu.file')) ?></button>
    <button type="button" data-menu="import"><?= Support::h($t('menu.import')) ?></button>
    <button type="button" data-menu="help"><?= Support::h($t('menu.help')) ?></button>
    <div class="kw-menu-popup" id="menu-file" hidden>
        <a href="index.php?edit=0"><?= Support::h($t('menu.new_application')) ?></a>
        <button type="submit" form="runAllForm"><?= Support::h($t('menu.update_all')) ?></button>
        <button type="button" data-open-settings><?= Support::h($t('menu.settings')) ?></button>
        <a href="index.php"><?= Support::h($t('menu.refresh')) ?></a>
    </div>
    <div class="kw-menu-popup" id="menu-import" hidden>
        <button type="button" data-toggle-panel="#importBox"><?= Support::h($t('menu.import_xml')) ?></button>
    </div>
    <div class="kw-menu-popup" id="menu-help" hidden>
        <button type="button" data-open-documentation><?= Support::h($t('menu.documentation')) ?></button>
        <button type="button" data-open-about><?= Support::h($t('menu.about')) ?></button>
    </div>
</nav>

<main class="kw-shell">
    <section class="kw-commandbar">
        <div>
            <strong><?= Support::h($t('toolbar.applications')) ?></strong>
            <span><?= count($apps) ?> <?= Support::h($t('toolbar.entries')) ?></span>
        </div>
        <div>
            <a class="btn btn-sm btn-primary" href="index.php?edit=0"><?= Support::h($t('button.add_application')) ?></a>
            <form method="post" class="d-inline" id="runAllForm"><input type="hidden" name="action" value="run_all"><button class="btn btn-sm btn-outline-primary" type="submit"><?= Support::h($t('button.update_all')) ?></button></form>
            <form method="post" class="d-inline" id="bulkDeleteForm">
                <input type="hidden" name="action" value="bulk_delete">
                <input type="hidden" name="ids" id="bulkDeleteIds">
                <button class="btn btn-sm btn-outline-danger" id="bulkDeleteButton" type="submit" disabled><?= Support::h($t('button.delete_selected')) ?></button>
            </form>
            <form method="post" class="d-inline" id="bulkUpdateForm">
                <input type="hidden" name="action" value="bulk_update">
                <input type="hidden" name="ids" id="bulkUpdateIds">
                <button class="btn btn-sm btn-outline-primary" id="bulkUpdateButton" type="submit" disabled><?= Support::h($t('button.update_selected')) ?></button>
            </form>
            <button class="btn btn-sm btn-outline-secondary" data-toggle-panel="#importBox" type="button"><?= Support::h($t('button.import')) ?></button>
        </div>
    </section>

    <section class="kw-grid">
        <table class="kw-table" id="appTable">
            <thead>
            <tr>
                <th data-col="application"><?= $sortLink('application', $t('table.application')) ?></th>
                <th data-col="updated"><?= $sortLink('updated', $t('table.last_updated')) ?></th>
                <th data-col="progress"><?= $sortLink('progress', $t('table.progress')) ?></th>
                <th data-col="target"><?= $sortLink('target', $t('table.target')) ?></th>
                <th data-col="category"><?= $sortLink('category', $t('table.category')) ?></th>
                <th data-col="version"><?= $sortLink('version', $t('table.version')) ?></th>
                <th data-col="command">C</th>
            </tr>
            </thead>
            <tbody>
            <?php $lastGroup = null; $showDateGroups = $sort === 'date' || $sort === 'updated'; foreach ($apps as $app): ?>
                <?php $displayUpdated = $app['last_updated'] ?: ''; ?>
                <?php $group = $displayUpdated ? date('d.m.Y', strtotime($displayUpdated)) : $t('table.never'); ?>
                <?php if ($showDateGroups && $group !== $lastGroup): $lastGroup = $group; ?>
                    <tr class="kw-date-row"><td colspan="7"><?= Support::h($group) ?></td></tr>
                <?php endif; ?>
                <?php $statusIcon = $app['error'] ? 'error' : (string)$app['status']; ?>
                <?php $hasCommandScript = trim((string)($app['command_script'] ?? '')) !== ''; ?>
                <?php $commandEnabled = $hasCommandScript && !empty($app['command_enabled']); ?>
                <tr class="kw-app-row <?= $app['enabled'] ? '' : 'kw-app-disabled' ?>" data-id="<?= (int)$app['id'] ?>" data-edit="index.php?edit=<?= (int)$app['id'] ?>" data-vars="index.php?variables=<?= (int)$app['id'] ?>" data-command="<?= !empty($app['command_enabled']) && trim((string)($app['command_script'] ?? '')) !== '' ? '1' : '0' ?>" data-website="<?= Support::h($app['website'] ?? '') ?>">
                    <td><span class="kw-status kw-status-<?= Support::h($statusIcon) ?>" data-status-icon></span><?= Support::h($app['name']) ?></td>
                    <td data-updated-cell><?= $displayUpdated ? Support::h(date('d.m.Y H:i', strtotime($displayUpdated))) : '' ?></td>
                    <td data-progress-cell><?= Support::h($app['error'] ?: $app['status']) ?></td>
                    <?php $targetText = ($app['update_mode'] ?? 'download') === 'notify' ? $t('table.just_notify') : ((($app['target_type'] ?? 'file') === 'folder' && $app['current_target_path']) ? $app['current_target_path'] : $app['target_path']); ?>
                    <td data-target-cell><?= Support::h($targetText) ?></td>
                    <td data-category-cell><?= Support::h($app['category']) ?></td>
                    <td data-version-cell><?= Support::h($app['current_version']) ?></td>
                    <td class="kw-command-cell"><?php if ($hasCommandScript): ?><span class="kw-command-icon <?= $commandEnabled ? 'kw-command-icon-enabled' : '' ?>" title="<?= Support::h($commandEnabled ? 'Command enabled' : 'Command configured') ?>"></span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$apps): ?>
                <tr><td colspan="7" class="kw-empty"><?= Support::h($t('table.empty')) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="kw-toolbar">
        <span class="kw-hint" id="selectionHint"><?= Support::h($t('hint.selection_default')) ?></span>
    </section>

    <section class="kw-import" id="importBox">
        <form method="post" enctype="multipart/form-data" class="form-inline">
            <input type="hidden" name="action" value="import_xml">
            <label class="mr-2"><?= Support::h($t('import.ketarin_xml')) ?></label>
            <input class="form-control form-control-sm mr-2" type="file" name="xml" accept=".xml">
            <button class="btn btn-sm btn-primary" type="submit"><?= Support::h($t('menu.import')) ?></button>
        </form>
    </section>
</main>

<?php if ($flash): ?>
    <div class="kw-toast-stack" aria-live="polite" aria-atomic="true">
        <div class="kw-toast kw-toast-<?= Support::h($flash['type']) ?>" role="status">
            <button type="button" class="kw-toast-close" aria-label="<?= Support::h($t('button.close')) ?>">x</button>
            <strong><?= $flash['type'] === 'danger' ? Support::h($t('toast.error')) : 'KetarinWeb' ?></strong>
            <span><?= Support::h($flash['message']) ?></span>
        </div>
    </div>
<?php endif; ?>

<div class="kw-dialog" id="aboutDialog" hidden>
    <div class="kw-window kw-about-window">
        <div class="kw-window-title"><?= Support::h($t('dialog.about.title')) ?><a href="#" data-close-about>x</a></div>
        <div class="kw-about-body">
            <h2>KetarinWeb</h2>
            <dl class="kw-about-list">
                <dt><?= Support::h($t('about.original')) ?></dt>
                <dd><a href="https://ketarin.org/" target="_blank" rel="noopener">ketarin.org</a></dd>
                <dt><?= Support::h($t('about.web_implementation')) ?></dt>
                <dd><a href="https://papazivi.de/" target="_blank" rel="noopener">PapaZivi</a></dd>
                <dt><?= Support::h($t('about.license')) ?></dt>
                <dd><a href="https://www.gnu.org/licenses/old-licenses/gpl-2.0.html" target="_blank" rel="noopener">GNU General Public License v2.0</a></dd>
            </dl>
        </div>
        <div class="kw-window-actions"><button class="btn btn-primary btn-sm" type="button" data-close-about><?= Support::h($t('button.ok')) ?></button></div>
    </div>
</div>

<div class="kw-dialog" id="documentationDialog" hidden>
    <div class="kw-window kw-documentation-window">
        <div class="kw-window-title"><?= Support::h($t('dialog.documentation.title')) ?><a href="#" data-close-documentation>x</a></div>
        <ul class="nav nav-tabs small kw-tabs kw-documentation-tabs">
            <li class="nav-item"><button class="nav-link active" type="button" data-tab="doc-basics"><?= Support::h($t('doc.tab.basics')) ?></button></li>
            <li class="nav-item"><button class="nav-link" type="button" data-tab="doc-interface"><?= Support::h($t('doc.tab.interface')) ?></button></li>
            <li class="nav-item"><button class="nav-link" type="button" data-tab="doc-vars"><?= Support::h($t('doc.tab.variables')) ?></button></li>
            <li class="nav-item"><button class="nav-link" type="button" data-tab="doc-functions"><?= Support::h($t('doc.tab.functions')) ?></button></li>
            <li class="nav-item"><button class="nav-link" type="button" data-tab="doc-commands"><?= Support::h($t('doc.tab.scripting')) ?></button></li>
            <li class="nav-item"><button class="nav-link" type="button" data-tab="doc-cli"><?= Support::h($t('doc.tab.cron')) ?></button></li>
            <li class="nav-item"><button class="nav-link" type="button" data-tab="doc-trouble"><?= Support::h($t('doc.tab.troubleshooting')) ?></button></li>
        </ul>
        <div class="kw-documentation-body">
            <section class="kw-tab-pane active" data-pane="doc-basics">
                <h2>Basics</h2>
                <p>KetarinWeb maintains a collection of application downloads. It is not a system package manager. Its purpose is to watch vendor pages, APIs or direct URLs, detect release changes and store the current package at a defined target.</p>
                <h3>Typical workflow</h3>
                <ol>
                    <li>Create or import an application.</li>
                    <li>Define how version and download URL are detected.</li>
                    <li>Choose whether updates should be downloaded or only reported.</li>
                    <li>Set the target as a fixed file or download folder.</li>
                    <li>Enable the application and let cron process it.</li>
                </ol>
                <h3>Update behavior</h3>
                <p><strong>Download update</strong> downloads changed files. <strong>Notify only</strong> only checks the change indicator and sends an email when configured. Notify-only applications do not need a target file and do not queue commands.</p>
                <h3>Imported applications</h3>
                <p>Ketarin XML imports are disabled by default. This prevents Windows paths from being used accidentally on Linux. Review variables, target paths and the change indicator before enabling imported applications.</p>
            </section>
            <section class="kw-tab-pane" data-pane="doc-interface">
                <h2>Interface</h2>
                <h3>Application list</h3>
                <p>The main list shows application name, last update time, progress/status, target path, category and version. Click column headers to sort. Drag column separators to adjust widths. Hover shortened cells to see the full value.</p>
                <h3>Selection and actions</h3>
                <p>Use Ctrl and Shift for multi-selection. Right-click an application to update it, check it, force a download, queue its command, visit its configured website, edit it or delete it.</p>
                <h3>Status icons</h3>
                <p>Green means the last operation completed successfully. Yellow marks new or update-found entries. Red marks an error. Disabled applications are dimmed and skipped by normal cron runs.</p>
                <h3>Target type</h3>
                <p><strong>Save to file</strong> writes the download to exactly the configured file path. <strong>Save in folder</strong> treats the target as a folder and stores the download there using the delivered filename or the filename from the URL.</p>
                <h3>File browser</h3>
                <p>The file browser follows the target type. Folder targets only allow folder selection; file targets allow file selection. Bookmarks provide quick access to common locations.</p>
            </section>
            <section class="kw-tab-pane" data-pane="doc-vars">
                <h2>Variables and Special Values</h2>
                <p>Variables are referenced with braces, for example <code>{version}</code>, <code>{url}</code> or <code>{download}</code>. Only variables referenced by the URL template, target path, command script or change indicator are resolved.</p>
                <h3>Variable types</h3>
                <p><strong>Content from URL (start/end)</strong> extracts text between two markers. <strong>Content from URL (Regular Expression)</strong> extracts a value with a raw regular expression. <strong>Textual content</strong> uses a fixed value and can reference other variables.</p>
                <h3>Regular expression flags</h3>
                <p>Regex fields contain the raw expression only. Do not wrap it in delimiters such as <code>/.../</code>. Flags are selected next to the field: <code>i</code> for case-insensitive matching, <code>m</code> for multiline anchors, <code>s</code> for dot-matches-newline and <code>g</code> for all matches.</p>
                <h3>Change indicator</h3>
                <p>The change indicator in Advanced settings decides which variable means "this release changed". Usually this is <code>version</code>, but it can be any variable that exists for the application.</p>
                <h3>Special command placeholders</h3>
                <table class="kw-doc-table"><tbody>
                    <tr><th><code>{file}</code></th><td>Final downloaded file path.</td></tr>
                    <tr><th><code>{target}</code></th><td>Same final target path as used for the command.</td></tr>
                    <tr><th><code>{url}</code></th><td>Resolved download URL when available.</td></tr>
                    <tr><th><code>{version}</code></th><td>Resolved version or configured change indicator value.</td></tr>
                    <tr><th><code>{name}</code></th><td>Application name.</td></tr>
                </tbody></table>
                <h3>Resolution order</h3>
                <p>Variables may reference other variables. KetarinWeb resolves dependencies only when needed. Circular references are reported as errors so they can be fixed in the Variables dialog.</p>
            </section>
            <section class="kw-tab-pane" data-pane="doc-functions">
                <h2>Functions</h2>
                <p>Ketarin-style functions can be appended to placeholders with this form:</p>
                <pre><code>{variablename:function:argument1:argument2}</code></pre>
                <p>Functions are applied when placeholders are replaced. They are useful for cleaning version strings, deriving filenames, normalizing case or making values URL-safe.</p>
                <h3>Supported functions</h3>
                <table class="kw-doc-table"><tbody>
                    <tr><th><code>directory</code></th><td>Extracts the directory part from a URL or file path.</td></tr>
                    <tr><th><code>empty</code></th><td>Returns an empty string.</td></tr>
                    <tr><th><code>ext</code></th><td>Extracts the file extension without the dot.</td></tr>
                    <tr><th><code>filename</code></th><td>Returns the filename with extension from a URL or path.</td></tr>
                    <tr><th><code>formatfilesize</code></th><td>Formats a byte value as a readable file size.</td></tr>
                    <tr><th><code>regex</code></th><td>Returns the value matched by a regular expression. Capture groups can be selected by index.</td></tr>
                    <tr><th><code>regexreplace</code></th><td>Replaces regex matches with a replacement string. Capture references such as <code>$1</code> can be used.</td></tr>
                    <tr><th><code>replace</code></th><td>Replaces all occurrences of one string with another string.</td></tr>
                    <tr><th><code>multireplace</code></th><td>Performs multiple replacements in one function call.</td></tr>
                    <tr><th><code>multireplacei</code></th><td>Case-insensitive multiple replacements.</td></tr>
                    <tr><th><code>split</code></th><td>Splits text and returns a part by zero-based index. Negative indexes count from the end.</td></tr>
                    <tr><th><code>tolower</code></th><td>Converts text to lowercase.</td></tr>
                    <tr><th><code>toupper</code></th><td>Converts text to uppercase.</td></tr>
                    <tr><th><code>trim</code></th><td>Trims whitespace or the supplied characters from both ends.</td></tr>
                    <tr><th><code>trimstart</code></th><td>Trims only at the beginning.</td></tr>
                    <tr><th><code>trimend</code></th><td>Trims only at the end.</td></tr>
                    <tr><th><code>urlencode</code></th><td>Encodes text for use in URLs.</td></tr>
                    <tr><th><code>urldecode</code></th><td>Decodes URL-encoded text.</td></tr>
                </tbody></table>
                <h3>Examples</h3>
                <pre><code>{version:regexreplace:.0:}
{name:tolower}
{url:filename}
{filename:ext}
{name:urlencode}
{raw:split:-:0}</code></pre>
                <h3>Applying multiple functions</h3>
                <p>For clarity and compatibility, use textual helper variables when chaining several transformations. Create one variable for the first function, then reference that variable from the next one.</p>
            </section>
            <section class="kw-tab-pane" data-pane="doc-commands">
                <h2>Commands and Scripting</h2>
                <p>Commands are Bash scripts. They are queued after successful downloads and executed by <code>command_cron.php</code>. They are not run directly from the browser request.</p>
                <h3>When commands run</h3>
                <p>A command is queued only after a successful download and only when command execution is enabled for the application. Notify-only applications do not queue commands.</p>
                <h3>Placeholders</h3>
                <p>Before execution, KetarinWeb replaces placeholders in the script. Available placeholders include <code>{file}</code>, <code>{target}</code>, <code>{url}</code>, <code>{version}</code>, <code>{name}</code> and all application variables.</p>
                <h3>Environment variables</h3>
                <table class="kw-doc-table"><tbody>
                    <tr><th><code>KETARINWEB_FILE</code></th><td>Final downloaded file path.</td></tr>
                    <tr><th><code>KETARINWEB_URL</code></th><td>Resolved download URL.</td></tr>
                    <tr><th><code>KETARINWEB_VERSION</code></th><td>Resolved version.</td></tr>
                    <tr><th><code>KETARINWEB_APP_NAME</code></th><td>Application name.</td></tr>
                </tbody></table>
                <h3>Example: write a metadata file</h3>
                <pre><code>set -euo pipefail
meta="$(dirname -- "{file}")/metadata.txt"
{
  echo "name={name}"
  echo "version={version}"
  echo "file={file}"
  echo "url={url}"
} > "$meta"</code></pre>
                <h3>Logs</h3>
                <p>The latest command log is stored per application in SQLite and shown in the Logs tab. It contains resolved variables, the final Bash script, output and exit code. The JSON log can be downloaded from that tab.</p>
                <h3>Concurrency</h3>
                <p>Command jobs are claimed atomically. If a second command cron starts while a job is already running, it skips that job instead of running it twice.</p>
            </section>
            <section class="kw-tab-pane" data-pane="doc-cli">
                <h2>Cron and Command Line</h2>
                <h3>Daily update run</h3>
                <p><code>cron.php</code> processes enabled applications, resolves variables, checks for changes, downloads files when needed and queues commands after successful downloads.</p>
                <pre><code>0 3 * * * /usr/bin/php /path/to/ketarinweb/cron.php</code></pre>
                <h3>Command job run</h3>
                <p><code>command_cron.php</code> processes pending command jobs. A one-minute interval is usually fine because jobs are protected against double execution.</p>
                <pre><code>* * * * * /usr/bin/php /path/to/ketarinweb/command_cron.php</code></pre>
                <h3>Useful switches</h3>
                <table class="kw-doc-table"><tbody>
                    <tr><th><code>--check-only</code></th><td>Detect updates but do not download files.</td></tr>
                    <tr><th><code>--id=ID</code></th><td>Process a single application.</td></tr>
                    <tr><th><code>--id=ID,ID</code></th><td>Process multiple applications.</td></tr>
                    <tr><th><code>ID</code></th><td>Short form for selecting applications by ID.</td></tr>
                    <tr><th><code>--force</code></th><td>Allow a selected disabled application to run once.</td></tr>
                    <tr><th><code>--commands</code></th><td>Process command jobs through the main cron script.</td></tr>
                    <tr><th><code>--verbose</code> / <code>-v</code></th><td>Print normal status output. Without it, cron output is quiet unless errors occur.</td></tr>
                </tbody></table>
                <h3>Logs</h3>
                <p>Cron logs are written to <code>data/logs/cron.log</code> and <code>data/logs/command-cron.log</code>. Entries are written when each event happens, not as one batch at the end.</p>
            </section>
            <section class="kw-tab-pane" data-pane="doc-trouble">
                <h2>Troubleshooting Downloads</h2>
                <h3>No regex match</h3>
                <p>Open the Variables dialog and inspect the last loaded content. Check that the URL returns the expected data, the regex is raw, and the right flags are selected.</p>
                <h3>HTTP 403 or blocked downloads</h3>
                <p>Set a browser-like user agent or referer in Advanced settings. Some vendors reject generic clients. KetarinWeb sends common browser headers, but individual sites may still require specific settings.</p>
                <h3>No download URL resolved</h3>
                <p>Check whether the download URL template references the right variable. If the application is notify-only, no download URL is required.</p>
                <h3>Wrong filename</h3>
                <p>If the target type is <strong>Save to file</strong>, the configured filename is always used. If the target type is <strong>Save in folder</strong>, KetarinWeb uses the delivered filename or the filename from the URL.</p>
                <h3>Command failed</h3>
                <p>Open the application Logs tab. The log shows resolved variables, the final script, stdout/stderr output and exit code. Download the JSON log when it should be processed elsewhere.</p>
                <h3>Permissions</h3>
                <p>The web server user needs write access to <code>data/</code> and configured download targets. The cron user must be able to read the application files and execute Bash commands.</p>
            </section>
        </div>
        <div class="kw-window-actions"><button class="btn btn-primary btn-sm" type="button" data-close-documentation><?= Support::h($t('button.ok')) ?></button></div>
    </div>
</div>

<div class="kw-dialog" id="settingsDialog" hidden>
    <form method="post" class="kw-window kw-settings-window">
        <input type="hidden" name="action" value="save_settings">
        <div class="kw-window-title"><?= Support::h($t('settings.title')) ?><a href="#" data-close-settings>x</a></div>
        <ul class="nav nav-tabs small kw-tabs">
            <li class="nav-item"><button class="nav-link active" type="button" data-tab="emailsettings"><?= Support::h($t('settings.tab.email')) ?></button></li>
            <li class="nav-item"><button class="nav-link" type="button" data-tab="filesettings"><?= Support::h($t('settings.tab.files')) ?></button></li>
            <li class="nav-item"><button class="nav-link" type="button" data-tab="languagesettings"><?= Support::h($t('settings.tab.language')) ?></button></li>
        </ul>
        <div class="kw-formbody kw-tab-pane active" data-pane="emailsettings">
            <div class="form-group">
                <label for="emailTo"><?= Support::h($t('settings.recipient_email')) ?></label>
                <input class="form-control form-control-sm" id="emailTo" name="settings[email_to]" type="email" value="<?= Support::h($settings['email_to']) ?>">
            </div>
            <div class="form-group">
                <label for="emailFrom"><?= Support::h($t('settings.sender_email')) ?></label>
                <input class="form-control form-control-sm" id="emailFrom" name="settings[email_from]" type="email" value="<?= Support::h($settings['email_from']) ?>">
            </div>
            <div class="form-group">
                <label for="emailFromName"><?= Support::h($t('settings.sender_name')) ?></label>
                <input class="form-control form-control-sm" id="emailFromName" name="settings[email_from_name]" value="<?= Support::h($settings['email_from_name']) ?>">
            </div>
            <p class="kw-muted"><?= Support::h($t('settings.mail_hint')) ?></p>
            <button class="btn btn-light border btn-sm mb-3" id="sendTestmailButton" name="action" value="send_testmail" type="submit" disabled><?= Support::h($t('button.send_testmail')) ?></button>
        </div>
        <div class="kw-formbody kw-tab-pane" data-pane="filesettings">
            <div class="form-group">
                <label><?= Support::h($t('settings.file_browser_action')) ?></label>
                <div>
                    <label class="mr-3"><input type="radio" name="settings[file_browser_action]" value="double" <?= $settings['file_browser_action'] === 'double' ? 'checked' : '' ?>> <?= Support::h($t('settings.double_click')) ?></label>
                    <label><input type="radio" name="settings[file_browser_action]" value="single" <?= $settings['file_browser_action'] === 'single' ? 'checked' : '' ?>> <?= Support::h($t('settings.single_click')) ?></label>
                </div>
                <p class="kw-muted mt-2"><?= Support::h($t('settings.file_browser_hint')) ?></p>
            </div>
        </div>
        <div class="kw-formbody kw-tab-pane" data-pane="languagesettings">
            <div class="form-group">
                <label for="languageSelect"><?= Support::h($t('settings.language')) ?></label>
                <select class="form-control form-control-sm" id="languageSelect" name="settings[language]">
                    <?php foreach ($languages as $language): ?>
                        <option value="<?= Support::h($language['locale']) ?>" <?= $settings['language'] === $language['locale'] ? 'selected' : '' ?>><?= Support::h($language['name'] . ' (' . $language['locale'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="kw-window-actions"><button class="btn btn-primary btn-sm" type="submit"><?= Support::h($t('button.ok')) ?></button><button class="btn btn-light border btn-sm" type="button" data-close-settings><?= Support::h($t('button.cancel')) ?></button></div>
    </form>
</div>

<?php if ($editId || isset($_GET['edit'])): $app = $editApp ?: $newApp; $appVars = (int)$app['id'] > 0 ? $repository->variables((int)$app['id']) : []; $appVarNames = array_values(array_filter(array_map(static fn (array $var): string => (string)$var['name'], $appVars), static fn (string $name): bool => $name !== '')); $commandLog = (int)$app['id'] > 0 ? $repository->commandLog((int)$app['id']) : null; ?>
<div class="kw-dialog">
    <form method="post" class="kw-window">
        <input type="hidden" name="action" value="save_app">
        <input type="hidden" name="id" value="<?= (int)$app['id'] ?>">
        <div class="kw-window-title"><?= Support::h($t('edit.title', ['name' => $app['name'] ?: $t('edit.fallback_application')])) ?><a href="index.php">x</a></div>
        <ul class="nav nav-tabs small kw-tabs">
            <li class="nav-item"><button class="nav-link active" type="button" data-tab="application"><?= Support::h($t('edit.tab.application')) ?></button></li>
            <li class="nav-item"><button class="nav-link" type="button" data-tab="advanced"><?= Support::h($t('edit.tab.advanced')) ?></button></li>
            <li class="nav-item"><button class="nav-link" type="button" data-tab="commands"><?= Support::h($t('edit.tab.commands')) ?></button></li>
            <li class="nav-item"><button class="nav-link" type="button" data-tab="information"><?= Support::h($t('edit.tab.information')) ?></button></li>
            <li class="nav-item"><button class="nav-link" type="button" data-tab="logs"><?= Support::h($t('edit.tab.logs')) ?></button></li>
        </ul>
        <div class="kw-formbody kw-tab-pane active" data-pane="application">
            <div class="form-group row"><label class="col-sm-3 col-form-label col-form-label-sm"><?= Support::h($t('edit.application_name')) ?></label><div class="col-sm-9"><input class="form-control form-control-sm" name="name" value="<?= Support::h($app['name']) ?>" required></div></div>
            <div class="form-group row"><label class="col-sm-3 col-form-label col-form-label-sm"><?= Support::h($t('edit.category')) ?></label><div class="col-sm-9"><input class="form-control form-control-sm" name="category" value="<?= Support::h($app['category']) ?>"></div></div>
            <div class="form-group form-check"><input class="form-check-input" type="checkbox" name="enabled" id="enabled" <?= $app['enabled'] ? 'checked' : '' ?>><label class="form-check-label" for="enabled"><?= Support::h($t('edit.enabled')) ?></label></div>
            <fieldset><legend><?= Support::h($t('edit.download_source')) ?></legend>
                <div class="form-group kw-url-row"><label class="col-form-label col-form-label-sm"><input type="radio" checked> <?= Support::h($t('edit.url')) ?></label><input class="form-control form-control-sm" name="download_url_template" value="<?= Support::h($app['download_url_template']) ?>"><a class="btn btn-sm btn-light border kw-variables-button" href="index.php?variables=<?= (int)$app['id'] ?>"><?= Support::h($t('button.variables')) ?></a></div>

                <input type="hidden" name="beta_policy" value="<?= Support::h($app['beta_policy'] ?? 'default') ?>">
            </fieldset>
            <fieldset><legend><?= Support::h($t('edit.update_behavior')) ?></legend>
                <div class="form-group mb-2"><label class="mr-3"><input type="radio" name="update_mode" value="download" data-update-mode <?= ($app['update_mode'] ?? 'download') === 'download' ? 'checked' : '' ?>> <?= Support::h($t('edit.download_update')) ?></label><label><input type="radio" name="update_mode" value="notify" data-update-mode <?= ($app['update_mode'] ?? 'download') === 'notify' ? 'checked' : '' ?>> <?= Support::h($t('edit.notify_only')) ?></label></div>
            </fieldset>
            <fieldset id="downloadLocationFields"><legend><?= Support::h($t('edit.download_location')) ?></legend>
                <div class="form-group mb-2"><label class="mr-3"><input type="radio" name="target_type" value="file" <?= ($app['target_type'] ?? 'file') === 'file' ? 'checked' : '' ?>> <?= Support::h($t('edit.save_to_file')) ?></label><label><input type="radio" name="target_type" value="folder" <?= ($app['target_type'] ?? 'file') === 'folder' ? 'checked' : '' ?>> <?= Support::h($t('edit.save_in_folder')) ?></label></div>
                <div class="form-group row"><label class="col-sm-3 col-form-label col-form-label-sm"><?= Support::h($t('edit.target')) ?></label><div class="col-sm-9"><div class="input-group input-group-sm"><input class="form-control" id="targetPath" name="target_path" value="<?= Support::h($app['target_path']) ?>"><div class="input-group-append"><button class="btn btn-light border" id="browseTarget" type="button">...</button></div></div></div></div>
            </fieldset>
        </div>
        <div class="kw-formbody kw-tab-pane" data-pane="advanced">
            <fieldset><legend><?= Support::h($t('edit.downloading')) ?></legend>
                <div class="form-group row mb-1">
                    <label class="col-sm-3 col-form-label col-form-label-sm" for="httpReferer"><?= Support::h($t('edit.spoof_referer')) ?></label>
                    <div class="col-sm-9"><input class="form-control form-control-sm" id="httpReferer" name="http_referer" value="<?= Support::h($app['http_referer'] ?? '') ?>"></div>
                </div>
                <div class="form-group row mb-1">
                    <label class="col-sm-3 col-form-label col-form-label-sm" for="httpUserAgent"><?= Support::h($t('edit.user_agent')) ?></label>
                    <div class="col-sm-9"><input class="form-control form-control-sm" id="httpUserAgent" name="http_user_agent" value="<?= Support::h($app['http_user_agent'] ?? '') ?>"></div>
                </div>
            </fieldset>
            <fieldset><legend><?= Support::h($t('edit.update_detection')) ?></legend>
                <div class="form-group form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="ignore_missing_file" id="ignoreMissing" <?= !empty($app['ignore_missing_file']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="ignoreMissing"><?= Support::h($t('edit.ignore_missing')) ?></label>
                </div>
                <div class="form-group row mb-1">
                    <label class="col-sm-7 col-form-label col-form-label-sm" for="changeIndicator"><?= Support::h($t('edit.change_indicator')) ?></label>
                    <div class="col-sm-5">
                        <select class="form-control form-control-sm" id="changeIndicator" name="change_indicator">
                            <?php $indicator = (string)($app['change_indicator'] ?? 'version'); if (!in_array($indicator, $appVarNames, true)) $indicator = in_array('version', $appVarNames, true) ? 'version' : (string)($appVarNames[0] ?? ''); ?>
                            <?php if (!$appVarNames): ?>
                                <option value=""><?= Support::h($t('edit.no_variables')) ?></option>
                            <?php endif; ?>
                            <?php foreach ($appVarNames as $varName): ?>
                                <option value="<?= Support::h($varName) ?>" <?= $indicator === $varName ? 'selected' : '' ?>><?= Support::h($varName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </fieldset>
        </div>
        <div class="kw-formbody kw-tab-pane" data-pane="commands">
            <div class="form-group form-check">
                <input class="form-check-input" type="checkbox" name="command_enabled" id="commandEnabled" <?= !empty($app['command_enabled']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="commandEnabled"><?= Support::h($t('edit.run_after_download')) ?></label>
            </div>
            <div class="form-group">
                <label for="commandScript"><?= Support::h($t('edit.bash_script')) ?></label>
                <textarea class="form-control form-control-sm kw-command-script" id="commandScript" name="command_script" rows="12" spellcheck="false"><?= Support::h($app['command_script'] ?? '') ?></textarea>
            </div>
            <p class="kw-muted"><?= Support::h($t('edit.placeholders')) ?></p>
        </div>
        <div class="kw-formbody kw-tab-pane" data-pane="information">
            <div class="form-group row">
                <label class="col-sm-2 col-form-label col-form-label-sm" for="website"><?= Support::h($t('edit.website')) ?></label>
                <div class="col-sm-10"><input class="form-control form-control-sm" id="website" name="website" value="<?= Support::h($app['website'] ?? '') ?>"></div>
            </div>
            <div class="form-group">
                <label for="notes"><?= Support::h($t('edit.notes')) ?></label>
                <textarea class="form-control form-control-sm" id="notes" name="notes" rows="10"><?= Support::h($app['notes'] ?? '') ?></textarea>
            </div>
            <p class="kw-muted mb-1"><?= Support::h($t('edit.last_checked')) ?> <?= Support::h($app['last_checked'] ?? '') ?></p>
            <p class="kw-muted"><?= Support::h($t('edit.current_url')) ?> <?= Support::h($app['current_download_url'] ?? '') ?></p>
        </div>
        <div class="kw-formbody kw-tab-pane" data-pane="logs">
            <?php if ($commandLog): ?>
                <div class="kw-log-header">
                    <div>
                        <strong><?= Support::h($t('logs.last_command_log')) ?></strong>
                        <span><?= Support::h($commandLog['updated_at'] ?? '') ?></span>
                        <?php if (($commandLog['exit_code'] ?? null) !== null): ?><span><?= Support::h($t('logs.exit', ['code' => (int)$commandLog['exit_code']])) ?></span><?php endif; ?>
                    </div>
                    <a class="btn btn-sm btn-light border" href="index.php?action=download_command_log&id=<?= (int)$app['id'] ?>"><?= Support::h($t('button.download_json')) ?></a>
                </div>
                <label class="small mb-1" for="commandLogText"><?= Support::h($t('logs.log')) ?></label>
                <textarea class="form-control form-control-sm kw-log-text" id="commandLogText" rows="10" readonly><?= Support::h($commandLog['log_text'] ?? '') ?></textarea>
            <?php else: ?>
                <div class="kw-empty-log"><?= Support::h($t('logs.empty')) ?></div>
            <?php endif; ?>
        </div>
        <div class="kw-window-actions"><button class="btn btn-primary btn-sm" type="submit"><?= Support::h($t('button.ok')) ?></button><a class="btn btn-light border btn-sm" href="index.php"><?= Support::h($t('button.cancel')) ?></a></div>
    </form>
</div>
<?php endif; ?>

<?php if ($varApp): $vars = $repository->variables((int)$varApp['id']); ?>
<div class="kw-dialog wide" data-close-url="index.php?edit=<?= (int)$varApp['id'] ?>">
    <form method="post" class="kw-window kw-variable-window">
        <input type="hidden" name="action" value="save_variables">
        <input type="hidden" name="app_id" value="<?= (int)$varApp['id'] ?>">
        <div class="kw-window-title"><?= Support::h($t('variables.title', ['name' => $varApp['name']])) ?><a href="index.php?edit=<?= (int)$varApp['id'] ?>">x</a></div>
        <p class="small kw-variable-help"><?= Support::h($t('variables.help')) ?></p>
        <div class="kw-variable-editor">
            <aside class="kw-variable-sidebar">
                <div class="kw-variable-sidebar-title"><?= Support::h($t('variables.sidebar')) ?></div>
                <div class="kw-variable-list" id="variableList"></div>
                <div class="kw-variable-buttons">
                    <button class="btn btn-sm btn-light border" id="addVariable" type="button">+</button>
                    <button class="btn btn-sm btn-light border" id="removeVariable" type="button">-</button>
                </div>
            </aside>
            <section class="kw-variable-detail" id="variableRows">
            <?php if (!$vars) $vars = [['name' => 'url', 'kind' => 'regex', 'url' => '', 'post_data' => '', 'search' => '', 'start_text' => '', 'end_text' => '', 'regex' => '', 'regex_flags' => 'is', 'text_value' => ''], ['name' => 'version', 'kind' => 'regex', 'url' => '', 'post_data' => '', 'search' => '', 'start_text' => '', 'end_text' => '', 'regex' => '', 'regex_flags' => 'is', 'text_value' => '']]; ?>
            <?php foreach ($vars as $var): ?>
                <div class="kw-var-row" data-var-row>
                    <div class="kw-var-fields">
                        <div class="form-row mb-2">
                            <div class="col-sm-3"><input name="variables[name][]" class="form-control form-control-sm" value="<?= Support::h($var['name']) ?>"></div>
                            <div class="col-sm-3"><select name="variables[kind][]" class="form-control form-control-sm" data-var-kind><option value="regex" <?= $var['kind'] === 'regex' ? 'selected' : '' ?>><?= Support::h($t('variables.kind_regex')) ?></option><option value="startend" <?= $var['kind'] === 'startend' ? 'selected' : '' ?>><?= Support::h($t('variables.kind_startend')) ?></option><option value="text" <?= $var['kind'] === 'text' ? 'selected' : '' ?>><?= Support::h($t('variables.kind_text')) ?></option></select></div>
                            <div class="col-sm-6 kw-var-mode kw-var-url"><input name="variables[url][]" class="form-control form-control-sm" placeholder="<?= Support::h($t('variables.contents_from_url')) ?>" value="<?= Support::h($var['url']) ?>"></div>
                        </div>
                        <input name="variables[search][]" class="form-control form-control-sm mb-1 kw-var-mode kw-var-regex" placeholder="<?= Support::h($t('variables.search_within')) ?>" value="<?= Support::h($var['search']) ?>">
                        <div class="form-row mb-1 kw-var-mode kw-var-startend">
                            <div class="col"><input name="variables[start_text][]" class="form-control form-control-sm" placeholder="<?= Support::h($t('variables.start_text')) ?>" value="<?= Support::h($var['start_text'] ?? '') ?>"></div>
                            <div class="col"><input name="variables[end_text][]" class="form-control form-control-sm" placeholder="<?= Support::h($t('variables.end_text')) ?>" value="<?= Support::h($var['end_text'] ?? '') ?>"></div>
                        </div>
                        <div class="input-group input-group-sm mb-1 kw-var-mode kw-var-regex kw-regex-line">
                            <input name="variables[regex][]" class="form-control" placeholder="<?= Support::h($t('variables.use_regex')) ?>" value="<?= Support::h($var['regex']) ?>">
                            <input type="hidden" name="variables[regex_flags][]" value="<?= Support::h($var['regex_flags'] ?? 'is') ?>" data-regex-flags-value>
                            <details class="kw-regex-flags">
                                <summary><span>/</span> <strong data-regex-flags-label><?= Support::h($var['regex_flags'] ?? 'is') ?></strong></summary>
                                <div class="kw-regex-flags-menu">
                                    <?php $regexFlags = (string)($var['regex_flags'] ?? 'is'); ?>
                                    <label><input type="checkbox" value="g" <?= str_contains($regexFlags, 'g') ? 'checked' : '' ?>> <strong><?= Support::h($t('regex.global')) ?></strong><small><?= Support::h($t('regex.global_help')) ?></small></label>
                                    <label><input type="checkbox" value="m" <?= str_contains($regexFlags, 'm') ? 'checked' : '' ?>> <strong><?= Support::h($t('regex.multiline')) ?></strong><small><?= Support::h($t('regex.multiline_help')) ?></small></label>
                                    <label><input type="checkbox" value="i" <?= str_contains($regexFlags, 'i') ? 'checked' : '' ?>> <strong><?= Support::h($t('regex.insensitive')) ?></strong><small><?= Support::h($t('regex.insensitive_help')) ?></small></label>
                                    <label><input type="checkbox" value="s" <?= str_contains($regexFlags, 's') ? 'checked' : '' ?>> <strong><?= Support::h($t('regex.singleline')) ?></strong><small><?= Support::h($t('regex.singleline_help')) ?></small></label>
                                </div>
                            </details>
                        </div>
                        <textarea name="variables[text_value][]" class="form-control form-control-sm mb-1 kw-var-mode kw-var-textarea" rows="6" placeholder="<?= Support::h($t('variables.kind_text')) ?>"><?= Support::h($var['text_value']) ?></textarea>
                        <textarea name="variables[post_data][]" class="form-control form-control-sm kw-var-mode kw-var-post" rows="1" placeholder="<?= Support::h($t('variables.post_data')) ?>"><?= Support::h($var['post_data']) ?></textarea>
                    </div>
                </div>
            <?php endforeach; ?>
            </section>
        </div>
        <div class="kw-window-actions"><button class="btn btn-primary btn-sm" type="submit"><?= Support::h($t('button.ok')) ?></button><a class="btn btn-light border btn-sm" href="index.php?edit=<?= (int)$varApp['id'] ?>"><?= Support::h($t('button.cancel')) ?></a></div>
    </form>
</div>
<?php endif; ?>

<div class="kw-file-browser" id="fileBrowser" hidden>
    <div class="kw-file-browser-window">
        <div class="kw-window-title"><?= Support::h($t('browser.title')) ?><a href="#" id="closeBrowser">x</a></div>
        <div class="kw-browser-body">
            <aside class="kw-browser-bookmarks">
                <div class="kw-browser-bookmarks-title">
                    <strong><?= Support::h($t('browser.bookmarks')) ?></strong>
                    <button type="button" id="addBookmark" title="<?= Support::h($t('browser.bookmark_current')) ?>">+</button>
                </div>
                <div id="browserBookmarks"></div>
            </aside>
            <section class="kw-browser-main">
                <div class="kw-browser-path" id="browserPath"></div>
                <div class="kw-browser-list" id="browserList"></div>
            </section>
        </div>
        <div class="kw-window-actions">
            <button class="btn btn-primary btn-sm" id="chooseBrowserPath" type="button"><?= Support::h($t('button.ok')) ?></button>
            <button class="btn btn-light border btn-sm" id="cancelBrowser" type="button"><?= Support::h($t('button.cancel')) ?></button>
        </div>
    </div>
</div>

<div id="contextMenu" class="kw-context">
    <form method="post"><input type="hidden" name="id"><button name="action" value="download"><?= Support::h($t('context.update')) ?></button><button name="action" value="check"><?= Support::h($t('context.check')) ?></button><button name="action" value="force"><?= Support::h($t('context.force')) ?></button><hr><button name="action" value="run_command" data-command-action><?= Support::h($t('context.commands')) ?></button><a data-website-action target="_new" rel="noopener"><?= Support::h($t('context.visit')) ?></a><hr><a data-cmd="edit"><strong><?= Support::h($t('context.edit')) ?></strong></a><button name="action" value="delete" onclick="return confirm('<?= Support::h($t('confirm.delete_application')) ?>')"><?= Support::h($t('context.delete')) ?></button></form>
</div>

<script>
window.KW_I18N = <?= json_encode($jsI18n, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="assets/ketarinweb.js"></script>
</body>
</html>
