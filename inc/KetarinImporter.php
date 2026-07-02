<?php
declare(strict_types=1);

final class KetarinImporter
{
    public function __construct(private readonly AppRepository $apps)
    {
    }

    public function import(string $file): int
    {
        $xml = simplexml_load_file($file);
        if (!$xml) {
            throw new RuntimeException('XML could not be read.');
        }
        $nodes = $xml->xpath('//*[local-name()="ApplicationJob" or local-name()="Application"]') ?: [];
        $count = 0;
        foreach ($nodes as $node) {
            $name = (string)($node->Name ?? $node->ApplicationName ?? '');
            if ($name === '') {
                continue;
            }
            $existing = $this->apps->findByName($name);
            $targetPath = (string)($node->TargetPath ?? $node->TargetFileName ?? '');
            $id = $this->apps->save([
                'id' => (int)($existing['id'] ?? 0),
                'name' => $name,
                'category' => (string)($node->Category ?? ''),
                'enabled' => '',
                'download_url_template' => (string)($node->FixedDownloadUrl ?? $node->DownloadUrl ?? '{url}'),
                'target_path' => $targetPath,
                'target_type' => $this->targetType($targetPath),
                'beta_policy' => 'default',
                'update_mode' => 'download',
                'command_enabled' => '',
                'command_script' => (string)($node->ExecuteCommand ?? ''),
            ]);
            $this->apps->setImportedLastUpdated($id, (string)($node->LastUpdated ?? ''));
            $this->apps->saveVariables($id, $this->extractVariables($node));
            $count++;
        }
        return $count;
    }

    private function targetType(string $targetPath): string
    {
        return $targetPath !== '' && (str_ends_with($targetPath, '/') || str_ends_with($targetPath, '\\')) ? 'folder' : 'file';
    }

    private function extractVariables(SimpleXMLElement $node): array
    {
        $rows = ['name' => [], 'kind' => [], 'url' => [], 'post_data' => [], 'search' => [], 'start_text' => [], 'end_text' => [], 'regex' => [], 'regex_flags' => [], 'text_value' => []];
        $variables = $node->xpath('.//*[local-name()="Variable" or local-name()="UrlVariable"]') ?: [];
        foreach ($variables as $var) {
            $name = $this->firstText($var, [
                './*[local-name()="Name"]',
                './*[local-name()="VariableName"]',
                'ancestor::*[local-name()="item"][1]/*[local-name()="key"]//*[local-name()="string"]',
                'ancestor::*[local-name()="KeyValuePairOfStringUrlVariable"][1]/*[local-name()="Key"]',
                'ancestor::*[local-name()="KeyValuePairOfStringVariable"][1]/*[local-name()="Key"]',
            ]);
            if ($name === '') {
                continue;
            }
            $type = $this->firstText($var, [
                './*[local-name()="VariableType"]',
                './*[local-name()="Type"]',
            ]) ?: '1';
            $kind = match ($type) {
                '0', 'StartEnd', 'ContentFromUrl' => 'startend',
                '2', 'Textual', 'TextualContent' => 'text',
                default => 'regex',
            };
            $rows['name'][] = $name;
            $rows['kind'][] = $kind;
            $rows['url'][] = $this->firstText($var, [
                './*[local-name()="Url"]',
                './*[local-name()="ContentFromUrl"]',
                './*[local-name()="ContentsUrl"]',
                './*[local-name()="ContentUrl"]',
                './*[local-name()="URL"]',
            ]);
            $rows['post_data'][] = $this->firstText($var, [
                './*[local-name()="PostData"]',
            ]);
            $rows['search'][] = $this->firstText($var, [
                './*[local-name()="SearchWithin"]',
                './*[local-name()="SearchString"]',
            ]);
            $rows['start_text'][] = $this->firstText($var, [
                './*[local-name()="StartText"]',
                './*[local-name()="Start"]',
            ]);
            $rows['end_text'][] = $this->firstText($var, [
                './*[local-name()="EndText"]',
                './*[local-name()="End"]',
            ]);
            $rows['regex'][] = $this->firstText($var, [
                './*[local-name()="RegularExpression"]',
                './*[local-name()="Regex"]',
                './*[local-name()="RegEx"]',
            ]);
            $rows['regex_flags'][] = 'is';
            $rows['text_value'][] = $this->firstText($var, [
                './*[local-name()="TextualContent"]',
                './*[local-name()="Text"]',
            ]);
        }
        return $rows;
    }

    private function firstText(SimpleXMLElement $node, array $queries): string
    {
        foreach ($queries as $query) {
            $result = $node->xpath($query) ?: [];
            foreach ($result as $match) {
                $value = trim((string)$match);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }
}
