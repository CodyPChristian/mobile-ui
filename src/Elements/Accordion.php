<?php

namespace Native\Mobile\UI\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Accordion extends Element
{
    protected string $type = 'accordion';

    protected array $props = [];

    public static function make(Element ...$children): static
    {
        $el = new static;
        $el->children = $children;

        return $el;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['expanded'])) {
            $this->expanded((bool) $attrs['expanded']);
        }

        $this->applyA11yAttributes($attrs);
    }

    public function expanded(bool $expanded): static
    {
        $this->props['expanded'] = $expanded;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->props;
    }
}
