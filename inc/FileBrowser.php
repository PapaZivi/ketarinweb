<?php
declare(strict_types=1);

final class FileBrowser
{
    public function __construct(private readonly ?Database $database = null)
    {
    }

    public function list(string $path = ''): array
    {
        if ($path === '') {
            return [
                'path' => '',
                'parent' => null,
                'entries' => $this->roots(),
                'bookmarks' => $this->bookmarks(),
            ];
        }

        $realPath = realpath($path);
        if ($realPath !== false && is_file($realPath)) {
            $realPath = dirname($realPath);
        }
        if ($realPath === false) {
            $parent = dirname($path);
            $realPath = realpath($parent);
        }
        if ($realPath === false || !is_dir($realPath)) {
            throw new RuntimeException('Folder not found.');
        }

        $entries = [];
        foreach (new DirectoryIterator($realPath) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $entries[] = [
                'name' => $entry->getFilename(),
                'path' => $entry->getPathname(),
                'type' => $entry->isDir() ? 'folder' : 'file',
            ];
        }

        usort($entries, static function (array $left, array $right): int {
            if ($left['type'] !== $right['type']) {
                return $left['type'] === 'folder' ? -1 : 1;
            }
            return strnatcasecmp($left['name'], $right['name']);
        });

        $parent = dirname($realPath);
        if ($parent === $realPath) {
            $parent = '';
        }

        return [
            'path' => $realPath,
            'parent' => $parent,
            'entries' => $entries,
            'bookmarks' => $this->bookmarks(),
        ];
    }

    public function addBookmark(string $path, string $name = ''): array
    {
        $realPath = realpath($path);
        if ($realPath === false || !is_dir($realPath)) {
            throw new RuntimeException('Bookmark folder not found.');
        }
        $label = trim($name) ?: basename($realPath) ?: $realPath;
        $this->pdo()->prepare('
            INSERT INTO file_bookmarks (name, path) VALUES (?, ?)
            ON CONFLICT(path) DO UPDATE SET name = excluded.name
        ')->execute([$label, $realPath]);
        return $this->bookmarks();
    }

    public function deleteBookmark(int $id): array
    {
        $this->pdo()->prepare('DELETE FROM file_bookmarks WHERE id = ?')->execute([$id]);
        return $this->bookmarks();
    }

    private function roots(): array
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $roots = [];
            foreach (range('A', 'Z') as $drive) {
                $path = $drive . ':\\';
                if (is_dir($path)) {
                    $roots[] = ['name' => $path, 'path' => $path, 'type' => 'folder'];
                }
            }
            return $roots;
        }

        return [['name' => '/', 'path' => '/', 'type' => 'folder']];
    }

    private function bookmarks(): array
    {
        return $this->pdo()
            ->query('SELECT id, name, path FROM file_bookmarks ORDER BY name COLLATE NOCASE')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    private function pdo(): PDO
    {
        if (!$this->database) {
            throw new RuntimeException('Database is not available.');
        }
        return $this->database->pdo();
    }
}
