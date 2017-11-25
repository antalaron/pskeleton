<?php

/*
 * This file is part of PSkeleton.
 *
 * (c) Antal Áron <antalaron@antalaron.hu>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PSkeleton;

use Distill\Distill;
use Distill\Strategy\MinimumSize;
use GuzzleHttp\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Filesystem\Filesystem;

/**
 * NewCommand.
 *
 * @author Antal Áron <antalaron@antalaron.hu>
 */
class NewCommand extends Command
{
    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var string
     */
    private $recipe;

    /**
     * @var string
     */
    private $version;

    /**
     * @var string
     */
    private $projectDir;

    /**
     * @var string
     */
    private $downloadPath;

    /**
     * @var string
     */
    private $downloadFile;

    /**
     * @var array
     */
    private $variables;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('new')
            ->setDescription('Creates new project from skeleton')
            ->addArgument('skeleton', InputArgument::REQUIRED, 'Skeleton which used as base for the project.')
            ->addArgument('directory', InputArgument::OPTIONAL, 'Directory where the new project will be created.')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->filesystem = new Filesystem();

        $skeleton = $input->getArgument('skeleton');
        if (false !== strpos($skeleton, ':')) {
            list($this->recipe, $this->version) = explode(':', $skeleton, 2);
        } else {
            $this->recipe = $skeleton;
            $this->version = 'latest';
        }

        if (null !== $input->getArgument('directory')) {
            $directory = rtrim(trim($input->getArgument('directory')), DIRECTORY_SEPARATOR);
        } else {
            list(, $directory) = explode('/', $this->recipe);
        }

        $this->projectDir = $this->filesystem->isAbsolutePath($directory) ? $directory : getcwd().DIRECTORY_SEPARATOR.$directory;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln(sprintf(
            'Installing <info>%s</info> with version <info>%s</info> to <info>%s</info>',
            $this->recipe,
            $this->version,
            $this->projectDir
        ));

        try {
            $this
                ->validateSkeleton()
                ->checkProjectDir()
                ->download()
                ->extract()
                ->setVariables()
            ;
        } catch (\Exception $e) {
            $this->cleanUp();

            throw $e;
        }

        $this->cleanUp();
    }

    private function validateSkeleton()
    {
        $client = new Client();
        $response = $client->get('https://raw.githubusercontent.com/pskeleton/recipes/master/config.json');

        $config = json_decode($response->getBody()->getContents(), true);

        if (!array_key_exists($this->recipe, $config['skeletons'])) {
            throw new \Exception(sprintf('No recipe for %s. Recipies: %s', $this->recipe, implode(', ', array_keys($config['skeletons']))));
        }

        if ('latest' === $this->version) {
            $this->version = $config['skeletons'][$this->recipe]['current-version'];
        }

        if (!in_array($this->version, $config['skeletons'][$this->recipe]['versions'], true)) {
            throw new \Exception(sprintf('No version %s for recipe %s. Valid versions: %s', $this->version, $this->recipe, implode(', ', $config['skeletons'][$this->recipe]['versions'])));
        }

        $response = $client->get('https://raw.githubusercontent.com/pskeleton/recipes/master/'.$this->recipe.'/'.$this->version.'/config.json');

        $this->variables = json_decode($response->getBody()->getContents(), true);
        $this->variables = $this->variables['variables'];

        return $this;
    }

    private function checkProjectDir()
    {
        if (is_dir($this->projectDir) && !$this->isEmptyDirectory($this->projectDir)) {
            throw new \Exception(sprintf('Directory %s is not empty', $this->projectDir));
        }

        return $this;
    }

    private function download()
    {
        $distill = new Distill();
        $archiveFile = $distill
            ->getChooser()
            ->setStrategy(new MinimumSize())
            ->addFilesWithDifferentExtensions('https://github.com/pskeleton/recipes/archive/master', ['zip'])
            ->getPreferredFile()
        ;

        $this->downloadPath = rtrim(getcwd(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.'.uniqid(time()).DIRECTORY_SEPARATOR;

        $this->downloadFile = $this->downloadPath.DIRECTORY_SEPARATOR.'pskeleton.'.pathinfo($archiveFile, PATHINFO_EXTENSION);

        $client = new Client();
        $response = $client->get((string) $archiveFile);

        $this->filesystem->dumpFile($this->downloadFile, $response->getBody());

        return $this;
    }

    private function extract()
    {
        $distill = new Distill();
        $extractionSucceeded = $distill->extractWithoutRootDirectory($this->downloadFile, $this->downloadPath.'src');

        $this->filesystem->mirror(
            $this->downloadPath.'src'.DIRECTORY_SEPARATOR.$this->recipe.DIRECTORY_SEPARATOR.$this->version.DIRECTORY_SEPARATOR.'src',
            $this->projectDir
        );

        return $this;
    }

    private function setVariables()
    {
        $helper = $this->getHelper('question');
        foreach ($this->variables as $key => $variable) {
            $question = new Question($variable['title'].' ['.$variable['default'].'] ', $variable['default']);

            $value = $helper->ask($this->input, $this->output, $question);
            foreach ($variable['files'] as $file) {
                file_put_contents($this->projectDir.'/'.$file, str_replace($key, $value, file_get_contents($this->projectDir.'/'.$file)));
            }
        }

        return $this;
    }

    protected function isEmptyDirectory($dir)
    {
        return 2 === count(scandir($dir.'/'));
    }

    private function cleanUp()
    {
        if (null !== $this->downloadPath) {
            $this->filesystem->remove($this->downloadPath);
        }

        return $this;
    }
}
