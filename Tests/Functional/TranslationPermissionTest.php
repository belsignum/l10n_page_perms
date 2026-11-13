<?php

declare(strict_types=1);

namespace Belsignum\L10nPagePerms\Tests\Functional;

use Belsignum\L10nPagePerms\Command\RepairCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Scenario (fixture Scenario.csv): page 2 belongs to group 2, its translations 3 (AT) and 4 (EN) as well and still
 * have the old backend layout. The editor is a member of group 4 only.
 */
final class TranslationPermissionTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['beuser'];

    protected array $testExtensionsToLoad = ['belsignum/l10n-page-perms'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Scenario.csv');
    }

    #[Test]
    public function changingTheGroupOfADefaultLanguagePageChangesItsTranslations(): void
    {
        $this->actAs(1);
        $this->runDataHandler(['pages' => [2 => ['perms_groupid' => 4]]]);

        self::assertSame(4, $this->getPage(3)['perms_groupid']);
        self::assertSame(4, $this->getPage(4)['perms_groupid']);
    }

    #[Test]
    public function changingSinglePermissionBitsOfADefaultLanguagePageChangesItsTranslations(): void
    {
        $this->actAs(1);
        $this->runDataHandler(['pages' => [2 => ['perms_everybody' => 1, 'perms_userid' => 2]]]);

        foreach ([3, 4] as $uid) {
            self::assertSame(1, $this->getPage($uid)['perms_everybody']);
            self::assertSame(2, $this->getPage($uid)['perms_userid']);
        }
    }

    /**
     * The core passes access rights on only as a side effect: saving a default language page pulls its
     * translations into the same save, and a translation takes over the access rights of its default language
     * page whenever it is saved. That needs edit access to the translation, which is checked against the
     * translation's own, outdated access rights. Page 7 belongs to the editor, its translation 8 fell behind
     * and belongs to the admin.
     */
    #[Test]
    public function ownerChangingTheGroupReachesTranslationsTheOwnerCannotEdit(): void
    {
        $this->actAs(2);
        $this->runDataHandler(['pages' => [7 => ['perms_groupid' => 4]]]);

        self::assertSame(4, $this->getPage(7)['perms_groupid']);
        self::assertSame(4, $this->getPage(8)['perms_groupid']);
        self::assertSame(2, $this->getPage(8)['perms_userid']);
    }

    #[Test]
    public function editorCanChangeTheBackendLayoutOfAllLanguagesAfterTheGroupChange(): void
    {
        $this->actAs(1);
        $this->runDataHandler(['pages' => [2 => ['perms_groupid' => 4]]]);

        $this->actAs(2);
        $errors = $this->runDataHandler(['pages' => [2 => ['backend_layout' => 'pagets__newer']]]);

        self::assertSame([], $errors);
        self::assertSame('pagets__newer', $this->getPage(2)['backend_layout']);
        self::assertSame('pagets__newer', $this->getPage(3)['backend_layout']);
        self::assertSame('pagets__newer', $this->getPage(4)['backend_layout']);
    }

    #[Test]
    public function dryRunChangesNothing(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['perms_groupid' => 4], ['uid' => 2]);

        $tester = new CommandTester($this->get(RepairCommand::class));
        $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('3 page translations differ', $tester->getDisplay());
        self::assertStringContainsString('2 live page translations differ', $tester->getDisplay());
        self::assertSame(2, $this->getPage(3)['perms_groupid']);
        self::assertSame('pagets__old', $this->getPage(3)['backend_layout']);
    }

    #[Test]
    public function repairCommandAlignsAccessRightsAndExcludedFields(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['perms_groupid' => 4], ['uid' => 2]);

        $tester = new CommandTester($this->get(RepairCommand::class));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        foreach ([3, 4] as $uid) {
            self::assertSame(4, $this->getPage($uid)['perms_groupid']);
            self::assertSame('pagets__new', $this->getPage($uid)['backend_layout']);
        }
        // Translated values of fields with allowLanguageSynchronization stay as they are.
        self::assertSame(1, (int)$this->getPage(3)['nav_hide']);
        self::assertSame('Other AT', $this->getPage(6)['title']);
    }

    #[Test]
    public function repairCommandCanSkipTheExcludedFields(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['perms_groupid' => 4], ['uid' => 2]);

        $tester = new CommandTester($this->get(RepairCommand::class));
        $tester->execute(['--skip-excluded-fields' => true]);

        self::assertSame(4, $this->getPage(3)['perms_groupid']);
        self::assertSame('pagets__old', $this->getPage(3)['backend_layout']);
    }

    private function actAs(int $backendUserUid): void
    {
        $this->setUpBackendUser($backendUserUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    /**
     * @return list<string>
     */
    private function runDataHandler(array $dataMap): array
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($dataMap, []);
        $dataHandler->process_datamap();
        return array_values($dataHandler->errorLog);
    }

    /**
     * @return array<string, mixed>
     */
    private function getPage(int $uid): array
    {
        $row = $this->getConnectionPool()->getConnectionForTable('pages')
            ->select(['*'], 'pages', ['uid' => $uid])
            ->fetchAssociative();
        self::assertIsArray($row);
        $row['perms_userid'] = (int)$row['perms_userid'];
        $row['perms_groupid'] = (int)$row['perms_groupid'];
        $row['perms_everybody'] = (int)$row['perms_everybody'];
        return $row;
    }
}
