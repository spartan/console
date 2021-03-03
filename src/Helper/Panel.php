<?php

namespace Spartan\Console\Helper;

use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Panel Console
 *
 * @package Spartan\Console
 * @author  Iulian N. <iulian@spartanphp.com>
 * @license LICENSE MIT
 */
class Panel
{
    const TOP_LEFT           = 'top-left-corner';
    const TOP_RIGHT          = 'top-right-corner';
    const BOTTOM_RIGHT       = 'bottom-right-corner';
    const BOTTOM_LEFT        = 'bottom-left-corner';
    const VERTICAL_LINE      = 'vertical-line';
    const HORIZONTAL_LINE    = 'horizontal-line';
    const PADDING_TOP_BOTTOM = 'padding-top-bottom';
    const PADDING_LEFT_RIGHT = 'padding-left-right';
    const MARGIN_TOP         = 'margin-top';
    const MARGIN_BOTTOM      = 'margin-bottom';
    const CENTERED           = 'centered';
    const ALLOWED_TAGS       = 'allowed-tags';

    /**
     * @var string
     */
    protected string $text = '';

    /**
     * @var mixed[]
     */
    protected array $options = [
        self::TOP_LEFT           => '╔',
        self::TOP_RIGHT          => '╗',
        self::BOTTOM_RIGHT       => '╝',
        self::BOTTOM_LEFT        => '╚',
        self::VERTICAL_LINE      => '║',
        self::HORIZONTAL_LINE    => '═',
        self::PADDING_TOP_BOTTOM => 1,
        self::PADDING_LEFT_RIGHT => 3,
        self::CENTERED           => false,
        self::ALLOWED_TAGS       => '',   // for Symfony Console
        self::MARGIN_TOP         => 1,
        self::MARGIN_BOTTOM      => 1,
    ];

    protected OutputInterface $output;

    /**
     * Border constructor.
     *
     * @param OutputInterface $output
     * @param mixed[]         $options Options for rendering
     */
    public function __construct(OutputInterface $output = null, array $options = [])
    {
        $this->options = $options + $this->options;
        $this->output  = $output ? $output : new ConsoleOutput();
    }

    /**
     * @param mixed[] $options
     *
     * @return $this
     */
    public function withOptions(array $options): self
    {
        $this->options = $options + $this->options;

        return $this;
    }

    /**
     * @param string $text Text to render
     *
     * @throws \RuntimeException
     */
    public function render(string $text): void
    {
        $text = explode("\n", trim($text));
        $min  = PHP_INT_MAX;
        $max  = 0;
        foreach ($text as $line) {
            $len = strlen(strip_tags($line, $this->options[self::ALLOWED_TAGS]));
            if ($len > $max) {
                $max = $len;
            }
            if ($len < $min) {
                $min = $len;
            }
        }

        // excluding border
        $cols = $max + $this->options[self::PADDING_LEFT_RIGHT] * 2;

        $lines = [];

        // top margin
        for ($i = 0; $i < $this->options[self::MARGIN_TOP]; $i++) {
            $lines[] = '';
        }

        // top
        $lines[] = $this->options[self::TOP_LEFT]
            . implode('', array_fill(0, $cols, $this->options[self::HORIZONTAL_LINE]))
            . $this->options[self::TOP_RIGHT];

        // padding top
        for ($i = 0; $i < $this->options[self::PADDING_TOP_BOTTOM]; $i++) {
            $lines[] = $this->options[self::VERTICAL_LINE]
                . implode('', array_fill(0, $cols, ' '))
                . $this->options[self::VERTICAL_LINE];
        }

        // text
        for ($i = 0; $i < count($text); $i++) {
            $length  = strlen(strip_tags($text[$i], $this->options[self::ALLOWED_TAGS]));
            $left    = $this->options[self::CENTERED]
                ? $this->options[self::PADDING_LEFT_RIGHT] + (int)floor(($max - $length) / 2)
                : $this->options[self::PADDING_LEFT_RIGHT];
            $right   = $this->options[self::CENTERED]
                ? $this->options[self::PADDING_LEFT_RIGHT] + (int)ceil(($max - $length) / 2)
                : $cols - $length - $this->options[self::PADDING_LEFT_RIGHT];
            $lines[] = $this->options[self::VERTICAL_LINE]
                . implode('', array_fill(0, $left, ' '))
                . $text[$i]
                . implode('', array_fill(0, $right, ' '))
                . $this->options[self::VERTICAL_LINE];
        }

        // padding bottom
        for ($i = 0; $i < $this->options[self::PADDING_TOP_BOTTOM]; $i++) {
            $lines[] = $this->options[self::VERTICAL_LINE]
                . implode('', array_fill(0, $cols, ' ')) . $this->options[self::VERTICAL_LINE];
        }

        // bottom
        $lines[] = $this->options[self::BOTTOM_LEFT]
            . implode('', array_fill(0, $cols, $this->options[self::HORIZONTAL_LINE]))
            . $this->options[self::BOTTOM_RIGHT];

        // bottom margin
        for ($i = 0; $i < $this->options[self::MARGIN_BOTTOM]; $i++) {
            $lines[] = '';
        }

        foreach ($lines as $line) {
            $this->output->writeln($line);
        }
    }
}
