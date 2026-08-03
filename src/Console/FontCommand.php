<?php

namespace Native\Mobile\UI\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Native\Mobile\UI\Fonts\GoogleFonts;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

/**
 * Download and manage Google Fonts for native-ui.
 *
 * No API key: family names and available weights/italics come from
 * fonts.google.com/metadata/fonts. The CSS API serves TTF sources to
 * non-browser user agents (it only serves woff2 to browsers that advertise
 * support), so we request `fonts.googleapis.com/css2?family=…`, parse the
 * `@font-face` src urls, and download the files. A 400 from the endpoint
 * means the family (or a requested weight) doesn't exist. Family names
 * are matched case-insensitively (`inter` → `Inter`).
 *
 * Files land as `resources/fonts/<Family>-<Style>.ttf` (e.g. Inter-Bold.ttf)
 * — the basename is the token used in Blade: `<native:text font="Inter-Bold">`.
 * The build's copy_assets hook bundles them into each native project.
 *
 * Aliases are written to the top-level `fonts` array in config/native-ui.php
 * (`config('native-ui.fonts')`), which Theme loads at boot. `--reset` clears
 * bundled font files (when any exist) and restores `default` => `System`.
 *
 * Google Fonts are libre-licensed (OFL / Apache / UFL), so bundling them in
 * your app is permitted; check the family page if you need specifics.
 *
 * Shared helpers (catalog parsing, filenames, config rewriting, font file
 * I/O) live in `Fonts\GoogleFonts` so they stay unit-testable.
 */
class FontCommand extends Command
{
    protected $signature = 'native:font
            {family? : Google Fonts family name, e.g. Lobster or "Rock Salt"}
            {--default : Set the chosen font as the app-wide default (fonts default alias)}
            {--clear-cache : Clear the cached Google Fonts catalog and exit (unless a family is also given)}
            {--force : Overwrite existing fonts / skip --reset confirmation}
            {--reset : Remove bundled fonts (if any) and reset default to System}';

    protected $description = 'Download Google Fonts, manage config aliases, or reset to System';

    private const METADATA_URL = 'https://fonts.google.com/metadata/fonts';

    /** Google CSS2 API — returns @font-face blocks whose src urls we download. */
    private const CSS_URL = 'https://fonts.googleapis.com/css2';

    /** Non-browser UA so Google serves TTF urls instead of browser-only woff2. */
    private const USER_AGENT = 'NativePHP-Fonts/1.0';

    /** @var array<string, list<string>> family => face keys */
    protected array $stylesByFamily = [];

    protected string $cache = 'google-fonts';

    protected int $expiry = 86400;

    public function handle(): int
    {
        if ($this->option('clear-cache')) {
            $this->forgetFontCache();
            $this->components->info('Cleared the Google Fonts catalog cache.');

            if ($this->argument('family') === null && ! $this->option('reset')) {
                return self::SUCCESS;
            }
        }

        if ($this->option('reset')) {
            return $this->resetFonts();
        }

        $fontsDir = base_path('resources/fonts');
        $configFile = config_path('native-ui.php');

        $this->stylesByFamily = spin(
            fn () => $this->fetchCatalog(),
            'Fetching available fonts from the <fg=blue>Google Webfonts</>...'
        );

        if ($this->stylesByFamily === []) {
            $this->components->error('Unable to fetch fonts from Google.');
            $this->components->info("You can register the font manually by placing the font file in <fg=blue>{$fontsDir}</> then register it in the {$configFile} file under the top-level <fg=blue>fonts</> array.");

            return self::FAILURE;
        }

        $family = $this->resolveFamily();

        if ($family === null) {
            return self::FAILURE;
        }

        if (! is_dir($fontsDir) && ! mkdir($fontsDir, 0755, true) && ! is_dir($fontsDir)) {
            $this->components->error("Couldn't create {$fontsDir} — check permissions.");

            return self::FAILURE;
        }

        $styles = $this->resolveStylesForFamily($family);
        $downloads = $this->downloadFamily($family, $styles, $fontsDir);

        if ($downloads === null) {
            return self::FAILURE;
        }

        $hints = $this->registerDownloadedFonts($family, $downloads);
        $this->printFontHints($hints);
        $this->printFontsDirectorySummary($fontsDir);

        return self::SUCCESS;
    }

    /**
     * Resolve the family from the CLI argument or an interactive search.
     */
    protected function resolveFamily(): ?string
    {
        $catalog = array_keys($this->stylesByFamily);
        $cliFamily = $this->argument('family');

        if (! empty($cliFamily)) {
            $matched = GoogleFonts::matchFamily((string) $cliFamily, $catalog);

            if ($matched === null) {
                $this->components->error("\"{$cliFamily}\" was not found in the Google Fonts catalog.");

                return null;
            }

            $this->components->info("Using <fg=green>{$matched}</>");

            return $matched;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Please provide a Google Fonts family name, e.g. <fg=blue>native:font Lobster</> or <fg=blue>native:font "Crimson Pro"</>');

            return null;
        }

        return search(
            label: 'Which Google Fonts family?',
            options: fn (string $value) => array_values(array_filter(
                $catalog,
                fn (string $name) => str_contains(strtolower($name), strtolower($value)),
            )),
            placeholder: '',
            hint: '<fg=blue>'.count($catalog).'</> fonts available.',
            required: true,
            scroll: 10,
            info: function (?string $name): string {
                if ($name === null || ! isset($this->stylesByFamily[$name])) {
                    return '';
                }

                $count = count($this->stylesByFamily[$name]);

                return $count.' '.Str::plural('style', $count);
            },
        );
    }

    /**
     * Offer an optional app-wide default among the downloaded tokens, then
     * prompt for a named fonts-array key for every remaining token.
     *
     * @param  array<string, int>  $downloads  token => file size in bytes
     * @return list<string> named config keys for Blade hints (excludes default)
     */
    protected function registerDownloadedFonts(string $family, array $downloads): array
    {
        $hints = [];
        /** @var list<array{0: string, 1: string, 2: string}> */
        $rows = [];
        $tokens = array_keys($downloads);
        $defaultToken = $this->resolveDefaultToken($tokens);

        if ($defaultToken !== null) {
            $key = $this->writeFontAlias('default', $defaultToken);
            $rows[] = [
                "<fg=blue>{$key}</>",
                "<fg=blue>{$defaultToken}</>",
                "<fg=blue>{$this->formatBytes($downloads[$defaultToken])}</>",
            ];
        }

        foreach ($tokens as $token) {
            if ($token === $defaultToken) {
                continue;
            }

            $key = $this->promptAndWriteNamedAlias($family, $token);
            $rows[] = [$key, $token, $this->formatBytes($downloads[$token])];
            $hints[] = $key;
        }

        if ($rows !== []) {
            $count = count($rows);
            $this->components->twoColumnDetail(
                "Added <fg=blue>{$count}</> ".Str::plural('font', $count).' to fonts array',
                'config/native-ui.php',
            );
            table(
                ['<fg=blue>Key</>', '<fg=blue>Value</>', '<fg=blue>Size</>'],
                $rows
            );
        }

        return array_values(array_unique(array_filter($hints)));
    }

    /**
     * @param  list<string>  $hints
     */
    protected function printFontHints(array $hints): void
    {
        if ($hints === []) {
            return;
        }

        $this->newLine();
        $this->line('Use in Blade with the font '.Str::plural('attribute', count($hints)).':');
        foreach ($hints as $hint) {
            $this->line('  \<native:text <fg=green;options=bold>font="'.$hint.'"</>>…\</native:text>');
        }
    }

    /**
     * Print how many font files are in resources/fonts/ and their total size
     * (every file there is bundled into the app, used or not).
     */
    protected function printFontsDirectorySummary(string $fontsDir): void
    {
        $stats = GoogleFonts::directoryStats($fontsDir);
        $label = Str::plural('font file', $stats['count']);

        $this->newLine();
        $this->components->info(
            "{$fontsDir} has <fg=blue>{$stats['count']}</> {$label} totaling <fg=blue>{$this->formatBytes($stats['bytes'])}</> — all of them ship with the app."
        );
    }

    /**
     * Which downloaded token becomes `fonts.default`, if any.
     *
     * @param  list<string>  $tokens
     */
    protected function resolveDefaultToken(array $tokens): ?string
    {
        if ($tokens === []) {
            return null;
        }

        if (count($tokens) === 1) {
            $token = $tokens[0];
            $asDefault = (bool) $this->option('default');

            if (! $asDefault && $this->input->isInteractive()) {
                $asDefault = confirm(
                    label: "Set {$token} as the app-wide default font?",
                    default: false,
                );
            }

            return $asDefault ? $token : null;
        }

        if ($this->option('default') && ! $this->input->isInteractive()) {
            return $this->preferRegularToken($tokens);
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        $options = ['' => 'None — leave the default font unchanged'];
        foreach ($tokens as $token) {
            $options[$token] = $token;
        }

        $choice = select(
            label: 'Which style should be the app-wide default?',
            options: $options,
            default: $this->option('default')
                ? $this->preferRegularToken($tokens)
                : '',
        );

        return $choice === '' ? null : $choice;
    }

    /**
     * Prefer a *-Regular token when choosing a multi-style default.
     *
     * @param  list<string>  $tokens
     */
    protected function preferRegularToken(array $tokens): string
    {
        return collect($tokens)->first(fn (string $token) => str_ends_with($token, '-Regular'))
            ?? $tokens[0];
    }

    /**
     * Prompt for a fonts-array key and write the alias for one token.
     */
    protected function promptAndWriteNamedAlias(string $family, string $token): string
    {
        $suggested = GoogleFonts::aliasKeyFor($family, $token);

        if (! $this->input->isInteractive()) {
            return $this->writeFontAlias($suggested, $token);
        }

        $answer = text(
            label: "Config key for {$token}",
            default: $suggested,
            placeholder: $suggested,
            required: true,
            validate: fn (string $value) => GoogleFonts::sanitizeKey($value) === null
                ? 'Use letters or numbers (normalized to lowercase a-z, 0-9, and hyphens).'
                : null,
        );

        return $this->writeFontAlias(GoogleFonts::sanitizeKey($answer) ?? $suggested, $token);
    }

    /**
     * Confirm when needed, delete font files if any exist, and rewrite the
     * top-level fonts array to only `'default' => 'System'`.
     */
    protected function resetFonts(): int
    {
        $fontsDir = base_path('resources/fonts');
        $stats = GoogleFonts::directoryStats($fontsDir);

        if ($stats['count'] > 0) {
            $label = Str::plural('font file', $stats['count']);

            if (! $this->option('force')) {
                if (! $this->input->isInteractive()) {
                    $this->components->error('Refusing to reset fonts non-interactively without --force.');

                    return self::FAILURE;
                }

                $confirmed = confirm(
                    label: "Delete {$stats['count']} {$label} and reset to System default?",
                    default: false,
                );

                if (! $confirmed) {
                    $this->components->info('Reset cancelled.');

                    return self::SUCCESS;
                }
            }

            $removed = GoogleFonts::clearFontFiles($fontsDir);
            $this->components->twoColumnDetail(
                'Cleared resources/fonts/',
                '<fg=red>'.$removed.' '.Str::plural('font file', $removed).' deleted</>',
            );
        }

        $configPath = $this->ensureNativeUiConfig();
        $snippet = "  'fonts' => ['default' => 'System'],";

        if (! file_exists($configPath)) {
            $this->warnManualFontsConfig("Couldn't find config/native-ui.php — set fonts yourself:", $snippet);

            return self::FAILURE;
        }

        $updated = GoogleFonts::resetFontsBlock(file_get_contents($configPath));

        if ($updated === null) {
            $this->warnManualFontsConfig("Couldn't find a top-level 'fonts' block in config/native-ui.php — set fonts yourself:", $snippet);

            return self::FAILURE;
        }

        if (file_put_contents($configPath, $updated) === false) {
            $this->warnManualFontsConfig("Couldn't write config/native-ui.php — set fonts yourself:", $snippet);

            return self::FAILURE;
        }

        $this->components->twoColumnDetail(
            'Reset fonts array to <fg=blue>default</> => <fg=blue>System</>',
            'config/native-ui.php',
        );

        $this->components->success('Font reset to System default.');

        return self::SUCCESS;
    }

    /**
     * Ensure config/native-ui.php exists and return its path.
     */
    protected function ensureNativeUiConfig(): string
    {
        $configPath = config_path('native-ui.php');

        if (! file_exists($configPath)) {
            $stub = dirname(__DIR__, 2).'/config/native-ui.php';

            if (file_exists($stub)) {
                copy($stub, $configPath);
                $this->components->twoColumnDetail('Published', 'config/native-ui.php');
            }
        }

        return $configPath;
    }

    /**
     * Write a fonts alias to config. Returns the key written (or the raw
     * token when the fonts block could not be rewritten).
     */
    protected function writeFontAlias(string $key, string $token): string
    {
        $configPath = $this->ensureNativeUiConfig();
        $snippet = "  'fonts' => ['{$key}' => '{$token}'],";

        if (! file_exists($configPath)) {
            $this->warnManualFontsConfig("Couldn't find config/native-ui.php — add the alias yourself:", $snippet);

            return $token;
        }

        $updated = GoogleFonts::setFontAlias(file_get_contents($configPath), $key, $token);

        if ($updated === null) {
            $this->warnManualFontsConfig("Couldn't find a top-level 'fonts' block in config/native-ui.php — add the alias yourself:", $snippet);

            return $token;
        }

        if (file_put_contents($configPath, $updated) === false) {
            $this->warnManualFontsConfig("Couldn't write config/native-ui.php — add the alias yourself:", $snippet);

            return $token;
        }

        return $key;
    }

    /**
     * Warn and print a manual config snippet when automatic rewrite fails.
     */
    protected function warnManualFontsConfig(string $message, string $snippet): void
    {
        $this->warn($message);
        $this->line($snippet);
    }

    /**
     * One style → download immediately. Multiple → multiselect with Regular
     * (400) preselected when available; it can be unselected, but at least
     * one style is required. Non-interactive runs download Regular when
     * present, otherwise the first available style.
     *
     * @return list<string>
     */
    protected function resolveStylesForFamily(string $family): array
    {
        $options = $this->stylesByFamily[$family] ?? ['400'];

        if (count($options) === 1) {
            return $options;
        }

        if (! $this->input->isInteractive()) {
            return in_array('400', $options, true)
                ? ['400']
                : [array_values($options)[0]];
        }

        $labels = [];

        foreach ($options as $key) {
            $labels[$key] = GoogleFonts::styleLabel($key);
        }

        // PHP casts numeric string keys to ints (`'400'` → `400`). The
        // default must use that same key type or Space can't unselect it
        // (toggle compares with !==).
        $default = [];

        foreach (array_keys($labels) as $key) {
            if ((string) $key === '400') {
                $default = [$key];

                break;
            }
        }

        $selected = GoogleFonts::sortFaceKeys(multiselect(
            label: "Which {$family} styles should be downloaded?",
            options: $labels,
            default: $default,
            required: 'Select at least one style.',
            hint: 'Spacebar to toggle',
            scroll: 10,
        ));

        return array_map(strval(...), $selected);
    }

    /**
     * Download the requested styles for one family into resources/fonts.
     * Fetches every face into memory, then commits via temp+rename so a
     * mid-batch failure never leaves partial files on disk. Returns an
     * empty array (success) when every face was skipped; null on error.
     *
     * @param  list<string>  $styles
     * @return ?array<string, int> token => file size in bytes
     */
    protected function downloadFamily(string $family, array $styles, string $fontsDir): ?array
    {
        if ($styles === []) {
            $this->components->error("\"{$family}\" has none of the requested styles on Google Fonts.");

            return null;
        }

        $faces = $this->fetchFaces($family, $styles);

        if ($faces === null) {
            $this->components->error("\"{$family}\" not found on Google Fonts (check the family name and ".Str::plural('style', count($styles)).'), or Google returned woff2 instead of TTF.');

            return null;
        }

        /** @var array<string, array{token: string, body: string, bytes: int}> */
        $pending = [];

        foreach ($faces as $face) {
            $filename = GoogleFonts::filenameFor($family, $face['weight'], $face['italic']);
            $path = $fontsDir.'/'.$filename;

            if (! $this->shouldWriteFontFile($path, $filename)) {
                continue;
            }

            $response = $this->fontHttp(timeout: 30)->get($face['url']);

            if ($response->failed()) {
                $this->components->error("Failed to download {$filename} for \"{$family}\"");

                return null;
            }

            $body = $response->body();

            if (! GoogleFonts::looksLikeTrueType($body)) {
                $this->components->error("Downloaded {$filename} doesn't look like a TrueType font — aborting.");

                return null;
            }

            $pending[$path] = [
                'token' => pathinfo($filename, PATHINFO_FILENAME),
                'body' => $body,
                'bytes' => strlen($body),
            ];
        }

        if ($pending === []) {
            $this->components->info('Nothing to download.');

            return [];
        }

        $downloads = GoogleFonts::commitFontFiles($pending);

        if ($downloads === null) {
            $this->components->error("Couldn't write font files for \"{$family}\" — no files were changed.");

            return null;
        }

        foreach ($downloads as $token => $bytes) {
            $this->components->twoColumnDetail(
                'resources/fonts/'.$token.'.ttf',
                $this->formatBytes($bytes),
            );
        }

        return $downloads;
    }

    /**
     * Whether an existing path should be overwritten. --force always wins;
     * interactive runs confirm; non-interactive skips unless --force.
     */
    protected function shouldWriteFontFile(string $path, string $filename): bool
    {
        if (! file_exists($path)) {
            return true;
        }

        if ($this->option('force')) {
            return true;
        }

        if ($this->input->isInteractive()) {
            return confirm(
                label: "{$filename} already exists — overwrite?",
                default: true,
            );
        }

        $this->components->twoColumnDetail(
            'resources/fonts/'.$filename,
            '<fg=yellow>skipped</> (exists — use --force)',
        );

        return false;
    }

    /**
     * Fetch the @font-face descriptors for the requested face keys.
     * Returns null when the family/style combination doesn't exist (HTTP 400)
     * or the response has no parseable TTF faces.
     *
     * @param  list<string>  $faceKeys
     * @return ?array<int, array{weight: int, italic: bool, url: string}>
     */
    protected function fetchFaces(string $family, array $faceKeys): ?array
    {
        $spec = GoogleFonts::familySpecForFaces($family, $faceKeys);

        if ($spec === null) {
            return null;
        }

        $url = self::CSS_URL.'?family='.$spec;

        // A non-browser UA makes Google serve truetype src urls instead of woff2.
        $response = $this->fontHttp(timeout: 15)->get($url);

        if ($response->failed()) {
            return null;
        }

        return GoogleFonts::parseFaces($response->body());
    }

    /**
     * Cached family → face-keys map. Plain arrays only — Collections/objects
     * unserialize as __PHP_Incomplete_Class from the file store. Failed or
     * empty fetches are never written to cache.
     *
     * @return array<string, list<string>>
     */
    protected function fetchCatalog(): array
    {
        $cached = cache()->store('file')->get($this->cache);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $response = $this->fontHttp(timeout: 15)->get(self::METADATA_URL);

        if ($response->failed()) {
            return [];
        }

        $styles = GoogleFonts::parseFamilyStyles($response->body());

        if ($styles !== []) {
            cache()->store('file')->put($this->cache, $styles, $this->expiry);
        }

        return $styles;
    }

    /**
     * Shared HTTP client for Google Fonts endpoints — explicit timeouts,
     * custom UA (required for TTF), retries on connection / 5xx only.
     */
    protected function fontHttp(int $timeout = 15): PendingRequest
    {
        return Http::timeout($timeout)
            ->connectTimeout(5)
            ->retry(
                [100, 500, 1000],
                throw: false,
                when: fn (Throwable $exception) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response?->serverError()),
            )
            ->withHeaders(['User-Agent' => self::USER_AGENT]);
    }

    protected function forgetFontCache(): void
    {
        cache()->store('file')->forget($this->cache);
    }

    protected function formatBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).' MB'
            : round($bytes / 1024).' KB';
    }
}
