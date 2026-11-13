<?php

declare(strict_types=1);

namespace Belsignum\L10nPagePerms\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Keeps the access rights (perms_*) of page translations equal to those of their default language page.
 *
 * Since TYPO3 v9 a page translation is a row of its own in "pages", with its own perms_* fields, and the
 * DataHandler checks edit access on a translation against these fields. The Access module only shows and
 * changes default language pages. The core passes the change on only as a side effect: saving a default
 * language page pulls its translations into the same save (l10n_mode=exclude sync), and a translation takes
 * over the access rights of its default language page whenever it is saved. The translations stay behind if
 * the user may not edit them (checked against their own, outdated access rights) or if the request ends
 * before the DataHandler gets to them, for example a recursive change of many pages running into a time limit.
 */
final class PermissionSynchronizer
{
    public const FIELDS = ['perms_userid', 'perms_groupid', 'perms_user', 'perms_group', 'perms_everybody'];

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /**
     * Copies the access rights of a default language page to all its translations, deleted ones and workspace
     * versions included. Does nothing for a translation or a page that does not exist.
     *
     * @return int Number of translation rows changed
     */
    public function synchronize(int $pageUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $page = $queryBuilder
            ->select($this->languageField(), ...self::FIELDS)
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        if ($page === false || (int)$page[$this->languageField()] !== 0) {
            return 0;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $differs = [];
        foreach (self::FIELDS as $field) {
            $value = (int)$page[$field];
            $queryBuilder->set($field, $value);
            $differs[] = $queryBuilder->expr()->neq($field, $queryBuilder->createNamedParameter($value, Connection::PARAM_INT));
        }

        return (int)$queryBuilder
            ->update('pages')
            ->where(
                $queryBuilder->expr()->eq($this->parentField(), $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt($this->languageField(), $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->or(...$differs)
            )
            ->executeStatement();
    }

    /**
     * Lists all page translations whose access rights differ from their default language page, deleted ones and
     * workspace versions included.
     *
     * @return list<array{uid: int, language: int, parent: int, current: array<string, int>, expected: array<string, int>}>
     */
    public function findDeviations(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $select = ['t.uid AS uid', 't.' . $this->languageField() . ' AS language', 'p.uid AS parent'];
        foreach (self::FIELDS as $field) {
            $select[] = 't.' . $field . ' AS current_' . $field;
            $select[] = 'p.' . $field . ' AS expected_' . $field;
        }
        $rows = $queryBuilder
            ->select(...$select)
            ->from('pages', 't')
            ->join('t', 'pages', 'p', $queryBuilder->expr()->eq('p.uid', $queryBuilder->quoteIdentifier('t.' . $this->parentField())))
            ->where(
                $queryBuilder->expr()->gt('t.' . $this->languageField(), $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('p.' . $this->languageField(), $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('p.uid')
            ->addOrderBy('t.uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $deviations = [];
        foreach ($rows as $row) {
            $current = $expected = [];
            foreach (self::FIELDS as $field) {
                $current[$field] = (int)$row['current_' . $field];
                $expected[$field] = (int)$row['expected_' . $field];
            }
            if ($current !== $expected) {
                $deviations[] = [
                    'uid' => (int)$row['uid'],
                    'language' => (int)$row['language'],
                    'parent' => (int)$row['parent'],
                    'current' => $current,
                    'expected' => $expected,
                ];
            }
        }
        return $deviations;
    }

    private function languageField(): string
    {
        return $GLOBALS['TCA']['pages']['ctrl']['languageField'] ?? 'sys_language_uid';
    }

    private function parentField(): string
    {
        return $GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField'] ?? 'l10n_parent';
    }
}
