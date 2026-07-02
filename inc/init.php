<?php
declare(strict_types=1);

const KW_ROOT = __DIR__ . '/..';
const KW_DATA = KW_ROOT . '/data';
const KW_DB = KW_DATA . '/ketarinweb.sqlite';

require __DIR__ . '/Support.php';
require __DIR__ . '/RuntimeRequirements.php';
require __DIR__ . '/I18n.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Flash.php';
require __DIR__ . '/FileBrowser.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/AppRepository.php';
require __DIR__ . '/SettingsRepository.php';
require __DIR__ . '/Mailer.php';
require __DIR__ . '/Updater.php';
require __DIR__ . '/KetarinImporter.php';

RuntimeRequirements::assertSatisfied();
