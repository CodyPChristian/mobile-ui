<?php

use Native\Mobile\UI\Fonts\GoogleFonts;

/**
 * Unit coverage for GoogleFonts helpers used by `native:font`
 * (catalog parsing, config rewriting, filesystem utilities).
 * Command I/O and interactive prompts are exercised manually.
 */

// ── parseFaceKey / sortFaceKeys ─────────────────────────────────────────────

it('parses metadata face keys into weight and italic', function () {
    expect(GoogleFonts::parseFaceKey('400'))->toBe(['weight' => 400, 'italic' => false]);
    expect(GoogleFonts::parseFaceKey('700i'))->toBe(['weight' => 700, 'italic' => true]);
    expect(GoogleFonts::parseFaceKey('bogus'))->toBeNull();
});

it('sorts face keys by roman then italic, then weight', function () {
    expect(GoogleFonts::sortFaceKeys(['700i', '400', '700', '400i']))
        ->toBe(['400', '700', '400i', '700i']);
});

// ── parseFamilyStyles ───────────────────────────────────────────────────────

it('parses available face keys from the metadata fonts map', function () {
    $json = json_encode([
        'familyMetadataList' => [
            [
                'family' => 'Inter',
                'fonts' => [
                    '400' => [],
                    '700' => [],
                    '400i' => [],
                ],
            ],
            [
                'family' => 'Lobster',
                'fonts' => [
                    '400' => [],
                ],
            ],
        ],
    ]);

    expect(GoogleFonts::parseFamilyStyles($json))->toBe([
        'Inter' => ['400', '700', '400i'],
        'Lobster' => ['400'],
    ]);
});

it('strips the google xss prefix before parsing metadata', function () {
    $json = ")]}'\n".json_encode([
        'familyMetadataList' => [
            ['family' => 'Roboto', 'fonts' => ['400' => []]],
        ],
    ]);

    expect(GoogleFonts::parseFamilyStyles($json))->toBe([
        'Roboto' => ['400'],
    ]);
});

it('defaults to regular when a family has no fonts map', function () {
    $json = json_encode([
        'familyMetadataList' => [
            ['family' => 'Odd', 'fonts' => []],
        ],
    ]);

    expect(GoogleFonts::parseFamilyStyles($json))->toBe([
        'Odd' => ['400'],
    ]);
});

it('matches family names case-insensitively against a catalog', function () {
    $catalog = ['Inter', 'BBH Bartle', 'Rock Salt'];

    expect(GoogleFonts::matchFamily('inter', $catalog))->toBe('Inter');
    expect(GoogleFonts::matchFamily('BBH BARTLE', $catalog))->toBe('BBH Bartle');
    expect(GoogleFonts::matchFamily('Rock+Salt', $catalog))->toBe('Rock Salt');
    expect(GoogleFonts::matchFamily('Missing', $catalog))->toBeNull();
});

// ── styleLabel / familySpecForFaces / filenameFor / parseFaces ──────────────

it('labels face keys with weight names', function () {
    expect(GoogleFonts::styleLabel('400'))->toBe('Regular (400)');
    expect(GoogleFonts::styleLabel('400i'))->toBe('Italic (400i)');
    expect(GoogleFonts::styleLabel('700'))->toBe('Bold (700)');
    expect(GoogleFonts::styleLabel('700i'))->toBe('Bold Italic (700i)');
});

it('builds a css2 spec from specific face keys only', function () {
    expect(GoogleFonts::familySpecForFaces('Inter', ['400']))->toBe('Inter');
    expect(GoogleFonts::familySpecForFaces('Inter', ['400', '700']))->toBe('Inter:wght@400;700');
    expect(GoogleFonts::familySpecForFaces('Inter', ['400', '700i']))
        ->toBe('Inter:ital,wght@0,400;1,700');
    expect(GoogleFonts::familySpecForFaces('Inter', []))->toBe('Inter');
    expect(GoogleFonts::familySpecForFaces('Inter', ['bogus']))->toBeNull();
});

it('names files with the Google zip convention', function () {
    expect(GoogleFonts::filenameFor('Inter', 400, false))->toBe('Inter-Regular.ttf');
    expect(GoogleFonts::filenameFor('Inter', 700, true))->toBe('Inter-BoldItalic.ttf');
    expect(GoogleFonts::filenameFor('Rock Salt', 400, false))->toBe('RockSalt-Regular.ttf');
});

it('parses weight, style, and ttf url from @font-face blocks', function () {
    $css = <<<'CSS'
    @font-face {
      font-family: 'Inter';
      font-style: italic;
      font-weight: 700;
      src: url(https://fonts.gstatic.com/s/inter/v20/bolditalic.ttf) format('truetype');
    }
    @font-face {
      font-family: 'Inter';
      font-style: normal;
      font-weight: 400;
      src: url('https://fonts.gstatic.com/s/inter/v20/regular.ttf') format('truetype');
    }
    CSS;

    $faces = GoogleFonts::parseFaces($css);

    expect($faces)->toHaveCount(2);
    expect($faces[0])->toBe(['weight' => 700, 'italic' => true, 'url' => 'https://fonts.gstatic.com/s/inter/v20/bolditalic.ttf']);
    expect($faces[1])->toBe(['weight' => 400, 'italic' => false, 'url' => 'https://fonts.gstatic.com/s/inter/v20/regular.ttf']);
});

it('rejects woff2-only css responses', function () {
    $css = <<<'CSS'
    @font-face {
      font-family: 'Roboto';
      font-style: normal;
      font-weight: 400;
      src: url(https://fonts.gstatic.com/s/roboto/v30/regular.woff2) format('woff2');
    }
    CSS;

    expect(GoogleFonts::parseFaces($css))->toBeNull();
});

it('detects TrueType magic bytes', function () {
    expect(GoogleFonts::looksLikeTrueType("\x00\x01\x00\x00".str_repeat('x', 100)))->toBeTrue();
    expect(GoogleFonts::looksLikeTrueType('OTTO'.str_repeat('x', 100)))->toBeTrue();
    expect(GoogleFonts::looksLikeTrueType('<!DOCTYPE html>'))->toBeFalse();
    expect(GoogleFonts::looksLikeTrueType(''))->toBeFalse();
});

// ── keyFor / sanitizeKey / setFontAlias ─────────────────────────────────────

it('derives a lowercase config key from the family name', function () {
    expect(GoogleFonts::keyFor('Roboto'))->toBe('roboto');
    expect(GoogleFonts::keyFor('Rock Salt'))->toBe('rock-salt');
});

it('derives alias keys from download tokens', function () {
    expect(GoogleFonts::keyForToken('Inter-Regular'))->toBe('inter-regular');
    expect(GoogleFonts::keyForToken('Inter-SemiBold'))->toBe('inter-semi-bold');
    expect(GoogleFonts::keyForToken('SemiBold'))->toBe('semi-bold');
});

it('suggests alias keys from family plus token without mangling acronyms', function () {
    expect(GoogleFonts::aliasKeyFor('ADLaM Display', 'ADLaMDisplay-Regular'))->toBe('adlam-display');
    expect(GoogleFonts::aliasKeyFor('ADLaM Display', 'ADLaMDisplay-Bold'))->toBe('adlam-display-bold');
    expect(GoogleFonts::aliasKeyFor('Inter', 'Inter-SemiBold'))->toBe('inter-semi-bold');
});

it('normalizes user-supplied keys to lowercase hyphenated form', function () {
    expect(GoogleFonts::sanitizeKey('My Font'))->toBe('my-font');
    expect(GoogleFonts::sanitizeKey("o'brien"))->toBe('obrien');
    expect(GoogleFonts::sanitizeKey('$#!'))->toBeNull();
});

it('rewrites an existing named alias', function () {
    $config = "<?php return [\n    'fonts' => [\n        'default' => 'System',\n        'lobster' => 'Lobster-Regular',\n    ],\n];";

    $updated = GoogleFonts::setFontAlias($config, 'lobster', 'Lobster-Bold');

    expect($updated)->toContain("'lobster' => 'Lobster-Bold'");
    expect($updated)->toContain("'default' => 'System'");
});

it('appends a named alias after existing aliases', function () {
    $config = "<?php return [\n    'fonts' => [\n        'default' => 'Inter-Regular',\n    ],\n];";

    $updated = GoogleFonts::setFontAlias($config, 'lobster', 'Lobster-Regular');

    expect($updated)->toContain("'lobster' => 'Lobster-Regular'");
    expect($updated)->toContain("'default' => 'Inter-Regular'");
});

it('rewrites the fonts default alias', function () {
    $config = "<?php return [\n    'fonts' => [\n        'default' => 'System',\n    ],\n];";

    expect(GoogleFonts::setFontAlias($config, 'default', 'Inter-Regular'))
        ->toContain("'default' => 'Inter-Regular'");
});

it('prefers the top-level fonts block over a nested theme.fonts leftover', function () {
    $config = <<<'PHP'
<?php

return [
    'theme' => [
        'fonts' => [
            'default' => 'Nested-Regular',
        ],
    ],
    'fonts' => [
        'default' => 'System',
    ],
];
PHP;

    $updated = GoogleFonts::setFontAlias($config, 'lobster', 'Lobster-Regular');

    expect($updated)->toContain("'lobster' => 'Lobster-Regular'");
    expect($updated)->toContain("'default' => 'System'");
    expect($updated)->toContain("'default' => 'Nested-Regular'");
    expect(substr_count($updated, 'Lobster-Regular'))->toBe(1);
});

it('rewrites double-quoted fonts aliases', function () {
    $config = "<?php return [\n    \"fonts\" => [\n        \"default\" => \"System\",\n    ],\n];";

    $updated = GoogleFonts::setFontAlias($config, 'default', 'Inter-Regular');

    expect($updated)->toContain("'default' => 'Inter-Regular'");
});

it('appends into an empty fonts block that only has a comment', function () {
    $config = "<?php return [\n    'fonts' => [\n        // nothing yet\n    ],\n];";

    $updated = GoogleFonts::setFontAlias($config, 'lobster', 'Lobster-Regular');

    expect($updated)->toContain("'lobster' => 'Lobster-Regular'");
    expect($updated)->toMatch('/\/\/ nothing yet\n\s+\'lobster\'/');
});

it('resets the fonts block to a single default System entry', function () {
    $config = "<?php return [\n    'fonts' => [\n        'default' => 'Inter-Regular',\n        'lobster' => 'Lobster-Regular',\n    ],\n];";

    $updated = GoogleFonts::resetFontsBlock($config);

    expect($updated)->toContain("'default' => 'System'");
    expect($updated)->not->toContain('lobster');
    expect($updated)->not->toContain('Inter-Regular');
});

it('resets only the top-level fonts block', function () {
    $config = <<<'PHP'
<?php

return [
    'theme' => [
        'fonts' => [
            'default' => 'Nested-Regular',
        ],
    ],
    'fonts' => [
        'default' => 'Inter-Regular',
        'accent' => 'Lobster-Regular',
    ],
];
PHP;

    $updated = GoogleFonts::resetFontsBlock($config);

    expect($updated)->toContain("'default' => 'System'");
    expect($updated)->toContain("'default' => 'Nested-Regular'");
    expect($updated)->not->toContain('accent');
    expect($updated)->not->toContain('Inter-Regular');
});

it('returns null when resetting a config without a fonts block', function () {
    expect(GoogleFonts::resetFontsBlock('<?php return [];'))->toBeNull();
});

// ── directoryStats / fontFilesIn ────────────────────────────────────────────

it('reports zero stats for a missing fonts directory', function () {
    expect(GoogleFonts::directoryStats('/tmp/native-ui-fonts-missing-'.uniqid()))
        ->toBe(['count' => 0, 'bytes' => 0]);
});

it('lists only font container files in a directory', function () {
    $dir = sys_get_temp_dir().'/native-ui-fonts-list-'.uniqid();
    mkdir($dir);

    try {
        file_put_contents($dir.'/Inter-Regular.ttf', 'a');
        file_put_contents($dir.'/Inter-Bold.otf', 'b');
        file_put_contents($dir.'/readme.txt', 'ignored');

        $files = GoogleFonts::fontFilesIn($dir);

        expect($files)->toHaveCount(2);
        expect($files)->toContain($dir.'/Inter-Regular.ttf');
        expect($files)->toContain($dir.'/Inter-Bold.otf');
    } finally {
        foreach (glob($dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
});

it('counts font container files and totals their size', function () {
    $dir = sys_get_temp_dir().'/native-ui-fonts-'.uniqid();
    mkdir($dir);

    try {
        file_put_contents($dir.'/Inter-Regular.ttf', str_repeat('a', 1024));
        file_put_contents($dir.'/Inter-Bold.otf', str_repeat('b', 2048));
        file_put_contents($dir.'/readme.txt', 'ignored');

        expect(GoogleFonts::directoryStats($dir))->toBe([
            'count' => 2,
            'bytes' => 3072,
        ]);
    } finally {
        foreach (glob($dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
});

it('clears font container files and leftover temp siblings', function () {
    $dir = sys_get_temp_dir().'/native-ui-fonts-clear-'.uniqid();
    mkdir($dir);

    try {
        file_put_contents($dir.'/Inter-Regular.ttf', 'a');
        file_put_contents($dir.'/Inter-Bold.otf', 'b');
        file_put_contents($dir.'/Inter-Regular.ttf.native-font-tmp', 'tmp');
        file_put_contents($dir.'/readme.txt', 'keep');

        expect(GoogleFonts::clearFontFiles($dir))->toBe(3);
        expect(file_exists($dir.'/Inter-Regular.ttf'))->toBeFalse();
        expect(file_exists($dir.'/Inter-Bold.otf'))->toBeFalse();
        expect(file_exists($dir.'/Inter-Regular.ttf.native-font-tmp'))->toBeFalse();
        expect(file_exists($dir.'/readme.txt'))->toBeTrue();
    } finally {
        foreach (glob($dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
});

it('returns zero when clearing a missing fonts directory', function () {
    expect(GoogleFonts::clearFontFiles('/tmp/native-ui-fonts-missing-'.uniqid()))->toBe(0);
});

// ── commitFontFiles ─────────────────────────────────────────────────────────

it('commits pending font files and returns token sizes', function () {
    $dir = sys_get_temp_dir().'/native-ui-fonts-commit-'.uniqid();
    mkdir($dir);

    $body = "\x00\x01\x00\x00".str_repeat('a', 100);

    try {
        $result = GoogleFonts::commitFontFiles([
            $dir.'/Inter-Regular.ttf' => [
                'token' => 'Inter-Regular',
                'body' => $body,
                'bytes' => strlen($body),
            ],
        ]);

        expect($result)->toBe(['Inter-Regular' => strlen($body)]);
        expect(file_get_contents($dir.'/Inter-Regular.ttf'))->toBe($body);
        expect(file_exists($dir.'/Inter-Regular.ttf.native-font-tmp'))->toBeFalse();
        expect(file_exists($dir.'/Inter-Regular.ttf.native-font-bak'))->toBeFalse();
    } finally {
        foreach (glob($dir.'/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($dir);
    }
});

it('overwrites existing font files on successful commit', function () {
    $dir = sys_get_temp_dir().'/native-ui-fonts-overwrite-'.uniqid();
    mkdir($dir);

    $original = "\x00\x01\x00\x00".'old';
    $updated = "\x00\x01\x00\x00".'new-content';
    file_put_contents($dir.'/Inter-Regular.ttf', $original);

    try {
        $result = GoogleFonts::commitFontFiles([
            $dir.'/Inter-Regular.ttf' => [
                'token' => 'Inter-Regular',
                'body' => $updated,
                'bytes' => strlen($updated),
            ],
        ]);

        expect($result)->toBe(['Inter-Regular' => strlen($updated)]);
        expect(file_get_contents($dir.'/Inter-Regular.ttf'))->toBe($updated);
        expect(file_exists($dir.'/Inter-Regular.ttf.native-font-bak'))->toBeFalse();
    } finally {
        foreach (glob($dir.'/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($dir);
    }
});

it('rolls back staged files when a later path cannot be written', function () {
    $dir = sys_get_temp_dir().'/native-ui-fonts-rollback-'.uniqid();
    mkdir($dir);

    $original = "\x00\x01\x00\x00".'keep-me';
    $body = "\x00\x01\x00\x00".'new';
    file_put_contents($dir.'/A.ttf', $original);
    // A directory at the destination makes rename(temp → path) fail after A commits.
    mkdir($dir.'/B.ttf');

    try {
        $result = GoogleFonts::commitFontFiles([
            $dir.'/A.ttf' => [
                'token' => 'A',
                'body' => $body,
                'bytes' => strlen($body),
            ],
            $dir.'/B.ttf' => [
                'token' => 'B',
                'body' => $body,
                'bytes' => strlen($body),
            ],
        ]);

        expect($result)->toBeNull();
        expect(file_get_contents($dir.'/A.ttf'))->toBe($original);
        expect(is_dir($dir.'/B.ttf'))->toBeTrue();
        expect(file_exists($dir.'/A.ttf.native-font-tmp'))->toBeFalse();
        expect(file_exists($dir.'/B.ttf.native-font-tmp'))->toBeFalse();
        expect(file_exists($dir.'/A.ttf.native-font-bak'))->toBeFalse();
    } finally {
        foreach (glob($dir.'/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($dir);
    }
});

it('leaves existing files untouched when staging a missing parent fails', function () {
    $dir = sys_get_temp_dir().'/native-ui-fonts-stage-fail-'.uniqid();
    mkdir($dir);

    $original = "\x00\x01\x00\x00".'untouched';
    $body = "\x00\x01\x00\x00".'new';
    file_put_contents($dir.'/A.ttf', $original);

    try {
        $result = GoogleFonts::commitFontFiles([
            $dir.'/A.ttf' => [
                'token' => 'A',
                'body' => $body,
                'bytes' => strlen($body),
            ],
            $dir.'/missing-subdir/B.ttf' => [
                'token' => 'B',
                'body' => $body,
                'bytes' => strlen($body),
            ],
        ]);

        expect($result)->toBeNull();
        expect(file_get_contents($dir.'/A.ttf'))->toBe($original);
        expect(file_exists($dir.'/A.ttf.native-font-tmp'))->toBeFalse();
    } finally {
        foreach (glob($dir.'/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($dir);
    }
});

it('returns an empty map when committing nothing', function () {
    expect(GoogleFonts::commitFontFiles([]))->toBe([]);
});
