# NativeUI Plugin for NativePHP Mobile

A NativePHP Mobile plugin

## Installation

```bash
composer require nativephp/mobile-ui
```

## Usage

```php
use Native\Mobile\UI\Facades\NativeUI;

// Execute functionality
$result = NativeUI::execute(['option1' => 'value']);

// Get status
$status = NativeUI::getStatus();
```

## Listening for Events

```php
use Livewire\Attributes\On;

#[On('native:Native\Mobile\UI\Events\NativeUICompleted')]
public function handleNativeUICompleted($result, $id = null)
{
    // Handle the event
}
```

## Theming & Colors

Theme tokens live in `config/native-ui.php` (publish with
`php artisan vendor:publish --tag=native-ui-config`). Every authored color —
theme tokens, element color props, and arbitrary-value classes — accepts the
same grammar:

```php
'light' => [
    'primary'   => 'violet-600',      // Tailwind palette name
    'secondary' => 'fuchsia-500/70',  // opacity modifier → tonal fill
    'surface'   => '#F8FAFC',         // plain hex (#RGB / #RRGGBB)
    'accent'    => '#00AAA680',       // CSS alpha hex (#RRGGBBAA)
],
```

Alpha hex is authored in CSS `#RRGGBBAA` order; the framework converts to the
native wire format. Dark mode is auto-derived from `light` (alpha preserved)
unless a `dark` block overrides specific tokens.

Disabled controls draw from the `surface-variant` (fill) and
`on-surface-variant` (label) tokens on both platforms — adjust those two
tokens to tune disabled contrast app-wide.

Icons accept platform enum overrides in Blade, matching the fluent API:

```blade
<native:icon :ios="Ios::House" :android="Android::Home" :size="24" />
```

## Accessibility

Every element accepts a screen-reader label and an optional hint, via Blade
attributes (`a11y-label` / `a11y-hint`, or the camelCase spellings
`a11yLabel` / `a11yHint`) or the fluent API (`->a11yLabel()` / `->a11yHint()`).
The label maps to `accessibilityLabel` on iOS and `contentDescription` on
Android; the hint maps to `accessibilityHint` on iOS and is appended to the
content description on Android.

```blade
<native:button icon="trash" a11y-label="Delete draft" a11y-hint="Deletes the draft permanently" @press="deleteDraft" />
```

```php
use Native\Mobile\UI\Elements\Button;

Button::make()
    ->icon('plus')
    ->a11yLabel('Add item')
    ->a11yHint('Adds a new item to the list')
    ->onPress('addItem');
```

Always set `a11y-label` on icon-only buttons, chips, and tabs — without
visible text there is nothing for VoiceOver / TalkBack to announce. Icons are
decorative (silent to screen readers) unless given an `a11y-label`. List items
with a trailing icon button take `trailing-a11y-label` (fluent:
`->trailingA11yLabel()`) to label that button separately from the row.

## Caret & Selection Reporting

> **Requires** a `nativephp/mobile` core that understands the `@selectionChange`
> directive and the `text_selection` callback kind (shipping alongside this
> feature). On older cores the Blade directive is ignored and the fluent
> `->onSelectionChange()` handler will be invoked with the wrong arity.

The text inputs (`<native:bare-text-input>`, `<native:outlined-text-input>`,
`<native:filled-text-input>`) can report caret position and text selection back
to PHP via `@selectionChange`. The handler receives the current text plus the
selection range:

```php
public function onCaretMove(string $text, int $selectionStart, int $selectionEnd)
{
    // $selectionStart === $selectionEnd when the caret is a plain cursor;
    // they differ when a range of text is selected.
}
```

Offsets are **Unicode code points** into the text (not UTF-16 units or bytes),
so emoji and other astral characters count as one — safe to feed straight into
`mb_substr(..., encoding: 'UTF-8')`.

```blade
<native:outlined-text-input
    label="Message"
    @selectionChange="onCaretMove"
    selection-debounce-ms="100"
/>
```

```php
use Native\Mobile\UI\Elements\OutlinedTextInput;

OutlinedTextInput::make()
    ->label('Message')
    ->onSelectionChange('onCaretMove')
    ->selectionDebounceMs(100);
```

Events are coalesced on the native side — by default at most one every **150ms**
while the caret moves (the trailing position always fires). Tune the window per
input with `selection-debounce-ms` / `selectionDebounceMs` (fluent:
`->selectionDebounceMs()`); when unset, nothing is serialized and the renderer
default applies. A value of `0` (or less) also means "use the default"; positive
values are floored at one frame (16ms).

> **Every event carries the full current text**, and every event costs a bridge
> frame plus a full component re-render. That is independent of the
> `native:model` sync mode: pairing `@selectionChange` with `native:model.blur`
> or `.debounce` still ships the field contents to PHP on the selection
> cadence, not the model cadence. Budget the debounce window accordingly.

`@selectionChange` is never emitted for `secure` inputs. The callback is not
serialized at all when `secure` is set, and both renderers additionally refuse
to emit — so caret telemetry can't leak password-field context.

### Contract details

- **Programmatic value pushes.** When PHP pushes a new `value` onto the input,
  the field is replaced wholesale and the caret drops at the end. Both platforms
  report that immediately as a single `(text, length, length)` event, bypassing
  the debounce. A handler that rewrites the bound model will therefore see one
  follow-up event.
- **Discontiguous selections** (multi-range, iOS) are reported as a single span
  from the lowest start to the highest end — the reported range covers the gaps,
  so `mb_substr($text, $start, $end - $start)` includes text the user did not
  select.
- **`read-only` inputs** report selection on Android (which keeps them focusable
  for copy) but not on iOS (where read-only implies disabled, so the field never
  focuses).

A typical use is typeahead / mention triggers, where `@change` alone can't tell
you *where* the user is typing:

```php
public function onCaretMove(string $text, int $start, int $end)
{
    // Look backwards from the caret for an "@mention" trigger.
    $before = mb_substr($text, 0, $start, 'UTF-8');

    if (preg_match('/@(\w*)$/u', $before, $m)) {
        $this->showMentionSuggestions($m[1]);
    } else {
        $this->hideMentionSuggestions();
    }
}
```

## Testing

Theme normalization and config write-back are pure PHP — no device, emulator,
or bridge round-trip required. `Theme::load()` / `Theme::merge()` resolve
authored color tokens (Tailwind names, `red-300/20` opacity modifiers, CSS
`#RRGGBBAA` alpha hex) to wire-format hex, auto-derive a dark block, and mirror
the effective set into `config('native-ui.theme.…')`. You can assert every step
of that in a unit test:

```php
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Native\Mobile\UI\Theme;

it('normalizes tokens and mirrors them into config', function () {
    Container::getInstance()->instance('config', new Repository);

    try {
        Theme::load([
            'light' => ['primary' => 'red-300', 'accent' => '#8B5CF680'],
            'dark'  => ['primary' => 'red-800'],
        ]);

        // Normalized tokens are readable via Theme::get('mode.token'):
        expect(Theme::get('light.primary'))->toBe('#FCA5A5');   // palette name
        expect(Theme::get('light.accent'))->toBe('#808B5CF6');  // CSS alpha → wire ARGB

        // …and mirrored back so core's theme() helper reads wire-format hex:
        expect(config('native-ui.theme.light.primary'))->toBe('#FCA5A5');
        expect(config('native-ui.theme.dark.primary'))->toBe('#991B1B');
    } finally {
        Container::setInstance(null);
    }
});
```

Element color and typography props share the same grammar and serialize the
same way. Elements expose `toArray(new CallbackRegistry)` (via
`NativeElementCollector`), so you can assert what lands on the wire:

```php
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\UI\Elements\Button;

it('serializes typography props on an element', function () {
    $props = Button::make('Save')->font('Inter-Bold')->toArray(new CallbackRegistry)['props'];

    expect($props['font_name'])->toBe('Inter-Bold');
});
```

### Keeping `Theme::pushToNative()` off the wire

`Theme::load()` / `merge()` fire a `NativeUI.Theme.Set` bridge call on every
change. In a full Laravel test app, `pushToNative()`'s `runningUnitTests()`
guard suppresses it. In **plain Pest** (no booted app), that guard can't trip,
so mute the bridge in `beforeEach()` — the same pattern the plugin's own tests
use — and `reset()` between tests:

```php
use Native\Mobile\JumpBridge;
use Native\Mobile\UI\Theme;

beforeEach(function () {
    JumpBridge::instance()->mute();
    Theme::reset();
});

afterEach(fn () => Theme::reset());
```

## License

MIT