<?php

declare(strict_types=1);

namespace Belsignum\L10nPagePerms\Command;

use Belsignum\L10nPagePerms\Service\ExcludedFieldSynchronizer;
use Belsignum\L10nPagePerms\Service\PermissionSynchronizer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Repairs page translations whose access rights fell behind their default language page, and then the
 * translations that missed a change of an l10n_mode=exclude field (such as backend_layout) because of it.
 */
final class RepairCommand extends Command
{
    public function __construct(
        private readonly PermissionSynchronizer $permissionSynchronizer,
        private readonly ExcludedFieldSynchronizer $excludedFieldSynchronizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                'Step 1 gives every page translation the access rights (perms_*) of its default language page, '
                . 'deleted translations and workspace versions included. Step 2 copies the l10n_mode=exclude '
                . 'fields of the default language page into live translations that differ and flushes '
                . 'their page cache. Run it with --dry-run first.'
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the deviations and change nothing')
            ->addOption('skip-excluded-fields', null, InputOption::VALUE_NONE, 'Repair the access rights only (step 1)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool)$input->getOption('dry-run');
        $result = Command::SUCCESS;

        $io->section('Step 1: access rights of page translations');
        $deviations = $this->permissionSynchronizer->findDeviations();
        if ($deviations === []) {
            $io->success('All page translations have the access rights of their default language page.');
        } else {
            $io->table(
                ['Translation', 'Language', 'Default page', 'Now (owner, group, user/group/everybody)', 'Target'],
                array_map(
                    fn(array $deviation): array => [
                        $deviation['uid'],
                        $deviation['language'],
                        $deviation['parent'],
                        $this->formatPermissions($deviation['current']),
                        $this->formatPermissions($deviation['expected']),
                    ],
                    $deviations
                )
            );
            if ($dryRun) {
                $io->note(sprintf('%d page translations differ. Dry run, nothing changed.', count($deviations)));
            } else {
                $changed = 0;
                foreach (array_unique(array_column($deviations, 'parent')) as $parent) {
                    $changed += $this->permissionSynchronizer->synchronize($parent);
                }
                $io->success(sprintf('Gave %d page translations the access rights of their default language page.', $changed));
            }
        }

        if ($input->getOption('skip-excluded-fields')) {
            return $result;
        }

        $io->section('Step 2: l10n_mode=exclude fields of page translations');
        $io->text('Fields: ' . implode(', ', $this->excludedFieldSynchronizer->getFieldNames()));
        $deviations = $this->excludedFieldSynchronizer->findDeviations();
        if ($deviations === []) {
            $io->success('All live page translations have the l10n_mode=exclude fields of their default language page.');
            return $result;
        }
        $io->table(
            ['Translation', 'Language', 'Default page', 'Field: now → target'],
            array_map(
                fn(array $deviation): array => [
                    $deviation['uid'],
                    $deviation['language'],
                    $deviation['parent'],
                    implode("\n", array_map(
                        fn(string $field, array $values): string => sprintf(
                            '%s: %s → %s',
                            $field,
                            $this->shorten((string)$values['current']),
                            $this->shorten((string)$values['expected'])
                        ),
                        array_keys($deviation['fields']),
                        $deviation['fields']
                    )),
                ],
                $deviations
            )
        );
        if ($dryRun) {
            $io->note(sprintf('%d live page translations differ. Dry run, nothing changed.', count($deviations)));
            return $result;
        }

        $changed = $this->excludedFieldSynchronizer->synchronize($deviations);
        $remaining = $this->excludedFieldSynchronizer->findDeviations();
        if ($remaining === []) {
            $io->success(sprintf('Gave %d live page translations the values of their default language page and flushed their page cache.', $changed));
            return $result;
        }
        $io->warning(sprintf(
            'Changed %d live page translations. %d still differ in relation fields, which only a save of the translation in the backend resolves: %s',
            $changed,
            count($remaining),
            implode(', ', array_map(
                static fn(array $deviation): string => $deviation['uid'] . ' (' . implode(', ', array_keys($deviation['fields'])) . ')',
                $remaining
            ))
        ));
        return Command::FAILURE;
    }

    /**
     * @param array<string, int> $permissions
     */
    private function formatPermissions(array $permissions): string
    {
        return sprintf(
            '%d, %d, %d/%d/%d',
            $permissions['perms_userid'],
            $permissions['perms_groupid'],
            $permissions['perms_user'],
            $permissions['perms_group'],
            $permissions['perms_everybody']
        );
    }

    private function shorten(string $value): string
    {
        $value = $value === '' ? '(empty)' : $value;
        return mb_strlen($value) > 40 ? mb_substr($value, 0, 39) . '…' : $value;
    }
}
