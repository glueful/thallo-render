<?php

declare(strict_types=1);

namespace Thallo\Render\Console;

use Glueful\Console\BaseCommand;
use Thallo\Render\Templates\ThemeCloner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `render:theme:clone <name> [--from=default]` — scaffold a new app theme by
 * copying an existing one into themes/{name}/. The CLI form works on every
 * deployment (the admin button needs a writable app dir); the copied theme
 * inherits nothing implicitly — it IS a full copy, editable in place or via
 * the admin's per-theme DB overrides.
 */
#[AsCommand(
    name: 'render:theme:clone',
    description: 'Clone a theme into a new themes/{name} directory.',
)]
final class ThemeCloneCommand extends BaseCommand
{
    public function __construct(private readonly ThemeCloner $cloner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The new theme name (lowercase, dashes/underscores)');
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Source theme to copy', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $created = $this->cloner->clone(
                (string) $input->getArgument('name'),
                (string) $input->getOption('from'),
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->success(sprintf("Theme '%s' created at %s.", $created['name'], $created['path']));
        $this->line('Edit it on disk or through the admin templates page (per-theme overrides).');
        return self::SUCCESS;
    }
}
