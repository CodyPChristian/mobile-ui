# Text Input Platform Icons Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Blade support for platform-specific leading and trailing text-input icons, then prove the released bug and local fix in a reusable NativePHP app on iOS and Android.

**Architecture:** Keep icon selection in the existing PHP `IconResolver` path: `BaseTextInput::applyAttributes()` gathers shared/iOS/Android Blade values and delegates to `leadingIcon()` or `trailingIcon()`. A sibling Laravel app consumes the package through a symlinked Composer path repository and compares the Blade path with the already-working fluent builder path.

**Tech Stack:** PHP 8.4, Laravel, NativePHP Mobile 4, nativephp/mobile-ui, Pest, Composer path repositories, Xcode iOS Simulator, Android SDK Emulator.

## Global Constraints

- Work on branch `fix/text-input-platform-icons-from-blade` in `/Users/wojt/Code/wojt-janowski/mobile-ui`.
- Create the reusable sibling app at exactly `/Users/wojt/Code/wojt-janowski/nativephp-test-app`.
- Support outlined and filled text inputs, leading and trailing icons, iOS and Android resolution, camelCase and kebab-case attribute keys, and a shared fallback.
- Do not change Swift, Kotlin, the wire format, renderers, unrelated elements, select semantics, or badge behavior.
- Use test-first development: the focused package test must fail for the missing platform attributes before production code changes.
- Use a symlinked Composer path repository from the app to `../mobile-ui`.
- Verify both an iOS simulator build and an Android emulator build; report an unavailable SDK/device as a blocker, never as a pass.
- Do not publish packages, push branches, or open a pull request.

---

### Task 1: Package regression test and minimal fix

**Files:**
- Create: `tests/BaseTextInputPlatformIconTest.php`
- Modify: `src/Elements/BaseTextInput.php:101-110`

**Interfaces:**
- Consumes: `BaseTextInput::leadingIcon(?string $name, IosSymbol|string|null $ios, AndroidSymbol|string|null $android): static`, `BaseTextInput::trailingIcon(...)`, `Platform::set(?string $platform): void`, and `Element::getResolvedProps(CallbackRegistry $registry): array`.
- Produces: Blade attribute handling for `leadingIcon`/`leading-icon`, `leadingIconIos`/`leading-icon-ios`, `leadingIconAndroid`/`leading-icon-android`, and the equivalent trailing keys.

- [ ] **Step 1: Install package development dependencies on PHP 8.4**

Run:

```bash
php84 "$(command -v composer)" install --no-interaction
```

Expected: `vendor/bin/pest` and `vendor/bin/pint` exist. Do not update dependency constraints or commit `composer.lock` unless the repository already tracks it.

- [ ] **Step 2: Write the focused failing test**

Create `tests/BaseTextInputPlatformIconTest.php` with unique fixture enums, reset `Platform` after every test, and exercise real prop serialization:

```php
<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Icon\AndroidSymbol;
use Native\Mobile\Icon\IosSymbol;
use Native\Mobile\Platform;
use Native\Mobile\UI\Elements\FilledTextInput;
use Native\Mobile\UI\Elements\OutlinedTextInput;

enum TextInputAttrIos: string implements IosSymbol
{
    case Mail = 'envelope.fill';
}

enum TextInputAttrAndroid: string implements AndroidSymbol
{
    public function variant(): string
    {
        return 'filled';
    }

    case Mail = 'mail';
}

afterEach(fn () => Platform::set(null));

it('resolves kebab-case leading platform icon attributes', function (string $inputClass, string $platform, string $expectedIcon, ?string $expectedVariant) {
    Platform::set($platform);

    $input = new $inputClass;
    $input->applyAttributes([
        'leading-icon' => 'email',
        'leading-icon-ios' => TextInputAttrIos::Mail,
        'leading-icon-android' => TextInputAttrAndroid::Mail,
    ]);

    $props = $input->getResolvedProps(new CallbackRegistry);

    expect($props['leading_icon'])->toBe($expectedIcon)
        ->and($props['leading_icon_variant'] ?? null)->toBe($expectedVariant);
})->with([
    'outlined ios' => [OutlinedTextInput::class, 'ios', 'envelope.fill', null],
    'outlined android' => [OutlinedTextInput::class, 'android', 'mail', 'filled'],
    'filled ios' => [FilledTextInput::class, 'ios', 'envelope.fill', null],
    'filled android' => [FilledTextInput::class, 'android', 'mail', 'filled'],
]);

it('resolves camelCase trailing platform icon attributes', function (string $inputClass, string $platform, string $expectedIcon, ?string $expectedVariant) {
    Platform::set($platform);

    $input = new $inputClass;
    $input->applyAttributes([
        'trailingIcon' => 'email',
        'trailingIconIos' => TextInputAttrIos::Mail,
        'trailingIconAndroid' => TextInputAttrAndroid::Mail,
    ]);

    $props = $input->getResolvedProps(new CallbackRegistry);

    expect($props['trailing_icon'])->toBe($expectedIcon)
        ->and($props['trailing_icon_variant'] ?? null)->toBe($expectedVariant);
})->with([
    'outlined ios' => [OutlinedTextInput::class, 'ios', 'envelope.fill', null],
    'outlined android' => [OutlinedTextInput::class, 'android', 'mail', 'filled'],
    'filled ios' => [FilledTextInput::class, 'ios', 'envelope.fill', null],
    'filled android' => [FilledTextInput::class, 'android', 'mail', 'filled'],
]);

it('keeps the shared icon as the fallback when the platform is unknown', function (string $inputClass) {
    Platform::set(null);

    $input = new $inputClass;
    $input->applyAttributes([
        'leading-icon' => 'email',
        'leading-icon-ios' => TextInputAttrIos::Mail,
        'leading-icon-android' => TextInputAttrAndroid::Mail,
    ]);

    $props = $input->getResolvedProps(new CallbackRegistry);

    expect($props['leading_icon'])->toBe('email')
        ->and($props['leading_icon_variant'] ?? null)->toBeNull();
})->with([OutlinedTextInput::class, FilledTextInput::class]);
```

- [ ] **Step 3: Run the focused test and verify the expected red state**

Run:

```bash
php84 vendor/bin/pest tests/BaseTextInputPlatformIconTest.php --colors=never
```

Expected: the iOS and Android cases fail because the serialized icon remains the shared `email` value; the unknown-platform fallback cases pass. A syntax/bootstrap error is not the required red state and must be corrected before continuing.

- [ ] **Step 4: Implement the minimal attribute mapping**

Replace only the two icon blocks in `BaseTextInput::applyAttributes()`:

```php
$leadingIcon = $attrs['leading-icon'] ?? $attrs['leadingIcon'] ?? null;
$leadingIconIos = $attrs['leading-icon-ios'] ?? $attrs['leadingIconIos'] ?? null;
$leadingIconAndroid = $attrs['leading-icon-android'] ?? $attrs['leadingIconAndroid'] ?? null;

if ($leadingIcon !== null || $leadingIconIos !== null || $leadingIconAndroid !== null) {
    $this->leadingIcon($leadingIcon, $leadingIconIos, $leadingIconAndroid);
}

$trailingIcon = $attrs['trailing-icon'] ?? $attrs['trailingIcon'] ?? null;
$trailingIconIos = $attrs['trailing-icon-ios'] ?? $attrs['trailingIconIos'] ?? null;
$trailingIconAndroid = $attrs['trailing-icon-android'] ?? $attrs['trailingIconAndroid'] ?? null;

if ($trailingIcon !== null || $trailingIconIos !== null || $trailingIconAndroid !== null) {
    $this->trailingIcon($trailingIcon, $trailingIconIos, $trailingIconAndroid);
}
```

- [ ] **Step 5: Verify the focused and complete package suites**

Run:

```bash
php84 vendor/bin/pest tests/BaseTextInputPlatformIconTest.php --colors=never
php84 vendor/bin/pest --colors=never
env -u FORCE_COLOR php84 vendor/bin/pint --test
git diff --check
```

Expected: all commands exit zero; the focused test reports 10 passing dataset executions.

- [ ] **Step 6: Commit the package fix**

```bash
git add src/Elements/BaseTextInput.php tests/BaseTextInputPlatformIconTest.php
git commit -m "fix: support platform icons on Blade text inputs"
```

---

### Task 2: Reusable NativePHP test app and demo screen

**Files:**
- Create repository: `/Users/wojt/Code/wojt-janowski/nativephp-test-app`
- Modify: `/Users/wojt/Code/wojt-janowski/nativephp-test-app/composer.json`
- Create: `/Users/wojt/Code/wojt-janowski/nativephp-test-app/app/NativeComponents/DemoIndex.php`
- Create: `/Users/wojt/Code/wojt-janowski/nativephp-test-app/app/NativeComponents/TextInputPlatformIconsDemo.php`
- Create: `/Users/wojt/Code/wojt-janowski/nativephp-test-app/resources/views/native/demos/text-input-platform-icons-blade.blade.php`
- Modify generated mobile routes file under `/Users/wojt/Code/wojt-janowski/nativephp-test-app/routes/`
- Create: `/Users/wojt/Code/wojt-janowski/nativephp-test-app/tests/Feature/TextInputPlatformIconsDemoTest.php`
- Replace: `/Users/wojt/Code/wojt-janowski/nativephp-test-app/README.md`

**Interfaces:**
- Consumes: the Task 1 branch through Composer repository `{"type":"path","url":"../mobile-ui","options":{"symlink":true,"versions":{"nativephp/mobile-ui":"0.3.1-dev"}}}` and package constraint `0.3.1-dev`.
- Produces: native routes `/` and `/demos/text-input-platform-icons`, with a Blade example and fluent-builder control using iOS `envelope.fill` and Android `mail`.

- [ ] **Step 1: Scaffold from the official NativePHP starter**

From `/Users/wojt/Code/wojt-janowski`, run:

```bash
laravel new nativephp-test-app --using=nativephp/mobile-starter --no-interaction
```

Expected: a fresh Git repository exists at the exact target path. If the installer does not initialize Git, run `git init -b main` inside the app before editing.

- [ ] **Step 2: Configure and install the local package**

Edit the app's `composer.json` with `apply_patch` so `repositories` includes:

```json
{
    "type": "path",
    "url": "../mobile-ui",
    "options": {
        "symlink": true,
        "versions": {
            "nativephp/mobile-ui": "0.3.1-dev"
        }
    }
}
```

Require `nativephp/mobile-ui` as `0.3.1-dev`, then run:

```bash
php84 "$(command -v composer)" update nativephp/mobile-ui nativephp/mobile --with-all-dependencies --no-interaction
php84 artisan native:plugin:register nativephp/mobile-ui --no-interaction
php84 artisan native:install both --no-interaction
php84 "$(command -v composer)" show nativephp/mobile-ui --path
```

Expected: Composer reports `/Users/wojt/Code/wojt-janowski/mobile-ui`, the plugin is registered, and both native shells exist.

- [ ] **Step 3: Write the failing app feature test**

Create `tests/Feature/TextInputPlatformIconsDemoTest.php`:

```php
<?php

use Native\Mobile\Testing\Native;

it('registers the reusable demo index and text input platform icon screen', function () {
    Native::visit('/')
        ->assertSee('NativePHP issue demos')
        ->assertSee('Text input platform icons');

    Native::visit('/demos/text-input-platform-icons')
        ->assertSee('Text input platform icons')
        ->assertSee('Blade attributes')
        ->assertSee('Fluent API control');
});
```

Run:

```bash
php84 artisan test tests/Feature/TextInputPlatformIconsDemoTest.php --colors=never
```

Expected: FAIL because the routes/components do not exist yet.

- [ ] **Step 4: Implement the demo index**

Create `app/NativeComponents/DemoIndex.php`:

```php
<?php

namespace App\NativeComponents;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

class DemoIndex extends NativeComponent
{
    public function openTextInputPlatformIcons(): void
    {
        $this->navigate('/demos/text-input-platform-icons');
    }

    public function render(): Element|View
    {
        return Column::make(
            Text::make('NativePHP issue demos'),
            Text::make('Reusable reproductions for local upstream fixes.'),
            Button::make('Text input platform icons')->onPress('openTextInputPlatformIcons'),
        )->gap(16)->padding(24);
    }
}
```

- [ ] **Step 5: Implement the comparison demo**

Create `app/NativeComponents/TextInputPlatformIconsDemo.php`:

```php
<?php

namespace App\NativeComponents;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\UI\Elements\OutlinedTextInput;

class TextInputPlatformIconsDemo extends NativeComponent
{
    public function render(): Element|View
    {
        return Column::make(
            Text::make('Text input platform icons'),
            Text::make('Blade attributes'),
            $this->partial('demos.text-input-platform-icons-blade'),
            Text::make('Fluent API control'),
            OutlinedTextInput::make()
                ->label('Fluent API control')
                ->placeholder('This icon already worked')
                ->leadingIcon(ios: 'envelope.fill', android: 'mail'),
            Text::make('Expected: both inputs show an envelope/mail icon.'),
        )->gap(16)->padding(24);
    }
}
```

Create `resources/views/native/demos/text-input-platform-icons-blade.blade.php`:

```blade
<native:outlined-text-input
    label="Blade attributes"
    placeholder="This icon is missing in 0.3.0"
    :leading-icon-ios="'envelope.fill'"
    :leading-icon-android="'mail'"
/>
```

- [ ] **Step 6: Register the native routes**

In the generated NativePHP mobile routes file, import both components and register:

```php
Route::native('/', DemoIndex::class)->name('demos.index');
Route::native('/demos/text-input-platform-icons', TextInputPlatformIconsDemo::class)
    ->name('demos.text-input-platform-icons');
```

Remove or replace only a generated route that conflicts with `/`; preserve the starter's unrelated setup.

- [ ] **Step 7: Verify the app test and package linkage**

Run:

```bash
php84 artisan test tests/Feature/TextInputPlatformIconsDemoTest.php --colors=never
php84 artisan test --colors=never
php84 "$(command -v composer)" show nativephp/mobile-ui --path
git diff --check
```

Expected: all tests exit zero and Composer reports the sibling `mobile-ui` path.

- [ ] **Step 8: Write reusable setup and reproduction instructions**

Replace the starter README with sections covering prerequisites, the sibling-directory contract, Composer linkage, adding future demo screens, and these exact before/after commands:

```bash
git -C ../mobile-ui switch --detach 0.3.0
php84 artisan native:install both --force --no-interaction

git -C ../mobile-ui switch fix/text-input-platform-icons-from-blade
php84 artisan native:install both --force --no-interaction
```

State explicitly that `mobile-ui` must be returned to the feature branch after reproducing the release and that release evidence is: Blade input missing its icon while the fluent control shows it.

- [ ] **Step 9: Commit the reusable app**

Inside `/Users/wojt/Code/wojt-janowski/nativephp-test-app`:

```bash
git add .
git commit -m "feat: add NativePHP upstream issue test app"
```

---

### Task 3: iOS and Android before/after evidence

**Files:**
- Create: `/Users/wojt/Code/wojt-janowski/nativephp-test-app/docs/evidence/text-input-platform-icons/released-ios.png`
- Create: `/Users/wojt/Code/wojt-janowski/nativephp-test-app/docs/evidence/text-input-platform-icons/fixed-ios.png`
- Create: `/Users/wojt/Code/wojt-janowski/nativephp-test-app/docs/evidence/text-input-platform-icons/released-android.png`
- Create: `/Users/wojt/Code/wojt-janowski/nativephp-test-app/docs/evidence/text-input-platform-icons/fixed-android.png`
- Modify: `/Users/wojt/Code/wojt-janowski/nativephp-test-app/README.md`

**Interfaces:**
- Consumes: Task 2 routes and the path-linked package; iOS Simulator via `xcrun simctl`; Android emulator via `adb`.
- Produces: four named screenshots and a verified evidence table recording released/fixed behavior on both platforms.

- [ ] **Step 1: Record the active package branch and available devices**

Run:

```bash
git -C ../mobile-ui branch --show-current
xcrun simctl list devices available
adb devices
```

Record the feature branch name before switching. Boot one available iOS simulator and one Android emulator if none is running.

- [ ] **Step 2: Build and capture the released iOS state**

Run from the app:

```bash
git -C ../mobile-ui switch --detach 0.3.0
NATIVEPHP_START_URL=/demos/text-input-platform-icons php84 artisan native:install ios --force --no-interaction
NATIVEPHP_START_URL=/demos/text-input-platform-icons php84 artisan native:run ios --no-interaction
mkdir -p docs/evidence/text-input-platform-icons
xcrun simctl io booted screenshot docs/evidence/text-input-platform-icons/released-ios.png
```

Inspect the screenshot. Required observation: the Blade input has no leading icon and the fluent control has the envelope icon.

- [ ] **Step 3: Build and capture the released Android state**

Run:

```bash
NATIVEPHP_START_URL=/demos/text-input-platform-icons php84 artisan native:install android --force --no-interaction
NATIVEPHP_START_URL=/demos/text-input-platform-icons php84 artisan native:run android --no-interaction
adb exec-out screencap -p > docs/evidence/text-input-platform-icons/released-android.png
```

Inspect the screenshot. Required observation: the Blade input has no leading icon and the fluent control has the mail icon.

- [ ] **Step 4: Restore the fix branch even if a released build fails**

Run:

```bash
git -C ../mobile-ui switch fix/text-input-platform-icons-from-blade
```

Verify with `git -C ../mobile-ui status --short --branch`. Do not leave the package detached.

- [ ] **Step 5: Build and capture the fixed iOS state**

Run:

```bash
NATIVEPHP_START_URL=/demos/text-input-platform-icons php84 artisan native:install ios --force --no-interaction
NATIVEPHP_START_URL=/demos/text-input-platform-icons php84 artisan native:run ios --no-interaction
xcrun simctl io booted screenshot docs/evidence/text-input-platform-icons/fixed-ios.png
```

Inspect the screenshot. Required observation: both inputs show the envelope icon.

- [ ] **Step 6: Build and capture the fixed Android state**

Run:

```bash
NATIVEPHP_START_URL=/demos/text-input-platform-icons php84 artisan native:install android --force --no-interaction
NATIVEPHP_START_URL=/demos/text-input-platform-icons php84 artisan native:run android --no-interaction
adb exec-out screencap -p > docs/evidence/text-input-platform-icons/fixed-android.png
```

Inspect the screenshot. Required observation: both inputs show the mail icon.

- [ ] **Step 7: Document evidence and re-run final checks**

Add an evidence table to the app README linking all four screenshots and recording the four required observations. Then run:

```bash
php84 artisan test --colors=never
php84 "$(command -v composer)" show nativephp/mobile-ui --path
git -C ../mobile-ui status --short --branch
git diff --check
```

Expected: app tests pass, Composer points to the sibling package, and `mobile-ui` is on the feature branch.

- [ ] **Step 8: Commit the evidence**

Inside the app:

```bash
git add README.md docs/evidence/text-input-platform-icons
git commit -m "docs: capture text input icon regression evidence"
```

---

### Task 4: Final cross-repository verification

**Files:**
- Review only: all branch changes in `/Users/wojt/Code/wojt-janowski/mobile-ui`
- Review only: all changes in `/Users/wojt/Code/wojt-janowski/nativephp-test-app`

**Interfaces:**
- Consumes: completed package fix, reusable app, and four screenshots.
- Produces: fresh completion evidence with both repositories clean and the package checkout restored to the feature branch.

- [ ] **Step 1: Verify the package from a clean command run**

```bash
cd /Users/wojt/Code/wojt-janowski/mobile-ui
php84 vendor/bin/pest --colors=never
env -u FORCE_COLOR php84 vendor/bin/pint --test
git diff --check
git status --short --branch
```

- [ ] **Step 2: Verify the app from a clean command run**

```bash
cd /Users/wojt/Code/wojt-janowski/nativephp-test-app
php84 artisan test --colors=never
php84 "$(command -v composer)" show nativephp/mobile-ui --path
git diff --check
git status --short --branch
```

- [ ] **Step 3: Inspect all four screenshots**

Open each file at original resolution and verify:

- released iOS: Blade missing, fluent envelope present;
- fixed iOS: both envelopes present;
- released Android: Blade missing, fluent mail present; and
- fixed Android: both mail icons present.

- [ ] **Step 4: Record final repository state**

```bash
git -C /Users/wojt/Code/wojt-janowski/mobile-ui log --oneline --decorate -5
git -C /Users/wojt/Code/wojt-janowski/nativephp-test-app log --oneline --decorate -5
git -C /Users/wojt/Code/wojt-janowski/mobile-ui status --short --branch
git -C /Users/wojt/Code/wojt-janowski/nativephp-test-app status --short --branch
```

Expected: both repositories are clean, the package is on `fix/text-input-platform-icons-from-blade`, and no push or PR has occurred.
