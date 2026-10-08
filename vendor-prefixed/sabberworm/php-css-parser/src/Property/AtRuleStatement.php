<?php

declare (strict_types=1);
namespace Bginfo\PmzhDvzh\Vendor\Sabberworm\CSS\Property;

use Bginfo\PmzhDvzh\Vendor\Sabberworm\CSS\Comment\CommentContainer;
use Bginfo\PmzhDvzh\Vendor\Sabberworm\CSS\OutputFormat;
use Bginfo\PmzhDvzh\Vendor\Sabberworm\CSS\Position\Position;
use Bginfo\PmzhDvzh\Vendor\Sabberworm\CSS\Position\Positionable;
use Bginfo\PmzhDvzh\Vendor\Sabberworm\CSS\ShortClassNameProvider;
/**
 * A generic semicolon-terminated at-rule, such as `@layer reset;` or `@layer base, components;`.
 *
 * Block at-rules (`@media`, `@layer theme { … }`, `@font-face`, …) are represented by
 * `AtRuleBlockList` or `AtRuleSet` instead.
 */
class AtRuleStatement implements AtRule, Positionable
{
    use CommentContainer;
    use Position;
    use ShortClassNameProvider;
    /**
     * @var non-empty-string
     */
    private $type;
    /**
     * @var string
     */
    private $arguments;
    /**
     * @param non-empty-string $type
     * @param int<1, max>|null $lineNumber
     */
    public function __construct(string $type, string $arguments = '', ?int $lineNumber = null)
    {
        $this->type = $type;
        $this->arguments = $arguments;
        $this->setPosition($lineNumber);
    }
    /**
     * @return non-empty-string
     */
    public function atRuleName(): string
    {
        return $this->type;
    }
    public function atRuleArgs(): string
    {
        return $this->arguments;
    }
    /**
     * @return non-empty-string
     */
    public function render(OutputFormat $outputFormat): string
    {
        $formatter = $outputFormat->getFormatter();
        $result = $formatter->comments($this);
        $arguments = $this->arguments;
        if ($arguments !== '') {
            $arguments = ' ' . $arguments;
        }
        $result .= "@{$this->type}{$arguments};";
        return $result;
    }
    /**
     * @return array<string, bool|int|float|string|array<mixed>|null>
     *
     * @internal
     */
    public function getArrayRepresentation(): array
    {
        return ['class' => $this->getShortClassName(), 'atRuleName' => $this->type, 'arguments' => $this->arguments];
    }
}
