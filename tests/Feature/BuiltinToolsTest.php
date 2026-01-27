<?php

use App\DTOs\ToolResult;
use App\Services\BuiltinTools\BashTool;
use App\Services\BuiltinTools\EditTool;
use App\Services\BuiltinTools\GlobTool;
use App\Services\BuiltinTools\GrepTool;
use App\Services\BuiltinTools\ReadTool;
use App\Services\BuiltinTools\WriteTool;
use Illuminate\Support\Facades\File;

describe('ReadTool', function () {
    beforeEach(function () {
        $this->tool = new ReadTool;
        $this->testDir = storage_path('app/test-files');
        File::ensureDirectoryExists($this->testDir);
    });

    afterEach(function () {
        File::deleteDirectory($this->testDir);
    });

    test('can read a file', function () {
        $filePath = $this->testDir.'/test.txt';
        file_put_contents($filePath, "Line 1\nLine 2\nLine 3");

        $result = $this->tool->execute(['file_path' => $filePath]);

        expect($result)->toBeInstanceOf(ToolResult::class)
            ->and($result->success)->toBeTrue()
            ->and($result->output)->toContain('Line 1')
            ->and($result->output)->toContain('Line 2')
            ->and($result->output)->toContain('Line 3');
    });

    test('returns error for non-existent file', function () {
        $result = $this->tool->execute(['file_path' => $this->testDir.'/nonexistent.txt']);

        expect($result->success)->toBeFalse()
            ->and($result->error)->toContain('File not found');
    });

    test('respects offset parameter', function () {
        $filePath = $this->testDir.'/test.txt';
        file_put_contents($filePath, "Line 1\nLine 2\nLine 3\nLine 4");

        $result = $this->tool->execute([
            'file_path' => $filePath,
            'offset' => 2,
        ]);

        expect($result->success)->toBeTrue()
            ->and($result->output)->not->toContain('| Line 1')
            ->and($result->output)->toContain('Line 2');
    });

    test('respects limit parameter', function () {
        $filePath = $this->testDir.'/test.txt';
        file_put_contents($filePath, "Line 1\nLine 2\nLine 3\nLine 4");

        $result = $this->tool->execute([
            'file_path' => $filePath,
            'limit' => 2,
        ]);

        expect($result->success)->toBeTrue()
            ->and($result->output)->toContain('Line 1')
            ->and($result->output)->toContain('Line 2')
            ->and($result->output)->not->toContain('| Line 3');
    });

    test('returns parameter schema', function () {
        $schema = $this->tool->getParameterSchema();

        expect($schema['type'])->toBe('object')
            ->and($schema['properties'])->toHaveKey('file_path')
            ->and($schema['required'])->toContain('file_path');
    });
});

describe('WriteTool', function () {
    beforeEach(function () {
        $this->tool = new WriteTool;
        $this->testDir = storage_path('app/test-files');
        File::ensureDirectoryExists($this->testDir);
    });

    afterEach(function () {
        File::deleteDirectory($this->testDir);
    });

    test('can write a file', function () {
        $filePath = $this->testDir.'/new-file.txt';
        $content = 'Hello, World!';

        $result = $this->tool->execute([
            'file_path' => $filePath,
            'content' => $content,
        ]);

        expect($result->success)->toBeTrue()
            ->and(file_exists($filePath))->toBeTrue()
            ->and(file_get_contents($filePath))->toBe($content);
    });

    test('creates nested directories', function () {
        $filePath = $this->testDir.'/nested/path/file.txt';
        $content = 'Test content';

        $result = $this->tool->execute([
            'file_path' => $filePath,
            'content' => $content,
        ]);

        expect($result->success)->toBeTrue()
            ->and(file_exists($filePath))->toBeTrue();
    });

    test('overwrites existing file', function () {
        $filePath = $this->testDir.'/existing.txt';
        file_put_contents($filePath, 'Old content');

        $result = $this->tool->execute([
            'file_path' => $filePath,
            'content' => 'New content',
        ]);

        expect($result->success)->toBeTrue()
            ->and(file_get_contents($filePath))->toBe('New content');
    });
});

describe('EditTool', function () {
    beforeEach(function () {
        $this->tool = new EditTool;
        $this->testDir = storage_path('app/test-files');
        File::ensureDirectoryExists($this->testDir);
    });

    afterEach(function () {
        File::deleteDirectory($this->testDir);
    });

    test('can replace string in file', function () {
        $filePath = $this->testDir.'/edit-test.txt';
        file_put_contents($filePath, 'Hello World');

        $result = $this->tool->execute([
            'file_path' => $filePath,
            'old_string' => 'World',
            'new_string' => 'Universe',
        ]);

        expect($result->success)->toBeTrue()
            ->and(file_get_contents($filePath))->toBe('Hello Universe');
    });

    test('fails when old_string not found', function () {
        $filePath = $this->testDir.'/edit-test.txt';
        file_put_contents($filePath, 'Hello World');

        $result = $this->tool->execute([
            'file_path' => $filePath,
            'old_string' => 'NotFound',
            'new_string' => 'Replacement',
        ]);

        expect($result->success)->toBeFalse()
            ->and($result->error)->toContain('not found');
    });

    test('fails when old_string is not unique', function () {
        $filePath = $this->testDir.'/edit-test.txt';
        file_put_contents($filePath, 'Hello Hello World');

        $result = $this->tool->execute([
            'file_path' => $filePath,
            'old_string' => 'Hello',
            'new_string' => 'Hi',
        ]);

        expect($result->success)->toBeFalse()
            ->and($result->error)->toContain('not unique');
    });

    test('can replace all with replace_all flag', function () {
        $filePath = $this->testDir.'/edit-test.txt';
        file_put_contents($filePath, 'Hello Hello World');

        $result = $this->tool->execute([
            'file_path' => $filePath,
            'old_string' => 'Hello',
            'new_string' => 'Hi',
            'replace_all' => true,
        ]);

        expect($result->success)->toBeTrue()
            ->and(file_get_contents($filePath))->toBe('Hi Hi World');
    });
});

describe('GlobTool', function () {
    beforeEach(function () {
        $this->tool = new GlobTool;
        $this->testDir = storage_path('app/test-files');
        File::ensureDirectoryExists($this->testDir);
        File::ensureDirectoryExists($this->testDir.'/subdir');

        file_put_contents($this->testDir.'/file1.php', '<?php');
        file_put_contents($this->testDir.'/file2.php', '<?php');
        file_put_contents($this->testDir.'/file3.txt', 'text');
        file_put_contents($this->testDir.'/subdir/nested.php', '<?php');
    });

    afterEach(function () {
        File::deleteDirectory($this->testDir);
    });

    test('can find files with pattern', function () {
        $result = $this->tool->execute([
            'pattern' => '*.php',
            'path' => $this->testDir,
        ]);

        expect($result->success)->toBeTrue()
            ->and($result->output)->toContain('file1.php')
            ->and($result->output)->toContain('file2.php')
            ->and($result->output)->not->toContain('file3.txt');
    });

    test('can find files recursively', function () {
        $result = $this->tool->execute([
            'pattern' => '**/*.php',
            'path' => $this->testDir,
        ]);

        expect($result->success)->toBeTrue()
            ->and($result->output)->toContain('file1.php')
            ->and($result->output)->toContain('nested.php');
    });

    test('returns empty message when no matches', function () {
        $result = $this->tool->execute([
            'pattern' => '*.xyz',
            'path' => $this->testDir,
        ]);

        expect($result->success)->toBeTrue()
            ->and($result->output)->toContain('No files found');
    });
});

describe('GrepTool', function () {
    beforeEach(function () {
        $this->tool = new GrepTool;
        $this->testDir = storage_path('app/test-files');
        File::ensureDirectoryExists($this->testDir);

        file_put_contents($this->testDir.'/search1.php', "<?php\nclass MyClass {\n    public function test() {}\n}");
        file_put_contents($this->testDir.'/search2.php', "<?php\nclass AnotherClass {\n}");
        file_put_contents($this->testDir.'/search3.txt', 'Some text without class');
    });

    afterEach(function () {
        File::deleteDirectory($this->testDir);
    });

    test('can search for pattern in files', function () {
        $result = $this->tool->execute([
            'pattern' => 'class',
            'path' => $this->testDir,
        ]);

        expect($result->success)->toBeTrue()
            ->and($result->output)->toContain('search1.php')
            ->and($result->output)->toContain('search2.php');
    });

    test('supports case insensitive search', function () {
        $result = $this->tool->execute([
            'pattern' => 'MYCLASS',
            'path' => $this->testDir,
            'case_insensitive' => true,
        ]);

        expect($result->success)->toBeTrue()
            ->and($result->output)->toContain('search1.php');
    });

    test('can filter by file glob', function () {
        $result = $this->tool->execute([
            'pattern' => 'class',
            'path' => $this->testDir,
            'glob' => '*.php',
        ]);

        expect($result->success)->toBeTrue()
            ->and($result->output)->toContain('search1.php')
            ->and($result->output)->not->toContain('search3.txt');
    });

    test('returns empty message when no matches', function () {
        $result = $this->tool->execute([
            'pattern' => 'notfound123',
            'path' => $this->testDir,
        ]);

        expect($result->success)->toBeTrue()
            ->and($result->output)->toContain('No matches found');
    });
});

describe('BashTool', function () {
    beforeEach(function () {
        $this->tool = new BashTool;
    });

    test('can execute simple command', function () {
        $result = $this->tool->execute(['command' => 'echo "Hello World"']);

        expect($result->success)->toBeTrue()
            ->and($result->output)->toContain('Hello World');
    });

    test('returns error for failed command', function () {
        $result = $this->tool->execute(['command' => 'exit 1']);

        expect($result->success)->toBeFalse()
            ->and($result->metadata['exit_code'])->toBe(1);
    });

    test('blocks dangerous commands', function () {
        $result = $this->tool->execute(['command' => 'rm -rf /']);

        expect($result->success)->toBeFalse()
            ->and($result->error)->toContain('Dangerous command');
    });

    test('respects timeout', function () {
        $result = $this->tool->execute([
            'command' => 'sleep 5',
            'timeout' => 1,
        ]);

        expect($result->success)->toBeFalse()
            ->and($result->error)->toContain('timed out');
    });

    test('captures both stdout and stderr', function () {
        $result = $this->tool->execute(['command' => 'echo "out" && >&2 echo "err"']);

        expect($result->success)->toBeTrue()
            ->and($result->output)->toContain('out')
            ->and($result->output)->toContain('err');
    });
});
