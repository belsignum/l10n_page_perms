<?php

declare(strict_types=1);

namespace Belsignum\L10nPagePerms\Service;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Repairs page translations that missed a change of a field with l10n_mode=exclude, for example
 * backend_layout.
 *
 * The DataHandler copies such fields from the default language page to its translations when the default
 * language page is saved. It refuses this for every translation the editor has no edit access to, and since
 * the access rights of translations can fall behind (see PermissionSynchronizer), translations end up with
 * outdated values. Only the system log notes it.
 *
 * The repair writes the values directly instead of saving the translations through the DataHandler: a
 * DataHandler save of a translation also takes over every field with allowLanguageSynchronization whose
 * l10n_state is not set, and could overwrite translated values that way.
 */
final class ExcludedFieldSynchronizer
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly CacheManager $cacheManager,
    ) {}

    /**
     * @return list<string> Columns of "pages" with l10n_mode=exclude
     */
    public function getFieldNames(): array
    {
        $fieldNames = [];
        foreach ($GLOBALS['TCA']['pages']['columns'] ?? [] as $fieldName => $configuration) {
            if (($configuration['l10n_mode'] ?? null) === 'exclude') {
                $fieldNames[] = (string)$fieldName;
            }
        }
        return $fieldNames;
    }

    /**
     * Whether the raw value of the field can be copied as it is. Not for relations kept in other tables (inline,
     * file, MM) and not for relations to records that have translations themselves: the DataHandler resolves
     * those when the translation is saved in the backend.
     */
    public function canBeCopied(string $fieldName): bool
    {
        $configuration = $GLOBALS['TCA']['pages']['columns'][$fieldName]['config'] ?? [];
        $type = $configuration['type'] ?? '';
        if (in_array($type, ['inline', 'file', 'category', 'flex'], true) || !empty($configuration['MM'])) {
            return false;
        }
        $relatedTables = [];
        if ($type === 'group') {
            $relatedTables = array_map('trim', explode(',', (string)($configuration['allowed'] ?? '')));
        } elseif ($type === 'select' && !empty($configuration['foreign_table'])) {
            $relatedTables = [(string)$configuration['foreign_table']];
        }
        foreach ($relatedTables as $relatedTable) {
            if ($relatedTable === '*' || !empty($GLOBALS['TCA'][$relatedTable]['ctrl']['languageField'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Lists live, not deleted page translations whose l10n_mode=exclude fields differ from their default
     * language page.
     *
     * @return list<array{uid: int, language: int, parent: int, fields: array<string, array{current: mixed, expected: mixed}>}>
     */
    public function findDeviations(): array
    {
        $fieldNames = $this->getFieldNames();
        if ($fieldNames === []) {
            return [];
        }
        $languageField = $GLOBALS['TCA']['pages']['ctrl']['languageField'] ?? 'sys_language_uid';
        $parentField = $GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField'] ?? 'l10n_parent';
        $deleteField = $GLOBALS['TCA']['pages']['ctrl']['delete'] ?? null;

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $select = ['t.uid AS uid', 't.' . $languageField . ' AS language', 'p.uid AS parent'];
        foreach ($fieldNames as $index => $fieldName) {
            $select[] = 't.' . $fieldName . ' AS current_' . $index;
            $select[] = 'p.' . $fieldName . ' AS expected_' . $index;
        }
        $constraints = [
            $queryBuilder->expr()->gt('t.' . $languageField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            $queryBuilder->expr()->eq('p.' . $languageField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            $queryBuilder->expr()->eq('t.t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            $queryBuilder->expr()->eq('p.t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
        ];
        if ($deleteField !== null) {
            $constraints[] = $queryBuilder->expr()->eq('t.' . $deleteField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
            $constraints[] = $queryBuilder->expr()->eq('p.' . $deleteField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
        }
        $rows = $queryBuilder
            ->select(...$select)
            ->from('pages', 't')
            ->join('t', 'pages', 'p', $queryBuilder->expr()->eq('p.uid', $queryBuilder->quoteIdentifier('t.' . $parentField)))
            ->where(...$constraints)
            ->orderBy('p.uid')
            ->addOrderBy('t.uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $deviations = [];
        foreach ($rows as $row) {
            $fields = [];
            foreach ($fieldNames as $index => $fieldName) {
                $current = $row['current_' . $index];
                $expected = $row['expected_' . $index];
                if ((string)$current !== (string)$expected) {
                    $fields[$fieldName] = ['current' => $current, 'expected' => $expected];
                }
            }
            if ($fields !== []) {
                $deviations[] = [
                    'uid' => (int)$row['uid'],
                    'language' => (int)$row['language'],
                    'parent' => (int)$row['parent'],
                    'fields' => $fields,
                ];
            }
        }
        return $deviations;
    }

    /**
     * Writes the values of the default language page into the translations and flushes the page cache of the
     * affected pages. Fields that cannot be copied (see canBeCopied()) are left as they are.
     *
     * @param list<array{uid: int, language: int, parent: int, fields: array<string, array{current: mixed, expected: mixed}>}> $deviations
     * @return int Number of translations changed
     */
    public function synchronize(array $deviations): int
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $changed = 0;
        $cacheTags = [];
        foreach ($deviations as $deviation) {
            $values = [];
            foreach ($deviation['fields'] as $fieldName => $field) {
                if ($this->canBeCopied($fieldName)) {
                    $values[$fieldName] = $field['expected'];
                }
            }
            if ($values === []) {
                continue;
            }
            $changed += $connection->update('pages', $values, ['uid' => $deviation['uid']]) > 0 ? 1 : 0;
            $cacheTags[] = 'pageId_' . $deviation['parent'];
            $cacheTags[] = 'pageId_' . $deviation['uid'];
        }
        if ($cacheTags !== []) {
            $this->cacheManager->flushCachesInGroupByTags('pages', array_values(array_unique($cacheTags)));
        }
        return $changed;
    }
}
