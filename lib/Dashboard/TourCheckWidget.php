<?php

declare(strict_types=1);

namespace OCA\TourCheck\Dashboard;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\IncidentService;
use OCA\TourCheck\Service\RoundService;
use OCA\TourCheck\Service\SiteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * NH-04 suite gravity: the instance Dashboard widget. Items are what a
 * supervisor needs at a glance — missed/active rounds and open incidents,
 * ACL-scoped per viewer (a worker sees only their sites; someone without
 * app access gets an empty widget, never a leak).
 */
class TourCheckWidget implements IAPIWidget, IIconWidget
{
	public function __construct(
		private readonly AccessControlService $acl,
		private readonly RoundService $rounds,
		private readonly SiteService $sites,
		private readonly IncidentService $incidents,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l10n,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	public function getId(): string
	{
		return 'tourcheck';
	}

	public function getTitle(): string
	{
		return 'TourCheck';
	}

	public function getOrder(): int
	{
		return 10;
	}

	public function getIconClass(): string
	{
		return 'icon-tourcheck';
	}

	public function getIconUrl(): string
	{
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Constants::APP_ID, 'app.svg')
		);
	}

	public function getUrl(): ?string
	{
		return $this->urlGenerator->linkToRouteAbsolute('tourcheck.page.dashboard');
	}

	public function load(): void
	{
		Util::addStyle(Constants::APP_ID, 'dashboard');
	}

	/** @return list<WidgetItem> */
	public function getItems(string $userId, ?string $since = null, int $limit = 7): array
	{
		if (!$this->acl->hasAppAccess($userId)) {
			return [];
		}
		$siteIds = $this->acl->visibleSiteIds($userId);
		$unrestricted = $siteIds === [] && ($this->acl->isAppAdmin($userId) || $this->acl->canPlan($userId));
		if ($siteIds === [] && !$unrestricted) {
			return [];
		}

		$items = [];
		$icon = $this->getIconUrl();
		foreach ($this->rounds->todayFor($siteIds, $unrestricted) as $round) {
			$status = (string)$round->getStatus();
			if (!in_array($status, [Constants::ROUND_MISSED, Constants::ROUND_ACTIVE], true)) {
				continue;
			}
			$site = $this->sites->findById((int)$round->getSiteId());
			$siteName = $site?->getName() ?? '';
			$items[] = [
				'prio' => $status === Constants::ROUND_MISSED ? 0 : 1,
				'item' => new WidgetItem(
					$status === Constants::ROUND_MISSED
						? $this->l10n->t('Missed round — %s', [$siteName])
						: $this->l10n->t('Round running — %s', [$siteName]),
					$status === Constants::ROUND_MISSED
						? $this->l10n->t('was due %s', [$round->getWindowEnd()?->format('H:i') ?? ''])
						: $this->l10n->t('ends %s', [$round->getWindowEnd()?->format('H:i') ?? '']),
					$this->urlGenerator->linkToRouteAbsolute('tourcheck.page.round', ['roundId' => (int)$round->getId()]),
					$icon,
					'round-' . (int)$round->getId(),
				),
			];
		}
		foreach ($this->incidents->listVisible('open', $limit * 2) as $inc) {
			if (!in_array((string)$inc->getSeverity(), ['high', 'critical'], true)) {
				continue;
			}
			$items[] = [
				'prio' => 2,
				'item' => new WidgetItem(
					$this->l10n->t('Open incident — %s', [(string)$inc->getTitle()]),
					$this->l10n->t('%s severity', [(string)$inc->getSeverity()]),
					$this->urlGenerator->linkToRouteAbsolute('tourcheck.page.incidents'),
					$icon,
					'incident-' . (int)$inc->getId(),
				),
			];
		}
		usort($items, static fn (array $a, array $b) => $a['prio'] <=> $b['prio']);
		return array_map(static fn (array $x) => $x['item'], array_slice($items, 0, $limit));
	}
}
