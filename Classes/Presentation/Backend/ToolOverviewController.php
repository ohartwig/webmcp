<?php

declare(strict_types=1);

namespace Koh\WebMcp\Presentation\Backend;

use Koh\WebMcp\Domain\Contract\ToolInvocationLogRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

final readonly class ToolOverviewController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private SiteFinder $siteFinder,
        private ToolInvocationLogRepositoryInterface $logRepository,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $sites = $this->siteFinder->getAllSites();
        $siteData = [];

        foreach ($sites as $site) {
            $settings = $site->getSettings();
            $siteSettings = [];
            try {
                $siteSettings['enabled'] = $settings->get('webmcp.enabled');
            } catch (\InvalidArgumentException) {
                $siteSettings['enabled'] = false;
            }

            $toolNames = ['search', 'navigation', 'page-content'];
            $tools = [];
            foreach ($toolNames as $toolName) {
                try {
                    $enabled = $settings->get('webmcp.tools.' . $toolName . '.enabled');
                } catch (\InvalidArgumentException) {
                    $enabled = true;
                }
                $tools[$toolName] = ['enabled' => (bool) $enabled];
            }

            // Check for form tool
            try {
                $formEnabled = $settings->get('webmcp.tools.form.enabled');
                $tools['form'] = ['enabled' => (bool) $formEnabled];
            } catch (\InvalidArgumentException) {
                // form tool not configured
            }

            $logCount = $this->logRepository->countBySite($site->getIdentifier());
            $toolCounts = $this->logRepository->countByToolForSite($site->getIdentifier());

            $siteData[] = [
                'identifier' => $site->getIdentifier(),
                'rootPageId' => $site->getRootPageId(),
                'base' => (string) $site->getBase(),
                'enabled' => (bool) ($siteSettings['enabled'] ?? false),
                'tools' => $tools,
                'logCount' => $logCount,
                'toolCounts' => $toolCounts,
            ];
        }

        $moduleTemplate->assignMultiple([
            'sites' => $siteData,
        ]);

        return $moduleTemplate->renderResponse('ToolOverview/Index');
    }

    public function logAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $queryParams = $request->getQueryParams();
        $siteIdentifier = $queryParams['site'] ?? '';
        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $logs = [];
        $totalLogs = 0;

        if ('' !== $siteIdentifier) {
            $logs = $this->logRepository->findBySite($siteIdentifier, $perPage, $offset);
            $totalLogs = $this->logRepository->countBySite($siteIdentifier);
        }

        $sites = $this->siteFinder->getAllSites();
        $siteOptions = [];
        foreach ($sites as $site) {
            $siteOptions[] = [
                'identifier' => $site->getIdentifier(),
                'label' => $site->getIdentifier() . ' (' . $site->getBase() . ')',
            ];
        }

        $moduleTemplate->assignMultiple([
            'logs' => $logs,
            'sites' => $siteOptions,
            'selectedSite' => $siteIdentifier,
            'currentPage' => $page,
            'totalPages' => max(1, (int) ceil($totalLogs / $perPage)),
            'totalLogs' => $totalLogs,
        ]);

        return $moduleTemplate->renderResponse('ToolOverview/Log');
    }

    public function consentAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $sites = $this->siteFinder->getAllSites();
        $siteData = [];

        foreach ($sites as $site) {
            $settings = $site->getSettings();
            $toolNames = ['search', 'navigation', 'page-content'];
            $consentConfig = [];

            foreach ($toolNames as $toolName) {
                try {
                    $required = $settings->get('webmcp.tools.' . $toolName . '.consent.required');
                } catch (\InvalidArgumentException) {
                    $required = false;
                }
                try {
                    $category = $settings->get('webmcp.tools.' . $toolName . '.consent.category');
                } catch (\InvalidArgumentException) {
                    $category = 'functional';
                }

                $consentConfig[$toolName] = [
                    'required' => (bool) $required,
                    'category' => (string) $category,
                ];
            }

            try {
                $provider = $settings->get('webmcp.consent.provider');
            } catch (\InvalidArgumentException) {
                $provider = 'native';
            }

            $siteData[] = [
                'identifier' => $site->getIdentifier(),
                'base' => (string) $site->getBase(),
                'consentProvider' => (string) $provider,
                'tools' => $consentConfig,
            ];
        }

        $moduleTemplate->assignMultiple([
            'sites' => $siteData,
        ]);

        return $moduleTemplate->renderResponse('ToolOverview/Consent');
    }
}
