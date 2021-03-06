<?php
/**
 * @file
 * Symlink.php
 */

namespace derhasi\symlinker\Command;

use derhasi\symlinker\Exception\TargetAlreadyExistsException;
use derhasi\symlinker\Exception\TargetAlreadyLinkedException;
use derhasi\symlinker\Exception\TargetAlreadyLinkedToSourceException;
use derhasi\symlinker\Symlink;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SymlinkSingleCmd extends Command {

    /**
     * @var \Symfony\Component\Console\Input\InputInterface
     */
    protected $input;

    /**
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected $output;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('symlink-single')
          ->setDescription('Create a single symlink')
          ->addArgument(
            'target',
            InputArgument::REQUIRED,
            'Location for the link to be created'
          )
          ->addArgument(
            'source',
            InputArgument::REQUIRED,
            'Path the symlink shall link to'
          )
          ->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Force symlink creation'
          )
          ->addOption(
            'no-backup',
            null,
            InputOption::VALUE_NONE,
            'Do not create a backup of the target, if it already exists'
          )
          ->addOption(
            'relativeSource',
            null,
            InputOption::VALUE_NONE,
            'The source is given relative to the target'
          )
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $target = $input->getArgument('target');
        $source = $input->getArgument('source');
        $backup = !$input->getOption('no-backup');
        $force = $input->getOption('force');
        $relativeSource = $input->getOption('relativeSource');

        $this->symlink($target, $source, $force, $backup, $relativeSource);
    }

    /**
     * Helper for wrapping the symlink.
     *
     * @param $target
     * @param $source
     * @param $force
     * @param $backup
     * @param $relativeSource
     */
    protected function symlink($target, $source, $force, $backup, $relativeSource)
    {
        $symlink = new Symlink($target, getcwd());

        if ($relativeSource) {
            $symlink->setSource($source);
        }
        else {
            $symlink->setSourceFromWorkingDirectory($source);
        }

        try {
            $symlink->create($target, $source);
            $this->output->writeln(sprintf('<info>Symlink created: %s to %s</info>', $source, $target));
        }
        // If the target already is linked correctly, we only print a different
        // message.
        catch (TargetAlreadyLinkedToSourceException $e) {
            $this->output->writeln('<info>' . $e->getMessage() . '</info>');
        }
        // If the target already exists, we may retry.
        catch (TargetAlreadyExistsException $e) {
            $this->symlinkRetry($target, $source, $force, $backup, $relativeSource, $e);
        }
        // If the target already is linked, we may retry.
        catch (TargetAlreadyLinkedException $e) {
            $this->symlinkRetry($target, $source, $force, $backup, $relativeSource, $e);
        }
    }

    /**
     * Wrapper to handle possible retry.
     *
     * @param $target
     * @param $source
     * @param $force
     * @param $backup
     * @param \RuntimeException $e
     */
    protected function symlinkRetry($target, $source, $force, $backup, $relativeSource, \RuntimeException $e)
    {
        if ($force) {
            if ($backup) {
                $this->backup($target);
                $this->output->writeln(sprintf('<comment>Created backup of %s.</comment>', $target));
            }
            $this->removeRecursive($target);
            $this->output->writeln(sprintf('<comment>Removed %s.</comment>', $target));
            
            // Retry.
            $this->symlink($target, $source, FALSE, FALSE, $relativeSource);
        }
        else {
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');
        }
    }

    /**
     * Helper to backup file.
     *
     * @param string $path
     */
    protected function backup($path)
    {
        $new_path = $path . '.' . date('c') . '.bak';
        return rename($path, $new_path);
    }

    /**
     * This function recursively deletes all files and folders under the given
     * directory, and then the directory itself.
     *
     * equivalent to Bash: rm -r $path
     * @param string $path
     */
    public static function removeRecursive($path) {

        // If the path is not a directory we can simply unlink it.
        if (!is_dir($path)) {
            chmod($path, 0777);
            return unlink($path);
        }

        // Otherwise we go through the whole directory.
        $it = new \RecursiveDirectoryIterator($path);

        // Fix permissions for all folders and files first, before we try to change
        // the file mode.
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($files as $file) {
            if ('.' === $file->getBasename() || '..' === $file->getBasename()) {
                continue;
            }
            chmod($file->getPathname(), 0777);
        }

        // Delete files first, before folders are removed.
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ('.' === $file->getBasename() || '..' === $file->getBasename()) {
                continue;
            }
            if ($file->isDir()) {
                rmdir($file->getPathname());
            }
            else {
                unlink($file->getPathname());
            }
        }
        return rmdir($path);
    }

}
