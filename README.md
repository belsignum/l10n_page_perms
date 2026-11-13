# Access rights of page translations (l10n_page_perms)

TYPO3 extension for TYPO3 12.4, 13.4 and 14.3. It keeps the access rights of page translations equal to their
default language page, and it repairs translations that fell behind.

## The problem

Since TYPO3 v9 a page translation is a row of its own in `pages`, with its own `perms_userid`,
`perms_groupid`, `perms_user`, `perms_group` and `perms_everybody`. The DataHandler checks edit access to a
translation against these fields, not against the default language page.

The Access module only lists and changes default language pages. The core passes a change on to the
translations only as a side effect:

- Saving a default language page pulls its translations into the same save, to sync the fields with
  `l10n_mode=exclude`.
- A translation takes over the access rights of its default language page whenever it is saved.

This fails in two cases, and nothing repairs the result:

- **The user may not edit the translation.** This is checked against the translation's own, outdated access
  rights. An example is an owner who moves a page to another group while the translations belong to someone
  else.
- **The request ends before the DataHandler gets to the translations.** It writes all default language pages
  first and the translations last. A recursive change of many pages in the Access module can run into a time
  limit in between. The default language pages then have the new access rights and the translations keep the
  old ones.

From then on editors in the new group cannot save the translations. Changes to fields such as
`backend_layout` reach only the default language page, and the frontend shows the translations with the old
value. The system log notes it as "Attempt to modify record … without permission or non-existing page".

TYPO3 core issue [#89838](https://forge.typo3.org/issues/89838) describes the symptom. It was closed in 2020
with a change that has the Access module save through the DataHandler, which covers neither case.

## What the extension does

- **DataHandler hook:** When the access rights of a default language page change, the hook writes them into all
  its translations, deleted ones and workspace versions included. It runs right after the default language page
  is written, does not depend on edit access to the translations and does not wait for the end of a long save.
  It covers every path: the Access module (form, recursive, AJAX for owner, group and single permission bits)
  and any other DataHandler call.
- **Repair command** for existing data:

  ```bash
  vendor/bin/typo3 l10n-page-perms:repair --dry-run
  vendor/bin/typo3 l10n-page-perms:repair
  ```

  - Step 1 gives every page translation the access rights of its default language page.
  - Step 2 copies the `l10n_mode=exclude` fields (such as `backend_layout`) of the default language page into
    live translations that differ, and flushes their page cache. Relations kept in other tables, and relations
    to records that have translations themselves, are only reported. A save of the translation in the backend
    resolves those.
  - `--skip-excluded-fields` runs step 1 only.

  Step 2 writes the values directly and does not save the translations through the DataHandler. A DataHandler
  save of a translation also takes over every field with `allowLanguageSynchronization` whose `l10n_state` is
  not set, and could overwrite translated values that way.

## Installation

```bash
composer require belsignum/l10n-page-perms
```

Then run the repair command once with `--dry-run`, check the list, and run it without.

## Limitations

- In a workspace, a change of access rights goes into a workspace version of the page and reaches the live page
  only when it is published. The hook leaves the translations alone in that case. Run the repair command after
  publishing.
- Changes written directly to the database, bypassing the DataHandler, are only caught by the repair command.

## Tests

Functional tests run in GitHub Actions against TYPO3 12.4, 13.4 and 14.3 with SQLite. To run them locally:

```bash
composer require --no-update "typo3/cms-core:^13.4"
composer require --dev --no-update "typo3/cms-beuser:^13.4" "typo3/testing-framework:^8.2"
composer update
typo3DatabaseDriver=pdo_sqlite .Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml
```

## License

GPL-2.0-or-later
