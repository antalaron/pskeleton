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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * UpdateCommand.
 *
 * @author Antal Áron <antalaron@antalaron.hu>
 */
class UpdateCommand extends Command
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
     * @var bool
     */
    private $doUpdate = false;

    /**
     * @var string
     */
    private $newestVersion;

    /**
     * @var array
     */
    private $release;

    /**
     * @var string
     */
    private $newReleaseFile;

    /**
     * @var string
     */
    private $currentReleaseFile;

    /**
     * @var string
     */
    private $currentReleaseBackupFile;

    /**
     * @var string
     */
    private $tempDir;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('update')
            ->setDescription('Self update')
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
        $this->currentReleaseFile = realpath($_SERVER['argv'][0]) ?: $_SERVER['argv'][0];
        $this->tempDir = sys_get_temp_dir();
        $this->currentReleaseBackupFile = basename($this->currentReleaseFile, '.phar').'-backup.phar';
        $this->newReleaseFile = $this->tempDir.'/'.basename($this->currentReleaseFile, '.phar').'-temp.phar';
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this
                ->validateVersion()
                ->downloadFile()
                ->backupCurrentVersion()
                ->replaceCurrentVersionbyNewVersion()
            ;
        } catch (\Exception $e) {
            $this->cleanUp();

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled()
    {
        return 'phar://' === substr(__DIR__, 0, 7);
    }

    private function validateVersion()
    {
        $client = new Client();
        $response = $client->get('https://api.github.com/repos/pskeleton/pskeleton/releases');

        $config = json_decode($response->getBody()->getContents(), true);

        $this->release = $config[0];
        $this->newestVersion = substr($this->release['tag_name'], 1);

        if (false !== strpos($this->getApplication()->getVersion(), '-')) {
            $currentVersion = substr($this->getApplication()->getVersion(), 0, strpos($this->getApplication()->getVersion(), '-'));
        } else {
            $currentVersion = $this->getApplication()->getVersion();
        }

        $this->doUpdate = version_compare($this->newestVersion, $currentVersion) > 0;

        return $this;
    }

    private function downloadFile()
    {
        if (!$this->doUpdate) {
            $this->output->writeln('Already in latest version.');

            return $this;
        }

        $this->output->writeln(sprintf('Downloading %s', $this->newestVersion));

        if (!is_writable($this->currentReleaseFile)) {
            throw new \Exception('PSkeleton update failed: the "'.$this->currentInstallerFile.'" file could not be written');
        }

        if (!is_writable($this->tempDir)) {
            throw new \Exception('PSkeleton update failed: the "'.$this->tempDir.'" directory used to download files temporarily could not be written');
        }

        $remoteFile = null;
        foreach ($this->release['assets'] as $asset) {
            if ('pskeleton.phar' === $asset['name']) {
                $remoteFile = $asset['browser_download_url'];

                break;
            }
        }

        if (null === $remoteFile) {
            throw new \Exception('The new version of the PSkeleton couldn\'t be downloaded from the server.');
        }

        $client = new Client();
        $response = $client->get($remoteFile, [
            'sink' => $this->newReleaseFile,
        ]);

        return $this;
    }

    private function backupCurrentVersion()
    {
        $this->filesystem->copy($this->currentReleaseFile, $this->currentReleaseBackupFile, true);

        return $this;
    }

    private function replaceCurrentVersionbyNewVersion()
    {
        $this->filesystem->copy($this->newReleaseFile, $this->currentReleaseFile, true);

        return $this;
    }

    private function cleanUp()
    {
        return $this;
    }
}
