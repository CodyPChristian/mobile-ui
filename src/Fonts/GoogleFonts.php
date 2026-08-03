<?php

namespace Native\Mobile\UI\Fonts;

/**
 * Helpers for the `native:font` Google Fonts downloader — metadata catalog
 * parsing, css2 URL specs, @font-face parsing, filename conventions, config
 * alias rewriting, and resources/fonts/ file helpers. Network fetch and
 * interactive prompts live in `Console\FontCommand`.
 */
class GoogleFonts
{
    /** css2 weight → conventional style name (matches Google's own zip naming). */
    public const WEIGHT_NAMES = [
        100 => 'Thin', 200 => 'ExtraLight', 300 => 'Light', 400 => 'Regular',
        500 => 'Medium', 600 => 'SemiBold', 700 => 'Bold', 800 => 'ExtraBold',
        900 => 'Black',
    ];

    /**
     * Parse a metadata `fonts` key (`400`, `700i`) into weight + italic.
     *
     * @return ?array{weight: int, italic: bool}
     */
    public static function parseFaceKey(string $key): ?array
    {
        if (! preg_match('/^([1-9]00)(i)?$/', $key, $match)) {
            return null;
        }

        $weight = (int) $match[1];

        if (! isset(self::WEIGHT_NAMES[$weight])) {
            return null;
        }

        return [
            'weight' => $weight,
            'italic' => ($match[2] ?? '') === 'i',
        ];
    }

    /**
     * Human label for a face key — `400` → `Regular (400)`, `700i` → `Bold Italic (700i)`.
     */
    public static function styleLabel(string $faceKey): string
    {
        $face = self::parseFaceKey($faceKey);

        if ($face === null) {
            return $faceKey;
        }

        $name = self::WEIGHT_NAMES[$face['weight']];

        if ($face['italic']) {
            $name = $name === 'Regular' ? 'Italic' : $name.' Italic';
        }

        return "{$name} ({$faceKey})";
    }

    /**
     * @param  list<string>  $faceKeys
     * @return list<string>
     */
    public static function sortFaceKeys(array $faceKeys): array
    {
        return collect($faceKeys)
            ->map(fn ($key) => (string) $key)
            ->filter(fn (string $key) => self::parseFaceKey($key) !== null)
            ->unique()
            ->sortBy(function (string $key) {
                $face = self::parseFaceKey($key);

                return sprintf('%d-%03d', $face['italic'] ? 1 : 0, $face['weight']);
            })
            ->values()
            ->all();
    }

    /**
     * Build the css2 family spec from metadata-style face keys (`400`,
     * `700i`). Only the requested faces are included. Returns null when
     * `$faceKeys` is non-empty but none parse — callers must not silently
     * fall back to Regular.
     *
     * @param  list<string>  $faceKeys
     */
    public static function familySpecForFaces(string $family, array $faceKeys): ?string
    {
        $name = str_replace(' ', '+', self::canonicalFamily($family));

        $faces = collect($faceKeys)
            ->map(fn ($key) => self::parseFaceKey((string) $key))
            ->filter()
            ->unique(fn (array $face) => $face['weight'].($face['italic'] ? 'i' : ''))
            ->sortBy(fn (array $face) => sprintf('%d-%03d', $face['italic'] ? 1 : 0, $face['weight']))
            ->values();

        if ($faces->isEmpty()) {
            return $faceKeys === [] ? $name : null;
        }

        if ($faces->count() === 1 && $faces[0]['weight'] === 400 && ! $faces[0]['italic']) {
            return $name;
        }

        if ($faces->every(fn (array $face) => ! $face['italic'])) {
            return $name.':wght@'.$faces->pluck('weight')->implode(';');
        }

        $tuples = $faces->map(
            fn (array $face) => ($face['italic'] ? '1' : '0').','.$face['weight'],
        );

        return $name.':ital,wght@'.$tuples->implode(';');
    }

    /**
     * Parse @font-face blocks out of a css2 response. One block per style for
     * truetype responses (no unicode-range subsetting). Dedupes by weight+style
     * keeping the last block — the latin subset is conventionally last.
     *
     * @return ?array<int, array{weight: int, italic: bool, url: string}>
     */
    public static function parseFaces(string $css): ?array
    {
        if (! str_contains($css, '@font-face')) {
            return null;
        }

        $faces = [];
        preg_match_all('/@font-face\s*{([^}]+)}/', $css, $blocks);

        foreach ($blocks[1] as $block) {
            if (! preg_match('/src:\s*url\(\s*[\'"]?(https:[^\'"\s)]+\.ttf)[\'"]?\s*\)/', $block, $src)) {
                continue;
            }
            preg_match('/font-weight:\s*(\d+)/', $block, $w);
            preg_match('/font-style:\s*(\w+)/', $block, $s);

            $weight = (int) ($w[1] ?? 400);
            $isItalic = ($s[1] ?? 'normal') === 'italic';

            $faces[$weight.($isItalic ? 'i' : '')] = [
                'weight' => $weight,
                'italic' => $isItalic,
                'url' => $src[1],
            ];
        }

        return empty($faces) ? null : array_values($faces);
    }

    /**
     * TrueType / OpenType sfnt magic at the start of a downloaded body.
     */
    public static function looksLikeTrueType(string $body): bool
    {
        if (strlen($body) < 4) {
            return false;
        }

        $magic = substr($body, 0, 4);

        return in_array($magic, ["\x00\x01\x00\x00", 'OTTO', 'true', 'typ1', 'ttcf'], true);
    }

    /**
     * Family name as typed → canonical spaced form. `+` reads as a space.
     */
    public static function canonicalFamily(string $family): string
    {
        return trim(str_replace('+', ' ', $family));
    }

    /** Inter + 700 + italic → Inter-BoldItalic.ttf (Google's zip convention). */
    public static function filenameFor(string $family, int $weight, bool $italic): string
    {
        $base = str_replace(' ', '', ucwords(self::canonicalFamily($family)));
        $style = self::WEIGHT_NAMES[$weight] ?? (string) $weight;

        if ($italic) {
            $style = $style === 'Regular' ? 'Italic' : $style.'Italic';
        }

        return "{$base}-{$style}.ttf";
    }

    /**
     * Parse fonts.google.com/metadata/fonts JSON into a map of canonical
     * family name → available face keys (`400`, `400i`, `700`, …).
     * Strips the optional XSS `)]}'` prefix Google sends.
     *
     * @return array<string, list<string>>
     */
    public static function parseFamilyStyles(string $json): array
    {
        $json = preg_replace("/^\)\]\}'?\n?/", '', $json) ?? $json;
        $data = json_decode($json, true);
        $list = is_array($data) ? ($data['familyMetadataList'] ?? []) : [];
        $styles = [];

        foreach ($list as $item) {
            $family = $item['family'] ?? null;

            if (! is_string($family) || $family === '') {
                continue;
            }

            $fonts = is_array($item['fonts'] ?? null) ? $item['fonts'] : [];
            $keys = self::sortFaceKeys(array_map(fn ($key) => (string) $key, array_keys($fonts)));

            $styles[$family] = $keys !== [] ? $keys : ['400'];
        }

        return $styles;
    }

    /**
     * Resolve a user-typed family against a catalog of canonical Google
     * Fonts names (case-insensitive). Returns null when nothing matches.
     *
     * @param  list<string>  $catalog
     */
    public static function matchFamily(string $typed, array $catalog): ?string
    {
        $needle = strtolower(self::canonicalFamily($typed));

        foreach ($catalog as $family) {
            if (strtolower($family) === $needle) {
                return $family;
            }
        }

        return null;
    }

    /**
     * Config alias key derived from a family name — lowercased, spaces
     * hyphenated: "Roboto" → roboto, "Rock Salt" → rock-salt.
     */
    public static function keyFor(string $family): string
    {
        return strtolower(str_replace(' ', '-', self::canonicalFamily($family)));
    }

    /**
     * Config alias key derived from a download token. CamelCase style
     * segments become hyphenated: Inter-SemiBold → inter-semi-bold.
     * Prefer {@see aliasKeyFor()} when the family name is known — it
     * avoids mangling acronyms in the family segment.
     */
    public static function keyForToken(string $token): string
    {
        $spaced = str_replace(['-', '_'], ' ', $token);
        $spaced = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $spaced) ?? $spaced;
        $spaced = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $spaced) ?? $spaced;

        return self::keyFor($spaced);
    }

    /**
     * Suggested fonts-array key for a downloaded token of a known family.
     * Regular → family key (`adlam-display`); other styles append the
     * style segment (`adlam-display-semi-bold`) so acronyms in the family
     * name are never re-split from the filename.
     */
    public static function aliasKeyFor(string $family, string $token): string
    {
        $familyKey = self::keyFor($family);
        $base = str_replace(' ', '', ucwords(self::canonicalFamily($family)));

        if ($token === "{$base}-Regular") {
            return $familyKey;
        }

        if (str_starts_with($token, $base.'-')) {
            $style = substr($token, strlen($base) + 1);

            return $familyKey.'-'.self::keyForToken($style);
        }

        return self::keyForToken($token);
    }

    /**
     * Normalize a user-supplied alias key to a config-safe form: lowercased,
     * spaces/underscores hyphenated, everything else outside a-z 0-9 and
     * hyphens stripped. Returns null when nothing usable remains.
     */
    public static function sanitizeKey(string $key): ?string
    {
        $key = preg_replace('/[\s_]+/', '-', strtolower(trim($key)));
        $key = preg_replace('/[^a-z0-9-]/', '', $key);
        $key = trim(preg_replace('/-+/', '-', $key), '-');

        return $key === '' ? null : $key;
    }

    /**
     * Upsert an alias in the shallowest `fonts` block of a config file's
     * contents (`'fonts' => ['<key>' => '<token>']`). Prefers the top-level
     * block over a nested `theme.fonts` leftover. For the `default` key only,
     * falls back to a legacy theme `font-family` token when no fonts block
     * exists. Returns null when nothing could be rewritten.
     */
    public static function setFontAlias(string $config, string $key, string $token): ?string
    {
        $block = self::findFontsBlock($config);

        if ($block === null) {
            if ($key !== 'default') {
                return null;
            }

            $safeToken = addcslashes($token, '\\$');
            $updated = preg_replace(
                "/(['\"])font-family\\1\\s*=>\\s*['\"][^'\"]*['\"]/",
                "'font-family' => '{$safeToken}'",
                $config,
                count: $replaced,
            );

            return $replaced ? $updated : null;
        }

        $inner = substr($config, $block['open'] + 1, $block['close'] - $block['open'] - 1);
        $quotedKey = preg_quote($key, '/');
        $safeToken = addcslashes($token, '\\$');

        $updatedInner = preg_replace(
            "/(['\"]){$quotedKey}\\1\\s*=>\\s*['\"][^'\"]*['\"]/",
            "'{$key}' => '{$safeToken}'",
            $inner,
            1,
            $replaced,
        );

        if ($replaced) {
            return substr($config, 0, $block['open'] + 1)
                .$updatedInner
                .substr($config, $block['close']);
        }

        $entryIndent = $block['indent'].'    ';
        $trimmed = rtrim($inner);

        if (trim($inner) === '') {
            $newInner = "\n{$entryIndent}'{$key}' => '{$safeToken}',\n{$block['indent']}";
        } else {
            $comma = self::fontsInnerNeedsComma($trimmed) ? ',' : '';
            $newInner = $trimmed.$comma."\n{$entryIndent}'{$key}' => '{$safeToken}',\n{$block['indent']}";
        }

        return substr($config, 0, $block['open'] + 1)
            .$newInner
            .substr($config, $block['close']);
    }

    /**
     * Locate the shallowest `fonts => [` block (single- or double-quoted).
     *
     * @return ?array{indent: string, open: int, close: int}
     */
    public static function findFontsBlock(string $config): ?array
    {
        if (! preg_match_all("/^([ \t]*)(['\"])fonts\\2\\s*=>\\s*\\[/m", $config, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $best = null;
        $bestIndentLen = PHP_INT_MAX;

        foreach ($matches[0] as $i => $full) {
            $indent = $matches[1][$i][0];
            $indentLen = strlen(str_replace("\t", '    ', $indent));

            if ($indentLen > $bestIndentLen) {
                continue;
            }

            $open = $full[1] + strlen($full[0]) - 1;
            $close = self::findMatchingBracket($config, $open);

            if ($close === null) {
                continue;
            }

            $bestIndentLen = $indentLen;
            $best = [
                'indent' => $indent,
                'open' => $open,
                'close' => $close,
            ];
        }

        return $best;
    }

    /**
     * Index of the `]` that closes the `[` at `$openPos`, respecting
     * strings and `//` line comments.
     */
    protected static function findMatchingBracket(string $config, int $openPos): ?int
    {
        $length = strlen($config);
        $depth = 0;
        $inString = null;
        $inLineComment = false;

        for ($i = $openPos; $i < $length; $i++) {
            $ch = $config[$i];
            $next = $i + 1 < $length ? $config[$i + 1] : '';

            if ($inLineComment) {
                if ($ch === "\n") {
                    $inLineComment = false;
                }

                continue;
            }

            if ($inString !== null) {
                if ($ch === '\\') {
                    $i++;

                    continue;
                }

                if ($ch === $inString) {
                    $inString = null;
                }

                continue;
            }

            if ($ch === '/' && $next === '/') {
                $inLineComment = true;
                $i++;

                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inString = $ch;

                continue;
            }

            if ($ch === '[') {
                $depth++;
            }

            if ($ch === ']') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Font container paths under a directory (ttf / otf / ttc), matching
     * the copy_assets bundler.
     *
     * @return list<string>
     */
    public static function fontFilesIn(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        return glob($dir.'/*.{ttf,otf,ttc}', GLOB_BRACE) ?: [];
    }

    /**
     * Count and total byte size of font container files in a directory.
     *
     * @return array{count: int, bytes: int}
     */
    public static function directoryStats(string $dir): array
    {
        $files = self::fontFilesIn($dir);
        $bytes = 0;

        foreach ($files as $file) {
            $bytes += (int) filesize($file);
        }

        return ['count' => count($files), 'bytes' => $bytes];
    }

    /**
     * Delete font container files (ttf / otf / ttc) and any leftover
     * `.native-font-tmp` / `.native-font-bak` siblings from a directory.
     * Returns the number of files removed.
     */
    public static function clearFontFiles(string $dir): int
    {
        if (! is_dir($dir)) {
            return 0;
        }

        $removed = 0;

        foreach (self::fontFilesIn($dir) as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        foreach (glob($dir.'/*.{native-font-tmp,native-font-bak}', GLOB_BRACE) ?: [] as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Replace the shallowest `fonts` block contents with a single
     * `'default' => '{token}'` entry (defaults to System). Returns null
     * when no fonts block can be found.
     */
    public static function resetFontsBlock(string $config, string $defaultToken = 'System'): ?string
    {
        $block = self::findFontsBlock($config);

        if ($block === null) {
            return null;
        }

        $safeToken = addcslashes($defaultToken, '\\$');
        $entryIndent = $block['indent'].'    ';
        $newInner = "\n{$entryIndent}'default' => '{$safeToken}',\n{$block['indent']}";

        return substr($config, 0, $block['open'] + 1)
            .$newInner
            .substr($config, $block['close']);
    }

    /**
     * Write pending font bodies atomically: stage each to a sibling temp
     * file, then rename into place (backing up overwrites). On any failure,
     * restore backups and remove staged/new files so the directory matches
     * its pre-call state. Returns null on failure, or token => byte sizes.
     *
     * @param  array<string, array{token: string, body: string, bytes: int}>  $pending
     * @return ?array<string, int>
     */
    public static function commitFontFiles(array $pending): ?array
    {
        if ($pending === []) {
            return [];
        }

        /** @var array<string, array{temp: string, backup: ?string, existed: bool, committed: bool, token: string, bytes: int}> */
        $staged = [];

        foreach ($pending as $path => $file) {
            $temp = $path.'.native-font-tmp';
            $parent = dirname($temp);

            if (! is_dir($parent) || @file_put_contents($temp, $file['body']) === false) {
                self::rollbackFontFiles($staged);

                return null;
            }

            $staged[$path] = [
                'temp' => $temp,
                'backup' => null,
                'existed' => is_file($path),
                'committed' => false,
                'token' => $file['token'],
                'bytes' => $file['bytes'],
            ];
        }

        foreach ($staged as $path => &$stage) {
            if ($stage['existed']) {
                $backup = $path.'.native-font-bak';

                if (! @rename($path, $backup)) {
                    self::rollbackFontFiles($staged);

                    return null;
                }

                $stage['backup'] = $backup;
            }

            // Refuse non-file destinations (e.g. a directory at $path) without
            // letting rename() emit a warning.
            if (file_exists($path) && ! is_file($path)) {
                self::rollbackFontFiles($staged);

                return null;
            }

            if (! @rename($stage['temp'], $path)) {
                self::rollbackFontFiles($staged);

                return null;
            }

            $stage['temp'] = '';
            $stage['committed'] = true;
        }
        unset($stage);

        foreach ($staged as $stage) {
            if ($stage['backup'] !== null && is_file($stage['backup'])) {
                @unlink($stage['backup']);
            }
        }

        $downloads = [];

        foreach ($staged as $stage) {
            $downloads[$stage['token']] = $stage['bytes'];
        }

        return $downloads;
    }

    /**
     * Undo a partial {@see commitFontFiles()} batch: drop temps, restore
     * backups, and remove newly committed files that had no prior original.
     *
     * @param  array<string, array{temp?: string, backup?: ?string, existed?: bool, committed?: bool}>  $staged
     */
    protected static function rollbackFontFiles(array $staged): void
    {
        foreach ($staged as $path => $stage) {
            $temp = $stage['temp'] ?? '';

            if ($temp !== '' && is_file($temp)) {
                @unlink($temp);
            }

            $backup = $stage['backup'] ?? null;
            $committed = (bool) ($stage['committed'] ?? false);

            if ($committed) {
                if (is_string($backup) && is_file($backup)) {
                    @unlink($path);
                    @rename($backup, $path);
                } elseif (! ($stage['existed'] ?? false) && is_file($path)) {
                    @unlink($path);
                }

                continue;
            }

            if (is_string($backup) && is_file($backup)) {
                if (! is_file($path)) {
                    @rename($backup, $path);
                } else {
                    @unlink($backup);
                }
            }
        }
    }

    /**
     * Whether the last meaningful line of a fonts-array body needs a
     * trailing comma before appending another entry.
     */
    protected static function fontsInnerNeedsComma(string $inner): bool
    {
        $lines = preg_split('/\R/', $inner) ?: [];

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);

            if ($line === '' || str_starts_with($line, '//') || str_starts_with($line, '#')) {
                continue;
            }

            return ! str_ends_with($line, ',');
        }

        return false;
    }
}
