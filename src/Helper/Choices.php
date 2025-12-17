<?php

namespace Spartan\Console\Helper;

use Closure;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Choice Console
 *
 * @package Spartan\Console
 * @author  Iulian N. <iulian@spartanphp.com>
 * @license LICENSE MIT
 */
class Choices
{
    /**
     * @var mixed[]
     */
    protected array $config = [
        'error_msg_delay' => 2,
        'sort'            => false,
        'templates'       => [
            'group'  => '%s',
            'on'     => ' [x] %s',
            'off'    => ' [ ] %s',
            'error'  => '%s',
            'cursor' => '%s',
            'clear'  => '%s',
        ],
    ];

    /**
     * @var mixed[]
     */
    protected array $choices = [];

    /**
     * @var mixed[]
     */
    protected array $groups = [];

    protected int $line = 0;

    protected int $lines = 0;

    /**
     * @var mixed[]
     */
    protected array $selections = [];

    protected OutputInterface $output;

    /**
     * MultiChoice constructor.
     *
     * @param mixed[]              $choices Choices as array
     * @param mixed[]              $config  Extra config
     * @param OutputInterface|null $output
     */
    public function __construct(
        array $choices,
        array $config = [],
        OutputInterface $output = null
    ) {
        $this->config = $config + $this->config;
        $this->output = $output ? $output : new ConsoleOutput();

        $this->setChoices($choices);
    }

    /**
     * Reset choices
     *
     * @return $this
     */
    public function reset(): self
    {
        $this->choices    = [];
        $this->groups     = [];
        $this->selections = [];

        return $this;
    }

    /**
     * Set choices
     *
     * @param mixed[] $choices Choices
     *
     * @return void
     */
    public function setChoices(array $choices): void
    {
        $this->reset();

        $flatten = $this->flatten($choices);
        if ($this->config['sort']) {
            ksort($flatten);
        }

        /*
         * $path = Db.Lang.php
         * $name = PHP
         */
        foreach ($flatten as $path => $name) {
            $ns                 = substr($path, 0, (int)strrpos($path, '.'));
            $this->choices[$ns] = $ns;
            if (isset($flatten["{$ns}._"])) {
                $this->groups[$ns] = $flatten["{$ns}._"] + ['max' => PHP_INT_MAX];
                unset($flatten["{$ns}._"]);
            }

            $key = strrpos($path, '.')
                ? substr($path, strrpos($path, '.') + 1) // php
                : $path;

            if ($key == '_') {
                continue;
            }

            $isSelected      = in_array($key, (array)($this->groups[$ns]['selected'] ?? []));
            $this->choices[] = [
                'key'      => $key,
                'ns'       => $ns,
                'name'     => $name,
                'selected' => $isSelected,
                'readonly' => in_array($key, $this->groups[$ns]['readonly'] ?? []),
                'depends'  => (array)($this->groups[$ns]['depends'][$key] ?? []),
            ];

            if ($isSelected) {
                $this->selections[$key] = $ns;
            }

            if (!isset($this->groups[$ns])) {
                $this->groups[$ns] = ['max' => PHP_INT_MAX];
            }
        }

        $this->choices = array_values($this->choices);
        $this->lines   = count($this->choices);
        $this->line    = $this->lines - 1;
    }

    /**
     * Check for current line
     *
     * @return bool
     */
    protected function isSelected(): bool
    {
        $key = $this->choices[$this->line]['key'];

        return array_key_exists($key, $this->selections);
    }

    /**
     * Check if current selection is a group
     *
     * @return bool
     */
    protected function isGroup(): bool
    {
        return is_string($this->choices[$this->line]);
    }

    /**
     * Check if current selection is selectable
     *
     * @return bool
     */
    protected function isChoice(): bool
    {
        return is_array($this->choices[$this->line]);
    }

    /**
     * @return mixed[]
     * @throws \RuntimeException
     */
    public function ask(): array
    {
        Terminal::prepare();
        Terminal::disableEcho();
        $this->render();
        $this->output->write(Terminal::cursorShow());
        $this->output->write(Terminal::cursorToLineStart());

        while ($char = fread(STDIN, 1)) {
            if ($char == "\n") {
                $this->output->write(Terminal::cursorDown($this->lines - $this->line));
                $this->output->write(PHP_EOL);
                Terminal::enableEcho();
                Terminal::reset();

                return array_keys($this->selections);
            } elseif ($char == "\e") {
                $navigate = fread(STDIN, 2) . '  ';
                if ($navigate[1] == 'A') {
                    if ($this->canGoUp()) {
                        $this->renderLine('clear');
                        $this->line--;
                        $this->output->write(Terminal::cursorUp());
                        $this->renderLine();
                    }
                } else {
                    if ($this->canGoDown()) {
                        $this->renderLine('clear');
                        $this->line++;
                        $this->output->write(Terminal::cursorDown());
                        $this->renderLine();
                    }
                }
            } elseif ($char == ' ') {
                if (!$this->isGroup()) {
                    $this->toggle();
                }
            }
        }

        return [];
    }

    /**
     * @return void
     * @throws \RuntimeException
     */
    protected function toggle(): void
    {
        $choice = $this->choices[$this->line];
        if ($choice['readonly']) {
            $this->renderError('Option cannot be changed');

            return;
        }

        if ($this->isSelected()) {
            if ($orphan = $this->checkOrphanChildrenDependencies($choice)) {
                $this->renderError('First remove child dependencies: ' . implode(', ', $orphan));
            } else {
                unset($this->selections[$choice['key']]);
                $this->renderLine('cursor');
            }
        } else {
            $selectedInNs = array_count_values($this->selections)[$choice['ns']] ?? 0;
            if ($selectedInNs >= $this->groups[$choice['ns']]['max']) {
                $this->renderError('Max selections allowed is: ' . $this->groups[$choice['ns']]['max']);
            } elseif ($missing = $this->checkMissingParentsDependencies($choice)) {
                $this->renderError('Depends on: ' . implode(', ', $missing));
            } else {
                $this->selections[$choice['key']] = $choice['ns'];
                $this->renderLine('cursor');
            }
        }
    }

    /**
     * Make sure all dependencies are already selected
     *
     * @param mixed[] $choice Checked choice
     *
     * @return mixed[]
     */
    protected function checkMissingParentsDependencies(array $choice): array
    {
        $missing = [];
        $depends = (array)$choice['depends'];
        foreach ($depends as $dependency) {
            if (!isset($this->selections[$dependency])) {
                foreach ($this->choices as $choice) {
                    if (is_array($choice) && $choice['key'] == $dependency) {
                        $missing[] = $choice['name'];
                        break;
                    }
                }
            }
        }

        return $missing;
    }

    /**
     * Make sure all choices who depend on $choice are not selected
     *
     * @param mixed[] $parent Checked choice
     *
     * @return mixed[]
     */
    protected function checkOrphanChildrenDependencies(array $parent): array
    {
        $orphan = [];
        $key    = $parent['key'];
        foreach ($this->choices as $choice) {
            if (is_array($choice) && in_array($key, $choice['depends']) && isset($this->selections[$choice['key']])) {
                $orphan[] = $choice['name'];
            }
        }

        return $orphan;
    }

    /*
     * Renders
     */

    /**
     * Render choices
     *
     * @return void
     * @throws \RuntimeException
     */
    public function render(): void
    {
        $lines = [];
        foreach ($this->choices as $key => $choice) {
            if (is_string($choice)) {
                $lines[] = $this->getGroupTemplate($choice);
            } else {
                $lines[] = $this->getChoiceTemplate($choice);
            }
        }

        $this->output->write(implode("\n", $lines));
    }

    /**
     * @param string $type Line type
     *
     * @return void
     * @throws \RuntimeException
     */
    public function renderLine($type = 'cursor'): void
    {
        $choice = $this->choices[$this->line];
        $this->output->write(Terminal::cursorToLineStart());
        if (is_string($choice)) {
            $this->output->write($this->getTemplate($type, $this->getGroupTemplate($choice)));
            $this->output->write(Terminal::cursorToLineStart());
        } else {
            $choice = ['selected' => isset($this->selections[$choice['key']])] + $choice;
            $this->output->write($this->getTemplate($type, $this->getChoiceTemplate($choice)));
            $this->output->write(Terminal::cursorToLineStart());
        }
    }

    /**
     * @param string $message Message to show
     * @param int    $wait    How many seconds to wait for the message to hide
     *
     * @return void
     * @throws \RuntimeException
     */
    public function renderError($message = '', $wait = 2): void
    {
        $this->output->write(Terminal::cursorToLineStart());
        $this->output->write(Terminal::clearLine());
        $this->output->write($this->getErrorTemplate($message));
        sleep($wait);
        $this->output->write(Terminal::cursorToLineStart());
        $this->output->write(Terminal::clearLine());
        $this->renderLine();
    }

    /**
     * @param string $name Choice name
     *
     * @return string
     */
    public function getGroupTemplate($name)
    {
        return $this->getTemplate('group', $name);
    }

    /**
     * @param mixed[] $choice Choices
     *
     * @return string
     */
    public function getChoiceTemplate(array $choice)
    {
        if ($choice['selected']) {
            return $this->getTemplate('on', $choice['name']);
        }

        return $this->getTemplate('off', $choice['name']);
    }

    /**
     * @param string $message Shown message
     *
     * @return string
     */
    public function getErrorTemplate($message): string
    {
        return $this->getTemplate('error', $message);
    }

    /**
     * @param string $name  Template name
     * @param mixed  $value Choice value
     *
     * @return string
     */
    public function getTemplate($name, $value): string
    {
        return $this->config['templates'][$name] instanceof Closure
            ? $this->config['templates'][$name]($value)
            : sprintf($this->config['templates'][$name], $value);
    }

    /*
     * Helpers
     */

    /**
     * @return bool
     */
    protected function canGoUp(): bool
    {
        return $this->line > 0;
    }

    /**
     * @return bool
     */
    protected function canGoDown(): bool
    {
        return $this->line < $this->lines - 1;
    }

    /**
     * @param mixed[] $array   Array to flatten
     * @param string  $prepend Prepend string
     *
     * @return mixed[]
     */
    public function flatten(array $array, $prepend = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            if (is_array($value) && !empty($value) && $key != '_') {
                $result = array_merge($result, static::flatten($value, $prepend . $key . '.'));
            } else {
                $result[$prepend . $key] = $value;
            }
        }

        return $result;
    }
}
