<?php

declare(strict_types=1);

namespace Belsignum\L10nPagePerms\Hook;

use Belsignum\L10nPagePerms\Service\PermissionSynchronizer;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Passes a change of the access rights of a default language page on to its translations right after the
 * default language page is written, whichever way the change comes in: the Access module (form, recursive,
 * AJAX for owner, group and single bits) or any other DataHandler call. Unlike the side effect in the core,
 * this does not depend on edit access to the translations and does not wait until the end of a long save.
 */
final class DataHandlerHook
{
    public function __construct(private readonly PermissionSynchronizer $permissionSynchronizer) {}

    /**
     * @param string|int $id
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, $id, array $fieldArray, DataHandler $dataHandler): void
    {
        if ($table !== 'pages' || $status !== 'update' || !MathUtility::canBeInterpretedAsInteger($id)) {
            return;
        }
        if (array_intersect_key($fieldArray, array_flip(PermissionSynchronizer::FIELDS)) === []) {
            return;
        }
        // In a workspace the change went into a workspace version of the page and reaches the live page only when
        // it is published. The translations follow with the next change in live or with l10n-page-perms:repair.
        if ((int)($dataHandler->BE_USER->workspace ?? 0) !== 0) {
            return;
        }
        // Translations are left alone: the DataHandler itself gives them the access rights of their default
        // language page whenever they are saved.
        $this->permissionSynchronizer->synchronize((int)$id);
    }
}
