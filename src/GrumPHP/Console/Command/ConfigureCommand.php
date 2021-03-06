<?php

namespace GrumPHP\Console\Command;

use Exception;
use Gitonomy\Git\Repository;
use GrumPHP\Configuration\GrumPHP;
use GrumPHP\Console\Helper\PathsHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Class ConfigureCommand
 *
 * @package GrumPHP\Console\Command
 */
class ConfigureCommand extends Command
{
    const COMMAND_NAME = 'configure';

    /**
     * @var GrumPHP
     */
    protected $config;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var Repository
     */
    protected $repository;

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @param GrumPHP    $config
     * @param Filesystem $filesystem
     * @param Repository $repository
     */
    public function __construct(GrumPHP $config, Filesystem $filesystem, Repository $repository)
    {
        parent::__construct();

        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->repository = $repository;
    }

    /**
     * Configure command
     */
    protected function configure()
    {
        $this->setName(self::COMMAND_NAME);
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int|void
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;

        $grumphpConfigName = $this->input->getOption('config');
        if ($this->filesystem->exists($grumphpConfigName)) {
            if ($input->isInteractive()) {
                $output->writeln('<fg=yellow>GrumPHP is already configured!</fg=yellow>');
            }
            return;
        }

        // Check configuration:
        $configuration = $this->buildConfiguration($input, $output);
        if (!$configuration) {
            $output->writeln('<fg=yellow>Skipped configuring GrumPHP. Using default configuration.</fg=yellow>');
            return;
        }

        // Check write action
        $written = $this->writeConfiguration($configuration);
        if (!$written) {
            $output->writeln('<fg=red>The configuration file could not be saved. Give me some permissions!</fg=red>');
            return;
        }

        if ($input->isInteractive()) {
            $output->writeln('<fg=green>GrumPHP is configured and ready to kick ass!</fg=green>');
        }
    }

    /**
     * This method will ask the developer for it's input and will result in a configuration array.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return array
     */
    protected function buildConfiguration(InputInterface $input, OutputInterface $output)
    {

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        $questionString = $this->createQuestionString(
            'No grumphp.yml file could be found. Do you want to create one?',
            'Yes'
        );
        $question = new ConfirmationQuestion($questionString, true);
        if (!$helper->ask($input, $output, $question)) {
            return array();
        }

        // Search for git_dir
        $default = $this->guessGitDir();
        $questionString = $this->createQuestionString('In which folder is GIT initialized?', $default);
        $question = new Question($questionString, $default);
        $question->setValidator(array($this, 'pathValidator'));
        $gitDir = $helper->ask($input, $output, $question);

        // Search for bin_dir
        $default = $this->guessBinDir();
        $questionString = $this->createQuestionString('Where can we find the executables?', $default);
        $question = new Question($questionString, $default);
        $question->setValidator(array($this, 'pathValidator'));
        $binDir = $helper->ask($input, $output, $question);

        // Search tasks
        $question = new ChoiceQuestion(
            'Which tasks do you want to run?',
            $this->getAvailableTasks(),
            array()
        );
        $question->setMultiselect(true);
        $tasks = $helper->ask($input, $output, $question);

        // Build configuration
        return array(
            'parameters' => array
            (
                'git_dir' => $gitDir,
                'bin_dir' => $binDir,
                'tasks' => array_map(function ($task) {
                    return null;
                }, array_flip($tasks)),
            )
        );
    }

    /**
     * @param        $question
     * @param null   $default
     * @param string $separator
     *
     * @return string
     */
    protected function createQuestionString($question, $default = null, $separator = ':')
    {
        return $default !== null ?
            sprintf('<info>%s</info> [<comment>%s</comment>]%s ', $question, $default, $separator) :
            sprintf('<info>%s</info>%s ', $question, $separator);
    }

    /**
     * @param array $configuration
     *
     * @return bool|int
     */
    protected function writeConfiguration(array $configuration)
    {
        try {
            $yaml = Yaml::dump($configuration);
            $grumphpConfigName = $this->input->getOption('config');
            return file_put_contents($grumphpConfigName, $yaml);
        } catch (Exception $e) {
            // Fail silently and return false!
        }

        return false;
    }

    /**
     * Make a guess to the bin dir
     *
     * @return string
     */
    protected function guessBinDir()
    {
        $defaultBinDir = $this->config->getBinDir();
        $composerFile = 'composer.json';
        if (!$this->filesystem->exists($composerFile)) {
            return $defaultBinDir;
        }

        $composer = json_decode(file_get_contents($composerFile), true);
        if (isset($composer['config']['bin-dir'])) {
            return $composer['config']['bin-dir'];
        }

        return $defaultBinDir;
    }

    /**
     * @return string
     */
    protected function guessGitDir()
    {
        $defaultGitDir = $this->config->getGitDir();
        try {
            $topLevel = $this->repository->run('rev-parse', array('--show-toplevel'));
        } catch (Exception $e) {
            return $defaultGitDir;
        }

        return rtrim($this->paths()->getRelativePath($topLevel), '/');
    }

    /**
     * @param $path
     *
     * @return bool
     */
    public function pathValidator($path)
    {
        if (!$this->filesystem->exists($path)) {
            throw new \RuntimeException(sprintf('The path %s could not be found!', $path));
        }
        return $path;
    }

    /**
     * Return a list of all available tasks
     *
     * TODO: find a way to load this from the service container.
     *
     * @return array
     */
    protected function getAvailableTasks()
    {
        $tasks = array(
            '1' => 'behat',
            '2' => 'blacklist',
            '3' => 'phpcs',
            '4' => 'phpcsfixer',
            '5' => 'phpspec',
            '6' => 'phpunit',
        );
        return $tasks;
    }

    /**
     * @return PathsHelper
     */
    protected function paths()
    {
        return $this->getHelper(PathsHelper::HELPER_NAME);
    }
}
