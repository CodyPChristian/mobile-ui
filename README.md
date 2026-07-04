# NativeUI Plugin for NativePHP Mobile

A NativePHP Mobile plugin

## Installation

```bash
composer require nativephp/native-ui
```

## Usage

```php
use Nativephp\NativeUi\Facades\NativeUI;

// Execute functionality
$result = NativeUI::execute(['option1' => 'value']);

// Get status
$status = NativeUI::getStatus();
```

## Listening for Events

```php
use Livewire\Attributes\On;

#[On('native:Nativephp\NativeUi\Events\NativeUICompleted')]
public function handleNativeUICompleted($result, $id = null)
{
    // Handle the event
}
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
use Nativephp\NativeUi\Elements\Button;

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

## License

MIT