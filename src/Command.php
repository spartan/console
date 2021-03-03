<?php

namespace Spartan\Console;

use Dotenv\Dotenv;
use Spartan\Console\Helper\Choices;
use Spartan\Console\Helper\Panel;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\Process;

/**
 * Command Console
 *
 * @method void note(string $text) Show a note text
 * @method void warning(string $text) Show a warning text
 * @method void danger(string $text) Show a danger text
 * @method void success(string $text) Show a success text
 *
 * @property mixed $definition
 *
 * @package Spartan\Console
 * @author  Iulian N. <iulian@spartanphp.com>
 * @license LICENSE MIT
 */
abstract class Command extends SymfonyCommand
{
    const RESERVED_OPTIONS = [
        'ansi',
        'no-ansi',
        'help',
        'quiet',
        'verbose',
        'version',
        'no-interaction',
        'i',
    ];

    const RESERVED_ARGUMENTS = [
        'command' // symfony
    ];

    protected InputInterface $input;

    protected OutputInterface $output;

    protected ProgressBar $progress;

    /**
     * @var mixed[]
     */
    protected array $interact = [];

    /**
     * @param string[] $paths
     */
    public function loadEnv($paths = ['./config/.env', '.env']): void
    {
        foreach ((array)$paths as $path) {
            if (file_exists($path)) {
                $dir = dirname($path);
                (Dotenv::createMutable($dir))->load();
                break;
            }
        }
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return void
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $verbosity = (int)getenv('SPARTAN_VERBOSITY');
        if ($verbosity) {
            $output->setVerbosity($verbosity);
        }

        $this->input  = $input;
        $this->output = $output;
    }

    /**
     * Interacts with the user before the InputDefinition is validated.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return void
     * @throws \ReflectionException
     */
    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        if (!($input->hasOption('i') && $this->isOptionPresent('i'))) {
            return;
        }

        // get InputDefinition from Input
        $property = (new \ReflectionClass($input))->getProperty('definition');
        $property->setAccessible(true);
        /** @var InputDefinition $definition */
        $definition = $property->getValue($input);

        $arguments = array_diff_key($definition->getArguments(), array_flip(self::RESERVED_ARGUMENTS));
        $options   = array_diff_key($definition->getOptions(), array_flip(self::RESERVED_OPTIONS));

        foreach ($arguments as $argument) {
            $params    = (array)($this->interact[$argument->getName()] ?? ['inquire']);
            $method    = array_shift($params);
            $statement = trim($argument->getDescription(), '.') . ' [required]: ';

            $input->setArgument($argument->getName(), $this->{$method}($statement, ...$params));
        }

        foreach ($options as $option) {
            $default       = $option->getDefault();
            $exportDefault = is_array($default) ? json_encode($default) : (string)$default;
            $params        = (array)($this->interact[$option->getName()] ?? ['inquire']);
            $method        = array_shift($params);
            $statement     = trim($option->getDescription(), '.') . " [{$exportDefault}]: ";
            $params[1]     = $default;

            $input->setOption($option->getName(), $this->{$method}($statement, ...$params));
        }
    }

    /**
     * Options example
     * [
     *    'name' => ['inquire', 'Give me your name']
     * ]
     *
     * @param mixed[] $options
     *
     * @return $this
     */
    public function withInteract(array $options = [])
    {
        $this->withOption('i', 'Run with interaction');

        $this->interact = $options;

        return $this;
    }

    /**
     * @param string  $name
     * @param string  $description
     * @param mixed[] $aliases
     * @param mixed[] $examples
     *
     * @return self
     */
    public function withSynopsis(string $name, string $description, array $aliases = [], array $examples = [])
    {
        $help = [];
        foreach ($examples as $index => $example) {
            if (!is_numeric($index)) {
                $help[] = $index;
                $help[] = "    <info>{$example}</info>";
            } else {
                $help[] = "    <info>{$example}</info>";
            }
        }

        $this->setName($name)
             ->setAliases($aliases)
             ->setDescription($description)
             ->setHelp(implode("\n", $help));

        return $this;
    }

    /**
     * @param string $name
     * @param string $description
     *
     * @return self
     */
    public function withArgument(string $name, string $description)
    {
        return $this->addArgument($name, InputArgument::REQUIRED, $description);
    }

    /**
     * With optional option
     *
     * @param string $name        Option name
     * @param string $description Option description
     * @param mixed  $default     Default must be empty string to check if the user has provided a value for option
     *
     * @return self
     */
    public function withOption(string $name, string $description, $default = '')
    {
        return $this->addOption($name, null, InputOption::VALUE_OPTIONAL, $description, $default);
    }

    /**
     * @param string|string[]      $cmd
     * @param OutputInterface|null $output
     * @param mixed[]              $remote
     *
     * @return string
     */
    public function process($cmd, OutputInterface $output = null, array $remote = []): string
    {
        if (!$output) {
            $output = $this->output;
        }

        /** @var ProcessHelper $helper */
        $helper = $this->getHelper('process');
        $cmd    = is_string($cmd) ? explode(' ', $cmd) : $cmd;

        if ($remote) {
            $cmd = [
                'ssh',
                '-t',
                '-p',
                $remote['port'],
                "{$remote['user']}@{$remote['host']}",
                sprintf('"cd %s && %s"', $remote['path'], implode(' ', $cmd)),
            ];

            passthru(implode(' ', $cmd));

            return '';
        }

        $process = $helper->run(
            $output,
            (new Process($cmd))->setTimeout(0),
            null,
            function ($type, $buffer) use ($output) {
                if ($output->getVerbosity() == ConsoleOutput::VERBOSITY_VERY_VERBOSE) {
                    echo $buffer;
                }
            }
        );

        if ($process->isSuccessful()) {
            return $process->getOutput();
        }

        return $process->getErrorOutput();
    }

    /*
     * Style
     */

    /**
     * Shortcut for showing a line with note, warning etc.
     *
     * @param string  $method
     * @param mixed[] $params
     */
    public function __call(string $method, array $params): void
    {
        $this->output->writeln("<{$method}>{$params[0]}</{$method}>");
    }

    /**
     * Magic getters for arguments and options
     *
     * @param string $name
     *
     * @return bool|string|string[]|null
     */
    public function __get(string $name)
    {
        if ($this->input->hasArgument($name)) {
            return $this->input->getArgument($name);
        }

        return $this->input->getOption($name);
    }

    /*
     * Helpers
     */

    /**
     * Progress start
     *
     * @param OutputInterface $output
     * @param int             $steps
     */
    public function startProgress(OutputInterface $output, int $steps = 1): void
    {
        $this->progress = new ProgressBar($output, $steps);

        $this->progress->start();
    }

    /**
     * Progress advance
     *
     * @param int $steps
     */
    public function advanceProgress(int $steps = 1): void
    {
        $this->progress->advance($steps);
    }

    /**
     * Progress finish
     */
    public function finishProgress(): void
    {
        $this->progress->finish();
    }

    /**
     * @param string $text
     */
    public function panel(string $text): void
    {
        (new Panel())->render($text);
    }

    /**
     * @param mixed[] $header
     * @param mixed[] $body
     *
     * @return Table
     */
    public function table(array $header, array $body = []): Table
    {
        $table = new Table($this->output);
        $table->setHeaders($header);
        $table->setRows($body);

        return $table;
    }

    /**
     * @param string       $statement
     * @param mixed[]|null $config
     * @param bool         $default
     *
     * @return mixed
     */
    public function confirm(string $statement, array $config = null, bool $default = false)
    {
        $config = $config ?: [0 => ' [y/N] ', 1 => ' [Y/n] '];
        $append = $config[(int)$default];

        return $this->getHelper('question')
                    ->ask(
                        $this->input,
                        $this->output,
                        new ConfirmationQuestion("{$statement}{$append}", $default)
                    );
    }

    /**
     * @param string  $statement
     * @param mixed[] $options
     *
     * @return mixed
     */
    public function inquire(string $statement, $options = [])
    {
        $question = new Question($statement);
        if ($options) {
            $question->setAutocompleterValues($options);
        }

        return $this->getHelper('question')
                    ->ask(
                        $this->input,
                        $this->output,
                        $question
                    );
    }

    /**
     * @param string  $statement
     * @param mixed[] $choices
     * @param mixed   $default
     * @param bool    $isMulti
     *
     * @return mixed
     */
    public function choose(string $statement, array $choices, $default = null, bool $isMulti = false)
    {
        /*
         * Use advanced choose
         */
        if (!isset($choices[0])) {
            return (new Choices($choices))->ask();
        }

        $question = new ChoiceQuestion($statement, $choices, $default);
        $question->setMultiselect($isMulti);

        return $this->getHelper('question')
                    ->ask(
                        $this->input,
                        $this->output,
                        $question
                    );
    }

    /**
     * @param string  $statement
     * @param mixed[] $choices
     * @param mixed   $default
     *
     * @return mixed
     */
    public function chooseMulti(string $statement, array $choices, $default = null)
    {
        return $this->choose($statement, $choices, $default, true);
    }

    /**
     * Run another command
     *
     * @param string  $name
     * @param mixed[] $args
     *
     * @return int
     * @throws \Exception
     */
    public function call(string $name, array $args = [])
    {
        $application = $this->getApplication();

        if (!$application) {
            throw new \InvalidArgumentException('Application was not set.');
        }

        $command = $application->find($name);

        $input = new ArrayInput(['command' => $name] + $args);

        return $command->run($input, $this->output);
    }

    /*
     * Input helpers
     */

    /**
     * @param string $name
     *
     * @return bool
     * @throws \ReflectionException
     */
    public function isOptionPresent(string $name): bool
    {
        $obj      = new \ReflectionObject($this->input);
        $property = $obj->getProperty('definition');
        $property->setAccessible(true);

        return $this->input->getOption($name)
            !== $property->getValue($this->input)->getOption($name)->getDefault();
    }

    /**
     * @return mixed[]
     */
    public function inputArguments()
    {
        return array_diff_key($this->input->getArguments(), array_flip(self::RESERVED_ARGUMENTS));
    }

    /**
     * @return mixed[]
     */
    public function inputOptions()
    {
        return array_diff_key($this->input->getOptions(), array_flip(self::RESERVED_OPTIONS));
    }

    /**
     * @return mixed[]
     */
    public function inputValues()
    {
        return $this->inputArguments() + $this->inputOptions();
    }
}
