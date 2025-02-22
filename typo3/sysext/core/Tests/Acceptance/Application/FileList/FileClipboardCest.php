<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Core\Tests\Acceptance\Application\FileList;

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\RemoteWebElement;
use TYPO3\CMS\Core\Tests\Acceptance\Support\ApplicationTester;
use TYPO3\CMS\Core\Tests\Acceptance\Support\Helper\FileTree;

/**
 * Cases concerning sys_file_metadata records
 */
final class FileClipboardCest
{
    private string $copyModeCopy = '#clipboard-copymode-copy';
    private string $copyModeMove = '#clipboard-copymode-move';

    public function _before(ApplicationTester $I, FileTree $tree): void
    {
        $I->useExistingSession('admin');
        $I->amOnPage('/typo3/module/file/list');
        $I->switchToContentFrame();
    }

    public function seeSwitchModes(ApplicationTester $I): void
    {
        $I->seeCheckboxIsChecked($this->copyModeMove);
        $I->dontSeeCheckboxIsChecked($this->copyModeCopy);
        $I->click('//*/label[@for="clipboard-copymode-copy"]');
        $I->waitForElement($this->copyModeMove, 2);
        $I->dontSeeCheckboxIsChecked($this->copyModeMove);
        $I->seeCheckboxIsChecked($this->copyModeCopy);
    }

    public function seeAddRemoveSingleRecord(ApplicationTester $I): void
    {
        $fileName = 'bus_lane.jpg';
        $I->switchToMainFrame();
        $I->click('//*[text()="styleguide"]');
        $I->click('.scaffold-content-navigation-switcher-close');
        $I->switchToContentFrame();
        $I->click('//*[text()="View"]');
        $I->click('//*[text()="List"]');

        $this->openActionDropdown($I, $fileName)->click();
        $I->click('Cut');
        $I->see($fileName, '.clipboard-panel a');
        $I->click('Remove item', '.clipboard-panel');
        $I->dontSee($fileName, '.clipboard-panel a');
    }

    public function seeAddRemoveMultipleRecords(ApplicationTester $I): void
    {
        $expectedFiles = ['bus_lane.jpg', 'telephone_box.jpg', 'underground.jpg'];

        $I->switchToMainFrame();
        $I->click('//*[text()="styleguide"]');
        $I->click('.scaffold-content-navigation-switcher-close');
        $I->switchToContentFrame();
        $I->click('//*[text()="View"]');
        $I->click('//*[text()="List"]');

        $I->amGoingTo('add multiple elements to clipboard');
        $I->click('Clipboard #1 (multi-selection mode)');
        $I->click('.t3js-multi-record-selection-check-actions-toggle');
        $I->click('button[data-multi-record-selection-check-action="check-all"]');
        $I->click('button[data-multi-record-selection-action="copyMarked"]');

        foreach ($expectedFiles as $file) {
            $I->see($file, '.clipboard-panel');
        }

        $I->amGoingTo('remove all elements from clipboard');
        $I->click('Remove all', '.clipboard-panel');

        foreach ($expectedFiles as $file) {
            $I->dontSee($file, '.clipboard-panel');
        }
    }

    private function openActionDropdown(ApplicationTester $I, string $title): RemoteWebElement
    {
        $I->comment('Get open action dropdown "' . $title . '"');
        return $I->executeInSelenium(
            static function (RemoteWebDriver $webDriver) use ($title) {
                return $webDriver->findElement(
                    \Facebook\WebDriver\WebDriverBy::xpath(
                        '//a[contains(text(),"' . $title . '")]/parent::node()/parent::node()//a[contains(@href, "#actions_") and contains(@data-bs-toggle, "dropdown")]'
                    )
                );
            }
        );
    }
}
