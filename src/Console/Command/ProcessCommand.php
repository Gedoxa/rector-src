<?php

declare(strict_types=1);

namespace Rector\Core\Console\Command;

use Rector\Caching\Detector\ChangedFilesDetector;
use Rector\ChangesReporting\Application\ErrorAndDiffCollector;
use Rector\ChangesReporting\Output\ConsoleOutputFormatter;
use Rector\Composer\Processor\ComposerProcessor;
use Rector\Core\Application\RectorApplication;
use Rector\Core\Autoloading\AdditionalAutoloader;
use Rector\Core\Configuration\Configuration;
use Rector\Core\Configuration\Option;
use Rector\Core\Console\Output\OutputFormatterCollector;
use Rector\Core\FileSystem\FilesFinder;
use Rector\Core\FileSystem\PhpFilesFinder;
use Rector\Core\Guard\RectorGuard;
use Rector\Core\NonPhpFile\NonPhpFileProcessor;
use Rector\Core\PhpParser\NodeTraverser\RectorNodeTraverser;
use Rector\Core\Stubs\StubLoader;
use Rector\Core\ValueObject\StaticNonPhpFileSuffixes;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symplify\PackageBuilder\Console\ShellCode;

final class ProcessCommand extends AbstractCommand
{
    /**
     * @var FilesFinder
     */
    private $filesFinder;

    /**
     * @var AdditionalAutoloader
     */
    private $additionalAutoloader;

    /**
     * @var RectorGuard
     */
    private $rectorGuard;

    /**
     * @var ErrorAndDiffCollector
     */
    private $errorAndDiffCollector;

    /**
     * @var Configuration
     */
    private $configuration;

    /**
     * @var RectorApplication
     */
    private $rectorApplication;

    /**
     * @var OutputFormatterCollector
     */
    private $outputFormatterCollector;

    /**
     * @var RectorNodeTraverser
     */
    private $rectorNodeTraverser;

    /**
     * @var StubLoader
     */
    private $stubLoader;

    /**
     * @var NonPhpFileProcessor
     */
    private $nonPhpFileProcessor;

    /**
     * @var SymfonyStyle
     */
    private $symfonyStyle;

    /**
     * @var ComposerProcessor
     */
    private $composerProcessor;

    /**
     * @var PhpFilesFinder
     */
    private $phpFilesFinder;

    public function __construct(
        AdditionalAutoloader $additionalAutoloader,
        ChangedFilesDetector $changedFilesDetector,
        Configuration $configuration,
        ErrorAndDiffCollector $errorAndDiffCollector,
        FilesFinder $filesFinder,
        NonPhpFileProcessor $nonPhpFileProcessor,
        OutputFormatterCollector $outputFormatterCollector,
        RectorApplication $rectorApplication,
        RectorGuard $rectorGuard,
        RectorNodeTraverser $rectorNodeTraverser,
        StubLoader $stubLoader,
        SymfonyStyle $symfonyStyle,
        ComposerProcessor $composerProcessor,
        PhpFilesFinder $phpFilesFinder
    ) {
        $this->filesFinder = $filesFinder;
        $this->additionalAutoloader = $additionalAutoloader;
        $this->rectorGuard = $rectorGuard;
        $this->errorAndDiffCollector = $errorAndDiffCollector;
        $this->configuration = $configuration;
        $this->rectorApplication = $rectorApplication;
        $this->outputFormatterCollector = $outputFormatterCollector;
        $this->rectorNodeTraverser = $rectorNodeTraverser;
        $this->stubLoader = $stubLoader;
        $this->nonPhpFileProcessor = $nonPhpFileProcessor;
        $this->changedFilesDetector = $changedFilesDetector;
        $this->symfonyStyle = $symfonyStyle;
        $this->composerProcessor = $composerProcessor;
        $this->phpFilesFinder = $phpFilesFinder;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Upgrade or refactor source code with provided rectors');
        $this->addArgument(
            Option::SOURCE,
            InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
            'Files or directories to be upgraded.'
        );
        $this->addOption(
            Option::OPTION_DRY_RUN,
            'n',
            InputOption::VALUE_NONE,
            'See diff of changes, do not save them to files.'
        );

        $this->addOption(
            Option::OPTION_AUTOLOAD_FILE,
            'a',
            InputOption::VALUE_REQUIRED,
            'File with extra autoload'
        );

        $names = $this->outputFormatterCollector->getNames();

        $description = sprintf('Select output format: "%s".', implode('", "', $names));
        $this->addOption(
            Option::OPTION_OUTPUT_FORMAT,
            'o',
            InputOption::VALUE_OPTIONAL,
            $description,
            ConsoleOutputFormatter::NAME
        );

        $this->addOption(
            Option::OPTION_NO_PROGRESS_BAR,
            null,
            InputOption::VALUE_NONE,
            'Hide progress bar. Useful e.g. for nicer CI output.'
        );

        $this->addOption(
            Option::OPTION_NO_DIFFS,
            null,
            InputOption::VALUE_NONE,
            'Hide diffs of changed files. Useful e.g. for nicer CI output.'
        );

        $this->addOption(
            Option::OPTION_OUTPUT_FILE,
            null,
            InputOption::VALUE_REQUIRED,
            'Location for file to dump result in. Useful for Docker or automated processes'
        );

        $this->addOption(Option::CACHE_DEBUG, null, InputOption::VALUE_NONE, 'Debug changed file cache');
        $this->addOption(Option::OPTION_CLEAR_CACHE, null, InputOption::VALUE_NONE, 'Clear unchaged files cache');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->configuration->resolveFromInput($input);
        $this->configuration->validateConfigParameters();
        $this->configuration->setAreAnyPhpRectorsLoaded((bool) $this->rectorNodeTraverser->getPhpRectorCount());

        $this->rectorGuard->ensureSomeRectorsAreRegistered();
        $this->stubLoader->loadStubs();

        $paths = $this->configuration->getPaths();

        $phpFileInfos = $this->phpFilesFinder->findInPaths($paths);

        $this->additionalAutoloader->autoloadWithInputAndSource($input);

        if ($this->configuration->isCacheDebug()) {
            $message = sprintf('[cache] %d files after cache filter', count($phpFileInfos));
            $this->symfonyStyle->note($message);
            $this->symfonyStyle->listing($phpFileInfos);
        }

        $this->configuration->setFileInfos($phpFileInfos);
        $this->rectorApplication->runOnPaths($paths);

        // must run after PHP rectors, because they might change class names, and these class names must be changed in configs
        $nonPhpFileInfos = $this->filesFinder->findInDirectoriesAndFiles(
            $paths,
            StaticNonPhpFileSuffixes::SUFFIXES
        );

        $this->nonPhpFileProcessor->runOnFileInfos($nonPhpFileInfos);

        $composerJsonFilePath = getcwd() . '/composer.json';
        $this->composerProcessor->process($composerJsonFilePath);

        $this->reportZeroCacheRectorsCondition();

        // report diffs and errors
        $outputFormat = (string) $input->getOption(Option::OPTION_OUTPUT_FORMAT);

        $outputFormatter = $this->outputFormatterCollector->getByName($outputFormat);
        $outputFormatter->report($this->errorAndDiffCollector);

        // invalidate affected files
        $this->invalidateAffectedCacheFiles();

        // some errors were found → fail
        if ($this->errorAndDiffCollector->getErrors() !== []) {
            return ShellCode::ERROR;
        }
        // inverse error code for CI dry-run
        if (! $this->configuration->isDryRun()) {
            return ShellCode::SUCCESS;
        }
        if ($this->errorAndDiffCollector->getFileDiffsCount() === 0) {
            return ShellCode::SUCCESS;
        }
        return ShellCode::ERROR;
    }

    private function reportZeroCacheRectorsCondition(): void
    {
        if (! $this->configuration->isCacheEnabled()) {
            return;
        }

        if ($this->configuration->shouldClearCache()) {
            return;
        }

        if (! $this->rectorNodeTraverser->hasZeroCacheRectors()) {
            return;
        }

        if ($this->configuration->shouldHideClutter()) {
            return;
        }

        $message = sprintf(
            'Ruleset contains %d rules that need "--clear-cache" option to analyse full project',
            $this->rectorNodeTraverser->getZeroCacheRectorCount()
        );

        $this->symfonyStyle->note($message);
    }

    private function invalidateAffectedCacheFiles(): void
    {
        if (! $this->configuration->isCacheEnabled()) {
            return;
        }

        foreach ($this->errorAndDiffCollector->getAffectedFileInfos() as $affectedFileInfo) {
            $this->changedFilesDetector->invalidateFile($affectedFileInfo);
        }
    }
}
