.. include:: /Includes.rst.txt

.. _feature-98394-1674070213:

==========================================================================
Feature: #98394 - Introduce event to prevent downloading of language packs
==========================================================================

See :issue:`98394`

Description
===========

..  code-block:: yaml
    :caption: Configuration/Services.yaml

    services:
      KA\Myext\EventListener\ModifyLanguagePacks:
        tags:
          - name: event.listener
            identifier: 'modifyLanguagePacks'
            event: TYPO3\CMS\Install\Service\Event\ModifyLanguagePacksEvent
            method: 'modifyLanguagePacks'


..  code-block:: php
    :caption: Classes/EventListener/ModifyLanguagePacks.php

    <?php
    namespace KA\Myext\EventListener;

    use TYPO3\CMS\Install\Service\Event\ModifyLanguagePacksEvent;

    class ModifyLanguagePacks
    {
        public function modifyLanguagePacks(ModifyLanguagePacksEvent $event): void
        {
            $extensions = $event->getExtensions();
            foreach ($extensions as $key => $extension){
                if($extension['type'] === 'typo3-cms-framework'){
                    $event->removeExtension($key);
                }
            }
            $event->removeIsoFromExtension('de', 'styleguide');
        }
    }

Impact
======

With the newly introduced event, it is possible to ignore extensions or
individual language packs for extensions when downloading the language packs.
It is still the case that only language packs for extensions and languages
available in the system can be downloaded. The options of the language:update
command can be used to further restrict the download (ignore additional
extensions or download only specific languages), but not to ignore decisions
made by the event.

.. index:: ext:install
