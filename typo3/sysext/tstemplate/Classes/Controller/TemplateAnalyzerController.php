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

namespace TYPO3\CMS\Tstemplate\Controller;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\TypoScript\IncludeTree\IncludeNode\IncludeInterface;
use TYPO3\CMS\Core\TypoScript\IncludeTree\IncludeNode\RootInclude;
use TYPO3\CMS\Core\TypoScript\IncludeTree\SysTemplateRepository;
use TYPO3\CMS\Core\TypoScript\IncludeTree\SysTemplateTreeBuilder;
use TYPO3\CMS\Core\TypoScript\IncludeTree\Traverser\ConditionVerdictAwareIncludeTreeTraverser;
use TYPO3\CMS\Core\TypoScript\IncludeTree\Traverser\IncludeTreeTraverser;
use TYPO3\CMS\Core\TypoScript\IncludeTree\Visitor\IncludeTreeAstBuilderVisitor;
use TYPO3\CMS\Core\TypoScript\IncludeTree\Visitor\IncludeTreeSetupConditionConstantSubstitutionVisitor;
use TYPO3\CMS\Core\TypoScript\Tokenizer\Line\LineStream;
use TYPO3\CMS\Core\TypoScript\Tokenizer\LosslessTokenizer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Tstemplate\TypoScript\IncludeTree\Visitor\IncludeTreeConditionAggregatorVisitor;
use TYPO3\CMS\Tstemplate\TypoScript\IncludeTree\Visitor\IncludeTreeConditionEnforcerVisitor;
use TYPO3\CMS\Tstemplate\TypoScript\IncludeTree\Visitor\IncludeTreeNodeFinderVisitor;
use TYPO3\CMS\Tstemplate\TypoScript\IncludeTree\Visitor\IncludeTreeSourceAggregatorVisitor;

/**
 * TypoScript template analyzer.
 * Show TypoScript constants and setup include tree of current page.
 *
 * @internal This is a specific Backend Controller implementation and is not considered part of the Public TYPO3 API.
 */
final class TemplateAnalyzerController extends AbstractTemplateModuleController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly SysTemplateRepository $sysTemplateRepository,
        private readonly IncludeTreeTraverser $treeTraverser,
        private readonly ConditionVerdictAwareIncludeTreeTraverser $treeTraverserConditionVerdictAware,
        private readonly SysTemplateTreeBuilder $treeBuilder,
        private readonly LosslessTokenizer $losslessTokenizer,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        $languageService = $this->getLanguageService();

        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();

        $currentModule = $request->getAttribute('module');
        $currentModuleIdentifier = $currentModule->getIdentifier();
        $moduleData = $request->getAttribute('moduleData');

        $pageUid = (int)($queryParams['id'] ?? 0);
        if ($pageUid === 0) {
            // Redirect to template record overview if on page 0.
            return new RedirectResponse($this->uriBuilder->buildUriFromRoute('web_typoscript_recordsoverview'));
        }
        $pageRecord = BackendUtility::readPageAccess($pageUid, '1=1') ?: [];

        // Template selection handling for this page
        $allTemplatesOnPage = $this->getAllTemplateRecordsOnPage($pageUid);
        $selectedTemplateFromModuleData = (array)$moduleData->get('selectedTemplatePerPage');
        $selectedTemplateUid = (int)($parsedBody['selectedTemplate'] ?? $selectedTemplateFromModuleData[$pageUid] ?? 0);
        if (!in_array($selectedTemplateUid, array_column($allTemplatesOnPage, 'uid'))) {
            $selectedTemplateUid = (int)($allTemplatesOnPage[0]['uid'] ?? 0);
        }
        if (($moduleData->get('selectedTemplatePerPage')[$pageUid] ?? 0) !== $selectedTemplateUid) {
            $selectedTemplateFromModuleData[$pageUid] = $selectedTemplateUid;
            $moduleData->set('selectedTemplatePerPage', $selectedTemplateFromModuleData);
            $backendUser->pushModuleData($currentModuleIdentifier, $moduleData->toArray());
        }
        $templateTitle = '';
        foreach ($allTemplatesOnPage as $templateRow) {
            if ((int)$templateRow['uid'] === $selectedTemplateUid) {
                $templateTitle = $templateRow['title'];
            }
        }

        $rootLine = GeneralUtility::makeInstance(RootlineUtility::class, $pageUid)->get();

        $sysTemplateRows = $this->sysTemplateRepository->getSysTemplateRowsByRootlineWithUidOverride($rootLine, $request, $selectedTemplateUid);

        // Build the constant include tree
        /** @var SiteInterface|null $site */
        $site = $request->getAttribute('site');
        $constantIncludeTree = $this->treeBuilder->getTreeBySysTemplateRowsAndSite('constants', $sysTemplateRows, $this->losslessTokenizer, $site);
        // Set enabled conditions in constant include tree
        $constantConditions = $this->handleToggledConstantConditions($constantIncludeTree, $moduleData, $parsedBody);
        $conditionEnforcerVisitor = GeneralUtility::makeInstance(IncludeTreeConditionEnforcerVisitor::class);
        $conditionEnforcerVisitor->setEnabledConditions(array_column(array_filter($constantConditions, static fn ($condition) => $condition['active']), 'value'));
        $this->treeTraverser->resetVisitors();
        $this->treeTraverser->addVisitor($conditionEnforcerVisitor);
        $this->treeTraverser->traverse($constantIncludeTree);
        // Build the constant AST and flatten it. Needed for setup include tree to substitute constants in setup conditions.
        $constantAstBuilderVisitor = GeneralUtility::makeInstance(IncludeTreeAstBuilderVisitor::class);
        $this->treeTraverserConditionVerdictAware->resetVisitors();
        $this->treeTraverserConditionVerdictAware->addVisitor($constantAstBuilderVisitor);
        $this->treeTraverserConditionVerdictAware->traverse($constantIncludeTree);
        $constantAst = $constantAstBuilderVisitor->getAst();
        $flattenedConstants = $constantAst->flatten();

        // Build the setup include tree
        $setupIncludeTree = $this->treeBuilder->getTreeBySysTemplateRowsAndSite('setup', $sysTemplateRows, $this->losslessTokenizer, $site);
        // Set enabled conditions in setup include tree and let it handle constant substitutions in setup conditions.
        $setupConditions = $this->handleToggledSetupConditions($setupIncludeTree, $moduleData, $parsedBody, $flattenedConstants);
        $conditionEnforcerVisitor = GeneralUtility::makeInstance(IncludeTreeConditionEnforcerVisitor::class);
        $conditionEnforcerVisitor->setEnabledConditions(array_column(array_filter($setupConditions, static fn ($condition) => $condition['active']), 'value'));
        $this->treeTraverser->resetVisitors();
        $this->treeTraverser->addVisitor($conditionEnforcerVisitor);
        $this->treeTraverser->traverse($setupIncludeTree);

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($languageService->sL($currentModule->getTitle()), $pageRecord['title']);
        $view->getDocHeaderComponent()->setMetaInformation($pageRecord);
        $this->addPreviewButtonToDocHeader($view, $pageUid, (int)$pageRecord['doktype']);
        $this->addShortcutButtonToDocHeader($view, $currentModuleIdentifier, $pageRecord, $pageUid);
        $view->makeDocHeaderModuleMenu(['id' => $pageUid]);
        $view->assignMultiple([
            'pageUid' => $pageUid,
            'allTemplatesOnPage' => $allTemplatesOnPage,
            'selectedTemplateUid' => $selectedTemplateUid,
            'templateTitle' => $templateTitle,
            'constantConditions' => $constantConditions,
            'constantConditionsActiveCount' => count(array_filter($constantConditions, static fn ($condition) => $condition['active'])),
            'constantIncludeTree' => $constantIncludeTree,
            'setupConditions' => $setupConditions,
            'setupConditionsActiveCount' => count(array_filter($setupConditions, static fn ($condition) => $condition['active'])),
            'setupIncludeTree' => $setupIncludeTree,
        ]);

        return $view->renderResponse('Analyzer');
    }

    public function sourceAction(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $pageUid = (int)($queryParams['id'] ?? 0);
        $type = $queryParams['includeType'] ?? null;
        $includeIdentifier = $queryParams['identifier'] ?? null;
        $moduleData = $request->getAttribute('moduleData');
        $allTemplatesOnPage = $this->getAllTemplateRecordsOnPage($pageUid);
        $selectedTemplateUid = (int)($moduleData->get('selectedTemplatePerPage')[$pageUid] ?? 0);
        if (!in_array($selectedTemplateUid, array_column($allTemplatesOnPage, 'uid'))) {
            $selectedTemplateUid = (int)($allTemplatesOnPage[0]['uid'] ?? 0);
        }
        if ($pageUid === 0 || $includeIdentifier === null || !in_array($type, ['constants', 'setup'], true)) {
            return $this->responseFactory->createResponse(400);
        }
        $rootLine = GeneralUtility::makeInstance(RootlineUtility::class, $pageUid)->get();
        $sysTemplateRows = $this->sysTemplateRepository->getSysTemplateRowsByRootlineWithUidOverride($rootLine, $request, $selectedTemplateUid);
        /** @var SiteInterface|null $site */
        $site = $request->getAttribute('site');
        $includeTree = $this->treeBuilder->getTreeBySysTemplateRowsAndSite($type, $sysTemplateRows, $this->losslessTokenizer, $site);

        $nodeFinderVisitor = GeneralUtility::makeInstance(IncludeTreeNodeFinderVisitor::class);
        $nodeFinderVisitor->setNodeIdentifier($includeIdentifier);
        $this->treeTraverser->resetVisitors();
        $this->treeTraverser->addVisitor($nodeFinderVisitor);
        $this->treeTraverser->traverse($includeTree);
        $foundNode = $nodeFinderVisitor->getFoundNode();

        if (!$foundNode instanceof IncludeInterface) {
            return $this->responseFactory->createResponse(400);
        }
        $lineStream = $foundNode->getLineStream();
        if (!$lineStream instanceof LineStream) {
            return $this->responseFactory->createResponse(400);
        }

        return $this->responseFactory
            ->createResponse()
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->streamFactory->createStream((string)$foundNode->getLineStream()));
    }

    public function sourceWithIncludesAction(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $pageUid = (int)($queryParams['id'] ?? 0);
        $type = $queryParams['includeType'] ?? null;
        $includeIdentifier = $queryParams['identifier'] ?? null;
        $moduleData = $request->getAttribute('moduleData');
        $allTemplatesOnPage = $this->getAllTemplateRecordsOnPage($pageUid);
        $selectedTemplateUid = (int)($moduleData->get('selectedTemplatePerPage')[$pageUid] ?? 0);
        if (!in_array($selectedTemplateUid, array_column($allTemplatesOnPage, 'uid'))) {
            $selectedTemplateUid = (int)($allTemplatesOnPage[0]['uid'] ?? 0);
        }
        if ($pageUid === 0 || $includeIdentifier === null || !in_array($type, ['constants', 'setup'], true)) {
            return $this->responseFactory->createResponse(400);
        }
        $rootLine = GeneralUtility::makeInstance(RootlineUtility::class, $pageUid)->get();
        $sysTemplateRows = $this->sysTemplateRepository->getSysTemplateRowsByRootlineWithUidOverride($rootLine, $request, $selectedTemplateUid);
        /** @var SiteInterface|null $site */
        $site = $request->getAttribute('site');
        $includeTree = $this->treeBuilder->getTreeBySysTemplateRowsAndSite($type, $sysTemplateRows, $this->losslessTokenizer, $site);

        $sourceAggregatorVisitor = GeneralUtility::makeInstance(IncludeTreeSourceAggregatorVisitor::class);
        $sourceAggregatorVisitor->setStartNodeIdentifier($includeIdentifier);
        $this->treeTraverser->resetVisitors();
        $this->treeTraverser->addVisitor($sourceAggregatorVisitor);
        $this->treeTraverser->traverse($includeTree);
        $source =  $sourceAggregatorVisitor->getSource();

        return $this->responseFactory
            ->createResponse()
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->streamFactory->createStream($source));
    }

    /**
     * Align module data active constant conditions with toggled conditions from POST,
     * write updated active conditions to user's module data if needed and
     * prepare a list of active conditions for view.
     */
    private function handleToggledConstantConditions(RootInclude $constantTree, ModuleData $moduleData, ?array $parsedBody): array
    {
        $conditionAggregatorVisitor = GeneralUtility::makeInstance(IncludeTreeConditionAggregatorVisitor::class);
        $this->treeTraverser->resetVisitors();
        $this->treeTraverser->addVisitor($conditionAggregatorVisitor);
        $this->treeTraverser->traverse($constantTree);
        $constantConditions = $conditionAggregatorVisitor->getConditions();
        $conditionsFromPost = $parsedBody['constantsConditions'] ?? [];
        $conditionsFromModuleData = array_flip((array)$moduleData->get('constantConditions'));
        $typoscriptConditions = [];
        foreach ($constantConditions as $condition) {
            $conditionHash = sha1($condition['value']);
            $conditionActive = array_key_exists($conditionHash, $conditionsFromModuleData);
            // Note we're not feeding the post values directly to module data, but filter
            // them through available conditions to prevent polluting module data with
            // manipulated post values.
            if (($conditionsFromPost[$conditionHash] ?? null) === '0') {
                unset($conditionsFromModuleData[$conditionHash]);
                $conditionActive = false;
            } elseif (($conditionsFromPost[$conditionHash] ?? null) === '1') {
                $conditionsFromModuleData[$conditionHash] = true;
                $conditionActive = true;
            }
            $typoscriptConditions[] = [
                'value' => $condition['value'],
                'hash' => $conditionHash,
                'active' => $conditionActive,
            ];
        }
        if ($conditionsFromPost) {
            $moduleData->set('constantConditions', array_keys($conditionsFromModuleData));
            $this->getBackendUser()->pushModuleData($moduleData->getModuleIdentifier(), $moduleData->toArray());
        }
        return $typoscriptConditions;
    }

    /**
     * Align module data active setup conditions with toggled conditions from POST,
     * write updated active conditions to user's module data if needed and
     * prepare a list of active conditions for view.
     */
    private function handleToggledSetupConditions(RootInclude $constantTree, ModuleData $moduleData, ?array $parsedBody, array $flattenedConstants): array
    {
        $this->treeTraverser->resetVisitors();
        $setupConditionConstantSubstitutionVisitor = new IncludeTreeSetupConditionConstantSubstitutionVisitor();
        $setupConditionConstantSubstitutionVisitor->setFlattenedConstants($flattenedConstants);
        $this->treeTraverser->addVisitor($setupConditionConstantSubstitutionVisitor);
        $conditionAggregatorVisitor = GeneralUtility::makeInstance(IncludeTreeConditionAggregatorVisitor::class);
        $this->treeTraverser->addVisitor($conditionAggregatorVisitor);
        $this->treeTraverser->traverse($constantTree);
        $setupConditions = $conditionAggregatorVisitor->getConditions();
        $conditionsFromPost = $parsedBody['setupConditions'] ?? [];
        $conditionsFromModuleData = array_flip((array)$moduleData->get('setupConditions'));
        $typoscriptConditions = [];
        foreach ($setupConditions as $condition) {
            $conditionHash = sha1($condition['value']);
            $conditionActive = array_key_exists($conditionHash, $conditionsFromModuleData);
            // Note we're not feeding the post values directly to module data, but filter
            // them through available conditions to prevent polluting module data with
            // manipulated post values.
            if (($conditionsFromPost[$conditionHash] ?? null) === '0') {
                unset($conditionsFromModuleData[$conditionHash]);
                $conditionActive = false;
            } elseif (($conditionsFromPost[$conditionHash] ?? null) === '1') {
                $conditionsFromModuleData[$conditionHash] = true;
                $conditionActive = true;
            }
            $typoscriptConditions[] = [
                'value' => $condition['value'],
                'originalValue' => $condition['originalValue'],
                'hash' => $conditionHash,
                'active' => $conditionActive,
            ];
        }
        if ($conditionsFromPost) {
            $moduleData->set('setupConditions', array_keys($conditionsFromModuleData));
            $this->getBackendUser()->pushModuleData($moduleData->getModuleIdentifier(), $moduleData->toArray());
        }
        return $typoscriptConditions;
    }
}
