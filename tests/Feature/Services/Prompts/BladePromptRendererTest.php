<?php

use App\Services\Prompts\BladePromptRenderer;

beforeEach(function () {
    $this->renderer = app(BladePromptRenderer::class);
});

test('renders simple template without variables', function () {
    $template = 'Hello, World!';

    $result = $this->renderer->render($template);

    expect($result)->toBe('Hello, World!');
});

test('renders template with blade echo syntax', function () {
    $template = 'Hello, {{ $name }}!';

    $result = $this->renderer->render($template, ['name' => 'Claude']);

    expect($result)->toBe('Hello, Claude!');
});

test('renders template with multiple variables', function () {
    $template = 'Today is {{ $date }} and the time is {{ $time }}.';

    $result = $this->renderer->render($template, [
        'date' => '2024-01-15',
        'time' => '14:30',
    ]);

    expect($result)->toBe('Today is 2024-01-15 and the time is 14:30.');
});

test('renders template with blade conditionals', function () {
    $template = '@if($show_greeting)Hello!@endif';

    $resultWithTrue = $this->renderer->render($template, ['show_greeting' => true]);
    $resultWithFalse = $this->renderer->render($template, ['show_greeting' => false]);

    expect($resultWithTrue)->toBe('Hello!');
    expect($resultWithFalse)->toBe('');
});

test('renders template with blade foreach', function () {
    $template = '@foreach($items as $item)- {{ $item }}@endforeach';

    $result = $this->renderer->render($template, [
        'items' => ['apple', 'banana', 'cherry'],
    ]);

    expect($result)->toContain('- apple');
    expect($result)->toContain('- banana');
    expect($result)->toContain('- cherry');
});

test('handles missing variables with null coalescing', function () {
    $template = 'Hello, {{ $name ?? "Guest" }}!';

    $result = $this->renderer->render($template, []);

    expect($result)->toBe('Hello, Guest!');
});

test('renders empty string for empty template', function () {
    $result = $this->renderer->render('', []);

    expect($result)->toBe('');
});

describe('template sanitization', function () {
    test('strips @php directive blocks', function () {
        $template = 'Hello @php echo "dangerous"; @endphp World';

        $result = $this->renderer->render($template);

        expect($result)->toBe('Hello  World');
        expect($result)->not->toContain('dangerous');
    });

    test('strips @include directive', function () {
        $template = 'Hello @include("some.view") World';

        $result = $this->renderer->render($template);

        expect($result)->toBe('Hello  World');
    });

    test('strips @inject directive', function () {
        $template = 'Hello @inject("service", "App\\Service") World';

        $result = $this->renderer->render($template);

        expect($result)->toBe('Hello  World');
    });

    test('strips unescaped output syntax', function () {
        $template = 'Hello {!! $dangerous !!} World';

        $result = $this->renderer->render($template, ['dangerous' => '<script>alert("xss")</script>']);

        expect($result)->toBe('Hello  World');
        expect($result)->not->toContain('script');
    });

    test('strips raw PHP tags', function () {
        $template = 'Hello <?php echo "bad"; ?> World';

        $result = $this->renderer->render($template);

        expect($result)->toBe('Hello  World');
    });

    test('strips short PHP tags', function () {
        $template = 'Hello <?= "bad" ?> World';

        $result = $this->renderer->render($template);

        expect($result)->toBe('Hello  World');
    });

    test('allows safe directives like @if and @foreach', function () {
        $template = '@if($show)Visible @endif';

        $result = $this->renderer->render($template, [
            'show' => true,
        ]);

        expect($result)->toContain('Visible');
    });

    test('allows foreach directive', function () {
        $template = '@foreach($items as $item){{ $item }} @endforeach';

        $result = $this->renderer->render($template, [
            'items' => ['a', 'b'],
        ]);

        expect($result)->toContain('a');
        expect($result)->toContain('b');
    });

    test('preserves escaped output syntax', function () {
        $template = 'Hello {{ $name }} World';

        $result = $this->renderer->render($template, ['name' => 'Claude']);

        expect($result)->toBe('Hello Claude World');
    });
});

describe('detectDangerousDirectives', function () {
    test('detects @php directive', function () {
        $template = 'Hello @php echo "test"; @endphp';

        $dangerous = $this->renderer->detectDangerousDirectives($template);

        expect($dangerous)->toContain('@php');
        expect($dangerous)->toContain('@endphp');
    });

    test('detects @include directive', function () {
        $template = '@include("view.name")';

        $dangerous = $this->renderer->detectDangerousDirectives($template);

        expect($dangerous)->toContain('@include');
    });

    test('detects unescaped output', function () {
        $template = 'Hello {!! $var !!}';

        $dangerous = $this->renderer->detectDangerousDirectives($template);

        expect($dangerous)->toContain('{!! !!}');
    });

    test('detects raw PHP tags', function () {
        $template = '<?php echo "test"; ?>';

        $dangerous = $this->renderer->detectDangerousDirectives($template);

        expect($dangerous)->toContain('<?php');
    });

    test('returns empty array for safe template', function () {
        $template = 'Hello {{ $name }} @if($show)Visible@endif';

        $dangerous = $this->renderer->detectDangerousDirectives($template);

        expect($dangerous)->toBeEmpty();
    });
});
