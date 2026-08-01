# Text Input Platform Icons From Blade

## Goal

Make the Blade attribute path for `outlined-text-input` and `filled-text-input`
support the same shared, iOS-specific, and Android-specific icons as their
existing fluent `leadingIcon()` and `trailingIcon()` APIs.

Provide a reusable sibling NativePHP application at
`~/Code/wojt-janowski/nativephp-test-app` that demonstrates the released bug
and the local fix on both iOS and Android.

## Package change

The change belongs in `BaseTextInput::applyAttributes()`. It will collect the
shared icon plus optional iOS and Android overrides, accepting the repository's
existing camelCase and kebab-case attribute conventions, and pass all three to
the existing `leadingIcon()` or `trailingIcon()` method. Icon resolution and
native wire props remain unchanged.

The supported attribute keys will be:

- `leadingIcon` / `leading-icon`
- `leadingIconIos` / `leading-icon-ios`
- `leadingIconAndroid` / `leading-icon-android`
- `trailingIcon` / `trailing-icon`
- `trailingIconIos` / `trailing-icon-ios`
- `trailingIconAndroid` / `trailing-icon-android`

No Swift, Kotlin, wire-format, renderer, or unrelated element changes are in
scope.

## Test strategy

A focused Pest test will exercise real element serialization through
`CallbackRegistry`. It will use fixture enums implementing `IosSymbol` and
`AndroidSymbol`, set the active `Platform`, and assert the resulting icon name
and Android material variant.

Coverage will include:

- outlined and filled text inputs;
- leading and trailing icon attributes;
- iOS and Android resolution;
- camelCase and kebab-case keys; and
- preservation of an optional shared fallback.

The test must fail against the current `0.3.0` implementation because the
platform override props are absent. Only after observing that expected failure
will the minimal production change be made.

## Reusable demonstration app

Create a fresh Laravel application at
`~/Code/wojt-janowski/nativephp-test-app`, install NativePHP Mobile v4, and
register `nativephp/mobile-ui`. Configure Composer with a symlinked path
repository pointing to `../mobile-ui` so package edits are consumed directly.

The app will have a small native demo index and a text-input platform-icon demo
screen. The demo screen will render:

1. a Blade-configured input using distinct iOS and Android icon overrides; and
2. a fluent-builder input using the same overrides as a working control.

The screen will label both examples and explain the expected result. It will
avoid application-specific dependencies so later NativePHP issues can add
their own isolated demo screens.

## Before/after demonstration

The app will document a repeatable two-state procedure:

1. With the sibling package at tag `0.3.0`, reinstall/rebuild the native shells.
   The fluent control displays the platform icon while the Blade example does
   not.
2. Switch the sibling package back to
   `fix/text-input-platform-icons-from-blade`, reinstall/rebuild, and run again.
   Both examples display the iOS symbol on iOS and the Material icon on Android.

Both an iOS simulator build and an Android emulator build are required for the
final verification. If local SDK/device availability blocks either platform,
the exact command and failure will be reported rather than treated as a pass.

## Verification

Package verification:

- focused regression test;
- complete Pest suite;
- Pint formatting check; and
- diff/working-tree review.

Application verification:

- Composer confirms `nativephp/mobile-ui` resolves from the sibling path;
- NativePHP recognizes the registered plugin and native route;
- the released state reproduces the missing Blade icon on iOS and Android;
- the fixed state displays the appropriate icon on both platforms; and
- the app retains concise setup and reproduction instructions for future issue
  work.

## Out of scope

- Select key/value semantics.
- Badge icon support.
- New icon names or generated icon catalogs.
- Changes to the core NativePHP precompiler.
- Publishing packages or opening a pull request.
