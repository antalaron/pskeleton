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

use GuzzleHttp\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * ListCommand.
 *
 * @author Antal Áron <antalaron@antalaron.hu>
 */
class ListCommand extends Command
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
            ->setName('skeletons')
            ->setDescription('List available skeletons')
            ->addArgument('skeleton', InputArgument::OPTIONAL, 'Skeleton which used as base for the project.')
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

        $this->recipe = $input->getArgument('skeleton');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            if (null === $this->recipe) {
                $this
                    ->validateSkeletons()
                ;
            } else {
                $this
                    ->validateSkeleton()
                ;
            }
        } catch (\Exception $e) {
            $this->cleanUp();

            throw $e;
        }
    }

    private function validateSkeletons()
    {
        $client = new Client();
        $response = $client->get('https://raw.githubusercontent.com/pskeleton/recipes/master/config.json');

        $config = json_decode($response->getBody()->getContents(), true);

        $table = new Table($this->output);
        $table->setStyle('compact');

        $rows = [];
        foreach ($config['skeletons'] as $name => $value) {
            $rows[] = [$name, $value['description']];
        }
        $table->setRows($rows);
        $table->render();

        return $this;
    }

    private function validateSkeleton()
    {
        $client = new Client();
        $response = $client->get('https://raw.githubusercontent.com/pskeleton/recipes/master/config.json');

        $config = json_decode($response->getBody()->getContents(), true);

        if (!array_key_exists($this->recipe, $config['skeletons'])) {
            throw new \Exception(sprintf('No skeleton for "%s"', $this->recipe));
        }

        $this->output->writeln(sprintf('<info>%s</info>: %s', $this->recipe, $config['skeletons'][$this->recipe]['description']));
        $currentVersion = $config['skeletons'][$this->recipe]['current-version'];

        foreach ($config['skeletons'][$this->recipe]['versions'] as $version) {
            $this->output->writeln(sprintf('%s%s%s', $version === $currentVersion ? '<info>* ' : '  ', $version, $version === $currentVersion ? '</info> current version' : ''));
        }

        return $this;
    }

    private function cleanUp()
    {
        return $this;
    }
}
