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
