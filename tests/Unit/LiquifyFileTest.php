<?php

declare(strict_types=1);

/*
 * The helpers inside liquify.php, the one file a PHP website gets: reading a
 * config without running it, and turning stored lists into plain text.
 */

beforeEach(function (): void {
    if (! function_exists('liquify_defines')) {
        $file = sys_get_temp_dir().'/liquify-unit-'.getmypid().'.php';
        file_put_contents($file, strtr((string) file_get_contents(app_path('Modules/Sites/resources/stubs/liquify.php.stub')), [
            '{{DOMAIN}}' => 'example.com', '{{URL}}' => 'https://crm.example.com/api/v1/ingest/site/x', '{{SECRET}}' => 'secret',
        ]));
        require_once $file;
    }
});

it('reads settings that are written out, without running the file', function (): void {
    $values = liquify_defines(<<<'PHP'
        <?php
        define('DB_HOST', 'localhost');
        define( "DB_NAME" , 'shop\'s_db' ); // spaces and an escaped quote
        define('DB_PORT', 3307);
        define('DB_PASS', setting('db_pass', 'hidden')); // worked out when it runs: not taken
        echo 'never runs';
        PHP);

    expect($values)->toBe(['DB_HOST' => 'localhost', 'DB_NAME' => "shop's_db", 'DB_PORT' => '3307']);
});

it('reads constants of the file and prefixed names, never a class constant', function (): void {
    expect(liquify_defines(<<<'PHP'
        <?php
        const SBP_DB_HOST = 'localhost';
        const SBP_DB_NAME = 'sydney';
        class Shelf { const DB_USER = 'not-this'; }
        PHP))->toBe(['SBP_DB_HOST' => 'localhost', 'SBP_DB_NAME' => 'sydney', 'DB_HOST' => 'localhost', 'DB_NAME' => 'sydney']);
});

it('runs a config whose database name is worked out, even beside a written-out default', function (): void {
    $code = <<<'PHP'
        <?php
        if (!empty($ovr['db_name'])) { define('DB_NAME', $ovr['db_name']); }
        defined('DB_NAME') || define('DB_NAME', 'default_db');
        PHP;

    expect(liquify_scan($code)['worked_out'])->toBeTrue()
        ->and(liquify_written($code))->toBeNull()
        ->and(liquify_written("<?php define('DB_NAME', 'shop');"))->toBe(['DB_NAME' => 'shop']);
});

it('turns a stored list into names and leaves other values alone', function (): void {
    expect(liquify_value('[{"name":"manuscript.pdf","stored":"admin/data/x.pdf"},{"name":"cover.png"}]'))->toBe('manuscript.pdf, cover.png')
        ->and(liquify_value('{"utm_source":"google"}'))->toBe('{"utm_source":"google"}')
        ->and(liquify_value('Hello [world]'))->toBe('Hello [world]')
        ->and(liquify_value(7))->toBe(7);
});
