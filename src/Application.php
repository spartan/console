<?php

namespace Spartan\Console;

use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Application Console
 *
 * Extends the Symfony Application
 *
 * @package Spartan\Console
 * @author  Iulian N. <iulian@spartanphp.com>
 * @license LICENSE MIT
 */
class Application extends SymfonyApplication
{
    protected ?OutputFormatter $formatter = null;

    /**
     * @return mixed[]
     */
    public function commands()
    {
        $property = new \ReflectionProperty(SymfonyApplication::class, 'commands');

        $property->setAccessible(true);

        return $property->getValue($this);
    }

    /**
     * Add commands
     * Can be used with object, path to file, directory (for recursive load)
     *
     * @param mixed[] $commands
     *
     * @return self
     */
    public function withCommands(array $commands)
    {
        $validCommands = [];

        foreach ($commands as $path) {
            if (is_object($path)) {
                $validCommands[] = $path;
                continue;
            }

            if (!is_string($path)) {
                continue;
            }

            if (!file_exists($path)) {
                continue;
            }

            if (is_dir($path)) {
                $before   = get_declared_classes();
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
                foreach ($iterator as $item) {
                    if (!$item->isDir() && substr($item->getPathname(), -4) == '.php') {
                        require_once $item->getPathname();
                    }
                }
                $after = get_declared_classes();

                foreach (array_diff($after, $before) as $className) {
                    if ($className != SymfonyCommand::class && $className != Command::class) {
                        $validCommands[] = new $className();
                    }
                }
            } else {
                if (is_string($path) && $path != SymfonyCommand::class && $path != Command::class) {
                    $validCommands[] = new $path();
                }
            }
        }

        $this->addCommands($validCommands);

        return $this;
    }

    /**
     * Add commands from a directory
     *
     * @param string $path
     *
     * @return $this
     */
    public function withCommandsDir(string $path)
    {
        $validCommands = [];

        if (!is_dir($path)) {
            throw new \InvalidArgumentException("Invalid path: `{$path}`");
        }

        $before   = get_declared_classes();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
        foreach ($iterator as $item) {
            if (!$item->isDir() && substr($item->getPathname(), -4) == '.php') {
                require_once $item->getPathname();
            }
        }
        $after = get_declared_classes();

        foreach (array_diff($after, $before) as $className) {
            if ($className != SymfonyCommand::class && $className != Command::class) {
                $validCommands[] = new $className();
            }
        }

        $this->addCommands($validCommands);

        return $this;
    }

    /**
     * Change output formatting styles
     *
     * @param mixed[] $styles
     *
     * @return self
     */
    public function withStyles(array $styles)
    {
        foreach ($styles as $name => $args) {
            $this->formatter()->setStyle($name, new OutputFormatterStyle(...$args));
        }

        return $this;
    }

    /**
     * @return self
     */
    public function withDefaultStyles()
    {
        return $this->withStyles(
            [
                'info'         => ['cyan'],
                'cursor'       => ['black', 'white'],
                'note'         => ['black', 'cyan'],
                'success'      => ['black', 'green'],
                'warning'      => ['black', 'yellow'],
                'danger'       => ['black', 'red'],
                'note-text'    => ['cyan', 'black'],
                'success-text' => ['green', 'black'],
                'warning-text' => ['yellow', 'black'],
                'danger-text'  => ['red', 'black'],
                'question'     => ['black', 'cyan'],
            ]
        );
    }

    /**
     * Change formatter
     *
     * @param OutputFormatter $formatter
     *
     * @return self
     */
    public function withFormatter(OutputFormatter $formatter)
    {
        $this->formatter = $formatter;

        return $this;
    }

    /**
     * Get default formatter
     *
     * @return OutputFormatter
     */
    public function formatter()
    {
        if ($this->formatter === null) {
            $this->formatter = new OutputFormatter();
        }

        return $this->formatter;
    }

    /**
     * Run application
     *
     * @param InputInterface|null  $input
     * @param OutputInterface|null $output
     *
     * @return int
     * @throws \Exception
     */
    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        $input  = $input ?: new ArgvInput();
        $output = $output ?: new ConsoleOutput(OutputInterface::VERBOSITY_NORMAL, null, $this->formatter());

        return parent::run($input, $output);
    }
}
