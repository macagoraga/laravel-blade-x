<?php

namespace Spatie\BladeX;

class Compiler
{
    /** @var \Spatie\BladeX\BladeX */
    protected $bladeX;

    public function __construct(BladeX $bladeX)
    {
        return $this->bladeX = $bladeX;
    }

    public function compile(string $viewContents): string
    {
        return array_reduce(
            $this->bladeX->registeredComponents(),
            [$this, 'parseComponentHtml'],
            $viewContents
        );
    }

    protected function parseComponentHtml(string $viewContents, Component $bladeXComponent)
    {
        $viewContents = $this->parseSlots($viewContents);

        $viewContents = $this->parseSelfClosingTags($viewContents, $bladeXComponent);

        $viewContents = $this->parseOpeningTags($viewContents, $bladeXComponent);

        $viewContents = $this->parseClosingTags($viewContents, $bladeXComponent);

        return $viewContents;
    }

    protected function parseSelfClosingTags(string $viewContents, Component $bladeXComponent): string
    {
        $prefix = $this->bladeX->getPrefix();

        $pattern = "/<\s*{$prefix}{$bladeXComponent->tag}\s*(.*)\s*\/>/m";

        return preg_replace_callback($pattern, function (array $regexResult) use ($bladeXComponent) {
            [$componentHtml, $attributesString] = $regexResult;

            $attributes = $this->getAttributesFromAttributeString($attributesString);

            return $this->componentString($bladeXComponent, $attributes);
        }, $viewContents);
    }

    protected function parseOpeningTags(string $viewContents, Component $component): string
    {
        $prefix = $this->bladeX->getPrefix();

        $pattern = "/<\s*{$prefix}{$component->tag}((?:\s+[\w\-:]*=(?:\\\"(?:.*?)\\\"|\'(?:.*)\'|[^\'\\\"=<>]*))*\s*)(?<![\/=\-])>/m";

        return preg_replace_callback($pattern, function (array $regexResult) use ($component) {
            [$componentHtml, $attributesString] = $regexResult;

            $attributes = $this->getAttributesFromAttributeString($attributesString);

            return $this->componentStartString($component, $attributes);
        }, $viewContents);
    }

    protected function parseClosingTags(string $viewContents, Component $component): string
    {
        $prefix = $this->bladeX->getPrefix();

        $pattern = "/<\/\s*{$prefix}{$component->tag}[^>]*>/m";

        return preg_replace($pattern, $this->componentEndString($component), $viewContents);
    }

    protected function componentString(Component $component, array $attributes = []): string
    {
        return $this->componentStartString($component, $attributes).$this->componentEndString($component);
    }

    protected function componentStartString(Component $component, array $attributes = []): string
    {
        $attributesString = $this->attributesToString($attributes);

        $componentAttributeString = "[{$attributesString}]";

        if ($component->view === 'bladex::context') {
            return "@php(app(Spatie\BladeX\ContextStack::class)->push({$componentAttributeString}))";
        }

        if ($component->viewModel) {
            $componentAttributeString = "
                array_merge(
                    app(Spatie\BladeX\ContextStack::class)->read(),
                    {$componentAttributeString},
                    app(
                        {$component->viewModel}::class,
                        array_merge(
                            app(Spatie\BladeX\ContextStack::class)->read(),
                            {$componentAttributeString}
                        )
                    )->toArray()
                )";
        }

        return "@component(
           '{$component->view}',
           array_merge(app(Spatie\BladeX\ContextStack::class)->read(), {$componentAttributeString}))";
    }

    protected function componentEndString(Component $component): string
    {
        if ($component->view === 'bladex::context') {
            return "@php(app(Spatie\BladeX\ContextStack::class)->pop())";
        }

        return ' @endcomponent';
    }

    protected function getAttributesFromAttributeString(string $attributeString): array
    {
        $attributeString = $this->parseBindAttributes($attributeString);

        $pattern = '/(?<attribute>[\w:-]+)(=(?<value>(\"[^\"]+\"|\\\'[^\\\']+\\\'|[^\s>]+)))?/';

        if (! preg_match_all($pattern, $attributeString, $matches, PREG_SET_ORDER)) {
            return [];
        }

        return collect($matches)->mapWithKeys(function ($match) {
            $attribute = camel_case($match['attribute']);
            $value = $match['value'] ?? null;

            if (is_null($value)) {
                $value = 'true';
                $attribute = str_start($attribute, 'bind:');
            }

            if (starts_with($value, ['"', '\''])) {
                $value = substr($value, 1, -1);
            }

            if (! starts_with($attribute, 'bind:')) {
                $value = str_replace("'", "\\'", $value);
                $value = "'{$value}'";
            }

            if (starts_with($attribute, 'bind:')) {
                $attribute = str_after($attribute, 'bind:');
            }

            return [$attribute => $value];
        })->toArray();
    }

    protected function parseSlots(string $viewContents): string
    {
        $pattern = '/<\s*slot[^>]*name=[\'"](.*)[\'"][^>]*>((.|\n)*?)<\s*\/\s*slot>/m';

        return preg_replace_callback($pattern, function ($regexResult) {
            [$slot, $name, $contents] = $regexResult;

            return "@slot('{$name}'){$contents}@endslot";
        }, $viewContents);
    }

    protected function isOpeningHtmlTag(string $tagName, string $html): bool
    {
        return ! ends_with($html, ["</{$tagName}>", '/>']);
    }

    protected function parseBindAttributes(string $html): string
    {
        return preg_replace("/\s+:([\w-]+)=/m", ' bind:$1=', $html);
    }

    protected function attributesToString(array $attributes): string
    {
        return collect($attributes)
            ->map(function (string $value, string $attribute) {
                return "'{$attribute}' => {$value}";
            })
            ->implode(',');
    }
}
