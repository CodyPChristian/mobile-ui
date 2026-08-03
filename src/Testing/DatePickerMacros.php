<?php

namespace Native\Mobile\UI\Testing;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use Native\Mobile\Testing\TestableComponent;
use PHPUnit\Framework\Assert;

/**
 * Date-picker test vocabulary, registered as TestableComponent macros so app
 * tests read in picker terms instead of the raw select-change plumbing:
 *
 *     Native::visit('/booking')
 *         ->pickDate('startsOn', '2026-12-24')
 *         ->pickTime('opensAt', new DateTimeImmutable('18:05'))
 *         ->assertPickerValue('startsOn', '2026-12-24');
 *
 * Without these, a picker interaction is `->select($target, '2026-12-24')` —
 * which works (the element rides the select-change event) but reads as a
 * dropdown, and silently accepts a value in the wrong shape for the mode.
 * These macros normalize to the wire contract first, so a test written with
 * a Carbon instance or a full ISO timestamp still sends what the renderers
 * would have sent.
 *
 * Registered by NativeUIServiceProvider under a test runner, on a core whose
 * TestableComponent is macroable.
 */
class DatePickerMacros
{
    /** Wire format per mode — mirrors DatePicker's own contract. */
    private const WIRE_FORMATS = [
        'date' => 'Y-m-d',
        'time' => 'H:i',
        'datetime' => 'Y-m-d\TH:i',
    ];

    public static function register(): void
    {
        /** Pick a date (`Y-m-d`) on a `mode="date"` picker. */
        TestableComponent::macro('pickDate', function (string $target, string|DateTimeInterface $value) {
            return $this->select($target, DatePickerMacros::wire($value, 'date'));
        });

        /** Pick a time (`H:i`, 24-hour) on a `mode="time"` picker. */
        TestableComponent::macro('pickTime', function (string $target, string|DateTimeInterface $value) {
            return $this->select($target, DatePickerMacros::wire($value, 'time'));
        });

        /** Pick a date+time (`Y-m-d\TH:i`) on a `mode="datetime"` picker. */
        TestableComponent::macro('pickDateTime', function (string $target, string|DateTimeInterface $value) {
            return $this->select($target, DatePickerMacros::wire($value, 'datetime'));
        });

        /**
         * Clear a picker's selection — the empty string is the wire value
         * for "nothing chosen", distinct from never having been set.
         */
        TestableComponent::macro('clearPicker', function (string $target) {
            return $this->select($target, '');
        });

        /**
         * Assert the wire value currently serialized for a picker. Matches
         * on the picker's `label`, or on any prop when given a callable.
         */
        TestableComponent::macro('assertPickerValue', function (string $label, string|DateTimeInterface $expected) {
            $props = DatePickerMacros::pickerByLabel($this->tree(), $label);

            Assert::assertNotNull($props, "No date_picker labelled [{$label}] in the rendered tree.");

            $mode = $props['mode'] ?? 'date';
            $want = DatePickerMacros::wire($expected, $mode);

            Assert::assertSame(
                $want,
                $props['value'] ?? null,
                "Picker [{$label}] (mode {$mode}) does not hold the expected value."
            );

            return $this;
        });

        /** Assert a picker exists with the given label, and optionally its mode. */
        TestableComponent::macro('assertPicker', function (string $label, ?string $mode = null) {
            $props = DatePickerMacros::pickerByLabel($this->tree(), $label);

            Assert::assertNotNull($props, "No date_picker labelled [{$label}] in the rendered tree.");

            if ($mode !== null) {
                Assert::assertSame($mode, $props['mode'] ?? 'date', "Picker [{$label}] is not in {$mode} mode.");
            }

            return $this;
        });

        /** Assert a picker's selection is empty (placeholder showing). */
        TestableComponent::macro('assertPickerEmpty', function (string $label) {
            $props = DatePickerMacros::pickerByLabel($this->tree(), $label);

            Assert::assertNotNull($props, "No date_picker labelled [{$label}] in the rendered tree.");
            Assert::assertSame('', $props['value'] ?? null, "Picker [{$label}] holds a selection.");

            return $this;
        });
    }

    /**
     * Normalize any accepted input to the wire shape for `$mode` — the same
     * coercion `DatePicker::value()` performs, so a test and the element
     * agree on what "this date" serializes to.
     *
     * @internal Exposed only because macro closures can't see private scope.
     */
    public static function wire(string|DateTimeInterface $value, string $mode): string
    {
        $format = self::WIRE_FORMATS[$mode] ?? self::WIRE_FORMATS['date'];

        if ($value instanceof DateTimeInterface) {
            return $value->format($format);
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($trimmed, new DateTimeZone('UTC')))->format($format);
        } catch (Exception) {
            throw new InvalidArgumentException(
                "Expected an ISO 8601 string or DateTimeInterface for a {$mode} picker, got [{$trimmed}]."
            );
        }
    }

    /**
     * Find a date_picker node by its `label` prop.
     *
     * @internal
     */
    public static function pickerByLabel(array $node, string $label): ?array
    {
        if (($node['type'] ?? null) === 'date_picker' && ($node['props']['label'] ?? null) === $label) {
            return $node['props'];
        }

        foreach ($node['children'] ?? [] as $child) {
            if ($found = self::pickerByLabel($child, $label)) {
                return $found;
            }
        }

        return null;
    }
}
