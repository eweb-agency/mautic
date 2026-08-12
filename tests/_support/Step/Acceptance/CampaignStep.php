<?php

namespace Step\Acceptance;

use Page\Acceptance\CampaignPage;
use Page\Acceptance\ContactPage;

class CampaignStep extends \AcceptanceTester
{
    private const MODAL_SELECTOR = '#MauticSharedModal';

    public function addContactsToCampaign()
    {
        $I = $this;
        $I->waitForElementVisible(ContactPage::$campaignsModalAddOption, 5); // Wait for the modal to appear
        $I->click(ContactPage::$campaignsModalAddOption); // Click into "Add to the following" option
        $I->click(ContactPage::$firstCampaignFromAddList); // Select the first campaign from the list
        $I->click(ContactPage::$campaignsModalSaveButton); // Click Save
        $I->waitForElementNotVisible(self::MODAL_SELECTOR, 30); // Wait for modal to close
        $I->ensureNotificationAppears('contacts affected');
    }

    /**
     * Opens the Contacts tab on the campaign detail page and waits for its
     * ajax-loaded content. The click can be swallowed when the async stats
     * chart shifts the layout at the wrong moment, so retry it once.
     */
    public function openContactsTab(): void
    {
        $I = $this;
        $I->waitForElementVisible(CampaignPage::$contactsTab, 30);
        $I->click(CampaignPage::$contactsTab);

        try {
            $I->waitForElementVisible(CampaignPage::$leadsContainer, 10);
        } catch (\Exception) {
            $I->click(CampaignPage::$contactsTab);
            $I->waitForElementVisible(CampaignPage::$leadsContainer, 10);
        }

        // The pane content is fetched from data-target-url; wait until the spinner is replaced
        $I->waitForElementNotVisible(CampaignPage::$leadsContainerSpinner, 30);
    }
}
