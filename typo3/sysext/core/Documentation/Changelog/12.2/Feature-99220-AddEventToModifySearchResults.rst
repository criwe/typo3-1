.. include:: /Includes.rst.txt

.. _feature-99220-1670250156:

====================================================
Feature: #99220 - Add event to modify search results
====================================================

See :issue:`99220`

Description
===========

A new event :php:`\TYPO3\CMS\Backend\Search\Event\ModifyResultItemInLiveSearchEvent`
is added to allow extension developers to take control over search result items
rendered in the backend search.

The event has a public method called :php:`getResultItem()`, returning the
:php:`\TYPO3\CMS\Backend\Search\LiveSearch\ResultItem` instance of the search
result item.

Impact
======

Search result items may be modified within a custom event listener, e.g. to add
custom actions.

Example
-------

..  warning::
    Some code in this example is experimental API and may change until TYPO3 v12 LTS.

..  code-block:: php
    :caption: Classes/Search/EventListener/AddLiveSearchResultActionsListener.php

    namespace MyVendor\MyExtension\Search\EventListener;

    use TYPO3\CMS\Backend\Search\Event\ModifyResultItemInLiveSearchEvent;
    use TYPO3\CMS\Backend\Search\LiveSearch\DatabaseRecordProvider;
    use TYPO3\CMS\Backend\Search\LiveSearch\ResultItemAction;
    use TYPO3\CMS\Core\Imaging\IconFactory;

    final class AddLiveSearchResultActionsListener
    {
        protected LanguageService $languageService;

        public function __construct(
            protected readonly IconFactory $iconFactory,
            protected readonly LanguageServiceFactory $languageServiceFactory,
            protected readonly UriBuilder $uriBuilder
        ) {
            $this->languageService = $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER']);
        }

        public function __invoke(ModifyResultItemInLiveSearchEvent $event): void
        {
            $resultItem = $event->getResultItem();
            if ($resultItem->getProviderClassName() !== DatabaseRecordProvider::class) {
                return;
            }

            if (($resultItem->getExtraData()['table'] ?? null) === 'tt_content') {
                /**
                 * WARNING: THIS EXAMPLE OMITS ANY ACCESS CHECK FOR SIMPLICITY REASONS - DO NOT USE AS-IS
                 */
                $showHistoryAction = (new ResultItemAction('view_history'))
                    ->setLabel($this->languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_mod_web_list.xlf:history'))
                    ->setIcon($this->iconFactory->getIcon('actions-document-history-open', Icon::SIZE_SMALL))
                    ->setUrl((string)$this->uriBuilder->buildUriFromRoute('record_history', [
                        'element' => $resultItem->getExtraData()['table'] . ':' . $resultItem->getExtraData()['uid']
                    ]));
                $resultItem->addAction($showHistoryAction);
            }
        }
    }

..  code-block:: yaml
    :caption: Configuration/Services.yaml

    MyVendor\MyExtension\Search\EventListener\AddLiveSearchResultActionsListener:
    tags:
      -
        name: event.listener
        identifier: 'my-vendor/my-extension/add-live-search-result-actions-listener'

.. index:: Backend, ext:backend
