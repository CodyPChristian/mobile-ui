<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Icon\AndroidSymbol;
use Native\Mobile\Icon\IosSymbol;
use Native\Mobile\Platform;
use Native\Mobile\UI\Elements\Button;
use Native\Mobile\UI\Elements\Chip;
use Native\Mobile\UI\Elements\Tab;

/**
 * `:ios-icon` / `:android-icon` blade attributes on the elements that were
 * previously shared-name-only — Button (multi-slot, so it can't use
 * HasPlatformIcon), plus Chip and Tab. Fixture enums are inline and
 * distinctly named because Pest loads every test file into one process.
 */
enum MultiSlotIos: string implements IosSymbol
{
    case Wave = 'wave.3.right';
}

enum MultiSlotAndroid: string implements AndroidSymbol
{
    public function variant(): string
    {
        return 'filled';
    }

    case Nfc = 'nfc';
}

beforeEach(function () {
    NativeElementCollector::reset();
    ElementRegistry::reset();
    ElementRegistry::register('button', Button::class);
    ElementRegistry::register('chip', Chip::class);
    ElementRegistry::register('tab', Tab::class);
});

afterEach(function () {
    NativeElementCollector::reset();
    ElementRegistry::reset();
    Platform::set(null);
});

function iconPropsFor(string $type, array $attrs): array
{
    NativeElementCollector::leaf($type, $attrs);

    return NativeElementCollector::collect()->toArray(new CallbackRegistry)['props'];
}

it('resolves a button android-icon override on android', function () {
    Platform::set('android');

    $props = iconPropsFor('button', [
        'label' => 'Add',
        'icon' => 'wave.3.right',
        'android-icon' => 'nfc',
    ]);

    expect($props['leading_icon'])->toBe('nfc');
});

it('keeps the shared button icon on ios when only android is overridden', function () {
    Platform::set('ios');

    $props = iconPropsFor('button', [
        'label' => 'Add',
        'icon' => 'wave.3.right',
        'android-icon' => 'nfc',
    ]);

    expect($props['leading_icon'])->toBe('wave.3.right');
});

it('accepts icon-less buttons that declare only per-platform overrides', function () {
    Platform::set('android');

    $props = iconPropsFor('button', [
        'label' => 'Add',
        'ios-icon' => 'wave.3.right',
        'android-icon' => 'nfc',
    ]);

    expect($props['leading_icon'])->toBe('nfc');
});

it('resolves per-platform overrides on the trailing button slot', function () {
    Platform::set('android');

    $props = iconPropsFor('button', [
        'label' => 'Next',
        'icon-trailing' => 'chevron.right',
        'android-icon-trailing' => 'chevron_right',
    ]);

    expect($props['trailing_icon'])->toBe('chevron_right');
});

it('carries the material variant through from an android enum case', function () {
    Platform::set('android');

    $props = iconPropsFor('button', [
        'label' => 'Add',
        'ios' => MultiSlotIos::Wave,
        'android' => MultiSlotAndroid::Nfc,
    ]);

    expect($props['leading_icon'])->toBe('nfc')
        ->and($props['leading_icon_variant'])->toBe('filled');
});

it('leaves a button with no icon of any kind alone', function () {
    Platform::set('ios');

    expect(iconPropsFor('button', ['label' => 'Plain']))
        ->not->toHaveKey('leading_icon');
});

it('resolves per-platform icon attributes on a chip', function () {
    Platform::set('android');

    expect(iconPropsFor('chip', [
        'label' => 'Nearby',
        'icon' => 'wave.3.right',
        'android-icon' => 'nfc',
    ])['icon'])->toBe('nfc');
});

it('resolves per-platform icon attributes on a tab', function () {
    Platform::set('android');

    expect(iconPropsFor('tab', [
        'label' => 'Devices',
        'icon' => 'wave.3.right',
        'android-icon' => 'nfc',
    ])['icon'])->toBe('nfc');
});

it('still honours the shared icon name when no override is given', function () {
    Platform::set('android');

    expect(iconPropsFor('chip', ['label' => 'Nearby', 'icon' => 'nfc'])['icon'])
        ->toBe('nfc');
});
