<?php

use Native\Mobile\Edge\CallbackRegistry;
use Nativephp\NativeUi\Builders\FloatingOverlay as FloatingOverlayBuilder;
use Nativephp\NativeUi\Elements\Chip;
use Nativephp\NativeUi\Elements\FloatingOverlay;

/**
 * The floating-overlay layout hook: a content-agnostic pill/banner that floats
 * over the content (and the tab bar) rather than insetting it. Covers the
 * builder surface (returned from `NativeLayout::floatingOverlay()`) and the
 * `floating_overlay` sentinel the chrome contributor emits from it.
 */

it('defaults the builder to a bottom overlay with no explicit offset', function () {
    $builder = FloatingOverlayBuilder::make(Chip::make('3 servers nearby'));

    expect($builder->getAlignment())->toBe('bottom');
    expect($builder->getOffset())->toBeNull();
});

it('carries alignment, offset and content through the builder', function () {
    $content = Chip::make('Now playing');
    $builder = FloatingOverlayBuilder::make($content)->top()->offset(88);

    expect($builder->getAlignment())->toBe('top');
    expect($builder->getOffset())->toBe(88);
    expect($builder->getContent())->toBe($content);
});

it('serializes the sentinel element props', function () {
    $overlay = FloatingOverlay::make();
    $overlay->applyAttributes(['alignment' => 'top', 'offset' => 88]);

    $props = $overlay->toArray(new CallbackRegistry)['props'];

    expect($props['alignment'])->toBe('top');
    expect($props['offset'])->toBe(88);
});

it('defaults sentinel alignment to bottom and omits an unset offset', function () {
    $overlay = FloatingOverlay::make();
    $overlay->applyAttributes(['offset' => null]);

    $props = $overlay->toArray(new CallbackRegistry)['props'];

    expect($props['alignment'])->toBe('bottom');
    expect($props)->not->toHaveKey('offset');
});

it('clamps an unknown alignment to bottom', function () {
    $overlay = FloatingOverlay::make();
    $overlay->applyAttributes(['alignment' => 'sideways']);

    $props = $overlay->toArray(new CallbackRegistry)['props'];

    expect($props['alignment'])->toBe('bottom');
});
